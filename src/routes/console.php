<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('pakasir:reconcile --chunk=100')->everyMinute()->withoutOverlapping();
Schedule::command('orders:expire-pending')->everyMinute()->withoutOverlapping();
Schedule::command('telegram:recover-updates --queue=default')->everyMinute()->withoutOverlapping();
Schedule::command('telegram:fake-sales-proof:auto')->everyMinute()->withoutOverlapping();
Schedule::job(new \App\Jobs\SendDailyAdminStatsNotification)->dailyAt('22:00');
