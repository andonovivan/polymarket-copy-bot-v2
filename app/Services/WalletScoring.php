<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class WalletScoring
{
    /**
     * Compute advanced performance metrics for the given wallet addresses.
     *
     * Single-query approach: fetches all trade_history rows, groups in PHP,
     * computes per-wallet metrics including composite score (0-100).
     *
     * @param  string[]  $addresses
     * @return array<string, array>  Keyed by wallet address
     */
    public function compute(array $addresses): array
    {
        if (empty($addresses)) {
            return [];
        }

        $minTrades = (int) config('polymarket.score_min_trades', 5);
        $rollingWindow = (int) config('polymarket.auto_pause_rolling_expectancy_trades', 20);
        $tradeSize = (float) Setting::get('fixed_amount_usdc', 2.0);

        // One query — fetch all closed trades for these wallets, ordered for processing.
        $allTrades = DB::table('trade_history')
            ->select('copied_from_wallet', 'pnl', 'buy_price', 'shares', 'closed_at')
            ->whereIn('copied_from_wallet', $addresses)
            ->orderBy('copied_from_wallet')
            ->orderBy('closed_at')
            ->orderBy('id')
            ->get()
            ->groupBy('copied_from_wallet');

        $results = [];
        foreach ($addresses as $addr) {
            $trades = $allTrades[$addr] ?? collect();
            $results[$addr] = $this->computeForWallet($trades, $minTrades, $rollingWindow, $tradeSize);
        }

        return $results;
    }

    private function computeForWallet($trades, int $minTrades, int $rollingWindow, float $tradeSize): array
    {
        $pnls = $trades->pluck('pnl')->map(fn ($v) => (float) $v)->values()->all();
        $count = count($pnls);

        $profitFactor = $this->profitFactor($pnls);
        $rollingExpectancy = $this->rollingExpectancy($pnls, $rollingWindow);
        $maxDrawdownPct = $this->maxDrawdownPct($pnls);
        $consistencyScore = $this->consistencyScore($pnls, $tradeSize);
        $winRate = $count > 0 ? round(count(array_filter($pnls, fn ($p) => $p >= 0)) / $count * 100, 1) : null;

        $compositeScore = $count >= $minTrades
            ? $this->compositeScore($profitFactor, $rollingExpectancy, $winRate, $maxDrawdownPct, $consistencyScore, $tradeSize)
            : null;

        $breakdown = $count >= $minTrades ? [
            'profit_factor' => round($this->scaleProfitFactor($profitFactor), 1),
            'rolling_expectancy' => round($this->scaleExpectancy($rollingExpectancy, $tradeSize), 1),
            'win_rate' => round($winRate ?? 0, 1),
            'max_drawdown' => round(max(0, 100 - $maxDrawdownPct), 1),
            'consistency' => round($consistencyScore, 1),
        ] : null;

        return [
            'profit_factor' => $profitFactor !== null ? round($profitFactor, 2) : null,
            'rolling_expectancy' => $rollingExpectancy !== null ? round($rollingExpectancy, 4) : null,
            'max_drawdown_pct' => round($maxDrawdownPct, 1),
            'consistency' => round($consistencyScore, 1),
            'win_rate' => $winRate,
            'composite_score' => $compositeScore,
            'score_breakdown' => $breakdown,
            'total_closed_trades' => $count,
        ];
    }

    /**
     * Gross profit / gross loss. Null if no trades. Capped at 999 if no losses.
     */
    private function profitFactor(array $pnls): ?float
    {
        if (empty($pnls)) {
            return null;
        }

        $grossProfit = array_sum(array_filter($pnls, fn ($p) => $p > 0));
        $grossLoss = abs(array_sum(array_filter($pnls, fn ($p) => $p < 0)));

        if ($grossLoss == 0) {
            return $grossProfit > 0 ? 999.0 : null;
        }

        return $grossProfit / $grossLoss;
    }

    /**
     * Average P&L over the last N trades.
     */
    private function rollingExpectancy(array $pnls, int $n): ?float
    {
        if (empty($pnls)) {
            return null;
        }

        $window = array_slice($pnls, -$n);

        return array_sum($window) / count($window);
    }

    /**
     * Max drawdown as percentage of peak equity.
     */
    private function maxDrawdownPct(array $pnls): float
    {
        if (empty($pnls)) {
            return 0.0;
        }

        $cumulative = 0.0;
        $peak = 0.0;
        $maxDrawdown = 0.0;

        foreach ($pnls as $pnl) {
            $cumulative += $pnl;
            if ($cumulative > $peak) {
                $peak = $cumulative;
            }
            $drawdown = $peak - $cumulative;
            if ($drawdown > $maxDrawdown) {
                $maxDrawdown = $drawdown;
            }
        }

        if ($peak <= 0) {
            return $maxDrawdown > 0 ? 100.0 : 0.0;
        }

        return min(100.0, ($maxDrawdown / $peak) * 100);
    }

    /**
     * Consistency score (0-100): inverted stddev of P&L, normalized to trade size.
     */
    private function consistencyScore(array $pnls, float $tradeSize): float
    {
        if (count($pnls) < 2) {
            return 50.0; // Neutral for insufficient data.
        }

        $mean = array_sum($pnls) / count($pnls);
        $variance = array_sum(array_map(fn ($p) => ($p - $mean) ** 2, $pnls)) / count($pnls);
        $stddev = sqrt($variance);

        // Normalize: stddev / tradeSize. If stddev equals trade size, score is 50.
        $normalized = $tradeSize > 0 ? ($stddev / $tradeSize) * 50 : 0;

        return max(0, min(100, 100 - $normalized));
    }

    private function scaleProfitFactor(?float $pf): float
    {
        if ($pf === null) {
            return 0;
        }

        return min($pf, 3.0) / 3.0 * 100;
    }

    private function scaleExpectancy(?float $exp, float $tradeSize): float
    {
        if ($exp === null || $tradeSize <= 0) {
            return 50;
        }

        // Scale: +tradeSize = 100, 0 = 50, -tradeSize = 0.
        $scaled = ($exp / $tradeSize) * 50 + 50;

        return max(0, min(100, $scaled));
    }

    private function compositeScore(
        ?float $profitFactor,
        ?float $rollingExpectancy,
        ?float $winRate,
        float $maxDrawdownPct,
        float $consistencyScore,
        float $tradeSize
    ): int {
        $pfScore = $this->scaleProfitFactor($profitFactor);
        $expScore = $this->scaleExpectancy($rollingExpectancy, $tradeSize);
        $wrScore = $winRate ?? 0;
        $ddScore = max(0, 100 - $maxDrawdownPct);
        $conScore = $consistencyScore;

        $weighted = $pfScore * 0.25
            + $expScore * 0.25
            + $wrScore * 0.20
            + $ddScore * 0.15
            + $conScore * 0.15;

        return (int) round(max(0, min(100, $weighted)));
    }
}
