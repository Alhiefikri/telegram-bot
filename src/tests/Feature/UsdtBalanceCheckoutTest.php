<?php

namespace Tests\Feature;

use App\Enums\BalancePaymentPreference;
use App\Enums\CartStatus;
use App\Enums\CustomerUsdtFundingPauseScope;
use App\Enums\InventoryMovementType;
use App\Enums\PaymentStatus;
use App\Enums\UsdtBalanceEntryKind;
use App\Exceptions\CheckoutPriceChanged;
use App\Exceptions\CustomerUsdtFundingUnavailable;
use App\Exceptions\InsufficientCheckoutBalance;
use App\Exceptions\InsufficientUsdtBalance;
use App\Exceptions\InvalidCheckout;
use App\Exceptions\StoreBalanceProjectionMismatchException;
use App\Exceptions\UsdtBalanceFrozenException;
use App\Exceptions\UsdtBalanceProjectionMismatchException;
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
use App\Services\Checkout\BalanceCheckoutRequest;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\OrderLifecycle;
use App\Services\PaidInvoice\PaidInvoiceOrderPresentationAdapter;
use App\Services\PaymentReceiptReport;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\VerifiedUsdtFunding;
use App\Services\UsdtFunding\CustomerUsdtFundingPolicyManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_full_refund_restores_original_usdt_and_replay_does_not_credit_again(): void
    {
        [$customer, $product, $cart] = $this->fixture();
        $order = $this->checkout($cart, 'refund-original');
        $payment = $order->payments->sole();
        $first = app(OrderLifecycle::class)->applyPaymentRefunded($payment);
        $replay = app(OrderLifecycle::class)->applyPaymentRefunded($payment);
        $this->assertTrue($first->newlyApplied);
        $this->assertFalse($replay->newlyApplied);
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(5_000_000, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
        $this->assertSame(1, CustomerUsdtBalanceEntry::where('kind', UsdtBalanceEntryKind::RefundCredit)->count());
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertNotNull(ProductStockItem::where('order_item_id', $order->orderItems()->sole()->id)->sole()->retired_at);
    }

    public static function preferenceCases(): array
    {
        return [
            'fixed IDR' => ['idr_only', 30_000, 5_000_000, 'IDR'],
            'fixed USD' => ['usd_only', 30_000, 5_000_000, 'USDT'],
            'prefer IDR' => ['idr_first', 30_000, 5_000_000, 'IDR'],
            'prefer USD' => ['usd_first', 30_000, 5_000_000, 'USDT'],
            'fallback USD' => ['idr_first', 14_999, 5_000_000, 'USDT'],
            'fallback IDR' => ['usd_first', 30_000, 1_999_999, 'IDR'],
        ];
    }

    #[DataProvider('preferenceCases')]
    public function test_preference_selects_one_full_wallet_and_refunds_original_currency(string $mode, int $idr, int $usd, string $currency): void
    {
        [$customer, $product, $cart] = $this->fixture($usd);
        $this->fundIdr($customer, $idr);
        $customer->forceFill(['balance_payment_preference' => BalancePaymentPreference::from($mode)])->save();
        $request = new BalanceCheckoutRequest(expectedIdrTotal: 15_000, expectedUsdTotalMicro: 2_000_000);
        $order = app(OrderCheckoutService::class)->checkout($cart, checkoutKey: 'preference', balanceRequest: $request);
        $payment = $order->payments->sole();
        $this->assertSame($currency, $payment->currency);
        $this->assertSame($currency === 'IDR' ? 15_000 : 2_000_000, $payment->amount);
        $this->assertSame($mode, $order->balance_checkout_snapshot['preference']);
        $this->assertSame('account', $order->balance_checkout_snapshot['source']);
        $this->assertSame($idr - ($currency === 'IDR' ? 15_000 : 0), app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        $this->assertSame($usd - ($currency === 'USDT' ? 2_000_000 : 0), app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertSame(1, ProductStockItem::whereNotNull('order_item_id')->count());

        $customer->forceFill(['balance_payment_preference' => BalancePaymentPreference::UsdOnly])->save();
        $product->update(['price_amount' => 99_000, 'usdt_price_amount' => 99_000_000]);
        $replay = app(OrderCheckoutService::class)->checkout($cart->fresh(), checkoutKey: 'preference', balanceRequest: $request);
        $this->assertSame($order->id, $replay->id);
        $this->assertSame($order->balance_checkout_snapshot, $replay->balance_checkout_snapshot);
        $this->assertSame($payment->amount, $replay->payments->sole()->amount);
        app(OrderLifecycle::class)->applyPaymentRefunded($payment);
        app(OrderLifecycle::class)->applyPaymentRefunded($payment);
        $this->assertSame($idr, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        $this->assertSame($usd, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
        $this->assertSame($currency, $payment->fresh()->currency);
        $this->assertSame(1, DB::table($currency === 'IDR' ? 'store_balance_entries' : 'customer_usdt_balance_entries')->where('kind', 'refund_credit')->count());
        Http::assertNothingSent();
    }

    public function test_default_override_and_explicit_rail_have_distinct_meaning(): void
    {
        [$customer, , $cart] = $this->fixture();
        $this->fundIdr($customer, 30_000);
        $this->assertSame(BalancePaymentPreference::IdrFirst, $customer->fresh()->balance_payment_preference);
        $order = app(OrderCheckoutService::class)->checkout($cart, balanceRequest: new BalanceCheckoutRequest(BalancePaymentPreference::UsdOnly, 15_000, 2_000_000));
        $this->assertSame('USDT', $order->currency);
        $this->assertSame('override', $order->balance_checkout_snapshot['source']);
        [$other, , $explicitCart] = $this->fixture();
        $this->fundIdr($other, 30_000);
        $other->forceFill(['balance_payment_preference' => BalancePaymentPreference::UsdOnly])->save();
        $explicit = app(OrderCheckoutService::class)->checkout($explicitCart, CheckoutPaymentIntents::storeBalance());
        $this->assertSame('IDR', $explicit->currency);
        $this->assertSame('explicit_rail', $explicit->balance_checkout_snapshot['source']);
        $this->assertSame(5_000_000, app(CustomerUsdtBalanceLedger::class)->snapshot($other)->balanceMicro());
    }

    public static function rejectedPreferences(): array
    {
        return [
            'fixed IDR never falls back' => ['idr_only', 1, 5_000_000, 15_000, 2_000_000, InsufficientCheckoutBalance::class],
            'fixed USD never falls back' => ['usd_only', 30_000, 1, 15_000, 2_000_000, InsufficientCheckoutBalance::class],
            'both insufficient cannot split' => ['idr_first', 14_999, 1_999_999, 15_000, 2_000_000, InsufficientCheckoutBalance::class],
            'unacknowledged secondary' => ['idr_first', 1, 5_000_000, 15_000, null, InsufficientCheckoutBalance::class],
            'missing primary consent' => ['usd_first', 30_000, 1, 15_000, null, InvalidCheckout::class],
            'stale primary cannot fallback' => ['usd_first', 30_000, 1, 15_000, 1_000_000, CheckoutPriceChanged::class],
            'stale secondary blocks primary' => ['idr_first', 30_000, 5_000_000, 15_000, 1_000_000, CheckoutPriceChanged::class],
        ];
    }

    #[DataProvider('rejectedPreferences')]
    public function test_rejected_preference_leaves_no_order_or_allocation(string $mode, int $idr, int $usd, ?int $expectedIdr, ?int $expectedUsd, string $error): void
    {
        [$customer, $product, $cart] = $this->fixture($usd);
        $this->fundIdr($customer, $idr);
        try {
            app(OrderCheckoutService::class)->checkout($cart, balanceRequest: new BalanceCheckoutRequest(BalancePaymentPreference::from($mode), $expectedIdr, $expectedUsd));
            $this->fail('Expected rejection.');
        } catch (InvalidCheckout $exception) {
            $this->assertInstanceOf($error, $exception);
        }
        $this->assertUnspent($customer, $product, $cart, $usd);
        $this->assertSame($idr, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        $this->assertSame(0, DB::table('store_balance_entries')->where('kind', 'purchase_debit')->count());
    }

    public function test_absent_usd_price_allows_only_authorized_idr_fallback(): void
    {
        [$customer, , $cart] = $this->fixture();
        $this->fundIdr($customer, 30_000);
        $cart->cartItems()->sole()->product->update(['usdt_price_amount' => null]);
        $order = app(OrderCheckoutService::class)->checkout($cart, balanceRequest: new BalanceCheckoutRequest(BalancePaymentPreference::UsdFirst, 15_000));
        $this->assertSame('IDR', $order->currency);
        $this->assertSame(15_000, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        $this->assertSame(5_000_000, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
    }

    public static function safetyCases(): array
    {
        return [
            'frozen preferred USD' => ['usd_first', 'freeze', UsdtBalanceFrozenException::class],
            'frozen secondary USD' => ['idr_first', 'freeze', UsdtBalanceFrozenException::class],
            'drift preferred USD' => ['usd_first', 'usd_drift', UsdtBalanceProjectionMismatchException::class],
            'drift secondary USD' => ['idr_first', 'usd_drift', UsdtBalanceProjectionMismatchException::class],
            'drift preferred IDR' => ['idr_first', 'idr_drift', StoreBalanceProjectionMismatchException::class],
            'drift secondary IDR' => ['usd_first', 'idr_drift', StoreBalanceProjectionMismatchException::class],
            'spending pause with IDR primary' => ['idr_first', 'pause', CustomerUsdtFundingUnavailable::class],
            'missing restore state' => ['idr_first', 'restore_missing', InvalidCheckout::class],
            'spending pause with USD primary' => ['usd_first', 'pause', CustomerUsdtFundingUnavailable::class],
            'restore with IDR only' => ['idr_only', 'restore', CustomerUsdtFundingUnavailable::class],
            'restore with USD only' => ['usd_only', 'restore', CustomerUsdtFundingUnavailable::class],
            'missing trusted policy' => ['idr_first', 'policy_missing', CustomerUsdtFundingUnavailable::class],
            'explicit IDR cannot bypass spending pause' => ['explicit_idr', 'pause', CustomerUsdtFundingUnavailable::class],
            'explicit IDR cannot bypass restore' => ['explicit_idr', 'restore', CustomerUsdtFundingUnavailable::class],
        ];
    }

    #[DataProvider('safetyCases')]
    public function test_financial_safety_errors_never_switch_wallets(string $mode, string $fault, string $error): void
    {
        [$customer, $product, $cart] = $this->fixture();
        $this->fundIdr($customer, 30_000);
        match ($fault) {
            'freeze' => DB::table('customer_usdt_balance_accounts')->where('customer_id', $customer->id)->update(['frozen_reason' => 'review']),
            'usd_drift' => DB::table('customer_usdt_balance_accounts')->where('customer_id', $customer->id)->increment('balance_micro'),
            'idr_drift' => DB::table('customer_balance_accounts')->where('customer_id', $customer->id)->increment('balance'),
            'restore_missing' => DB::table('balance_restore_gates')->delete(),
            'pause' => app(CustomerUsdtFundingPolicyManager::class)->pause(CustomerUsdtFundingPauseScope::Spending, 'Incident', User::factory()->create(), null),
            'restore' => DB::table('balance_restore_gates')->updateOrInsert(['id' => 1], ['state' => 'open', 'reason' => 'fixture', 'opened_by' => 'test', 'opened_at' => now(), 'window_start' => now()->subHour(), 'window_end' => now()]),
            'policy_missing' => DB::table('customer_usdt_funding_policies')->delete(),
        };
        try {
            if ($mode === 'explicit_idr') {
                app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::storeBalance());
            } else {
                app(OrderCheckoutService::class)->checkout($cart, balanceRequest: new BalanceCheckoutRequest(BalancePaymentPreference::from($mode), 15_000, 2_000_000));
            }
            $this->fail('Expected safety rejection.');
        } catch (\Throwable $exception) {
            $this->assertInstanceOf($error, $exception);
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertSame(0, DB::table('store_balance_entries')->where('kind', 'purchase_debit')->count());
        $this->assertSame(0, CustomerUsdtBalanceEntry::where('kind', UsdtBalanceEntryKind::PurchaseDebit)->count());
        $this->assertSame(1, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertNull($product->stockItems()->sole()->order_item_id);
        Http::assertNothingSent();
    }

    private function fundIdr(Customer $customer, int $amount): void
    {
        app(StoreBalanceLedger::class)->creditTopUp($customer, $amount, 'fixture-idr-'.$customer->id, 'fixture-idr-'.$customer->id);
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
