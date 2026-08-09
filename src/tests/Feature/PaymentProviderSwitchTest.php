<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Exceptions\InvalidCheckout;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\Telegram\TelegramCheckoutPaymentIntentSelector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\TestCase;

class PaymentProviderSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_switch_to_klikqris_after_explicit_confirmation_and_audit_the_provider_change(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $setting = ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'is_active' => true,
        ]);
        KlikQrisSandboxScenarios::configureReadySandbox();

        $this->actingAs($owner)
            ->post(route('admin.payment-mode.update'), [
                'update_scope' => 'provider_switch',
                'payment_mode' => 'klikqris',
                'confirm_provider_switch' => '1',
            ])
            ->assertRedirect('/admin?tab=stats#admin-payment-settings');

        $this->assertDatabaseHas('manual_payment_settings', [
            'id' => $setting->id,
            'payment_mode' => 'klikqris',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment_provider.switched',
            'user_id' => $owner->id,
            'subject_type' => ManualPaymentSetting::class,
            'subject_id' => (string) $setting->id,
        ]);

        $audit = AuditLog::query()->where('action', 'payment_provider.switched')->latest('id')->firstOrFail();
        $this->assertSame('manual', $audit->old_values['provider']);
        $this->assertSame('klikqris', $audit->new_values['provider']);
    }

    public function test_provider_switch_requires_explicit_confirmation(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'is_active' => true]);
        KlikQrisSandboxScenarios::configureReadySandbox();

        $this->from('/admin?tab=stats')
            ->actingAs($owner)
            ->post(route('admin.payment-mode.update'), [
                'update_scope' => 'provider_switch',
                'payment_mode' => 'klikqris',
            ])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'stats']))
            ->assertSessionHasErrors(['confirm_provider_switch'], null, 'paymentMode');

        $this->assertDatabaseHas('manual_payment_settings', [
            'payment_mode' => 'manual',
            'is_active' => true,
        ]);
    }

    public function test_readiness_failure_rejects_klikqris_switch_and_preserves_old_provider(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        ManualPaymentSetting::create(['payment_mode' => 'pakasir', 'is_active' => true]);
        KlikQrisSandboxScenarios::configureReadySandbox();
        config()->set('services.klikqris.sandbox_enabled', false);

        $this->from('/admin?tab=stats')
            ->actingAs($owner)
            ->post(route('admin.payment-mode.update'), [
                'update_scope' => 'provider_switch',
                'payment_mode' => 'klikqris',
                'confirm_provider_switch' => '1',
            ])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'stats']))
            ->assertSessionHasErrors(['payment_mode'], null, 'paymentMode');

        $this->assertDatabaseHas('manual_payment_settings', [
            'payment_mode' => 'pakasir',
            'is_active' => true,
        ]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'payment_provider.switched']);
    }

    public function test_non_owner_can_view_readiness_but_cannot_mutate_provider_switch(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'is_active' => true]);
        KlikQrisSandboxScenarios::configureReadySandbox();

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('KlikQRIS')
            ->assertSee('Hanya owner yang bisa mengganti provider aktif.');

        $this->actingAs($admin)
            ->post(route('admin.payment-mode.update'), [
                'update_scope' => 'provider_switch',
                'payment_mode' => 'klikqris',
                'confirm_provider_switch' => '1',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('manual_payment_settings', [
            'payment_mode' => 'manual',
            'is_active' => true,
        ]);
    }

    public function test_checkout_snapshot_keeps_existing_payment_on_original_provider_after_switch(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'is_active' => true]);
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0]);
        $customer = Customer::factory()->create();
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->setItemQuantity($cart, $product, 1);

        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::manualQris());
        $payment = $order->payments()->firstOrFail();
        $this->assertSame('manual', $payment->provider);
        $this->assertSame(PaymentStatus::Pending, $payment->status);

        KlikQrisSandboxScenarios::configureReadySandbox();
        $this->actingAs($owner)
            ->post(route('admin.payment-mode.update'), [
                'update_scope' => 'provider_switch',
                'payment_mode' => 'klikqris',
                'confirm_provider_switch' => '1',
            ])
            ->assertRedirect('/admin?tab=stats#admin-payment-settings');

        $this->assertSame('manual', $payment->fresh()->provider);
    }

    public function test_telegram_reads_the_same_active_provider_switch_for_new_checkout_intents(): void
    {
        ManualPaymentSetting::create(['payment_mode' => 'klikqris', 'is_active' => true]);
        KlikQrisSandboxScenarios::configureReadySandbox();

        $selection = app(TelegramCheckoutPaymentIntentSelector::class)->select(false);

        $this->assertSame('klikqris', $selection->requestedPaymentMode);
        $this->assertSame('klikqris', $selection->paymentMode);
        $this->assertSame('klikqris', $selection->paymentIntent->provider());
    }

    public function test_checkout_rechecks_a_provider_selection_when_the_switch_changes_before_creation(): void
    {
        $setting = ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'payment_provider_version' => 0,
            'is_active' => true,
        ]);
        $selection = app(TelegramCheckoutPaymentIntentSelector::class)->select(false);

        KlikQrisSandboxScenarios::configureReadySandbox();
        $setting->forceFill([
            'payment_mode' => 'klikqris',
            'payment_provider_version' => 1,
        ])->save();

        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 0]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->setItemQuantity($cart, $product, 1);

        $order = app(OrderCheckoutService::class)->checkout($cart, $selection->paymentIntent);
        $payment = $order->payments()->sole();

        $this->assertSame('klikqris', $payment->provider);
        $this->assertSame('QRIS', $payment->method);
        $this->assertSame(1, $order->payments()->count());
    }

    public function test_klikqris_readiness_loss_fails_closed_without_falling_back_or_creating_a_payment(): void
    {
        KlikQrisSandboxScenarios::configureReadySandbox();
        ManualPaymentSetting::create(['payment_mode' => 'klikqris', 'is_active' => true]);
        $selection = app(TelegramCheckoutPaymentIntentSelector::class)->select(false);

        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->setItemQuantity($cart, $product, 1);

        config()->set('services.klikqris.sandbox_enabled', false);

        $this->expectException(InvalidCheckout::class);
        try {
            app(OrderCheckoutService::class)->checkout($cart, $selection->paymentIntent);
        } finally {
            $this->assertSame(0, Order::query()->count());
            $this->assertSame(0, Payment::query()->count());
            $this->assertSame(1, $product->fresh()->stock);
            $this->assertSame(0, $product->fresh()->reserved_stock);
        }
    }

    public function test_stale_provider_switch_version_does_not_overwrite_a_newer_switch_or_create_a_second_audit(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $setting = ManualPaymentSetting::create(['payment_mode' => 'manual', 'is_active' => true]);
        KlikQrisSandboxScenarios::configureReadySandbox();

        $this->actingAs($owner)
            ->post(route('admin.payment-mode.update'), [
                'payment_mode' => 'klikqris',
                'payment_provider_version' => 0,
                'confirm_provider_switch' => '1',
            ])
            ->assertRedirect('/admin?tab=stats#admin-payment-settings');

        config()->set('services.pakasir.slug', 'sedulur-digital');
        config()->set('services.pakasir.api_key', 'pakasir-key');
        config()->set('services.pakasir.base_url', 'https://app.pakasir.test');

        $this->from('/admin?tab=stats')
            ->actingAs($owner)
            ->post(route('admin.payment-mode.update'), [
                'payment_mode' => 'pakasir',
                'pakasir_checkout_flow' => 'hosted',
                'payment_provider_version' => 0,
                'confirm_provider_switch' => '1',
            ])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'stats']))
            ->assertSessionHasErrors(['payment_mode'], null, 'paymentMode');

        $this->assertSame('klikqris', $setting->fresh()->payment_mode);
        $this->assertSame(1, $setting->fresh()->payment_provider_version);
        $this->assertSame(1, AuditLog::query()->where('action', 'payment_provider.switched')->count());
    }
}
