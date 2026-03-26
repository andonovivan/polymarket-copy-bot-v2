<?php

namespace App\Services;

use App\DTOs\DetectedTrade;
use App\Models\BotMeta;
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
     * Uses per-wallet timestamp watermarks (last_trade_ts) for deduplication.
     * Newly added wallets (null watermark) are seeded on first poll without
     * returning trades — prevents copying historical trades.
     *
     * @return DetectedTrade[]
     */
    public function poll(): array
    {
        // Global pause — skip all polling to save resources.
        if (BotMeta::getValue('global_paused') === '1') {
            return [];
        }

        $walletModels = TrackedWallet::where('is_paused', false)->get(['address', 'last_trade_ts']);
        if ($walletModels->isEmpty()) {
            return [];
        }

        $wallets = $walletModels->pluck('address')->all();
        $walletTimestamps = $walletModels->pluck('last_trade_ts', 'address')->all();

        // Fetch trades from all wallets in rate-limited batches.
        $responses = $this->fetchTradesInBatches($wallets);

        // Process each wallet's trades, filtering by per-wallet timestamp watermark.
        $newTrades = [];
        $seenTxInBatch = []; // Dedup within a single poll cycle.
        $walletMaxTs = [];   // Track max timestamp per wallet for updating watermarks.

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
            $lastTs = $walletTimestamps[$wallet] ?? null;
            $needsSeed = $lastTs === null;
            $maxTs = $lastTs ?? 0;

            foreach ($trades as $t) {
                $tradeId = $t['transactionHash'] ?? '';
                $ts = (int) ($t['timestamp'] ?? 0);

                if (! $tradeId) {
                    continue;
                }

                // Track the highest timestamp seen for this wallet.
                if ($ts > $maxTs) {
                    $maxTs = $ts;
                }

                // Skip trades at or before the watermark — already processed.
                if (! $needsSeed && $ts <= $lastTs) {
                    continue;
                }

                // First poll for this wallet: seed watermark only, don't copy.
                if ($needsSeed) {
                    continue;
                }

                // Dedup within a single poll cycle (same tx across multiple wallets).
                if (isset($seenTxInBatch[$tradeId])) {
                    continue;
                }
                $seenTxInBatch[$tradeId] = true;

                $detected = new DetectedTrade(
                    tradeId: $tradeId,
                    wallet: $wallet,
                    assetId: $t['asset'] ?? '',
                    side: $t['side'] ?? 'BUY',
                    price: (float) ($t['price'] ?? 0),
                    size: (float) ($t['size'] ?? 0),
                    timestamp: $ts,
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

            // Always update the watermark (even for seed runs).
            if ($maxTs > 0) {
                $walletMaxTs[$wallet] = $maxTs;
            }

            if ($needsSeed) {
                Log::info('wallet_seeded', [
                    'wallet' => substr($wallet, 0, 10) . '...',
                    'max_ts' => $maxTs,
                ]);
            }
        }

        // Batch-update watermarks in a single query per wallet.
        foreach ($walletMaxTs as $address => $maxTs) {
            TrackedWallet::where('address', $address)
                ->where(function ($q) use ($maxTs) {
                    $q->whereNull('last_trade_ts')->orWhere('last_trade_ts', '<', $maxTs);
                })
                ->update(['last_trade_ts' => $maxTs]);
        }

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
