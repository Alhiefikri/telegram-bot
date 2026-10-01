<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\SendTelegramReceipt;
use App\Models\ManualPaymentSetting;
use App\Models\Payment;
use App\Support\PaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\TestCase;

class KlikQrisOwnerAlertTest extends TestCase
{
    use RefreshDatabase;

    private bool $providerOutage = false;

    /** @var array<string, mixed> */
    private array $providerStatusOverrides = [];

    private int $telegramFailures = 0;

    private int $successfulTelegramSends = 0;

    protected function setUp(): void
    {
        parent::setUp();

        KlikQrisSandboxScenarios::configureReadySandbox();
        config()->set('services.telegram.token', 'bot-token');
        config()->set('services.telegram.api_base_url', 'https://api.telegram.org');
        ManualPaymentSetting::create(['admin_telegram_chat_id' => '999', 'is_active' => true]);
        Queue::fake([SendTelegramReceipt::class]);
        $this->travelTo('2026-07-28 07:05:00');
    }

    public function test_recovery_pending_alerts_only_after_the_retry_budget_is_exhausted(): void
    {
        config()->set('services.klikqris.recovery_alert_max_attempts', 3);
        $payment = $this->klikQrisPayment(['merchant_ref' => 'KLIK-BUDGET']);
        $this->fakeProvider($payment);
        $this->statusOutage();

        $this->artisan('klikqris:reconcile --chunk=100')->assertExitCode(1);
        $this->assertCount(0, $this->ownerAlerts());

        $this->artisan('klikqris:reconcile --chunk=100')->assertExitCode(1);
        $this->assertCount(0, $this->ownerAlerts());

        $this->artisan('klikqris:reconcile --chunk=100')->assertExitCode(1);

        $alerts = $this->ownerAlerts();
        $this->assertCount(1, $alerts);
        $text = (string) $alerts->first()[0]['text'];
        $this->assertStringContainsString('KlikQRIS butuh keputusan owner', $text);
        $this->assertStringContainsString($payment->order->invoice_number, $text);
        $this->assertStringContainsString('Percobaan pemulihan: 3x, batas 3x', $text);
    }

    public function test_repeated_recovery_pending_passes_are_deduplicated_but_a_new_transition_alerts_again(): void
    {
        config()->set('services.klikqris.recovery_alert_max_attempts', 1);
        $payment = $this->klikQrisPayment(['merchant_ref' => 'KLIK-DEDUP']);
        $this->fakeProvider($payment);
        $this->statusOutage();

        $this->artisan('klikqris:reconcile --chunk=100')->assertExitCode(1);
        $this->artisan('klikqris:reconcile --chunk=100')->assertExitCode(1);
        $this->artisan('klikqris:reconcile --chunk=100')->assertExitCode(1);

        $this->assertCount(1, $this->ownerAlerts());

        // A different actionable transition on the same Payment is newly actionable.
        $this->statusResponds([
            'status' => 'PENDING',
            'qris_url' => 'https://evil.example.test/qris.png',
        ]);

        $this->artisan('klikqris:reconcile --chunk=100')->assertExitCode(0);

        $alerts = $this->ownerAlerts();
        $this->assertCount(2, $alerts);
        $this->assertStringContainsString('tidak valid untuk dibayar', (string) $alerts->last()[0]['text']);
    }

    public function test_invalid_provider_result_alerts_immediately_without_spending_a_retry_budget(): void
    {
        config()->set('services.klikqris.recovery_alert_max_attempts', 3);
        $payment = $this->klikQrisPayment(['merchant_ref' => 'KLIK-INVALID']);
        $this->fakeProvider($payment);
        $this->statusResponds([
            'status' => 'PENDING',
            'qris_url' => 'https://evil.example.test/qris.png',
        ]);

        $this->artisan('klikqris:reconcile --chunk=100')->assertExitCode(0);

        $this->assertCount(1, $this->ownerAlerts());
    }

    public function test_normal_creation_and_recovery_generate_no_owner_telegram_noise(): void
    {
        $payment = $this->klikQrisPayment([
            'merchant_ref' => 'KLIK-QUIET',
            'provider_payment_method' => null,
            'provider_total_payment' => null,
            'provider_payload' => null,
            'provider_create_state' => null,
        ]);

        Http::fakeSequence()
            ->push(KlikQrisSandboxScenarios::createPayload($payment, [
                'total_amount' => 1016,
                'qris_url' => 'https://klikqris.com/storage/sandbox/qris_quiet.png',
            ]), 200)
            ->push(KlikQrisSandboxScenarios::statusPayload($payment, ['total_amount' => 1016]), 200);

        $this->artisan('klikqris:reconcile --chunk=100')->assertSuccessful();

        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertCount(0, $this->ownerAlerts());
    }

    public function test_recovering_after_an_alert_clears_the_budget_and_dedup_marker(): void
    {
        config()->set('services.klikqris.recovery_alert_max_attempts', 1);
        $payment = $this->klikQrisPayment(['merchant_ref' => 'KLIK-RECOVERED']);
        $this->fakeProvider($payment);
        $this->statusOutage();

        $this->artisan('klikqris:reconcile --chunk=100')->assertExitCode(1);
        $this->assertCount(1, $this->ownerAlerts());

        $this->statusResponds(['qris_url' => 'https://klikqris.com/storage/sandbox/qris_reconcile.png']);
        $this->artisan('klikqris:reconcile --chunk=100')->assertExitCode(0);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertCount(1, $this->ownerAlerts());
    }

