<?php

// Included only by the guarded disposable benchmark harness.
use App\Enums\CustomerUsdtFundingRail;
use App\Enums\TelegramOutboundEffectType;
use App\Enums\UserRole;
use App\Jobs\SendUsdtTopUpReceipt;
use App\Models\Customer;
use App\Models\TelegramOutboundEffect;
use App\Models\User;
use App\Services\Bsc\BscVerificationRpcClientInterface;
use App\Services\StoreBalance\StoreBalanceActivityService;
use App\Services\Telegram\UsdtTopUpOutbound;
use App\Services\UsdtBalance\CustomerUsdtActivityService;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtFunding\CustomerUsdtFundingReservationService;
use App\Services\UsdtFunding\UsdtFundingRailReadiness;
use App\Services\UsdtFunding\UsdtFundingRailReadinessObservation;
use App\Services\UsdtFunding\UsdtFundingRailReadinessRegistry;
use App\Services\UsdtReceivers\UsdtDepositReceiverRegistry;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\Support\BscUsdtRailRpcFixture;

$operational = [];
$historyQueries = [];
$captureHistory = true;
DB::listen(function (QueryExecuted $query) use (&$historyQueries, &$captureHistory): void {
    if ($captureHistory && str_starts_with($query->sql, 'select')) {
        $historyQueries[$query->sql] = $query->bindings;
    }
});
foreach ([25, 100] as $pageSize) {
    foreach (['usdt', 'idr'] as $currency) {
        $historyRows = [];
        for ($i = 1; $i <= 20; $i++) {
            $customer = new Customer;
            $customer->id = $i;
            $queryCount = 0;
            $began = benchmarkTime();
            $page = $currency === 'usdt'
                ? app(CustomerUsdtActivityService::class)->firstPage($customer, $pageSize)
                : app(StoreBalanceActivityService::class)->pageForCustomer($customer, null, false, $pageSize);
            if (count($page->items) > $pageSize) {
                throw new RuntimeException('History page exceeded its limit.');
            }
            $historyRows[] = ['ms' => (benchmarkTime() - $began) * 1000, 'queries' => $queryCount];
        }
        $operational['history'][$currency][$pageSize] = [
            'latency_ms' => distribution(array_column($historyRows, 'ms')),
            'queries' => distribution(array_column($historyRows, 'queries')),
        ];
    }
}
$captureHistory = false;
$historyPlans = [];
foreach ($historyQueries as $sql => $bindings) {
    $historyPlans[] = ['sql' => $sql, 'plan' => DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$sql, $bindings)];
}
file_put_contents($artifacts.'/history-plans.json', json_encode($historyPlans, JSON_PRETTY_PRINT));
$readiness = new class implements UsdtFundingRailReadiness
{
    public function railKey(): string
    {
        return CustomerUsdtFundingRail::BscUsdt->value;
    }

    public function readiness(): UsdtFundingRailReadinessObservation
    {
        return UsdtFundingRailReadinessObservation::ready($this->railKey());
    }

    public function assertRail(CustomerUsdtFundingRail $rail): void
    {
        if ($rail->value !== $this->railKey()) {
            throw new RuntimeException('Wrong fixture rail.');
        }
    }
};
app()->instance(UsdtFundingRailReadinessRegistry::class, new UsdtFundingRailReadinessRegistry([$readiness]));
// Only the guarded disposable database receives the fixture activation flag.
DB::table('customer_usdt_funding_policies')->update(['funding_enabled' => true]);
app()->forgetInstance(CustomerUsdtFundingReservationService::class);
DB::disconnect();
$children = [];
$quotaCount = $smoke ? 20 : 1000;
foreach (['distinct', 'hot'] as $reservationScenario) {
    DB::disconnect();
    $reservationStart = benchmarkTime();
    for ($worker = 0; $worker < 4; $worker++) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork reservation probe.');
        }
        if ($pid !== 0) {
            $children[] = $pid;

            continue;
        }
        DB::purge('pgsql');
        $rows = [];
        for ($i = $worker; $i < $quotaCount; $i += 4) {
            $customer = new Customer;
            $customer->id = $reservationScenario === 'hot' ? $customers : ($i % $customers) + 1;
            $queryCount = 0;
            $lockQueryMs = 0.0;
            $began = benchmarkTime();
            $result = app(CustomerUsdtFundingReservationService::class)->reserve(
                $customer, CustomerUsdtFundingRail::BscUsdt, UsdtMicroAmount::fromUnits(1000000),
                'benchmark', 'reservation-'.$reservationScenario.'-'.$i,
            );
            $rows[] = ['ms' => (benchmarkTime() - $began) * 1000, 'queries' => $queryCount,
                'lock_query_ms' => $lockQueryMs, 'outcome' => $result->outcome->value];
        }
        file_put_contents($artifacts."/reservations-$reservationScenario-$worker.json", json_encode($rows));
        exit(0);
    }
    foreach ($children as $index => $pid) {
        pcntl_waitpid($pid, $status);
        unset($children[$index]);
        if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
            throw new RuntimeException('Reservation probe failed.');
        }
    }
    $rows = [];
    for ($worker = 0; $worker < 4; $worker++) {
        $rows = [...$rows, ...json_decode(file_get_contents($artifacts."/reservations-$reservationScenario-$worker.json"), true, flags: JSON_THROW_ON_ERROR)];
    }
    $operational['reservation'][$reservationScenario] = ['processes' => 4, 'operations' => $quotaCount,
        'throughput_s' => $quotaCount / (benchmarkTime() - $reservationStart),
        'latency_ms' => distribution(array_column($rows, 'ms')),
        'queries' => distribution(array_column($rows, 'queries')),
        'policy_and_customer_lock_query_ms' => distribution(array_column($rows, 'lock_query_ms')),
        'outcomes' => array_count_values(array_column($rows, 'outcome')),
        'policy' => 'H0-v1 uncapped; one active target per Customer, serialized policy safety lock'];
}
$duplicates = DB::selectOne('select count(*) as n from (select customer_id from customer_usdt_funding_reservations
    where resolved_at is null group by customer_id having count(*)>1) violations');
