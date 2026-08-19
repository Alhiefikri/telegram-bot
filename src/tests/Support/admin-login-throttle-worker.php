<?php

declare(strict_types=1);

use App\Services\Admin\AdminLoginThrottle;
use Illuminate\Contracts\Console\Kernel;

if ($argc !== 6) {
    fwrite(STDERR, "Usage: php admin-login-throttle-worker.php <login> <ip> <release> <ready> <result>\n");
    exit(2);
}

[, $login, $ip, $releaseFile, $readyFile, $resultFile] = $argv;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    touch($readyFile);

    $deadline = microtime(true) + 30;
    while (! is_file($releaseFile)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Timed out waiting for the admin throttle barrier.');
        }

        usleep(10_000);
    }

    $decision = $app->make(AdminLoginThrottle::class)->recordFailure($login, $ip);
    file_put_contents($resultFile, json_encode([
        'ok' => true,
        'value' => [
            'identity' => $decision->identityAttempts,
            'ip' => $decision->ipAttempts,
            'blocked' => $decision->blocked,
        ],
    ], JSON_THROW_ON_ERROR));
    exit(0);
} catch (Throwable $exception) {
    file_put_contents($resultFile, json_encode([
        'ok' => false,
        'class' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
