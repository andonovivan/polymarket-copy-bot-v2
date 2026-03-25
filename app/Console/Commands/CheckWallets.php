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
        $minTrades = (int) config('polymarket.auto_pause_min_trades', 10);
        $maxWinRate = (float) config('polymarket.auto_pause_max_win_rate', 30);
        $maxLoss = (float) config('polymarket.auto_pause_max_loss', -15);

        $wallets = TrackedWallet::where('is_paused', false)->get();
        if ($wallets->isEmpty()) {
            return self::SUCCESS;
        }

        $pausedCount = 0;

        foreach ($wallets as $wallet) {
            $address = $wallet->address;

            // Compute realized stats from trade history.
            $trades = TradeHistory::where('copied_from_wallet', $address)->get();
            $totalTrades = $trades->count();

            if ($totalTrades < $minTrades) {
                continue; // Not enough data to evaluate.
            }

            $winningTrades = $trades->where('pnl', '>=', 0)->count();
            $winRate = $totalTrades > 0 ? round($winningTrades / $totalTrades * 100, 1) : 0;
            $realizedPnl = (float) $trades->sum('pnl');

            // Compute unrealized P&L from open positions.
            $unrealizedPnl = 0.0;
            foreach (Position::where('shares', '>', 0)->where('copied_from_wallet', $address)->get() as $pos) {
                $cost = (float) $pos->buy_price * (float) $pos->shares;
                $value = $pos->current_price !== null ? (float) $pos->current_price * (float) $pos->shares : null;
                if ($value !== null) {
                    $unrealizedPnl += $value - $cost;
                }
            }

            $combinedPnl = round($realizedPnl + $unrealizedPnl, 4);

            // Check auto-pause thresholds (ALL must be true).
            $shouldPause = $winRate < $maxWinRate && $combinedPnl < $maxLoss;

            if ($shouldPause) {
                $reason = $winRate < $maxWinRate ? 'auto:low_win_rate' : 'auto:high_loss';

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
