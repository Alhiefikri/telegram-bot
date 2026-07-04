<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('orders:expire-pending')->everyMinute()->withoutOverlapping();
Schedule::command('pakasir:reconcile --chunk=100')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('telegram:recover-updates --queue=default')->everyMinute()->withoutOverlapping();
