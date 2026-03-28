<?php

namespace App\Services;

use App\Models\BotMeta;
use App\Models\PendingOrder;
use App\Models\PnlSummary;
use App\Models\Position;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ArbitrageScanner
{
    private PolymarketClient $client;

    public function __construct(PolymarketClient $client)
    {
        $this->client = $client;
    }

    /**
     * Scan Polymarket for arbitrage opportunities in grouped markets.
     *
     * Fetches all active negRisk events (mutually exclusive outcomes),
     * checks if outcome prices sum to ~1.0, and reports deviations.
     *
     * @return array List of opportunities with deviation details
     */
    public function scan(): array
    {
        $minSpread = (float) Setting::get('arb_min_spread', 0.02);

        $events = $this->fetchNegRiskEvents();
        $opportunities = [];

        foreach ($events as $event) {
            $markets = $event['markets'] ?? [];
            if (count($markets) < 3) {
                continue;
            }

            $priceSum = 0;
            $marketDetails = [];

            foreach ($markets as $market) {
                $prices = $market['outcomePrices'] ?? '[]';
                if (is_string($prices)) {
                    $prices = json_decode($prices, true) ?: [];
                }
                $yesPrice = (float) ($prices[0] ?? 0);

                $tokenIds = $market['clobTokenIds'] ?? '[]';
                if (is_string($tokenIds)) {
                    $tokenIds = json_decode($tokenIds, true) ?: [];
                }

                $priceSum += $yesPrice;
                $marketDetails[] = [
                    'question' => $market['groupItemTitle'] ?? $market['question'] ?? 'Unknown',
                    'yes_price' => $yesPrice,
                    'yes_token_id' => $tokenIds[0] ?? null,
                    'no_token_id' => $tokenIds[1] ?? null,
                    'slug' => $market['slug'] ?? null,
                ];
            }

            $deviation = $priceSum - 1.0;

            // Skip events where prices sum far from 1.0 — these are likely
            // multi-winner markets (e.g., "which teams make playoffs?") where
            // multiple outcomes can win, not true single-winner markets.
            if ($priceSum > 1.5 || $priceSum < 0.5) {
                continue;
            }

            if (abs($deviation) >= $minSpread) {
                $opportunities[] = [
                    'event_slug' => $event['slug'] ?? '',
                    'event_title' => $event['title'] ?? 'Unknown Event',
                    'market_count' => count($markets),
                    'price_sum' => round($priceSum, 4),
                    'deviation' => round($deviation, 4),
                    'deviation_pct' => round($deviation * 100, 2),
                    'type' => $deviation > 0 ? 'overround' : 'underround',
                    'markets' => $marketDetails,
                ];
            }
        }

        // Sort by absolute deviation descending (best opportunities first).
        usort($opportunities, fn ($a, $b) => abs($b['deviation']) <=> abs($a['deviation']));

        return $opportunities;
    }

    /**
     * Execute an arbitrage trade for a given opportunity.
     *
     * For underround (sum < 1.0): buy Yes on all outcomes.
     * For overround (sum > 1.0): buy No on the cheapest outcome.
     */
    public function execute(array $opportunity, float $amount): array
    {
        $isDryRun = (bool) Setting::get('dry_run', true);
        $results = [];

        if ($opportunity['type'] === 'underround') {
            // Buy Yes on all outcomes — total cost ≈ sum, guaranteed $1 payout.
            $perOutcome = round($amount / count($opportunity['markets']), 2);

            foreach ($opportunity['markets'] as $market) {
                $tokenId = $market['yes_token_id'];
                if (! $tokenId) {
                    continue;
                }

                $midpoint = $this->client->getMidpoint($tokenId);
                if ($midpoint === null || $midpoint <= 0) {
                    $results[] = ['market' => $market['question'], 'status' => 'skipped', 'reason' => 'no midpoint'];
                    continue;
                }

                $shares = round($perOutcome / $midpoint, 2);
                if ($shares <= 0) {
                    continue;
                }

                $result = $this->client->placeOrder($tokenId, 'BUY', $midpoint, $shares);
                $this->handleOrderResult($result, $tokenId, $midpoint, $shares, $market);
                $results[] = [
                    'market' => $market['question'],
                    'side' => 'BUY',
                    'token' => 'Yes',
                    'price' => $midpoint,
                    'shares' => $shares,
                    'status' => $result['status'] ?? 'failed',
                ];
            }
        } else {
            // Overround: buy No on the cheapest Yes outcome (most overpriced).
            $cheapest = null;
            foreach ($opportunity['markets'] as $market) {
                if ($cheapest === null || $market['yes_price'] < $cheapest['yes_price']) {
                    $cheapest = $market;
                }
            }

            if ($cheapest && $cheapest['no_token_id']) {
                $tokenId = $cheapest['no_token_id'];
                $midpoint = $this->client->getMidpoint($tokenId);

                if ($midpoint !== null && $midpoint > 0) {
                    $shares = round($amount / $midpoint, 2);
                    if ($shares > 0) {
                        $result = $this->client->placeOrder($tokenId, 'BUY', $midpoint, $shares);
                        $this->handleOrderResult($result, $tokenId, $midpoint, $shares, $cheapest);
                        $results[] = [
                            'market' => $cheapest['question'],
                            'side' => 'BUY',
                            'token' => 'No',
                            'price' => $midpoint,
                            'shares' => $shares,
                            'status' => $result['status'] ?? 'failed',
                        ];
                    }
                }
            }
        }

        Log::info('arb_execute', [
            'event' => $opportunity['event_title'],
            'type' => $opportunity['type'],
            'deviation' => $opportunity['deviation_pct'] . '%',
            'amount' => $amount,
            'trades' => count($results),
        ]);

        return $results;
    }

    /**
     * Handle order result — create position or pending order.
     */
    private function handleOrderResult(?array $result, string $tokenId, float $price, float $shares, array $market): void
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
            $position->copied_from_wallet = 'arb:scanner';
            $position->market_slug = $market['slug'];

            if (! $position->opened_at || $oldShares <= 0) {
                $position->opened_at = now();
            }

            if ($newShares > 0) {
                $position->buy_price = (($oldPrice * $oldShares) + ($fillPrice * $shares)) / $newShares;
            } else {
                $position->buy_price = $fillPrice;
            }

            // Set TP/SL if enabled.
            if (Setting::get('enable_tp_sl', true)) {
                $tpPct = (float) Setting::get('tp_percentage', 20);
                $slPct = (float) Setting::get('sl_percentage', 15);
                $position->tp_price = round($position->buy_price * (1 + $tpPct / 100), 8);
                $position->sl_price = round($position->buy_price * (1 - $slPct / 100), 8);
            }

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
                    'copied_from_wallet' => 'arb:scanner',
                    'market_slug' => $market['slug'],
                    'status' => $status,
                    'placed_at' => now(),
                ]);
            }
        }
    }

    /**
     * Fetch all active negRisk events from Gamma API.
     * Strips response to essential fields only to avoid memory issues.
     * Cached for 60 seconds.
     */
    private function fetchNegRiskEvents(): array
    {
        return Cache::remember('arb:neg_risk_events', 60, function () {
            $allEvents = [];
            $offset = 0;
            $limit = 100;

            for ($i = 0; $i < 5; $i++) {
                try {
                    $response = Http::timeout(15)
                        ->get('https://gamma-api.polymarket.com/events', [
                            'closed' => 'false',
                            'active' => 'true',
                            'negRisk' => 'true',
                            'limit' => $limit,
                            'offset' => $offset,
                        ]);

                    if (! $response->successful()) {
                        break;
                    }

                    $events = $response->json();
                    if (empty($events)) {
                        break;
                    }

                    // Strip to essential fields to keep memory usage low.
                    foreach ($events as $event) {
                        $stripped = [
                            'slug' => $event['slug'] ?? '',
                            'title' => $event['title'] ?? '',
                            'markets' => [],
                        ];

                        foreach ($event['markets'] ?? [] as $market) {
                            $stripped['markets'][] = [
                                'question' => $market['question'] ?? '',
                                'groupItemTitle' => $market['groupItemTitle'] ?? null,
                                'slug' => $market['slug'] ?? null,
                                'outcomePrices' => $market['outcomePrices'] ?? '[]',
                                'clobTokenIds' => $market['clobTokenIds'] ?? '[]',
                            ];
                        }

                        $allEvents[] = $stripped;
                    }

                    $offset += $limit;

                    if (count($events) < $limit) {
                        break;
                    }
                } catch (\Throwable $e) {
                    Log::warning('arb_fetch_events_failed', [
                        'offset' => $offset,
                        'error' => substr($e->getMessage(), 0, 120),
                    ]);
                    break;
                }
            }

            return $allEvents;
        });
    }
}
