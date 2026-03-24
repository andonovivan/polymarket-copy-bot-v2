<?php

namespace App\Console\Commands;

use App\Models\BotMeta;
use App\Services\PolymarketClient;
use App\Services\TradeCopier;
use App\Services\TradeTracker;
use Illuminate\Console\Command;

class BotPoll extends Command
{
    protected $signature = 'bot:poll';

    protected $description = 'Run one poll cycle: fetch trades, detect new ones, copy them.';

    public function handle(PolymarketClient $client, TradeTracker $tracker, TradeCopier $copier): int
    {
        $this->components->info('Poll cycle starting...');

        $newTrades = $tracker->poll();

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
}
