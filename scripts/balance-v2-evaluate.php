<?php

// Re-evaluate immutable observations without overwriting the original result.
require __DIR__.'/balance-v2-acceptance.php';

$directory = realpath($argv[1] ?? '');
if (! $directory || ! str_starts_with($directory, realpath(__DIR__.'/..').'/artifacts/balance-v2/')) {
    throw new RuntimeException('A retained local balance V2 artifact directory is required.');
}
$load = json_decode(file_get_contents($directory.'/load.json'), true, flags: JSON_THROW_ON_ERROR);
$dataset = json_decode(file_get_contents($directory.'/dataset.json'), true, flags: JSON_THROW_ON_ERROR);
$result = json_decode(file_get_contents($directory.'/result.json'), true, flags: JSON_THROW_ON_ERROR);
$short = (bool) $dataset['short'];
$expected = [
    'steady' => ['reads' => 50 * ($short ? 5 : 1800), 'postings' => 10 * ($short ? 5 : 1800)],
    'burst' => ['reads' => 250 * ($short ? 2 : 60), 'postings' => 50 * ($short ? 2 : 60)],
    'hot' => ['reads' => 250 * ($short ? 2 : 60), 'postings' => 50 * ($short ? 2 : 60)],
];
$checks = balanceV2Acceptance($load, $expected);
// Counterfactual controls prove genuine misses and incomplete data cannot be
// turned green by the corrected evaluation; actual observations stay intact.
$controls = [];
foreach (['read', 'posting', 'lock', 'population', 'missing', 'errors', 'backlog'] as $case) {
    $changed = $load;
    match ($case) {
        'read' => $changed['steady']['usdt_read']['latency_ms']['p95'] = 101,
        'posting' => $changed['steady']['usdt_posting']['latency_ms']['p95'] = 251,
        'lock' => $changed['steady']['usdt_posting']['lock_query_ms']['p99'] = 501,
        'population' => $changed['steady']['usdt_posting']['latency_ms']['count'] = 0,
        'missing' => $changed['steady']['usdt_posting']['latency_ms']['p95'] = null,
        'errors' => $changed['steady']['usdt_posting']['errors'] = 1,
        'backlog' => $changed['burst']['remaining_scheduled_work'] = 1,
    };
    $controls[$case] = in_array(false, balanceV2Acceptance($changed, $expected), true);
}
$passed = ! in_array(false, $checks, true) && ! in_array(false, $controls, true)
    && $result['correctness_passed'] === true;
$evaluation = [
    'measurement_commit' => trim(file_get_contents($directory.'/commit.txt')),
    'original_result_sha256' => hash_file('sha256', $directory.'/result.json'),
    'load_sha256' => hash_file('sha256', $directory.'/load.json'),
    'checks' => $checks, 'negative_controls' => $controls, 'passed' => $passed,
    'capacity_duration_met' => ! $short && $load['steady']['seconds'] >= 1800,
    'interpretation' => 'Internal invocation p95 100ms read / 250ms posting, distinct-account lock upper-bound p99 500ms, complete populations and drained work. Scheduled/arrival latency is separately retained and is not promised as an end-to-end SLA.',
];
file_put_contents($directory.'/acceptance-evaluation.json', json_encode($evaluation, JSON_PRETTY_PRINT));
echo json_encode($evaluation, JSON_PRETTY_PRINT).PHP_EOL;
exit($passed ? 0 : 1);
