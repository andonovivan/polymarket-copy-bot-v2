<?php

namespace App\Services;

use App\DTOs\DetectedTrade;
use App\Models\BotMeta;
use App\Models\SeenTrade;
use App\Models\TrackedWallet;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TradeTracker
{
    private const POLL_LIMIT = 100;
    public const BULK_LIMIT = 10000;

    /**
     * Fetch trades for a wallet from the Polymarket Data API.
     * Filters client-side by sinceTs if provided.
     */
    public static function fetchUserTrades(string $wallet, int $limit = self::POLL_LIMIT, int $sinceTs = 0): array
    {
        try {
            $response = Http::timeout(15)
                ->get(config('polymarket.data_api_url') . '/trades', [
                    'user' => $wallet,
                    'limit' => $limit,
                ]);

            if (! $response->successful()) {
                Log::warning('trade_fetch_failed', [
                    'wallet' => substr($wallet, 0, 10) . '...',
                    'status' => $response->status(),
                ]);

                return [];
            }

            $trades = $response->json() ?? [];

            if ($sinceTs > 0) {
                $trades = array_filter($trades, fn ($t) => ($t['timestamp'] ?? 0) > $sinceTs);
                $trades = array_values($trades);
            }

            return $trades;
        } catch (\Throwable $e) {
            Log::warning('trade_fetch_failed', [
                'wallet' => substr($wallet, 0, 10) . '...',
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Poll all tracked wallets and return only new trades.
     * On the first poll (no seen trades in DB), seeds all existing trade IDs
     * without returning them — prevents copying old trades.
     *
     * @return DetectedTrade[]
     */
    public function poll(): array
    {
        $wallets = TrackedWallet::pluck('address')->all();
        if (empty($wallets)) {
            return [];
        }

        $seeded = SeenTrade::exists();
        $newTrades = [];

        foreach ($wallets as $wallet) {
            $rawTrades = self::fetchUserTrades($wallet, self::POLL_LIMIT);

            foreach ($rawTrades as $t) {
                $tradeId = $t['transactionHash'] ?? '';
                if (! $tradeId) {
                    continue;
                }

                // Check if already seen (DB lookup).
                if (SeenTrade::where('transaction_hash', $tradeId)->exists()) {
                    continue;
                }

                // Mark as seen.
                SeenTrade::create([
                    'transaction_hash' => $tradeId,
                    'created_at' => now(),
                ]);

                // First poll: seed only — don't return trades.
                if (! $seeded) {
                    continue;
                }

                $detected = new DetectedTrade(
                    tradeId: $tradeId,
                    wallet: $wallet,
                    assetId: $t['asset'] ?? '',
                    side: $t['side'] ?? 'BUY',
                    price: (float) ($t['price'] ?? 0),
                    size: (float) ($t['size'] ?? 0),
                    timestamp: (int) ($t['timestamp'] ?? 0),
                    raw: $t,
                );

                $newTrades[] = $detected;

                Log::info('new_trade_detected', [
                    'wallet' => substr($wallet, 0, 10) . '...',
                    'side' => $detected->side,
                    'price' => $detected->price,
                    'size' => $detected->size,
                    'asset_id' => substr($detected->assetId, 0, 16) . '...',
                ]);
            }
        }

        if (! $seeded) {
            Log::info('tracker_seeded', ['seen_trade_ids' => SeenTrade::count()]);
        }

        // Prune to prevent unbounded growth.
        SeenTrade::prune(50000);

        return $newTrades;
    }
}
