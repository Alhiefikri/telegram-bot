<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Enums\CustomerUsdtFundingPauseScope;
use App\Enums\InventoryMovementType;
use App\Enums\PaymentStatus;
use App\Enums\UsdtBalanceEntryKind;
use App\Exceptions\CustomerUsdtFundingUnavailable;
use App\Exceptions\InsufficientUsdtBalance;
use App\Exceptions\InvalidCheckout;
use App\Exceptions\InvalidOrderTransition;
use App\Exceptions\UsdtBalanceFrozenException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\CustomerUsdtBalanceEntry;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\TelegramOutboundEffect;
use App\Models\User;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\OrderLifecycle;
use App\Services\PaidInvoice\PaidInvoiceOrderPresentationAdapter;
use App\Services\PaymentReceiptReport;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\VerifiedUsdtFunding;
use App\Services\UsdtFunding\CustomerUsdtFundingPolicyManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

final class UsdtBalanceCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_tiered_full_payment_and_lost_response_replay_preserve_currency_and_one_debit(): void
    {
        [$customer, $product, $cart] = $this->fixture(10_000_000, 2);
        $product->quantityPriceTiers()->create(['minimum_quantity' => 2, 'price_amount' => 12_000, 'usdt_price_amount' => 1_234_567]);
        config(['services.telegram.admin_telegram_chat_id' => '123456', 'services.telegram.token' => 'fixture-token']);

        $order = $this->checkout($cart, 'usdt-tier');
        $payment = $order->payments->sole();
        $this->assertSame('USDT', $order->currency);
        $this->assertSame(24_000, $order->total_amount); // legacy IDR commercial display only
        $this->assertSame('usdt_balance', $payment->provider);
        $this->assertSame('USDT_BALANCE', $payment->method);
        $this->assertSame('USDT', $payment->currency);
        $this->assertSame(2_469_134, $payment->amount);
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(1_234_567, $order->orderItems->sole()->usdt_unit_price);
        $this->assertSame(2_469_134, $order->orderItems->sole()->usdt_subtotal);
        $this->assertSame(7_530_866, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
        $this->assertSame(1, InventoryMovement::where('order_id', $order->id)->where('type', InventoryMovementType::Sale)->count());
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(CartStatus::CheckedOut, $cart->fresh()->status);
        $this->assertDatabaseCount('store_balance_entries', 0);
        $this->assertDatabaseCount('payment_bsc_details', 0);
        $this->assertDatabaseCount('external_money_receipts', 0);
        $this->assertDatabaseHas('customer_usdt_balance_accounting_events', ['currency' => 'USDT', 'amount_micro' => 2_469_134, 'credit_account' => 'usdt_sale']);
        $this->assertSame(1, TelegramOutboundEffect::where('source_id', $order->id)->where('effect_type', TelegramOutboundEffectService::TYPE_ADMIN_PAID)->count());

        $invoice = app(PaidInvoiceOrderPresentationAdapter::class)->present($order);
        $this->assertSame(2_469_134, $invoice->totalPaid);
        $report = app(PaymentReceiptReport::class);
        $this->assertSame(['IDR' => 0, 'USDT' => 2_469_134], $report->customerTransactionTotals($customer->id));
        $this->assertSame(0, $report->idrNetTotalCanonical());

        DB::table('customer_usdt_balance_accounts')->where('customer_id', $customer->id)->update(['frozen_reason' => 'incident']);
        app(CustomerUsdtFundingPolicyManager::class)->pause(CustomerUsdtFundingPauseScope::Spending, 'Incident', User::factory()->create(), null);
        $product->update(['usdt_price_amount' => 9_000_000]);
        $replayed = $this->checkout($cart->fresh(), 'usdt-tier');
        $this->assertSame($order->id, $replayed->id);
        $this->assertSame(2_469_134, $replayed->payments->sole()->amount);
        $this->assertSame(1, CustomerUsdtBalanceEntry::where('kind', UsdtBalanceEntryKind::PurchaseDebit)->count());
        $this->assertDatabaseCount('orders', 1);
        Http::assertNothingSent();
    }

    public function test_insufficient_funds_roll_back_order_payment_credentials_stock_and_outbound(): void
    {
        [$customer, $product, $cart] = $this->fixture(1_999_999);
        try {
            $this->checkout($cart, 'insufficient');
            $this->fail('Expected insufficient funds.');
        } catch (InsufficientUsdtBalance) {
            $this->assertUnspent($customer, $product, $cart, 1_999_999);
        }
    }

    public function test_failure_after_debit_rolls_back_all_financial_and_inventory_effects(): void
    {
        [$customer, $product, $cart] = $this->fixture();
        $this->mock(OrderLifecycle::class)->shouldReceive('applyPaymentPaid')->once()->andThrow(new RuntimeException('injected lifecycle failure'));
        try {
            $this->checkout($cart, 'rollback');
            $this->fail('Expected injected failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('injected lifecycle failure', $exception->getMessage());
            $this->assertUnspent($customer, $product, $cart, 5_000_000);
            $this->assertDatabaseCount('customer_usdt_balance_accounting_events', 1);
        }
    }

    public function test_missing_usdt_price_cannot_convert_idr_or_spend_partially(): void
    {
        [$customer, $product, $cart] = $this->fixture();
        $product->update(['usdt_price_amount' => null]);
        try {
            $this->checkout($cart, 'no-price');
            $this->fail('Expected unavailable price.');
        } catch (InvalidCheckout) {
            $this->assertUnspent($customer, $product, $cart, 5_000_000);
        }
    }

    public function test_frozen_and_paused_spending_are_denied_but_funding_pause_does_not_block_spending(): void
    {
        [$customer, $product, $cart] = $this->fixture();
        $manager = app(CustomerUsdtFundingPolicyManager::class);
        $owner = User::factory()->create();
        $manager->pause(CustomerUsdtFundingPauseScope::Spending, 'Spend incident', $owner, null);
        try {
            $this->checkout($cart, 'paused');
            $this->fail('Expected spending pause.');
        } catch (CustomerUsdtFundingUnavailable) {
            $this->assertUnspent($customer, $product, $cart, 5_000_000);
        }
        $manager->resume(CustomerUsdtFundingPauseScope::Spending, $owner, null);
        DB::table('customer_usdt_balance_accounts')->where('customer_id', $customer->id)->update(['frozen_reason' => 'review']);
        try {
            $this->checkout($cart, 'frozen');
            $this->fail('Expected frozen account.');
        } catch (UsdtBalanceFrozenException) {
            $this->assertUnspent($customer, $product, $cart, 5_000_000);
        }
        DB::table('customer_usdt_balance_accounts')->where('customer_id', $customer->id)->update(['frozen_reason' => null]);
        $manager->pause(CustomerUsdtFundingPauseScope::Funding, 'Funding incident', $owner, null);
        $this->assertSame(PaymentStatus::Paid, $this->checkout($cart, 'funding-paused')->payment_status);
    }

    public function test_guest_cannot_spend_and_foreign_customer_cannot_replay_an_order_key(): void
    {
        [, , $cart] = $this->fixture();
        $order = $this->checkout($cart, 'private-key');
        [$foreign, , $foreignCart] = $this->fixture();
        try {
            $this->checkout($foreignCart, 'private-key');
            $this->fail('Expected foreign key rejection.');
        } catch (InvalidCheckout) {
            $this->assertDatabaseCount('orders', 1);
            $this->assertSame(5_000_000, app(CustomerUsdtBalanceLedger::class)->snapshot($foreign)->balanceMicro());
        }
        $foreign->update(['telegram_id' => null]);
        $this->expectException(InvalidCheckout::class);
        $this->checkout($foreignCart->fresh(), 'guest');
    }

    public function test_usdt_order_cannot_be_marked_refunded_without_original_currency_credit(): void
    {
        [$customer, , $cart] = $this->fixture();
        $order = $this->checkout($cart, 'refund-gate');
        try {
            app(OrderLifecycle::class)->applyPaymentRefunded($order->payments->sole());
            $this->fail('Expected U11 entitlement gate.');
        } catch (InvalidOrderTransition) {
            $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
            $this->assertSame(3_000_000, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
        }
    }

    private function fixture(int $funds = 5_000_000, int $quantity = 1): array
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['price_amount' => 15_000, 'usdt_price_amount' => 2_000_000, 'stock' => $quantity, 'reserved_stock' => 0]);
        for ($i = 0; $i < $quantity; $i++) {
            ProductStockItem::create(['product_id' => $product->id, 'secret' => 'checkout-secret-'.$i]);
        }
        $cart = Cart::factory()->create(['customer_id' => $customer->id, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => $quantity]);
        app(CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp($customer,
            new VerifiedUsdtFunding($customer->id, UsdtMicroAmount::fromUnits($funds), 'fixture-'.$customer->id, 'receipt-'.$customer->id, 'v1'),
            'fixture-credit-'.$customer->id);

        return [$customer, $product, $cart];
    }

    private function checkout(Cart $cart, string $key): Order
    {
        return app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::usdtBalance(), $key);
    }

    private function assertUnspent(Customer $customer, Product $product, Cart $cart, int $funds): void
    {
        $this->assertSame($funds, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
        $this->assertSame(0, CustomerUsdtBalanceEntry::where('kind', UsdtBalanceEntryKind::PurchaseDebit)->count());
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('telegram_outbound_effects', 0);
        $this->assertSame(CartStatus::Active, $cart->fresh()->status);
        $this->assertSame(1, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertNull($product->stockItems()->sole()->order_item_id);
        Http::assertNothingSent();
    }
}
