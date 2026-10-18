<?php

namespace App\Console\Commands {
    function time(): int
    {
        if ($GLOBALS['webhook_real_clock'] ?? false) {
            return \time();
        }

        return (int) floor($GLOBALS['webhook_clock']);
    }

    function hrtime(bool $asNumber = false): int
    {
        if ($GLOBALS['webhook_real_clock'] ?? false) {
            return \hrtime($asNumber);
        }

        return (int) round($GLOBALS['webhook_clock'] * 1_000_000_000);
    }

    function sleep(int $seconds): int
    {
        $GLOBALS['webhook_clock'] += $seconds;

        return 0;
    }

    function usleep(int $microseconds): void
    {
        if ($GLOBALS['webhook_real_clock'] ?? false) {
            \usleep($microseconds);

            return;
        }

        $GLOBALS['webhook_clock'] += $microseconds / 1_000_000;
    }
}

namespace {
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Support\Facades\Http;
    use Symfony\Component\Console\Output\BufferedOutput;

    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    $fixture = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
    $GLOBALS['webhook_real_clock'] = $fixture['real_clock'] ?? false;
    config([
        'app.url' => $fixture['app_url'] ?? 'https://store.example.com',
        'services.telegram.token' => 'fixture-token',
        'services.telegram.webhook_secret' => 'fixture-secret',
    ]);
    $GLOBALS['webhook_clock'] = $fixture['start'] ?? 1000.99;
    $startedAt = $GLOBALS['webhook_clock'];
    $realStartedAt = \hrtime(true);
    $samples = [];
    Http::preventStrayRequests();
    Http::fake(function () use ($fixture, &$samples) {
        $index = count($samples);
        $GLOBALS['webhook_clock'] += $fixture['latency'] ?? 0.02;
        if (($fixture['failure_at'] ?? null) === $index) {
            throw new RuntimeException('fixture-token private transport details');
        }
        $pending = $fixture['pending'][min($index, count($fixture['pending']) - 1)];
        $samples[] = $pending;

        return Http::response([
            'ok' => $fixture['ok'] ?? true,
            'result' => [
                'url' => $fixture['target'] ?? 'https://store.example.com/api/webhook/telegram',
                'pending_update_count' => $pending,
            ],
        ]);
    });
    $output = new BufferedOutput;
    $exit = $kernel->call('ops:check-telegram-webhook', [
        '--max-pending' => $fixture['max_pending'] ?? 0,
        '--observation-seconds' => $fixture['window'] ?? 1,
        '--sample-interval' => $fixture['interval'] ?? 1,
    ], $output);
    echo json_encode([
        'exit' => $exit,
        'samples' => $samples,
        'elapsed' => round($GLOBALS['webhook_real_clock'] ? (\hrtime(true) - $realStartedAt) / 1_000_000_000 : $GLOBALS['webhook_clock'] - $startedAt, 6),
        'output' => $output->fetch(),
    ], JSON_THROW_ON_ERROR)."\n";
}
