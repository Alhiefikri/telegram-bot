<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Admin\AdminDashboardData;
use App\Services\Sakurupiah\SakurupiahCreateInvoice;
use App\Services\Sakurupiah\SakurupiahInvoiceLifecycleService;
use App\Support\PaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SakurupiahOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.sakurupiah', array_replace(config('services.sakurupiah', []), [
            'sandbox_enabled' => false,
            'sandbox_base_url' => 'https://sakurupiah.id/api-sanbox/',
            'sandbox_api_id' => 'api-test',
            'sandbox_api_key' => 'secret-key',
            'sandbox_callback_url' => 'https://store.example.test/api/webhook/sakurupiah',
            'sandbox_return_url' => 'https://store.example.test/shop/orders',
            'sandbox_allowed_hosts' => ['sakurupiah.id'],
            'sandbox_callback_allowed_hosts' => ['store.example.test'],
            'sandbox_return_allowed_hosts' => ['store.example.test'],
            'qris_contract' => [
                'currency' => 'IDR',
                'currency_confirmed' => true,
                'fee_payer' => 'merchant',
                'merchant_fee' => 1,
                'fee_confirmed' => true,
                'expiry_hours' => 24,
                'expiry_confirmed' => true,
            ],
            'reconcile_terminal_lookback_hours' => 72,
            'reconcile_max_execution_seconds' => 45,
        ]));
    }

    public function test_readiness_command_treats_disabled_environment_as_safe_success(): void
    {
        $code = Artisan::call('sakurupiah:check-readiness', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('"enabled":false', $output, $output);
        $this->assertStringContainsString('"ready":false', $output, $output);
    }

    public function test_readiness_command_fails_when_enabled_but_not_ready(): void
    {
        config()->set('services.sakurupiah.sandbox_enabled', true);
        config()->set('services.sakurupiah.sandbox_api_key', null);

        $code = Artisan::call('sakurupiah:check-readiness', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('"ready":false', $output, $output);
    }

    public function test_readiness_command_reports_bounded_backlog_for_pending_verification_payments(): void
    {
        config()->set('services.sakurupiah.sandbox_enabled', true);
        config()->set('services.sakurupiah.sandbox_api_id', 'api-test');
        config()->set('services.sakurupiah.sandbox_api_key', 'secret-key');

        $this->verificationPendingPayment('SAKU-BACKLOG-1');
        $this->verificationPendingPayment('SAKU-BACKLOG-2');

        $code = Artisan::call('sakurupiah:check-readiness', ['--json' => true]);
        $output = Artisan::output();
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('"verification_pending":2', $output, $output);
        $this->assertStringContainsString('"pending":2', $output, $output);
    }

    public function test_ops_health_sakurupiah_reports_bounded_verification_pending_backlog(): void
    {
        config()->set('services.sakurupiah.sandbox_enabled', true);
        config()->set('services.sakurupiah.sandbox_api_id', 'api-test');
        config()->set('services.sakurupiah.sandbox_api_key', 'secret-key');

        $this->verificationPendingPayment('SAKU-BACKLOG-1');
        $this->verificationPendingPayment('SAKU-BACKLOG-2');

        $code = Artisan::call('ops:health', ['--sakurupiah' => true]);
        $output = Artisan::output();

        // ops:health --sakurupiah is an observability surface: enabled but
        // not ready fails, while the bounded backlog is always reported.
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Sakurupiah backlog: pending=2 verification_pending=2', $output, $output);
    }

    public function test_dashboard_stats_expose_sakurupiah_backlog_without_provider_requests(): void
    {
        config()->set('services.sakurupiah.sandbox_enabled', true);
        config()->set('services.sakurupiah.sandbox_api_id', 'api-test');
        config()->set('services.sakurupiah.sandbox_api_key', 'secret-key');

        $this->verificationPendingPayment('SAKU-DASH-1');

        $data = app(AdminDashboardData::class)->forAdmin(
            User::factory()->create(['role' => UserRole::Owner]),
        );

        $this->assertSame(1, $data['statsTab']['paymentControl']['sakurupiah_backlog']['pending']);
        $this->assertSame(1, $data['statsTab']['paymentControl']['sakurupiah_backlog']['verification_pending']);
        // The metrics surface is local-only and must not trigger provider HTTP.
        $this->assertSame(0, Http::recorded()->count());
    }

    private function verificationPendingPayment(string $merchantRef): void
    {
        $order = Order::factory()->create([
            'total_amount' => 12000,
            'currency' => 'IDR',
            'expires_at' => now()->addHours(2),
        ]);

        Payment::factory()->for($order, 'order')->create([
            'provider' => PaymentProvider::SAKURUPIAH,
            'merchant_ref' => $merchantRef,
            'provider_reference' => 'trx-'.$merchantRef,
            'provider_payment_method' => SakurupiahCreateInvoice::METHOD_QRISC,
            'provider_total_payment' => 12084,
            'provider_fee' => 84,
            'provider_create_state' => SakurupiahInvoiceLifecycleService::STATE_RECOVERY_UNCONFIRMED,
            'method' => SakurupiahCreateInvoice::PAYMENT_METHOD_QRIS,
            'amount' => 12000,
            'currency' => 'IDR',
            'status' => PaymentStatus::Pending,
            'expired_at' => now()->addHours(2),
        ]);
    }
}
