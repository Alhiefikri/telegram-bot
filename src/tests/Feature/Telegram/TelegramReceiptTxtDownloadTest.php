<?php

namespace Tests\Feature\Telegram;

use App\Enums\CredentialAccessMethod;
use App\Enums\CredentialFormat;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Jobs\SendTelegramReceiptTxt;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\TelegramOutboundEffect;
use App\Services\Telegram\ReceiptTxtDeliveryPipeline;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\Support\TelegramBotTestSupport;

final class TelegramReceiptTxtDownloadTest extends TelegramBotTestSupport
{
    public function test_confirmed_request_materializes_one_safe_idempotent_effect_and_dispatches_it(): void
    {
        Queue::fake();
        [$order, $customer] = $this->paidOrder();

        $effect = app(TelegramOutboundEffectService::class)->recordReceiptTxtDownload(
            $order,
            'confirmation-422',
            $customer,
            [
                'origin_message_id' => 422001,
                'correlation_id' => 'correlation-422',
            ],
        );
        $sameEffect = app(TelegramOutboundEffectService::class)->recordReceiptTxtDownload(
            $order->fresh(),
            'confirmation-422',
            $customer->fresh(),
        );

        $this->assertNotNull($effect);
        $this->assertSame($effect->id, $sameEffect?->id);
        $this->assertDatabaseCount('telegram_outbound_effects', 1);
        $this->assertSame(
            'order:'.$order->id.':telegram_txt_download:confirmation-422:v1',
            $effect->effect_key,
        );
        $this->assertSame(TelegramOutboundEffectService::TYPE_RECEIPT_TXT_DOWNLOAD, $effect->effect_type);
        $this->assertSame(TelegramOutboundEffectService::TEMPLATE_RECEIPT_TXT_DOWNLOAD, $effect->template_version);
        $this->assertSame('notifications', $effect->queue);
        $this->assertSame('sendDocument', $effect->telegram_method);
        $this->assertSame(1, $effect->outcome_metadata['product_count']);
        $this->assertSame(1, $effect->outcome_metadata['item_count']);
        $this->assertSame('confirmation-422', $effect->outcome_metadata['confirmation_nonce']);
        $this->assertStringNotContainsString(
            'Txt-password',
            json_encode($effect->getAttributes(), JSON_THROW_ON_ERROR),
        );

        $serializedJob = serialize(new SendTelegramReceiptTxt($order->id, null, $effect->id));
        $this->assertStringNotContainsString('Txt-password', $serializedJob);

        app(TelegramOutboundEffectDispatcher::class)->dispatch($effect);

        Queue::assertPushedOn(
            'notifications',
            SendTelegramReceiptTxt::class,
            fn (SendTelegramReceiptTxt $job): bool => $job->orderId === $order->id
                && $job->effectId === $effect->id,
        );
    }

