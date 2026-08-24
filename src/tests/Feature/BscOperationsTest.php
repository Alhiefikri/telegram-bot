<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Jobs\VerifyBscPayment;
use App\Models\BscPaymentReceiver;
use App\Models\BscPaymentTransferEvidence;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentBscDetail;
use App\Models\Product;
use App\Models\User;
use App\Services\Bsc\BscPaymentVerification;
use App\Services\Bsc\BscRpcClientInterface;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\OrderLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BscOperationsTest extends TestCase
{
    use RefreshDatabase;

    private const CONTRACT = '0x55d398326f99059ff775485246999027b3197955';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.bsc.rpc_url', 'https://bsc-rpc.test');
        config()->set('services.bsc.chain_id', 56);
        config()->set('services.bsc.usdt_contract', self::CONTRACT);
        config()->set('services.bsc.expected_token_decimals', 18);

        app()->instance(BscRpcClientInterface::class, new class implements BscRpcClientInterface
        {
            public function chainId(): int
            {
                return 56;
            }

            public function contractCode(string $contract): string
            {
                return '0x60016001';
            }

            public function tokenDecimals(string $contract): int
            {
                return 18;
            }

            public function isValidErc1271Signature(string $contract, string $messageHashHex, string $signature): bool
            {
                return true;
            }
        });

        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'is_active' => true,
            'bsc_payment_enabled' => true,
            'bsc_confirmation_grace_minutes' => 3,
        ]);
    }

    public function test_pending_expiry_requires_matching_pre_deadline_evidence_for_bsc_grace(): void
    {
        $invalidGrace = $this->bscPayment();
        $deadline = now()->subMinute();
        $invalidGrace->update(['expired_at' => $deadline]);
        $invalidGrace->order->update(['expires_at' => $deadline]);
        $invalidGrace->bscDetail()->update([
            'verification_grace_until' => now()->addMinutes(2),
            'verification_status' => PaymentBscDetail::STATUS_CONFIRMING,
        ]);

        $expired = app(OrderLifecycle::class)->applyPendingExpiry($invalidGrace->order);

        $this->assertTrue($expired->newlyApplied);
        $this->assertSame(OrderStatus::Expired, $invalidGrace->order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Expired, $invalidGrace->fresh()->status);
    }

    public function test_pending_expiry_skips_only_an_active_grace_backed_by_pre_deadline_evidence(): void
    {
        $payment = $this->bscPayment();
        $deadline = now()->subMinute();
        $payment->update(['expired_at' => $deadline]);
        $payment->order->update(['expires_at' => $deadline]);
        $detail = $payment->bscDetail()->firstOrFail();
        $detail->update([
            'verification_grace_until' => now()->addMinutes(2),
            'verification_status' => PaymentBscDetail::STATUS_CONFIRMING,
        ]);
        BscPaymentTransferEvidence::query()->create([
            'payment_id' => $payment->id,
            'tx_hash' => '0x'.str_repeat('a', 64),
            'token_contract' => self::CONTRACT,
            'receiver_address' => $detail->receiver_address,
            'from_address' => '0x'.str_repeat('b', 40),
            'amount_units' => $detail->expected_token_units,
            'block_number' => 100,
            'block_hash' => '0x'.str_repeat('c', 64),
            'block_timestamp' => $deadline->copy()->subSecond(),
            'receipt_status' => 'success',
            'log_index' => 0,
            'confirmations' => 1,
            'verification_status' => PaymentBscDetail::STATUS_CONFIRMING,
            'first_seen_at' => now(),
            'last_verified_at' => now(),
        ]);

        $skipped = app(OrderLifecycle::class)->applyPendingExpiry($payment->order->fresh());

        $this->assertFalse($skipped->newlyApplied);
        $this->assertNull($skipped->order);
        $this->assertSame(OrderStatus::PendingPayment, $payment->order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);

        $detail->fresh()->update(['verification_grace_until' => now()->subSecond()]);
        $expired = app(OrderLifecycle::class)->applyPendingExpiry($payment->order->fresh());

        $this->assertTrue($expired->newlyApplied);
        $this->assertSame(OrderStatus::Expired, $payment->order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Expired, $payment->fresh()->status);
    }

    public function test_bsc_readiness_command_treats_disabled_as_safe_and_enabled_missing_receiver_as_failure(): void
    {
        ManualPaymentSetting::current()->update(['bsc_payment_enabled' => false]);

        $this->artisan('bsc:check-readiness', ['--json' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('"enabled":false');

        ManualPaymentSetting::current()->update(['bsc_payment_enabled' => true]);

        $this->artisan('ops:health', ['--bsc' => true])
            ->assertFailed()
            ->expectsOutputToContain('ACTIVE_VERIFIED_BSC_RECEIVER');
    }

    public function test_enabled_bsc_readiness_requires_approved_rpc_metadata_and_active_receiver(): void
    {
        BscPaymentReceiver::factory()->manuallyVerified()->active()->create([
            'chain_id' => 56,
            'token_contract' => self::CONTRACT,
            'token_decimals' => 18,
        ]);

        $this->artisan('bsc:check-readiness', ['--json' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('"ready":true');

        config()->set('services.bsc.chain_id', 97);

        $this->artisan('ops:health', ['--bsc' => true])
            ->assertFailed()
            ->expectsOutputToContain('BSC_CHAIN_ID');
    }

    public function test_bsc_reconciliation_reports_readiness_and_handles_an_empty_backlog(): void
    {
        $this->artisan('bsc:reconcile', ['--chunk' => 1])
            ->assertSuccessful()
            ->expectsOutputToContain('bsc:reconcile completed');
    }

    public function test_cancelled_bsc_order_releases_reservation_and_verification_job_is_a_no_op(): void
    {
        Queue::fake();
        BscPaymentReceiver::factory()->manuallyVerified()->active()->create([
            'token_contract' => self::CONTRACT,
        ]);
        $product = Product::factory()->create([
            'stock' => 1,
            'reserved_stock' => 0,
            'usdt_price_amount' => 1_000_000,
        ]);
        $cart = Cart::factory()->create(['customer_id' => Customer::factory()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1]);
        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::usdtBep20());
        $payment = $order->payments()->sole();

        app(OrderLifecycle::class)->applyPendingCancellation($order);
        (new VerifyBscPayment($payment->id))->handle(app(BscPaymentVerification::class));

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertSame(PaymentBscDetail::STATUS_MANUAL_REVIEW, $payment->fresh()->bscDetail->verification_status);
    }

    public function test_bsc_health_fails_when_pending_verification_backlog_exceeds_threshold(): void
    {
        $this->bscPayment();
        $this->bscPayment(1_000_100);
        config()->set('services.bsc.pending_backlog_alert_threshold', 1);

        $this->artisan('ops:health', ['--bsc' => true])
            ->assertFailed()
            ->expectsOutputToContain('BSC backlog: pending=2')
            ->expectsOutputToContain('BSC backlog exceeds the configured alert threshold.');
    }

    public function test_admin_order_detail_shows_only_sanitized_bsc_evidence_and_safe_explorer_link(): void
    {
        $payment = $this->bscPayment();
        $detail = $payment->bscDetail()->firstOrFail();
        $hash = '0x'.str_repeat('d', 64);
        BscPaymentTransferEvidence::query()->create([
            'payment_id' => $payment->id,
            'tx_hash' => $hash,
            'token_contract' => self::CONTRACT,
            'receiver_address' => $detail->receiver_address,
            'from_address' => '0x'.str_repeat('e', 40),
            'amount_units' => $detail->expected_token_units,
            'block_number' => 123456,
            'block_hash' => '0x'.str_repeat('f', 64),
            'block_timestamp' => now()->subMinute(),
            'receipt_status' => 'success',
            'log_index' => 0,
            'confirmations' => 3,
            'verification_status' => PaymentBscDetail::STATUS_MANUAL_REVIEW,
            'first_seen_at' => now()->subMinute(),
            'last_verified_at' => now(),
        ]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Finance]))
            ->get(route('admin.orders.show', $payment->order))
            ->assertOk()
            ->assertSee(self::CONTRACT)
            ->assertSee('0x'.str_repeat('e', 40))
            ->assertSee($detail->receiver_address)
            ->assertSee($detail->expected_token_units)
            ->assertSee('123456')
            ->assertSee($hash)
            ->assertSee('https://bscscan.com/tx/'.$hash, false)
            ->assertSee('3');
    }

    private function bscPayment(int $amount = 1_000_000): Payment
    {
        $receiver = BscPaymentReceiver::factory()->manuallyVerified()->active()->create([
            'token_contract' => self::CONTRACT,
        ]);
        $order = Order::factory()->create(['expires_at' => now()->addMinutes(30)]);
        $payment = Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'manual',
            'method' => 'USDT_BEP20',
            'amount' => $amount,
            'currency' => 'USDT',
            'status' => PaymentStatus::Pending,
            'expired_at' => now()->addMinutes(30),
            'created_at' => now()->subMinutes(10),
        ]);
        PaymentBscDetail::query()->create([
            'payment_id' => $payment->id,
            'receiver_id' => $receiver->id,
            'receiver_address' => $receiver->address_normalized,
            'chain_id' => 56,
            'token_contract' => self::CONTRACT,
            'token_decimals' => 18,
            'base_amount_micro' => $amount,
            'payable_amount_micro' => $amount,
            'expected_token_units' => (string) $amount.'000000000000',
            'verification_status' => PaymentBscDetail::STATUS_AWAITING_TRANSFER,
        ]);

        return $payment->fresh(['order', 'bscDetail']);
    }
}
