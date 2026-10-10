<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\InvalidCheckout;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntent;
use App\Services\Checkout\CheckoutPostCreationBehavior;
use App\Services\Checkout\PaymentProviderResolver;
use App\Services\OrderCheckoutService;
use App\Services\Telegram\TelegramCheckoutPaymentIntentSelector;
use App\Support\PaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\TestCase;

class PaymentProviderSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_klikqris_switch_is_rejected_even_with_configured_credentials(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $setting = ManualPaymentSetting::create(['payment_mode' => 'manual', 'is_active' => true]);
        KlikQrisSandboxScenarios::configureReadySandbox();

        $this->actingAs($owner)->post(route('admin.payment-mode.update'), [
            'update_scope' => 'provider_switch',
            'payment_mode' => 'klikqris',
            'confirm_provider_switch' => '1',
        ])->assertSessionHasErrors(['payment_mode'], null, 'paymentMode');

        $this->assertSame('manual', $setting->fresh()->payment_mode);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'payment_provider.switched']);
    }

    public function test_pakasir_is_not_available_for_new_provider_switches(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'is_active' => true]);
        KlikQrisSandboxScenarios::configureReadySandbox();

        $this->actingAs($owner)
            ->post(route('admin.payment-mode.update'), [
                'update_scope' => 'provider_switch',
                'payment_mode' => 'pakasir',
                'confirm_provider_switch' => '1',
            ])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'settings', 'section' => 'pembayaran']))
            ->assertSessionHasErrors([
                'payment_mode' => 'Provider pembayaran tidak valid.',
            ], null, 'paymentMode');

    }

    public function test_pakasir_setting_is_rejected_by_new_checkout_selector(): void
    {
        ManualPaymentSetting::create(['payment_mode' => 'pakasir', 'is_active' => true]);

        $this->expectException(InvalidCheckout::class);

        app(TelegramCheckoutPaymentIntentSelector::class)->select(false);
    }

    public function test_pakasir_setting_fails_closed_for_qris_without_affecting_binance_rail_identity(): void
    {
        $setting = ManualPaymentSetting::create(['payment_mode' => 'pakasir', 'is_active' => true]);
        $resolver = app(PaymentProviderResolver::class);

        $binance = $resolver->resolve('binance_pay_id', $setting);
        $this->assertSame('pakasir', $binance->requestedProvider);
        $this->assertSame('manual', $binance->provider);
        $this->assertSame('BINANCE_PAY_ID', $binance->paymentIntent->method());

        $this->expectException(InvalidCheckout::class);
        $resolver->resolve('qris', $setting);
    }

    public function test_default_checkout_resolution_rejects_stale_pakasir_setting_instead_of_using_manual(): void
    {
        ManualPaymentSetting::create(['payment_mode' => 'pakasir', 'is_active' => true]);
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0]);
        $customer = Customer::factory()->create();
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->setItemQuantity($cart, $product, 1);

        $this->expectException(InvalidCheckout::class);

        try {
            app(OrderCheckoutService::class)->checkout($cart);
        } finally {
            $this->assertSame(0, Order::query()->count());
            $this->assertSame(0, Payment::query()->count());
        }
    }

    public function test_direct_pakasir_checkout_intent_is_rejected_without_creating_an_order(): void
    {
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0]);
        $customer = Customer::factory()->create();
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->setItemQuantity($cart, $product, 1);
        $intent = new class implements CheckoutPaymentIntent
        {
            public function provider(): string
            {
                return PaymentProvider::PAKASIR;
            }

            public function method(): string
            {
                return 'hosted';
            }

            public function postCreationBehavior(): CheckoutPostCreationBehavior
            {
                return CheckoutPostCreationBehavior::ManualQrisInstructions;
            }
        };

        $this->expectException(InvalidCheckout::class);

        try {
            app(OrderCheckoutService::class)->checkout($cart, $intent);
        } finally {
            $this->assertSame(0, Order::query()->count());
            $this->assertSame(0, Payment::query()->count());
        }
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
            ->assertRedirect(route('admin.dashboard', ['tab' => 'settings', 'section' => 'pembayaran']))
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
            ->assertRedirect(route('admin.dashboard', ['tab' => 'settings', 'section' => 'pembayaran']))
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

    public function test_stale_provider_switch_version_does_not_overwrite_the_current_provider(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $setting = ManualPaymentSetting::create(['payment_mode' => 'klikqris', 'is_active' => true, 'payment_provider_version' => 1]);

        $this->actingAs($owner)->post(route('admin.payment-mode.update'), [
            'payment_mode' => 'manual',
            'payment_provider_version' => 0,
            'confirm_provider_switch' => '1',
        ])->assertSessionHasErrors(['payment_mode'], null, 'paymentMode');

        $this->assertSame('klikqris', $setting->fresh()->payment_mode);
        $this->assertSame(1, $setting->fresh()->payment_provider_version);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'payment_provider.switched']);
    }
}