if ((int) $duplicates->n !== 0) {
    throw new RuntimeException('Reservation oversubscription.');
}

// Fairness and outage through the real scheduler, with local RPC fixtures.
$owner = User::factory()->create(['role' => UserRole::Owner]);
$registry = app(UsdtDepositReceiverRegistry::class);
$receivers = [];
for ($i = 1; $i <= 12; $i++) {
    $address = $i === 1 ? BscUsdtRailRpcFixture::ADDRESS : '0x'.str_pad(dechex($i), 40, '0', STR_PAD_LEFT);
    $receiver = $registry->register(Customer::findOrFail($i), $address, $owner)->receiver;
    $receiver = $registry->manualVerify($receiver, true, $owner);
    $receivers[] = $receiver->id;
    DB::table('usdt_bsc_scan_checkpoints')->insert([
        'receiver_id' => $receiver->id, 'first_block' => 100, 'version' => 0, 'updated_at' => now(),
    ]);
}
$rpc = new BscUsdtRailRpcFixture;
for ($i = 0; $i < 100; $i++) {
    $rpc->logs[] = $rpc->log(100, $i);
}
$rpc->failMethods = ['chainId'];
app()->instance(BscVerificationRpcClientInterface::class, $rpc);
$ticks = [];
$queries = [];
$recordQueries = true;
DB::listen(function (QueryExecuted $query) use (&$queries, &$recordQueries): void {
    if ($recordQueries && str_starts_with($query->sql, 'select')) {
        $queries[$query->sql] = $query->bindings;
    }
});
foreach (['outage', 'recovered'] as $stage) {
    if ($stage === 'recovered') {
        $rpc->failMethods = [];
    }
    for ($tick = 0; $tick < 6; $tick++) {
        $rpc->calls = [];
        $queryCount = 0;
        $began = benchmarkTime();
        Artisan::call('usdt-funding:recover', ['--limit' => 25, '--json' => true]);
        $report = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        $ticks[] = ['stage' => $stage, 'ms' => (benchmarkTime() - $began) * 1000,
            'queries' => $queryCount, 'rpc_calls' => count($rpc->calls), 'report' => $report];
        if ($report['scan_requests'] > 32) {
            throw new RuntimeException('Scheduler exceeded shared RPC budget.');
        }
        if ($stage === 'outage' && DB::table('usdt_bsc_scan_checkpoints')->whereNotNull('last_block')->exists()) {
            throw new RuntimeException('Outage incorrectly advanced durable checkpoint.');
        }
    }
}
$recordQueries = false;
$unfinished = DB::table('usdt_bsc_scan_checkpoints')->whereNull('last_attempt_at')->count();
$uncaught = DB::table('usdt_bsc_scan_checkpoints')->whereNull('last_block')->count();
$observations = DB::table('usdt_bsc_transfer_observations')->count();
$operational['ingestion'] = ['receivers' => count($receivers), 'ticks' => $ticks,
    'never_attempted' => $unfinished, 'not_caught_up' => $uncaught,
    'observed_logs' => $observations,
    'provider' => 'BscUsdtRailRpcFixture; 100 synthetic transfer logs, zero external requests or money claims'];
