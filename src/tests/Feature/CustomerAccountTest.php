<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\CustomerLoginLink;
use App\Models\Order;
use App\Notifications\CustomerLoginLinkNotification;
use App\Services\Customer\CustomerOrderClaimService;
use App\Storefront\StorefrontOrderAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_customer_order_list_is_scoped_to_paid_orders(): void
    {
        $customer = Customer::factory()->create(['email' => 'owner@example.test', 'email_verified_at' => now()]);
        Order::factory()->create(['customer_id' => $customer->id, 'payment_status' => PaymentStatus::Paid]);
        Order::factory()->create(['customer_id' => $customer->id, 'payment_status' => PaymentStatus::Pending]);
        $other = Order::factory()->create(['payment_status' => PaymentStatus::Paid]);
        $this->actingAs($customer, 'customer')->get(route('customer.orders.index'))
            ->assertOk()
            ->assertSee($customer->orders()->where('payment_status', PaymentStatus::Paid)->first()->invoice_number)
            ->assertDontSee($other->invoice_number);
    }

    public function test_customer_order_list_is_paginated(): void
    {
        $customer = Customer::factory()->create(['email' => 'owner@example.test', 'email_verified_at' => now()]);
        Order::factory()->count(21)->create(['customer_id' => $customer->id, 'payment_status' => PaymentStatus::Paid]);

        $this->actingAs($customer, 'customer')->get(route('customer.orders.index'))
            ->assertOk()
            ->assertSee('page=2');
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
