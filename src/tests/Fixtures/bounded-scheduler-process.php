<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArrayInput;

[$script, $workspace, $mode] = $argv;
if ($mode === 'child') {
    $id = $argv[3];
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, SIG_IGN);
    file_put_contents($workspace.'/children', json_encode(['pid' => getmypid(), 'id' => $id])."\n", FILE_APPEND | LOCK_EX);
    while (true) {
        usleep(100_000);
    }
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useStoragePath($workspace);
$app->make(Kernel::class)->bootstrap();
config([
    'cache.default' => 'file',
    'cache.stores.file.path' => $workspace.'/framework/cache/data',
    'cache.stores.file.lock_path' => $workspace.'/framework/cache/data',
]);
$schedule = new Schedule;
$mutexes = [];
foreach (['first', 'second', 'queued'] as $id) {
    $event = $schedule->exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.escapeshellarg($workspace).' child '.$id)
        ->everyMinute()->withoutOverlapping(2);
    if ($mode !== 'foreground' || $id !== 'first') {
        $event->runInBackground();
    }
    $mutexes[$id] = $event->mutexName();
}
file_put_contents($workspace.'/mutexes', json_encode($mutexes));
$app->instance(Schedule::class, $schedule);
exit($app->handleCommand(new ArrayInput([
    'command' => 'ops:schedule-run',
    '--cycle-id' => 'termination-fixture',
])));
