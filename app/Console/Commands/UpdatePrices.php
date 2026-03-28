<?php

namespace App\Console\Commands;

use App\Models\BotMeta;
use App\Models\PnlSnapshot;
use App\Models\PnlSummary;
use App\Models\Position;
use App\Services\PolymarketClient;
use App\Services\Setting;
use App\Services\TradeCopier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UpdatePrices extends Command
{
    protected $signature = 'bot:update-prices';

    protected $description = 'Fetch current midpoint prices for all open positions and cache them in the DB';

    public function handle(PolymarketClient $client): int
    {
        $positions = Position::where('shares', '>', 0)->get();
        if ($positions->isEmpty()) {
            return self::SUCCESS;
        }

        // Fetch midpoints concurrently using Laravel HTTP pool.
        $tokenIds = $positions->pluck('asset_id')->all();

        $responses = Http::pool(function ($pool) use ($tokenIds, $client) {
            $clobApiUrl = config('polymarket.clob_api_url');
            foreach ($tokenIds as $tokenId) {
                $pool->as($tokenId)
                    ->timeout(8)
                    ->get("{$clobApiUrl}/midpoint", ['token_id' => $tokenId]);
            }
        });

        $now = now();
        $updated = 0;

        foreach ($positions as $position) {
            $assetId = $position->asset_id;
            $response = $responses[$assetId] ?? null;

            $midpoint = null;
            if ($response && $response->successful()) {
                $mid = (float) ($response->json('mid') ?? 0);
                if ($mid > 0) {
                    $midpoint = $mid;
                }
            }

            if ($midpoint !== null) {
                $position->current_price = $midpoint;
                $position->market_status = 'active';
                $position->price_updated_at = $now;
                $position->save();
                $updated++;
            } elseif ($position->market_status === 'active') {
                // Midpoint failed — check if market resolved.
                $market = $client->getMarketByToken($assetId);
                if ($market !== null && $market['resolved']) {
                    $payout = $market['payout'];
                    $isWinner = $market['winner_token'] === $assetId;
                    $position->current_price = $payout;
                    $status = $isWinner ? 'resolved_won' : ($payout > 0 ? 'resolved_voided' : 'resolved_lost');
                    $position->market_status = $status;
                    $position->price_updated_at = $now;
                    $position->save();
                    $updated++;
                } elseif ($position->current_price === null) {
                    // No midpoint and not resolved — use buy price as fallback
                    // so the dashboard shows $0.00 P&L instead of "–".
                    $position->current_price = $position->buy_price;
                    $position->price_updated_at = $now;
                    $position->save();
                    $updated++;
                }
            }
        }

        // --- P&L snapshot (every 30 minutes) ---
        $lastSnapshot = BotMeta::getValue('last_pnl_snapshot_ts');
        if (! $lastSnapshot || (time() - (int) $lastSnapshot) >= 1800) {
            $snapPositions = Position::where('shares', '>', 0);
            $posData = $snapPositions
                ->selectRaw('COALESCE(SUM(buy_price * shares), 0) as invested')
                ->selectRaw('COALESCE(SUM(CASE WHEN current_price IS NOT NULL THEN current_price * shares ELSE buy_price * shares END), 0) as value')
                ->selectRaw('COALESCE(SUM(CASE WHEN current_price IS NOT NULL THEN (current_price - buy_price) * shares ELSE 0 END), 0) as unrealized')
                ->selectRaw('COUNT(*) as count')
                ->first();

            $realized = (float) PnlSummary::singleton()->total_realized;
            $unrealized = round((float) $posData->unrealized, 4);

            PnlSnapshot::create([
                'realized_pnl' => $realized,
                'unrealized_pnl' => $unrealized,
                'combined_pnl' => round($realized + $unrealized, 4),
                'positions_value' => round((float) $posData->value, 4),
                'total_invested' => round((float) $posData->invested, 4),
                'open_positions' => (int) $posData->count,
                'recorded_at' => now(),
            ]);

            BotMeta::setValue('last_pnl_snapshot_ts', (string) time());

            // Prune snapshots older than 90 days.
            PnlSnapshot::where('recorded_at', '<', now()->subDays(90))->delete();
        }

        // --- Take-profit / Stop-loss auto-exits ---
        if (Setting::get('enable_tp_sl', true)) {
            $copier = app(TradeCopier::class);
            // Re-read positions after price updates to get fresh current_price values.
            $activePositions = Position::where('shares', '>', 0)->get();

            foreach ($activePositions as $pos) {
                if ($pos->current_price === null) {
                    continue;
                }

                if ($pos->tp_price && $pos->current_price >= $pos->tp_price) {
                    Log::info('tp_exit_triggered', [
                        'asset_id' => $pos->asset_id,
                        'buy_price' => $pos->buy_price,
                        'current_price' => $pos->current_price,
                        'tp_price' => $pos->tp_price,
                    ]);
                    $copier->closePosition($pos->asset_id);
                } elseif ($pos->sl_price && $pos->current_price <= $pos->sl_price) {
                    Log::info('sl_exit_triggered', [
                        'asset_id' => $pos->asset_id,
                        'buy_price' => $pos->buy_price,
                        'current_price' => $pos->current_price,
                        'sl_price' => $pos->sl_price,
                    ]);
                    $copier->closePosition($pos->asset_id);
                }
            }
        }

        // --- Max position age auto-close ---
        $maxAgeHours = (int) Setting::get('max_position_age_hours', 72);
        if ($maxAgeHours > 0) {
            if (! isset($copier)) {
                $copier = app(TradeCopier::class);
            }
            $cutoff = now()->subHours($maxAgeHours);
            $stalePositions = Position::where('shares', '>', 0)
                ->whereNotNull('opened_at')
                ->where('opened_at', '<', $cutoff)
                ->get();

            foreach ($stalePositions as $pos) {
                Log::info('age_exit_triggered', [
                    'asset_id' => $pos->asset_id,
                    'opened_at' => $pos->opened_at->toIso8601String(),
                    'age_hours' => round(now()->diffInMinutes($pos->opened_at) / 60, 1),
                    'max_hours' => $maxAgeHours,
                    'current_price' => $pos->current_price,
                    'buy_price' => $pos->buy_price,
                ]);
                $copier->closePosition($pos->asset_id);
            }
        }

        // Backfill market metadata for positions missing any field (max 5 per cycle).
        $missingMeta = Position::where('shares', '>', 0)
            ->where(function ($q) {
                $q->whereNull('market_slug')
                  ->orWhereNull('market_question')
                  ->orWhereNull('outcome');
            })
            ->limit(5)
            ->get();

        foreach ($missingMeta as $pos) {
            $meta = $client->getMarketMetadata($pos->asset_id);
            if ($meta) {
                if (! $pos->market_slug && ($meta['slug'] ?? null)) {
                    $pos->market_slug = $meta['slug'];
                }
                if (! $pos->market_question && ($meta['question'] ?? null)) {
                    $pos->market_question = $meta['question'];
                }
                if (! $pos->market_image && ($meta['image'] ?? null)) {
                    $pos->market_image = $meta['image'];
                }
                if (! $pos->outcome && ($meta['outcome'] ?? null)) {
                    $pos->outcome = $meta['outcome'];
                }
                $pos->save();
            }
        }

        // Cache Polymarket account balance.
        if (! Setting::get('dry_run', true)) {
            $balance = $client->getBalanceUsdc();
            if ($balance !== null) {
                BotMeta::setValue('polymarket_balance', (string) round($balance, 4));
            }
        }

        return self::SUCCESS;
    }
}
