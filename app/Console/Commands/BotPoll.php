<?php

namespace App\Console\Commands;

use App\DTOs\DetectedTrade;
use App\Models\BotMeta;
use App\Services\PolymarketClient;
use App\Services\TradeCopier;
use App\Services\TradeTracker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BotPoll extends Command
{
    protected $signature = 'bot:poll';

    protected $description = 'Run one poll cycle: fetch trades, detect new ones, copy them.';

    public function handle(PolymarketClient $client, TradeTracker $tracker, TradeCopier $copier): int
    {
        $this->components->info('Poll cycle starting...');

        $newTrades = $tracker->poll();

        // Coalesce multi-fill trades before copying.
        $newTrades = $this->coalesceTrades($newTrades);

        if (count($newTrades) > 0) {
            // Balance check (once per cycle).
            if (config('polymarket.dry_run')) {
                $hasBalance = true;
            } else {
                $balance = $client->getBalanceUsdc();
                $fixedAmount = config('polymarket.fixed_amount_usdc');
                $hasBalance = $balance === null || $balance >= $fixedAmount;
            }

            if (! $hasBalance) {
                $this->components->warn('Insufficient balance — buys paused, sells still execute.');
            }

            $copied = 0;
            foreach ($newTrades as $trade) {
                // Always allow sells. Skip buys when broke.
                if ($trade->side === 'BUY' && ! $hasBalance) {
                    continue;
                }
                if ($copier->copy($trade)) {
                    $copied++;
                }
            }

            $this->components->info("Poll cycle done: {$copied} trades copied out of " . count($newTrades) . ' detected.');
        } else {
            $this->components->info('Poll cycle done: no new trades.');
        }

        // Record that we're alive.
        BotMeta::setValue('last_running_ts', time());

        return self::SUCCESS;
    }

    /**
     * Coalesce multi-fill trades from the same order into single representative trades.
     *
     * When a tracked trader places a large order, it fills against multiple resting
     * orders at different price levels, creating multiple trade records. Group by
     * (wallet + asset_id + side) within a time window and produce a single
     * DetectedTrade with volume-weighted average price and summed size.
     *
     * @param  DetectedTrade[]  $trades
     * @return DetectedTrade[]
     */
    private function coalesceTrades(array $trades): array
    {
        if (count($trades) <= 1) {
            return $trades;
        }

        $windowSeconds = (int) config('polymarket.trade_coalesce_window_seconds', 5);
        if ($windowSeconds <= 0) {
            return $trades;
        }

        // Group by composite key: wallet + asset_id + side.
        $groups = [];
        foreach ($trades as $trade) {
            $key = $trade->wallet . '|' . $trade->assetId . '|' . $trade->side;
            $groups[$key][] = $trade;
        }

        $result = [];
        foreach ($groups as $groupTrades) {
            if (count($groupTrades) === 1) {
                $result[] = $groupTrades[0];

                continue;
            }

            // Sort by timestamp ascending within the group.
            usort($groupTrades, fn ($a, $b) => $a->timestamp <=> $b->timestamp);

            // Sub-group by time proximity: start a new cluster whenever a trade's
            // timestamp exceeds the cluster's first trade by more than the window.
            // Using "anchor" approach prevents drift (chain of trades 4s apart each
            // would NOT all merge into one giant cluster).
            $clusters = [];
            $currentCluster = [$groupTrades[0]];
            $clusterStartTs = $groupTrades[0]->timestamp;

            for ($i = 1, $iMax = count($groupTrades); $i < $iMax; $i++) {
                $trade = $groupTrades[$i];
                if ($trade->timestamp - $clusterStartTs <= $windowSeconds) {
                    $currentCluster[] = $trade;
                } else {
                    $clusters[] = $currentCluster;
                    $currentCluster = [$trade];
                    $clusterStartTs = $trade->timestamp;
                }
            }
            $clusters[] = $currentCluster;

            // Coalesce each cluster into a single DetectedTrade.
            foreach ($clusters as $cluster) {
                if (count($cluster) === 1) {
                    $result[] = $cluster[0];

                    continue;
                }

                // Volume-weighted average price, summed size.
                $totalSize = 0.0;
                $totalNotional = 0.0;
                foreach ($cluster as $t) {
                    $totalSize += $t->size;
                    $totalNotional += $t->price * $t->size;
                }
                $vwap = $totalSize > 0 ? $totalNotional / $totalSize : $cluster[0]->price;

                $first = $cluster[0];

                $coalesced = new DetectedTrade(
                    tradeId: $first->tradeId,
                    wallet: $first->wallet,
                    assetId: $first->assetId,
                    side: $first->side,
                    price: round($vwap, 6),
                    size: round($totalSize, 6),
                    timestamp: $first->timestamp,
                    raw: $first->raw,
                );

                Log::info('trades_coalesced', [
                    'wallet' => substr($first->wallet, 0, 10) . '...',
                    'asset_id' => substr($first->assetId, 0, 16) . '...',
                    'side' => $first->side,
                    'fills' => count($cluster),
                    'total_size' => round($totalSize, 4),
                    'vwap' => round($vwap, 4),
                ]);

                $result[] = $coalesced;
            }
        }

        return $result;
    }
}
