<?php

namespace App\Services;

use App\DTOs\DetectedTrade;
use App\Models\BotMeta;
use App\Models\PnlSummary;
use App\Models\Position;
use App\Models\TradeHistory;
use App\Models\TrackedWallet;
use Illuminate\Support\Facades\Log;

class TradeCopier
{
    private PolymarketClient $client;

    public function __construct(PolymarketClient $client)
    {
        $this->client = $client;
    }

    /**
     * Check all open positions for resolved markets and close them accordingly.
     *
     * For each position:
     * - If market resolved and our token WON → record P&L at $1/share (full payout).
     * - If market resolved and our token LOST → record P&L at $0/share (total loss).
     * - If market not resolved or check fails → skip.
     *
     * Resolved markets have no order book, so we don't place sell orders.
     * Instead we directly update the position as if redeemed.
     */
    public function checkResolvedPositions(): int
    {
        $positions = Position::where('shares', '>', 0)->get();
        if ($positions->isEmpty()) {
            return 0;
        }

        $closedCount = 0;

        foreach ($positions as $position) {
            $assetId = $position->asset_id;
            $market = $this->client->getMarketByToken($assetId);

            if ($market === null || ! $market['resolved']) {
                continue;
            }

            $shares = (float) $position->shares;
            $buyPrice = (float) $position->buy_price;

            // Payout comes from the market data:
            // - $1.00 if our token won
            // - $0.50 if market was voided/cancelled (50/50 split)
            // - $0.00 if our token lost
            $sellPrice = $market['payout'];
            $isWinner = $market['winner_token'] === $assetId;

            $pnl = round(($sellPrice - $buyPrice) * $shares, 4);
            $outcome = $isWinner ? 'WON' : ($sellPrice > 0 ? 'VOIDED' : 'LOST');

            Log::info('resolved_position_closed', [
                'asset_id' => substr($assetId, 0, 16) . '...',
                'outcome' => $outcome,
                'shares' => $shares,
                'buy_price' => $buyPrice,
                'sell_price' => $sellPrice,
                'pnl' => $pnl,
            ]);

            $this->recordPnl($assetId, $buyPrice, $sellPrice, $shares);

            $position->shares = 0;
            $position->exposure = 0;
            $position->buy_price = 0;
            $position->opened_at = null;
            $position->save();

            $closedCount++;
        }

        if ($closedCount > 0) {
            Log::info('resolved_positions_check_done', ['closed' => $closedCount]);
        }

        return $closedCount;
    }

