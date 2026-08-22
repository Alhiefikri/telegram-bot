<?php

namespace Tests\Feature\Telegram;

use App\Enums\CredentialAccessMethod;
use App\Enums\CredentialFormat;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Jobs\SendTelegramReceipt;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\TelegramOutboundEffect;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\Credentials\StructuredCredentialPayload;
use App\Services\OrderCheckoutService;
use App\Services\OrderStateMachine;
use App\Services\PaidInvoice\PaidInvoiceRenderer;
use App\Services\Telegram\ReceiptDeliveryPipeline;
use App\Services\Telegram\TelegramOutboundEffectReviewService;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\Telegram\TelegramReceiptNotifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TelegramBotTestSupport;

class TelegramReceiptOutboundEffectTest extends TelegramBotTestSupport
{
    public function test_paid_lifecycle_materializes_one_idempotent_receipt_group(): void
    {
        Queue::fake();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->addItem($cart, $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::manualQris());
        $payment = $order->payments()->sole();

        app(OrderStateMachine::class)->applyPaymentPaid($payment);
        app(OrderStateMachine::class)->applyPaymentPaid($payment->fresh());

        $effects = TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS;
        $receiptEffects = $order->telegramOutboundEffects()
            ->where('effect_type', $effects)
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $receiptEffects);
        $this->assertSame(
            TelegramOutboundEffectService::legacyReceiptEffectGroupKey($order->id),
            $receiptEffects->first()->effect_group_key,
        );
        $this->assertSame(TelegramOutboundEffectState::Pending, $receiptEffects->first()->state);
        $this->assertSame('sendPhoto', $receiptEffects->first()->telegram_method);
        $this->assertSame('invoice', $receiptEffects->first()->outcome_metadata['unit_role']);
        $this->assertSame('sendMessage', $receiptEffects->last()->telegram_method);
        $this->assertSame('product_detail', $receiptEffects->last()->outcome_metadata['unit_role']);
        $this->assertSame(
            TelegramOutboundEffectService::TEMPLATE_RECEIPT_INVOICE,
            $receiptEffects->first()->template_version,
        );
        $this->assertStringNotContainsString('buyer@example', json_encode($receiptEffects->first()->getAttributes(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('buyer@example', json_encode($receiptEffects->last()->getAttributes(), JSON_THROW_ON_ERROR));
        Queue::assertPushed(SendTelegramReceipt::class, 1);
    }

    #[DataProvider('singleStructuredPhotoCases')]
    public function test_single_structured_account_receipt_is_one_protected_localized_photo(
        string $locale,
        CredentialAccessMethod $accessMethod,
    ): void {
        Queue::fake();
        [$order, $sensitiveValues] = $this->paidSingleStructuredReceiptOrder($locale, $accessMethod);
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 834001],
            ]),
        ]);

        app(TelegramReceiptNotifier::class)->send($order);

        $effects = TelegramOutboundEffect::query()
            ->where('effect_group_key', TelegramOutboundEffectService::receiptPhotoCaptionEffectGroupKey((string) $order->id))
            ->orderBy('sequence')
            ->get();
        $this->assertCount(1, $effects);
        $this->assertSame('sendPhoto', $effects->sole()->telegram_method);
        $this->assertSame(
            TelegramOutboundEffectService::TEMPLATE_RECEIPT_PHOTO_CAPTION,
            $effects->sole()->template_version,
        );
        $this->assertSame('invoice_caption', $effects->sole()->outcome_metadata['unit_role']);

        $photoRequests = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto'));
        $this->assertCount(1, $photoRequests);
        $this->assertCount(
            0,
            Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')),
        );
        $photo = $photoRequests->sole()[0];
        $caption = (string) $this->telegramRequestField($photo, 'caption');
        $this->assertLessThan(TelegramReceiptNotifier::PHOTO_CAPTION_LIMIT, mb_strlen($caption, 'UTF-8'));
        $this->assertStringContainsString($order->invoice_number, $caption);
        $this->assertStringContainsString('Issue 334 Product', $caption);
        $this->assertStringContainsString('Product note once', $caption);
        $this->assertSame(1, substr_count($caption, 'Product note once'));
        $this->assertStringContainsString('LHIE STORE', $caption);
        $this->assertTrue(filter_var($this->telegramRequestField($photo, 'protect_content'), FILTER_VALIDATE_BOOLEAN));

        $expectedTitles = [
            'id' => '✅ Pembayaran diterima',
            'en' => '✅ Payment received',
            'ms' => '✅ Bayaran diterima',
        ];
        $expectedPaymentLabels = [
            'id' => 'Metode pembayaran: QRIS',
            'en' => 'Payment method: QRIS',
            'ms' => 'Kaedah bayaran: QRIS',
        ];
        $this->assertStringContainsString($expectedTitles[$locale], $caption);
        $this->assertStringContainsString($expectedPaymentLabels[$locale], $caption);
        $this->assertStringContainsString(
            match ($locale) {
                'id' => '🔐 Detail Produk',
                'en' => '🔐 Product Detail',
                'ms' => '🔐 Butiran Produk',
            },
            $caption,
        );

        $email = $sensitiveValues['email'];
        $password = $sensitiveValues['password'];
        $this->assertStringContainsString($email, $caption);
        $this->assertStringContainsString($password, $caption);
        $fields = [$email, $password];
        if ($accessMethod === CredentialAccessMethod::OtpMailLink) {
            $this->assertStringContainsString('otp.issue334.example.test', $caption);
            $this->assertStringNotContainsString($sensitiveValues['access_value'], $caption);
            $markup = json_decode((string) $this->telegramRequestField($photo, 'reply_markup'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($sensitiveValues['access_value'], data_get($markup, 'inline_keyboard.0.0.url'));
        } elseif ($accessMethod === CredentialAccessMethod::AuthToken) {
            $this->assertStringContainsString($locale === 'en' ? 'access: Auth Token' : 'akses: Auth Token', $caption);
            $this->assertStringContainsString($sensitiveValues['access_value'], $caption);
            $this->assertStringNotContainsString($sensitiveValues['access_value'], json_encode([
                'reply_markup' => $this->telegramRequestField($photo, 'reply_markup'),
                'options' => $this->telegramRequestField($photo, 'caption_entities'),
            ], JSON_THROW_ON_ERROR));
            $fields[] = $sensitiveValues['access_value'];
        } else {
            $this->assertStringNotContainsString('akses:', $caption);
        }

        $entities = json_decode((string) $this->telegramRequestField($photo, 'caption_entities'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(count($fields), $entities);
        foreach ($fields as $field) {
            $byteOffset = strpos($caption, $field);
            $this->assertNotFalse($byteOffset);
            $prefix = substr($caption, 0, $byteOffset);
            $this->assertTrue(collect($entities)->contains(
                fn (array $entity): bool => $entity['type'] === 'code'
                    && $entity['offset'] === intdiv(strlen(mb_convert_encoding($prefix, 'UTF-16LE', 'UTF-8')), 2)
                    && $entity['length'] === intdiv(strlen(mb_convert_encoding($field, 'UTF-16LE', 'UTF-8')), 2),
            ));
        }

        $photoBytes = $this->telegramRequestField($photo, 'photo');
        foreach ($sensitiveValues as $value) {
            $this->assertStringNotContainsString($value, $photoBytes);
        }
        $effectJson = json_encode($effects->sole()->getAttributes(), JSON_THROW_ON_ERROR);
        foreach ($sensitiveValues as $value) {
            $this->assertStringNotContainsString($value, $effectJson);
        }
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
    }

    /** @return iterable<string, array{string, CredentialAccessMethod}> */
    public static function singleStructuredPhotoCases(): iterable
    {
        foreach (['id', 'en', 'ms'] as $locale) {
            yield $locale.' none' => [$locale, CredentialAccessMethod::None];
            yield $locale.' otp' => [$locale, CredentialAccessMethod::OtpMailLink];
            yield $locale.' auth token' => [$locale, CredentialAccessMethod::AuthToken];
        }
    }

    public function test_direct_receipt_send_processes_photo_and_text_once_without_successor_jobs(): void
    {
        Queue::fake();
        $order = $this->paidOrderWithoutCredentials();

        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 810001],
            ]),
            'https://api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 810002],
            ]),
        ]);

        app(TelegramReceiptNotifier::class)->send($order);

        Queue::assertNotPushed(SendTelegramReceipt::class);
        $this->assertCount(
            1,
            Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto')),
        );
        $this->assertCount(
            1,
            Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')),
        );
        $this->assertSame(
            [
                TelegramOutboundEffectState::Sent,
                TelegramOutboundEffectState::Sent,
            ],
            TelegramOutboundEffect::query()
                ->where('effect_group_key', TelegramOutboundEffectService::legacyReceiptEffectGroupKey((string) $order->id))
                ->orderBy('sequence')
                ->pluck('state')
                ->all(),
        );
    }

    #[DataProvider('buyerCredentialPaths')]
    public function test_successful_buyer_pipeline_sends_invoice_photo_then_product_detail_without_credential_persistence(
        string $credentialPath,
    ): void {
        Queue::fake();
        [$order, $sensitiveValues] = $this->paidBuyerReceiptOrder($credentialPath);

        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 810101],
            ]),
            'https://api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 810102],
            ]),
        ]);

        app(TelegramReceiptNotifier::class)->send($order);

        $deliveryRequests = Http::recorded()
            ->filter(static function (array $record): bool {
                $url = $record[0]->url();

                return str_ends_with($url, '/sendPhoto')
                    || str_ends_with($url, '/sendMessage');
            })
            ->values();

        $this->assertGreaterThanOrEqual(2, $deliveryRequests->count());
        $photoRequest = $deliveryRequests->get(0)[0];
        $detailRequest = $deliveryRequests->get(1)[0];
        $this->assertStringEndsWith('/sendPhoto', $photoRequest->url());
        $this->assertStringEndsWith('/sendMessage', $detailRequest->url());
        $this->assertStringContainsString(
            '🔐 Detail Produk',
            (string) $this->telegramRequestField($detailRequest, 'text'),
        );

        $photoBytes = $this->telegramRequestField($photoRequest, 'photo');
        $this->assertIsString($photoBytes);
        $this->assertNotSame('', $photoBytes);
        $this->assertSame("\x89PNG\r\n\x1A\n", substr($photoBytes, 0, 8));

        $imageInfo = getimagesizefromstring($photoBytes);
        $this->assertIsArray($imageInfo);
        $this->assertSame(PaidInvoiceRenderer::WIDTH, $imageInfo[0] ?? null);
        $this->assertSame(PaidInvoiceRenderer::HEIGHT, $imageInfo[1] ?? null);
        $this->assertSame(IMAGETYPE_PNG, $imageInfo[2] ?? null);
        $this->assertSame('image/png', $imageInfo['mime'] ?? null);

        $decodedImage = imagecreatefromstring($photoBytes);
        $this->assertInstanceOf(\GdImage::class, $decodedImage);
        if ($decodedImage instanceof \GdImage) {
            $this->assertSame(PaidInvoiceRenderer::WIDTH, imagesx($decodedImage));
            $this->assertSame(PaidInvoiceRenderer::HEIGHT, imagesy($decodedImage));
            imagedestroy($decodedImage);
        }

        foreach ($sensitiveValues as $sensitiveValue) {
            $this->assertStringNotContainsString(
                $sensitiveValue,
                $photoBytes,
                $credentialPath.' invoice PNG contains credential-derived text.',
            );
        }

        $effects = TelegramOutboundEffect::query()
            ->where('effect_group_key', TelegramOutboundEffectService::legacyReceiptEffectGroupKey((string) $order->id))
            ->orderBy('sequence')
            ->get();
        $this->assertGreaterThanOrEqual(2, $effects->count());

        foreach ($effects as $effect) {
            $attributes = json_encode($effect->getAttributes(), JSON_THROW_ON_ERROR);
            foreach ($sensitiveValues as $sensitiveValue) {
                $this->assertStringNotContainsString(
                    $sensitiveValue,
                    $attributes,
                    $credentialPath.' receipt effect persisted credential text.',
                );
            }
            $this->assertStringNotContainsString(
                $photoBytes,
                $attributes,
                $credentialPath.' receipt effect persisted PNG bytes.',
            );
        }

        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
    }

    /** @return iterable<string, array{string}> */
    public static function buyerCredentialPaths(): iterable
    {
        yield 'structured account' => ['structured'];
        yield 'legacy credential' => ['legacy'];
    }

    public function test_queued_receipt_delivery_dispatches_one_successor_effect(): void
    {
        Queue::fake();
        $order = $this->paidOrderWithoutCredentials();
        $invoice = app(TelegramOutboundEffectService::class)->recordReceipt($order);
        $this->assertNotNull($invoice);
        $detail = TelegramOutboundEffect::query()
            ->where('effect_group_key', $invoice->effect_group_key)
            ->where('sequence', 2)
            ->firstOrFail();

        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 810003],
            ]),
        ]);

        app(ReceiptDeliveryPipeline::class)->deliver($invoice);

        Queue::assertPushedTimes(SendTelegramReceipt::class, 1);
        Queue::assertPushed(
            SendTelegramReceipt::class,
            fn (SendTelegramReceipt $job): bool => $job->effectId === (string) $detail->id,
        );
    }

    #[DataProvider('receiptAmountFallbackCases')]
    public function test_invoice_metadata_and_buyer_text_use_order_total_when_provider_total_is_missing(
        string $currency,
        int $orderTotal,
        int $paymentAmount,
        string $expectedAmount,
        string $unexpectedAmount,
    ): void {
        $order = $this->paidOrderWithAmountMismatch($currency, $orderTotal, $paymentAmount);

        $invoice = app(PaidInvoiceRenderer::class)->render($order);
        $detail = app(TelegramReceiptNotifier::class)->renderProductDetail($order)['units'][0];

        $this->assertSame($currency, $invoice->metadata['currency']);
        $this->assertSame($orderTotal, $invoice->metadata['total_paid']);
        $this->assertStringContainsString('Jumlah dibayar: '.$expectedAmount, $detail->text());
        $this->assertStringNotContainsString('Jumlah dibayar: '.$unexpectedAmount, $detail->text());
    }

    public static function receiptAmountFallbackCases(): iterable
    {
        yield 'IDR' => ['IDR', 12_345, 98_765, 'Rp 12.345', 'Rp 98.765'];
        yield 'USDT' => ['USDT', 2_500_000, 4_000_000, '2.500000 USDT', '4.000000 USDT'];
    }

    public function test_invoice_metadata_and_buyer_text_keep_provider_total_when_present(): void
    {
        $order = $this->paidOrderWithAmountMismatch('IDR', 12_345, 98_765);
        $order->payments()->sole()->forceFill(['provider_total_payment' => 13_579])->save();
        $order->refresh()->load(['customer', 'orderItems.stockItems', 'orderItems.product', 'payments']);

        $invoice = app(PaidInvoiceRenderer::class)->render($order);
        $detail = app(TelegramReceiptNotifier::class)->renderProductDetail($order)['units'][0];

        $this->assertSame(13_579, $invoice->metadata['total_paid']);
        $this->assertStringContainsString('Jumlah dibayar: Rp 13.579', $detail->text());
    }

    private function paidOrderWithAmountMismatch(string $currency, int $orderTotal, int $paymentAmount): Order
    {
        $order = $this->paidOrderWithoutCredentials();
        $order->forceFill([
            'currency' => $currency,
            'total_amount' => $orderTotal,
        ])->save();
        $order->payments()->sole()->forceFill([
            'amount' => $paymentAmount,
            'currency' => $currency,
            'provider_total_payment' => null,
        ])->save();

        return $order->fresh(['customer', 'orderItems.stockItems', 'orderItems.product', 'payments']);
    }

    public function test_invoice_failure_dispatches_product_detail_and_completion_ignores_photo_failure(): void
    {
        Queue::fake();
        $order = $this->paidOrderWithoutCredentials();
        $invoice = app(TelegramOutboundEffectService::class)->recordReceipt($order);
        $this->assertNotNull($invoice);
        $detail = TelegramOutboundEffect::query()
            ->where('effect_group_key', $invoice->effect_group_key)
            ->where('sequence', 2)
            ->firstOrFail();

        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => false,
                'description' => 'invoice photo rejected',
            ]),
            'https://api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 800001],
            ]),
        ]);

        app(ReceiptDeliveryPipeline::class)->deliver($invoice);

        $this->assertSame(TelegramOutboundEffectState::Failed, $invoice->fresh()->state);
        $this->assertSame(TelegramOutboundEffectState::Pending, $detail->fresh()->state);
        Queue::assertPushed(
            SendTelegramReceipt::class,
            fn (SendTelegramReceipt $job): bool => $job->effectId === (string) $detail->id,
        );

        app(ReceiptDeliveryPipeline::class)->deliver($detail, dispatchNext: false);

        $this->assertSame(TelegramOutboundEffectState::Sent, $detail->fresh()->state);
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
    }

    public function test_ambiguous_invoice_is_unknown_but_product_detail_still_delivers(): void
    {
        Queue::fake();
        $order = $this->paidOrderWithoutCredentials();
        $invoice = app(TelegramOutboundEffectService::class)->recordReceipt($order);
        $this->assertNotNull($invoice);
        $detail = TelegramOutboundEffect::query()
            ->where('effect_group_key', $invoice->effect_group_key)
            ->where('sequence', 2)
            ->firstOrFail();

        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => function (): never {
                throw new ConnectionException('invoice photo timed out after Telegram accepted it');
            },
            'https://api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 800002],
            ]),
        ]);

        app(ReceiptDeliveryPipeline::class)->deliver($invoice);

        $this->assertSame(TelegramOutboundEffectState::Unknown, $invoice->fresh()->state);
        $this->assertSame(TelegramOutboundEffectState::Pending, $detail->fresh()->state);
        Queue::assertPushed(
            SendTelegramReceipt::class,
            fn (SendTelegramReceipt $job): bool => $job->effectId === (string) $detail->id,
        );

        app(ReceiptDeliveryPipeline::class)->deliver($detail, dispatchNext: false);

        $this->assertSame(TelegramOutboundEffectState::Sent, $detail->fresh()->state);
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
    }

    public function test_preexisting_text_only_receipt_template_remains_dispatchable(): void
    {
        $order = $this->paidOrderWithoutCredentials();
        $groupKey = TelegramOutboundEffectService::legacyReceiptEffectGroupKey($order->id);
        $effect = TelegramOutboundEffect::create([
            'effect_key' => $groupKey.':unit:1',
            'effect_group_key' => $groupKey,
            'sequence' => 1,
            'effect_type' => TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS,
            'source_type' => Order::class,
            'source_id' => (string) $order->id,
            'target_type' => 'customer',
            'telegram_chat_id' => (string) $order->customer->telegram_id,
            'queue' => TelegramOutboundEffectService::RECEIPT_QUEUE,
            'telegram_method' => 'sendMessage',
            'locale' => 'id',
            'template_version' => TelegramOutboundEffectService::TEMPLATE_RECEIPT_CREDENTIALS,
            'state' => TelegramOutboundEffectState::Pending,
            'attempts' => 0,
            'next_attempt_at' => now(),
            'outcome_metadata' => [
                'delivery_context' => 'receipt',
                'unit_role' => 'product_detail',
                'receipt_chunk_index' => 0,
                'receipt_chunk_count' => 1,
            ],
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 800003],
            ]),
        ]);

        app(ReceiptDeliveryPipeline::class)->deliver($effect, dispatchNext: false);

        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state);
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
        $this->assertCount(
            1,
            Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')),
        );
        $this->assertCount(
            0,
            Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto')),
        );
    }

    public function test_recovery_materializes_a_paid_receipt_without_a_legacy_claim(): void
    {
        Queue::fake();
        $order = $this->multiChunkPaidOrder();

        $this->artisan('telegram:recover-outbound')
            ->assertSuccessful();

        Queue::assertPushed(
            SendTelegramReceipt::class,
            fn (SendTelegramReceipt $job): bool => $job->orderId === (string) $order->id
                && $job->effectId !== null,
        );
    }

    public function test_ordered_receipt_stops_after_an_ambiguous_credential_unit_without_resending_previous_units(): void
    {
        $order = $this->multiChunkPaidOrder();
        $outboundEffects = app(TelegramOutboundEffectService::class);
        $first = $outboundEffects->recordReceipt($order);
        $this->assertNotNull($first);
        $summary = TelegramOutboundEffect::query()
            ->where('effect_group_key', $first->effect_group_key)
            ->where('sequence', 2)
            ->firstOrFail();
        $credential = TelegramOutboundEffect::query()
            ->where('effect_group_key', $first->effect_group_key)
            ->where('sequence', 3)
            ->firstOrFail();
        $later = TelegramOutboundEffect::query()
            ->where('effect_group_key', $first->effect_group_key)
            ->where('sequence', 4)
            ->firstOrFail();

        $photoCount = 0;
        $sendCount = 0;
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => function () use (&$photoCount): mixed {
                $photoCount++;

                return Http::response(['ok' => true, 'result' => ['message_id' => 700 + $photoCount]]);
            },
            'https://api.telegram.org/*/sendMessage' => function () use (&$sendCount): mixed {
                $sendCount++;
                if ($sendCount === 2) {
                    throw new ConnectionException('timeout after Telegram accepted the credential chunk');
                }

                return Http::response(['ok' => true, 'result' => ['message_id' => 710 + $sendCount]]);
            },
        ]);

        $pipeline = app(ReceiptDeliveryPipeline::class);
        $pipeline->deliver($first, dispatchNext: false);
        $pipeline->deliver($summary, dispatchNext: false);
        $pipeline->deliver($credential, dispatchNext: false);
        $pipeline->deliver($later, dispatchNext: false);

        $this->assertSame(1, $photoCount);
        $this->assertSame(2, $sendCount);
        $this->assertSame(TelegramOutboundEffectState::Sent, $first->fresh()->state);
        $this->assertSame(TelegramOutboundEffectState::Sent, $summary->fresh()->state);
        $this->assertSame(TelegramOutboundEffectState::Unknown, $credential->fresh()->state);
        $this->assertSame(TelegramOutboundEffectState::Pending, $later->fresh()->state);
        $this->assertNull($order->fresh()->telegram_receipt_sent_at);
        $this->assertStringNotContainsString(
            'buyer0@example.test',
            json_encode($credential->fresh()->getAttributes(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_marking_the_final_receipt_chunk_sent_completes_the_legacy_projection(): void
    {
        $order = $this->multiChunkPaidOrder();
        $outboundEffects = app(TelegramOutboundEffectService::class);
        $first = $outboundEffects->recordReceipt($order);
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 900000],
            ]),
            'https://api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 900001],
            ]),
        ]);
        $pipeline = app(ReceiptDeliveryPipeline::class);
        $pipeline->deliver($first, dispatchNext: false);
        $current = TelegramOutboundEffect::query()
            ->where('effect_group_key', $first->effect_group_key)
            ->where('sequence', '>', $first->sequence)
            ->orderBy('sequence')
            ->first();
        $this->assertNotNull($current);

        while (true) {
            $current->forceFill([
                'state' => TelegramOutboundEffectState::Sent,
                'sent_at' => now(),
            ])->save();

            $next = TelegramOutboundEffect::query()
                ->where('effect_group_key', $current->effect_group_key)
                ->where('sequence', '>', $current->sequence)
                ->whereIn('state', [TelegramOutboundEffectState::Pending, TelegramOutboundEffectState::Failed])
                ->orderBy('sequence')
                ->first();
            if ($next === null) {
                break;
            }

            $current = $next;
        }

        $current->forceFill([
            'state' => TelegramOutboundEffectState::Unknown,
            'unknown_at' => now(),
        ])->save();

        $this->assertTrue(app(TelegramOutboundEffectReviewService::class)->markSent($current, '900002'));
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
        $this->assertSame(TelegramOutboundEffectState::Sent, $current->fresh()->state);
    }

    #[DataProvider('invalidTelegramResponses')]
    public function test_invalid_telegram_responses_never_mark_a_receipt_chunk_sent(array $response): void
    {
        $order = $this->multiChunkPaidOrder();
        $outboundEffects = app(TelegramOutboundEffectService::class);
        $effect = $outboundEffects->recordReceipt($order);
        $this->assertNotNull($effect);
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => Http::response($response),
            'https://api.telegram.org/*/sendMessage' => Http::response($response),
        ]);

        app(ReceiptDeliveryPipeline::class)->deliver($effect, dispatchNext: false);

        $freshEffect = $effect->fresh();
        $this->assertSame(TelegramOutboundEffectState::Failed, $freshEffect->state);
        $this->assertNull($freshEffect->telegram_message_id);
        $this->assertNull($order->fresh()->telegram_receipt_sent_at);
    }

    /** @return array{0: Order, 1: array{email: string, password: string, access_value?: string, otp_domain?: string}} */
    private function paidSingleStructuredReceiptOrder(string $locale, CredentialAccessMethod $accessMethod): array
    {
        $localeIndex = array_search($locale, ['id', 'en', 'ms'], true);
        $methodIndex = array_search($accessMethod, [
            CredentialAccessMethod::None,
            CredentialAccessMethod::OtpMailLink,
            CredentialAccessMethod::AuthToken,
        ], true);
        $customer = Customer::factory()->create([
            'telegram_id' => 334000 + ((int) $localeIndex * 10) + (int) $methodIndex,
            'locale' => $locale,
        ]);
        $product = Product::factory()->create(['name' => 'Issue 334 Product']);
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-334-'.$locale.'-'.$accessMethod->value,
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => 12_500,
            'currency' => 'IDR',
        ]);
        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'delivery_note' => 'Product note once',
            'delivery_note_snapshotted' => true,
            'quantity' => 1,
            'unit_price' => 12_500,
            'subtotal' => 12_500,
        ]);
        Payment::factory()->for($order)->create([
            'method' => 'QR_MANUAL',
            'amount' => 12_500,
            'provider_total_payment' => 12_500,
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        $email = 'issue334-'.$locale.'-'.$accessMethod->value.'@example.test';
        $password = 'Issue334-'.$locale.'-password';
        $accessValue = match ($accessMethod) {
            CredentialAccessMethod::None => null,
            CredentialAccessMethod::OtpMailLink => 'https://otp.issue334.example.test/mail?opaque=issue334-'.$locale,
            CredentialAccessMethod::AuthToken => 'Issue334-'.$locale.'-auth-token',
        };
        ProductStockItem::create([
            'product_id' => $product->id,
            'order_item_id' => $item->id,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => StructuredCredentialPayload::fromValues(
                $email,
                $password,
                $accessMethod,
                $accessValue,
            )->toArray(),
        ]);

        $sensitiveValues = [
            'email' => $email,
            'password' => $password,
        ];
        if ($accessValue !== null) {
            $sensitiveValues['access_value'] = $accessValue;
        }
        if ($accessMethod === CredentialAccessMethod::OtpMailLink) {
            $sensitiveValues['otp_domain'] = 'otp.issue334.example.test';
        }

        return [$order->fresh(), $sensitiveValues];
    }

    private function paidOrderWithoutCredentials(): Order
    {
        $customer = Customer::factory()->create(['telegram_id' => 24680, 'locale' => 'id']);
        $product = Product::factory()->create(['name' => 'No Credential Product']);
        $order = Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
        ]);
        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        return $order->fresh();
    }

    private function multiChunkPaidOrder(): Order
    {
        $customer = Customer::factory()->create(['telegram_id' => 98765, 'locale' => 'id']);
        $product = Product::factory()->create(['name' => 'Credential Bundle']);
        $order = Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 60,
        ]);
        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        for ($index = 0; $index < 60; $index++) {
            ProductStockItem::create([
                'product_id' => $product->id,
                'order_item_id' => $item->id,
                'secret' => 'buyer'.$index.'@example.test|Password-'.$index.'-'.str_repeat('x', 70).'|MFA-'.$index,
            ]);
        }

        return $order->fresh();
    }

    /** @return array{0: Order, 1: list<string>} */
    private function paidBuyerReceiptOrder(string $credentialPath): array
    {
        if (! in_array($credentialPath, ['structured', 'legacy'], true)) {
            throw new \InvalidArgumentException('Unsupported buyer credential path.');
        }

        $isStructured = $credentialPath === 'structured';
        $telegramId = $isStructured ? 332001 : 332002;
        $tutorial = 'Issue 332 '.ucfirst($credentialPath).' tutorial';
        $recoveryKey = 'Recovery-Key-332-'.$credentialPath;
        $quantity = $isStructured ? 2 : 1;
        $total = $quantity * 10_000;
        $customer = Customer::factory()->create([
            'telegram_id' => $telegramId,
            'locale' => 'id',
        ]);
        $product = Product::factory()->create([
            'name' => 'Issue 332 '.ucfirst($credentialPath).' Bundle',
        ]);
        $order = Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => $total,
        ]);
        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'delivery_note' => $tutorial,
            'delivery_note_snapshotted' => true,
            'quantity' => $quantity,
            'unit_price' => 10_000,
            'subtotal' => $total,
        ]);
        Payment::factory()->for($order)->create([
            'amount' => $total,
            'provider_total_payment' => $total,
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);
        $order->forceFill([
            'credential_access_key_hash' => Hash::make($recoveryKey),
        ])->save();

        if ($isStructured) {
            $otpEmail = 'issue332-otp@example.test';
            $otpPassword = 'Issue332-otp-password';
            $otpUrl = 'https://otp.issue332.example.test/mail?opaque=issue332-otp';
            $authEmail = 'issue332-auth@example.test';
            $authPassword = 'Issue332-auth-password';
            $authToken = 'issue332-auth-token';

            ProductStockItem::create([
                'product_id' => $product->id,
                'order_item_id' => $item->id,
                'credential_format' => CredentialFormat::StructuredAccount,
                'credential_payload' => StructuredCredentialPayload::fromValues(
                    $otpEmail,
                    $otpPassword,
                    CredentialAccessMethod::OtpMailLink,
                    $otpUrl,
                )->toArray(),
            ]);
            ProductStockItem::create([
                'product_id' => $product->id,
                'order_item_id' => $item->id,
                'credential_format' => CredentialFormat::StructuredAccount,
                'credential_payload' => StructuredCredentialPayload::fromValues(
                    $authEmail,
                    $authPassword,
                    CredentialAccessMethod::AuthToken,
                    $authToken,
                )->toArray(),
            ]);

            return [$order->fresh(), [
                $otpEmail,
                $otpPassword,
                $otpUrl,
                'otp.issue332.example.test',
                $authEmail,
                $authPassword,
                $authToken,
                $tutorial,
                $recoveryKey,
            ]];
        }

        $legacyEmail = 'issue332-legacy@example.test';
        $legacyPassword = 'Issue332-legacy-password';
        $legacyMfa = 'Issue332-legacy-mfa';
        $legacySecret = implode('|', [$legacyEmail, $legacyPassword, $legacyMfa]);
        ProductStockItem::create([
            'product_id' => $product->id,
            'order_item_id' => $item->id,
            'secret' => $legacySecret,
        ]);

        return [$order->fresh(), [
            $legacyEmail,
            $legacyPassword,
            $legacyMfa,
            $legacySecret,
            $tutorial,
            $recoveryKey,
        ]];
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidTelegramResponses(): iterable
    {
        yield 'ok false with numeric result' => [['ok' => false, 'result' => ['message_id' => 901]]];
        yield 'zero' => [['ok' => true, 'result' => ['message_id' => 0]]];
        yield 'negative' => [['ok' => true, 'result' => ['message_id' => -1]]];
        yield 'fractional' => [['ok' => true, 'result' => ['message_id' => 1.5]]];
        yield 'empty' => [['ok' => true, 'result' => ['message_id' => '']]];
        yield 'non numeric' => [['ok' => true, 'result' => ['message_id' => 'invalid']]];
    }
}
