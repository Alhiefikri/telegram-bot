<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('orders:expire-pending')->everyMinute()->withoutOverlapping();
Schedule::command('pakasir:reconcile --chunk=100')->everyFiveMinutes()->withoutOverlapping();
