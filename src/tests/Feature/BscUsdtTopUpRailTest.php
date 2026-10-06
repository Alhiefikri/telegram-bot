<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\UsdtBscRailInvoice;
use App\Models\User;
use App\Services\Bsc\BscRpcClient;
use App\Services\Bsc\BscVerificationRpcClientInterface;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtReceivers\UsdtDepositReceiverRegistry;
use App\Services\UsdtTopUps\UsdtTopUpRail;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\BscUsdtRailRpcFixture;
use Tests\TestCase;

final class BscUsdtTopUpRailTest extends TestCase
{
    private BscUsdtRailRpcFixture $rpc;

    protected function setUp(): void
    {
        parent::setUp();
        // A fresh in-memory database without an enclosing transaction lets the
        // RPC fixture assert the real no-network-in-money-locks contract.
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'is_active' => true, 'bsc_min_confirmations' => 3]);
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-06 08:00:00 UTC'));
        $this->rpc = new BscUsdtRailRpcFixture;
        $this->app->instance(BscVerificationRpcClientInterface::class, $this->rpc);
    }

    public function test_verified_receiver_technical_readiness_never_claims_funding_authorization(): void
    {
        $customer = $this->customerWithReceiver();
        $readiness = app(UsdtTopUpRail::class)->readiness($customer);
        $this->assertTrue($readiness->technicalReady);
        $this->assertFalse($readiness->fundingAuthorized);
        $this->assertSame('h1_funding_unqualified', $readiness->reason);
        $this->assertNoMoney();
    }

    public function test_missing_receiver_fails_closed_without_rpc_or_invoice(): void
    {
        $customer = Customer::factory()->create();
        $this->assertFalse(app(UsdtTopUpRail::class)->readiness($customer)->technicalReady);
        $this->assertSame([], $this->rpc->calls);
        $this->assertSame(0, UsdtBscRailInvoice::query()->count());
    }

    public function test_lost_create_response_recovers_same_reference_snapshot_without_rpc_after_retirement_and_policy_change(): void
    {
        $customer = $this->customerWithReceiver();
        $invoice = $this->invoice($customer);
        $owner = User::query()->where('role', UserRole::Owner->value)->firstOrFail();
        $registry = app(UsdtDepositReceiverRegistry::class);
        $registry->retire($registry->findByAddress(56, BscUsdtRailRpcFixture::ADDRESS), $owner, 'Sandbox rotation');
        ManualPaymentSetting::current()->update(['bsc_min_confirmations' => 9]);
        $this->travelTo(CarbonImmutable::parse('2026-10-06 09:00:00 UTC'));
        $calls = $this->rpc->calls;
        $this->rpc->failMethods = ['chainId'];
        $recovered = $this->invoice($customer);
        $this->assertSame($invoice->id, $recovered->id);
        $this->assertSame($invoice->attributesToArray(), $recovered->attributesToArray());
        $this->assertSame($calls, $this->rpc->calls);
        $this->assertSame(1, UsdtBscRailInvoice::query()->count());
        $this->assertNoMoney();
    }

    public function test_reference_replay_with_changed_amount_conflicts(): void
    {
        $customer = $this->customerWithReceiver();
        $this->invoice($customer);
        $this->expectException(DomainException::class);
        app(UsdtTopUpRail::class)->createOrRecover($customer, 'sandbox:funding:1', UsdtMicroAmount::fromDecimalString('2'), $this->deadline());
    }

    public function test_another_customer_cannot_recover_or_inspect_a_foreign_reference(): void
    {
        $customer = $this->customerWithReceiver();
        $this->invoice($customer);
        $other = Customer::factory()->create();
        $calls = $this->rpc->calls;
        foreach (['create', 'inspect'] as $operation) {
            try {
                if ($operation === 'create') {
                    $this->invoice($other);
                } else {
                    app(UsdtTopUpRail::class)->inspect($other, 'sandbox:funding:1', BscUsdtRailRpcFixture::TX);
                }
                $this->fail('Foreign reference must not confer ownership.');
            } catch (AuthorizationException) {
                $this->assertSame($calls, $this->rpc->calls);
            }
        }
        $this->assertNoMoney();
    }

    public function test_overlapping_invoice_window_is_rejected_and_checkpoint_remains_single(): void
    {
        $customer = $this->customerWithReceiver();
        $this->invoice($customer);
        try {
            app(UsdtTopUpRail::class)->createOrRecover($customer, 'sandbox:funding:2', UsdtMicroAmount::fromDecimalString('1'), $this->deadline());
            $this->fail('One receiver cannot have two overlapping invoice windows.');
        } catch (DomainException) {
            $this->assertSame(1, UsdtBscRailInvoice::query()->count());
            $this->assertSame(1, DB::table('usdt_bsc_scan_checkpoints')->count());
        }
    }

    public function test_new_invoice_creation_is_blocked_in_production_before_rpc(): void
    {
        $customer = $this->customerWithReceiver();
        $this->app->instance('env', 'production');
        try {
            $this->invoice($customer);
            $this->fail('Unqualified production funding must not create an invoice.');
        } catch (RuntimeException) {
            $this->assertSame([], $this->rpc->calls);
            $this->assertSame(0, UsdtBscRailInvoice::query()->count());
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_rpc_timeout_before_local_creation_leaves_no_ambiguous_remote_effect_and_retry_uses_same_reference(): void
    {
        $customer = $this->customerWithReceiver();
        $this->rpc->failMethods = ['chainId'];
        try {
            $this->invoice($customer);
            $this->fail('Unavailable RPC cannot prepare an invoice.');
        } catch (RuntimeException) {
            $this->assertSame(0, UsdtBscRailInvoice::query()->count());
        }
        $this->rpc->failMethods = [];
        $created = $this->invoice($customer);
        $this->assertSame('sandbox:funding:1', $created->reference);
        $this->assertSame($created->id, $this->invoice($customer)->id);
    }

    public function test_invoice_snapshot_cannot_be_rewritten_through_direct_sql(): void
    {
        $invoice = $this->invoice($this->customerWithReceiver());
        $this->expectException(QueryException::class);
        DB::table('usdt_bsc_rail_invoices')->where('id', $invoice->id)->update(['amount_micro' => 2000000]);
    }

    public function test_database_rejects_receiver_snapshot_bound_to_another_customer(): void
    {
        $invoice = $this->invoice($this->customerWithReceiver());
        $attributes = $invoice->getAttributes();
        $attributes['id'] = (string) Str::uuid();
        $attributes['reference'] = 'sandbox:stolen';
        $attributes['customer_id'] = Customer::factory()->create()->getKey();
        $this->expectException(QueryException::class);
        DB::table('usdt_bsc_rail_invoices')->insert($attributes);
    }

    public function test_successful_final_transfer_is_only_inspection_and_still_verifies_after_local_expiry(): void
    {
        $customer = $this->customerWithReceiver();
        $invoice = $this->invoice($customer);
        $this->travelTo($this->deadline()->addHour());
        $result = app(UsdtTopUpRail::class)->inspect($customer, $invoice->reference, BscUsdtRailRpcFixture::TX);
        $this->assertTrue($result->verified(), $result->reason);
        $this->assertSame(BscUsdtRailRpcFixture::ADDRESS, $result->evidence->receiverAddress);
        $this->assertSame('1000000000000000000', $result->evidence->amountUnits);
        $this->assertSame(11, $result->evidence->confirmations);
        $this->assertNoMoney();
    }

    public function test_invoice_window_uses_epoch_seconds_independent_of_application_timezone(): void
    {
        $customer = $this->customerWithReceiver();
        $invoice = $this->invoice($customer);
        $this->assertSame(CarbonImmutable::parse('2026-10-06 08:00:00 UTC')->getTimestamp(), $invoice->starts_at_epoch);
        $timezone = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Honolulu');
            $result = app(UsdtTopUpRail::class)->inspect($customer, $invoice->reference, BscUsdtRailRpcFixture::TX);
            $this->assertTrue($result->verified(), $result->reason);
        } finally {
            date_default_timezone_set($timezone);
        }
        $this->assertNoMoney();
    }

    #[DataProvider('invalidEvidence')]
    public function test_invalid_or_incomplete_evidence_never_becomes_credit_authority(string $mutation, string $expectedStatus): void
    {
        $customer = $this->customerWithReceiver();
        $invoice = $this->invoice($customer);
        $receipt = &$this->rpc->receipt;
        switch ($mutation) {
            case 'wrong_chain': $this->rpc->chain = 97;
                break;
            case 'wrong_decimals': $this->rpc->decimals = 6;
                break;
            case 'wrong_token': $receipt['logs'][0]['address'] = '0x'.str_repeat('1', 40);
                break;
            case 'stolen_public_hash': $receipt['logs'][0]['topics'][2] = '0x'.str_repeat('0', 24).str_repeat('1', 40);
                break;
            case 'wrong_amount': $receipt['logs'][0] = $this->rpc->log(amount: '2000000000000000000');
                break;
            case 'nonrepresentable_amount': $receipt['logs'][0] = $this->rpc->log(amount: '1000000000000000001');
                break;
            case 'failed_receipt': $receipt['status'] = '0x0';
                break;
            case 'missing_receipt': $receipt = null;
                break;
            case 'missing_log_index': unset($receipt['logs'][0]['logIndex']);
                break;
            case 'malformed_second_log': $receipt['logs'][] = ['topics' => [BscRpcClient::TRANSFER_TOPIC]];
                break;
            case 'multiple_logs': $receipt['logs'][] = $this->rpc->log(index: 1);
                break;
            case 'unrelated_second_transfer': $receipt['logs'][] = $this->rpc->log(index: 1, receiver: '0x'.str_repeat('1', 40));
                break;
            case 'duplicate_log': $receipt['logs'][] = $receipt['logs'][0];
                break;
            case 'oversized_logs': $receipt['logs'] = array_fill(0, 101, $receipt['logs'][0]);
                break;
            case 'removed_log': $receipt['logs'][0]['removed'] = true;
                break;
            case 'log_hash_mismatch': $receipt['logs'][0]['blockHash'] = '0x'.str_repeat('1', 64);
                break;
            case 'missing_block_hash': unset($receipt['blockHash']);
                break;
            case 'reorg': $receipt['blockHash'] = $receipt['logs'][0]['blockHash'] = '0x'.str_repeat('1', 64);
                break;
            case 'missing_finalized': $this->rpc->blockOverrides['finalized'] = null;
                break;
            case 'unfinalized': $this->rpc->finalized = 99;
                break;
            case 'finalized_is_head': $this->rpc->finalized = $this->rpc->head;
                break;
            case 'finalized_not_canonical': $this->rpc->blockOverrides['finalized'] = ['number' => '0x69', 'hash' => '0x'.str_repeat('1', 64), 'timestamp' => '0x'.dechex($this->rpc->timestamp)];
                break;
            case 'outside_window': $this->rpc->timestamp = $this->deadline()->getTimestamp() + 1;
                break;
            case 'rpc_unavailable': $this->rpc->failMethods = ['transactionReceipt'];
                break;
        }
        $result = app(UsdtTopUpRail::class)->inspect($customer, $invoice->reference, BscUsdtRailRpcFixture::TX);
        $this->assertSame($expectedStatus, $result->status, $result->reason);
        $this->assertFalse($result->verified());
        $this->assertNoMoney();
    }

    public static function invalidEvidence(): array
    {
        $review = ['wrong_chain', 'wrong_decimals', 'wrong_token', 'stolen_public_hash', 'wrong_amount', 'nonrepresentable_amount',
            'failed_receipt', 'missing_log_index', 'malformed_second_log', 'multiple_logs', 'unrelated_second_transfer',
            'duplicate_log', 'oversized_logs', 'removed_log', 'log_hash_mismatch', 'missing_block_hash', 'reorg', 'finalized_not_canonical', 'outside_window'];
        $pending = ['missing_receipt', 'missing_finalized', 'unfinalized', 'finalized_is_head', 'rpc_unavailable'];
        $cases = [];
        foreach ($review as $mutation) {
            $cases[$mutation] = [$mutation, 'review'];
        }
        foreach ($pending as $mutation) {
            $cases[$mutation] = [$mutation, 'pending'];
        }

        return $cases;
    }

    public function test_rail_refuses_rpc_inside_a_caller_transaction(): void
    {
        $customer = $this->customerWithReceiver();
        try {
            DB::transaction(fn () => app(UsdtTopUpRail::class)->readiness($customer));
            $this->fail('Money transactions must not contain RPC work.');
        } catch (RuntimeException) {
            $this->assertSame([], $this->rpc->calls);
        }
    }

    private function customerWithReceiver(): Customer
    {
        $customer = Customer::factory()->create();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $registry = app(UsdtDepositReceiverRegistry::class);
        $registry->manualVerify($registry->register($customer, BscUsdtRailRpcFixture::ADDRESS, $owner)->receiver, true, $owner);

        return $customer;
    }

    private function invoice(Customer $customer): UsdtBscRailInvoice
    {
        return app(UsdtTopUpRail::class)->createOrRecover($customer, 'sandbox:funding:1', UsdtMicroAmount::fromDecimalString('1'), $this->deadline());
    }

    private function deadline(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-06 08:15:00 UTC');
    }

    private function assertNoMoney(): void
    {
        foreach (['external_money_receipts', 'customer_usdt_balance_entries', 'store_balance_entries'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }
}