    public function test_alert_excludes_secrets_provider_payloads_qr_media_and_customer_data(): void
    {
        config()->set('services.klikqris.recovery_alert_max_attempts', 1);
        config()->set('services.klikqris.sandbox_api_key', 'secret-api-key');
        $payment = $this->klikQrisPayment([
            'merchant_ref' => 'MERCHANT-SECRET-REF',
            'provider_create_initial_error' => 'create_http_422 secret-api-key opaque-test-signature',
        ]);
        $customer = $payment->order->customer;
        $this->fakeProvider($payment);
        $this->statusOutage();

        $this->artisan('klikqris:reconcile --chunk=100')->assertExitCode(1);

        $alerts = $this->ownerAlerts();
        $this->assertCount(1, $alerts);
        $text = (string) $alerts->first()[0]['text'];

        $this->assertStringNotContainsString('MERCHANT-SECRET-REF', $text);
        $this->assertStringNotContainsString('secret-api-key', $text);
        $this->assertStringNotContainsString('opaque-test-signature', $text);
        $this->assertStringNotContainsString('qris_reconcile.png', $text);
        $this->assertStringNotContainsString('klikqris.com', $text);
        $this->assertStringNotContainsString('data:image', $text);
        $this->assertStringNotContainsString('ConnectionException', $text);
        $this->assertStringNotContainsString('recovery_unconfirmed', $text);
        $this->assertStringContainsString('Endpoint status provider tidak tersedia', $text);

        $this->assertNotNull($customer);
        $this->assertStringNotContainsString((string) $customer->telegram_id, $text);
        if (filled($customer->first_name)) {
            $this->assertStringNotContainsString((string) $customer->first_name, $text);
        }
    }

    public function test_alert_never_switches_provider_or_creates_a_manual_fallback_payment(): void
    {
        config()->set('services.klikqris.recovery_alert_max_attempts', 1);
        $payment = $this->klikQrisPayment(['merchant_ref' => 'KLIK-NO-SWITCH']);
        $settings = ManualPaymentSetting::query()->firstOrFail();
        $this->fakeProvider($payment);
        $this->statusOutage();

        $this->artisan('klikqris:reconcile --chunk=100')->assertExitCode(1);

        $this->assertCount(1, $this->ownerAlerts());
        $this->assertSame(1, Payment::query()->where('order_id', $payment->order_id)->count());
        $this->assertSame(PaymentProvider::KLIKQRIS, $payment->fresh()->provider);
        $this->assertEquals($settings->only($settings->getFillable()), ManualPaymentSetting::query()->firstOrFail()->only($settings->getFillable()));
    }

    public function test_missing_admin_chat_configuration_never_breaks_reconciliation(): void
    {
        config()->set('services.klikqris.recovery_alert_max_attempts', 1);
        ManualPaymentSetting::query()->delete();
        config()->set('services.telegram.admin_telegram_chat_id', '');
        $payment = $this->klikQrisPayment(['merchant_ref' => 'KLIK-NO-ADMIN']);
        $this->fakeProvider($payment);
        $this->statusOutage();

        $this->artisan('klikqris:reconcile --chunk=100')->assertExitCode(1);

        $this->assertCount(0, $this->ownerAlerts());
    }

    public function test_failed_telegram_delivery_is_retryable_on_the_next_observation(): void
    {
        config()->set('services.klikqris.recovery_alert_max_attempts', 1);
        $payment = $this->klikQrisPayment(['merchant_ref' => 'KLIK-TELEGRAM-RETRY']);
        $this->telegramFailures = 1;
        $this->fakeProvider($payment);
        $this->statusOutage();

        $this->artisan('klikqris:reconcile --chunk=100')->assertExitCode(1);
        $this->assertSame(0, $this->successfulTelegramSends);

        $this->artisan('klikqris:reconcile --chunk=100')->assertExitCode(1);
        $this->assertSame(1, $this->successfulTelegramSends);
    }

    private function ownerAlerts(): Collection
    {
        return Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '999');
    }

    /**
     * Register one provider fake for the whole test. Http::fake merges stubs, so
     * later registrations cannot override an earlier throwing stub; the outage
     * and status payload are switched through mutable state instead.
     */
    private function fakeProvider(Payment $payment): void
    {
        Http::fake([
            'https://api.telegram.org/*' => function () {
                if ($this->telegramFailures > 0) {
                    $this->telegramFailures--;
                    throw new ConnectionException('telegram owner alert unavailable');
                }

                $this->successfulTelegramSends++;

                return Http::response(['ok' => true, 'result' => ['message_id' => 1]]);
            },
            'https://klikqris.com/*' => function () use ($payment) {
                if ($this->providerOutage) {
                    throw new ConnectionException('socket failed with secret-api-key and opaque-test-signature at https://klikqris.com/private');
                }

                return Http::response(KlikQrisSandboxScenarios::statusPayload($payment, $this->providerStatusOverrides), 200);
            },
        ]);
    }

    private function statusOutage(): void
    {
        $this->providerOutage = true;
    }

    /** @param array<string, mixed> $overrides */
    private function statusResponds(array $overrides = []): void
    {
        $this->providerOutage = false;
        $this->providerStatusOverrides = $overrides;
    }

    private function klikQrisPayment(array $attributes = []): Payment
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
