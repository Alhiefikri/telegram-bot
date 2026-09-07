<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Support\PaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SakurupiahProviderSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_select_ready_sakurupiah_for_new_checkout_only(): void
    {
        $this->configureReadySakurupiah();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $setting = ManualPaymentSetting::create([
            'payment_mode' => PaymentProvider::MANUAL,
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->post(route('admin.payment-mode.update'), [
                'update_scope' => 'provider_switch',
                'payment_mode' => PaymentProvider::SAKURUPIAH,
                'confirm_provider_switch' => '1',
            ])
            ->assertRedirect('/admin?tab=stats#admin-payment-settings')
            ->assertSessionDoesntHaveErrors()
            ->assertSessionHas('status', 'Sakurupiah diaktifkan. Checkout baru memakai Sakurupiah; payment lama tetap memakai provider asal.');

        $this->assertDatabaseHas('manual_payment_settings', [
            'id' => $setting->id,
            'payment_mode' => PaymentProvider::SAKURUPIAH,
            'is_active' => true,
            'payment_provider_version' => 1,
        ]);
        $audit = AuditLog::query()->where('action', 'payment_provider.switched')->sole();
        $this->assertSame(PaymentProvider::MANUAL, $audit->old_values['provider']);
        $this->assertSame(PaymentProvider::SAKURUPIAH, $audit->new_values['provider']);
        Http::assertSentCount(1);
    }

    public function test_switching_away_from_sakurupiah_keeps_existing_payment_provenance(): void
    {
        $this->configureReadySakurupiah();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $setting = ManualPaymentSetting::create([
            'payment_mode' => PaymentProvider::SAKURUPIAH,
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create();
        $order = Order::factory()->for($customer, 'customer')->create([
            'total_amount' => 12_000,
            'currency' => 'IDR',
        ]);
        $payment = Payment::factory()->for($order, 'order')->create([
            'provider' => PaymentProvider::SAKURUPIAH,
            'method' => 'QRIS',
            'currency' => 'IDR',
            'amount' => 12_000,
        ]);

        $this->actingAs($owner)
            ->post(route('admin.payment-mode.update'), [
                'update_scope' => 'provider_switch',
                'payment_mode' => PaymentProvider::MANUAL,
                'confirm_provider_switch' => '1',
            ])
            ->assertRedirect('/admin?tab=stats#admin-payment-settings')
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(PaymentProvider::MANUAL, $setting->fresh()->payment_mode);
        $this->assertSame(PaymentProvider::SAKURUPIAH, $payment->fresh()->provider);
        $this->assertSame('QRIS', $payment->fresh()->method);
        $this->assertSame('IDR', $payment->fresh()->currency);
    }

    public function test_unready_sakurupiah_selection_fails_closed_and_preserves_the_current_provider(): void
    {
        config()->set('services.sakurupiah', array_replace(config('services.sakurupiah', []), [
            'sandbox_enabled' => false,
            'sandbox_api_id' => 'sandbox-id',
            'sandbox_api_key' => 'sandbox-key',
        ]));
        Http::fake();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $setting = ManualPaymentSetting::create([
            'payment_mode' => PaymentProvider::MANUAL,
            'is_active' => true,
        ]);

        $this->from('/admin?tab=stats')
            ->actingAs($owner)
            ->post(route('admin.payment-mode.update'), [
                'update_scope' => 'provider_switch',
                'payment_mode' => PaymentProvider::SAKURUPIAH,
                'confirm_provider_switch' => '1',
            ])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'stats']))
            ->assertSessionHasErrors(['payment_mode'], null, 'paymentMode');

        $this->assertSame(PaymentProvider::MANUAL, $setting->fresh()->payment_mode);
        $this->assertSame(0, AuditLog::query()->where('action', 'payment_provider.switched')->count());
        Http::assertNothingSent();
    }

    private function configureReadySakurupiah(): void
    {
        config()->set('services.sakurupiah', array_replace(config('services.sakurupiah', []), [
            'sandbox_enabled' => true,
            'sandbox_base_url' => 'https://sakurupiah.id/api-sanbox/',
            'sandbox_api_id' => 'sandbox-id',
            'sandbox_api_key' => 'sandbox-key',
            'qris_contract' => [
                'currency' => 'IDR',
                'currency_confirmed' => true,
                'fee_payer' => 'merchant',
                'merchant_fee' => 1,
                'fee_confirmed' => true,
                'expiry_hours' => 24,
                'expiry_confirmed' => true,
            ],
        ]));
        Http::fake(fn () => Http::response([
            'status' => '200',
            'data' => [[
                'kode' => 'QRISC',
                'nama' => 'QRISC Custom Merchant',
                'minimal' => 200,
                'maksimal' => 20_000_000,
                'tipe' => 'DIRECT',
                'status' => 'Aktif',
                'addition' => ['default_expired' => 24],
            ]],
        ], 200));
    }
}
