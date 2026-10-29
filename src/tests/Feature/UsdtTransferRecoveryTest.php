<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\BalanceAdjustmentRejected;
use App\Models\BscPaymentReceiver;
use App\Models\Customer;
use App\Models\User;
use App\Services\Bsc\BscReadiness;
use App\Services\Bsc\BscVerificationRpcClientInterface;
use App\Services\MoneyReceipts\ExternalMoneyReceiptClaims;
use App\Services\MoneyReceipts\MoneyReceiptIdentity;
use App\Services\MoneyReceipts\MoneyReceiptParentClaim;
use App\Services\MoneyReceipts\MoneyReceiptTarget;
use App\Services\MoneyReceipts\VerifiedMoneyReceipt;
use App\Services\UsdtBalance\UsdtReceiptReconciliation;
use App\Services\UsdtTopUps\UsdtTransferRecovery;
use DomainException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\BscUsdtRailRpcFixture;
use Tests\TestCase;

class UsdtTransferRecoveryTest extends TestCase
{
    private User $owner;

    private Customer $customer;

    private BscPaymentReceiver $receiver;

    private BscUsdtRailRpcFixture $rpc;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--force' => true]);
        Http::preventStrayRequests();
        Queue::fake();
        $this->owner = User::factory()->create(['role' => UserRole::Owner, 'password' => 'owner-password']);
        $this->customer = Customer::factory()->create(['telegram_id' => 256632746]);
        $this->actingAs($this->owner);
        $session = app('session')->driver();
        $session->start();
        request()->setLaravelSession($session);
        config(['services.telegram.admin_telegram_chat_id' => '998877']);
        $this->receiver = BscPaymentReceiver::factory()->manuallyVerified()->create([
            'address' => BscUsdtRailRpcFixture::ADDRESS,
            'token_contract' => BscReadiness::APPROVED_USDT_CONTRACT,
            'verified_at' => now()->subDay(),
        ]);
        $this->rpc = new BscUsdtRailRpcFixture;
        $this->rpc->receipt['logs'] = [$this->rpc->log(amount: '2000000000000000000')];
        $this->app->instance(BscVerificationRpcClientInterface::class, $this->rpc);
    }

    private function preview(?Customer $customer = null)
    {
        return app(UsdtTransferRecovery::class)->request($this->owner, (string) ($customer ?? $this->customer)->telegram_id,
            $this->receiver->id, '2', BscUsdtRailRpcFixture::TX, 'Owner confirms payment belongs to this Customer');
    }

    public function test_uninvoiced_payment_credits_once_with_receipt_accounting_audit_and_recoverable_notices(): void
    {
        $preview = $this->preview();
        $service = app(UsdtTransferRecovery::class);
        $result = $service->confirm($this->owner, $preview->id, 'owner-password');
        $this->assertSame($result->entry_id, $service->confirm($this->owner, $preview->id, 'unused-replay-password')->entry_id);
        $this->assertDatabaseHas('customer_usdt_balance_accounts', ['customer_id' => $this->customer->id, 'balance_micro' => 2000000, 'version' => 1]);
        $this->assertDatabaseHas('customer_usdt_balance_entries', ['id' => $result->entry_id, 'source_type' => 'customer_usdt_recovery', 'amount_micro' => 2000000]);
        $this->assertDatabaseHas('customer_usdt_balance_accounting_events', ['customer_usdt_balance_entry_id' => $result->entry_id, 'amount_micro' => 2000000]);
        $this->assertDatabaseHas('external_money_receipts', ['id' => $result->receipt_id, 'target_type' => 'customer_usdt_recovery', 'beneficiary' => 'customer:'.$this->customer->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'usdt_transfer.recovered', 'subject_id' => $preview->id]);
        $this->assertSame(2, DB::table('telegram_outbound_effects')->where('source_id', $preview->id)->count());
        $this->assertSame(0, DB::table('customer_usdt_top_ups')->count());
        $this->assertSame([], app(UsdtReceiptReconciliation::class)->inspect($this->customer->id, null, null, 100)->findings);
    }

    public function test_same_physical_transfer_cannot_credit_another_customer_or_another_request(): void
    {
        $first = $this->preview();
        app(UsdtTransferRecovery::class)->confirm($this->owner, $first->id, 'owner-password');
        $other = Customer::factory()->create();
        $second = $this->preview($other);
        try {
            app(UsdtTransferRecovery::class)->confirm($this->owner, $second->id, 'owner-password');
            $this->fail('Duplicate physical transfer was accepted.');
        } catch (DomainException $error) {
            $this->assertStringContainsString('already claimed', $error->getMessage());
        }
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->count());
        $this->assertDatabaseMissing('customer_usdt_balance_accounts', ['customer_id' => $other->id]);
    }

    public function test_invalid_owner_password_cannot_claim_or_credit_payment(): void
    {
        $preview = $this->preview();
        try {
            app(UsdtTransferRecovery::class)->confirm($this->owner, $preview->id, 'incorrect');
            $this->fail('Invalid password was accepted.');
        } catch (BalanceAdjustmentRejected) {
            $this->assertDatabaseCount('external_money_receipts', 0);
            $this->assertDatabaseCount('customer_usdt_balance_entries', 0);
        }
    }

    public function test_changed_chain_evidence_is_rejected_before_credit(): void
    {
        $preview = $this->preview();
        $this->rpc->receipt['logs'] = [$this->rpc->log(amount: '1000000000000000000')];
        $this->expectException(DomainException::class);
        app(UsdtTransferRecovery::class)->confirm($this->owner, $preview->id, 'owner-password');
    }

    public function test_wrong_receiver_is_not_recovered(): void
    {
        $this->rpc->receipt['logs'] = [$this->rpc->log(amount: '2000000000000000000', receiver: '0x'.str_repeat('1', 40))];
        $this->expectException(DomainException::class);
        $this->preview();
    }

    public function test_different_session_cannot_confirm_the_preview(): void
    {
        $preview = $this->preview();
        request()->session()->migrate();
        $this->expectException(DomainException::class);
        app(UsdtTransferRecovery::class)->confirm($this->owner, $preview->id, 'owner-password');
    }

    public function test_checkout_claim_prevents_recovering_the_same_transfer_as_balance(): void
    {
        $preview = $this->preview();
        $receipt = new VerifiedMoneyReceipt(
            MoneyReceiptIdentity::bscLog(56, BscUsdtRailRpcFixture::TX, 0),
            'customer:'.$this->customer->id, 'USDT', '2000000000000000000', 18,
            MoneyReceiptTarget::orderPayment((string) Str::uuid()),
            $preview->evidence,
            parentClaim: MoneyReceiptParentClaim::for(56, BscUsdtRailRpcFixture::TX),
        );
        DB::transaction(fn () => app(ExternalMoneyReceiptClaims::class)->claim($receipt));
        try {
            app(UsdtTransferRecovery::class)->confirm($this->owner, $preview->id, 'owner-password');
            $this->fail('Checkout receipt was credited twice.');
        } catch (DomainException) {
            $this->assertDatabaseCount('customer_usdt_balance_entries', 0);
            $this->assertDatabaseCount('usdt_transfer_recoveries', 0);
            $this->assertDatabaseHas('external_money_receipts', ['target_type' => 'order_payment']);
        }
    }

    public function test_expired_preview_cannot_credit_payment(): void
    {
        $preview = $this->preview();
        $this->travel(6)->minutes();
        try {
            app(UsdtTransferRecovery::class)->confirm($this->owner, $preview->id, 'owner-password');
            $this->fail('Expired recovery preview was accepted.');
        } catch (DomainException) {
            $this->assertDatabaseCount('external_money_receipts', 0);
            $this->assertDatabaseCount('customer_usdt_balance_entries', 0);
        }
    }
}
