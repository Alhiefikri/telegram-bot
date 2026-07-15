<?php

use App\Jobs\SendDailyAdminStatsNotification;
use Illuminate\Support\Facades\Schedule;

Schedule::command('pakasir:reconcile --chunk=100')->everyMinute()->withoutOverlapping();
Schedule::command('orders:expire-pending')->everyMinute()->withoutOverlapping();
Schedule::command('telegram:recover-updates --queue=default')->everyMinute()->withoutOverlapping();
Schedule::command('telegram:fake-sales-proof:auto')->everyMinute()->withoutOverlapping();
Schedule::command('customers:cleanup-login-links --chunk=500')->hourly()->withoutOverlapping();
Schedule::job(new SendDailyAdminStatsNotification, 'notifications')->dailyAt('00:00')->withoutOverlapping()->onOneServer();
