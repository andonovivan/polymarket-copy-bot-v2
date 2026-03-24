<?php

namespace App\Console\Commands;

use App\Services\TradeCopier;
use Illuminate\Console\Command;

class BotReconcile extends Command
{
    protected $signature = 'bot:reconcile';

    protected $description = 'Reconcile positions: close profitable trades the tracked trader exited while offline.';

    public function handle(TradeCopier $copier): int
    {
        $this->components->info('Starting reconciliation...');

        $copier->reconcileOnStartup();

        $this->components->info('Reconciliation complete.');

        return self::SUCCESS;
    }
}
