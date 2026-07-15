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

    public function test_login_request_is_generic_and_stores_only_a_token_hash(): void
    {
        Notification::fake();

        $response = $this->post(route('customer.login-link.request'), ['email' => ' Customer@Example.TEST ']);

        $response->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_sent');
        $link = CustomerLoginLink::query()->firstOrFail();
        $this->assertSame('customer@example.test', $link->email);
        $this->assertSame(64, strlen($link->token_hash));
        Notification::assertSentOnDemand(CustomerLoginLinkNotification::class);
    }

    public function test_valid_login_link_is_single_use_and_sets_verified_customer_session(): void
    {
        $token = 'a'.str_repeat('b', 63);
        CustomerLoginLink::create([
            'email' => 'customer@example.test',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->get(route('customer.login-link.consume', ['token' => $token]))
            ->assertRedirect(route('customer.orders.index'))
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $this->assertAuthenticated('customer');
        $this->assertNotNull(Customer::query()->firstOrFail()->email_verified_at);
        $this->get(route('customer.login-link.consume', ['token' => $token]))
            ->assertRedirect(route('customer.account'))
            ->assertSessionHas('customer_login_error');
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
}
