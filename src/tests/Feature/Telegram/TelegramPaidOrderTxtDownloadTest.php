<?php

namespace Tests\Feature\Telegram;

use App\Enums\CredentialAccessMethod;
use App\Enums\CredentialFormat;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Jobs\SendTelegramReceiptTxt;
use App\Models\Customer;
use App\Models\ManualFulfillmentReview;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\TelegramOutboundEffect;
use App\Services\Telegram\ReceiptTxtDeliveryPipeline;
use App\Services\Telegram\TelegramOutboundEffectLedgerProtocol;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\Telegram\TelegramPaidOrderTxtExporter;
use App\Services\Telegram\TelegramReceiptActionPanel;
use App\Services\Telegram\TelegramReceiptNotifier;
use App\Services\Telegram\TelegramReceiptTxtConfirmationSession;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TelegramBotTestSupport;

final class TelegramPaidOrderTxtDownloadTest extends TelegramBotTestSupport
{
    public function test_ten_item_mixed_multi_product_export_is_complete_and_deterministic(): void
    {
        [$order, $customer, $sensitive] = $this->mixedTenItemOrder();
        $exporter = app(TelegramPaidOrderTxtExporter::class);

        $first = $exporter->export($order->fresh(), $customer);
        $second = $exporter->export($order->fresh(), $customer);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->documentContents(), $second->documentContents());
        $this->assertSame($first->filename(), $second->filename());
        $this->assertSame('lhie-store-INV-426-MIXED-10.txt', $first->filename());

        $contents = $first->documentContents();
        $this->assertTrue(mb_check_encoding($contents, 'UTF-8'));
        $this->assertStringStartsNotWith("\xEF\xBB\xBF", $contents);
        $this->assertStringNotContainsString("\r", $contents);
        $this->assertSame(strlen($contents), $first->byteLength());
        $this->assertStringContainsString("LHIE STORE\nOrder : INV-426-MIXED-10\nItems : 10", $contents);

        $alphaPosition = strpos($contents, 'Product : Alpha Ten Pack');
        $betaPosition = strpos($contents, 'Product : Beta Legacy Pack');
        $this->assertNotFalse($alphaPosition);
        $this->assertNotFalse($betaPosition);
        $this->assertLessThan($betaPosition, $alphaPosition);

        foreach ([
            'none-one@example.test',
            'none-four@example.test',
            'none-five@example.test',
            'none-six@example.test',
            'none-seven@example.test',
            'otp@example.test',
            'auth@example.test',
            'legacy-one@example.test',
            'legacy-two@example.test',
            'legacy-three@example.test',
        ] as $value) {
            $this->assertSame(1, substr_count($contents, $value));
        }
        $this->assertSecretValuePresent('password : None-one-password', $contents);
        $this->assertStringContainsString('access: OTP Mail Link', $contents);
        $this->assertSecretValuePresent('OTP Mail Link : '.$sensitive['otp_url'], $contents);
        $this->assertStringContainsString('access: Auth Token', $contents);
        $this->assertSecretValuePresent('Auth Token : '.$sensitive['auth_token'], $contents);
        $this->assertSecretValuePresent('MFA : Legacy-one-mfa', $contents);
        $this->assertSame(1, substr_count($contents, $sensitive['otp_url']));
        $this->assertSame(1, substr_count($contents, $sensitive['auth_token']));
        $this->assertSame(2, substr_count($contents, 'Account #1'));
        $this->assertSame(1, substr_count($contents, 'Account #7'));
        $this->assertSame(2, substr_count($contents, 'Account #3'));
        $this->assertSame(1, substr_count($contents, 'Alpha order delivery note'));
        $this->assertSame(1, substr_count($contents, 'Beta product delivery note'));
        $this->assertSame(1, substr_count($contents, 'Alpha setup guide'));
        $this->assertSame(1, substr_count($contents, 'Beta setup guide'));
        $this->assertSame(1, substr_count($contents, 'URL : https://guide.alpha.example.test'));
        $this->assertSame(1, substr_count($contents, 'URL : https://guide.beta.example.test'));

