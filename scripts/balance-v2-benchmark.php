<?php

// A CLI-only fixture harness: no route, service binding or deployment setting.
use App\Enums\StoreBalanceEntryKind;
use App\Models\Customer;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\Telegram\TelegramBotClient;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtBalanceReconciliation;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\UsdtPurchase;
use App\Services\UsdtBalance\UsdtReceiptReconciliation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require __DIR__.'/../src/vendor/autoload.php';
require __DIR__.'/fixtures/balance-v2/vendor/autoload.php';

/** Shared monotonic clock for scheduled arrivals and duration measurements. */
function benchmarkTime(): float
{
    return hrtime(true) / 1_000_000_000;
}

if (PHP_SAPI !== 'cli' || ! extension_loaded('pcntl') || ! extension_loaded('pdo_pgsql')) {
    throw new RuntimeException('CLI, pcntl and pdo_pgsql are required.');
}
$port = filter_var($argv[1] ?? '', FILTER_VALIDATE_INT);
$artifacts = realpath($argv[2] ?? '');
if (! $port || $port < 1024 || $port > 65535 || ! $artifacts
    || ! str_starts_with($artifacts, realpath(__DIR__.'/..').'/artifacts/balance-v2/')) {
    throw new RuntimeException('Only the wrapper-created disposable target and artifact directory are accepted.');
}
$smoke = ($argv[3] ?? '') === '--smoke';
$short = $smoke || ($argv[3] ?? '') === '--quick';
$parentPid = getmypid();
$children = [];
register_shutdown_function(function () use ($parentPid, &$children): void {
    if (getmypid() !== $parentPid) {
        return;
    }
    foreach ($children as $pid) {
        posix_kill($pid, SIGTERM);
        pcntl_waitpid($pid, $status);
    }
});
$benchmarkExceptionHandler = function (Throwable $exception) use ($artifacts): void {
    $failure = ['type' => $exception::class, 'message' => $exception->getMessage()];
    file_put_contents($artifacts.'/failure-'.getmypid().'.json', json_encode($failure, JSON_PRETTY_PRINT));
    fwrite(STDERR, json_encode($failure).PHP_EOL);
    exit(1);
};
set_exception_handler($benchmarkExceptionHandler);
$redisPort = filter_var($argv[4] ?? '', FILTER_VALIDATE_INT);
if (! $redisPort || $redisPort < 1024 || $redisPort > 65535) {
    throw new RuntimeException('Disposable Redis port is required.');
}
$fixtureConfig = [
    'app.env' => 'testing',
    'database.default' => 'pgsql',
    'database.connections.pgsql.host' => '127.0.0.1',
    'database.connections.pgsql.port' => $port,
    'database.connections.pgsql.database' => 'balance_v2_disposable',
    'database.connections.pgsql.username' => 'postgres',
    'database.connections.pgsql.password' => 'benchmark',
    'database.connections.pgsql.url' => null,
    'cache.default' => 'array',
    'session.driver' => 'array',
    'queue.default' => 'database',
    'database.redis.client' => 'predis',
    'database.redis.options' => ['prefix' => 'balance-v2:'],
    'database.redis.default' => ['host' => '127.0.0.1', 'port' => $redisPort, 'database' => 0],
    'queue.connections.redis' => ['driver' => 'redis', 'connection' => 'default', 'queue' => 'notifications',
        'retry_after' => 90, 'block_for' => null, 'after_commit' => true],
];
$app = require __DIR__.'/../src/bootstrap/app.php';
// Override even cached configuration before any application provider boots.
$app->afterBootstrapping(LoadConfiguration::class, function ($application) use ($fixtureConfig): void {
    $application->make('config')->set($fixtureConfig);
    $application->instance('env', 'testing');
});
$app->afterBootstrapping(HandleExceptions::class, function () use ($benchmarkExceptionHandler): void {
    set_exception_handler($benchmarkExceptionHandler);
});
$app->beforeBootstrapping(BootProviders::class, function (): void {
    Http::preventStrayRequests();
});
$app->make(Kernel::class)->bootstrap();
// Outside PHPUnit, testing mode intentionally binds the local fake bot. This
// lane must exercise the real request factory against HTTP fixtures instead.
$app->bind(TelegramBotClient::class, TelegramBotClient::class);
// Every failed measurement must fail the shell gate, including CLI exceptions.
set_exception_handler($benchmarkExceptionHandler);
DB::purge('pgsql');
Http::preventStrayRequests();
$realQueue = app('queue');
Queue::fake(); // Benchmark the DB owner. Recovery/delivery gets a separate lane.
if (DB::selectOne('select current_database() as name')->name !== 'balance_v2_disposable') {
    throw new RuntimeException('Disposable database guard failed.');
}
if ((int) DB::selectOne("select count(*) as n from pg_tables where schemaname='public'")->n !== 0) {
    throw new RuntimeException('Benchmark requires the wrapper-created empty database.');
}
if (Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
    throw new RuntimeException(Artisan::output());
}

