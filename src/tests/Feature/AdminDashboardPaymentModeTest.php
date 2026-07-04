<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ManualPaymentSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardPaymentModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_toggle_payment_mode_from_manual_dashboard(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
        ]));

        config()->set('services.pakasir.slug', 'sedulur-digital');
        config()->set('services.pakasir.api_key', 'pakasir-key');
        config()->set('services.pakasir.base_url', 'https://app.pakasir.test');
        config()->set('services.pakasir.checkout_flow', 'hosted');

        $setting = ManualPaymentSetting::create([
            'payment_mode' => 'pakasir',
            'admin_telegram_chat_id' => '999',
            'is_active' => true,
        ]);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('Mode pembayaran')
            ->assertSee('Pakasir hosted aktif')
            ->assertSee('QR Manual /payment');

        $this->post(route('admin.payment-mode.update'), ['payment_mode' => 'manual'])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'stats']));

        $this->assertDatabaseHas('manual_payment_settings', [
            'id' => $setting->id,
            'payment_mode' => 'manual',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment_mode.updated',
            'subject_type' => ManualPaymentSetting::class,
            'subject_id' => (string) $setting->id,
        ]);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('Manual /payment aktif')
            ->assertSee('QR manual LHIE STORE');
    }

    public function test_saved_pakasir_mode_is_reported_unready_and_effectively_manual_when_config_missing(): void
    {
        config()->set('services.pakasir.slug', '');
        config()->set('services.pakasir.api_key', '');
        config()->set('services.pakasir.checkout_flow', 'hosted');
        config()->set('services.pakasir.base_url', 'https://app.pakasir.test');

        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
        ]));

        ManualPaymentSetting::create([
            'payment_mode' => 'pakasir',
            'is_active' => true,
        ]);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('Manual /payment aktif — Pakasir belum siap')
            ->assertSee('Mode tersimpan meminta Pakasir, tapi checkout baru dialihkan ke QR manual sampai konfigurasi Pakasir siap.')
            ->assertSee('Mode Pakasir belum siap. Lengkapi PAKASIR_SLUG dan PAKASIR_API_KEY di environment. Checkout baru akan memakai QR manual sampai diperbaiki.')
            ->assertSee('PAKASIR BELUM SIAP')
            ->assertDontSee('Pakasir hosted aktif');
    }

    public function test_invalid_pakasir_checkout_flow_is_explained_by_admin_dashboard(): void
    {
        config()->set('services.pakasir.slug', 'warung-ai');
        config()->set('services.pakasir.api_key', 'pakasir-secret');
        config()->set('services.pakasir.base_url', 'https://app.pakasir.test');
        config()->set('services.pakasir.checkout_flow', 'inline_qris');

        $this->actingAs(User::factory()->create([
            'role' => UserRole::Admin,
        ]));

        ManualPaymentSetting::create([
            'payment_mode' => 'pakasir',
            'is_active' => true,
        ]);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('Manual /payment aktif — Pakasir belum siap')
            ->assertSee('Mode Pakasir belum siap. PAKASIR_CHECKOUT_FLOW must be one of: hosted, api_qris. Checkout baru akan memakai QR manual sampai diperbaiki.')
            ->assertDontSee('Pakasir hosted aktif');
    }

    public function test_payment_mode_toggle_creates_active_setting_when_none_exists(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Admin,
        ]));

        $this->assertSame(0, ManualPaymentSetting::query()->count());

        $this->post(route('admin.payment-mode.update'), ['payment_mode' => 'manual'])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'stats']));

        $this->assertDatabaseHas('manual_payment_settings', [
            'payment_mode' => 'manual',
            'is_active' => true,
        ]);
    }

    public function test_payment_mode_validation_uses_payment_mode_error_bag_and_stats_tab(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Admin,
        ]));

        $this->from('/admin?tab=stats')
            ->post(route('admin.payment-mode.update'), ['payment_mode' => 'unknown'])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'stats']))
            ->assertSessionHasErrors(['payment_mode'], null, 'paymentMode');

        $this->assertDatabaseCount('manual_payment_settings', 0);
    }

    public function test_non_manager_role_cannot_toggle_payment_mode(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Finance,
        ]));

        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'is_active' => true,
        ]);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('Hanya owner/admin yang bisa mengubah mode pembayaran.');

        $this->post(route('admin.payment-mode.update'), ['payment_mode' => 'pakasir'])
            ->assertForbidden();

        $this->assertDatabaseMissing('manual_payment_settings', [
            'payment_mode' => 'pakasir',
        ]);
    }
}