        $this->assertNoSecretValues($contents, ['legacy_one_raw' => $sensitive['legacy_one_raw']]);
    }

    public function test_full_secrets_are_present_only_in_the_document_request(): void
    {
        [$order, , $sensitive] = $this->otpAndAuthOrder();
        config()->set('services.telegram.admin_contact_url', 'https://t.me/lhie_support');
        $logs = $this->captureDogfoodLogs();

        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendDocument' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 426101],
            ]),
        ]);

        $panel = app(TelegramReceiptActionPanel::class)->render($order, 'en', true);
        $this->assertNoSecretValues($panel->text().json_encode($panel->replyMarkup(), JSON_THROW_ON_ERROR), $sensitive);

        $this->postTelegramWebhook(
            $this->callbackUpdate(42611, 'cb-426-txt-request', 'txt:request:'.$order->id, chatId: 426001),
        )->assertOk();

        $this->assertCount(0, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')));
        $this->assertNull(Cache::get('telegram:receipt-txt-confirmation:426001:'.$order->id));

        $effect = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_TXT_DOWNLOAD)
            ->sole();
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state);
        $this->assertNoSecretValues(json_encode($effect->getAttributes(), JSON_THROW_ON_ERROR), $sensitive);
        $this->assertNoSecretValues(serialize(new SendTelegramReceiptTxt($order->id, null, $effect->id)), $sensitive);

        $documentRequest = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendDocument'))->sole()[0];
        $documentContents = (string) $this->telegramRequestField($documentRequest, 'document');
        $this->assertSecretValuePresent($sensitive['otp_email'], $documentContents);
        $this->assertSecretValuePresent($sensitive['auth_email'], $documentContents);
        $this->assertSecretValuePresent($sensitive['otp_url'], $documentContents);
        $this->assertSecretValuePresent($sensitive['auth_token'], $documentContents);
        $this->assertSecretValuePresent($sensitive['otp_password'], $documentContents);
        $this->assertSecretValuePresent($sensitive['auth_password'], $documentContents);
        $this->assertFalse(filter_var($this->telegramRequestField($documentRequest, 'protect_content'), FILTER_VALIDATE_BOOLEAN));
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state);
        $this->assertNoSecretValues(json_encode($effect->fresh()->getAttributes(), JSON_THROW_ON_ERROR), $sensitive);
        $this->assertNoSecretValues(
            json_encode(app(TelegramOutboundEffectService::class)->reviewSummary($effect->fresh()), JSON_THROW_ON_ERROR),
            $sensitive,
        );

        $nonDocumentRequests = Http::recorded(fn ($request): bool => ! str_ends_with($request->url(), '/sendDocument'))
            ->map(fn (array $record): array => $record[0]->data())
            ->all();
        $this->assertNoSecretValues(json_encode($nonDocumentRequests, JSON_THROW_ON_ERROR), $sensitive);
        $this->assertNoSecretValues(json_encode($logs->entries, JSON_THROW_ON_ERROR), $sensitive);
    }

    #[DataProvider('directRequestLocaleCases')]
    public function test_direct_request_feedback_uses_the_stored_customer_locale(
        string $locale,
        string $requestStartedCopy,
    ): void {
        Queue::fake([SendTelegramReceiptTxt::class]);
        $this->fakeTelegramHttp();
        $telegramId = 426300 + array_search($locale, ['id', 'en', 'ms'], true);
        [$order, , $sensitive] = $this->otpAndAuthOrder(oneAccount: true, locale: $locale, telegramId: $telegramId);

        $this->postTelegramWebhook($this->callbackUpdate(
            42621,
            'cb-426-direct-request-'.$locale,
            'txt:request:'.$order->id,
            chatId: $telegramId,
        ))->assertOk();

        $this->assertCount(0, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')));
        $this->assertCount(0, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendDocument')));
        $this->assertCount(1, TelegramOutboundEffect::query()->where(
            'effect_type',
            TelegramOutboundEffectService::TYPE_RECEIPT_TXT_DOWNLOAD,
        )->get());
        Queue::assertPushed(SendTelegramReceiptTxt::class);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && ($request['text'] ?? null) === $requestStartedCopy);
        $this->assertNoSecretValues(
            json_encode(Http::recorded()->map(fn (array $record): array => $record[0]->data())->all(), JSON_THROW_ON_ERROR),
            $sensitive,
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function directRequestLocaleCases(): iterable
    {
        yield 'indonesian' => ['id', '✅ Permintaan diterima. File TXT kamu sedang disiapkan.'];
        yield 'english' => ['en', '✅ Request received. Your TXT file is being prepared.'];
        yield 'malay' => ['ms', '✅ Permintaan diterima. Fail TXT anda sedang disediakan.'];
    }

    public function test_normal_receipt_remains_protected_while_txt_document_is_downloadable(): void
    {
        Queue::fake();
        [$order, $customer, $sensitive] = $this->otpAndAuthOrder(oneAccount: true);
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 426201],
            ]),
            'https://api.telegram.org/*/sendDocument' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 426202],
            ]),
        ]);

        app(TelegramReceiptNotifier::class)->send($order);
        $effect = $this->recordTxtEffect($order, $customer, 'transport-426');
        app(ReceiptTxtDeliveryPipeline::class)->deliver($effect);

        $photo = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto'))->sole()[0];
        $document = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendDocument'))->sole()[0];
        $receiptCaption = (string) $this->telegramRequestField($photo, 'caption');
        $documentContents = (string) $this->telegramRequestField($document, 'document');

        $this->assertTrue(filter_var($this->telegramRequestField($photo, 'protect_content'), FILTER_VALIDATE_BOOLEAN));
        $this->assertFalse(filter_var($this->telegramRequestField($document, 'protect_content'), FILTER_VALIDATE_BOOLEAN));
        $this->assertStringContainsString('otp.issue426.example.test', $receiptCaption);
        $this->assertNoSecretValues($receiptCaption, ['otp_url' => $sensitive['otp_url']]);
        $this->assertSecretValuePresent($sensitive['otp_url'], $documentContents);
        $this->assertTrue($document->isMultipart());
        $this->assertTrue($document->hasFile('document', $documentContents, 'lhie-store-INV-426-OTP.txt'));
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state);
    }

    #[DataProvider('ineligibleOrderCases')]
    public function test_txt_request_rejects_unpaid_refunded_cancelled_and_expired_orders(
        string $case,
        OrderStatus $orderStatus,
        PaymentStatus $orderPaymentStatus,
        PaymentStatus $paymentStatus,
    ): void {
        $this->fakeTelegramHttp();
        [$order] = $this->singleCredentialOrder();
        $order->forceFill([
            'order_status' => $orderStatus,
            'payment_status' => $orderPaymentStatus,
        ])->save();
        $order->payments()->sole()->forceFill([
            'status' => $paymentStatus,
            'paid_at' => $paymentStatus === PaymentStatus::Paid ? now() : null,
        ])->save();

        $this->postTelegramWebhook(
            $this->callbackUpdate(42631, 'cb-426-ineligible-'.$case, 'txt:request:'.$order->id, chatId: 426001),
        )->assertOk();

        $this->assertSame(0, TelegramOutboundEffect::query()->count());
        $this->assertNull(Cache::get('telegram:receipt-txt-confirmation:426001:'.$order->id));
        $this->assertCount(0, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')));
        $this->assertCount(0, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendDocument')));
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && ($request['text'] ?? null) === 'This download is not available for this order right now.');
    }

    /** @return iterable<string, array{string, OrderStatus, PaymentStatus, PaymentStatus}> */
    public static function ineligibleOrderCases(): iterable
    {
        yield 'unpaid' => ['unpaid', OrderStatus::PendingPayment, PaymentStatus::Pending, PaymentStatus::Pending];
        yield 'refunded' => ['refunded', OrderStatus::Completed, PaymentStatus::Refunded, PaymentStatus::Refunded];
        yield 'cancelled' => ['cancelled', OrderStatus::Cancelled, PaymentStatus::Paid, PaymentStatus::Paid];
        yield 'expired' => ['expired', OrderStatus::Expired, PaymentStatus::Paid, PaymentStatus::Paid];
    }

    public function test_txt_callbacks_require_the_exact_customer_and_private_chat(): void
    {
        $this->fakeTelegramHttp();
        [$order] = $this->singleCredentialOrder();
        $other = Customer::factory()->create(['telegram_id' => 426002, 'locale' => 'en']);

        $this->postTelegramWebhook($this->callbackUpdate(
            42641,
            'cb-426-wrong-customer',
            'txt:request:'.$order->id,
            from: ['id' => $other->telegram_id],
            chatId: (int) $other->telegram_id,
        ))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(
            42642,
            'cb-426-wrong-chat',
            'txt:request:'.$order->id,
            from: ['id' => 426001],
            chatId: 426099,
        ))->assertOk();

        $nonce = $this->startConfirmation($order, '426001');
        $this->postTelegramWebhook($this->callbackUpdate(
            42643,
            'cb-426-send-wrong-chat',
            'txt:send:'.$nonce,
            from: ['id' => 426001],
            chatId: 426099,
        ))->assertOk();

        $this->assertSame(0, TelegramOutboundEffect::query()->count());
        $this->assertIsArray(Cache::get('telegram:receipt-txt-confirmation:426001:'.$order->id));
        $this->assertCount(0, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')));
        $this->assertCount(0, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendDocument')));
    }

    public function test_open_late_payment_review_blocks_request_without_exposing_order_state(): void
    {
        $this->fakeTelegramHttp();
        [$order, $customer] = $this->singleCredentialOrder();
        $payment = $order->payments()->sole();
        ManualFulfillmentReview::create([
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'amount' => 10_000,
            'currency' => 'IDR',
            'reason' => ManualFulfillmentReview::REASON_LATE_PAYMENT,
            'state' => ManualFulfillmentReview::STATE_PENDING,
        ]);

        $this->postTelegramWebhook(
            $this->callbackUpdate(42651, 'cb-426-late-review', 'txt:request:'.$order->id, chatId: (int) $customer->telegram_id),
        )->assertOk();

        $this->assertSame(0, TelegramOutboundEffect::query()->count());
        $this->assertNull(Cache::get('telegram:receipt-txt-confirmation:426001:'.$order->id));
        $this->assertCount(0, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')));
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && ($request['text'] ?? null) === 'This download is not available for this order right now.');
    }

    public function test_confirmation_is_expiring_single_use_and_cancel_is_non_destructive(): void
    {
        $this->fakeTelegramHttp();
        [$order, $customer] = $this->singleCredentialOrder();
        $session = app(TelegramReceiptTxtConfirmationSession::class);

        $expired = $session->start($customer, '426001', (string) $order->id, 'en');
        Cache::put(
            'telegram:receipt-txt-confirmation:426001:'.$order->id,
            $expired,
            now()->subMinute(),
        );
        $this->postTelegramWebhook($this->callbackUpdate(
            42661,
            'cb-426-expired',
            'txt:send:'.$expired['nonce'],
            chatId: 426001,
        ))->assertOk();

        $cancel = $session->start($customer, '426001', (string) $order->id, 'en');
        $this->postTelegramWebhook($this->callbackUpdate(
            42662,
            'cb-426-cancel',
            'txt:cancel:'.$cancel['nonce'],
            chatId: 426001,
        ))->assertOk();
        $this->assertNull(Cache::get('telegram:receipt-txt-confirmation:426001:'.$order->id));

        $send = $session->start($customer, '426001', (string) $order->id, 'en');
        $this->postTelegramWebhook($this->callbackUpdate(
            42663,
            'cb-426-send',
            'txt:send:'.$send['nonce'],
            chatId: 426001,
        ))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(
            42664,
            'cb-426-send-replay',
            'txt:send:'.$send['nonce'],
            chatId: 426001,
        ))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(
            42665,
            'cb-426-malformed',
            'txt:send:'.str_repeat('z', 32),
            chatId: 426001,
        ))->assertOk();

        $this->assertSame(1, TelegramOutboundEffect::query()->count());
        $this->assertCount(1, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendDocument')));
        $this->assertCount(0, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')));
    }

    public function test_no_credential_projection_omits_txt_and_never_sends_an_empty_document(): void
    {
        config()->set('services.telegram.admin_contact_url', 'https://t.me/lhie_support');
        $this->fakeTelegramHttp();
        [$order] = $this->paidOrderWithoutCredentials();
        $panel = app(TelegramReceiptActionPanel::class)->render($order, 'en', false);
        $rows = $panel->replyMarkup()['inline_keyboard'] ?? [];

        $this->assertCount(3, $rows);
        $this->assertFalse(collect($rows)->flatten(1)->contains(
            fn (array $button): bool => ($button['callback_data'] ?? null) === 'txt:request:'.$order->id,
        ));
        $this->assertFalse(collect($rows)->flatten(1)->contains(
            fn (array $button): bool => str_contains(strtolower((string) ($button['text'] ?? '')), 'excel'),
        ));

        $this->postTelegramWebhook($this->callbackUpdate(
            42671,
            'cb-426-no-credentials',
            'txt:request:'.$order->id,
            chatId: 426001,
        ))->assertOk();

        $this->assertSame(0, TelegramOutboundEffect::query()->count());
        $this->assertCount(0, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')));
        $this->assertCount(0, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendDocument')));
    }

    #[DataProvider('definiteDocumentRejectionStatuses')]
    public function test_definite_document_rejection_is_terminal_and_secret_free(int $status): void
    {
        [$order, $customer, $sensitive] = $this->otpAndAuthOrder(oneAccount: true);
        $effect = $this->recordTxtEffect($order, $customer, 'terminal-426');
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendDocument' => Http::response([
                'ok' => false,
                'description' => 'Bad Request: document rejected',
            ], $status),
        ]);

        app(ReceiptTxtDeliveryPipeline::class)->deliver($effect);

        $failed = $effect->fresh();
        $this->assertSame(TelegramOutboundEffectState::Failed, $failed->state);
        $this->assertSame('telegram_http_'.$status, $failed->last_error_code);
        $this->assertNull($failed->next_attempt_at);
        $this->assertNoSecretValues(json_encode($failed->getAttributes(), JSON_THROW_ON_ERROR), $sensitive);
        $this->assertCount(1, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendDocument')));
    }

    /** @return iterable<string, array{int}> */
    public static function definiteDocumentRejectionStatuses(): iterable
    {
        yield 'bad request' => [400];
        yield 'forbidden' => [403];
    }

    public function test_ambiguous_document_delivery_requires_review_and_is_not_resent(): void
    {
        [$order, $customer] = $this->singleCredentialOrder();
        $effect = $this->recordTxtEffect($order, $customer, 'unknown-426');
        $sendCount = 0;
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendDocument' => function () use (&$sendCount): never {
                $sendCount++;
                throw new ConnectionException('connection closed after Telegram accepted the request');
            },
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        app(ReceiptTxtDeliveryPipeline::class)->deliver($effect);
        $unknown = $effect->fresh();
        $this->assertSame(TelegramOutboundEffectState::Unknown, $unknown->state);
        $operatorResolved = app(TelegramOutboundEffectLedgerProtocol::class)->finalizeOperatorSent(
            (string) $effect->id,
            '426901',
        );
        app(ReceiptTxtDeliveryPipeline::class)->deliver($effect->fresh());

        $resolved = $effect->fresh();
        $this->assertSame(TelegramOutboundEffectState::Sent, $operatorResolved?->state);
        $this->assertNotNull($operatorResolved?->manual_review_at);
        $this->assertSame(TelegramOutboundEffectState::Sent, $resolved->state);
        $this->assertSame(1, $sendCount);
    }

    public function test_pending_effect_is_recovered_by_effect_id_without_rendering_in_the_callback(): void
    {
        Queue::fake();
        [$order, $customer] = $this->singleCredentialOrder();
        $effect = $this->recordTxtEffect($order, $customer, 'recover-426');

        Artisan::call('telegram:recover-outbound', ['--chunk' => 100]);

        Queue::assertPushed(
            SendTelegramReceiptTxt::class,
            fn (SendTelegramReceiptTxt $job): bool => $job->orderId === (string) $order->id
                && $job->effectId === (string) $effect->id,
        );
    }

    /** @return array{0: Order, 1: Customer, 2: array<string, string>} */
    private function mixedTenItemOrder(): array
    {
        $customer = Customer::factory()->create(['telegram_id' => 426001, 'locale' => 'en']);
        $alpha = Product::factory()->create(['name' => 'Alpha Ten Pack']);
        $beta = Product::factory()->create(['name' => 'Beta Legacy Pack', 'delivery_note' => 'Beta product delivery note', 'usage_guide_title' => 'Beta setup guide', 'usage_guide_url' => 'https://guide.beta.example.test']);
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-426-MIXED-10',
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $alphaItem = OrderItem::factory()->for($order)->create([
            'product_id' => $alpha->id,
            'product_name' => $alpha->name,
            'delivery_note' => 'Alpha order delivery note',
            'delivery_note_snapshotted' => true,
            'usage_guide_title' => 'Alpha setup guide',
            'usage_guide_url' => 'https://guide.alpha.example.test',
            'usage_guide_snapshotted' => true,
            'quantity' => 7,
        ]);
        $betaItem = OrderItem::factory()->for($order)->create([
            'product_id' => $beta->id,
            'product_name' => $beta->name,
            'quantity' => 3,
        ]);
        Payment::factory()->for($order)->create(['status' => PaymentStatus::Paid, 'paid_at' => now()]);

        $accounts = [
            ['none-one@example.test', 'None-one-password', CredentialAccessMethod::None, null],
            ['otp@example.test', 'Otp-password', CredentialAccessMethod::OtpMailLink, 'https://otp.issue426.example.test/mail?opaque=full-otp-426'],
            ['auth@example.test', 'Auth-password', CredentialAccessMethod::AuthToken, 'auth-token-426'],
            ['none-four@example.test', 'None-four-password', CredentialAccessMethod::None, null],
            ['none-five@example.test', 'None-five-password', CredentialAccessMethod::None, null],
            ['none-six@example.test', 'None-six-password', CredentialAccessMethod::None, null],
            ['none-seven@example.test', 'None-seven-password', CredentialAccessMethod::None, null],
        ];
        foreach ($accounts as [$email, $password, $accessMethod, $accessValue]) {
            ProductStockItem::create([
                'product_id' => $alpha->id,
                'order_item_id' => $alphaItem->id,
                'credential_format' => CredentialFormat::StructuredAccount,
                'credential_payload' => $this->structured($email, $password, $accessMethod, $accessValue),
            ]);
        }

        $legacyOneRaw = 'legacy-one@example.test|Legacy-one-password|Legacy-one-mfa';
        foreach ([
            $legacyOneRaw,
            'legacy-two@example.test|Legacy-two-password',
            'legacy-three@example.test|Legacy-three-password|Legacy-three-mfa',
        ] as $secret) {
            ProductStockItem::create([
                'product_id' => $beta->id,
                'order_item_id' => $betaItem->id,
                'secret' => $secret,
            ]);
        }

        return [$order->fresh(), $customer, [
            'otp_url' => 'https://otp.issue426.example.test/mail?opaque=full-otp-426',
            'auth_token' => 'auth-token-426',
            'legacy_one_raw' => $legacyOneRaw,
        ]];
    }

    /** @return array{0: Order, 1: Customer, 2: array<string, string>} */
    private function otpAndAuthOrder(bool $oneAccount = false, string $locale = 'en', int $telegramId = 426001): array
    {
        $customer = Customer::factory()->create(['telegram_id' => $telegramId, 'locale' => $locale]);
        $product = Product::factory()->create(['name' => 'OTP and Auth Product']);
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => $oneAccount ? 'INV-426-OTP' : 'INV-426-SECRETS',
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => $oneAccount ? 1 : 2,
        ]);
        Payment::factory()->for($order)->create(['status' => PaymentStatus::Paid, 'paid_at' => now()]);

        $otpUrl = 'https://otp.issue426.example.test/mail?opaque=full-otp-426';
        ProductStockItem::create([
            'product_id' => $product->id,
            'order_item_id' => $item->id,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => $this->structured(
                'otp@example.test',
                'Otp-password-426',
                CredentialAccessMethod::OtpMailLink,
                $otpUrl,
            ),
        ]);

        if (! $oneAccount) {
            ProductStockItem::create([
                'product_id' => $product->id,
                'order_item_id' => $item->id,
                'credential_format' => CredentialFormat::StructuredAccount,
                'credential_payload' => $this->structured(
                    'auth@example.test',
                    'Auth-password-426',
                    CredentialAccessMethod::AuthToken,
                    'auth-token-426',
                ),
            ]);
        }

        return [$order->fresh(), $customer, [
            'otp_email' => 'otp@example.test',
            'otp_url' => $otpUrl,
            'otp_password' => 'Otp-password-426',
            'auth_email' => 'auth@example.test',
            'auth_token' => 'auth-token-426',
            'auth_password' => 'Auth-password-426',
        ]];
    }

    /** @return array{0: Order, 1: Customer} */
    private function singleCredentialOrder(): array
    {
        [$order, $customer] = $this->otpAndAuthOrder(oneAccount: true);

        return [$order, $customer];
    }

    /** @return array{0: Order} */
    private function paidOrderWithoutCredentials(): array
    {
        $customer = Customer::factory()->create(['telegram_id' => 426001, 'locale' => 'en']);
        $product = Product::factory()->create(['name' => 'No Credential Product']);
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-426-NONE',
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
        ]);
        Payment::factory()->for($order)->create(['status' => PaymentStatus::Paid, 'paid_at' => now()]);

        return [$order->fresh()];
    }

    private function recordTxtEffect(Order $order, Customer $customer, string $nonce): TelegramOutboundEffect
    {
        return app(TelegramOutboundEffectService::class)->recordReceiptTxtDownload(
            $order,
            $nonce,
            $customer,
        ) ?? throw new InvalidArgumentException('Expected a TXT download effect.');
    }

    private function startConfirmation(Order $order, string $chatId): string
    {
        $confirmation = app(TelegramReceiptTxtConfirmationSession::class)->start(
            $order->customer,
            $chatId,
            (string) $order->id,
            'en',
        );

        return (string) $confirmation['nonce'];
    }

    /** @return array<string, mixed> */
    private function structured(
        string $email,
        string $password,
        CredentialAccessMethod $accessMethod,
        ?string $accessValue = null,
    ): array {
        return [
            'version' => 1,
            'email' => $email,
            'password' => $password,
            'access_method' => $accessMethod->value,
            'access_value' => $accessValue,
            'tutorial_override' => null,
        ];
    }

    /** @return object{entries: list<array{string, array<string, mixed>}>} */
    private function captureDogfoodLogs(): object
    {
        config()->set('services.dogfood_timing.enabled', true);
        $logs = (object) ['entries' => []];

        Log::shouldReceive('info')->zeroOrMoreTimes()->withArgs(function (string $event, array $context = []) use ($logs): bool {
            $logs->entries[] = [$event, $context];

            return true;
        });
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        return $logs;
    }

    /** @param array<string, string> $sensitive */
    private function assertNoSecretValues(string $haystack, array $sensitive): void
    {
        foreach (array_unique(array_values($sensitive)) as $secret) {
            $this->assertFalse(str_contains($haystack, $secret));
        }
    }

    private function assertSecretValuePresent(string $secret, string $haystack): void
    {
        $this->assertTrue(str_contains($haystack, $secret));
    }
}
