<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Services\PaidInvoice\PaidInvoiceOrderPresentationAdapter;
use App\Services\PaidInvoice\PaidInvoiceRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Collection;

require __DIR__.'/../vendor/autoload.php';

$options = getopt('', ['scenario::', 'runs::', 'warmup::']);
$scenarioName = trim((string) ($options['scenario'] ?? 'all'));
$runs = max(1, (int) ($options['runs'] ?? 5));
$warmupRuns = max(0, (int) ($options['warmup'] ?? 1));

$scenarioDefinitions = [
    'representative' => [
        'kind' => 'representative',
        'rows' => 1,
        'unicode' => true,
        'newlines' => false,
        'items' => [[
            'product_name' => 'Snapshot 東京 — Plan',
            'quantity' => 2,
            'unit_price' => 25000,
            'subtotal' => 50000,
        ]],
    ],
    'worst-case-unicode' => [
        'kind' => 'worst_case',
        'rows' => 1,
        'unicode' => true,
        'newlines' => false,
        'items' => [[
            'product_name' => str_repeat('商品🙂', 85),
            'quantity' => 1,
            'unit_price' => 10000,
            'subtotal' => 10000,
        ]],
    ],
    'worst-case-newlines' => [
        'kind' => 'worst_case',
        'rows' => 1,
        'unicode' => false,
        'newlines' => true,
        'items' => [[
            'product_name' => 'Produkt'.str_repeat("\n", 248),
            'quantity' => 1,
            'unit_price' => 20000,
            'subtotal' => 20000,
        ]],
    ],
    'worst-case-rows' => [
        'kind' => 'worst_case',
        'rows' => 3,
        'unicode' => false,
        'newlines' => false,
        'items' => array_map(
            static fn (int $index): array => [
                'product_name' => str_repeat('Long Product ', 19).'NameTest',
                'quantity' => $index + 1,
                'unit_price' => 10000 * ($index + 1),
                'subtotal' => 10000 * ($index + 1) ** 2,
            ],
            range(0, 2),
        ),
    ],
];

if ($scenarioName === 'all') {
    $reports = [];
    $exitCode = 0;

    foreach (array_keys($scenarioDefinitions) as $name) {
        [$report, $status] = runChild($name, $runs, $warmupRuns);
        $reports[$name] = $report;
        $exitCode = max($exitCode, $status);
    }

    $output = [
        'schema_version' => 1,
        'benchmark' => 'paid-invoice-render',
        'production_equivalent' => [
            'service' => 'app',
            'cpu_limit' => '1.00',
            'memory_limit_mib' => 768,
            'worker_processes' => 1,
            'external_services' => false,
        ],
        'budgets' => [
            'max_render_ms' => 250,
            'max_peak_rss_delta_mib' => 24,
            'max_png_bytes' => PaidInvoiceRenderer::MAX_BYTES,
        ],
        'runs' => $runs,
        'warmup_runs' => $warmupRuns,
        'generated_at' => date(DATE_ATOM),
        'scenarios' => $reports,
        'passed' => $exitCode === 0,
    ];
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit($exitCode);
}

if (! isset($scenarioDefinitions[$scenarioName])) {
    fwrite(STDERR, 'Unknown scenario. Use: '.implode(', ', array_keys($scenarioDefinitions)).PHP_EOL);
    exit(2);
}

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

$definition = $scenarioDefinitions[$scenarioName];
$order = benchmarkOrder($definition['items'], $scenarioName);
$renderer = app(PaidInvoiceRenderer::class);
$presentation = app(PaidInvoiceOrderPresentationAdapter::class)->present($order);

$baselinePeakRss = peakRssBytes();
if ($baselinePeakRss === null) {
    throw new RuntimeException('Linux peak RSS metrics are unavailable.');
}

for ($index = 0; $index < $warmupRuns; $index++) {
    $warmup = $renderer->render($presentation);
    unset($warmup);
}
gc_collect_cycles();

$samples = [];
$pngBytes = null;
$dimensions = null;
$peakAfterRender = $baselinePeakRss;

for ($index = 0; $index < $runs; $index++) {
    $startedAt = hrtime(true);
    $result = $renderer->render($presentation);
    $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;
    $samples[] = round($elapsedMs, 3);

    $pngBytes = strlen($result->bytes);
    $dimensions = getimagesizefromstring($result->bytes);
    if (! is_array($dimensions)
        || ($dimensions[0] ?? null) !== PaidInvoiceRenderer::WIDTH
        || ($dimensions[1] ?? null) !== PaidInvoiceRenderer::HEIGHT
        || ($dimensions['mime'] ?? null) !== 'image/png') {
        throw new RuntimeException('Benchmark render produced invalid PNG dimensions or format.');
    }

    $peakAfterRender = max($peakAfterRender, peakRssBytes() ?? $baselinePeakRss);
    unset($result);
    gc_collect_cycles();
}

