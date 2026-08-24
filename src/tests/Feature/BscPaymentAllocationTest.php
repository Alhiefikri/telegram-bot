<?php

namespace Tests\Feature;

use App\Enums\BscReceiverVerificationStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidCheckout;
use App\Models\BscPaymentReceiver;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\InventoryMovement;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentBscDetail;
use App\Models\Product;
use App\Services\Bsc\BscRpcClientInterface;
use App\Services\Bsc\BscUniquePaymentAmount;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BscPaymentAllocationTest extends TestCase
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
        config()->set('services.bsc.max_unique_amount_candidates', 1_000);

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
            'bsc_unique_amount_increment_micro' => 100,
        ]);
    }

    public function test_bsc_checkout_snapshots_receiver_and_exact_integer_amount(): void
    {
        $receiver = $this->activeReceiver('0x1111111111111111111111111111111111111111');
        $product = Product::factory()->create([
            'stock' => 2,
            'reserved_stock' => 0,
            'usdt_price_amount' => 2_500_000,
        ]);

        $order = app(OrderCheckoutService::class)->checkout(
            $this->cartForProduct($product),
            CheckoutPaymentIntents::usdtBep20(),
        );

        $payment = $order->payments()->sole();
        $detail = $payment->bscDetail()->firstOrFail();

        $this->assertSame('manual', $payment->provider);
        $this->assertSame('USDT_BEP20', $payment->method);
        $this->assertSame('USDT', $payment->currency);
        $this->assertSame(2_500_000, $payment->amount);
        $this->assertSame($receiver->id, $detail->receiver_id);
        $this->assertSame($receiver->address_normalized, $detail->receiver_address);
        $this->assertSame(56, $detail->chain_id);
        $this->assertSame(self::CONTRACT, $detail->token_contract);
        $this->assertSame(18, $detail->token_decimals);
        $this->assertSame(2_500_000, $detail->base_amount_micro);
        $this->assertSame(2_500_000, $detail->payable_amount_micro);
        $this->assertSame('2500000000000000000', (string) $detail->expected_token_units);
        $this->assertSame(PaymentBscDetail::STATUS_AWAITING_TRANSFER, $detail->verification_status);

        $receiver->forceFill([
            'label' => 'Renamed receiver',
            'is_active' => false,
        ])->save();

        $detail = $detail->fresh();
        $this->assertSame($receiver->address_normalized, $detail->receiver_address);
        $this->assertSame(self::CONTRACT, $detail->token_contract);
        $this->assertSame(2_500_000, $detail->payable_amount_micro);
    }

    public function test_allocator_uses_exact_base_then_configured_integer_increment(): void
    {
        $base = 1_000_000;
        $this->pendingBscPayment($base);
        $this->pendingBscPayment($base + 100);

        $this->assertSame(
            $base + 200,
            app(BscUniquePaymentAmount::class)->allocate($base),
        );
        $this->pendingBscPayment($base + 200);
        $this->assertSame(
            $base + 300,
            app(BscUniquePaymentAmount::class)->allocate($base, 100, 4),
        );

        $paid = $this->pendingBscPayment($base + 300);
        $paid->forceFill(['status' => PaymentStatus::Paid])->save();
        $this->assertSame($base + 300, app(BscUniquePaymentAmount::class)->allocate($base, 100, 4));
    }

    public function test_pending_bsc_amount_index_is_partial_and_database_enforced(): void
    {
        $indexNames = collect(DB::select("PRAGMA index_list('payments')"))
            ->map(fn (object $index): string => (string) $index->name)
            ->all();
        $this->assertContains('payments_pending_bsc_amount_unique', $indexNames);

        $amount = 900_000;
        $paid = $this->pendingBscPayment($amount);
        $paid->forceFill(['status' => PaymentStatus::Paid])->save();
        $this->pendingBscPayment($amount);

        $this->expectException(QueryException::class);
        $this->pendingBscPayment($amount);
    }

    public function test_allocation_exhaustion_rolls_back_receiver_assignment_and_checkout_side_effects(): void
    {
        config()->set('services.bsc.max_unique_amount_candidates', 2);
        $receiver = $this->activeReceiver('0x2222222222222222222222222222222222222222');
        $this->pendingBscPayment(1_000_000);
        $this->pendingBscPayment(1_000_100);
        $product = Product::factory()->create([
            'stock' => 1,
            'reserved_stock' => 0,
            'usdt_price_amount' => 1_000_000,
        ]);
        $cart = $this->cartForProduct($product);

        try {
            app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::usdtBep20());
            $this->fail('Expected BSC amount allocation to exhaust its bounded window.');
        } catch (InvalidCheckout $exception) {
            $this->assertStringContainsString('No unique BSC payment amount', $exception->getMessage());
        }

        $this->assertDatabaseMissing('orders', ['cart_id' => $cart->id]);
        $this->assertDatabaseMissing('payments', ['order_id' => $cart->id]);
        $this->assertSame(0, InventoryMovement::query()->count());
        $this->assertNull($receiver->fresh()->last_assigned_at);
        $this->assertSame(2, Payment::query()->where('method', 'USDT_BEP20')->count());
    }

    public function test_pending_or_rejected_receiver_can_never_be_assigned(): void
    {
        BscPaymentReceiver::factory()->active()->create([
            'verification_status' => BscReceiverVerificationStatus::PendingVerification,
            'address' => '0x3333333333333333333333333333333333333333',
            'token_contract' => self::CONTRACT,
        ]);
        $product = Product::factory()->create([
            'stock' => 1,
            'reserved_stock' => 0,
            'usdt_price_amount' => 1_000_000,
        ]);
        $cart = $this->cartForProduct($product);

        $this->expectException(InvalidCheckout::class);
        app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::usdtBep20());
    }

    public function test_switching_rails_on_an_existing_cart_requires_cancel_and_reorder(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        ManualPaymentSetting::current()->update(['binance_pay_enabled' => true]);
        $this->activeReceiver('0x4444444444444444444444444444444444444444');
        $product = Product::factory()->create([
            'stock' => 2,
            'reserved_stock' => 0,
            'usdt_price_amount' => 1_000_000,
        ]);
        $cart = $this->cartForProduct($product);

        app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::binancePayId());

        try {
            app(OrderCheckoutService::class)->checkout($cart->fresh(), CheckoutPaymentIntents::usdtBep20());
            $this->fail('Expected changing the rail on an existing checkout to fail.');
        } catch (InvalidCheckout $exception) {
            $this->assertStringContainsString('requires cancelling the existing Order', $exception->getMessage());
        }

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame('BINANCE_PAY_ID', Payment::query()->sole()->method);
    }

    public function test_payment_bsc_detail_factory_materializes_consistent_snapshots(): void
    {
        $detail = PaymentBscDetail::factory()->create();

        $this->assertSame('USDT_BEP20', $detail->payment->method);
        $this->assertSame($detail->receiver->address_normalized, $detail->receiver_address);
        $this->assertSame((string) $detail->payable_amount_micro, (string) $detail->payment->amount);
        $this->assertSame('1000000000000000000', (string) $detail->expected_token_units);
    }

    private function activeReceiver(string $address): BscPaymentReceiver
    {
        return BscPaymentReceiver::factory()
            ->verified()
            ->active()
            ->create([
                'address' => $address,
                'token_contract' => self::CONTRACT,
            ]);
    }

    private function pendingBscPayment(int $amount): Payment
    {
        return Payment::factory()->create([
            'provider' => 'manual',
            'method' => 'USDT_BEP20',
            'amount' => $amount,
            'currency' => 'USDT',
            'status' => PaymentStatus::Pending,
        ]);
    }

    private function cartForProduct(Product $product): Cart
    {
        $cart = Cart::factory()->create();
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        return $cart;
    }
}
