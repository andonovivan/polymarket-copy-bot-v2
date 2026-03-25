<?php

namespace App\Console\Commands;

use App\Models\Position;
use App\Models\TrackedWallet;
use App\Models\TradeHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckWallets extends Command
{
    protected $signature = 'bot:check-wallets';

    protected $description = 'Evaluate tracked wallets and auto-pause those with poor performance';

    public function handle(): int
    {
        $wallets = TrackedWallet::where('is_paused', false)->get();
        if ($wallets->isEmpty()) {
            return self::SUCCESS;
        }

        $maxUnrealizedLoss = (float) config('polymarket.auto_pause_max_unrealized_loss', -50);
        $maxExposureLossRatio = (float) config('polymarket.auto_pause_max_exposure_loss_ratio', 0.20);
        $minExposure = (float) config('polymarket.auto_pause_min_exposure', 100);
        $badRecordMinTrades = (int) config('polymarket.auto_pause_bad_record_min_trades', 5);
        $badRecordMaxWinRate = (float) config('polymarket.auto_pause_bad_record_max_win_rate', 40);
        $badRecordMaxLoss = (float) config('polymarket.auto_pause_bad_record_max_loss', -10);
        $zeroWinMinTrades = (int) config('polymarket.auto_pause_zero_win_min_trades', 3);

        $pausedCount = 0;

        foreach ($wallets as $wallet) {
            $address = $wallet->address;

            // Compute realized stats from trade history.
            $trades = TradeHistory::where('copied_from_wallet', $address)->get();
            $totalTrades = $trades->count();
            $winningTrades = $trades->where('pnl', '>=', 0)->count();
            $winRate = $totalTrades > 0 ? round($winningTrades / $totalTrades * 100, 1) : null;
            $realizedPnl = (float) $trades->sum('pnl');

            // Compute unrealized P&L and total invested from open positions.
            $unrealizedPnl = 0.0;
            $totalInvested = 0.0;
            foreach (Position::where('shares', '>', 0)->where('copied_from_wallet', $address)->get() as $pos) {
                $cost = (float) $pos->buy_price * (float) $pos->shares;
                $totalInvested += $cost;
                $value = $pos->current_price !== null ? (float) $pos->current_price * (float) $pos->shares : null;
                if ($value !== null) {
                    $unrealizedPnl += $value - $cost;
                }
            }

            $combinedPnl = round($realizedPnl + $unrealizedPnl, 4);

            // Rule 1: Deep unrealized loss.
            $reason = null;
            if ($unrealizedPnl < $maxUnrealizedLoss) {
                $reason = 'auto:deep_unrealized_loss';
            }

            // Rule 2: High exposure + losing (unrealized loss > X% of invested).
            if (! $reason && $totalInvested > $minExposure && $unrealizedPnl < 0) {
                $lossRatio = abs($unrealizedPnl) / $totalInvested;
                if ($lossRatio >= $maxExposureLossRatio) {
                    $reason = 'auto:high_exposure_loss';
                }
            }

            // Rule 3: Bad closed track record (enough trades + low win rate + losing).
            if (! $reason && $totalTrades >= $badRecordMinTrades && $winRate < $badRecordMaxWinRate && $combinedPnl < $badRecordMaxLoss) {
                $reason = 'auto:bad_track_record';
            }

            // Rule 4: Small sample but zero wins.
            if (! $reason && $totalTrades >= $zeroWinMinTrades && $winningTrades === 0) {
                $reason = 'auto:zero_wins';
            }

            if ($reason) {
                $wallet->update([
                    'is_paused' => true,
                    'paused_at' => now(),
                    'pause_reason' => $reason,
                ]);

                Log::warning('wallet_auto_paused', [
                    'address' => substr($address, 0, 10) . '...',
                    'reason' => $reason,
                    'win_rate' => $winRate,
                    'combined_pnl' => $combinedPnl,
                    'unrealized_pnl' => round($unrealizedPnl, 2),
                    'total_invested' => round($totalInvested, 2),
                    'total_trades' => $totalTrades,
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
