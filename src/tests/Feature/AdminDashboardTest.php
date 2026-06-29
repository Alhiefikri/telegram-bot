<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_admin_login(): void
    {
        $this->get('/admin')
            ->assertRedirect('/admin/login');
    }

    public function test_user_without_admin_panel_access_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create(['role' => null]));

        $this->get('/admin')
            ->assertForbidden();
    }

    public function test_admin_dashboard_shell_renders_manual_blade_tabs_without_sidebar_or_escrow(): void
    {
        $this->actingAs(User::factory()->create([
            'name' => 'Lhie Admin',
            'role' => UserRole::Owner,
        ]));

        Product::factory()->count(3)->create(['is_active' => true]);
        Product::factory()->create(['is_active' => false]);
        Order::factory()->create(['order_status' => OrderStatus::Processing, 'total_amount' => 125000]);

        $response = $this->get('/admin')
            ->assertOk()
            ->assertSee('LHIE STORE')
            ->assertSee('Lhie Admin')
            ->assertSee('Saldo')
            ->assertSee('GMV')
            ->assertSee('Produk')
            ->assertSee('Perlu diproses')
            ->assertSee('Belanja Produk Lain')
            ->assertSee('Bagikan Toko')
            ->assertSeeInOrder(['Stats', 'Produk', 'Penjualan', 'Tambah', 'Withdraw', 'Riwayat', 'Affiliate'])
            ->assertSee('overflow-x-auto', false)
            ->assertSee('x-data', false)
            ->assertDontSee('Escrow')
            ->assertDontSee('fi-sidebar', false);

        $this->assertStringNotContainsString('<aside', $response->getContent());
    }
}
