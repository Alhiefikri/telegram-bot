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

        $setting = ManualPaymentSetting::create([
            'payment_mode' => 'pakasir',
            'admin_telegram_chat_id' => '999',
            'is_active' => true,
        ]);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('Mode pembayaran')
            ->assertSee('Pakasir hosted aktif')
            ->assertSee('QR Manual')
            ->assertSee('Hosted Pakasir')
            ->assertSee('QR Native Pakasir')
            ->assertSee('name="payment_mode" value="manual"', false)
            ->assertSee('name="payment_mode" value="pakasir"', false)
            ->assertSee('name="pakasir_checkout_flow" value="hosted"', false)
            ->assertSee('name="pakasir_checkout_flow" value="api_qris"', false);

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

    public function test_owner_can_switch_dashboard_to_native_qris_pakasir_flow(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
        ]));

        config()->set('services.pakasir.slug', 'sedulur-digital');
        config()->set('services.pakasir.api_key', 'pakasir-key');
        config()->set('services.pakasir.base_url', 'https://app.pakasir.test');

        $setting = ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'pakasir_checkout_flow' => 'hosted',
            'admin_telegram_chat_id' => '999',
            'is_active' => true,
        ]);

        $this->post(route('admin.payment-mode.update'), [
            'payment_mode' => 'pakasir',
            'pakasir_checkout_flow' => 'api_qris',
        ])->assertRedirect(route('admin.dashboard', ['tab' => 'stats']));

        $this->assertDatabaseHas('manual_payment_settings', [
            'id' => $setting->id,
            'payment_mode' => 'pakasir',
            'pakasir_checkout_flow' => 'api_qris',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment_mode.updated',
            'subject_type' => ManualPaymentSetting::class,
            'subject_id' => (string) $setting->id,
        ]);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('Pakasir native QRIS aktif')
            ->assertSee('QR payment_number dikirim di Telegram');
    }

    public function test_owner_can_toggle_binance_pay_while_pakasir_mode_is_active(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
        ]));

        config()->set('services.pakasir.slug', 'sedulur-digital');
        config()->set('services.pakasir.api_key', 'pakasir-key');
        config()->set('services.pakasir.base_url', 'https://app.pakasir.test');

        $setting = ManualPaymentSetting::create([
            'payment_mode' => 'pakasir',
            'pakasir_checkout_flow' => 'api_qris',
            'binance_pay_enabled' => false,
            'admin_telegram_chat_id' => '999',
            'is_active' => true,
        ]);

        $html = $this->get('/admin')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<form[^>]*class="mt-3"[^>]*>[\s\S]*name="payment_mode" value="pakasir"[\s\S]*name="pakasir_checkout_flow" value="api_qris"[\s\S]*name="binance_pay_enabled" value="1"/',
            $html,
        );

        $this->from('/admin?tab=stats')
            ->post(route('admin.payment-mode.update'), [
                'payment_mode' => 'pakasir',
                'pakasir_checkout_flow' => 'api_qris',
                'binance_pay_enabled' => '1',
            ])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'stats']))
            ->assertSessionDoesntHaveErrors(['pakasir_checkout_flow'], null, 'paymentMode');

        $this->assertDatabaseHas('manual_payment_settings', [
            'id' => $setting->id,
            'payment_mode' => 'pakasir',
            'pakasir_checkout_flow' => 'api_qris',
            'binance_pay_enabled' => true,
            'is_active' => true,
        ]);
    }

    public function test_saved_pakasir_mode_is_reported_unready_and_effectively_manual_when_config_missing(): void
    {
        config()->set('services.pakasir.slug', '');
        config()->set('services.pakasir.api_key', '');
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

        $this->actingAs(User::factory()->create([
            'role' => UserRole::Admin,
        ]));

        ManualPaymentSetting::create([
            'payment_mode' => 'pakasir',
            'pakasir_checkout_flow' => 'inline_qris',
            'is_active' => true,
        ]);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('Manual /payment aktif — Pakasir belum siap')
            ->assertSee('Mode Pakasir belum siap. pakasir_checkout_flow must be one of: hosted, api_qris. Checkout baru akan memakai QR manual sampai diperbaiki.')
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