    /**
     * Attempt to replicate a detected trade. Returns true if an order was placed.
     *
     * Filters: sell filter → zero price → size calc → price tolerance → exposure cap → place order.
     */
    public function copy(DetectedTrade $trade): bool
    {
        // --- Sell filter ---
        if ($trade->side === 'SELL' && ! config('polymarket.copy_sells')) {
            Log::info('skipped_sell', ['trade_id' => $trade->tradeId]);

            return false;
        }

        // --- Zero price ---
        if ($trade->price <= 0) {
            Log::info('skipped_zero_price', ['trade_id' => $trade->tradeId]);

            return false;
        }

        // --- Size calculation ---
        if ($trade->side === 'SELL') {
            $position = Position::where('asset_id', $trade->assetId)->first();
            $fixedSize = $position ? (float) $position->shares : 0.0;
            if ($fixedSize <= 0) {
                Log::info('skipped_sell_no_position', ['trade_id' => $trade->tradeId]);

                return false;
            }
        } else {
            $fixedAmountUsdc = config('polymarket.fixed_amount_usdc');
            $fixedSize = round($fixedAmountUsdc / $trade->price, 2);
            if ($fixedSize <= 0) {
                Log::info('skipped_zero_size', ['trade_id' => $trade->tradeId]);

                return false;
            }
        }

        // --- Price tolerance ---
        $midpoint = $this->client->getMidpoint($trade->assetId);
        if ($midpoint !== null) {
            $deviation = abs($midpoint - $trade->price);
            if ($deviation > config('polymarket.price_tolerance')) {
                Log::warning('price_deviation_too_high', [
                    'trade_id' => $trade->tradeId,
                    'original_price' => $trade->price,
                    'midpoint' => $midpoint,
                    'deviation' => $deviation,
                ]);

                return false;
            }
        }

        // --- Exposure cap (BUY only) ---
        $fixedAmountUsdc = config('polymarket.fixed_amount_usdc');
        $position = Position::where('asset_id', $trade->assetId)->first();
        $currentExposure = $position ? (float) $position->exposure : 0.0;

        if ($trade->side === 'BUY' && $currentExposure + $fixedAmountUsdc > config('polymarket.max_position_usdc')) {
            Log::warning('exposure_cap_reached', [
                'trade_id' => $trade->tradeId,
                'asset_id' => $trade->assetId,
                'current' => $currentExposure,
                'would_add' => $fixedAmountUsdc,
                'cap' => config('polymarket.max_position_usdc'),
            ]);

            return false;
        }

        // --- Place order ---
        $result = $this->client->placeOrder($trade->assetId, $trade->side, $trade->price, $fixedSize);
        if ($result === null) {
            return false;
        }

        // --- Post-order state updates ---
        if ($trade->side === 'BUY') {
            $position = Position::firstOrNew(['asset_id' => $trade->assetId]);
            $oldShares = (float) ($position->shares ?? 0);
            $newShares = $oldShares + $fixedSize;
            $oldPrice = (float) ($position->buy_price ?? 0);

            $position->shares = $newShares;
            $position->exposure = ($position->exposure ?? 0) + $fixedAmountUsdc;
            $position->copied_from_wallet = $trade->wallet;

            // Only set opened_at on the first buy.
            if (! $position->opened_at || $oldShares <= 0) {
                $position->opened_at = now();
            }

            // Weighted average buy price.
            if ($newShares > 0) {
                $position->buy_price = (($oldPrice * $oldShares) + ($trade->price * $fixedSize)) / $newShares;
            } else {
                $position->buy_price = $trade->price;
            }

            $position->save();
        } else {
            // SELL
            $position = Position::where('asset_id', $trade->assetId)->first();
            $buyPrice = $position ? (float) $position->buy_price : 0.0;

            $this->recordPnl($trade->assetId, $buyPrice, $trade->price, $fixedSize);

            if ($position) {
                $sellValue = $fixedSize * $trade->price;
                $position->exposure = max(0, $position->exposure - $sellValue);
                $position->shares = 0;
                $position->buy_price = 0;
                $position->opened_at = null;
                $position->save();
            }
        }

        Log::info('trade_copied', [
            'trade_id' => $trade->tradeId,
            'side' => $trade->side,
            'price' => $trade->price,
            'size' => $fixedSize,
        ]);

        return true;
    }

    /**
     * Manually close a position at the current midpoint.
     */
    public function closePosition(string $assetId): array
    {
        $position = Position::where('asset_id', $assetId)->first();
        if (! $position || $position->shares <= 0) {
            return ['error' => 'No position held for this asset'];
        }

        $shares = (float) $position->shares;
        $midpoint = $this->client->getMidpoint($assetId);
        if ($midpoint === null || $midpoint <= 0) {
            return ['error' => 'Could not get current price for this asset'];
        }

        $result = $this->client->placeOrder($assetId, 'SELL', $midpoint, $shares);
        if ($result === null) {
            return ['error' => 'Order placement failed'];
        }

        $buyPrice = (float) $position->buy_price;
        $pnl = round(($midpoint - $buyPrice) * $shares, 4);

        $this->recordPnl($assetId, $buyPrice, $midpoint, $shares);

        $sellValue = $shares * $midpoint;
        $position->exposure = max(0, $position->exposure - $sellValue);
        $position->shares = 0;
        $position->buy_price = 0;
        $position->opened_at = null;
        $position->save();

        Log::info('position_manually_closed', [
            'asset_id' => substr($assetId, 0, 16) . '...',
            'shares' => $shares,
            'price' => $midpoint,
            'pnl' => $pnl,
        ]);

        return ['ok' => true, 'shares' => $shares, 'price' => $midpoint, 'pnl' => $pnl];
    }

