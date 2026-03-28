<?php

namespace App\Services;

use App\DTOs\DetectedTrade;
use App\Models\BotMeta;
use App\Models\PendingOrder;
use App\Models\PnlSummary;
use App\Models\Position;
use App\Models\TradeHistory;
use App\Models\TrackedWallet;
use Illuminate\Support\Facades\DB;
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
     *
     * For immediately matched orders, the position is updated right away.
     * For live/delayed orders, a PendingOrder record is created and the position
     * update is deferred until the order fills (checked by processPendingOrders).
     */
    public function copy(DetectedTrade $trade): bool
    {
        // --- Global pause ---
        if (BotMeta::getValue('global_paused') === '1') {
            Log::info('skipped_global_paused', ['trade_id' => $trade->tradeId]);

            return false;
        }

        // --- Sell filter ---
        if ($trade->side === 'SELL' && ! Setting::get('copy_sells', true)) {
            Log::info('skipped_sell', ['trade_id' => $trade->tradeId]);

            return false;
        }

        // --- Minimum price filter ---
        // Skip trades at or near zero (penny bets like 1-2¢ are high-risk lottery tickets).
        $minPrice = (float) Setting::get('min_trade_price', 0.05);
        if ($trade->price < $minPrice) {
            Log::info('skipped_below_min_price', [
                'trade_id' => $trade->tradeId,
                'price' => $trade->price,
                'min' => $minPrice,
            ]);

            return false;
        }

        // --- Trade freshness filter ---
        // Skip trades that are too old — reduces price divergence between original and copy.
        $maxAge = (int) Setting::get('max_trade_age_seconds', 30);
        if ($maxAge > 0 && $trade->timestamp > 0) {
            $age = time() - $trade->timestamp;
            if ($age > $maxAge) {
                Log::info('skipped_stale_trade', [
                    'trade_id' => $trade->tradeId,
                    'age_seconds' => $age,
                    'max' => $maxAge,
                ]);

                return false;
            }
        }

        // --- Market category filter (BUY only) ---
        if ($trade->side === 'BUY' && $this->isCategoryBlocked($trade->assetId)) {
            Log::info('skipped_category_filter', [
                'trade_id' => $trade->tradeId,
                'asset_id' => $trade->assetId,
            ]);

            return false;
        }

        // --- Market duration filter (BUY only) ---
        // Skip markets that don't resolve for a long time to avoid capital being stuck.
        if ($trade->side === 'BUY') {
            $maxDurationDays = (int) Setting::get('max_market_duration_days', 30);
            if ($maxDurationDays > 0) {
                $meta = $this->client->getMarketMetadata($trade->assetId);
                $endDate = $meta['end_date'] ?? null;
                if ($endDate) {
                    try {
                        $now = new \DateTime;
                        $end = new \DateTime($endDate);
                        if ($end > $now) {
                            $daysUntilEnd = (int) $now->diff($end)->days;
                            if ($daysUntilEnd > $maxDurationDays) {
                                Log::info('skipped_long_duration', [
                                    'trade_id' => $trade->tradeId,
                                    'asset_id' => $trade->assetId,
                                    'end_date' => $endDate,
                                    'days_until_end' => $daysUntilEnd,
                                    'max_days' => $maxDurationDays,
                                ]);

                                return false;
                            }
                        }
                    } catch (\Throwable $e) {
                        // Invalid date format — skip filter, don't block.
                    }
                }
            }
        }

        // --- Fetch midpoint (used by momentum filter + price tolerance) ---
        $midpoint = $this->client->getMidpoint($trade->assetId);

        // --- Momentum confirmation filter (BUY only) ---
        if ($trade->side === 'BUY' && Setting::get('momentum_filter', true) && $midpoint !== null) {
            if ($midpoint < $trade->price) {
                Log::info('skipped_momentum', [
                    'trade_id' => $trade->tradeId,
                    'asset_id' => $trade->assetId,
                    'trade_price' => $trade->price,
                    'midpoint' => $midpoint,
                ]);

                return false;
            }
        }

        // --- Size calculation ---
        if ($trade->side === 'SELL') {
            $position = Position::where('asset_id', $trade->assetId)->first();
            $fixedSize = $position ? (float) $position->shares : 0.0;
            if ($fixedSize <= 0) {
                Log::info('skipped_sell_no_position', ['trade_id' => $trade->tradeId]);

                return false;
            }
            $tradeAmountUsdc = null; // Not applicable for sells.
        } else {
            // Dynamic position sizing: % of available balance based on wallet score, with min/max caps.
            $tradeAmountUsdc = $this->computeTradeAmount($trade->wallet);

            $fixedSize = round($tradeAmountUsdc / $trade->price, 2);
            if ($fixedSize <= 0) {
                Log::info('skipped_zero_size', ['trade_id' => $trade->tradeId]);

                return false;
            }
        }

        // --- Price tolerance ---
        if ($midpoint !== null) {
            $deviation = abs($midpoint - $trade->price);
            if ($deviation > Setting::get('price_tolerance', 0.03)) {
                Log::warning('price_deviation_too_high', [
                    'trade_id' => $trade->tradeId,
                    'original_price' => $trade->price,
                    'midpoint' => $midpoint,
                    'deviation' => $deviation,
                ]);

                return false;
            }
        }

        // --- Trading balance limit (BUY only) ---
        // Include pending BUY orders as committed capital to avoid overcommitting.
        if ($trade->side === 'BUY') {
            $tradingBalance = BotMeta::getValue('trading_balance');
            if ($tradingBalance !== null && $tradingBalance !== '') {
                $tradingBalance = (float) $tradingBalance;
                $totalInvested = (float) Position::where('shares', '>', 0)->sum(DB::raw('buy_price * shares'));
                $pendingBuyUsdc = (float) PendingOrder::pending()->where('side', 'BUY')->sum('amount_usdc');
                // Polymarket-style: realized P&L adjusts available capital.
                // Profits expand it, losses shrink it.
                $realizedPnl = (float) PnlSummary::singleton()->total_realized;
                $available = $tradingBalance - $totalInvested - $pendingBuyUsdc + $realizedPnl;
                if ($tradingBalance > 0 && $tradeAmountUsdc > $available) {
                    Log::warning('trading_balance_exceeded', [
                        'trade_id' => $trade->tradeId,
                        'total_invested' => round($totalInvested, 2),
                        'pending_buys' => round($pendingBuyUsdc, 2),
                        'realized_pnl' => round($realizedPnl, 2),
                        'available' => round($available, 2),
                        'would_add' => $tradeAmountUsdc,
                        'trading_balance' => $tradingBalance,
                    ]);

                    return false;
                }
            }
        }

        // --- Exposure cap (BUY only) ---
        // Include pending BUY orders for this asset to avoid exceeding the cap.
        $position = Position::where('asset_id', $trade->assetId)->first();
        $currentExposure = $position ? (float) $position->exposure : 0.0;
        $pendingExposure = (float) PendingOrder::pending()
            ->where('asset_id', $trade->assetId)
            ->where('side', 'BUY')
            ->sum('amount_usdc');

        if ($trade->side === 'BUY' && $currentExposure + $pendingExposure + $tradeAmountUsdc > Setting::get('max_position_usdc', 10.0)) {
            Log::warning('exposure_cap_reached', [
                'trade_id' => $trade->tradeId,
                'asset_id' => $trade->assetId,
                'current' => $currentExposure,
                'pending' => $pendingExposure,
                'would_add' => $tradeAmountUsdc,
                'cap' => Setting::get('max_position_usdc', 10.0),
            ]);

            return false;
        }

        // --- Per-wallet exposure cap (BUY only) ---
        if ($trade->side === 'BUY') {
            $maxWalletExposure = (float) Setting::get('max_wallet_exposure_usdc', 20.0);
            if ($maxWalletExposure > 0) {
                $walletInvested = (float) Position::where('shares', '>', 0)
                    ->where('copied_from_wallet', $trade->wallet)
                    ->sum(DB::raw('buy_price * shares'));
                $walletPendingBuys = (float) PendingOrder::pending()
                    ->where('side', 'BUY')
                    ->where('copied_from_wallet', $trade->wallet)
                    ->sum('amount_usdc');

                if ($walletInvested + $walletPendingBuys + $tradeAmountUsdc > $maxWalletExposure) {
                    Log::warning('wallet_exposure_cap_reached', [
                        'trade_id' => $trade->tradeId,
                        'wallet' => substr($trade->wallet, 0, 10) . '...',
                        'wallet_invested' => round($walletInvested, 2),
                        'wallet_pending' => round($walletPendingBuys, 2),
                        'would_add' => $tradeAmountUsdc,
                        'cap' => $maxWalletExposure,
                    ]);

                    return false;
                }
            }
        }

        // --- Global per-market exposure cap (BUY only) ---
        if ($trade->side === 'BUY') {
            $maxGlobalMarket = (float) Setting::get('max_global_market_usdc', 30.0);
            if ($maxGlobalMarket > 0) {
                $marketSlug = $position?->market_slug
                    ?? $this->client->getMarketSlug($trade->assetId);

                if ($marketSlug) {
                    $globalPositionExposure = (float) Position::where('market_slug', $marketSlug)
                        ->where('shares', '>', 0)
                        ->sum(DB::raw('buy_price * shares'));

                    $globalPendingExposure = (float) PendingOrder::pending()
                        ->where('market_slug', $marketSlug)
                        ->where('side', 'BUY')
                        ->sum('amount_usdc');

                    if ($globalPositionExposure + $globalPendingExposure + $tradeAmountUsdc > $maxGlobalMarket) {
                        Log::warning('global_market_cap_reached', [
                            'trade_id' => $trade->tradeId,
                            'market_slug' => $marketSlug,
                            'global_position_exposure' => round($globalPositionExposure, 2),
                            'global_pending_exposure' => round($globalPendingExposure, 2),
                            'would_add' => $tradeAmountUsdc,
                            'cap' => $maxGlobalMarket,
                        ]);

                        return false;
                    }
                }
            }
        }

        // --- Place order ---
        $result = $this->client->placeOrder($trade->assetId, $trade->side, $trade->price, $fixedSize);
        if ($result === null) {
            return false;
        }

        $status = $result['status'];
        $fillPrice = (float) $result['fill_price'];

        // --- Immediately matched or dry-run: update position now ---
        if ($status === 'matched' || $status === 'dry_run') {
            if ($trade->side === 'BUY') {
                $marketMeta = $this->client->getMarketMetadata($trade->assetId);
                $this->applyBuyFill($trade->assetId, $fillPrice, $fixedSize, $trade->wallet, $marketMeta, now());
            } else {
                $this->applySellFill($trade->assetId, $fillPrice, $fixedSize);
            }

            Log::info('trade_copied', [
                'trade_id' => $trade->tradeId,
                'side' => $trade->side,
                'original_price' => $trade->price,
                'fill_price' => $fillPrice,
                'size' => $fixedSize,
                'status' => $status,
            ]);

            return true;
        }

        // --- Live or delayed: defer position update, create PendingOrder ---
        $orderId = $result['raw']['orderID'] ?? null;
        if (! $orderId) {
            Log::error('pending_order_missing_id', [
                'trade_id' => $trade->tradeId,
                'result' => $result['raw'],
            ]);

            return false;
        }

        PendingOrder::create([
            'order_id' => $orderId,
            'asset_id' => $trade->assetId,
            'side' => $trade->side,
            'price' => $trade->price,
            'size' => $fixedSize,
            'amount_usdc' => $trade->side === 'BUY' ? round($trade->price * $fixedSize, 4) : null,
            'copied_from_wallet' => $trade->wallet,
            'market_slug' => $this->client->getMarketSlug($trade->assetId),
            'status' => $status,
            'placed_at' => now(),
        ]);

        Log::info('order_pending', [
            'trade_id' => $trade->tradeId,
            'order_id' => $orderId,
            'side' => $trade->side,
            'price' => $trade->price,
            'size' => $fixedSize,
            'status' => $status,
        ]);

        return true;
    }

    /**
     * Poll pending orders for fill status and cancel expired ones.
     *
     * Called by the bot:check-orders scheduled command every 30 seconds.
     *
     * @return array{filled: int, cancelled: int, pending: int}
     */
    public function processPendingOrders(): array
    {
        $counts = ['filled' => 0, 'cancelled' => 0, 'pending' => 0];

        $pendingOrders = PendingOrder::pending()->get();
        if ($pendingOrders->isEmpty()) {
            return $counts;
        }

        $ttl = (int) Setting::get('pending_order_ttl_minutes', 10);

        foreach ($pendingOrders as $pending) {
            // --- Expired: cancel the order ---
            if ($pending->isExpired($ttl)) {
                $this->cancelPendingOrder($pending);
                $counts['cancelled']++;

                continue;
            }

            // --- Poll CLOB for current status ---
            $orderData = $this->client->getOrder($pending->order_id);
            if ($orderData === null) {
                // API call failed, retry next cycle.
                $counts['pending']++;

                continue;
            }

            $apiStatus = $orderData['status'] ?? '';

            if ($apiStatus === 'matched') {
                $this->fillPendingOrder($pending, $orderData);
                $counts['filled']++;
            } elseif ($apiStatus === 'cancelled') {
                $pending->update([
                    'status' => 'cancelled',
                    'resolved_at' => now(),
                ]);
                Log::info('pending_order_cancelled_externally', [
                    'order_id' => $pending->order_id,
                    'asset_id' => $pending->asset_id,
                    'side' => $pending->side,
                ]);
                $counts['cancelled']++;
            } else {
                // Still live or delayed.
                $counts['pending']++;
            }
        }

        // Prune old resolved records.
        PendingOrder::prune(7);

        if ($counts['filled'] > 0 || $counts['cancelled'] > 0) {
            Log::info('pending_orders_processed', $counts);
        }

        return $counts;
    }

    /**
     * Manually close a position at the current midpoint.
     *
     * If the sell order is immediately matched, the position is closed right away.
     * If the order goes live/delayed, a PendingOrder is created and the position
     * remains open until the sell fills.
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

        $status = $result['status'];

        if ($status === 'matched' || $status === 'dry_run') {
            $sellPrice = (float) $result['fill_price'];
            $buyPrice = (float) $position->buy_price;
            $pnl = round(($sellPrice - $buyPrice) * $shares, 4);

            $this->applySellFill($assetId, $sellPrice, $shares);

            Log::info('position_manually_closed', [
                'asset_id' => substr($assetId, 0, 16) . '...',
                'shares' => $shares,
                'requested_price' => $midpoint,
                'fill_price' => $sellPrice,
                'pnl' => $pnl,
            ]);

            return ['ok' => true, 'shares' => $shares, 'price' => $sellPrice, 'pnl' => $pnl];
        }

        // Live/delayed — create PendingOrder, position stays open.
        $orderId = $result['raw']['orderID'] ?? null;
        if (! $orderId) {
            return ['error' => 'Order placed but no order ID returned'];
        }

        PendingOrder::create([
            'order_id' => $orderId,
            'asset_id' => $assetId,
            'side' => 'SELL',
            'price' => $midpoint,
            'size' => $shares,
            'amount_usdc' => null,
            'copied_from_wallet' => $position->copied_from_wallet,
            'market_slug' => $position->market_slug,
            'status' => $status,
            'placed_at' => now(),
        ]);

        Log::info('close_order_pending', [
            'asset_id' => substr($assetId, 0, 16) . '...',
            'order_id' => $orderId,
            'shares' => $shares,
            'price' => $midpoint,
            'status' => $status,
        ]);

        return ['ok' => true, 'pending' => true, 'order_id' => $orderId, 'shares' => $shares, 'price' => $midpoint];
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
            if ($result === null) {
                continue;
            }

            $status = $result['status'];

            if ($status === 'matched' || $status === 'dry_run') {
                $sellPrice = (float) $result['fill_price'];
                $this->applySellFill($position->asset_id, $sellPrice, $shares);
                Log::info('reconcile_sold', [
                    'asset_id' => substr($position->asset_id, 0, 16) . '...',
                    'shares' => $shares,
                    'requested_price' => $midpoint,
                    'fill_price' => $sellPrice,
                ]);
            } else {
                // Sell order is pending — create PendingOrder, position stays open.
                $orderId = $result['raw']['orderID'] ?? null;
                if ($orderId) {
                    PendingOrder::create([
                        'order_id' => $orderId,
                        'asset_id' => $position->asset_id,
                        'side' => 'SELL',
                        'price' => $midpoint,
                        'size' => $shares,
                        'copied_from_wallet' => $position->copied_from_wallet,
                        'market_slug' => $position->market_slug,
                        'status' => $status,
                        'placed_at' => now(),
                    ]);
                    Log::info('reconcile_sell_pending', [
                        'asset_id' => substr($position->asset_id, 0, 16) . '...',
                        'order_id' => $orderId,
                        'shares' => $shares,
                        'price' => $midpoint,
                    ]);
                }
            }
        }

        Log::info('reconcile_done');
    }

    // -------------------------------------------------------------------------
    //  Position update helpers — shared by copy() and processPendingOrders().
    // -------------------------------------------------------------------------

    /**
     * Apply a confirmed BUY fill to the position.
     */
    private function applyBuyFill(
        string $assetId,
        float $fillPrice,
        float $size,
        string $wallet,
        ?array $marketMeta,
        \DateTimeInterface $openedAt,
    ): void {
        $position = Position::firstOrNew(['asset_id' => $assetId]);
        $oldShares = (float) ($position->shares ?? 0);
        $newShares = $oldShares + $size;
        $oldPrice = (float) ($position->buy_price ?? 0);

        $actualCost = $fillPrice * $size;
        $position->shares = $newShares;
        $position->exposure = ($position->exposure ?? 0) + $actualCost;
        $position->copied_from_wallet = $wallet;

        // Only set opened_at on the first buy.
        if (! $position->opened_at || $oldShares <= 0) {
            $position->opened_at = $openedAt;
        }

        // Set market metadata if not already known.
        if ($marketMeta) {
            if (! $position->market_slug && ($marketMeta['slug'] ?? null)) {
                $position->market_slug = $marketMeta['slug'];
            }
            if (! $position->market_question && ($marketMeta['question'] ?? null)) {
                $position->market_question = $marketMeta['question'];
            }
            if (! $position->market_image && ($marketMeta['image'] ?? null)) {
                $position->market_image = $marketMeta['image'];
            }
            if (! $position->outcome && ($marketMeta['outcome'] ?? null)) {
                $position->outcome = $marketMeta['outcome'];
            }
        }

        // Weighted average buy price using actual fill price.
        if ($newShares > 0) {
            $position->buy_price = (($oldPrice * $oldShares) + ($fillPrice * $size)) / $newShares;
        } else {
            $position->buy_price = $fillPrice;
        }

        // Set take-profit / stop-loss prices based on current buy price.
        if (Setting::get('enable_tp_sl', true)) {
            $tpPct = (float) Setting::get('tp_percentage', 20);
            $slPct = (float) Setting::get('sl_percentage', 15);
            $position->tp_price = round($position->buy_price * (1 + $tpPct / 100), 8);
            $position->sl_price = round($position->buy_price * (1 - $slPct / 100), 8);
        }

        $position->save();
    }

    /**
     * Apply a confirmed SELL fill to the position: record P&L, zero out shares.
     */
    private function applySellFill(string $assetId, float $fillPrice, float $size): void
    {
        $position = Position::where('asset_id', $assetId)->first();
        $buyPrice = $position ? (float) $position->buy_price : 0.0;

        $this->recordPnl($assetId, $buyPrice, $fillPrice, $size);

        if ($position) {
            $sellValue = $size * $fillPrice;
            $position->exposure = max(0, $position->exposure - $sellValue);
            $position->shares = 0;
            $position->buy_price = 0;
            $position->opened_at = null;
            $position->tp_price = null;
            $position->sl_price = null;
            $position->save();
        }
    }

    // -------------------------------------------------------------------------
    //  Pending order resolution helpers.
    // -------------------------------------------------------------------------

    /**
     * Handle a pending order that has been confirmed as matched by the CLOB API.
     */
    private function fillPendingOrder(PendingOrder $pending, array $orderData): void
    {
        $fillPrice = $this->client->deriveFillPriceFromResponse($orderData, $pending->side, $pending->price);

        $pending->update([
            'status' => 'filled',
            'fill_price' => $fillPrice,
            'resolved_at' => now(),
        ]);

        if ($pending->side === 'BUY') {
            $marketMeta = $this->client->getMarketMetadata($pending->asset_id);
            $this->applyBuyFill(
                $pending->asset_id,
                $fillPrice,
                $pending->size,
                $pending->copied_from_wallet ?? '',
                $marketMeta,
                $pending->placed_at,
            );
        } else {
            $this->applySellFill($pending->asset_id, $fillPrice, $pending->size);
        }

        Log::info('pending_order_filled', [
            'order_id' => $pending->order_id,
            'asset_id' => $pending->asset_id,
            'side' => $pending->side,
            'requested_price' => $pending->price,
            'fill_price' => $fillPrice,
            'size' => $pending->size,
        ]);
    }

    /**
     * Cancel an expired pending order on the CLOB and mark it as cancelled locally.
     */
    private function cancelPendingOrder(PendingOrder $pending): void
    {
        $this->client->cancelOrder($pending->order_id);

        $pending->update([
            'status' => 'cancelled',
            'resolved_at' => now(),
        ]);

        Log::info('pending_order_expired_cancelled', [
            'order_id' => $pending->order_id,
            'asset_id' => $pending->asset_id,
            'side' => $pending->side,
            'age_minutes' => $pending->placed_at->diffInMinutes(now()),
        ]);
    }

    // -------------------------------------------------------------------------
    //  Private helpers.
    // -------------------------------------------------------------------------

    /**
     * Compute the USDC amount for a BUY trade.
     *
     * If fixed_amount_override is set, uses that for all trades (bypasses dynamic sizing).
     * Check if a market's category is disabled via Settings.
     * Returns true if the market belongs to a disabled category.
     * Returns false (allow) if metadata/tags are unavailable or no category matches.
     */
    private function isCategoryBlocked(string $assetId): bool
    {
        // Quick bail: check if any category is actually disabled.
        $categoryKeys = ['crypto', 'politics', 'sports', 'pop_culture', 'business', 'science', 'other'];
        $disabledCategories = [];
        foreach ($categoryKeys as $cat) {
            if (! Setting::get("category_{$cat}", true)) {
                $disabledCategories[] = $cat;
            }
        }

        if (empty($disabledCategories)) {
            return false;
        }

        // Fetch metadata (usually cached).
        $meta = $this->client->getMarketMetadata($assetId);
        $tags = $meta['tags'] ?? [];

        // Map tags to the primary category for this market.
        $tagMapping = config('polymarket.market_category_tags', []);
        $matchedCategory = 'other';
        foreach ($tagMapping as $cat => $categoryTags) {
            if (! empty(array_intersect($tags, $categoryTags))) {
                $matchedCategory = $cat;
                break;
            }
        }

        if (in_array($matchedCategory, $disabledCategories)) {
            Log::debug('category_blocked', [
                'asset_id' => $assetId,
                'category' => $matchedCategory,
                'tags' => $tags,
            ]);

            return true;
        }

        return false;
    }

    /**
     * Otherwise uses the wallet's composite score + available balance hybrid model.
     *
     * Tiers: score 70+ (high), 50-69 (mid), 30-49 (low), <30 or no score (fallback to fixed).
     */
    private function computeTradeAmount(string $walletAddress): float
    {
        // Fixed override — bypass all dynamic sizing.
        $fixedOverride = Setting::get('fixed_amount_override');
        if ($fixedOverride !== null && $fixedOverride > 0) {
            return (float) $fixedOverride;
        }

        $fallback = (float) Setting::get('fixed_amount_usdc', 2.0);

        // Compute available balance.
        $tradingBalance = BotMeta::getValue('trading_balance');
        if ($tradingBalance === null || $tradingBalance === '') {
            return $fallback;
        }
        $tradingBalance = (float) $tradingBalance;
        if ($tradingBalance <= 0) {
            return $fallback;
        }
        $totalInvested = (float) Position::where('shares', '>', 0)->sum(DB::raw('buy_price * shares'));
        $pendingBuyUsdc = (float) PendingOrder::pending()->where('side', 'BUY')->sum('amount_usdc');
        $realizedPnl = (float) PnlSummary::singleton()->total_realized;
        $available = $tradingBalance - $totalInvested - $pendingBuyUsdc + $realizedPnl;

        if ($available <= 0) {
            return $fallback;
        }

        $sizingMin = (float) Setting::get('sizing_min', 1.0);

        // --- Kelly Criterion sizing (if enabled) ---
        if (Setting::get('use_kelly_sizing', false)) {
            $kellyFraction = (new WalletScoring)->computeKellyFraction($walletAddress);

            if ($kellyFraction !== null) {
                $multiplier = (float) Setting::get('kelly_fraction_multiplier', 0.5);
                $adjustedFraction = $kellyFraction * $multiplier;
                $sizingMax = (float) Setting::get('sizing_high_max', 10.0);

                if ($adjustedFraction <= 0) {
                    Log::info('kelly_no_edge', [
                        'wallet' => substr($walletAddress, 0, 10) . '...',
                        'kelly_raw' => round($kellyFraction, 4),
                    ]);

                    return $sizingMin;
                }

                $amount = $available * $adjustedFraction;
                $amount = max($sizingMin, min($sizingMax, $amount));

                Log::info('kelly_sizing', [
                    'wallet' => substr($walletAddress, 0, 10) . '...',
                    'kelly_raw' => round($kellyFraction, 4),
                    'multiplier' => $multiplier,
                    'adjusted_fraction' => round($adjustedFraction, 4),
                    'available' => round($available, 2),
                    'raw_amount' => round($available * $adjustedFraction, 2),
                    'capped_amount' => round($amount, 2),
                ]);

                return round($amount, 2);
            }

            Log::info('kelly_insufficient_data', [
                'wallet' => substr($walletAddress, 0, 10) . '...',
            ]);
            // Fall through to tier-based sizing.
        }

        // --- Tier-based sizing (fallback) ---

        // Get wallet's composite score.
        $scores = (new WalletScoring)->compute([$walletAddress]);
        $score = $scores[$walletAddress]['composite_score'] ?? null;

        if ($score === null || $score < 30) {
            // No score or very low — use fixed amount as fallback.
            return max($sizingMin, $fallback);
        }

        if ($score >= 70) {
            $pct = (float) Setting::get('sizing_high_pct', 0.50);
            $max = (float) Setting::get('sizing_high_max', 10.0);
        } elseif ($score >= 50) {
            $pct = (float) Setting::get('sizing_mid_pct', 0.30);
            $max = (float) Setting::get('sizing_mid_max', 5.0);
        } else {
            $pct = (float) Setting::get('sizing_low_pct', 0.15);
            $max = (float) Setting::get('sizing_low_max', 3.0);
        }

        $amount = $available * ($pct / 100);
        $amount = max($sizingMin, min($max, $amount));

        Log::info('dynamic_sizing', [
            'wallet' => substr($walletAddress, 0, 10) . '...',
            'score' => $score,
            'available' => round($available, 2),
            'pct' => $pct,
            'raw_amount' => round($available * ($pct / 100), 2),
            'capped_amount' => round($amount, 2),
        ]);

        return round($amount, 2);
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

        // Get metadata from the position to copy into trade history.
        $position = Position::where('asset_id', $assetId)->first();
        $openedAt = $position?->opened_at;
        $copiedFromWallet = $position?->copied_from_wallet;

        // Create history record.
        TradeHistory::create([
            'asset_id' => $assetId,
            'market_slug' => $position?->market_slug,
            'market_question' => $position?->market_question,
            'market_image' => $position?->market_image,
            'outcome' => $position?->outcome,
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
