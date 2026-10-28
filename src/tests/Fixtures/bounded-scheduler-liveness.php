<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArgvInput;

[$script, $workspace] = $argv;
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useStoragePath($workspace);
$app->make(Kernel::class)->bootstrap();
config(['cache.default' => 'array']);

$mode = getenv('SCHEDULER_TEST_MODE');
$payload = match ($mode) {
    'success' => 'exit(0);',
    'fatal' => 'trigger_error("Fixture fatal error.", E_USER_ERROR);',
    'killed' => 'posix_kill(getmypid(), SIGKILL);',
    'unexpected' => 'exit(7);',
    default => 'exit(1);',
};
$schedule = new Schedule;
$task = $schedule->exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($payload))
    ->everyMinute()->runInBackground()->withoutOverlapping();
if ($mode === 'before') {
    $task->before(fn () => throw new RuntimeException('Fixture preparation failed.'));
} elseif ($mode === 'after') {
    $task->after(fn () => throw new RuntimeException('Fixture completion failed.'));
} elseif ($mode === 'oom') {
    $task->after(fn () => file_put_contents($workspace.'/memory.events', "oom_kill 1\n"));
}
$schedule->exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg('touch('.var_export($workspace.'/remaining-completed', true).');'))
    ->everyMinute()->runInBackground()->withoutOverlapping();
$app->instance(Schedule::class, $schedule);
$arguments = array_merge([$script], array_slice($argv, 3));
if ($mode === 'stale-token') {
    foreach ($arguments as &$argument) {
        if (str_starts_with($argument, '--cycle-id=')) {
            $argument = '--cycle-id=old-cycle';
        }
    }
    unset($argument);
}
exit($app->handleCommand(new ArgvInput($arguments)));
