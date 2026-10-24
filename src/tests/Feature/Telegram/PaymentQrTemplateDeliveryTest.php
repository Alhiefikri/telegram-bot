<?php

namespace Tests\Feature\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\SendKlikQrisCheckoutInstructions;
use App\Jobs\SendSakurupiahCheckoutInstructions;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentQrTemplateSetting;
use App\Models\StoreBalanceTopUp;
use App\Services\KlikQris\KlikQrisNativeQrisLifecycleResult;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\KlikQris\KlikQrisPayableNativeQris;
use App\Services\Sakurupiah\SakurupiahCreateInvoice;
use App\Services\StoreBalance\TopUp\SakurupiahStoreBalanceTopUpLifecycle;
use App\Services\StoreBalance\TopUp\StoreBalanceTopUpPayable;
use App\Services\Telegram\TelegramCheckoutIntents;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Storefront\StorefrontInvoiceUrl;
use chillerlan\QRCode\QRCode;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\Support\TelegramBotTestSupport;

/**
 * Provider QR images and locally rendered payloads must reach the customer
 * inside the configured template, and must fall back to the untouched provider
 * bytes whenever the template cannot be applied.
 */
final class PaymentQrTemplateDeliveryTest extends TelegramBotTestSupport
{
    private const DIRECT_QRIS = '0002010102125303360540820450.005802ID63045B6D';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.sakurupiah', array_replace(config('services.sakurupiah', []), [
            'sandbox_enabled' => true,
            'sandbox_base_url' => 'https://sakurupiah.id/api-sanbox/',
            'sandbox_api_id' => 'api-test',
            'sandbox_api_key' => 'secret-key',
            'sandbox_allowed_hosts' => ['sakurupiah.id'],
            'sandbox_callback_url' => 'https://store.example.test/api/webhook/sakurupiah',
            'sandbox_return_url' => 'https://store.example.test/shop/orders',
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
        ]));
    }

    public function test_klikqris_checkout_photo_is_composited_into_the_template(): void
    {
        KlikQrisSandboxScenarios::configureReadySandbox();
        $providerImage = $this->fixture('provider-qr-285.png');
        $this->fakeTelegramHttp([
            'https://klikqris.com/storage/sandbox/qris_template.png' => Http::response($providerImage, 200, ['Content-Type' => 'image/png']),
        ]);
        $this->configureTemplate();

        $order = Order::factory()->for(Customer::factory()->create([
            'telegram_id' => 778001,
            'locale' => 'en',
        ]))->create([
            'invoice_number' => 'INV-QR-TEMPLATE-KLIK',
            'order_status' => OrderStatus::PendingPayment,
            'manual_checkout_customer_notified_at' => null,
            'expires_at' => now()->addMinutes(14),
        ]);
        $payment = Payment::factory()->for($order)->create([
            'provider' => 'klikqris',
            'provider_payment_method' => 'qris',
            'merchant_ref' => 'INV-QR-TEMPLATE-KLIK',
            'amount' => 1000,
            'provider_total_payment' => 1016,
            'provider_expires_at' => $order->expires_at->copy()->addMinute(),
            'status' => PaymentStatus::Pending,
            'provider_payload' => ['data' => [
                'order_id' => 'INV-QR-TEMPLATE-KLIK',
                'amount' => 1000,
                'total_amount' => '1016.00',
                'status' => 'PENDING',
                'qris_url' => 'https://klikqris.com/storage/sandbox/qris_template.png',
                'expired_at' => $order->expires_at->copy()->addMinute()->toAtomString(),
            ]],
            'provider_create_state' => 'created',
        ]);

        $native = $this->mock(KlikQrisNativeQrisService::class);
        $native->shouldReceive('lifecycle')
            ->once()
            ->andReturn(KlikQrisNativeQrisLifecycleResult::payableResult(
                KlikQrisPayableNativeQris::fromPayment($payment->fresh()),
            ));

        (new SendKlikQrisCheckoutInstructions($order->id))->handle(app(TelegramCheckoutIntents::class));

        $photo = $this->deliveredPhoto();
        $this->assertSame([1000, 1000], $this->dimensions($photo));
        $this->assertSame($this->fixture('provider-payload.txt'), $this->decoded($photo));
        $this->assertStringNotContainsString($providerImage, $photo);
    }

    public function test_sakurupiah_provider_image_checkout_photo_is_composited(): void
    {
        $imageUrl = 'https://sakurupiah.id/qris/trx-template.png';
        $this->fakeTelegramHttp([
            $imageUrl => Http::response($this->fixture('provider-qr-285.png'), 200, ['Content-Type' => 'image/png']),
        ]);
        $this->configureTemplate();

        [$order, $payment] = $this->sakurupiahPayment([
            'invoice_number' => 'INV-QR-TEMPLATE-SAK-IMAGE',
            'provider_presentation' => [
                'mode' => 'qris_image',
                'qris_image_url' => $imageUrl,
                'checkout_url' => null,
            ],
        ]);

        $effect = app(TelegramOutboundEffectService::class)->recordCheckoutInstructions($order, $payment);
        (new SendSakurupiahCheckoutInstructions($order->id, null, $effect?->id))
            ->handle(app(TelegramCheckoutIntents::class));

        $photo = $this->deliveredPhoto();
        $this->assertSame([1000, 1000], $this->dimensions($photo));
        $this->assertSame($this->fixture('provider-payload.txt'), $this->decoded($photo));
    }

    public function test_sakurupiah_direct_qris_checkout_photo_is_composited(): void
    {
        $this->fakeTelegramHttp();
        $this->configureTemplate();

        [$order, $payment] = $this->sakurupiahPayment([
            'invoice_number' => 'INV-QR-TEMPLATE-SAK-DIRECT',
            'provider_presentation' => [
                'mode' => 'qris',
                'qris_payload' => self::DIRECT_QRIS,
                'checkout_url' => null,
            ],
        ]);

        $effect = app(TelegramOutboundEffectService::class)->recordCheckoutInstructions($order, $payment);
        (new SendSakurupiahCheckoutInstructions($order->id, null, $effect?->id))
            ->handle(app(TelegramCheckoutIntents::class));

        $photo = $this->deliveredPhoto();
        $this->assertSame([1000, 1000], $this->dimensions($photo));
        $this->assertSame(self::DIRECT_QRIS, $this->decoded($photo));
    }

    public function test_klikqris_storefront_route_returns_the_composited_image(): void
    {
        $this->travelTo('2026-08-13 10:10:00 UTC');
        KlikQrisSandboxScenarios::configureReadyProduction();
        app()->detectEnvironment(fn (): string => 'production');
        $this->configureTemplate();

        $qrisUrl = 'https://api.klikqris.example/storage/qris_api/qris_INV-QR-STOREFRONT.png';
        $expiresAt = now()->addMinutes(15)->startOfSecond();
        $payment = KlikQrisSandboxScenarios::klikQrisPayment([
            'merchant_ref' => 'INV-QR-STOREFRONT',
            'amount' => 1000,
            'provider_total_payment' => 1016,
            'provider_fee' => 16,
            'provider_expires_at' => $expiresAt,
            'expired_at' => $expiresAt,
            'provider_create_state' => 'created',
            'provider_payload' => ['data' => [
                'order_id' => 'INV-QR-STOREFRONT',
                'amount' => 1000,
                'total_amount' => '1016.00',
                'status' => 'PENDING',
                'qris_url' => $qrisUrl,
                'expired_at' => $expiresAt->toISOString(),
            ]],
        ]);

        Http::fake([
            $qrisUrl => Http::response($this->fixture('provider-qr-285.png'), 200, ['Content-Type' => 'image/png']),
            'https://api.klikqris.example/qris/status/INV-QR-STOREFRONT' => Http::response(['data' => [
                'order_id' => 'INV-QR-STOREFRONT',
                'amount' => 1000,
                'total_amount' => '1016.00',
                'status' => 'PENDING',
                'qris_url' => $qrisUrl,
                'expired_at' => $expiresAt->toISOString(),
                'signature' => 'provider-signature',
            ]]),
        ]);

        $response = $this->get(app(StorefrontInvoiceUrl::class)->qrisImage($payment->order));

        $response->assertOk();
        $contents = $response->getContent();
        $this->assertSame([1000, 1000], $this->dimensions($contents));
        $this->assertSame($this->fixture('provider-payload.txt'), $this->decoded($contents));
    }

    public function test_sakurupiah_storefront_route_returns_the_composited_image(): void
    {
        $imageUrl = 'https://sakurupiah.id/qris/trx-storefront-template.png';
        $this->configureTemplate();
        [$order] = $this->sakurupiahPayment([
            'invoice_number' => 'INV-QR-STOREFRONT-SAK',
            'provider_presentation' => [
                'mode' => 'qris_image',
                'qris_image_url' => $imageUrl,
                'checkout_url' => null,
            ],
        ]);
        Http::fake([
            $imageUrl => Http::response($this->fixture('provider-qr-285.png'), 200, ['Content-Type' => 'image/png']),
        ]);

        $response = $this->get(app(StorefrontInvoiceUrl::class)->qrisImage($order));

        $response->assertOk();
        $contents = $response->getContent();
        $this->assertSame([1000, 1000], $this->dimensions($contents));
        $this->assertSame($this->fixture('provider-payload.txt'), $this->decoded($contents));
    }

    public function test_checkout_falls_back_to_the_provider_photo_when_the_template_file_is_missing(): void
    {
        $providerImage = $this->fixture('provider-qr-285.png');
        $this->fakeTelegramHttp([
            'https://sakurupiah.id/qris/trx-missing-template.png' => Http::response($providerImage, 200, ['Content-Type' => 'image/png']),
        ]);
        PaymentQrTemplateSetting::query()->updateOrCreate(
            ['singleton_key' => PaymentQrTemplateSetting::SINGLETON_KEY],
            [
                'image_path' => 'payment-qr-templates/missing.webp',
                'qr_x' => 250,
                'qr_y' => 250,
                'qr_width' => 520,
                'qr_height' => 520,
                'version' => 1,
            ],
        );

        [$order, $payment] = $this->sakurupiahPayment([
            'invoice_number' => 'INV-QR-TEMPLATE-MISSING',
            'provider_presentation' => [
                'mode' => 'qris_image',
                'qris_image_url' => 'https://sakurupiah.id/qris/trx-missing-template.png',
                'checkout_url' => null,
            ],
        ]);

        $effect = app(TelegramOutboundEffectService::class)->recordCheckoutInstructions($order, $payment);
        (new SendSakurupiahCheckoutInstructions($order->id, null, $effect?->id))
            ->handle(app(TelegramCheckoutIntents::class));

        $photo = $this->deliveredPhoto();
        $this->assertSame($providerImage, $photo);
        $this->assertSame([285, 285], $this->dimensions($photo));
    }

    public function test_checkout_keeps_the_provider_photo_when_no_template_is_configured(): void
    {
        $providerImage = $this->fixture('provider-qr-285.png');
        $this->fakeTelegramHttp([
            'https://sakurupiah.id/qris/trx-no-template.png' => Http::response($providerImage, 200, ['Content-Type' => 'image/png']),
        ]);

        [$order, $payment] = $this->sakurupiahPayment([
            'invoice_number' => 'INV-QR-TEMPLATE-ABSENT',
            'provider_presentation' => [
                'mode' => 'qris_image',
                'qris_image_url' => 'https://sakurupiah.id/qris/trx-no-template.png',
                'checkout_url' => null,
            ],
        ]);

        $effect = app(TelegramOutboundEffectService::class)->recordCheckoutInstructions($order, $payment);
        (new SendSakurupiahCheckoutInstructions($order->id, null, $effect?->id))
            ->handle(app(TelegramCheckoutIntents::class));

        $this->assertSame($providerImage, $this->deliveredPhoto());
    }

    public function test_sakurupiah_top_up_attachment_is_composited(): void
    {
        $this->configureTemplate();
        $topUp = new StoreBalanceTopUp(['provider_qris_payload' => self::DIRECT_QRIS]);
        $payable = new StoreBalanceTopUpPayable(5_000, 450, 5_450, now()->addMinutes(10)->toImmutable());

        $attachment = app(SakurupiahStoreBalanceTopUpLifecycle::class)
            ->instructionAttachment($topUp, $payable);

        $photo = $attachment->photoContents();
        $this->assertSame([1000, 1000], $this->dimensions($photo));
        $this->assertSame(self::DIRECT_QRIS, $this->decoded($photo));
    }

    private function configureTemplate(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('payment-qr-templates/template.webp', $this->fixture('template-square-box.webp'));

        PaymentQrTemplateSetting::query()->updateOrCreate(
            ['singleton_key' => PaymentQrTemplateSetting::SINGLETON_KEY],
            [
                'image_path' => 'payment-qr-templates/template.webp',
                'qr_x' => 250,
                'qr_y' => 250,
                'qr_width' => 520,
                'qr_height' => 520,
                'version' => 1,
            ],
        );
    }

    private function deliveredPhoto(): string
    {
        $sendPhotos = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'));
        $this->assertCount(1, $sendPhotos);
        $body = $sendPhotos->first()[0]->body();
        $start = strpos($body, "\x89PNG\r\n\x1A\n");
        $end = strpos($body, "IEND\xAE\x42\x60\x82", $start === false ? 0 : $start);
        $this->assertIsInt($start);
        $this->assertIsInt($end);

        return substr($body, $start, $end - $start + 8);
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(base_path('tests/Fixtures/qr/'.$name));
        $this->assertIsString($contents);

        return $contents;
    }

    /** @return array{0: int, 1: int} */
    private function dimensions(string $png): array
    {
        $size = getimagesizefromstring($png);
        $this->assertIsArray($size);

        return [(int) $size[0], (int) $size[1]];
    }

    private function decoded(string $png): string
    {
        return (new QRCode)->readFromBlob($png)->data;
    }

    /** @return array{0: Order, 1: Payment} */
    private function sakurupiahPayment(array $attributes = []): array
    {
        $expiresAt = now()->addMinutes(20)->startOfSecond();
        $invoiceNumber = $attributes['invoice_number'] ?? 'INV-SAK-QR-TEMPLATE';
        $presentation = $attributes['provider_presentation'] ?? null;
        unset($attributes['invoice_number'], $attributes['provider_presentation']);
        if (is_array($presentation)) {
            $presentation['effective_expires_at'] = $expiresAt->toIso8601String();
        }
        $customer = Customer::factory()->create([
            'telegram_id' => 778101,
            'locale' => 'en',
        ]);
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => $invoiceNumber,
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'total_amount' => 20_000,
            'currency' => 'IDR',
            'expires_at' => $expiresAt,
        ]);
        OrderItem::factory()->for($order)->create([
            'product_name' => 'Premium access',
            'quantity' => 1,
            'unit_price' => 20_000,
            'subtotal' => 20_000,
        ]);

        $payment = Payment::factory()->for($order)->create(array_merge([
            'provider' => 'sakurupiah',
            'method' => SakurupiahCreateInvoice::PAYMENT_METHOD_QRIS,
            'provider_reference' => 'trx-'.$order->invoice_number,
            'provider_payment_method' => SakurupiahCreateInvoice::METHOD_QRISC,
            'provider_total_payment' => 20_450,
            'provider_fee' => 450,
            'provider_created_at' => now()->subMinute(),
            'provider_expires_at' => $expiresAt,
            'provider_presentation' => $presentation,
            'provider_payload' => [
                'data' => [[
                    'trx_id' => 'trx-'.$order->invoice_number,
                    'merchant_ref' => $order->invoice_number,
                    'payment_kode' => 'QRISC',
                    'payment_status' => 'pending',
                    'total' => '20450',
                    'fee' => '450',
                ]],
            ],
            'provider_create_state' => 'created',
            'amount' => 20_000,
            'currency' => 'IDR',
            'status' => PaymentStatus::Pending,
            'expired_at' => $expiresAt,
        ], $attributes));

        return [$order, $payment];
    }
}
