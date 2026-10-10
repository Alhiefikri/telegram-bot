<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\ManualPaymentSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\TestCase;

class AdminDashboardPaymentModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_toggle_payment_mode_from_manual_dashboard(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
        ]));

        KlikQrisSandboxScenarios::configureReadySandbox();

        $setting = ManualPaymentSetting::create([
            'payment_mode' => 'klikqris',
            'admin_telegram_chat_id' => '999',
            'is_active' => true,
        ]);

        $this->post(route('admin.payment-mode.update'), [
            'payment_mode' => 'manual',
            'confirm_provider_switch' => '1',
        ])
            ->assertRedirect('/admin?tab=settings&section=pembayaran#admin-payment-settings');

        $this->assertDatabaseHas('manual_payment_settings', [
            'id' => $setting->id,
            'payment_mode' => 'manual',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment_provider.switched',
            'subject_type' => ManualPaymentSetting::class,
            'subject_id' => (string) $setting->id,
        ]);

    }

    public function test_owner_can_toggle_binance_pay_while_klikqris_mode_is_active(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
        ]));

        KlikQrisSandboxScenarios::configureReadySandbox();

        $setting = ManualPaymentSetting::create([
            'payment_mode' => 'klikqris',
            'binance_pay_enabled' => false,
            'admin_telegram_chat_id' => '999',
            'is_active' => true,
        ]);

        $this->from('/admin?tab=stats')
            ->post(route('admin.payment-mode.update'), [
                'update_scope' => 'binance_settings',
                'payment_mode' => 'klikqris',
                'binance_pay_enabled' => '1',
            ])
            ->assertRedirect('/admin?tab=settings&section=pembayaran#admin-payment-settings')
            ->assertSessionDoesntHaveErrors(['pakasir_checkout_flow'], null, 'paymentMode');

        $this->assertDatabaseHas('manual_payment_settings', [
            'id' => $setting->id,
            'payment_mode' => 'klikqris',
            'binance_pay_enabled' => true,
            'is_active' => true,
        ]);
    }

    public function test_owner_can_activate_order_id_from_dashboard_with_valid_credentials(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        config()->set('services.binance.api_key', 'key');
        config()->set('services.binance.api_secret', 'secret');
        config()->set('services.binance.pay_id', '987654321');

        $setting = ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => true,
            'binance_verification_mode' => 'amount_unique',
            'is_active' => true,
        ]);

        $this->post(route('admin.payment-mode.update'), [
            'update_scope' => 'binance_verification',
            'payment_mode' => 'manual',
            'binance_verification_mode' => 'order_id',
        ])->assertRedirect('/admin?tab=settings&section=pembayaran#admin-payment-settings')
            ->assertSessionDoesntHaveErrors(['binance_verification_mode'], null, 'paymentMode');

        $this->assertSame('order_id', $setting->fresh()->binance_verification_mode);
        $this->assertNull($setting->fresh()->binance_order_id_compatibility_ready_at);
    }

    public function test_order_id_dashboard_activation_is_rejected_without_binance_api_credentials(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        config()->set('services.binance.api_key', null);
        config()->set('services.binance.api_secret', null);
        config()->set('services.binance.pay_id', '987654321');
        $setting = ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_verification_mode' => 'amount_unique',
            'is_active' => true,
        ]);

        $this->from('/admin?tab=stats')
            ->post(route('admin.payment-mode.update'), [
                'update_scope' => 'binance_verification',
                'payment_mode' => 'manual',
                'binance_verification_mode' => 'order_id',
            ])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'settings', 'section' => 'pembayaran']))
            ->assertSessionHasErrors(['binance_verification_mode'], null, 'paymentMode');
        $this->assertSame('amount_unique', $setting->fresh()->binance_verification_mode);
    }

    public function test_order_id_dashboard_activation_is_rejected_without_binance_pay_receiver(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        config()->set('services.binance.api_key', 'key');
        config()->set('services.binance.api_secret', 'secret');
        config()->set('services.binance.pay_id', null);
        config()->set('services.binance.pay_uid', null);
        $setting = ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_verification_mode' => 'amount_unique',
            'is_active' => true,
        ]);

        $this->from('/admin?tab=stats')
            ->post(route('admin.payment-mode.update'), [
                'update_scope' => 'binance_verification',
                'payment_mode' => 'manual',
                'binance_verification_mode' => 'order_id',
            ])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'settings', 'section' => 'pembayaran']))
            ->assertSessionHasErrors(['binance_verification_mode'], null, 'paymentMode');
        $this->assertSame('amount_unique', $setting->fresh()->binance_verification_mode);
    }

    public function test_order_id_rollback_does_not_depend_on_qris_provider_readiness(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        $setting = ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_verification_mode' => 'order_id',
            'binance_order_id_compatibility_ready_at' => now(),
            'is_active' => true,
        ]);

        $this->post(route('admin.payment-mode.update'), [
            'update_scope' => 'binance_verification',
            'payment_mode' => 'manual',
            'binance_verification_mode' => 'amount_unique',
        ])->assertRedirect('/admin?tab=settings&section=pembayaran#admin-payment-settings');

        $setting->refresh();
        $this->assertSame('amount_unique', $setting->binance_verification_mode);
        $this->assertSame('manual', $setting->payment_mode);
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
            'update_scope' => 'binance_settings',
            'payment_mode' => 'manual',
            'qris_payment_ttl_minutes' => 7,
            'binance_payment_ttl_minutes' => 25,
        ])->assertRedirect('/admin?tab=settings&section=pembayaran#admin-payment-settings');

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

        $this->post(route('admin.support-settings.update'), [
            'whatsapp_support_number' => '0812-3456-7890',
            'whatsapp_support_url' => '',
        ])->assertRedirect('/admin?tab=settings&section=pembayaran#admin-support-settings');

        $this->assertDatabaseHas('manual_payment_settings', [
            'id' => $setting->id,
            'whatsapp_support_number' => '6281234567890',
            'whatsapp_support_url' => null,
        ]);
        $auditLog = AuditLog::query()->latest('id')->firstOrFail();
        $this->assertSame('support_settings.updated', $auditLog->action);
        $this->assertSame('6281234567890', $auditLog->new_values['whatsapp_support_number']);

    }

    public function test_payment_deadlines_must_be_positive_integers(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Admin,
        ]));

        $this->from('/admin?tab=stats')
            ->post(route('admin.payment-mode.update'), [
                'update_scope' => 'binance_settings',
                'payment_mode' => 'manual',
                'qris_payment_ttl_minutes' => 0,
                'binance_payment_ttl_minutes' => 'twenty',
            ])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'settings', 'section' => 'pembayaran']))
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
            ->assertRedirect(route('admin.dashboard', ['tab' => 'settings', 'section' => 'pembayaran']))
            ->assertSessionHasErrors(['whatsapp_support_url', 'whatsapp_support_number'], null, 'supportSettings');
    }

    public function test_payment_mode_toggle_creates_active_setting_when_none_exists(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
        ]));
        $this->assertSame(0, ManualPaymentSetting::query()->count());

        $this->post(route('admin.payment-mode.update'), [
            'payment_mode' => 'manual',
            'confirm_provider_switch' => '1',
        ])
            ->assertRedirect('/admin?tab=settings&section=pembayaran#admin-payment-settings');

        $this->assertDatabaseHas('manual_payment_settings', [
            'payment_mode' => 'manual',
            'is_active' => true,
        ]);
    }

    public function test_payment_mode_validation_uses_payment_mode_error_bag_and_stats_tab(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
        ]));
        $this->from('/admin?tab=stats')
            ->post(route('admin.payment-mode.update'), [
                'payment_mode' => 'unknown',
                'confirm_provider_switch' => '1',
            ])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'settings', 'section' => 'pembayaran']))
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
            ->assertForbidden();

        $this->post(route('admin.payment-mode.update'), ['payment_mode' => 'klikqris'])
            ->assertForbidden();

        $this->assertDatabaseHas('manual_payment_settings', [
            'payment_mode' => 'manual',
            'is_active' => true,
        ]);
    }
}