    public function test_metadata_boundary_rejects_credential_bearing_values(): void
    {
        [$order, $customer] = $this->paidOrder();

        try {
            app(TelegramOutboundEffectService::class)->recordReceiptTxtDownload(
                $order,
                'unsafe-confirmation',
                $customer,
                ['password' => 'Txt-password'],
            );
            $this->fail('Credential-bearing TXT metadata must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('TXT download metadata is not safe.', $exception->getMessage());
        }

        $this->assertDatabaseCount('telegram_outbound_effects', 0);
    }

    public function test_pipeline_renders_and_sends_a_transient_unprotected_document(): void
    {
        $otpUrl = 'https://mail.example.test/otp?opaque=full-otp-value';
        [$order, $customer] = $this->paidOrder(
            CredentialFormat::StructuredAccount,
            $this->structured(
                'otp@example.test',
                'Otp-password',
                CredentialAccessMethod::OtpMailLink,
                $otpUrl,
            ),
        );
        $effect = $this->txtEffect($order, $customer, 'document-confirmation');
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendDocument' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 422101],
            ]),
        ]);

        app(ReceiptTxtDeliveryPipeline::class)->deliver($effect);

        $sent = $effect->fresh();
        $this->assertSame(TelegramOutboundEffectState::Sent, $sent->state);
        $this->assertSame('422101', $sent->telegram_message_id);
        $this->assertSame('sendDocument', $sent->outcome_metadata['delivery_method']);
        $this->assertArrayHasKey('byte_size_bucket', $sent->outcome_metadata);

        $requests = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendDocument'));
        $this->assertCount(1, $requests);
        $request = $requests->sole()[0];
        $contents = (string) $this->telegramRequestField($request, 'document');
        $this->assertStringContainsString('Otp-password', $contents);
        $this->assertStringContainsString($otpUrl, $contents);
        $this->assertSame((string) $customer->telegram_id, (string) $this->telegramRequestField($request, 'chat_id'));
        $this->assertFalse(filter_var($this->telegramRequestField($request, 'protect_content'), FILTER_VALIDATE_BOOLEAN));
        $this->assertTrue($request->hasFile('document', $contents, 'lhie-store-INV-422-TXT.txt'));
        $this->assertStringNotContainsString($otpUrl, json_encode($sent->getAttributes(), JSON_THROW_ON_ERROR));
    }

    public function test_ambiguous_document_delivery_becomes_unknown_without_automatic_resend(): void
    {
        [$order, $customer] = $this->paidOrder();
        $effect = $this->txtEffect($order, $customer, 'ambiguous-confirmation');
        $sendCount = 0;
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendDocument' => function () use (&$sendCount): never {
                $sendCount++;
                throw new ConnectionException('connection closed after Telegram accepted the request');
            },
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $pipeline = app(ReceiptTxtDeliveryPipeline::class);
        $pipeline->deliver($effect);
        $pipeline->deliver($effect->fresh());

        $unknown = $effect->fresh();
        $this->assertSame(TelegramOutboundEffectState::Unknown, $unknown->state);
        $this->assertSame('telegram_transport_ambiguous', $unknown->last_error_code);
        $this->assertNull($unknown->next_attempt_at);
        $this->assertSame(1, $sendCount);
        $this->assertStringNotContainsString('Txt-password', json_encode($unknown->getAttributes(), JSON_THROW_ON_ERROR));
    }

    public function test_retryable_rate_limit_uses_the_shared_bounded_retry_schedule(): void
    {
        [$order, $customer] = $this->paidOrder();
        $effect = $this->txtEffect($order, $customer, 'rate-limit-confirmation');
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendDocument' => Http::response([
                'ok' => false,
                'description' => 'Too Many Requests',
                'parameters' => ['retry_after' => 7],
            ], 429),
        ]);

        app(ReceiptTxtDeliveryPipeline::class)->deliver($effect);

        $failed = $effect->fresh();
        $this->assertSame(TelegramOutboundEffectState::Failed, $failed->state);
        $this->assertSame('telegram_http_429', $failed->last_error_code);
        $this->assertNotNull($failed->next_attempt_at);
        $this->assertNull($failed->telegram_message_id);
    }

    public function test_malformed_success_response_fails_without_persisting_document_content(): void
    {
        [$order, $customer] = $this->paidOrder();
        $effect = $this->txtEffect($order, $customer, 'malformed-response-confirmation');
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendDocument' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 0],
            ]),
        ]);

        app(ReceiptTxtDeliveryPipeline::class)->deliver($effect);

        $failed = $effect->fresh();
        $this->assertSame(TelegramOutboundEffectState::Failed, $failed->state);
        $this->assertSame('telegram_response_missing_message_id', $failed->last_error_code);
        $this->assertNotNull($failed->next_attempt_at);
        $this->assertStringNotContainsString('Txt-password', json_encode($failed->getAttributes(), JSON_THROW_ON_ERROR));
    }

    public function test_renderer_failure_is_terminal_and_never_sends_a_partial_document(): void
    {
        [$order, $customer, $item] = $this->paidOrderWithoutCredential();
        $secret = 'malformed-password';
        ProductStockItem::withoutEvents(static fn () => ProductStockItem::create([
            'product_id' => $item->product_id,
            'order_item_id' => $item->id,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => [
                'version' => 1,
                'email' => 'malformed@example.test',
                'password' => $secret,
                'access_method' => CredentialAccessMethod::OtpMailLink->value,
                'access_value' => 'http://not-https.example.test/otp',
                'tutorial_override' => null,
            ],
        ]));
        $effect = $this->txtEffect($order, $customer, 'renderer-failure-confirmation');
        config()->set('services.telegram.admin_contact_url', 'https://t.me/lhie_support');
        $this->fakeTelegramHttp();

        app(ReceiptTxtDeliveryPipeline::class)->deliver($effect);

        $failed = $effect->fresh();
        $this->assertSame(TelegramOutboundEffectState::Failed, $failed->state);
        $this->assertSame('receipt_txt_render_failed', $failed->last_error_code);
        $this->assertNull($failed->next_attempt_at);
        $this->assertNull($failed->telegram_message_id);
        $this->assertStringNotContainsString($secret, json_encode($failed->getAttributes(), JSON_THROW_ON_ERROR));
        $this->assertCount(0, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendDocument')));
        $notice = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage'))->sole()[0];
        $this->assertStringContainsString('could not be prepared safely', (string) ($notice['text'] ?? ''));
        $this->assertSame(
            'https://t.me/lhie_support',
            data_get($notice->data(), 'reply_markup.inline_keyboard.0.0.url'),
        );
        $this->assertStringNotContainsString($secret, json_encode($notice->data(), JSON_THROW_ON_ERROR));

    }

    public function test_successful_document_delivery_clears_confirmation_buttons_best_effort(): void
    {
        [$order, $customer] = $this->paidOrder();
        $effect = $this->txtEffect($order, $customer, 'cleanup-success-confirmation', 422401);
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendDocument' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 422402],
            ]),
            'https://api.telegram.org/*/editMessageReplyMarkup' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 422401],
            ]),
        ]);

        app(ReceiptTxtDeliveryPipeline::class)->deliver($effect);

        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state);
        $cleanup = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/editMessageReplyMarkup'))->sole()[0];
        $this->assertSame('422001', (string) ($cleanup['chat_id'] ?? ''));
        $this->assertSame(422401, (int) ($cleanup['message_id'] ?? 0));
        $this->assertSame(['inline_keyboard' => []], $cleanup['reply_markup'] ?? null);
    }

    public function test_confirmation_cleanup_failure_does_not_change_sent_delivery_state(): void
    {
        [$order, $customer] = $this->paidOrder();
        $effect = $this->txtEffect($order, $customer, 'cleanup-failure-confirmation', 422403);
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendDocument' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 422404],
            ]),
            'https://api.telegram.org/*/editMessageReplyMarkup' => Http::response([
                'ok' => false,
                'description' => 'Bad Request: message to edit not found',
            ], 400),
        ]);

        app(ReceiptTxtDeliveryPipeline::class)->deliver($effect);

        $sent = $effect->fresh();
        $this->assertSame(TelegramOutboundEffectState::Sent, $sent->state);
        $this->assertSame('422404', (string) $sent->telegram_message_id);
        $this->assertNull($sent->last_error_code);
        $this->assertCount(1, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/editMessageReplyMarkup')));
    }

    public function test_recovery_redispatches_a_committed_pending_txt_effect_by_effect_id(): void
    {
        Queue::fake();
        [$order, $customer] = $this->paidOrder();
        $effect = $this->txtEffect($order, $customer, 'recovery-confirmation');

        Artisan::call('telegram:recover-outbound', ['--chunk' => 100]);

        Queue::assertPushed(
            SendTelegramReceiptTxt::class,
            fn (SendTelegramReceiptTxt $job): bool => $job->orderId === $order->id
                && $job->effectId === $effect->id,
        );
    }

    /** @return array{0: Order, 1: Customer} */
    private function paidOrder(
        ?CredentialFormat $format = null,
        ?array $payload = null,
    ): array {
        [$order, $customer, $item] = $this->paidOrderWithoutCredential();

        if ($format === null) {
            ProductStockItem::create([
                'product_id' => $item->product_id,
                'order_item_id' => $item->id,
                'secret' => 'txt@example.test|Txt-password|Txt-mfa',
            ]);
        } else {
            ProductStockItem::create([
                'product_id' => $item->product_id,
                'order_item_id' => $item->id,
                'credential_format' => $format,
                'credential_payload' => $payload,
            ]);
        }

        return [$order, $customer];
    }

    /** @return array{0: Order, 1: Customer, 2: OrderItem} */
    private function paidOrderWithoutCredential(): array
    {
        $customer = Customer::factory()->create(['telegram_id' => 422001, 'locale' => 'en']);
        $product = Product::factory()->create(['name' => 'TXT Delivery Product']);
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-422-TXT',
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
        ]);
        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        return [$order, $customer, $item];
    }

    private function txtEffect(
        Order $order,
        Customer $customer,
        string $nonce,
        ?int $originMessageId = null,
    ): TelegramOutboundEffect {
        $metadata = $originMessageId === null ? [] : ['origin_message_id' => $originMessageId];
        $effect = app(TelegramOutboundEffectService::class)->recordReceiptTxtDownload(
            $order,
            $nonce,
            $customer,
            $metadata,
        );

        return $effect ?? throw new InvalidArgumentException('Expected a TXT download effect.');
    }

    /** @return array<string, mixed> */
    private function structured(
        string $email,
        string $password,
        CredentialAccessMethod $accessMethod,
        ?string $accessValue = null,
        ?string $tutorial = null,
    ): array {
        return [
            'version' => 1,
            'email' => $email,
            'password' => $password,
            'access_method' => $accessMethod->value,
            'access_value' => $accessValue,
            'tutorial_override' => $tutorial,
        ];
    }
}
