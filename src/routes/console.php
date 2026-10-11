<?php

use App\Console\Commands\ReconcileKlikQrisPayments;
use App\Console\Commands\ReconcileSakurupiahPayments;
use App\Console\Commands\ReconcileStoreBalanceTopUps;
use App\Enums\KlikQrisEnvironment;
use App\Enums\SakurupiahEnvironment;
use App\Jobs\SendDailyAdminStatsNotification;
use App\Services\KlikQris\KlikQrisProviderReadiness;
use App\Services\Sakurupiah\SakurupiahProviderReadiness;
use Illuminate\Support\Facades\Schedule;

// Supplier observations and fulfillment are recoverable from PostgreSQL. The
// Redis queue is only a wakeup path; neither command creates customer payments.
Schedule::command('suppliers:sync --limit=5')->everyMinute()->withoutOverlapping(2)->runInBackground();
Schedule::command('suppliers:recover --limit=25')->everyMinute()->withoutOverlapping(2)->runInBackground();
Schedule::command('suppliers:recover-warranty --limit=25')->everyMinute()->withoutOverlapping(2)->runInBackground();
// Deadline reminders are materialized from durable PostgreSQL unit state; the
// queue job is only the delivery wakeup and the effect ledger survives Redis loss.
Schedule::command('suppliers:notify-warranty-deadlines --limit=25')->everyMinute()->withoutOverlapping(2)->runInBackground();
Schedule::command('usdt-funding:recover --limit=25 --json')->everyMinute()->withoutOverlapping(2)->runInBackground();
// Drift and coverage authority for the Customer USDT subledger (#558). The run
// is bounded per facet and records sanitized evidence that alerting consumes;
// a full sweep across cursors stays an explicit operator action (runbook).
Schedule::command('usdt-balance:reconcile --limit=500 --json')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground();
// Accounting aggregates are derived reporting evidence, rebuilt asynchronously
// from immutable events through a unique consumer/event claim.
Schedule::command('usdt-balance:aggregate-accounting --limit=2000 --json')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground();
// Durable balance operations alerts (#558): episodes + one Telegram Outbound
// Effect per new/changed episode, recovered by telegram:recover-outbound.
Schedule::command('ops:balance-alerts --json')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground();
// Bounded retention for the operational metric ledger (append-only, UPDATE-denied).
Schedule::command('usdt-balance:metrics --prune-days=30 --json')
    ->daily()
    ->withoutOverlapping(10);
$klikQrisEnvironment = KlikQrisEnvironment::runtime();
if (app(KlikQrisProviderReadiness::class)->schedulingEnabledFor($klikQrisEnvironment)) {
    Schedule::command((string) config('services.klikqris.reconciliation_command', 'klikqris:reconcile --chunk=100'))
        ->everyMinute()
        ->withoutOverlapping(ReconcileKlikQrisPayments::schedulerMutexMinutes());
    Schedule::command('klikqris:backfill-in-flight --chunk=100')->everyFiveMinutes()->withoutOverlapping();
    Schedule::command('klikqris:reconcile-balance-top-ups --chunk=100 --max-top-ups=10')
        ->everyMinute()
        ->withoutOverlapping(ReconcileStoreBalanceTopUps::schedulerMutexMinutes());
}

// Every top-up rail needs a deadline sweep, including the manual rail which
// has no provider to poll. This command performs no provider I/O.
Schedule::command('store-balance:expire-top-ups --chunk=100')
    ->everyMinute()
    ->withoutOverlapping(2);

// Sakurupiah reconciliation runs only when the environment is enabled,
// operationally ready, and the verification queue is served by the release.
// The reconciliation itself is synchronous scheduler work (no enqueue to an
// unserved queue); deferred webhook verification already uses the supervised
// default queue through SakurupiahProviderReadiness checks.
$sakurupiahEnvironment = SakurupiahEnvironment::runtime();
if (app(SakurupiahProviderReadiness::class)->scheduleRegistrationEnabledFor($sakurupiahEnvironment)) {
    Schedule::command((string) config('services.sakurupiah.reconciliation_command', 'sakurupiah:reconcile --chunk=100'))
        ->everyMinute()
        ->withoutOverlapping(ReconcileSakurupiahPayments::schedulerMutexMinutes());
    Schedule::command('sakurupiah:reconcile-balance-top-ups --chunk=100 --max-top-ups=10')
        ->everyMinute()
        ->withoutOverlapping(2);
}
Schedule::command('pakasir:reconcile --chunk=100')->everyMinute()->withoutOverlapping();
Schedule::command('binance:reconcile --chunk=100')->everyMinute()->withoutOverlapping();
Schedule::command('bsc:reconcile --chunk=100')->everyMinute()->withoutOverlapping();
Schedule::command('orders:expire-pending')->everyMinute()->withoutOverlapping();
// Credential emails are durable per order row; the queue is only the wakeup
// path and this run also materializes handoffs that a lifecycle hook missed.
Schedule::command('orders:recover-credential-mails --limit=100 --since-hours=72')->everyMinute()->withoutOverlapping(2);
Schedule::command('telegram:recover-updates --queue=default')->everyMinute()->withoutOverlapping();
Schedule::command('telegram:forward-payment-proofs --chunk=100')->everyMinute()->withoutOverlapping();
// The outbound recovery worker owns this bounded sweep in its dedicated container.
Schedule::command('telegram:reconcile-stock-notifications --chunk=25')
    ->everyMinute()
    ->withoutOverlapping(45)
    ->runInBackground();
Schedule::command('waitlist:reconcile --chunk=100')->everyMinute()->withoutOverlapping();
// Health episodes and outbound effects are PostgreSQL-durable. One existing
// scheduler is sufficient; Redis overlap is not the dedupe authority.
Schedule::command('suppliers:notify-health --limit=25')->everyMinute()->withoutOverlapping(2)->runInBackground();
Schedule::command('ops:payment-lifecycle-rollout-report')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('telegram:fake-sales-proof:auto')->everyMinute()->withoutOverlapping();
Schedule::command('customer-auth:cleanup --limit=500')->hourly()->withoutOverlapping(10);
Schedule::command('customer-auth:recover-mail --limit=100')->everyMinute()->withoutOverlapping(2);
Schedule::command('ops:cleanup-retained-data --chunk=500')->daily()->withoutOverlapping();
Schedule::job(new SendDailyAdminStatsNotification, 'notifications')->dailyAt('00:00')->withoutOverlapping()->onOneServer();