$peakRssDeltaBytes = max(0, $peakAfterRender - $baselinePeakRss);
$maxRenderMs = max($samples);
$passed = $maxRenderMs <= 250
    && ($peakRssDeltaBytes / 1024 / 1024) <= 24
    && $pngBytes !== null
    && $pngBytes <= PaidInvoiceRenderer::MAX_BYTES;

$output = [
    'scenario' => $scenarioName,
    'input' => [
        'kind' => $definition['kind'],
        'rows' => $definition['rows'],
        'unicode' => $definition['unicode'],
        'newlines' => $definition['newlines'],
    ],
    'runtime' => [
        'php' => PHP_VERSION,
        'gd' => extension_loaded('gd'),
        'freetype' => (bool) (gd_info()['FreeType Support'] ?? false),
        'sapi' => PHP_SAPI,
    ],
    'runs' => $runs,
    'warmup_runs' => $warmupRuns,
    'wall_time_ms' => [
        'samples' => $samples,
        'max' => round($maxRenderMs, 3),
        'p50' => percentile($samples, 0.5),
    ],
    'peak_rss_delta_bytes' => $peakRssDeltaBytes,
    'peak_rss_delta_mib' => round($peakRssDeltaBytes / 1024 / 1024, 3),
    'png' => [
        'bytes' => $pngBytes,
        'width' => $dimensions[0] ?? null,
        'height' => $dimensions[1] ?? null,
        'mime' => $dimensions['mime'] ?? null,
    ],
    'budgets' => [
        'max_render_ms' => 250,
        'max_peak_rss_delta_mib' => 24,
        'max_png_bytes' => PaidInvoiceRenderer::MAX_BYTES,
    ],
    'passed' => $passed,
];

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($passed ? 0 : 1);

/**
 * @return array{0: array<string, mixed>, 1: int}
 */
function runChild(string $scenario, int $runs, int $warmupRuns): array
{
    $command = [
        PHP_BINARY,
        __FILE__,
        '--scenario='.$scenario,
        '--runs='.$runs,
        '--warmup='.$warmupRuns,
    ];
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, __DIR__);
    if (! is_resource($process)) {
        throw new RuntimeException('Unable to launch paid invoice benchmark child.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    $report = json_decode($stdout ?: '', true);

    if (! is_array($report)) {
        throw new RuntimeException('Paid invoice benchmark child returned invalid JSON: '.trim($stderr));
    }

    return [$report, $status];
}

/**
 * @param  list<array{product_name: string, quantity: int, unit_price: int, subtotal: int}>  $items
 */
function benchmarkOrder(array $items, string $scenario): Order
{
    $order = new Order([
        'invoice_number' => 'BENCHMARK-'.strtoupper(str_replace('-', '_', $scenario)),
        'total_amount' => array_sum(array_column($items, 'subtotal')),
        'currency' => 'IDR',
        'payment_status' => PaymentStatus::Paid,
    ]);
    $order->setAttribute('id', 'benchmark-order');

    $customer = new Customer;
    $customer->setRawAttributes(['telegram_id' => 8581234505]);
    $order->setRelation('customer', $customer);

    $orderItems = collect($items)->map(static function (array $item) use ($order): OrderItem {
        return new OrderItem([
            'order_id' => $order->getKey(),
            ...$item,
        ]);
    });
    $order->setRelation('orderItems', $orderItems);

    $payment = new Payment([
        'order_id' => $order->getKey(),
        'provider' => 'manual',
        'merchant_ref' => 'benchmark-merchant-ref',
        'method' => 'qris',
        'amount' => $order->total_amount,
        'currency' => 'IDR',
        'status' => PaymentStatus::Paid,
        'paid_at' => CarbonImmutable::parse('2026-08-21 12:00:00 UTC'),
        'provider_fee' => 2500,
        'provider_total_payment' => $order->total_amount + 2500,
    ]);
    $payment->setAttribute('id', 'benchmark-payment');
    $order->setRelation('payments', new Collection([$payment]));

    return $order;
}

function peakRssBytes(): ?int
{
    $status = @file_get_contents('/proc/self/status');
    if (! is_string($status)) {
        return null;
    }

    if (preg_match('/^VmHWM:\s+(\d+)\s+kB$/m', $status, $matches) !== 1) {
        return null;
    }

    return (int) $matches[1] * 1024;
}

/** @param list<float> $values */
function percentile(array $values, float $percentile): float
{
    sort($values);
    $index = max(0, (int) ceil(count($values) * $percentile) - 1);

    return round($values[$index], 3);
}
