<?php

namespace Tests\Feature\Telegram;

use App\Enums\CustomerUsdtFundingPauseScope;
use App\Enums\PaymentStatus;
use App\Enums\UsdtBalanceEntryKind;
use App\Jobs\SendTelegramReceipt;
use App\Models\Customer;
use App\Models\CustomerUsdtBalanceEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\User;
use App\Services\CartService;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\VerifiedUsdtFunding;
use App\Services\UsdtFunding\CustomerUsdtFundingPolicyManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TelegramBotTestSupport;

final class UsdtBalanceCheckoutTest extends TelegramBotTestSupport
{
    public function test_private_checkout_selector_and_callback_pay_usdt_once_without_external_invoice(): void
    {
        $this->fakeTelegramHttp();
        Queue::fake([SendTelegramReceipt::class]);
        $customer = $this->fixture();
        $this->postTelegramWebhook($this->callbackUpdate(55501, 'choose', 'checkout', [], 555001, 'private', 55501))->assertOk();
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains(json_encode($request->data()), 'checkout:usdt_balance')
            && str_contains(json_encode($request->data()), '2.000001 USDT'));
        $this->postTelegramWebhook($this->callbackUpdate(55502, 'pay', 'checkout:usdt_balance', [], 555001, 'private', 55501))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(55503, 'retry', 'checkout:usdt_balance', [], 555001, 'private', 55501))->assertOk();
        $order = Order::sole();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame('usdt_balance', $order->payments()->sole()->provider);
        $this->assertSame(2_000_001, $order->payments()->sole()->amount);
        $this->assertSame(999_999, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
        $this->assertSame(1, CustomerUsdtBalanceEntry::where('kind', UsdtBalanceEntryKind::PurchaseDebit)->count());
        $this->assertDatabaseCount('payment_bsc_details', 0);
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto'));
    }

    public function test_group_and_cross_actor_callbacks_cannot_spend_the_customer_balance(): void
    {
        $this->fakeTelegramHttp();
        Queue::fake([SendTelegramReceipt::class]);
        $customer = $this->fixture();
        $this->postTelegramWebhook($this->callbackUpdate(55504, 'group', 'checkout:usdt_balance', ['id' => 555001], -555, 'group', 55501))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(55505, 'foreign-chat', 'checkout:usdt_balance', ['id' => 555001], 555002, 'private', 55501))->assertOk();
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(3_000_000, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
    }

    public function test_spending_pause_hides_the_button_and_rejects_an_old_callback_without_debit(): void
    {
        $this->fakeTelegramHttp();
        Queue::fake([SendTelegramReceipt::class]);
        $customer = $this->fixture();
        app(CustomerUsdtFundingPolicyManager::class)->pause(CustomerUsdtFundingPauseScope::Spending, 'Incident', User::factory()->create(), null);
        $this->postTelegramWebhook($this->callbackUpdate(55506, 'paused-choose', 'checkout', [], 555001, 'private', 55501))->assertOk();
        Http::assertNotSent(fn ($request): bool => str_contains(json_encode($request->data()), 'checkout:usdt_balance'));
        $this->postTelegramWebhook($this->callbackUpdate(55507, 'paused-pay', 'checkout:usdt_balance', [], 555001, 'private', 55501))->assertOk();
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(3_000_000, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
    }

    public function test_explicit_idr_callback_cannot_bypass_the_shared_spending_pause(): void
    {
        $this->fakeTelegramHttp();
        Queue::fake([SendTelegramReceipt::class]);
        $customer = $this->fixture();
        $ledger = app(\App\Services\StoreBalance\StoreBalanceLedger::class);
        $ledger->creditTopUp($customer, 30_000, 'telegram-idr-fixture', 'telegram-idr-fixture');
        $this->postTelegramWebhook($this->callbackUpdate(55508, 'choose-idr', 'checkout', [], 555001, 'private', 55501))->assertOk();
        app(CustomerUsdtFundingPolicyManager::class)->pause(CustomerUsdtFundingPauseScope::Spending, 'Incident', User::factory()->create(), null);
        $this->postTelegramWebhook($this->callbackUpdate(55509, 'paused-idr', 'checkout:store_balance', [], 555001, 'private', 55501))->assertOk();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(30_000, $ledger->snapshot($customer)->balance());
        $this->assertSame(3_000_000, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
        $this->assertSame(0, \App\Models\StoreBalanceEntry::where('kind', 'purchase_debit')->count());
        $this->assertSame(1, Product::sole()->stock);
        $this->assertSame(0, Product::sole()->reserved_stock);
        $this->assertNull(ProductStockItem::sole()->order_item_id);
    }

    private function fixture(): Customer
    {
        $customer = Customer::factory()->create(['telegram_id' => 555001, 'locale' => 'en']);
        $product = Product::factory()->create(['price_amount' => 15_000, 'usdt_price_amount' => 2_000_001, 'stock' => 1, 'reserved_stock' => 0]);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'private-checkout-credential']);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->addItem($cart, $product, 1);
        app(CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp($customer,
            new VerifiedUsdtFunding($customer->id, UsdtMicroAmount::fromUnits(3_000_000), 'telegram-fixture', 'telegram-receipt', 'v1'), 'telegram-fixture-credit');

        return $customer;
    }
}
