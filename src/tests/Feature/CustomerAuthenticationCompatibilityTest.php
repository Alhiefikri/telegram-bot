<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\CustomerAuthMailWork;
use App\Models\CustomerIdentity;
use App\Models\CustomerLoginLink;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Customer\CustomerPasswordHasher;
use App\Services\Customer\CustomerTelegramAuthenticationService;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Storefront\StorefrontInvoiceUrl;
use App\Storefront\StorefrontOrderAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerAuthenticationCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public static function methods(): array
    {
        return [
            'password' => ['password'],
            'email OTP legacy Customer' => ['otp'],
            'magic link legacy Customer' => ['magic'],
            'Google with foreign provider email' => ['google'],
            'Google nullable email' => ['google-null'],
            'Telegram-only legacy Customer' => ['telegram'],
            'Telegram unverified email' => ['telegram-pending'],
        ];
    }

    #[DataProvider('methods')]
    public function test_each_method_uses_canonical_order_and_balance_ownership(string $method): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $this->withCredentials();
        $foreign = Customer::factory()->create(['telegram_id' => null, 'email' => 'foreign@example.test', 'email_verified_at' => now()]);
        $customer = Customer::factory()->create([
            'telegram_id' => str_starts_with($method, 'telegram') ? $foreign->id : null,
            'email' => in_array($method, ['telegram', 'google-null'], true) ? null : 'owner@example.test',
            'email_verified_at' => str_starts_with($method, 'telegram') || $method === 'google-null' ? null : now(),
        ])->fresh();
        [$owned] = $this->paidOrder($customer, 'OWNED-CREDENTIAL');
        [$other, $otherKey] = $this->paidOrder($foreign, 'FOREIGN-CREDENTIAL');
        $guest = Customer::factory()->create(['telegram_id' => null, 'email' => null, 'email_verified_at' => null, 'first_name' => $customer->first_name]);
        [$guestOrder, $guestKey] = $this->paidOrder($guest, 'GUEST-CREDENTIAL');
        $guestOrder->forceFill(['notification_email' => $foreign->email, 'support_contact_channel' => 'whatsapp', 'support_contact_value' => '08123456789'])->save();
        $this->fund($customer, 12_345, 2_000_000);
        $this->fund($foreign, 98_765, 9_000_000);
        $count = Customer::query()->count();

        $this->login($customer, $foreign, $method);
        $this->assertAuthenticatedAs($customer, 'customer');
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->get(route('customer.orders.index', ['customer_id' => $foreign->id, 'email' => $foreign->email]))
            ->assertOk()->assertSee($owned->invoice_number)->assertDontSee($other->invoice_number)->assertDontSee($guestOrder->invoice_number);
        $this->get(route('customer.orders.show', $other))->assertNotFound();
        $this->postJson(route('customer.orders.status', $other))->assertNotFound();
        $this->get(route('customer.orders.show', $owned))->assertOk()->assertSee('OWNED-CREDENTIAL')
            ->assertDontSee('FOREIGN-CREDENTIAL')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
        foreach (['IDR', 'USDT'] as $currency) {
            $response = $this->get(route('customer.balance.show', [
                'currency' => $currency, 'customer_id' => $foreign->id, 'telegram_id' => $foreign->id,
                'provider_subject' => $foreign->id, 'email' => $foreign->email,
            ]))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
            $response->assertViewHas('customer', fn (Customer $value): bool => $value->id === $customer->id);
            $response->assertViewHas('overview', fn ($value): bool => $value->storeBalance->balance() === 12_345 && $value->usdtBalance->balanceMicro() === 2_000_000);
            $response->assertDontSee('Rp 98.765')->assertDontSee('9 USDT');
        }
        $this->get(app(StorefrontInvoiceUrl::class)->show($guestOrder))->assertOk()->assertDontSee('GUEST-CREDENTIAL');
        $this->get(route('storefront.order-access.show', $guestOrder))->assertForbidden();
        $this->from(route('customer.claim.create'))->post(route('customer.claim.store'), [
            'invoice_number' => $guestOrder->invoice_number, 'access_key' => 'LHIE-WRONG-KEY-123456',
            'email' => $foreign->email, 'telegram_id' => $customer->telegram_id, 'whatsapp' => '08123456789', 'name' => $customer->first_name,
        ])->assertSessionHas('customer_claim_error');
        $this->assertSame($guest->id, $guestOrder->fresh()->customer_id);
        $this->post(route('customer.claim.store'), ['invoice_number' => $other->invoice_number, 'access_key' => $otherKey])->assertSessionHas('customer_claim_error');
        $hash = $guestOrder->credential_access_key_hash;
        $this->post(route('customer.claim.store'), ['invoice_number' => $guestOrder->invoice_number, 'access_key' => $guestKey])
            ->assertRedirect(route('customer.orders.show', $guestOrder));
        $this->get(route('customer.orders.show', $guestOrder))->assertOk()->assertSee('GUEST-CREDENTIAL')
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->post(route('customer.logout'));
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->get(route('customer.balance.show'))->assertRedirect(route('customer.account'));
        $this->post(route('storefront.order-access.store'), ['invoice_number' => $guestOrder->invoice_number, 'access_key' => $guestKey])
            ->assertRedirect(route('storefront.order-access.show', $guestOrder));
        $this->get(route('storefront.order-access.show', $guestOrder))->assertOk()->assertSee('GUEST-CREDENTIAL')
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertSame($hash, $guestOrder->fresh()->credential_access_key_hash);
        $this->assertSame($foreign->id, $other->fresh()->customer_id);
        $this->assertSame($customer->id, $owned->fresh()->customer_id);
        $this->assertSame($count, Customer::query()->count());
    }

    public function test_magic_link_customer_can_enroll_without_losing_historical_orders_or_balances(): void
    {
        Queue::fake();
        $this->withCredentials();
        $customer = Customer::factory()->create(['telegram_id' => null, 'email' => 'legacy@example.test', 'email_verified_at' => now()])->fresh();
        [$order, $key] = $this->paidOrder($customer, 'LEGACY-CREDENTIAL');
        $hash = $order->credential_access_key_hash;
        $this->fund($customer, 12_345, 2_000_000);
        $this->login($customer, $customer, 'magic');
        $this->assertNull($customer->fresh()->password);
        $this->withCookie(config('session.cookie'), session()->getId())->get(route('customer.orders.show', $order))->assertOk()->assertSee('LEGACY-CREDENTIAL');
        $this->post(route('customer.password.enroll'), ['password' => 'First legacy password', 'password_confirmation' => 'First legacy password'])
            ->assertRedirect(route('customer.security'))->assertSessionHas('customer_login_sent');
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->get(route('customer.orders.show', $order))->assertOk()->assertSee('LEGACY-CREDENTIAL');
        $this->get(route('customer.balance.show'))->assertViewHas('overview', fn ($value): bool => $value->storeBalance->balance() === 12_345 && $value->usdtBalance->balanceMicro() === 2_000_000);
        $this->assertSame($customer->id, $order->fresh()->customer_id);
        $this->assertSame($hash, $order->fresh()->credential_access_key_hash);
        $this->assertTrue(app(StorefrontOrderAccess::class)->verifyKey($order->fresh(), $key));
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_customer_default_guard_never_becomes_an_admin_audit_actor(): void
    {
        $admin = User::factory()->create();
        $explicit = User::factory()->create();
        $customer = Customer::factory()->create();
        $audit = app(AuditLogger::class);
        $this->actingAs($customer, 'customer');

        $this->assertNull($audit->log('storefront.customer_action', $customer)->user_id);
        $this->actingAs($admin, 'web')->actingAs($customer, 'customer');
        $this->assertSame($admin->id, $audit->log('storefront.admin_session', $customer)->user_id);
        $this->assertSame($explicit->id, $audit->log('admin.explicit_actor', $customer, user: $explicit)->user_id);
    }

    private function login(Customer $customer, Customer $foreign, string $method): void
    {
        if ($method === 'password') {
            $customer->forceFill(['password' => app(CustomerPasswordHasher::class)->make('Compatibility password'), 'password_scheme' => CustomerPasswordHasher::SCHEME])->save();
            $this->post(route('customer.login'), ['email' => $customer->email, 'password' => 'Compatibility password'])->assertRedirect(route('customer.orders.index'));
        } elseif ($method === 'magic') {
            $token = bin2hex(random_bytes(32));
            CustomerLoginLink::create(['email' => $customer->email, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(15)]);
            $this->post(route('customer.login-link.consume'), ['token' => $token])->assertRedirect(route('customer.orders.index'));
        } elseif ($method === 'otp') {
            $this->post(route('customer.login-code.request'), ['email' => $customer->email]);
            $code = CustomerAuthMailWork::query()->sole()->payload['token'];
            $this->withCookie(config('session.cookie'), session()->getId())->post(route('customer.login-code.consume'), [
                'email' => $customer->email, 'challenge' => session('customer_otp_challenge'), 'code' => $code,
            ])->assertRedirect(route('customer.orders.index'));
        } elseif (str_starts_with($method, 'google')) {
            config(['app.url' => 'https://store.example.test', 'services.google.client_id' => 'fixture-client', 'services.google.client_secret' => 'fixture-secret']);
            (new CustomerIdentity)->forceFill(['customer_id' => $customer->id, 'provider' => 'google', 'provider_subject' => (string) $foreign->id])->save();
            Http::fake([
                'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fixture-token', 'token_type' => 'Bearer']),
                'https://www.googleapis.com/oauth2/v3/userinfo' => Http::response(['sub' => (string) $foreign->id, 'email' => $foreign->email, 'email_verified' => true]),
            ]);
            $response = $this->get(route('customer.google.redirect'));
            parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
            $this->withCookie(config('session.cookie'), session()->getId())->get(route('customer.google.callback', ['state' => $query['state'], 'code' => 'fixture-code']))
                ->assertRedirect(route('customer.orders.index'));
        } else {
            config(['services.telegram.token' => 'fixture-token', 'storefront.links.telegram_bot_url' => 'https://t.me/TestStoreBot']);
            $response = $this->postJson(route('customer.telegram.login'))->assertCreated();
            parse_str(parse_url($response->json('deep_link'), PHP_URL_QUERY), $query);
            $service = app(CustomerTelegramAuthenticationService::class);
            $actor = (string) $customer->telegram_id;
            $confirmation = $service->prepare($query['start'], $actor, $actor, 'private');
            $this->assertNotNull($confirmation);
            $this->assertTrue($service->confirm($confirmation, $actor, $actor, 'private'));
            $this->withCookie(config('session.cookie'), session()->getId())->postJson(route('customer.telegram.consume'))->assertOk();
        }
    }

    private function paidOrder(Customer $customer, string $secret): array
    {
        $order = Order::factory()->create(['customer_id' => $customer->id, 'payment_status' => PaymentStatus::Paid, 'order_status' => OrderStatus::Completed]);
        $product = Product::factory()->create();
        $item = OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id]);
        ProductStockItem::create(['product_id' => $product->id, 'order_item_id' => $item->id, 'secret' => $secret]);
        Payment::factory()->create(['order_id' => $order->id, 'status' => PaymentStatus::Paid, 'paid_at' => now()]);

        return [$order, app(StorefrontOrderAccess::class)->issue($order)];
    }

    private function fund(Customer $customer, int $idr, int $usdt): void
    {
        app(StoreBalanceLedger::class)->creditTopUp($customer, $idr, 'compatibility-'.$customer->id, 'compatibility:'.$customer->id);
        $account = DB::table('customer_usdt_balance_accounts')->insertGetId([
            'customer_id' => $customer->id, 'currency' => 'USDT', 'balance_micro' => $usdt, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $entry = DB::table('customer_usdt_balance_entries')->insertGetId([
            'customer_usdt_balance_account_id' => $account, 'account_sequence' => 1, 'kind' => 'funding_credit',
            'amount_micro' => $usdt, 'balance_after_micro' => $usdt, 'source_type' => 'fixture', 'source_id' => 'compatibility-'.$customer->id,
            'idempotency_key' => 'compatibility:'.$customer->id, 'request_fingerprint' => hash('sha256', 'compatibility:'.$customer->id), 'created_at' => now(),
        ]);
        DB::table('customer_usdt_balance_accounts')->where('id', $account)->update(['last_entry_id' => $entry]);
    }
}
