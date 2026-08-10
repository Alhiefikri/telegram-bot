<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Services\KlikQris\KlikQrisNativeQrisLifecycleResult;
use App\Services\KlikQris\KlikQrisNativeQrisOutcome;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\Telegram\TelegramCheckoutIntents;
use App\Storefront\StorefrontShopOrderPresenter;
use App\Storefront\StorefrontShopSignedInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\Support\TelegramBotTestSupport;

class KlikQrisCustomerSurfacesIntegrationTest extends TelegramBotTestSupport
{
    private const PNG_BYTES = "\x89PNG\r\n\x1A\n".'customer-surface-qris';

    public function test_storefront_and_store_bot_render_the_same_payable_qris_outcome_payload(): void
    {
        KlikQrisSandboxScenarios::configureReadySandbox();
        $this->travelTo('2026-07-28 10:10:00 UTC');

        $orderDeadline = CarbonImmutable::parse('2026-07-28 17:20:00', 'Asia/Jakarta');
        $providerDeadline = CarbonImmutable::parse('2026-07-28 17:25:00', 'Asia/Jakarta');
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'en']);
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-SURFACES',
            'total_amount' => 1000,
            'expires_at' => $orderDeadline,
        ]);
        $payment = Payment::factory()->for($order)->create([
            'provider' => 'klikqris',
            'provider_payment_method' => 'qris',
            'merchant_ref' => 'INV-SURFACES',
            'amount' => 1000,
            'provider_fee' => 16,
            'provider_total_payment' => 1016,
            'provider_expires_at' => $providerDeadline,
            'provider_create_state' => 'created',
            'provider_payload' => ['data' => [
                'order_id' => 'INV-SURFACES',
                'amount' => 1000,
                'total_amount' => '1016.00',
                'status' => 'PENDING',
                'qris_url' => 'https://klikqris.com/storage/sandbox/qris_surface.png',
                'expired_at' => $providerDeadline->utc()->toAtomString(),
            ]],
        ]);

        $invoice = app(StorefrontShopSignedInvoice::class)->read($order);

        $this->assertTrue($invoice->isQrisPayable);
        $this->assertSame('https://klikqris.com/storage/sandbox/qris_surface.png', $invoice->qrisImageSource);
        $this->assertSame(1016, $invoice->payableAmount);
        $this->assertSame('IDR 1016.00', $invoice->payableAmountDisplay);
        $this->assertNotNull($invoice->qrisEffectiveExpiresAt);
        $this->assertSame($orderDeadline->timestamp, $invoice->qrisEffectiveExpiresAt->timestamp);

        $viewData = app(StorefrontShopOrderPresenter::class)->viewData($order);
        $this->assertSame($orderDeadline->timestamp, $viewData['paymentDeadline']->timestamp);

        $this->fakeTelegramHttp([
            'https://klikqris.com/storage/sandbox/qris_surface.png' => Http::response(self::PNG_BYTES, 200, ['Content-Type' => 'image/png']),
        ]);

        app(TelegramCheckoutIntents::class)->sendKlikQrisPaymentInstructions(
            '12345',
            $order,
            $payment,
        );

        $sendPhotos = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'));
        $this->assertCount(1, $sendPhotos);
        $caption = $sendPhotos->first()[0]->body();
        $this->assertStringContainsString($invoice->payableAmountDisplay, $caption);
        $this->assertStringContainsString($orderDeadline->format('H:i'), $caption);
        $this->assertStringNotContainsString($providerDeadline->format('H:i'), $caption);
        $this->assertStringNotContainsString('secret', strtolower($caption));
    }

    public function test_non_payable_outcomes_never_render_a_qr_on_either_customer_surface(): void
    {
        $this->travelTo('2026-07-28 10:10:00 UTC');
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'en']);
        $outcomes = [
            KlikQrisNativeQrisOutcome::Creating,
            KlikQrisNativeQrisOutcome::RecoveryPending,
            KlikQrisNativeQrisOutcome::ProviderTotalPending,
            KlikQrisNativeQrisOutcome::InvalidProviderResult,
            KlikQrisNativeQrisOutcome::Expired,
        ];
        $resultsByPayment = [];
        $payments = [];

        foreach ($outcomes as $index => $outcome) {
            $order = Order::factory()->for($customer)->create([
                'invoice_number' => 'INV-NONPAYABLE-'.$index,
                'expires_at' => now()->addMinutes(15),
            ]);
            $payment = Payment::factory()->for($order)->create([
                'provider' => 'klikqris',
                'provider_payment_method' => 'qris',
                'merchant_ref' => $order->invoice_number,
                'amount' => 1000,
            ]);
            $payments[$payment->getKey()] = $payment;
            $resultsByPayment[$payment->getKey()] = KlikQrisNativeQrisLifecycleResult::state($outcome);
        }

        $native = Mockery::mock(KlikQrisNativeQrisService::class);
        $native->shouldReceive('classify')
            ->times(count($outcomes))
            ->andReturnUsing(fn (Payment $payment): KlikQrisNativeQrisLifecycleResult => $resultsByPayment[$payment->getKey()]);
        $native->shouldReceive('lifecycle')
            ->times(count($outcomes))
            ->andReturnUsing(fn (Payment $payment): KlikQrisNativeQrisLifecycleResult => $resultsByPayment[$payment->getKey()]);
        app()->instance(KlikQrisNativeQrisService::class, $native);

        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 654321]]),
        ]);

        foreach ($payments as $payment) {
            $invoice = app(StorefrontShopSignedInvoice::class)->read($payment->order);

            $this->assertFalse($invoice->isQrisPayable);
            $this->assertNotSame('ready', $invoice->qrisReadinessState);
            $this->assertNull($invoice->qrisImageSource);
            $this->assertNull($invoice->payableAmount);
            $this->assertNull($invoice->payableAmountDisplay);
            $this->assertNull($invoice->qrisEffectiveExpiresAt);

            app(TelegramCheckoutIntents::class)->sendKlikQrisPaymentInstructions(
                '12345',
                $payment->order,
                $payment,
            );
        }

        $sendPhotos = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'));
        $this->assertCount(0, $sendPhotos);
        $sendMessages = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'));
        $this->assertCount(count($outcomes), $sendMessages);

        foreach ($sendMessages as [$request]) {
            $body = strtolower($request->body());
            $this->assertStringNotContainsString('qris_url', $body);
            $this->assertStringNotContainsString('provider_create_state', $body);
            $this->assertStringNotContainsString('outcome_unknown', $body);
            $this->assertStringNotContainsString('signature', $body);
            $this->assertStringContainsString('check payment', $body);
        }
    }
}
