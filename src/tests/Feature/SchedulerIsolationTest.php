<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SchedulerIsolationTest extends TestCase
{
    use RefreshDatabase;

    public static function slowMaintenanceCommands(): array
    {
        return [
            'credential mail' => ['orders:recover-credential-mails'],
            'Telegram update recovery' => ['telegram:recover-updates'],
            'payment proof forwarding' => ['telegram:forward-payment-proofs'],
            'waitlist' => ['waitlist:reconcile'],
            'automatic sales proof' => ['telegram:fake-sales-proof:auto'],
            'authentication cleanup' => ['customer-auth:cleanup'],
            'authentication mail' => ['customer-auth:recover-mail'],
            'API usage flush' => ['customer-api:flush-usage'],
            'retention cleanup' => ['ops:cleanup-retained-data'],
            'balance metrics' => ['usdt-balance:metrics'],
        ];
    }

    #[DataProvider('slowMaintenanceCommands')]
    public function test_slow_maintenance_does_not_prevent_an_independent_payment_poll_from_starting(string $command): void
    {
        $workspace = sys_get_temp_dir().'/scheduler-isolation-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($workspace.'/framework');
        $originalStorage = $this->app->storagePath();
        $schedule = $this->app->make(Schedule::class);
        $this->app->useStoragePath($workspace);
        $fixture = $workspace.'/task.php';
        file_put_contents($fixture, <<<'PHP'
<?php
[$script, $workspace, $kind] = $argv;
file_put_contents($workspace.'/trace', $kind." start\n", FILE_APPEND | LOCK_EX);
if ($kind === 'maintenance') {
    $deadline = microtime(true) + 3;
    while (! is_file($workspace.'/provider-started') && microtime(true) < $deadline) {
        usleep(10_000);
    }
    if (! is_file($workspace.'/provider-started')) {
        file_put_contents($workspace.'/trace', "provider blocked\n", FILE_APPEND | LOCK_EX);
        exit(37);
    }
} else {
    touch($workspace.'/provider-started');
}
file_put_contents($workspace.'/trace', $kind." end\n", FILE_APPEND | LOCK_EX);
PHP);
        $selected = [];
        foreach ($schedule->events() as $event) {
            $kind = str_contains($event->command ?? '', 'binance:reconcile') ? 'provider'
                : (str_contains($event->command ?? '', $command) ? 'maintenance' : null);
            if ($kind === null) {
                $event->skip(fn (): bool => true);

                continue;
            }
            $selected[] = $kind;
            $event->command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($fixture).' '.escapeshellarg($workspace).' '.$kind;
            $event->cron('* * * * *')->sendOutputTo($workspace.'/'.$kind.'.log');
        }

        try {
            $this->assertEqualsCanonicalizing(['provider', 'maintenance'], $selected);
            $this->assertSame(0, Artisan::call('ops:schedule-run'), Artisan::output());
            $trace = file($workspace.'/trace', FILE_IGNORE_NEW_LINES);
            $this->assertNotContains('provider blocked', $trace);
            $providerStart = array_search('provider start', $trace, true);
            $maintenanceEnd = array_search('maintenance end', $trace, true);
            $this->assertNotFalse($providerStart);
            $this->assertNotFalse($maintenanceEnd);
            $this->assertLessThan($maintenanceEnd, $providerStart);
        } finally {
            $this->app->useStoragePath($originalStorage);
            File::deleteDirectory($workspace);
        }
    }

    public static function expiryCommands(): array
    {
        return [
            'orders' => ['orders:expire-pending'],
            'balance top-ups' => ['store-balance:expire-top-ups'],
        ];
    }

    #[DataProvider('expiryCommands')]
    public function test_expiry_gets_a_priority_slot_without_serially_blocking_provider_work(string $command): void
    {
        $workspace = sys_get_temp_dir().'/scheduler-expiry-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($workspace.'/framework');
        $originalStorage = $this->app->storagePath();
        $schedule = $this->app->make(Schedule::class);
        $this->app->useStoragePath($workspace);
        $fixture = $workspace.'/task.php';
        file_put_contents($fixture, <<<'PHP'
<?php
[$script, $workspace, $kind] = $argv;
$record = function (string $state) use ($workspace, $kind): void {
    file_put_contents($workspace.'/trace', $kind.' '.$state."\n", FILE_APPEND | LOCK_EX);
};
$deadline = microtime(true) + 3;
if ($kind === 'expiry') {
    $record('start');
    touch($workspace.'/expiry-started');
    while (! is_file($workspace.'/provider-started') && microtime(true) < $deadline) {
        usleep(10_000);
    }
    if (! is_file($workspace.'/provider-started')) {
        exit(41);
    }
} else {
    while (! is_file($workspace.'/expiry-started') && microtime(true) < $deadline) {
        usleep(10_000);
    }
    if (! is_file($workspace.'/expiry-started')) {
        exit(42);
    }
    $record('start');
    touch($workspace.'/provider-started');
}
$record('end');
PHP);
        $selected = [];
        foreach ($schedule->events() as $event) {
            $kind = match (true) {
                str_contains($event->command ?? '', 'suppliers:sync') => 'provider-sync',
                str_contains($event->command ?? '', 'suppliers:recover') => 'provider-recovery',
                str_contains($event->command ?? '', $command) => 'expiry',
                default => null,
            };
            if ($kind === null) {
                $event->skip(fn (): bool => true);

                continue;
            }
            $selected[] = $kind;
            $event->command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($fixture).' '.escapeshellarg($workspace).' '.$kind;
            $event->sendOutputTo($workspace.'/'.$kind.'.log');
        }

        try {
            $this->assertEqualsCanonicalizing(['provider-sync', 'provider-recovery', 'expiry'], $selected);
            $this->assertSame(0, Artisan::call('ops:schedule-run'));
            $trace = file($workspace.'/trace', FILE_IGNORE_NEW_LINES);
            $this->assertSame('expiry start', $trace[0]);
            $this->assertContains('expiry end', $trace);
            $this->assertContains('provider-sync end', $trace);
            $this->assertContains('provider-recovery end', $trace);
        } finally {
            $this->app->useStoragePath($originalStorage);
            File::deleteDirectory($workspace);
        }
    }
}
