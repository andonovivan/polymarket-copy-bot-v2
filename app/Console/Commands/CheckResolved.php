<?php

namespace App\Console\Commands;

use App\Services\TradeCopier;
use Illuminate\Console\Command;

class CheckResolved extends Command
{
    protected $signature = 'bot:check-resolved';

    protected $description = 'Check open positions for resolved markets and close them (WON→$1, LOST→$0)';

    public function handle(TradeCopier $copier): int
    {
        $this->info('Checking open positions for resolved markets...');

        $closed = $copier->checkResolvedPositions();

        if ($closed > 0) {
            $this->info("Closed {$closed} resolved position(s).");
        } else {
            $this->info('No resolved positions found.');
        }

        return self::SUCCESS;
    }
}
