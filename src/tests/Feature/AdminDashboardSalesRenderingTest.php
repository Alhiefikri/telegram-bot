<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminDashboardSalesRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_penjualan_tab_renders_compact_order_cards_with_operational_filters(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
        ]));

        $customer = Customer::factory()->create([
            'first_name' => 'Budi',
            'username' => 'budilanjut',
        ]);
        $completedOrder = Order::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-DONE',
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => 75000,
            'created_at' => Carbon::parse('2026-06-29 10:15:00'),
        ]);
        OrderItem::factory()->create([
            'order_id' => $completedOrder->id,
            'product_id' => null,
            'product_name' => 'ChatGPT Plus 1 Bulan',
            'quantity' => 1,
            'unit_price' => 75000,
            'subtotal' => 75000,
        ]);
        Payment::factory()->create([
            'order_id' => $completedOrder->id,
            'status' => PaymentStatus::Paid,
            'amount' => 70000,
            'paid_at' => Carbon::parse('2026-06-29 10:20:00'),
        ]);

        $cancelledOrder = Order::factory()->create([
            'invoice_number' => 'INV-ISSUE',
            'order_status' => OrderStatus::Cancelled,
            'payment_status' => PaymentStatus::Failed,
            'total_amount' => 45000,
            'created_at' => Carbon::parse('2026-06-28 09:00:00'),
        ]);
        OrderItem::factory()->create([
            'order_id' => $cancelledOrder->id,
            'product_id' => null,
            'product_name' => 'Canva Pro',
            'subtotal' => 45000,
        ]);

        $this->get('/admin?tab=penjualan')
            ->assertOk()
            ->assertSee('Order masuk dan diproses')
            ->assertSeeInOrder(['Semua', 'Selesai', 'Masalah', 'Menunggu', 'Diproses'])
            ->assertSee('salesFilter', false)
            ->assertSee("salesFilter = 'completed'", false)
            ->assertSee('data-order-filter-groups="all completed"', false)
            ->assertSee('data-order-filter-groups="all issue"', false)
            ->assertSee('grid grid-cols-2 gap-2', false)
            ->assertSee('INV-DONE')
            ->assertSee('ChatGPT Plus 1 Bulan')
            ->assertSee('IDR 75.000')
            ->assertSee('IDR 70.000')
            ->assertSee('Budi (@budilanjut)')
            ->assertSee('29 Jun 2026')
            ->assertSee('Selesai')
            ->assertSee('Masalah')
            ->assertDontSee('Escrow')
            ->assertDontSee('<table', false);
    }
}
