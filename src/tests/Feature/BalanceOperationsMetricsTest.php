<?php

namespace Tests\Feature;

use App\Enums\BalanceOperationMetric;
use App\Enums\CustomerUsdtFundingRail;
use App\Enums\ExternalMoneyReceiptClaimOutcome;
use App\Enums\UserRole;
use App\Exceptions\CustomerUsdtFundingUnavailable;
use App\Models\Customer;
use App\Models\User;
use App\Services\MoneyReceipts\ExternalMoneyReceiptClaims;
use App\Services\MoneyReceipts\MoneyReceiptIdentity;
use App\Services\MoneyReceipts\MoneyReceiptParentClaim;
use App\Services\MoneyReceipts\MoneyReceiptTarget;
use App\Services\MoneyReceipts\VerifiedMoneyReceipt;
use App\Services\Operations\BalanceOperationEventRecorder;
use App\Services\Operations\BalanceOperationMetrics;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\VerifiedUsdtFunding;
use App\Services\UsdtFunding\CustomerUsdtFundingPolicyManager;
use App\Services\UsdtFunding\CustomerUsdtFundingReservationService;
use App\Services\UsdtFunding\UsdtFundingRailReadiness;
use App\Services\UsdtFunding\UsdtFundingRailReadinessObservation;
use App\Services\UsdtFunding\UsdtFundingRailReadinessRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class BalanceOperationsMetricsTest extends TestCase
{
    use RefreshDatabase;

    private const TOP_LEVEL_KEYS = [
        'generated_at',
        'window_minutes',
        'counters',
        'backlog',
        'drift',
        'coverage',
        'conflict',
        'controls',
    ];

    public function test_report_has_the_fixed_shape_and_zero_defaults_on_an_empty_database(): void
    {
        $report = app(BalanceOperationMetrics::class)->report();

        $this->assertSame(self::TOP_LEVEL_KEYS, array_keys($report));
        $this->assertIsInt($report['window_minutes']);
        $this->assertSame(60, $report['window_minutes']);
        $this->assertNotFalse(strtotime((string) $report['generated_at']));

        foreach (BalanceOperationMetric::cases() as $metric) {
            $this->assertArrayHasKey($metric->value, $report['counters']);
            $this->assertSame($metric->outcomes(), array_keys($report['counters'][$metric->value]));
            foreach ($report['counters'][$metric->value] as $outcome => $count) {
                $this->assertSame(0, $count, "Expected zero default for {$metric->value}/{$outcome}.");
            }
        }

        $this->assertSame(
            ['top_up_preparation', 'top_up_settlement', 'funding_reservations', 'balance_outbound', 'top_up_review'],
            array_keys($report['backlog']),
        );
        $this->assertSame(['pending', 'oldest_age_seconds'], array_keys($report['backlog']['top_up_preparation']));
        $this->assertSame(['open', 'oldest_age_seconds'], array_keys($report['backlog']['top_up_settlement']));
        $this->assertSame(['unresolved', 'oldest_age_seconds'], array_keys($report['backlog']['funding_reservations']));
        $this->assertSame(['pending', 'oldest_age_seconds'], array_keys($report['backlog']['balance_outbound']));
        $this->assertSame(['open', 'oldest_age_seconds'], array_keys($report['backlog']['top_up_review']));
        foreach ($report['backlog'] as $facet) {
            foreach ($facet as $value) {
                $this->assertSame(0, $value);
            }
        }

        $this->assertSame(0, $report['drift']['frozen_accounts']);
        $this->assertNull($report['drift']['latest']);

        $this->assertSame(['ledger_liability_micro', 'unresolved_deposit_micro', 'custody_state', 'custody_assets_micro', 'verdict'], array_keys($report['coverage']));
        $this->assertSame('0', $report['coverage']['ledger_liability_micro']);
        $this->assertSame('0', $report['coverage']['unresolved_deposit_micro']);
        $this->assertNull($report['coverage']['custody_state']);
        $this->assertNull($report['coverage']['custody_assets_micro']);
        $this->assertNull($report['coverage']['verdict']);

        $this->assertSame(['review_receipts', 'review_parent_claims', 'open_registry_conflict_reservations'], array_keys($report['conflict']));
        $this->assertSame([0, 0, 0], array_values($report['conflict']));

        $this->assertSame(['pauses', 'restore_gate'], array_keys($report['controls']));
        $this->assertSame([], $report['controls']['pauses']);
        $this->assertSame(
            ['state', 'opened_at', 'window_start', 'window_end', 'replay_completed_at'],
            array_keys($report['controls']['restore_gate']),
        );
        $this->assertSame('cleared', $report['controls']['restore_gate']['state']);
        $this->assertNull($report['controls']['restore_gate']['opened_at']);
    }

    public function test_counters_increment_for_posted_and_replayed_ledger_mutations(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 902101]);
        $source = $this->funding($customer->id);
        $ledger = app(CustomerUsdtBalanceLedger::class);

        $ledger->creditVerifiedTopUp($customer, $source, 'usdt:metrics:1');
        $replayed = $ledger->creditVerifiedTopUp($customer, $source, 'usdt:metrics:1');

        $this->assertFalse($replayed->applied);
        $this->assertSame(2, DB::table('balance_operation_events')->count());

        $counters = app(BalanceOperationMetrics::class)->report()['counters']['ledger_outcome'];
        $this->assertSame(1, $counters['posted']);
        $this->assertSame(1, $counters['replayed']);
        $this->assertSame(0, $counters['insufficient_funds']);
        $this->assertSame(0, $counters['frozen']);
        $this->assertSame(0, $counters['projection_drift']);
        $this->assertSame(0, $counters['identity_conflict']);
    }

    public function test_a_paused_database_reports_zeros_without_error(): void
    {
        DB::table('customer_usdt_funding_pauses')->insert([
            'scope' => 'funding',
            'reason' => 'operational pause test',
            'paused_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $report = app(BalanceOperationMetrics::class)->report();

        $this->assertSame(['funding'], $report['controls']['pauses']);
        $this->assertSame(0, $report['drift']['frozen_accounts']);
        $this->assertNull($report['drift']['latest']);
        $this->assertSame('0', $report['coverage']['ledger_liability_micro']);
        $this->assertSame([0, 0, 0], array_values($report['conflict']));
        $this->assertSame('cleared', $report['controls']['restore_gate']['state']);
    }

    public function test_json_output_is_valid_json_with_the_allowlisted_keys_only(): void
    {
        $this->assertSame(0, Artisan::call('usdt-balance:metrics', ['--json' => true, '--window-minutes' => 30]));

        /** @var array<string, mixed> $report */
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(self::TOP_LEVEL_KEYS, array_keys($report));
        $this->assertSame(30, $report['window_minutes']);
        $this->assertSame(
            ['ledger_liability_micro', 'unresolved_deposit_micro', 'custody_state', 'custody_assets_micro', 'verdict'],
            array_keys($report['coverage']),
        );

        foreach (BalanceOperationMetric::cases() as $metric) {
            $this->assertSame($metric->outcomes(), array_keys($report['counters'][$metric->value]));
        }
    }

    public function test_prune_days_deletes_only_old_events_and_reports_the_count(): void
    {
        DB::table('balance_operation_events')->insert([
            'metric' => BalanceOperationMetric::LedgerOutcome->value,
            'outcome' => 'posted',
            'occurred_at' => now()->subDays(40),
            'created_at' => now()->subDays(40),
        ]);
        DB::table('balance_operation_events')->insert([
            'metric' => BalanceOperationMetric::LedgerOutcome->value,
            'outcome' => 'replayed',
            'occurred_at' => now()->subDay(),
            'created_at' => now()->subDay(),
        ]);

        $this->artisan('usdt-balance:metrics', ['--prune-days' => 30])
            ->expectsOutputToContain('Pruned 1 metric event(s) older than 30 day(s).')
            ->assertSuccessful();

        $remaining = DB::table('balance_operation_events')->pluck('outcome')->all();
        $this->assertSame(['replayed'], $remaining);
    }

    public function test_stored_metric_columns_stay_within_closed_bounded_allowlists(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 902106]);
        $source = $this->funding($customer->id, 1_000_000, 'topup-card', 'receipt-card');
        $ledger = app(CustomerUsdtBalanceLedger::class);
        $ledger->creditVerifiedTopUp($customer, $source, 'usdt:cardinality:1');
        $ledger->creditVerifiedTopUp($customer, $source, 'usdt:cardinality:1');

        $receiptHash = $this->txHash('cardinality-receipt');
        $claims = app(ExternalMoneyReceiptClaims::class);
        $claims->claim($this->bscReceipt('cardinality-receipt'));
        $claims->claim($this->bscReceipt('cardinality-receipt'));

        $rows = DB::table('balance_operation_events')->get(['metric', 'outcome', 'bucket', 'value']);
        $this->assertCount(4, $rows);

        $this->assertEqualsCanonicalizing(
            [BalanceOperationMetric::LedgerOutcome->value, BalanceOperationMetric::ReceiptClaimOutcome->value],
            $rows->pluck('metric')->unique()->values()->all(),
        );
        $this->assertEqualsCanonicalizing(['posted', 'replayed', 'claimed'], $rows->pluck('outcome')->unique()->values()->all());
        $this->assertSame([], $rows->pluck('bucket')->filter()->unique()->values()->all());

        $poison = [
            (string) $customer->id,
            (string) $customer->telegram_id,
            $receiptHash,
            '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        ];

        foreach ($rows as $row) {
            $metric = BalanceOperationMetric::from($row->metric);
            $this->assertContains($row->outcome, $metric->outcomes());
            $this->assertTrue($row->bucket === null || in_array($row->bucket, BalanceOperationEventRecorder::DURATION_BUCKETS, true));
            $this->assertTrue($row->value === null || is_int((int) $row->value));

            foreach ([$row->metric, $row->outcome, $row->bucket, $row->value === null ? null : (string) $row->value] as $stored) {
                if ($stored === null) {
                    continue;
                }

                $this->assertNotContains($stored, $poison);
                $this->assertDoesNotMatchRegularExpression('/0x[0-9a-fA-F]{40,64}/', $stored);
                $this->assertDoesNotMatchRegularExpression('/^[0-9a-f]{64}$/', $stored);
            }
        }
    }

    public function test_receipt_claim_and_replay_record_exactly_one_bounded_outcome(): void
    {
        $claims = app(ExternalMoneyReceiptClaims::class);
        $receipt = $this->bscReceipt('metrics-claim');

        $this->assertSame(ExternalMoneyReceiptClaimOutcome::Claimed, $claims->claim($receipt));
        $this->assertSame(ExternalMoneyReceiptClaimOutcome::Replayed, $claims->claim($receipt));

        $outcomes = DB::table('balance_operation_events')
            ->where('metric', BalanceOperationMetric::ReceiptClaimOutcome->value)
            ->pluck('outcome')
            ->all();

        $this->assertEqualsCanonicalizing(['claimed', 'replayed'], $outcomes);
    }

    public function test_reservation_records_exactly_one_outcome_per_call(): void
    {
        $customer = Customer::factory()->create();
        $this->enableFunding();
        $this->registerReadiness(CustomerUsdtFundingRail::BscUsdt, true);

        $service = app(CustomerUsdtFundingReservationService::class);
        $amount = UsdtMicroAmount::fromUnits(1_000_000);
        $service->reserve($customer, CustomerUsdtFundingRail::BscUsdt, $amount, 'usdt_funding_intent', 'intent-1');
        $service->reserve($customer, CustomerUsdtFundingRail::BscUsdt, $amount, 'usdt_funding_intent', 'intent-1');
        $service->reserve($customer, CustomerUsdtFundingRail::BscUsdt, $amount, 'usdt_funding_intent', 'intent-2');

        $blocked = Customer::factory()->create();
        try {
            $service->reserve($blocked, CustomerUsdtFundingRail::BinancePay, $amount, 'usdt_funding_intent', 'intent-blocked');
            $this->fail('Expected an unregistered rail to fail closed.');
        } catch (CustomerUsdtFundingUnavailable) {
            // Expected: the unsuccessful call records the conflict outcome.
        }

        $outcomes = DB::table('balance_operation_events')
            ->where('metric', BalanceOperationMetric::FundingReservationOutcome->value)
            ->pluck('outcome')
            ->all();

        $this->assertCount(4, $outcomes);
        $this->assertEqualsCanonicalizing(['reserved', 'replayed', 'existing_active', 'conflict'], $outcomes);
    }

    private function funding(
        int $customerId,
        int $amountMicro = 5_000_000,
        string $topUpId = 'topup-1',
        string $receiptId = 'receipt-1',
    ): VerifiedUsdtFunding {
        return new VerifiedUsdtFunding($customerId, UsdtMicroAmount::fromUnits($amountMicro), $topUpId, $receiptId, 'v1');
    }

    private function bscReceipt(string $seed): VerifiedMoneyReceipt
    {
        $hash = $this->txHash($seed);

        return new VerifiedMoneyReceipt(
            identity: MoneyReceiptIdentity::bscLog(56, $hash, 0),
            beneficiary: '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            currency: 'USDT',
            amountUnits: '2500000',
            decimals: 6,
            target: MoneyReceiptTarget::orderPayment('order-1'),
            evidence: ['tx_hash' => $hash, 'log_index' => 0, 'confirmations' => 12],
            aliases: [],
            parentClaim: MoneyReceiptParentClaim::for(56, $hash),
        );
    }

    private function txHash(string $seed): string
    {
        return '0x'.substr(hash('sha256', $seed), 0, 64);
    }

    private function enableFunding(int $ttlSeconds = 600, int $feeMicro = 0): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        app(CustomerUsdtFundingPolicyManager::class)->change(
            true,
            $ttlSeconds,
            $feeMicro,
            'Syarat top-up pengujian.',
            0,
            $owner,
            null,
        );
    }

    private function registerReadiness(CustomerUsdtFundingRail $rail, bool $ready, ?string $reason = null): void
    {
        $alias = 'testing.usdt-funding-readiness.'.$rail->value;
        $provider = new class($rail->value, $ready, $reason) implements UsdtFundingRailReadiness
        {
            public function __construct(
                private readonly string $key,
                private readonly bool $ready,
                private readonly ?string $reason,
            ) {}

            public function railKey(): string
            {
                return $this->key;
            }

            public function assertRail(CustomerUsdtFundingRail $rail): void {}

            public function readiness(): UsdtFundingRailReadinessObservation
            {
                return $this->ready
                    ? UsdtFundingRailReadinessObservation::ready($this->key)
                    : UsdtFundingRailReadinessObservation::unavailable($this->key, $this->reason ?? 'not_ready');
            }
        };

        $this->app->instance($alias, $provider);
        $this->app->tag([$alias], 'usdt-funding-rail-readiness');
        $this->app->forgetInstance(UsdtFundingRailReadinessRegistry::class);
    }
}