file_put_contents($artifacts.'/operations.json', json_encode($operational, JSON_PRETTY_PRINT));
if ($unfinished || $uncaught || $observations !== 100) {
    throw new RuntimeException("Receiver scheduler failed: never_attempted=$unfinished not_caught_up=$uncaught observations=$observations.");
}
$plans = [];
foreach ($queries as $sql => $bindings) {
    $plans[] = ['sql' => $sql, 'plan' => DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$sql, $bindings)];
}
file_put_contents($artifacts.'/scheduler-plans.json', json_encode($plans, JSON_PRETTY_PRINT));

// Real Laravel Redis transport loss: durable effects survive FLUSHDB, then
// the repository recovery command rediscovers jobs and the actual handler
// delivers each immutable message once to a local HTTP fixture.
Queue::swap($realQueue);
config(['queue.default' => 'redis', 'services.telegram.token' => 'benchmark-fixture']);
$queue = app('queue')->connection('redis');
$effectCount = $smoke ? 20 : 500;
for ($i = 0; $i < $effectCount; $i++) {
    TelegramOutboundEffect::query()->create([
        'effect_key' => 'benchmark-receipt-'.$i, 'effect_group_key' => 'benchmark-receipt-'.$i,
        'sequence' => 1, 'effect_type' => TelegramOutboundEffectType::UsdtRefundReceipt->value,
        'source_type' => Customer::class, 'source_id' => 'fixture-'.$i, 'target_type' => 'customer',
        'telegram_chat_id' => (string) (990000001 + ($i % $customers)), 'queue' => 'notifications',
        'telegram_method' => 'sendMessage', 'locale' => 'id', 'template_version' => 'usdt-refund-receipt-v1',
        'state' => 'pending', 'attempts' => 0, 'next_attempt_at' => now(),
        'outcome_metadata' => ['message' => 'Fixture receipt '.$i],
    ]);
}
$began = benchmarkTime();
$oldestCreated = TelegramOutboundEffect::query()->oldest('created_at')->value('created_at');
$oldestBeforeSeconds = $oldestCreated === null ? null : abs(now()->diffInSeconds($oldestCreated));
Artisan::call('telegram:recover-outbound', ['--chunk' => 25]);
$queuedBeforeLoss = $queue->size('notifications');
Redis::connection()->flushdb(); // This guarded connection points only to the new disposable Redis.
$queuedAfterLoss = $queue->size('notifications');
if ($queuedBeforeLoss !== $effectCount || $queuedAfterLoss !== 0) {
    throw new RuntimeException('Redis wakeup loss probe did not lose the actual jobs.');
}
Artisan::call('telegram:recover-outbound', ['--chunk' => 25]);
$recovered = $queue->size('notifications');
Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 560]], 200)]);
$deliveries = [];
while ($job = $queue->pop('notifications')) {
    $deliveryStart = benchmarkTime();
    $job->fire();
    $job->delete();
    $deliveries[] = (benchmarkTime() - $deliveryStart) * 1000;
}
$sent = TelegramOutboundEffect::query()->where('state', 'sent')->count();
$remaining = TelegramOutboundEffect::query()->where('state', '<>', 'sent')->count();
$requests = count(Http::recorded());
// Replaying actual handlers must consume no extra request or effect.
foreach (TelegramOutboundEffect::all() as $effect) {
    (new SendUsdtTopUpReceipt((string) $effect->id))->handle(app(UsdtTopUpOutbound::class));
}
$operational['outbound'] = ['effects' => $effectCount, 'queued_before_loss' => $queuedBeforeLoss,
    'queued_after_loss' => $queuedAfterLoss, 'rediscovered' => $recovered,
    'sent' => $sent, 'backlog_after' => $remaining, 'oldest_work_after_seconds' => null,
    'oldest_work_before_seconds' => $oldestBeforeSeconds, 'peak_backlog' => $effectCount,
    'drain_seconds' => benchmarkTime() - $began, 'delivery_ms' => distribution($deliveries),
    'requests' => $requests, 'requests_after_replay' => count(Http::recorded()),
    'provider' => 'Laravel HTTP fixture; no Telegram network calls'];
file_put_contents($artifacts.'/operations.json', json_encode($operational, JSON_PRETTY_PRINT));
if ($sent !== $effectCount || $remaining !== 0 || $requests !== $effectCount
    || count(Http::recorded()) !== $effectCount || $recovered !== $effectCount) {
    throw new RuntimeException("Durable recovery failed: sent=$sent remaining=$remaining requests=$requests rediscovered=$recovered expected=$effectCount.");
}
echo 'Operational probes passed: reservation, ingestion, Redis loss/recovery.'.PHP_EOL;
