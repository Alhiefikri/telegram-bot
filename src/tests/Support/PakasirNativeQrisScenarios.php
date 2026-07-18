<?php

namespace Tests\Support;

use App\Models\ManualPaymentSetting;
use App\Models\Payment;
use App\Services\Telegram\TelegramQrCodeRenderer;
use App\Support\PakasirCheckoutFlow;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

trait PakasirNativeQrisScenarios
{
    protected function pakasirConfigIsReady(): void
    {
        config()->set('services.pakasir.slug', 'lhie-test');
        config()->set('services.pakasir.api_key', 'secret-key');
        config()->set('services.pakasir.base_url', 'https://app.pakasir.test');
        config()->set('services.pakasir.redirect_url', 'https://lhie.test/shop/pakasir-return');
    }

    protected function pakasirNativeQrisIsReady(): void
    {
        $this->pakasirConfigIsReady();
        ManualPaymentSetting::create([
            'payment_mode' => 'pakasir',
            'pakasir_checkout_flow' => PakasirCheckoutFlow::API_QRIS,
            'is_active' => true,
        ]);
    }

    protected function pakasirPaymentSettingsStartEmpty(): void
    {
        ManualPaymentSetting::query()->delete();
    }

    protected function pakasirNativeQrisHasMissingCredentials(): void
    {
        config()->set('services.pakasir.slug', null);
        config()->set('services.pakasir.api_key', null);
        config()->set('services.pakasir.base_url', 'https://app.pakasir.test');
        ManualPaymentSetting::create([
            'payment_mode' => 'pakasir',
            'pakasir_checkout_flow' => PakasirCheckoutFlow::API_QRIS,
            'is_active' => true,
        ]);
    }

    protected function manualPaymentModeIsActive(): void
    {
        $this->pakasirConfigIsReady();
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'pakasir_checkout_flow' => PakasirCheckoutFlow::API_QRIS,
            'is_active' => true,
        ]);
    }

    protected function pakasirHostedCheckoutIsActive(): void
    {
        $this->pakasirConfigIsReady();
        ManualPaymentSetting::create([
            'payment_mode' => 'pakasir',
            'pakasir_checkout_flow' => PakasirCheckoutFlow::HOSTED,
            'is_active' => true,
        ]);
    }

    protected function qrisRendererReturnsDeterministicPng(): void
    {
        $this->app->instance(TelegramQrCodeRenderer::class, new class extends TelegramQrCodeRenderer
        {
            public function png(string $content): string
            {
                return 'png-for:'.$content;
            }
        });
    }

    protected function providerQrisCreateSucceedsWithProviderTotal(int $amount, string $paymentNumber, int $fee = 103): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://app.pakasir.test/api/transactioncreate/qris' => function (HttpRequest $request) use ($amount, $paymentNumber, $fee) {
                $this->assertSame('lhie-test', $request['project']);
                $this->assertSame($amount, $request['amount']);
                $this->assertSame('secret-key', $request['api_key']);

                return Http::response([
                    'payment' => [
                        'project' => 'lhie-test',
                        'order_id' => $request['order_id'],
                        'amount' => $amount,
                        'fee' => $fee,
                        'total_payment' => $amount + $fee,
                        'payment_method' => 'qris',
                        'payment_number' => $paymentNumber,
                        'expired_at' => now()->addMinutes(15)->utc()->toAtomString(),
                    ],
                ], 200);
            },
        ]);
    }

    protected function providerQrisCreateFails(array $payload = ['message' => 'upstream unavailable'], int $status = 500): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://app.pakasir.test/api/transactioncreate/qris' => Http::response($payload, $status),
        ]);
    }

    protected function providerTransactionDetailIsPending(Payment $payment, ?int $amount = null): void
    {
        $amount ??= $payment->amount;

        Http::preventStrayRequests();
        Http::fake([
            'https://app.pakasir.test/api/transactiondetail*' => Http::response([
                'transaction' => [
                    'project' => 'lhie-test',
                    'order_id' => $payment->merchant_ref,
                    'amount' => $amount,
                    'payment_method' => 'qris',
                    'status' => 'pending',
                ],
            ], 200),
        ]);
    }

    protected function providerTransactionDetailCompletesPayment(Payment $payment, ?int $amount = null): void
    {
        $amount ??= $payment->amount;

        Http::preventStrayRequests();
        Http::fake([
            'https://app.pakasir.test/api/transactiondetail*' => Http::response([
                'transaction' => [
                    'project' => 'lhie-test',
                    'order_id' => $payment->merchant_ref,
                    'amount' => $amount,
                    'payment_method' => 'qris',
                    'status' => 'completed',
                ],
            ], 200),
        ]);
    }

    protected function providerTransactionDetailFails(array $payload = ['message' => 'upstream unavailable'], int $status = 500): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://app.pakasir.test/api/transactiondetail*' => Http::response($payload, $status),
        ]);
    }
}
