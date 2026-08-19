<?php

declare(strict_types=1);

if ($argc !== 6) {
    fwrite(STDERR, "Usage: php scripts/junit-gate-report.php <title> <junit.xml> <exit-code> <log-path> <database-name>\n");
    exit(2);
}

[$script, $title, $junitPath, $exitCode, $logPath, $database] = $argv;

if (! is_file($junitPath)) {
    fwrite(STDERR, "JUnit file not found: {$junitPath}\n");
    exit(1);
}

$xml = simplexml_load_file($junitPath);
if (! $xml instanceof SimpleXMLElement) {
    fwrite(STDERR, "Unable to parse JUnit file: {$junitPath}\n");
    exit(1);
}

$totals = [
    'tests' => 0,
    'assertions' => 0,
    'failures' => 0,
    'errors' => 0,
    'skipped' => 0,
    'time' => 0.0,
];

$suites = $xml->xpath('//testsuite[not(testsuite)]') ?: [];
foreach ($suites as $suite) {
    foreach (['tests', 'assertions', 'failures', 'errors', 'skipped'] as $attribute) {
        $totals[$attribute] += (int) $suite[$attribute];
    }
    $totals['time'] += (float) $suite['time'];
}

$problemCases = [];
foreach (($xml->xpath('//testcase[failure or error]') ?: []) as $testCase) {
    $class = (string) $testCase['class'];
    $name = (string) $testCase['name'];
    $problemCases[] = $class === '' ? $name : "{$class}::{$name}";
}

$passed = (int) $exitCode === 0
    && $totals['tests'] > 0
    && $totals['skipped'] === 0
    && $totals['failures'] === 0
    && $totals['errors'] === 0;
$status = $passed ? 'passed' : 'failed';
$generatedAt = gmdate('Y-m-d\TH:i:s\Z');

printf("# %s\n\n", $title);
echo "- Generated: {$generatedAt}\n";
echo "- Result: {$status}\n";
echo "- Exit code: {$exitCode}\n";
echo "- Tests: {$totals['tests']}\n";
echo "- Assertions: {$totals['assertions']}\n";
echo "- Skipped: {$totals['skipped']}\n";
echo "- Failures: {$totals['failures']}\n";
echo "- Errors: {$totals['errors']}\n";
echo '- Time: '.number_format($totals['time'], 3, '.', '')." seconds\n";
echo "- Database: {$database}\n";
echo "- JUnit: {$junitPath}\n";
echo "- Log: {$logPath}\n";
if ($totals['tests'] === 0) {
    echo "- Gate warning: PHPUnit reported no executed tests.\n";
}
echo "\nFailures:\n";
if ($problemCases === []) {
    echo "- None\n";
} else {
    foreach ($problemCases as $problemCase) {
        echo "- {$problemCase}\n";
    }
}
