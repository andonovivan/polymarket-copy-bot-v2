<?php

namespace App\Services;

use App\DTOs\DetectedTrade;
use App\Models\BotMeta;
use App\Models\SeenTrade;
use App\Models\TrackedWallet;
use Illuminate\Http\Client\Response;
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
     * Uses concurrent HTTP requests and batch DB operations for efficiency.
     *
     * @return DetectedTrade[]
     */
    public function poll(): array
    {
        // Global pause — skip all polling to save resources.
        if (BotMeta::getValue('global_paused') === '1') {
            return [];
        }

        $wallets = TrackedWallet::where('is_paused', false)->pluck('address')->all();
        if (empty($wallets)) {
            return [];
        }

        $seeded = SeenTrade::exists();

        // Fetch trades from all wallets in rate-limited batches.
        $responses = $this->fetchTradesInBatches($wallets);

        // Collect all trade IDs from all wallets for a single batch lookup.
        $allRawTrades = []; // ['wallet' => ..., 'trade' => ..., 'tradeId' => ...]
        foreach ($wallets as $wallet) {
            $response = $responses[$wallet] ?? null;
            if (! $response || ! $response->successful()) {
                if ($response) {
                    Log::warning('trade_fetch_failed', [
                        'wallet' => substr($wallet, 0, 10) . '...',
                        'status' => $response->status(),
                    ]);
                }

                continue;
            }

            $trades = $response->json() ?? [];
            foreach ($trades as $t) {
                $tradeId = $t['transactionHash'] ?? '';
                if (! $tradeId) {
                    continue;
                }
                $allRawTrades[] = [
                    'wallet' => $wallet,
                    'trade' => $t,
                    'tradeId' => $tradeId,
                ];
            }
        }

        if (empty($allRawTrades)) {
            return [];
        }

        // Batch-check which trade IDs are already seen (chunked to avoid query limits).
        $allTradeIds = array_unique(array_column($allRawTrades, 'tradeId'));
        $alreadySeen = [];
        foreach (array_chunk($allTradeIds, 500) as $chunk) {
            foreach (SeenTrade::whereIn('transaction_hash', $chunk)->pluck('transaction_hash') as $hash) {
                $alreadySeen[$hash] = true;
            }
        }

        // Filter to only new trades and batch-insert them.
        $newTradeIds = [];
        $newTrades = [];

        foreach ($allRawTrades as $entry) {
            if (isset($alreadySeen[$entry['tradeId']])) {
                continue;
            }

            // Avoid duplicates within the same batch (same tx can appear for multiple wallets).
            if (isset($newTradeIds[$entry['tradeId']])) {
                continue;
            }
            $newTradeIds[$entry['tradeId']] = true;

            // First poll: seed only — don't return trades.
            if (! $seeded) {
                continue;
            }

            $t = $entry['trade'];
            $detected = new DetectedTrade(
                tradeId: $entry['tradeId'],
                wallet: $entry['wallet'],
                assetId: $t['asset'] ?? '',
                side: $t['side'] ?? 'BUY',
                price: (float) ($t['price'] ?? 0),
                size: (float) ($t['size'] ?? 0),
                timestamp: (int) ($t['timestamp'] ?? 0),
                raw: $t,
            );

            $newTrades[] = $detected;

            Log::info('new_trade_detected', [
                'wallet' => substr($entry['wallet'], 0, 10) . '...',
                'side' => $detected->side,
                'price' => $detected->price,
                'size' => $detected->size,
                'asset_id' => substr($detected->assetId, 0, 16) . '...',
            ]);
        }

        // Batch-insert all new seen trade IDs (single INSERT with multiple rows).
        if (! empty($newTradeIds)) {
            $now = now();
            $rows = array_map(fn ($hash) => [
                'transaction_hash' => $hash,
                'created_at' => $now,
            ], array_keys($newTradeIds));

            // Insert in chunks to avoid exceeding DB packet limits.
            foreach (array_chunk($rows, 500) as $chunk) {
                SeenTrade::insert($chunk);
            }
        }

        if (! $seeded) {
            Log::info('tracker_seeded', ['seen_trade_ids' => SeenTrade::count()]);
        }

        // Prune to prevent unbounded growth.
        SeenTrade::prune(50000);

        return $newTrades;
    }

    /**
     * Fetch trades from all wallets using batched concurrent requests
     * with inter-batch delays to avoid 429 rate limiting.
     *
     * Wallets that receive a 429 response are retried once after all
     * primary batches complete.
     *
     * @return array<string, \Illuminate\Http\Client\Response|null>
     */
    private function fetchTradesInBatches(array $wallets): array
    {
        $batchSize = config('polymarket.poll_batch_size', 15);
        $delayMs = config('polymarket.poll_batch_delay_ms', 500);
        $apiUrl = config('polymarket.data_api_url') . '/trades';

        $allResponses = [];
        $retryWallets = [];
        $batches = array_chunk($wallets, $batchSize);

        foreach ($batches as $batchIndex => $batch) {
            // Delay between batches (not before the first one).
            if ($batchIndex > 0 && $delayMs > 0) {
                usleep($delayMs * 1000);
            }

            $responses = Http::pool(function ($pool) use ($batch, $apiUrl) {
                foreach ($batch as $wallet) {
                    $pool->as($wallet)
                        ->timeout(15)
                        ->get($apiUrl, [
                            'user' => $wallet,
                            'limit' => self::POLL_LIMIT,
                        ]);
                }
            });

            foreach ($batch as $wallet) {
                $response = $responses[$wallet] ?? null;

                // Http::pool returns ConnectionException on timeout/failure — skip those.
                if (! $response instanceof Response) {
                    $allResponses[$wallet] = null;

                    continue;
                }

                if ($response->status() === 429) {
                    $retryWallets[] = $wallet;

                    continue;
                }

                $allResponses[$wallet] = $response;
            }
        }

        // Retry 429'd wallets once after a longer delay.
        if (! empty($retryWallets)) {
            Log::info('poll_retrying_429', ['count' => count($retryWallets)]);

            usleep($delayMs * 1000 * 2); // Double delay before retry pass.

            $retryBatches = array_chunk($retryWallets, $batchSize);

            foreach ($retryBatches as $batchIndex => $batch) {
                if ($batchIndex > 0 && $delayMs > 0) {
                    usleep($delayMs * 1000);
                }

                $responses = Http::pool(function ($pool) use ($batch, $apiUrl) {
                    foreach ($batch as $wallet) {
                        $pool->as($wallet)
                            ->timeout(15)
                            ->get($apiUrl, [
                                'user' => $wallet,
                                'limit' => self::POLL_LIMIT,
                            ]);
                    }
                });

                foreach ($batch as $wallet) {
                    $response = $responses[$wallet] ?? null;
                    $allResponses[$wallet] = ($response instanceof Response) ? $response : null;
                }
            }
        }

        // Summary log when there are failures (after retries).
        $failedCount = count(array_filter($allResponses, fn ($r) => $r === null || ! $r->successful()));
        if ($failedCount > 0) {
            Log::warning('poll_batch_summary', [
                'total' => count($wallets),
                'failed_after_retry' => $failedCount,
                'retried' => count($retryWallets),
            ]);
        }

        return $allResponses;
    }
}
