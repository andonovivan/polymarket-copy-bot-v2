<?php

namespace App\Console\Commands;

use App\Models\BotMeta;
use App\Models\Position;
use App\Services\PolymarketClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

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

        // Cache Polymarket account balance.
        if (! config('polymarket.dry_run')) {
            $balance = $client->getBalanceUsdc();
            if ($balance !== null) {
                BotMeta::setValue('polymarket_balance', (string) round($balance, 4));
            }
        }

        return self::SUCCESS;
    }
}