    /**
     * Reconcile on startup: close profitable positions if the tracked trader exited while offline.
     */
    public function reconcileOnStartup(): void
    {
        $heldPositions = Position::where('shares', '>', 0)->get();
        if ($heldPositions->isEmpty()) {
            return;
        }

        $lastRunningTs = (int) BotMeta::getValue('last_running_ts', 0);
        $heldAssetIds = $heldPositions->pluck('asset_id')->toArray();

        Log::info('reconcile_start', [
            'positions' => $heldPositions->count(),
            'offline_since' => $lastRunningTs ?: 'never',
        ]);

        // Find assets the tracked traders sold while we were offline.
        $traderSoldAssets = [];
        $wallets = TrackedWallet::pluck('address')->all();

        foreach ($wallets as $wallet) {
            $trades = TradeTracker::fetchUserTrades($wallet, TradeTracker::BULK_LIMIT, $lastRunningTs);
            foreach ($trades as $t) {
                $assetId = $t['asset'] ?? '';
                if (in_array($assetId, $heldAssetIds) && ($t['side'] ?? '') === 'SELL') {
                    $traderSoldAssets[$assetId] = true;
                }
            }
        }

        $assetsToClose = [];

        foreach ($heldPositions as $position) {
            $assetId = $position->asset_id;

            if (! isset($traderSoldAssets[$assetId])) {
                Log::info('reconcile_keep', [
                    'asset_id' => substr($assetId, 0, 16) . '...',
                    'reason' => 'trader_still_holds',
                ]);

                continue;
            }

            $buyPrice = (float) $position->buy_price;
            if ($buyPrice <= 0) {
                Log::info('reconcile_keep', [
                    'asset_id' => substr($assetId, 0, 16) . '...',
                    'reason' => 'no_buy_price_recorded',
                ]);

                continue;
            }

            $midpoint = $this->client->getMidpoint($assetId);
            if ($midpoint === null) {
                Log::info('reconcile_keep', [
                    'asset_id' => substr($assetId, 0, 16) . '...',
                    'reason' => 'no_midpoint',
                ]);

                continue;
            }

            if ($midpoint > $buyPrice) {
                Log::info('reconcile_close_profitable', [
                    'asset_id' => substr($assetId, 0, 16) . '...',
                    'buy_price' => $buyPrice,
                    'current_price' => $midpoint,
                    'shares' => (float) $position->shares,
                ]);
                $assetsToClose[] = $position;
            } else {
                Log::info('reconcile_keep', [
                    'asset_id' => substr($assetId, 0, 16) . '...',
                    'reason' => 'not_profitable',
                    'buy_price' => $buyPrice,
                    'current_price' => $midpoint,
                ]);
            }
        }

        // Execute sells.
        foreach ($assetsToClose as $position) {
            $shares = (float) $position->shares;
            $midpoint = $this->client->getMidpoint($position->asset_id);
            if ($midpoint === null || $midpoint <= 0) {
                continue;
            }

            $result = $this->client->placeOrder($position->asset_id, 'SELL', $midpoint, $shares);
            if ($result !== null) {
                $buyPrice = (float) $position->buy_price;
                $this->recordPnl($position->asset_id, $buyPrice, $midpoint, $shares);
                $position->exposure = 0;
                $position->shares = 0;
                $position->buy_price = 0;
                $position->opened_at = null;
                $position->save();
                Log::info('reconcile_sold', [
                    'asset_id' => substr($position->asset_id, 0, 16) . '...',
                    'shares' => $shares,
                    'price' => $midpoint,
                ]);
            }
        }

        Log::info('reconcile_done');
    }

    /**
     * Record a realized trade P&L.
     */
    private function recordPnl(string $assetId, float $buyPrice, float $sellPrice, float $shares): void
    {
        $pnl = round(($sellPrice - $buyPrice) * $shares, 4);

        // Update PnlSummary.
        $summary = PnlSummary::singleton();
        $summary->total_realized = round($summary->total_realized + $pnl, 4);
        $summary->total_trades++;
        if ($pnl >= 0) {
            $summary->winning_trades++;
        } else {
            $summary->losing_trades++;
        }
        $summary->updated_at = now();
        $summary->save();

        // Get opened_at and copied_from_wallet from the position.
        $position = Position::where('asset_id', $assetId)->first();
        $openedAt = $position?->opened_at;
        $copiedFromWallet = $position?->copied_from_wallet;

        // Create history record.
        TradeHistory::create([
            'asset_id' => $assetId,
            'copied_from_wallet' => $copiedFromWallet,
            'buy_price' => $buyPrice,
            'sell_price' => $sellPrice,
            'shares' => $shares,
            'pnl' => $pnl,
            'opened_at' => $openedAt,
            'closed_at' => now(),
        ]);

        $totalTrades = $summary->total_trades;
        $winRate = $totalTrades > 0 ? round($summary->winning_trades / $totalTrades * 100, 1) : 0;

        Log::info('pnl_update', [
            'trade_pnl' => $pnl,
            'total_realized' => $summary->total_realized,
            'total_trades' => $totalTrades,
            'win_rate' => "{$winRate}%",
        ]);
    }
}
