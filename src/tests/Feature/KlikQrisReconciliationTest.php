<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\SendTelegramReceipt;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\TestCase;

class KlikQrisReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.klikqris', KlikQrisSandboxScenarios::readyConfig());
        $this->travelTo('2026-07-28 07:05:00');
    }

    public function test_command_fulfills_authoritative_success_and_skips_pending_without_touching_other_providers_or_paid_payments(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        $success = $this->payableKlikQrisPayment(['merchant_ref' => 'KLIK-SUCCESS']);
        $pending = $this->payableKlikQrisPayment(['merchant_ref' => 'KLIK-PENDING']);
        $paid = $this->payableKlikQrisPayment(['merchant_ref' => 'KLIK-PAID', 'status' => PaymentStatus::Paid]);
        $pakasir = Payment::factory()->create(['provider' => 'pakasir', 'merchant_ref' => 'PAKASIR-REF', 'status' => PaymentStatus::Pending]);

        Http::fake(function ($request) use ($success, $pending) {
            $this->assertSame('GET', $request->method());
            $this->assertStringContainsString('/qris/status/', $request->url());
            $this->assertStringNotContainsString('KLIK-PAID', $request->url());
            $this->assertStringNotContainsString('PAKASIR-REF', $request->url());

            return str_contains($request->url(), 'KLIK-PENDING')
                ? Http::response(KlikQrisSandboxScenarios::statusPayload($pending, ['status' => 'PENDING']), 200)
                : Http::response(KlikQrisSandboxScenarios::statusPayload($success), 200);
        });

        $this->artisan('klikqris:reconcile --chunk=2')
            ->expectsOutputToContain('processed=1 skipped=1 failed=0')
            ->assertExitCode(0);

        $this->assertSame(PaymentStatus::Paid, $success->fresh()->status);
        $this->assertSame(OrderStatus::Processing, $success->order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Pending, $pending->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $paid->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $pakasir->fresh()->status);
        Http::assertSentCount(2);
        Queue::assertPushed(SendTelegramReceipt::class, 1);
    }

    public function test_command_can_process_locally_expired_klikqris_success_when_provider_evidence_matches(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        $payment = $this->payableKlikQrisPayment(['merchant_ref' => 'KLIK-EXPIRED']);
        $payment->order->update(['order_status' => OrderStatus::Expired, 'payment_status' => PaymentStatus::Expired]);
        $payment->update(['status' => PaymentStatus::Expired, 'expired_at' => now()->subMinute()]);
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::statusPayload($payment), 200));

        $this->artisan('klikqris:reconcile --chunk=100')
            ->expectsOutputToContain('processed=1 skipped=0 failed=0')
            ->assertExitCode(0);

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $payment->order->fresh()->payment_status);
    }

    public function test_command_continues_chunk_after_status_outage_and_sanitizes_output(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        config()->set('services.klikqris.sandbox_api_key', 'secret-api-key');
        $failed = $this->payableKlikQrisPayment(['merchant_ref' => 'MERCHANT-SECRET-REF']);
        $processed = $this->payableKlikQrisPayment(['merchant_ref' => 'KLIK-AFTER-FAIL']);

        Http::fake(function ($request) use ($processed) {
            if (str_contains($request->url(), 'MERCHANT-SECRET-REF')) {
                throw new ConnectionException('socket failed with secret-api-key and opaque-test-signature at https://klikqris.com/private');
            }

            return Http::response(KlikQrisSandboxScenarios::statusPayload($processed), 200);
        });

        $this->artisan('klikqris:reconcile --chunk=1')
            ->expectsOutputToContain('processed=1 skipped=0 failed=1')
            ->doesntExpectOutputToContain('MERCHANT-SECRET-REF')
            ->doesntExpectOutputToContain('secret-api-key')
            ->doesntExpectOutputToContain('opaque-test-signature')
            ->doesntExpectOutputToContain('https://klikqris.com/private')
            ->assertExitCode(1);

        $this->assertSame(PaymentStatus::Pending, $failed->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $processed->fresh()->status);
    }

    public function test_command_selects_outcome_unknown_but_never_calls_create_transaction(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        $payment = $this->payableKlikQrisPayment([
            'merchant_ref' => 'KLIK-UNKNOWN',
            'status' => PaymentStatus::Failed,
            'provider_create_state' => 'outcome_unknown',
        ]);
        Http::fake(function ($request) use ($payment) {
            $this->assertSame('GET', $request->method());
            $this->assertStringContainsString('/qris/status/KLIK-UNKNOWN', $request->url());
            $this->assertStringNotContainsString('/qris/create', $request->url());

            return Http::response(KlikQrisSandboxScenarios::statusPayload($payment, ['status' => 'PENDING']), 200);
        });

        $this->artisan('klikqris:reconcile --chunk=100')
            ->expectsOutputToContain('processed=0 skipped=1 failed=0')
            ->assertExitCode(0);

        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_command_recovers_true_outcome_unknown_success_from_status_without_create_transaction(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        $payment = $this->payableKlikQrisPayment([
            'merchant_ref' => 'KLIK-UNKNOWN-SUCCESS',
            'status' => PaymentStatus::Pending,
            'provider_payment_method' => null,
            'provider_total_payment' => null,
            'provider_payload' => null,
            'provider_transaction_token' => null,
            'provider_create_state' => 'outcome_unknown',
            'provider_create_last_error' => 'Illuminate\\Http\\Client\\ConnectionException',
        ]);

        Http::fake(function ($request) use ($payment) {
            $this->assertSame('GET', $request->method());
            $this->assertStringContainsString('/qris/status/KLIK-UNKNOWN-SUCCESS', $request->url());
            $this->assertStringNotContainsString('/qris/create', $request->url());

            return Http::response(KlikQrisSandboxScenarios::statusPayload($payment), 200);
        });

        $this->artisan('klikqris:reconcile --chunk=100')
            ->expectsOutputToContain('processed=1 skipped=0 failed=0')
            ->assertExitCode(0);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(OrderStatus::Processing, $payment->order->fresh()->order_status);
        $this->assertSame('qris', $payment->provider_payment_method);
        $this->assertSame(1016, $payment->provider_total_payment);
        $this->assertSame('SUCCESS', $payment->provider_payload['data']['status'] ?? null);
        $this->assertNull($payment->provider_create_last_error);
        Http::assertSentCount(1);
        Queue::assertPushed(SendTelegramReceipt::class, 1);
    }

    public function test_command_validates_bounded_chunk_option(): void
    {
        $this->artisan('klikqris:reconcile --chunk=0')->assertExitCode(1);
        $this->artisan('klikqris:reconcile --chunk=1001')->assertExitCode(1);
    }

    public function test_schedule_registers_one_minute_klikqris_reconcile_without_overlap(): void
    {
        Artisan::call('schedule:list');
        $output = Artisan::output();
        $schedule = file_get_contents(base_path('routes/console.php'));

        $this->assertSame(1, substr_count($output, 'klikqris:reconcile --chunk=100'));
        $this->assertSame(1, substr_count($schedule, "Schedule::command('klikqris:reconcile --chunk=100')->everyMinute()->withoutOverlapping();"));
    }

    private function payableKlikQrisPayment(array $attributes = []): Payment
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment(array_replace([
            'amount' => 1000,
            'provider_total_payment' => 1016,
            'provider_payload' => ['data' => [
                'order_id' => $attributes['merchant_ref'] ?? 'KLIK-REF',
                'amount' => 1000,
                'total_amount' => 1016,
                'status' => 'PENDING',
                'qris_url' => 'https://klikqris.com/storage/sandbox/qris_reconcile.png',
                'expired_at' => '2026-07-28T07:20:00+00:00',
            ]],
            'provider_transaction_token' => 'opaque-test-signature',
            'provider_create_state' => 'created',
        ], $attributes));

        $payment->order->update([
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => $payment->status,
            'expires_at' => now()->addMinutes(15),
        ]);

        return $payment->fresh('order');
    }
}
