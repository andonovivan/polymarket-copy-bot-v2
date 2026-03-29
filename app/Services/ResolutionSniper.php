<?php

namespace App\Services;

use App\Models\BotMeta;
use App\Models\PendingOrder;
use App\Models\Position;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ResolutionSniper
{
    private PolymarketClient $client;

    public function __construct(PolymarketClient $client)
    {
        $this->client = $client;
    }

    /**
     * Scan for markets resolving soon with a high-probability outcome.
     *
     * Fetches active markets from Gamma API, filters by end date and price,
     * returns candidates sorted by time to resolution.
     */
    public function scan(): array
    {
        $minProbability = (float) Setting::get('snipe_min_probability', 0.90);
        $maxHours = (int) Setting::get('snipe_max_hours', 48);

        $markets = $this->fetchActiveMarkets();
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $candidates = [];

        foreach ($markets as $market) {
            $endDate = $market['endDate'] ?? null;
            if (! $endDate) {
                continue;
            }

            try {
                $end = new \DateTime($endDate);
            } catch (\Throwable $e) {
                continue;
            }

            // Skip markets that already ended or are too far away.
            if ($end <= $now) {
                continue;
            }

            $hoursUntilEnd = ($end->getTimestamp() - $now->getTimestamp()) / 3600;
            if ($hoursUntilEnd > $maxHours) {
                continue;
            }

            // Parse prices and outcomes.
            $prices = $market['outcomePrices'] ?? '[]';
            if (is_string($prices)) {
                $prices = json_decode($prices, true) ?: [];
            }
            $outcomes = $market['outcomes'] ?? '[]';
            if (is_string($outcomes)) {
                $outcomes = json_decode($outcomes, true) ?: [];
            }
            $tokenIds = $market['clobTokenIds'] ?? '[]';
            if (is_string($tokenIds)) {
                $tokenIds = json_decode($tokenIds, true) ?: [];
            }

            if (count($prices) < 2 || count($tokenIds) < 2) {
                continue;
            }

            $yesPrice = (float) $prices[0];
            $noPrice = (float) $prices[1];

            // Find the high-probability side.
            if ($yesPrice >= $minProbability && $yesPrice >= $noPrice) {
                $highPrice = $yesPrice;
                $highOutcome = $outcomes[0] ?? 'Yes';
                $highTokenId = $tokenIds[0];
            } elseif ($noPrice >= $minProbability && $noPrice > $yesPrice) {
                $highPrice = $noPrice;
                $highOutcome = $outcomes[1] ?? 'No';
                $highTokenId = $tokenIds[1];
            } else {
                continue;
            }

            // Skip if already holding this token.
            $existing = Position::where('asset_id', $highTokenId)->where('shares', '>', 0)->exists();
            if ($existing) {
                continue;
            }

            $potentialProfitPct = round((1.0 - $highPrice) / $highPrice * 100, 2);

            $candidates[] = [
                'slug' => $market['slug'] ?? '',
                'question' => $market['question'] ?? 'Unknown',
                'image' => $market['image'] ?? null,
                'outcome' => $highOutcome,
                'price' => $highPrice,
                'hours_until_end' => round($hoursUntilEnd, 1),
                'end_date' => $endDate,
                'token_id' => $highTokenId,
                'potential_profit_pct' => $potentialProfitPct,
            ];
        }

        // Sort by soonest resolution first.
        usort($candidates, fn ($a, $b) => $a['hours_until_end'] <=> $b['hours_until_end']);

        return $candidates;
    }

    /**
     * Execute a snipe trade — buy the high-probability outcome.
     */
    public function execute(array $candidate, float $amount): ?array
    {
        $tokenId = $candidate['token_id'];

        // Get fresh midpoint price.
        $midpoint = $this->client->getMidpoint($tokenId);
        if ($midpoint === null || $midpoint <= 0) {
            Log::info('snipe_skip_no_midpoint', ['slug' => $candidate['slug']]);

            return null;
        }

        $shares = round($amount / $midpoint, 2);
        if ($shares <= 0) {
            return null;
        }

        $result = $this->client->placeOrder($tokenId, 'BUY', $midpoint, $shares);

        if ($result === null) {
            Log::warning('snipe_order_failed', ['slug' => $candidate['slug']]);

            return null;
        }

        $this->handleOrderResult($result, $tokenId, $midpoint, $shares, $candidate);

        Log::info('snipe_executed', [
            'market' => $candidate['question'],
            'outcome' => $candidate['outcome'],
            'price' => $midpoint,
            'shares' => $shares,
            'hours_left' => $candidate['hours_until_end'],
            'profit_pct' => $candidate['potential_profit_pct'],
            'status' => $result['status'],
        ]);

        return [
            'market' => $candidate['question'],
            'outcome' => $candidate['outcome'],
            'price' => $midpoint,
            'shares' => $shares,
            'status' => $result['status'],
        ];
    }

    /**
     * Handle order result — create position or pending order.
     */
    private function handleOrderResult(?array $result, string $tokenId, float $price, float $shares, array $candidate): void
    {
        if ($result === null) {
            return;
        }

        $status = $result['status'];
        $fillPrice = (float) $result['fill_price'];

        if ($status === 'matched' || $status === 'dry_run') {
            $position = Position::firstOrNew(['asset_id' => $tokenId]);
            $oldShares = (float) ($position->shares ?? 0);
            $newShares = $oldShares + $shares;
            $oldPrice = (float) ($position->buy_price ?? 0);

            $position->shares = $newShares;
            $position->exposure = ($position->exposure ?? 0) + ($fillPrice * $shares);
            $position->copied_from_wallet = 'snipe:resolution';

            // Metadata will be backfilled by bot:update-prices if not set here.
            if (! $position->market_slug) {
                $meta = $this->client->getMarketMetadata($tokenId);
                if ($meta) {
                    $position->market_slug = $meta['slug'] ?? null;
                    $position->market_question = $meta['question'] ?? $candidate['question'];
                    $position->market_image = $meta['image'] ?? $candidate['image'];
                    $position->outcome = $meta['outcome'] ?? $candidate['outcome'];
                }
            }

            if (! $position->opened_at || $oldShares <= 0) {
                $position->opened_at = now();
            }

            if ($newShares > 0) {
                $position->buy_price = (($oldPrice * $oldShares) + ($fillPrice * $shares)) / $newShares;
            } else {
                $position->buy_price = $fillPrice;
            }

            // No TP/SL for snipe trades — hold to resolution.
            $position->save();
        } elseif ($status === 'live' || $status === 'delayed') {
            $orderId = $result['raw']['orderID'] ?? null;
            if ($orderId) {
                PendingOrder::create([
                    'order_id' => $orderId,
                    'asset_id' => $tokenId,
                    'side' => 'BUY',
                    'price' => $price,
                    'size' => $shares,
                    'amount_usdc' => round($price * $shares, 4),
                    'copied_from_wallet' => 'snipe:resolution',
                    'market_slug' => null,
                    'status' => $status,
                    'placed_at' => now(),
                ]);
            }
        }
    }

    /**
     * Fetch all active markets from Gamma API.
     * Cached for 5 minutes.
     */
    private function fetchActiveMarkets(): array
    {
        return Cache::remember('snipe:active_markets', 300, function () {
            $allMarkets = [];
            $offset = 0;
            $limit = 200;

            for ($i = 0; $i < 5; $i++) {
                try {
                    $response = Http::timeout(15)
                        ->get('https://gamma-api.polymarket.com/markets', [
                            'closed' => 'false',
                            'limit' => $limit,
                            'offset' => $offset,
                        ]);

                    if (! $response->successful()) {
                        break;
                    }

                    $markets = $response->json();
                    if (empty($markets)) {
                        break;
                    }

                    // Strip to essential fields.
                    foreach ($markets as $market) {
                        $allMarkets[] = [
                            'slug' => $market['slug'] ?? '',
                            'question' => $market['question'] ?? '',
                            'image' => $market['image'] ?? null,
                            'endDate' => $market['endDate'] ?? null,
                            'outcomePrices' => $market['outcomePrices'] ?? '[]',
                            'outcomes' => $market['outcomes'] ?? '[]',
                            'clobTokenIds' => $market['clobTokenIds'] ?? '[]',
                        ];
                    }

                    $offset += $limit;

                    if (count($markets) < $limit) {
                        break;
                    }
                } catch (\Throwable $e) {
                    Log::warning('snipe_fetch_markets_failed', [
                        'offset' => $offset,
                        'error' => substr($e->getMessage(), 0, 120),
                    ]);
                    break;
                }
            }

            return $allMarkets;
        });
    }
}
