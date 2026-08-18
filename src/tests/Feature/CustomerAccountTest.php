<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\CustomerLoginLink;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Notifications\CustomerLoginLinkNotification;
use App\Services\Customer\CustomerOrderClaimService;
use App\Storefront\StorefrontOrderAccess;
use App\Storefront\StorefrontShopPaymentStatusRefresh;
use App\Storefront\StorefrontShopSignedStatusRefreshResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CustomerAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_request_is_generic_stores_only_a_token_hash_and_emails_fragment_link(): void
    {
        Notification::fake();

        $response = $this->post(route('customer.login-link.request'), ['email' => ' Customer@Example.TEST ']);

        $response->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_sent');
        $link = CustomerLoginLink::query()->firstOrFail();
        $this->assertSame('customer@example.test', $link->email);
        $this->assertSame(64, strlen($link->token_hash));
        Notification::assertSentOnDemand(CustomerLoginLinkNotification::class, function (CustomerLoginLinkNotification $notification): bool {
            $url = (string) $notification->toMail((object) [])->actionUrl;

            return str_starts_with($url, route('customer.login-link.show').'#token=')
                && ! str_contains($url, '?token=')
                && preg_match('/\/akun\/login-link#token=[a-f0-9]{64}$/i', $url) === 1;
        });
    }

    public function test_valid_login_link_is_single_use_and_sets_verified_customer_session(): void
    {
        $token = 'a'.str_repeat('b', 63);
        CustomerLoginLink::create([
            'email' => 'customer@example.test',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->post(route('customer.login-link.consume'), ['token' => $token])
            ->assertRedirect(route('customer.orders.index'))
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $this->assertAuthenticated('customer');
        $this->assertNotNull(Customer::query()->firstOrFail()->email_verified_at);
        $this->post(route('customer.login-link.consume'), ['token' => $token])
            ->assertRedirect(route('customer.account'))
            ->assertSessionHas('customer_login_error');
    }

    public function test_login_link_used_state_cannot_be_mass_assigned(): void
    {
        $link = CustomerLoginLink::create([
            'email' => 'customer@example.test',
            'token_hash' => hash('sha256', str_repeat('a', 64)),
            'expires_at' => now()->addMinutes(15),
            'requested_ip' => '192.0.2.10',
            'used_at' => now(),
        ]);

        $fresh = $link->fresh();

        $this->assertNotNull($fresh);
        $this->assertSame('customer@example.test', $fresh->email);
        $this->assertSame('192.0.2.10', $fresh->requested_ip);
        $this->assertNull(
            $fresh->used_at,
            'The one-time-used state must only be changed by the explicit consume flow.',
        );
    }

    public function test_customer_serialization_hides_sensitive_fields(): void
    {
        $customer = Customer::factory()->create([
            'telegram_id' => 12345,
            'username' => 'private-user',
            'phone_number' => '08123456789',
            'email' => 'customer@example.test',
            'email_verified_at' => now(),
        ]);

        $serialized = $customer->toArray();

        foreach (['telegram_id', 'username', 'phone_number', 'email', 'email_verified_at'] as $key) {
            $this->assertArrayNotHasKey($key, $serialized);
        }
        $this->assertSame($customer->first_name, $serialized['first_name'] ?? null);
    }

    public function test_customer_order_list_is_scoped_to_customer_and_includes_each_order_status(): void
    {
        $customer = Customer::factory()->create(['email' => 'owner@example.test', 'email_verified_at' => now()]);
        $orders = [
            ['invoice_number' => 'INV-DRAFT', 'order_status' => OrderStatus::Draft, 'payment_status' => PaymentStatus::Pending],
            ['invoice_number' => 'INV-WAITING', 'order_status' => OrderStatus::PendingPayment, 'payment_status' => PaymentStatus::Pending],
            ['invoice_number' => 'INV-PROCESSING', 'order_status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::Paid],
            ['invoice_number' => 'INV-COMPLETED', 'order_status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Paid],
            ['invoice_number' => 'INV-EXPIRED', 'order_status' => OrderStatus::Expired, 'payment_status' => PaymentStatus::Expired],
            ['invoice_number' => 'INV-CANCELLED', 'order_status' => OrderStatus::Cancelled, 'payment_status' => PaymentStatus::Failed],
        ];

        foreach ($orders as $attributes) {
            Order::factory()->create(['customer_id' => $customer->id, ...$attributes]);
        }

        $other = Order::factory()->create(['invoice_number' => 'INV-OTHER', 'payment_status' => PaymentStatus::Paid]);

        $response = $this->actingAs($customer, 'customer')->get(route('customer.orders.index'));

        $response
            ->assertOk()
            ->assertSee('Status Order')
            ->assertSee('Status Pembayaran')
            ->assertSee('Menunggu pembayaran')
            ->assertSee('Dibayar')
            ->assertSee('Gagal')
            ->assertSee('Kedaluwarsa')
            ->assertDontSee($other->invoice_number);

        foreach ($orders as $attributes) {
            $response->assertSee($attributes['invoice_number']);
        }
    }

    public function test_customer_order_list_filters_order_status_groups_and_preserves_filter_pagination(): void
    {
        $customer = Customer::factory()->create(['email' => 'owner@example.test', 'email_verified_at' => now()]);
        Order::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-WAITING-1',
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
        ]);
        foreach (range(1, 20) as $index) {
            Order::factory()->create([
                'customer_id' => $customer->id,
                'invoice_number' => 'INV-WAITING-'.($index + 1),
                'order_status' => OrderStatus::PendingPayment,
                'payment_status' => PaymentStatus::Pending,
                'created_at' => now()->subMinutes($index),
            ]);
        }
        $processing = Order::factory()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-PROCESSING-FILTER', 'order_status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::Paid]);
        $completed = Order::factory()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-COMPLETED-FILTER', 'order_status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Paid]);
        $issue = Order::factory()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-ISSUE-FILTER', 'order_status' => OrderStatus::Expired, 'payment_status' => PaymentStatus::Expired]);

        $this->actingAs($customer, 'customer')->get(route('customer.orders.index', ['status' => 'waiting', 'page' => 2]))
            ->assertOk()
            ->assertSee('Menunggu')
            ->assertSee('status=waiting', false)
            ->assertDontSee($processing->invoice_number)
            ->assertDontSee($completed->invoice_number)
            ->assertDontSee($issue->invoice_number);

        foreach ([
            'processing' => [$processing, [$completed, $issue]],
            'completed' => [$completed, [$processing, $issue]],
            'issue' => [$issue, [$processing, $completed]],
        ] as $filter => [$included, $excluded]) {
            $response = $this->actingAs($customer, 'customer')->get(route('customer.orders.index', ['status' => $filter]));
            $response->assertOk()->assertSee($included->invoice_number);
            foreach ($excluded as $excludedOrder) {
                $response->assertDontSee($excludedOrder->invoice_number);
            }
        }

        $this->actingAs($customer, 'customer')->get(route('customer.orders.index', ['status' => 'not-a-filter']))
            ->assertOk()
            ->assertSee($processing->invoice_number)
            ->assertSee($completed->invoice_number)
            ->assertSee($issue->invoice_number);
    }

    public function test_customer_order_list_distinguishes_empty_history_from_empty_filter(): void
    {
        $customer = Customer::factory()->create(['email' => 'owner@example.test', 'email_verified_at' => now()]);

        $this->actingAs($customer, 'customer')->get(route('customer.orders.index'))
            ->assertOk()
            ->assertSee('Belum ada riwayat pesanan di akun ini.');

        Order::factory()->create([
            'customer_id' => $customer->id,
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);

        $this->actingAs($customer, 'customer')->get(route('customer.orders.index', ['status' => 'waiting']))
            ->assertOk()
            ->assertSee('Tidak ada pesanan pada filter "Menunggu".', false);
    }

    public function test_customer_order_history_uses_deterministic_newest_first_ordering(): void
    {
        $customer = Customer::factory()->create(['email' => 'owner@example.test', 'email_verified_at' => now()]);
        $timestamp = now()->startOfSecond();
        $first = Order::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-SAME-TIME-FIRST',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $second = Order::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-SAME-TIME-SECOND',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $expectedOrder = collect([$first, $second])->sortByDesc('id')->pluck('invoice_number')->all();

        $this->actingAs($customer, 'customer')->get(route('customer.orders.index'))
            ->assertOk()
            ->assertSeeInOrder($expectedOrder);
    }

    public function test_customer_can_open_owned_orders_across_lifecycle_statuses_but_not_another_customer_order(): void
    {
        $customer = Customer::factory()->create(['email' => 'owner@example.test', 'email_verified_at' => now()]);
        $orders = [
            ['invoice_number' => 'INV-PENDING-DETAIL', 'order_status' => OrderStatus::PendingPayment, 'payment_status' => PaymentStatus::Pending, 'method' => 'hosted'],
            ['invoice_number' => 'INV-PROCESSING-DETAIL', 'order_status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::Paid, 'method' => 'hosted'],
            ['invoice_number' => 'INV-EXPIRED-DETAIL', 'order_status' => OrderStatus::Expired, 'payment_status' => PaymentStatus::Expired, 'method' => 'hosted'],
            ['invoice_number' => 'INV-CANCELLED-DETAIL', 'order_status' => OrderStatus::Cancelled, 'payment_status' => PaymentStatus::Failed, 'method' => 'hosted'],
        ];

        foreach ($orders as $attributes) {
            $paymentMethod = $attributes['method'];
            unset($attributes['method']);
            $order = Order::factory()->create(['customer_id' => $customer->id, ...$attributes]);
            Payment::factory()->create([
                'order_id' => $order->id,
                'provider' => 'pakasir',
                'merchant_ref' => 'MR-'.$attributes['invoice_number'],
                'method' => $paymentMethod,
                'status' => $attributes['payment_status'],
            ]);

            $response = $this->actingAs($customer, 'customer')->get(route('customer.orders.show', $order));

            $response
                ->assertOk()
                ->assertSee($attributes['invoice_number'])
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertHeader('Referrer-Policy', 'no-referrer');
            if ($attributes['order_status'] === OrderStatus::PendingPayment) {
                $response->assertSee('/akun/pesanan/'.$order->getKey().'/status', false);
            }
        }

        $otherOrder = Order::factory()->create(['invoice_number' => 'INV-DETAIL-OTHER']);

        $this->actingAs($customer, 'customer')->get(route('customer.orders.show', $otherOrder))
            ->assertNotFound();
    }

    public function test_customer_payment_status_refresh_redirects_back_to_account_detail(): void
    {
        $customer = Customer::factory()->create(['email' => 'owner@example.test', 'email_verified_at' => now()]);
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-ACCOUNT-STATUS',
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
        ]);

        $result = StorefrontShopSignedStatusRefreshResult::refreshed(
            PaymentStatus::Paid,
            OrderStatus::Processing,
            isPaid: true,
            isTerminal: false,
            qrisReady: false,
            shouldReload: false,
            redirectUrl: '/shop/orders/'.$order->getKey(),
        );
        $this->mock(StorefrontShopPaymentStatusRefresh::class)
            ->shouldReceive('execute')
            ->once()
            ->withArgs(fn (Order $ownedOrder): bool => $ownedOrder->is($order))
            ->andReturn($result);

        $this->actingAs($customer, 'customer')->post(route('customer.orders.status', $order))
            ->assertOk()
            ->assertJsonPath('is_paid', true)
            ->assertJsonPath('redirect_url', route('customer.orders.show', $order));
    }

    public function test_nonpaid_customer_order_detail_does_not_reveal_credentials_recovery_key_or_copy_controls(): void
    {
        $customer = Customer::factory()->create(['email' => 'owner@example.test', 'email_verified_at' => now()]);
        $product = Product::factory()->create(['name' => 'Private Account Product']);
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-PRIVATE-PENDING',
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
        ]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
        ]);
        ProductStockItem::create([
            'product_id' => $product->id,
            'order_item_id' => $item->id,
            'secret' => 'private-account@example.test|NeverRevealThis',
        ]);
        Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'manual',
            'merchant_ref' => 'MR-PRIVATE-PENDING',
            'method' => 'QR_MANUAL',
            'status' => PaymentStatus::Pending,
        ]);
        $recoveryKey = 'LHIE-PRIVATE-RECOVERY-KEY';
        $order->forceFill(['credential_access_key_hash' => Hash::make($recoveryKey)])->save();

        $this->actingAs($customer, 'customer')->get(route('customer.orders.show', $order))
            ->assertOk()
            ->assertSee('Data akun aman sampai pembayaran masuk')
            ->assertDontSee('private-account@example.test|NeverRevealThis', false)
            ->assertDontSee($recoveryKey, false)
            ->assertDontSee('data-copy-text', false);
    }

    public function test_paid_customer_order_detail_keeps_credential_delivery_and_copy_behavior(): void
    {
        $customer = Customer::factory()->create(['email' => 'owner@example.test', 'email_verified_at' => now()]);
        $product = Product::factory()->create(['name' => 'Paid Account Product']);
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-PRIVATE-PAID',
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
        ]);
        $secret = 'paid-account@example.test|KeepThisCredential';
        ProductStockItem::create(['product_id' => $product->id, 'order_item_id' => $item->id, 'secret' => $secret]);
        Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'pakasir',
            'merchant_ref' => 'MR-PRIVATE-PAID',
            'method' => 'hosted',
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        $this->actingAs($customer, 'customer')->get(route('customer.orders.show', $order))
            ->assertOk()
            ->assertSee('Data akun siap digunakan')
            ->assertSee($secret, false)
            ->assertSee('data-copy-text="'.$secret.'"', false);
    }

    public function test_paid_guest_order_can_be_claimed_and_repeat_claim_is_idempotent(): void
    {
        $customer = Customer::factory()->create(['email' => 'owner@example.test', 'email_verified_at' => now()]);
        $order = Order::factory()->create([
            'customer_id' => Customer::factory()->create(['telegram_id' => null])->id,
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $key = app(StorefrontOrderAccess::class)->issue($order);

        $service = app(CustomerOrderClaimService::class);
        $this->assertNotNull($service->claim($customer, $order->invoice_number, $key));
        $this->assertSame($customer->id, $order->fresh()->customer_id);
        $this->assertNotNull($service->claim($customer, $order->invoice_number, $key));
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.customer_claimed', 'subject_id' => $order->id]);
    }

    public function test_paid_guest_order_with_support_contact_can_be_claimed_only_with_invoice_and_recovery_key(): void
    {
        $customer = Customer::factory()->create(['email' => 'owner@example.test', 'email_verified_at' => now()]);
        $order = Order::factory()->create([
            'customer_id' => Customer::factory()->create(['telegram_id' => null, 'email' => null])->id,
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'support_contact_channel' => 'telegram',
            'support_contact_value' => '@support_buyer',
        ]);
        $key = app(StorefrontOrderAccess::class)->issue($order);

        $claimed = app(CustomerOrderClaimService::class)->claim($customer, $order->invoice_number, $key);

        $this->assertNotNull($claimed);
        $this->assertSame($customer->id, $order->fresh()->customer_id);
    }

    public function test_invalid_order_claim_does_not_attach_order(): void
    {
        $customer = Customer::factory()->create(['email' => 'owner@example.test', 'email_verified_at' => now()]);
        $order = Order::factory()->create([
            'customer_id' => Customer::factory()->create(['telegram_id' => null, 'email' => null])->id,
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        app(StorefrontOrderAccess::class)->issue($order);

        $this->assertNull(app(CustomerOrderClaimService::class)->claim($customer, $order->invoice_number, 'LHIE-WRONG-KEY-123'));
        $this->assertNotSame($customer->id, $order->fresh()->customer_id);
    }

    public function test_order_owned_by_another_customer_account_cannot_be_claimed(): void
    {
        $customer = Customer::factory()->create(['email' => 'owner@example.test', 'email_verified_at' => now()]);
        $other = Customer::factory()->create(['email' => 'other@example.test', 'email_verified_at' => now(), 'telegram_id' => null]);
        $order = Order::factory()->create([
            'customer_id' => $other->id,
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $key = app(StorefrontOrderAccess::class)->issue($order);

        $this->assertNull(app(CustomerOrderClaimService::class)->claim($customer, $order->invoice_number, $key));
        $this->assertSame($other->id, $order->fresh()->customer_id);
    }

    public function test_failed_order_claim_does_not_flash_recovery_key_to_old_input(): void
    {
        $customer = Customer::factory()->create(['email' => 'owner@example.test', 'email_verified_at' => now()]);
        $submittedKey = 'LHIE-THISKEYMUSTNOTFLASH';

        $this->actingAs($customer, 'customer')
            ->from(route('customer.claim.create'))
            ->post(route('customer.claim.store'), [
                'invoice_number' => 'INV-NOT-FOUND',
                'access_key' => $submittedKey,
            ])
            ->assertRedirect(route('customer.claim.create'))
            ->assertSessionHas('customer_claim_error', 'Nomor invoice atau recovery key tidak cocok.');

        $oldInput = session()->getOldInput();

        $this->assertSame('INV-NOT-FOUND', $oldInput['invoice_number'] ?? null);
        $this->assertArrayNotHasKey('access_key', $oldInput);
        $this->assertStringNotContainsString($submittedKey, (string) json_encode($oldInput));
    }
}
