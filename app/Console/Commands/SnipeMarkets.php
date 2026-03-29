<?php

namespace App\Console\Commands;

use App\Models\BotMeta;
use App\Models\PendingOrder;
use App\Models\PnlSummary;
use App\Models\Position;
use App\Services\ResolutionSniper;
use App\Services\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SnipeMarkets extends Command
{
    protected $signature = 'bot:snipe';

    protected $description = 'Scan for high-probability markets resolving soon and auto-trade';

    public function handle(ResolutionSniper $sniper): int
    {
        if (! Setting::get('snipe_enabled', true)) {
            return self::SUCCESS;
        }

        if (BotMeta::getValue('global_paused')) {
            return self::SUCCESS;
        }

        $candidates = $sniper->scan();

        if (empty($candidates)) {
            Log::info('snipe_scan_complete', ['candidates' => 0]);

            return self::SUCCESS;
        }

        Log::info('snipe_scan_complete', [
            'candidates' => count($candidates),
            'best' => $candidates[0]['question'],
            'best_price' => $candidates[0]['price'],
            'best_hours' => $candidates[0]['hours_until_end'],
        ]);

        // Auto-trade if enabled (works in both dry-run and live mode).
        if (Setting::get('snipe_auto_trade', false)) {
            $tradeAmount = (float) Setting::get('snipe_trade_amount', 5.0);

            // Check available balance.
            $tradingBalance = (float) (BotMeta::getValue('trading_balance') ?? 0);
            if ($tradingBalance > 0) {
                $totalInvested = (float) Position::where('shares', '>', 0)->sum(DB::raw('buy_price * shares'));
                $pendingBuys = (float) PendingOrder::pending()->where('side', 'BUY')->sum('amount_usdc');
                $realizedPnl = (float) PnlSummary::singleton()->total_realized;
                $available = $tradingBalance - $totalInvested - $pendingBuys + $realizedPnl;

                foreach ($candidates as $candidate) {
                    if ($tradeAmount > $available) {
                        Log::info('snipe_skip_insufficient_balance', [
                            'market' => $candidate['question'],
                            'available' => round($available, 2),
                            'needed' => $tradeAmount,
                        ]);
                        break;
                    }

                    $result = $sniper->execute($candidate, $tradeAmount);
                    if ($result) {
                        $available -= $tradeAmount;
                    }
                }
            }
        }

        return self::SUCCESS;
    }
}
