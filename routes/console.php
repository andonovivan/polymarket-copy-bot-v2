<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('bot:poll')->everyThirtySeconds()->withoutOverlapping();
