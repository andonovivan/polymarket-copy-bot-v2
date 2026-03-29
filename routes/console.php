<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('bot:poll')->everyThirtySeconds()->withoutOverlapping(5);
Schedule::command('bot:update-prices')->everyThirtySeconds()->withoutOverlapping(5);
Schedule::command('bot:check-orders')->everyThirtySeconds()->withoutOverlapping(5);
Schedule::command('bot:check-resolved')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('bot:check-wallets')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('bot:discover-wallets')->hourly()->withoutOverlapping();
Schedule::command('bot:snipe')->everyFiveMinutes()->withoutOverlapping();
