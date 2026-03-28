<?php

namespace App\Console\Commands;

use App\Models\TrackedWallet;
use App\Services\Setting;
use App\Services\WalletScoring;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckWallets extends Command
{
    protected $signature = 'bot:check-wallets';

    protected $description = 'Evaluate tracked wallets and auto-pause those with poor performance';

    public function handle(): int
    {
        if (! Setting::get('auto_pause_enabled', true)) {
            return self::SUCCESS;
        }

        $wallets = TrackedWallet::where('is_paused', false)->get();
        if ($wallets->isEmpty()) {
            return self::SUCCESS;
        }

        // Read all thresholds from runtime settings (DB override > env > defaults).
        $gracePeriodTrades = (int) Setting::get('auto_pause_grace_period_trades', 10);
        $maxUnrealizedLoss = (float) Setting::get('auto_pause_max_unrealized_loss', -50);
        $maxExposureLossRatio = (float) Setting::get('auto_pause_max_exposure_loss_ratio', 0.20);
        $minExposure = (float) Setting::get('auto_pause_min_exposure', 100);
        $badRecordMinTrades = (int) Setting::get('auto_pause_bad_record_min_trades', 15);
        $badRecordMaxWinRate = (float) Setting::get('auto_pause_bad_record_max_win_rate', 30);
        $badRecordMaxLoss = (float) Setting::get('auto_pause_bad_record_max_loss', -25);
        $zeroWinMinTrades = (int) Setting::get('auto_pause_zero_win_min_trades', 8);
        $rollingExpectancyTrades = (int) Setting::get('auto_pause_rolling_expectancy_trades', 40);
        $minProfitFactor = (float) Setting::get('auto_pause_min_profit_factor', 0.7);
        $profitFactorMinTrades = (int) Setting::get('auto_pause_profit_factor_min_trades', 25);

        $addresses = $wallets->pluck('address')->all();

        // Batch-fetch realized stats per wallet in a single query.
        $realizedStats = DB::table('trade_history')
            ->select('copied_from_wallet')
            ->selectRaw('COUNT(*) as total_trades')
            ->selectRaw('SUM(CASE WHEN pnl >= 0 THEN 1 ELSE 0 END) as winning_trades')
            ->selectRaw('SUM(pnl) as realized_pnl')
            ->whereIn('copied_from_wallet', $addresses)
            ->groupBy('copied_from_wallet')
            ->get()
            ->keyBy('copied_from_wallet');

        // Batch-fetch unrealized stats per wallet in a single query.
        $positionStats = DB::table('positions')
            ->select('copied_from_wallet')
            ->selectRaw('SUM(buy_price * shares) as total_invested')
            ->selectRaw('SUM(CASE WHEN current_price IS NOT NULL THEN (current_price - buy_price) * shares ELSE 0 END) as unrealized_pnl')
            ->where('shares', '>', 0)
            ->whereIn('copied_from_wallet', $addresses)
            ->groupBy('copied_from_wallet')
            ->get()
            ->keyBy('copied_from_wallet');

        // Compute advanced metrics (rolling expectancy, profit factor) in one query.
        $walletScores = (new WalletScoring)->compute($addresses);

        $pausedCount = 0;

        foreach ($wallets as $wallet) {
            $address = $wallet->address;

            $rs = $realizedStats[$address] ?? null;
            $totalTrades = $rs ? (int) $rs->total_trades : 0;
            $winningTrades = $rs ? (int) $rs->winning_trades : 0;
            $winRate = $totalTrades > 0 ? round($winningTrades / $totalTrades * 100, 1) : null;
            $realizedPnl = $rs ? (float) $rs->realized_pnl : 0.0;

            $ps = $positionStats[$address] ?? null;
            $totalInvested = $ps ? (float) $ps->total_invested : 0.0;
            $unrealizedPnl = $ps ? (float) $ps->unrealized_pnl : 0.0;

            $combinedPnl = round($realizedPnl + $unrealizedPnl, 4);

            // Grace period: skip all rules if the wallet hasn't had enough closed trades yet.
            // Rules 1-2 (unrealized loss) still apply — they protect against immediate capital loss.
            $inGracePeriod = $totalTrades < $gracePeriodTrades;

            // Rule 1: Deep unrealized loss (always applies, even during grace period).
            $reason = null;
            if ($unrealizedPnl < $maxUnrealizedLoss) {
                $reason = 'auto:deep_unrealized_loss';
            }

            // Rule 2: High exposure + losing (always applies, even during grace period).
            if (! $reason && $totalInvested > $minExposure && $unrealizedPnl < 0) {
                $lossRatio = abs($unrealizedPnl) / $totalInvested;
                if ($lossRatio >= $maxExposureLossRatio) {
                    $reason = 'auto:high_exposure_loss';
                }
            }

            // Rules 3-6 only apply after the grace period.
            if (! $inGracePeriod) {
                // Rule 3: Bad closed track record (enough trades + low win rate + losing).
                if (! $reason && $totalTrades >= $badRecordMinTrades && $winRate < $badRecordMaxWinRate && $combinedPnl < $badRecordMaxLoss) {
                    $reason = 'auto:bad_track_record';
                }

                // Rule 4: Zero wins after enough trades.
                if (! $reason && $totalTrades >= $zeroWinMinTrades && $winningTrades === 0) {
                    $reason = 'auto:zero_wins';
                }

                // Rule 5: Negative rolling expectancy (last N trades losing on average).
                if (! $reason) {
                    $metrics = $walletScores[$address] ?? null;
                    if ($metrics && $metrics['total_closed_trades'] >= $rollingExpectancyTrades
                        && $metrics['rolling_expectancy'] !== null
                        && $metrics['rolling_expectancy'] < 0) {
                        $reason = 'auto:negative_rolling_expectancy';
                    }
                }

                // Rule 6: Low profit factor (gross profit / gross loss < threshold).
                if (! $reason) {
                    $metrics = $walletScores[$address] ?? null;
                    if ($metrics && $metrics['total_closed_trades'] >= $profitFactorMinTrades
                        && $metrics['profit_factor'] !== null
                        && $metrics['profit_factor'] < $minProfitFactor) {
                        $reason = 'auto:low_profit_factor';
                    }
                }
            }

            if ($reason) {
                $wallet->update([
                    'is_paused' => true,
                    'paused_at' => now(),
                    'pause_reason' => $reason,
                ]);

                $m = $walletScores[$address] ?? [];
                Log::warning('wallet_auto_paused', [
                    'address' => substr($address, 0, 10) . '...',
                    'reason' => $reason,
                    'win_rate' => $winRate,
                    'combined_pnl' => $combinedPnl,
                    'unrealized_pnl' => round($unrealizedPnl, 2),
                    'total_invested' => round($totalInvested, 2),
                    'total_trades' => $totalTrades,
                    'rolling_expectancy' => $m['rolling_expectancy'] ?? null,
                    'profit_factor' => $m['profit_factor'] ?? null,
                    'composite_score' => $m['composite_score'] ?? null,
                ]);

                $pausedCount++;
            }
        }

        if ($pausedCount > 0) {
            $this->components->warn("Auto-paused {$pausedCount} wallet(s) due to poor performance.");
        }

        return self::SUCCESS;
    }
}
