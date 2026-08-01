<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\User;
use App\Models\Order;
use App\Models\Payment;
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
            ->assertRedirect('/admin?tab=stats#admin-payment-settings');

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
        ])->assertRedirect('/admin?tab=stats#admin-payment-settings');

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
            '/<form[^>]*id="admin-binance-enabled-setting"[^>]*>[\s\S]*?name="payment_mode" value="pakasir"[\s\S]*?name="pakasir_checkout_flow" value="api_qris"[\s\S]*?name="binance_pay_enabled" value="1"[\s\S]*?<\/form>/',
            $html,
        );

        $this->from('/admin?tab=stats')
            ->post(route('admin.payment-mode.update'), [
                'payment_mode' => 'pakasir',
                'pakasir_checkout_flow' => 'api_qris',
                'binance_pay_enabled' => '1',
            ])
            ->assertRedirect('/admin?tab=stats#admin-payment-settings')
            ->assertSessionDoesntHaveErrors(['pakasir_checkout_flow'], null, 'paymentMode');

        $this->assertDatabaseHas('manual_payment_settings', [
            'id' => $setting->id,
            'payment_mode' => 'pakasir',
            'pakasir_checkout_flow' => 'api_qris',
            'binance_pay_enabled' => true,
            'is_active' => true,
        ]);
    }

    public function test_owner_can_activate_order_id_from_dashboard_after_successful_canary(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        config()->set('services.binance.api_key', 'key');
        config()->set('services.binance.api_secret', 'secret');
        config()->set('services.binance.pay_id', '987654321');
        config()->set('services.telegram.admin_user_id', '777');

        $setting = ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => true,
            'binance_verification_mode' => 'amount_unique',
            'is_active' => true,
        ]);
        $admin = Customer::factory()->create(['telegram_id' => 777]);
        $order = Order::factory()->for($admin)->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        Payment::factory()->for($order)->create([
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
            'binance_verification_mode' => 'order_id',
            'binance_order_id' => '445011487581044736',
            'binance_order_id_submitted_at' => now()->subMinute(),
            'binance_verification_status' => 'confirmed',
            'provider_reference' => '445011487581044736',
            'binance_receiver_type' => 'pay_id',
            'binance_receiver_value' => '987654321',
        ]);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('Amount unik (legacy)')
            ->assertSee('Order ID');

        $this->post(route('admin.payment-mode.update'), [
            'payment_mode' => 'manual',
            'binance_verification_mode' => 'order_id',
        ])->assertRedirect('/admin?tab=stats#admin-payment-settings')
            ->assertSessionDoesntHaveErrors(['binance_verification_mode'], null, 'paymentMode');

        $this->assertSame('order_id', $setting->fresh()->binance_verification_mode);
        $this->assertNotNull($setting->fresh()->binance_order_id_compatibility_ready_at);
    }

    public function test_order_id_dashboard_activation_is_rejected_without_canary_and_legacy_mode_remains_available(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        config()->set('services.binance.api_key', 'key');
        config()->set('services.binance.api_secret', 'secret');
        config()->set('services.binance.pay_id', '987654321');
        config()->set('services.telegram.admin_user_id', '777');
        $setting = ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_verification_mode' => 'amount_unique',
            'is_active' => true,
        ]);

        $this->from('/admin?tab=stats')
            ->post(route('admin.payment-mode.update'), [
                'payment_mode' => 'manual',
                'binance_verification_mode' => 'order_id',
            ])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'stats']))
            ->assertSessionHasErrors(['binance_verification_mode'], null, 'paymentMode');
        $this->assertSame('amount_unique', $setting->fresh()->binance_verification_mode);

        $setting->update([
            'binance_verification_mode' => 'order_id',
            'binance_order_id_compatibility_ready_at' => now(),
        ]);
        $this->post(route('admin.payment-mode.update'), [
            'payment_mode' => 'manual',
            'binance_verification_mode' => 'amount_unique',
        ])->assertRedirect('/admin?tab=stats#admin-payment-settings');
        $this->assertSame('amount_unique', $setting->fresh()->binance_verification_mode);
    }

    public function test_order_id_rollback_does_not_depend_on_pakasir_readiness(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        config()->set('services.pakasir.slug', null);
        config()->set('services.pakasir.api_key', null);
        $setting = ManualPaymentSetting::create([
            'payment_mode' => 'pakasir',
            'pakasir_checkout_flow' => 'api_qris',
            'binance_verification_mode' => 'order_id',
            'binance_order_id_compatibility_ready_at' => now(),
            'is_active' => true,
        ]);

        $this->post(route('admin.payment-mode.update'), [
            'update_scope' => 'binance_verification',
            'payment_mode' => 'pakasir',
            'pakasir_checkout_flow' => 'api_qris',
            'binance_verification_mode' => 'amount_unique',
        ])->assertRedirect('/admin?tab=stats#admin-payment-settings');

        $setting->refresh();
        $this->assertSame('amount_unique', $setting->binance_verification_mode);
        $this->assertSame('pakasir', $setting->payment_mode);
        $this->assertSame('api_qris', $setting->pakasir_checkout_flow);
    }

    public function test_owner_can_update_payment_deadlines_from_dashboard(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
        ]));

        $setting = ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'qris_payment_ttl_minutes' => 10,
            'binance_payment_ttl_minutes' => 20,
            'is_active' => true,
        ]);

        $this->post(route('admin.payment-mode.update'), [
            'payment_mode' => 'manual',
            'qris_payment_ttl_minutes' => 7,
            'binance_payment_ttl_minutes' => 25,
        ])->assertRedirect('/admin?tab=stats#admin-payment-settings');

        $this->assertDatabaseHas('manual_payment_settings', [
            'id' => $setting->id,
            'qris_payment_ttl_minutes' => 7,
            'binance_payment_ttl_minutes' => 25,
        ]);
        $this->assertSame(7, AuditLog::query()->latest('id')->firstOrFail()->new_values['qris_payment_ttl_minutes']);
        $this->assertSame(25, AuditLog::query()->latest('id')->firstOrFail()->new_values['binance_payment_ttl_minutes']);
    }

    public function test_owner_can_update_public_whatsapp_support_from_dashboard(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
        ]));

        $setting = ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'is_active' => true,
        ]);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('Support WhatsApp')
            ->assertSee('Nomor publik toko disimpan di dashboard.');

        $this->post(route('admin.support-settings.update'), [
            'whatsapp_support_number' => '0812-3456-7890',
            'whatsapp_support_url' => '',
        ])->assertRedirect('/admin?tab=stats#admin-support-settings');

        $this->assertDatabaseHas('manual_payment_settings', [
            'id' => $setting->id,
            'whatsapp_support_number' => '6281234567890',
            'whatsapp_support_url' => null,
        ]);
        $auditLog = AuditLog::query()->latest('id')->firstOrFail();
        $this->assertSame('support_settings.updated', $auditLog->action);
        $this->assertSame('6281234567890', $auditLog->new_values['whatsapp_support_number']);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('https://wa.me/6281234567890');
    }

    public function test_payment_deadlines_must_be_positive_integers(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Admin,
        ]));

        $this->from('/admin?tab=stats')
            ->post(route('admin.payment-mode.update'), [
                'payment_mode' => 'manual',
                'qris_payment_ttl_minutes' => 0,
                'binance_payment_ttl_minutes' => 'twenty',
            ])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'stats']))
            ->assertSessionHasErrors(['qris_payment_ttl_minutes', 'binance_payment_ttl_minutes'], null, 'paymentMode');
    }

    public function test_whatsapp_support_url_must_be_valid_when_updated_from_dashboard(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Admin,
        ]));

        $this->from('/admin?tab=stats')
            ->post(route('admin.support-settings.update'), [
                'whatsapp_support_url' => 'not-a-url',
                'whatsapp_support_number' => 'admin<script>',
            ])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'stats']))
            ->assertSessionHasErrors(['whatsapp_support_url', 'whatsapp_support_number'], null, 'supportSettings');
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
            ->assertRedirect('/admin?tab=stats#admin-payment-settings');

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
