<?php

namespace App\Console\Commands;

use App\Services\TradeCopier;
use Illuminate\Console\Command;

class CheckOrders extends Command
{
    protected $signature = 'bot:check-orders';

    protected $description = 'Poll pending orders for fill status, cancel expired ones (>10min)';

    public function handle(TradeCopier $copier): int
    {
        $result = $copier->processPendingOrders();

        if ($result['filled'] > 0 || $result['cancelled'] > 0) {
            $this->info("Pending orders: {$result['filled']} filled, {$result['cancelled']} cancelled, {$result['pending']} still pending.");
        }

        return self::SUCCESS;
    }
}
