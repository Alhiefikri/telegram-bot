<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Services\CartService;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Storefront\StorefrontCheckoutChallenge;
use App\Storefront\StorefrontGuestCart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeStorefrontCheckoutChallenge;
use Tests\Support\StorefrontCheckoutScenarios;
use Tests\TestCase;

final class StorefrontWalletCheckoutTest extends TestCase
{
    use RefreshDatabase;
    use StorefrontCheckoutScenarios;

    protected function setUp(): void
    {
        parent::setUp();
        $this->instance(StorefrontCheckoutChallenge::class, new FakeStorefrontCheckoutChallenge);
        config()->set('services.storefront.telegram_reservation_floor', 0);
        Queue::fake();
        Http::fake();
        $this->manualPaymentModeIsActive();
    }

    public function test_signed_in_email_customer_sees_and_pays_with_their_own_balance_once(): void
    {
        $product = $this->availableProduct(['price_amount' => 13_000]);
        $buyer = Customer::factory()->create(['telegram_id' => null, 'email_verified_at' => now()]);
        $ledger = app(StoreBalanceLedger::class);
        $ledger->creditTopUp($buyer, 30_000, 'wallet-web-credit', 'wallet-web-credit');
        $payload = $this->payload($product) + ['email' => 'wallet-receipt@example.test'];

        $this->actingAs($buyer, 'customer')
            ->get(route('storefront.shop.checkout.product', $product))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Bayar dengan Saldo')
            ->assertSee('Rp30.000');

        $first = $this->post(route('storefront.shop.checkout'), $payload)->assertRedirect();
        $order = Order::query()->sole();
        $this->assertSame($buyer->id, $order->customer_id);
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame('STORE_BALANCE', $order->payments()->sole()->method);
        $this->assertSame('wallet-receipt@example.test', $order->notification_email);
        $this->assertSame(17_000, $ledger->snapshot($buyer)->balance());
        $this->get($first->headers->get('Location'))->assertOk();
        $this->get(route('customer.orders.show', $order))->assertOk()->assertSee($order->invoice_number);

        $this->post(route('storefront.shop.checkout'), array_replace($payload, ['email' => 'replacement@example.test']))
            ->assertRedirect($first->headers->get('Location'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame('wallet-receipt@example.test', $order->fresh()->notification_email);
        $this->assertSame(17_000, $ledger->snapshot($buyer)->balance());
        Http::assertNothingSent();
    }

    public function test_telegram_buyer_pays_for_the_web_cart_without_changing_the_bot_cart(): void
    {
        $product = $this->availableProduct(['price_amount' => 13_000]);
        $buyer = Customer::factory()->create(['email_verified_at' => null]);
        $buyer->identities()->forceCreate(['provider' => 'telegram', 'provider_subject' => (string) $buyer->telegram_id]);
        $ledger = app(StoreBalanceLedger::class);
        $ledger->creditTopUp($buyer, 40_000, 'telegram-wallet-credit', 'telegram-wallet-credit');
        $botCart = app(CartService::class)->activeCartFor($buyer);
        $botCart->cartItems()->create(['product_id' => $product->id, 'quantity' => 1]);

        $this->post(route('storefront.shop.cart.items.store'), ['product_id' => $product->id, 'quantity' => 2])->assertRedirect();
        $webCart = Cart::where('customer_id', '!=', $buyer->id)->sole();

        $this->actingAs($buyer, 'customer')->withSession(['customer_telegram_authenticated' => true])->withCookie('storefront_locale', 'ms')
            ->get(route('storefront.shop.checkout.start'))
            ->assertOk()
            ->assertSee('Bayar dengan Baki');

        $payload = $this->payload($product);
        unset($payload['product_id'], $payload['quantity']);
        $payload['cart_snapshot'] = app(StorefrontGuestCart::class)->snapshotToken($webCart);
        $payload['wallet_expected_total'] = 26_000;

        $first = $this->post(route('storefront.shop.checkout'), $payload)->assertRedirect();
        $order = Order::query()->sole();
        $this->assertSame($buyer->id, $order->customer_id);
        $this->assertSame(26_000, (int) $order->total_amount);
        $this->assertSame(14_000, $ledger->snapshot($buyer)->balance());
        $this->assertSame('checked_out', $webCart->fresh()->status->value);
        $this->assertSame('active', $botCart->fresh()->status->value);
        $this->assertSame(1, (int) $botCart->cartItems()->sole()->quantity);
        $this->post(route('storefront.shop.checkout'), $payload)->assertRedirect($first->headers->get('Location'));
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_guest_or_other_customer_cannot_spend_the_funded_account(): void
    {
        $product = $this->availableProduct(['price_amount' => 13_000]);
        $funded = Customer::factory()->create();
        $other = Customer::factory()->create(['email_verified_at' => now()]);
        $ledger = app(StoreBalanceLedger::class);
        $ledger->creditTopUp($funded, 30_000, 'private-wallet-credit', 'private-wallet-credit');
        $url = route('storefront.shop.checkout.product', $product);
        $payload = $this->payload($product) + ['customer_id' => $funded->id];

        $this->get($url)->assertOk()->assertDontSee('value="store_balance"', false);
        $this->post(route('storefront.shop.checkout'), $payload)->assertSessionHasErrors();

        $this->actingAs($other, 'customer');
        $this->get($url)->assertOk()->assertDontSee('value="store_balance"', false)->assertDontSee('Rp30.000');
        $this->post(route('storefront.shop.checkout'), $payload)->assertSessionHasErrors();
        $this->assertSame(30_000, $ledger->snapshot($funded)->balance());
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('carts', 0);
    }

    public function test_price_change_and_insufficient_balance_leave_no_order_or_debit(): void
    {
        $product = $this->availableProduct(['price_amount' => 13_000]);
        $buyer = Customer::factory()->create(['email_verified_at' => now()]);
        $ledger = app(StoreBalanceLedger::class);
        $ledger->creditTopUp($buyer, 14_000, 'price-test-wallet', 'price-test-wallet');
        $this->actingAs($buyer, 'customer')->withCookie('storefront_locale', 'en')
            ->get(route('storefront.shop.checkout.product', $product))
            ->assertOk()
            ->assertSee('Pay with Balance');

        $payload = $this->payload($product);
        $product->update(['price_amount' => 15_000]);
        $this->post(route('storefront.shop.checkout'), $payload)
            ->assertSessionHasErrors(['product_id' => 'The order total changed. Review the new total before paying.']);
        $payload['wallet_expected_total'] = 15_000;
        $this->post(route('storefront.shop.checkout'), $payload)
            ->assertSessionHasErrors(['product_id' => 'Not enough balance. Reduce the quantity or choose another payment method.']);
        $this->assertSame(14_000, $ledger->snapshot($buyer)->balance());
        $this->assertDatabaseCount('store_balance_entries', 1);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('carts', 0);
    }

    private function payload(Product $product): array
    {
        return [
            'checkout_attempt' => '73000000-0000-4000-8000-000000000009',
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'Wallet Buyer',
            'payment_method' => 'store_balance',
            'wallet_expected_total' => 13_000,
            'terms_accepted' => '1',
        ];
    }
}
