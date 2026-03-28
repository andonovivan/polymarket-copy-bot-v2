<?php

namespace App\Console\Commands;

use App\Models\BotMeta;
use App\Models\PendingOrder;
use App\Models\PnlSummary;
use App\Models\Position;
use App\Services\ArbitrageScanner;
use App\Services\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScanArbitrage extends Command
{
    protected $signature = 'bot:scan-arb';

    protected $description = 'Scan Polymarket for arbitrage opportunities in grouped markets';

    public function handle(ArbitrageScanner $scanner): int
    {
        // Skip if disabled or bot is paused.
        if (! Setting::get('arb_enabled', true)) {
            return self::SUCCESS;
        }

        if (BotMeta::getValue('global_paused')) {
            return self::SUCCESS;
        }

        $opportunities = $scanner->scan();

        if (empty($opportunities)) {
            Log::info('arb_scan_complete', ['opportunities' => 0]);

            return self::SUCCESS;
        }

        Log::info('arb_scan_complete', [
            'opportunities' => count($opportunities),
            'best_deviation' => $opportunities[0]['deviation_pct'] . '%',
            'best_event' => $opportunities[0]['event_title'],
        ]);

        // Auto-trade if enabled.
        if (Setting::get('arb_auto_trade', false) && ! Setting::get('dry_run', true)) {
            $minAutoSpread = (float) Setting::get('arb_min_auto_trade_spread', 0.05);
            $tradeAmount = (float) Setting::get('arb_trade_amount', 5.0);

            // Check available balance.
            $tradingBalance = (float) (BotMeta::getValue('trading_balance') ?? 0);
            if ($tradingBalance > 0) {
                $totalInvested = (float) Position::where('shares', '>', 0)->sum(DB::raw('buy_price * shares'));
                $pendingBuys = (float) PendingOrder::pending()->where('side', 'BUY')->sum('amount_usdc');
                $realizedPnl = (float) PnlSummary::singleton()->total_realized;
                $available = $tradingBalance - $totalInvested - $pendingBuys + $realizedPnl;

                foreach ($opportunities as $opp) {
                    if (abs($opp['deviation']) < $minAutoSpread) {
                        continue;
                    }

                    if ($tradeAmount > $available) {
                        Log::info('arb_skip_insufficient_balance', [
                            'event' => $opp['event_title'],
                            'available' => round($available, 2),
                            'needed' => $tradeAmount,
                        ]);
                        break;
                    }

                    $results = $scanner->execute($opp, $tradeAmount);
                    $available -= $tradeAmount;

                    Log::info('arb_auto_traded', [
                        'event' => $opp['event_title'],
                        'deviation' => $opp['deviation_pct'] . '%',
                        'trades' => count($results),
                    ]);
                }
            }
        }

        return self::SUCCESS;
    }
}
