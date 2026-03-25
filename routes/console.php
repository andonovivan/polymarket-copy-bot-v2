<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('bot:poll')->everyThirtySeconds()->withoutOverlapping();
Schedule::command('bot:update-prices')->everyThirtySeconds()->withoutOverlapping();
Schedule::command('bot:check-resolved')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('bot:check-wallets')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('bot:discover-wallets')->hourly()->withoutOverlapping();