$customers = $smoke ? 100 : 10000;
$entriesPerAccount = 100;
$seedStart = benchmarkTime();
// Bulk synthetic history keeps every sequence, projection and counterpart
// consistent without pretending these fixtures are verified provider deposits.
DB::statement("insert into customers (id, telegram_id, first_name, created_at, updated_at)
    select n, 990000000+n, 'Benchmark', now(), now() from generate_series(1, ?) n", [$customers]);
DB::statement("insert into customer_usdt_balance_accounts
    (id, customer_id, currency, balance_micro, version, last_entry_id, created_at, updated_at)
    select n, n, 'USDT', 99999901, 100, n*100, now(), now() from generate_series(1, ?) n", [$customers]);
DB::statement("insert into customer_usdt_balance_entries
    (id, customer_usdt_balance_account_id, account_sequence, kind, amount_micro,
    balance_after_micro, source_type, source_id, idempotency_key, request_fingerprint, created_at)
    select (a-1)*100+s, a, s, case when s=1 then 'funding_credit' else 'purchase_debit' end,
    case when s=1 then 100000000 else 1 end, 100000000-(s-1),
    case when s=1 then 'customer_usdt_top_up' else 'order' end,
    case when s=1 then 'fixture-fund-'||a else '56000000-0000-4000-8000-'||lpad(((a-1)*100+s)::text,12,'0') end,
    'fixture-'||a||'-'||s,
    repeat('a',64), now() from generate_series(1, ?) a cross join generate_series(1,100) s", [$customers]);
DB::statement("insert into customer_usdt_balance_accounting_events
    (customer_usdt_balance_entry_id,currency,amount_micro,debit_account,credit_account,source_fingerprint,created_at)
    select id,'USDT',amount_micro,
    case when kind='funding_credit' then 'usdt_settlement_asset' else 'customer_usdt_liability' end,
    case when kind='funding_credit' then 'customer_usdt_liability' else 'usdt_sale' end,request_fingerprint,created_at
    from customer_usdt_balance_entries");
DB::statement("with fixtures as (
    select n, '0x'||md5(n::text)||md5('receipt-'||n::text) as tx_hash from generate_series(1, ?) n
    ) insert into external_money_receipts
    (canonical_key,provider,identity_kind,account_namespace,reference,chain_id,tx_hash,log_index,
    beneficiary,currency,amount_units,decimals,status,target_type,target_id,fingerprint,claimed_at,created_at,updated_at)
    select 'bsc:56:'||tx_hash||':0','bsc','bsc_log','benchmark',substring(tx_hash from 3),56,tx_hash,0,
    'customer:'||n,'USDT','100000000000000000000',18,'claimed','customer_usdt_top_up','fixture-fund-'||n,
    repeat('b',64),now(),now(),now() from fixtures", [$customers]);
DB::statement("insert into external_money_receipt_aliases (external_money_receipt_id,alias_key,alias_type,created_at)
    select id,canonical_key,'bsc_log',now() from external_money_receipts");
DB::statement('insert into external_money_receipt_parent_claims
    (chain_id,tx_hash,external_money_receipt_id,status,target_type,target_id,created_at,updated_at)
    select chain_id,tx_hash,id,status,target_type,target_id,now(),now() from external_money_receipts');
DB::statement('insert into customer_balance_accounts (id, customer_id, balance, created_at, updated_at)
    select n, n, 100000000, now(), now() from generate_series(1, ?) n', [$customers]);
DB::statement("insert into store_balance_entries
    (id, customer_balance_account_id, kind, amount, balance_after, source_type, source_id, idempotency_key, created_at)
    select (a-1)*100+s, a, 'top_up_credit', 1000000, s*1000000,
    'store_balance_top_up', 'fixture-'||a||'-'||s, 'fixture-'||a||'-'||s,
    now() from generate_series(1, ?) a cross join generate_series(1,100) s", [$customers]);
foreach (['customers', 'customer_usdt_balance_accounts', 'customer_usdt_balance_entries', 'customer_balance_accounts', 'store_balance_entries'] as $table) {
    DB::statement("select setval(pg_get_serial_sequence('$table','id'), (select max(id) from $table))");
}
DB::statement('ANALYZE');
file_put_contents($artifacts.'/dataset.json', json_encode([
    'customers' => $customers, 'usdt_entries' => $customers * $entriesPerAccount,
    'idr_entries' => $customers * $entriesPerAccount,
    'distribution' => 'USDT: one receipt-backed synthetic 100 USDT funding plus 99 micro-unit purchases/account; IDR: 100 synthetic funding entries/account',
    'synthetic_usdt_receipts' => $customers,
    'seed' => 560, 'seed_seconds' => benchmarkTime() - $seedStart,
    'php' => PHP_VERSION, 'postgres' => DB::selectOne('select version() as version')->version,
    'laravel' => app()->version(), 'smoke' => $smoke, 'short' => $short,
], JSON_PRETTY_PRINT));
file_put_contents($artifacts.'/source-sha256.txt', hash_file('sha256', __FILE__)."  balance-v2-benchmark.php\n");
echo "Seeded $customers customers / ".($customers * 100)." entries.\n";

$queryCount = 0;
$queryMs = 0.0;
$lockQueryMs = 0.0;
DB::listen(function (QueryExecuted $query) use (&$queryCount, &$queryMs, &$lockQueryMs): void {
    $queryCount++;
    $queryMs += $query->time;
    if (str_contains(strtolower($query->sql), 'for update')) {
        $lockQueryMs += $query->time;
    }
});

// Capture actual owner-generated read SQL, rather than a simplified surrogate.
$plans = [];
$capture = true;
DB::listen(function (QueryExecuted $query) use (&$plans, &$capture): void {
    if ($capture && str_starts_with($query->sql, 'select')) {
        $capture = false;
        $plans[] = DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$query->sql, $query->bindings);
    }
});
app(CustomerUsdtBalanceLedger::class)->snapshot(Customer::findOrFail(1));
// Customer lookup is measured separately from the snapshot query plan.
$capture = true;
$customer = new Customer;
$customer->id = 1;
app(CustomerUsdtBalanceLedger::class)->snapshot($customer);
$capture = true;
app(StoreBalanceLedger::class)->snapshot($customer);
$capture = false;
file_put_contents($artifacts.'/plans.json', json_encode($plans, JSON_PRETTY_PRINT));

/** Nearest-rank percentile; empty populations are never reported as zero. */
function distribution(array $values): array
{
    sort($values, SORT_NUMERIC);
    $count = count($values);
    $result = ['count' => $count, 'max' => $count ? $values[$count - 1] : null];
    foreach ([50, 95, 99] as $percent) {
        $result['p'.$percent] = $count ? $values[(int) ceil($count * $percent / 100) - 1] : null;
    }

    return $result;
}

$phases = [
    ['steady', $short ? 5 : 1800, 50, 10, false],
    ['burst', $short ? 2 : 60, 250, 50, false],
    ['hot', $short ? 2 : 60, 250, 50, true],
];
$summaries = [];
foreach ($phases as [$phase, $seconds, $reads, $postings, $hot]) {
    echo "Starting $phase: {$seconds}s, $reads reads/s + $postings postings/s per currency.\n";
    DB::disconnect(); // No fork may share its parent's PDO connection.
    $start = benchmarkTime() + 1;
    $children = [];
    for ($worker = 0; $worker < 24; $worker++) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork benchmark worker.');
        }
        if ($pid !== 0) {
            $children[] = $pid;

            continue;
        }
        DB::purge('pgsql');
        $currency = $worker < 12 ? 'USDT' : 'IDR';
        $localWorker = $worker % 12;
        $isRead = $localWorker < 8;
        $slots = $isRead ? 8 : 4;
        $slot = $isRead ? $localWorker : $localWorker - 8;
        $rate = $isRead ? $reads : $postings;
        $handle = fopen($artifacts."/$phase-$worker.jsonl", 'xb');
        $failed = false;
        for ($ordinal = $slot; $ordinal < $rate * $seconds; $ordinal += $slots) {
            $due = $start + $ordinal / $rate;
            $sleep = $due - benchmarkTime();
            if ($sleep > 0) {
                usleep((int) ($sleep * 1000000));
            }
            $began = benchmarkTime();
            $queryCount = 0;
            $queryMs = 0.0;
            $lockQueryMs = 0.0;
            $error = null;
            try {
                $customer = new Customer;
                // Worker-disjoint account sets for distinct writers; hot is
                // deliberately shared. Deterministic reads span all accounts.
                $customer->id = $hot ? 1 : ($isRead
                    ? (($ordinal * 7919) % $customers) + 1
                    : (($ordinal % $customers) + 1));
                $ledger = $currency === 'USDT' ? app(CustomerUsdtBalanceLedger::class) : app(StoreBalanceLedger::class);
                if ($isRead) {
                    if (! $ledger->snapshot($customer)->isAvailable()) {
                        throw new RuntimeException('Snapshot unavailable.');
                    }
                } else {
                    $identity = "$phase-$worker-$ordinal";
                    if ($currency === 'USDT') {
                        $orderId = sprintf('56000000-%04x-4000-%04x-%012x',
                            array_search($phase, ['steady', 'burst', 'hot'], true) + 1, 0x8000 + $worker, $ordinal);
                        $ledger->debitPurchase($customer, new UsdtPurchase(
                            (int) $customer->id, UsdtMicroAmount::fromUnits(1), $orderId, $orderId,
                        ), $identity);
                    } else {
                        // Existing IDR full-history validation stays enabled.
                        $ledger->credit($customer, 1, StoreBalanceEntryKind::TopUpCredit,
                            'store_balance_top_up', $identity, $identity);
                    }
                }
            } catch (Throwable $exception) {
                $error = $exception::class.': '.$exception->getMessage();
                $failed = true;
            }
            fwrite($handle, json_encode([
                'operation' => strtolower($currency).'_'.($isRead ? 'read' : 'posting'),
                'latency_ms' => (benchmarkTime() - $began) * 1000,
                'scheduled_latency_ms' => (benchmarkTime() - $due) * 1000,
                'lag_ms' => max(0, ($began - $due) * 1000),
                'queries' => $queryCount, 'query_ms' => $queryMs,
                // Includes execution + transport; conservative upper bound
                // for account lock wait, not an invented exact wait metric.
                'lock_query_ms' => $lockQueryMs, 'error' => $error,
            ])."\n");
        }
        fclose($handle);
        exit($failed ? 1 : 0);
    }
    $samples = [];
    $lastHeartbeat = $start;
    do {
        usleep(100000);
        if (benchmarkTime() - $lastHeartbeat >= 30) {
            echo "$phase elapsed ".(int) (benchmarkTime() - $start).'s; '.count($children)." live workers.\n";
            $lastHeartbeat = benchmarkTime();
        }
        $samples[] = DB::selectOne("select count(*) filter (where wait_event_type='Lock') as lock_waiters,
            count(*) filter (where state='active') as active from pg_stat_activity
            where datname=current_database() and pid<>pg_backend_pid()");
        foreach ($children as $index => $pid) {
            $waited = pcntl_waitpid($pid, $status, WNOHANG);
            if ($waited === $pid) {
                if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                    throw new RuntimeException("$phase worker $pid failed; raw evidence retained.");
                }
                unset($children[$index]);
            }
        }
    } while ($children !== []);
    $elapsed = benchmarkTime() - $start;
    $summary = ['seconds' => $elapsed, 'offered_reads_s_per_currency' => $reads, 'offered_postings_s_per_currency' => $postings,
        'all_scheduled_work_completed' => true, 'remaining_scheduled_work' => 0];
    foreach (['usdt_read', 'usdt_posting', 'idr_read', 'idr_posting'] as $operation) {
        $rows = [];
        for ($worker = 0; $worker < 24; $worker++) {
            foreach (file($artifacts."/$phase-$worker.jsonl", FILE_IGNORE_NEW_LINES) as $line) {
                $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if ($row['operation'] === $operation) {
                    $rows[] = $row;
                }
            }
        }
        $result = ['throughput_s' => count($rows) / $elapsed, 'errors' => count(array_filter($rows, fn ($r) => $r['error'] !== null))];
        foreach (['latency_ms', 'scheduled_latency_ms', 'lag_ms', 'queries', 'query_ms', 'lock_query_ms'] as $metric) {
            $result[$metric] = distribution(array_column($rows, $metric));
        }
        $summary[$operation] = $result;
    }
    $summary['lock_wait_samples'] = distribution(array_map(fn ($r) => (int) $r->lock_waiters, $samples));
    file_put_contents($artifacts."/$phase-lock-samples.json", json_encode($samples));
    $summaries[$phase] = $summary;
    file_put_contents($artifacts.'/load.json', json_encode($summaries, JSON_PRETTY_PRINT));
    echo json_encode([$phase => $summary])."\n";
}

require __DIR__.'/balance-v2-operational-probes.php';

$reconciliationQuery = null;
DB::listen(function (QueryExecuted $query) use (&$reconciliationQuery): void {
    if ($reconciliationQuery === null && str_contains($query->sql, 'min_sequence')) {
        $reconciliationQuery = ['sql' => $query->sql, 'bindings' => $query->bindings];
    }
});
$began = benchmarkTime();
$cursor = null;
$inspected = 0;
$findings = 0;
$chunks = [];
do {
    $chunkStart = benchmarkTime();
    $report = app(UsdtBalanceReconciliation::class)->inspect(null, $cursor, 200);
    $inspected += $report->accountsInspected;
    $findings += count($report->findings);
    $cursor = $report->nextCursor;
    $chunks[] = (benchmarkTime() - $chunkStart) * 1000;
    file_put_contents($artifacts.'/reconciliation-checkpoint.json', json_encode([
        'inspected' => $inspected, 'findings' => $findings, 'cursor' => $cursor,
    ]));
} while ($report->hasMore);
$reconciliationSeconds = benchmarkTime() - $began;
if ($reconciliationQuery !== null) {
    file_put_contents($artifacts.'/reconciliation-plan.json', json_encode([
        'sql' => $reconciliationQuery['sql'],
        'plan' => DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$reconciliationQuery['sql'], $reconciliationQuery['bindings']),
    ], JSON_PRETTY_PRINT));
}
$accounting = DB::selectOne('select count(*) as mismatches from customer_usdt_balance_entries e
    left join customer_usdt_balance_accounting_events a on a.customer_usdt_balance_entry_id=e.id
    where a.id is null or a.amount_micro<>e.amount_micro or a.source_fingerprint<>e.request_fingerprint');
$correct = $inspected === $customers && $findings === 0 && (int) $accounting->mismatches === 0;
$idrFindings = 0;
for ($after = 0; $after < $customers; $after += 200) {
    $result = DB::selectOne("select count(*) as n from customer_balance_accounts a
        left join (select customer_balance_account_id, sum(case when kind in ('purchase_debit','admin_adjustment_reversal')
        then -amount else amount end) as signed_sum from store_balance_entries
        where customer_balance_account_id > ? and customer_balance_account_id <= ? group by customer_balance_account_id) e
        on e.customer_balance_account_id=a.id where a.id > ? and a.id <= ? and a.balance <> coalesce(e.signed_sum,0)",
        [$after, $after + 200, $after, $after + 200]);
    $idrFindings += (int) $result->n;
}
$correct = $correct && $idrFindings === 0;
$receiptStart = benchmarkTime();
$receiptCursor = null;
$creditCursor = null;
$receiptFindings = 0;
$receiptsInspected = 0;
$receiptsDone = false;
$creditsDone = false;
do {
    $receiptReport = app(UsdtReceiptReconciliation::class)->inspect(null,
        $receiptsDone ? PHP_INT_MAX : $receiptCursor, $creditsDone ? PHP_INT_MAX : $creditCursor, 1000);
    $receiptFindings += count($receiptReport->findings);
    $receiptsInspected += $receiptReport->receiptsInspected;
    $receiptCursor = $receiptReport->nextReceiptCursor;
    $creditCursor = $receiptReport->nextCreditCursor;
    $receiptsDone = ! $receiptReport->receiptsHasMore;
    $creditsDone = ! $receiptReport->creditsHaveMore;
} while (! $receiptsDone || ! $creditsDone);
$correct = $correct && $receiptsInspected === $customers && $receiptFindings === 0;
$targets = true;
foreach (['usdt', 'idr'] as $currency) {
    $targets = $targets && $summaries['steady'][$currency.'_read']['scheduled_latency_ms']['p95'] <= 100
        && $summaries['steady'][$currency.'_posting']['scheduled_latency_ms']['p95'] <= 250
        && $summaries['steady'][$currency.'_posting']['lock_query_ms']['p99'] <= 500
        && $summaries['burst'][$currency.'_posting']['lag_ms']['p99'] <= 250;
}
file_put_contents($artifacts.'/result.json', json_encode([
    'profile' => $short ? 'diagnostic-not-capacity-evidence' : 'representative-local-fixture',
    'correctness_passed' => $correct, 'load_targets_passed' => $targets,
    'reconciliation_seconds' => $reconciliationSeconds, 'reconciliation_chunks_ms' => distribution($chunks),
    'accounts_inspected' => $inspected, 'findings' => $findings,
    'idr_accounts_inspected' => $customers, 'idr_findings' => $idrFindings,
    'accounting_mismatches' => (int) $accounting->mismatches,
    'receipts_inspected' => $receiptsInspected, 'receipt_findings' => $receiptFindings,
    'receipt_reconciliation_seconds' => benchmarkTime() - $receiptStart,
    'limitations' => ['Local disposable hardware, not production capacity.',
        'Synthetic ledger/receipt fixtures do not prove custody or provider qualification.',
        'Lock-query duration bounds wait; pg_stat_activity samples and server logs complement it.',
        'Application ledger benchmark excludes controller, checkout inventory and external provider latency.'],
], JSON_PRETTY_PRINT));
$lines = [
    '# Balance V2 benchmark evidence', '',
    'Commit: `'.trim(file_get_contents($artifacts.'/commit.txt')).'`; see worktree patch/status and copied source hashes for the exact code.', '',
    'Profile: '.($short ? '**diagnostic only; duration gate not met**' : 'representative local disposable fixtures; not production capacity').'.', '',
    "Dataset: $customers Customers; ".($customers * 100).' entries **per currency** before load; 24 independent load workers.', '',
    '| Phase | Operation | Count | p50 ms | p95 ms | p99 ms | Scheduled p95 ms | Query p95 | Completed/s |',
    '| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |',
];
foreach ($summaries as $phase => $summary) {
    foreach (['usdt_read', 'usdt_posting', 'idr_read', 'idr_posting'] as $operation) {
        $row = $summary[$operation];
        $lines[] = sprintf('| %s | %s | %d | %.3f | %.3f | %.3f | %.3f | %d | %.3f |',
            $phase, $operation, $row['latency_ms']['count'], $row['latency_ms']['p50'],
            $row['latency_ms']['p95'], $row['latency_ms']['p99'], $row['scheduled_latency_ms']['p95'],
            $row['queries']['p95'], $row['throughput_s']);
    }
}
$lines = [...$lines, '',
    'Load targets: '.($targets ? 'passed' : '**failed; no target relaxation**').'. Correctness: '.($correct ? 'passed' : '**failed**').'.', '',
    "Full USDT history: $inspected accounts / $findings findings; IDR history: $customers accounts / $idrFindings findings.", '',
    "Synthetic USDT receipts: $receiptsInspected inspected / $receiptFindings findings.", '',
    'See result.json for reconciliation timings, operations.json for reservations/history/provider budgets/fairness and Redis backlog recovery, load.json for lock bounds and scheduling lag.', '',
    'Query plans and PostgreSQL wait samples/logs are retained. FOR UPDATE durations bound wait and include execution/transport. CPU, memory, filesystem and container details are in adjacent artifacts.', '',
    'Synthetic funding/purchase sources and HTTP/RPC fixtures do not qualify custody, live provider latency, treasury backing or production capacity. The ledger benchmark excludes controller/inventory/checkout latency. No production deployment, receipt claim or funding activation occurred.',
];
file_put_contents($artifacts.'/report.md', implode(PHP_EOL, $lines).PHP_EOL);
echo "Reconciliation: $inspected accounts, $findings findings.\n";
exit($correct && $targets ? 0 : 1);
