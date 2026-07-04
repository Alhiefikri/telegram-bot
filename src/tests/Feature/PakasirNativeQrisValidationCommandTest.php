<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PakasirNativeQrisValidationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_native_staging_config_before_touching_payment_or_provider(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        config()->set('services.pakasir.checkout_flow', 'hosted');
        config()->set('services.pakasir.slug', 'staging-store');
        config()->set('services.pakasir.api_key', 'pakasir-secret');
        config()->set('services.pakasir.base_url', 'https://app.pakasir.test');

        $this->artisan('pakasir:validate-native-qris', ['payment' => 'missing-payment'])
            ->expectsOutputToContain('FAIL TELEGRAM_PAYMENT_MODE=pakasir - Configured value is manual.')
            ->expectsOutputToContain('FAIL PAKASIR_CHECKOUT_FLOW=api_qris - Configured value is hosted.')
            ->doesntExpectOutputToContain('pakasir-secret')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_validates_native_qris_payment_with_redacted_output_and_reconciliation(): void
    {
        Queue::fake();
        $this->configureNativePakasir();

        $payment = $this->pendingPakasirPayment(30103, 'INV-STAGE-QRIS-1');
        $paymentNumber = '00020101021226610016ID.CO.QRIS.STAGING92';

        Http::fake([
            'https://app.pakasir.test/api/transactioncreate/qris' => fn (Request $request) => Http::response([
                'payment' => [
                    'project' => 'staging-store',
                    'order_id' => $request['order_id'],
                    'amount' => $request['amount'],
                    'fee' => 103,
                    'total_payment' => $request['amount'] + 103,
                    'payment_method' => 'qris',
                    'payment_number' => $paymentNumber,
                    'expired_at' => '2026-07-04T16:30:00Z',
                ],
            ]),
            'https://app.pakasir.test/api/transactiondetail*' => Http::response([
                'transaction' => [
                    'project' => 'staging-store',
                    'order_id' => 'INV-STAGE-QRIS-1',
                    'amount' => 30103,
                    'fee' => 103,
                    'total_payment' => 30206,
                    'payment_method' => 'qris',
                    'payment_number' => $paymentNumber,
                    'expired_at' => '2026-07-04T16:30:00Z',
                    'status' => 'completed',
                ],
            ]),
        ]);

        $this->artisan('pakasir:validate-native-qris', [
            'payment' => $payment->id,
            '--create' => true,
            '--reconcile' => true,
        ])
            ->expectsOutputToContain('PASS native QRIS payment_number persisted as redacted non-URL string')
            ->expectsOutputToContain('PASS normal app flow avoids duplicate provider create - provider_create event delta=1.')
            ->expectsOutputToContain('PASS hosted fallback URL generated for same payment')
            ->expectsOutputToContain('PASS Transaction Detail amount equals original payment amount - amount=30103.')
            ->expectsOutputToContain('PASS reconciliation can mark completed native QRIS paid - payment_status=paid event_processed=true.')
            ->doesntExpectOutputToContain($paymentNumber)
            ->doesntExpectOutputToContain('pakasir-secret')
            ->doesntExpectOutputToContain('https://app.pakasir.test/pay')
            ->assertSuccessful();

        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(OrderStatus::Processing, $payment->order->fresh()->order_status);
        $this->assertSame($paymentNumber, $payment->provider_payment_number);
        $this->assertSame(hash('sha256', $paymentNumber), $payment->provider_payment_number_hash);
        $this->assertStringNotContainsString($paymentNumber, json_encode($payment->provider_payload, JSON_THROW_ON_ERROR));

        $this->assertSame(1, PaymentEvent::query()->where('event_type', PaymentEventType::ProviderCreate)->count());
        $this->assertSame(1, PaymentEvent::query()->where('event_type', PaymentEventType::Reconciliation)->whereNotNull('processed_at')->count());
        $this->assertStringNotContainsString(
            $paymentNumber,
            json_encode(PaymentEvent::query()->where('event_type', PaymentEventType::ProviderCreate)->firstOrFail()->raw_payload, JSON_THROW_ON_ERROR),
        );
        $reconciliationPayload = PaymentEvent::query()->where('event_type', PaymentEventType::Reconciliation)->firstOrFail()->raw_payload;
        $this->assertStringNotContainsString($paymentNumber, json_encode($reconciliationPayload, JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('payment_number', $reconciliationPayload['transaction']);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/transactioncreate/qris')
            && $request['project'] === 'staging-store'
            && $request['order_id'] === 'INV-STAGE-QRIS-1'
            && $request['amount'] === 30103
            && $request['api_key'] === 'pakasir-secret');
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://app.pakasir.test/api/transactiondetail'));
    }

    public function test_duplicate_provider_create_probe_records_redacted_staging_observation(): void
    {
        $this->configureNativePakasir();

        $payment = $this->pendingPakasirPayment(15000, 'INV-STAGE-DUPLICATE-1');
        $paymentNumbers = [
            '00020101021226610016ID.CO.QRIS.FIRST92',
            '00020101021226610016ID.CO.QRIS.SECOND92',
        ];
        $createCalls = 0;

        Http::fake([
            'https://app.pakasir.test/api/transactioncreate/qris' => function (Request $request) use (&$createCalls, $paymentNumbers) {
                $paymentNumber = $paymentNumbers[$createCalls] ?? $paymentNumbers[1];
                $createCalls++;

                return Http::response([
                    'payment' => [
                        'project' => 'staging-store',
                        'order_id' => $request['order_id'],
                        'amount' => $request['amount'],
                        'fee' => 100,
                        'total_payment' => $request['amount'] + 100,
                        'payment_method' => 'qris',
                        'payment_number' => $paymentNumber,
                        'expired_at' => '2026-07-04T16:30:00Z',
                    ],
                ]);
            },
            'https://app.pakasir.test/api/transactiondetail*' => Http::response([
                'transaction' => [
                    'project' => 'staging-store',
                    'order_id' => 'INV-STAGE-DUPLICATE-1',
                    'amount' => 15000,
                    'fee' => 100,
                    'total_payment' => 15100,
                    'payment_method' => 'qris',
                    'payment_number' => $paymentNumbers[0],
                    'expired_at' => '2026-07-04T16:30:00Z',
                    'status' => 'pending',
                ],
            ]),
        ]);

        $this->artisan('pakasir:validate-native-qris', [
            'payment' => $payment->merchant_ref,
            '--create' => true,
            '--duplicate-provider-create' => true,
        ])
            ->expectsOutputToContain('MANUAL duplicate transactioncreate/qris behavior observed - Provider accepted duplicate create; same_payment_number=false same_total_payment=true')
            ->doesntExpectOutputToContain($paymentNumbers[0])
            ->doesntExpectOutputToContain($paymentNumbers[1])
            ->assertSuccessful();

        $this->assertSame(1, PaymentEvent::query()->where('event_type', PaymentEventType::ProviderCreate)->count());
        Http::assertSentCount(3);
    }

    private function configureNativePakasir(): void
    {
        config()->set('services.telegram.payment_mode', 'pakasir');
        config()->set('services.pakasir.checkout_flow', 'api_qris');
        config()->set('services.pakasir.slug', 'staging-store');
        config()->set('services.pakasir.api_key', 'pakasir-secret');
        config()->set('services.pakasir.base_url', 'https://app.pakasir.test');
        config()->set('services.pakasir.redirect_url', null);
        config()->set('services.pakasir.qris_only', false);
    }

    private function pendingPakasirPayment(int $amount, string $merchantRef): Payment
    {
        $order = Order::factory()->create([
            'invoice_number' => $merchantRef,
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'total_amount' => $amount,
        ]);

        return Payment::factory()->for($order)->create([
            'provider' => 'pakasir',
            'merchant_ref' => $merchantRef,
            'amount' => $amount,
            'status' => PaymentStatus::Pending,
        ]);
    }
}
