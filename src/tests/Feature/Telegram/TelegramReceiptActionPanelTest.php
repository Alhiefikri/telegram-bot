<?php

namespace Tests\Feature\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Jobs\SendTelegramReceiptActionPanel;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\TelegramOutboundEffect;
use App\Models\TelegramUiIcon;
use App\Models\User;
use App\Services\Telegram\CustomEmojiCapability;
use App\Services\Telegram\ReceiptActionPanelDeliveryPipeline;
use App\Services\Telegram\ReceiptDeliveryPipeline;
use App\Services\Telegram\TelegramLocale;
use App\Services\Telegram\TelegramOutboundEffectLedgerProtocol;
use App\Services\Telegram\TelegramOutboundEffectPolicy;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\Telegram\TelegramOutboundResponseValidator;
use App\Services\Telegram\TelegramPaidOrderTxtExporter;
use App\Services\Telegram\TelegramReceiptActionPanel;
use App\Services\Telegram\TelegramReceiptNotifier;
use App\Storefront\StorefrontInvoiceUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\TelegramBotTestSupport;

final class TelegramReceiptActionPanelTest extends TelegramBotTestSupport
{
    public function test_completed_new_receipt_materializes_one_panel_effect_after_the_receipt(): void
    {
        Queue::fake();
        config()->set('services.telegram.admin_contact_url', 'https://t.me/lhie_support');
        [$order, $secret] = $this->paidOrder(withCredential: true, locale: 'id');
        $receipt = app(TelegramOutboundEffectService::class)->recordReceipt($order);
        $this->assertNotNull($receipt);

        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 424401],
            ]),
        ]);

        app(ReceiptDeliveryPipeline::class)->deliver($receipt, dispatchNext: false);

        $panel = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_ORDER_ACTIONS)
            ->sole();
        $this->assertSame(TelegramOutboundEffectState::Pending, $panel->state);
        $this->assertSame('sendMessage', $panel->telegram_method);
        $this->assertSame('receipt-actions-v1', $panel->template_version);
        $this->assertSame(true, $panel->outcome_metadata['txt_available']);
        $this->assertSame(4, $panel->outcome_metadata['action_count']);
        $this->assertStringNotContainsString($secret, json_encode($panel->getAttributes(), JSON_THROW_ON_ERROR));
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);

        Queue::assertPushedOn(
            'notifications',
            SendTelegramReceiptActionPanel::class,
            fn (SendTelegramReceiptActionPanel $job): bool => $job->orderId === (string) $order->id
                && $job->effectId === (string) $panel->id,
        );

        $photo = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto'))->sole()[0];
        $this->assertEmpty($this->telegramRequestField($photo, 'reply_markup'));
        $this->assertCount(0, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')));
        $this->assertStringNotContainsString('Simpan data ini', (string) $this->telegramRequestField($photo, 'caption'));
        app(ReceiptActionPanelDeliveryPipeline::class)->deliver($panel);
        $message = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage'))->sole()[0];
        $this->assertStringContainsString('Simpan data ini', (string) $message['text']);
        $this->assertStringContainsString('Terima kasih sudah belanja', (string) $message['text']);
        $this->assertStringNotContainsString($secret, (string) $message['text']);
    }

    public function test_action_panel_readiness_is_delegated_to_the_ledger_completion_seam(): void
    {
        Queue::fake();
        [$order] = $this->paidOrder(withCredential: true, locale: 'en');
        $receipt = app(TelegramOutboundEffectService::class)->recordReceipt($order);
        $this->assertNotNull($receipt);

        $receipt->forceFill([
            'state' => TelegramOutboundEffectState::Pending,
            'telegram_message_id' => null,
            'sent_at' => null,
        ])->save();
        $order->forceFill(['telegram_receipt_sent_at' => now()])->save();

        $ledger = Mockery::mock(TelegramOutboundEffectLedgerProtocol::class, [
            app(TelegramOutboundResponseValidator::class),
            app(TelegramOutboundEffectPolicy::class),
        ])->makePartial();
        $ledger->shouldReceive('isReceiptGroupComplete')
            ->twice()
            ->with((string) $receipt->effect_group_key)
            ->andReturn(true);
        $this->app->instance(TelegramOutboundEffectLedgerProtocol::class, $ledger);

        $panel = app(TelegramOutboundEffectService::class)->recordReceiptActionPanel($receipt->fresh());
        $this->assertNotNull($panel);

        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 424410],
            ]),
        ]);
        app(ReceiptActionPanelDeliveryPipeline::class)->deliver($panel);

        $this->assertSame(TelegramOutboundEffectState::Sent, $panel->fresh()->state);
        $this->assertCount(
            1,
            Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')),
        );
    }

    public function test_panel_presentation_is_localized_and_uses_one_full_width_row_per_action(): void
    {
        config()->set('services.telegram.admin_contact_url', 'https://t.me/lhie_support');
        [$order, $secret] = $this->paidOrder(withCredential: true, locale: 'ms');

        $unit = app(TelegramReceiptActionPanel::class)->render($order, 'ms', true);
        $rows = $unit->replyMarkup()['inline_keyboard'] ?? [];

        $this->assertCount(4, $rows);
        $this->assertSame('🛟 Perlukan bantuan dengan pesanan ini? Tekan Sokongan di bawah dan sertakan Order ID supaya kami boleh membantu.', $unit->text());
        $this->assertSame('txt:request:'.$order->id, $rows[0][0]['callback_data']);
        $this->assertSame('https://t.me/lhie_support', $rows[1][0]['url']);
        $this->assertSame(TelegramReceiptActionPanel::BUY_AGAIN_CALLBACK_PREFIX.$order->id, $rows[2][0]['callback_data']);
        $this->assertSame(TelegramReceiptActionPanel::MAIN_MENU_CALLBACK_PREFIX.$order->id, $rows[3][0]['callback_data']);
        foreach ($rows as $row) {
            $this->assertCount(1, $row);
        }
        $labels = collect($rows)->flatten(1)->pluck('text')->all();
        $this->assertNotContains('Excel', $labels);
        $this->assertStringNotContainsString($secret, $unit->text().json_encode($unit->replyMarkup(), JSON_THROW_ON_ERROR));
    }

    #[DataProvider('panelLocaleCases')]
    public function test_panel_uses_the_stored_customer_locale_for_every_supported_locale(
        string $locale,
        string $message,
        string $supportLabel,
        string $buyAgainLabel,
        string $mainMenuLabel,
    ): void {
        config()->set('services.telegram.admin_contact_url', 'https://t.me/lhie_support');
        [$order] = $this->paidOrder(withCredential: true, locale: $locale, telegramId: 424100 + array_search($locale, ['id', 'en', 'ms'], true));

        $unit = app(TelegramReceiptActionPanel::class)->render($order, $order->customer->locale, true);
        $rows = $unit->replyMarkup()['inline_keyboard'] ?? [];
        $labels = collect($rows)->flatten(1)->pluck('text')->all();

        $this->assertSame($message, $unit->text());
        $this->assertSame(['⬇️ TXT', $supportLabel, $buyAgainLabel, $mainMenuLabel], $labels);
        $this->assertFalse(collect($labels)->contains(fn (string $label): bool => str_contains(strtolower($label), 'excel')));
    }

    /** @return iterable<string, array{string, string, string, string, string}> */
    public static function panelLocaleCases(): iterable
    {
        yield 'indonesian' => [
            'id',
            '🛟 Butuh bantuan dengan pesanan ini? Tekan Support di bawah dan sertakan Order ID agar kami dapat membantu.',
            '🛟 Support',
            '🎁 Beli Lagi',
            '📦 Menu Utama',
        ];
        yield 'english' => [
            'en',
            '🛟 Need help with this order? Tap Support below and include your Order ID so we can help.',
            '🛟 Support',
            '🎁 Buy Again',
            '📦 Main Menu',
        ];
        yield 'malay' => [
            'ms',
            '🛟 Perlukan bantuan dengan pesanan ini? Tekan Sokongan di bawah dan sertakan Order ID supaya kami boleh membantu.',
            '🛟 Sokongan',
            '🎁 Beli Lagi',
            '📦 Menu Utama',
        ];
    }

    public function test_panel_omits_txt_when_the_completed_receipt_has_no_deliverable_credentials(): void
    {
        Queue::fake();
        config()->set('services.telegram.admin_contact_url', 'https://t.me/lhie_support');
        [$order] = $this->paidOrder(withCredential: false, locale: 'en');
        $receipt = app(TelegramOutboundEffectService::class)->recordReceipt($order);
        $this->assertNotNull($receipt);

        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 424402],
            ]),
        ]);
        app(ReceiptDeliveryPipeline::class)->deliver($receipt, dispatchNext: false);

        $panel = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_ORDER_ACTIONS)
            ->sole();
        $this->assertFalse($panel->outcome_metadata['txt_available']);
        $rows = app(TelegramReceiptActionPanel::class)->render($order, 'en', false)->replyMarkup()['inline_keyboard'];
        $this->assertCount(3, $rows);
        $this->assertFalse(collect($rows)->flatten(1)->contains(fn ($button): bool => ($button['callback_data'] ?? null) === 'txt:request:'.$order->id));
    }

    public function test_action_panel_worker_sends_a_separate_unprotected_message_and_finalizes_the_effect(): void
    {
        Queue::fake();
        config()->set('services.telegram.admin_contact_url', 'https://t.me/lhie_support');
        [$order] = $this->paidOrder(withCredential: true, locale: 'en');
        $receipt = app(TelegramOutboundEffectService::class)->recordReceipt($order);
        $this->assertNotNull($receipt);

        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 424403],
            ]),
            'https://api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 424404],
            ]),
        ]);
        app(ReceiptDeliveryPipeline::class)->deliver($receipt, dispatchNext: false);
        $panel = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_ORDER_ACTIONS)
            ->sole();

        app(ReceiptActionPanelDeliveryPipeline::class)->deliver($panel);

        $sent = $panel->fresh();
        $this->assertSame(TelegramOutboundEffectState::Sent, $sent->state);
        $this->assertTrue((int) $sent->telegram_message_id > 0);
        $message = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage'))->sole()[0];
        $deliveryRequests = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto')
            || str_ends_with($request->url(), '/sendMessage'));
        $this->assertCount(2, $deliveryRequests);
        $this->assertTrue(str_ends_with($deliveryRequests->first()[0]->url(), '/sendPhoto'));
        $this->assertTrue(str_ends_with($deliveryRequests->last()[0]->url(), '/sendMessage'));
        $this->assertStringContainsString('Order ID', (string) ($message['text'] ?? ''));
        $this->assertStringNotContainsString('protect_content', json_encode($message->data(), JSON_THROW_ON_ERROR));
        $this->assertSame(
            'txt:request:'.$order->id,
            data_get($message->data(), 'reply_markup.inline_keyboard.0.0.callback_data'),
        );
    }

    public function test_panel_delivery_ambiguity_is_recorded_without_a_duplicate_send_or_receipt_rollback(): void
    {
        Queue::fake();
        [$order] = $this->paidOrder(withCredential: true, locale: 'en');
        $receipt = app(TelegramOutboundEffectService::class)->recordReceipt($order);
        $this->assertNotNull($receipt);
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 424405],
            ]),
        ]);
        app(ReceiptDeliveryPipeline::class)->deliver($receipt, dispatchNext: false);
        $panel = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_ORDER_ACTIONS)
            ->sole();

        $sendCount = 0;
        Http::fake([
            'https://api.telegram.org/*/sendMessage' => function () use (&$sendCount): never {
                $sendCount++;
                throw new ConnectionException('connection closed after Telegram accepted the request');
            },
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);
        $pipeline = app(ReceiptActionPanelDeliveryPipeline::class);
        $pipeline->deliver($panel);
        $pipeline->deliver($panel->fresh());

        $unknown = $panel->fresh();
        $this->assertSame(TelegramOutboundEffectState::Unknown, $unknown->state);
        $this->assertSame('telegram_transport_ambiguous', $unknown->last_error_code);
        $this->assertNull($unknown->next_attempt_at);
        $this->assertSame(1, $sendCount);
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
    }

    public function test_recovery_rebuilds_a_committed_missing_panel_without_touching_legacy_effects(): void
    {
        Queue::fake();
        [$order] = $this->paidOrder(withCredential: true, locale: 'en');
        $this->fakeTelegramHttp();
        $receipt = app(TelegramOutboundEffectService::class)->recordReceipt($order);
        $this->assertNotNull($receipt);
        TelegramOutboundEffect::query()
            ->where('effect_group_key', $receipt->effect_group_key)
            ->update([
                'state' => TelegramOutboundEffectState::Sent->value,
                'telegram_message_id' => '424406',
                'sent_at' => now(),
            ]);
        $order->forceFill(['telegram_receipt_sent_at' => now()])->save();

        Artisan::call('telegram:recover-outbound', ['--chunk' => 1]);
        Artisan::call('telegram:recover-outbound', ['--chunk' => 1]);

        $panel = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_ORDER_ACTIONS)
            ->sole();
        $this->assertSame(TelegramOutboundEffectState::Pending, $panel->state);
        Queue::assertPushed(SendTelegramReceiptActionPanel::class, fn (SendTelegramReceiptActionPanel $job): bool => $job->effectId === (string) $panel->id);
        Queue::assertPushedTimes(SendTelegramReceiptActionPanel::class, 1);
    }

    public function test_panel_failure_is_independent_from_the_successful_receipt(): void
    {
        Queue::fake();
        [$order] = $this->paidOrder(withCredential: true, locale: 'en');
        $receipt = app(TelegramOutboundEffectService::class)->recordReceipt($order);
        $this->assertNotNull($receipt);

        $panelShouldFail = false;
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 424408],
            ]),
            'https://api.telegram.org/*/sendMessage' => function () use (&$panelShouldFail): mixed {
                if ($panelShouldFail) {
                    return Http::response([
                        'ok' => false,
                        'description' => 'Bad Request: panel rejected',
                    ], 400);
                }

                return Http::response(['ok' => true, 'result' => ['message_id' => 424409]]);
            },
        ]);
        app(ReceiptDeliveryPipeline::class)->deliver($receipt, dispatchNext: false);
        $panel = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_ORDER_ACTIONS)
            ->sole();

        $panelShouldFail = true;
        $pipeline = app(ReceiptActionPanelDeliveryPipeline::class);
        $pipeline->deliver($panel);
        $pipeline->deliver($panel->fresh());

        $failed = $panel->fresh();
        $receiptStates = TelegramOutboundEffect::query()
            ->where('effect_group_key', $receipt->effect_group_key)
            ->pluck('state')
            ->all();
        $this->assertSame(TelegramOutboundEffectState::Failed, $failed->state);
        $this->assertSame('telegram_http_400', $failed->last_error_code);
        $this->assertNull($failed->next_attempt_at);
        $this->assertTrue(collect($receiptStates)->every(
            fn (TelegramOutboundEffectState $state): bool => $state === TelegramOutboundEffectState::Sent,
        ));
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
        $this->assertCount(1, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')));
    }

    public function test_panel_render_failure_emits_one_failed_observation(): void
    {
        Queue::fake();
        config()->set('services.dogfood_timing.enabled', true);
        $logs = [];
        Log::shouldReceive('info')->zeroOrMoreTimes()->withArgs(function (string $event, array $context = []) use (&$logs): bool {
            $logs[] = [$event, $context];

            return true;
        });
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        [$order] = $this->paidOrder(withCredential: true, locale: 'en');
        $receipt = app(TelegramOutboundEffectService::class)->recordReceipt($order);
        $this->assertNotNull($receipt);
        $this->fakeTelegramHttp();
        app(ReceiptDeliveryPipeline::class)->deliver($receipt, dispatchNext: false);
        $panel = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_ORDER_ACTIONS)
            ->sole();

        $locale = Mockery::mock(TelegramLocale::class);
        $locale->shouldReceive('translateLocale')->andThrow(new RuntimeException('render failed'));
        $this->app->instance(TelegramLocale::class, $locale);

        app(ReceiptActionPanelDeliveryPipeline::class)->deliver($panel);

        $failed = $panel->fresh();
        $failureMetrics = collect($logs)->filter(
            static fn (array $entry): bool => $entry[0] === 'dogfood.telegram.receipt_counter'
                && ($entry[1]['metric'] ?? null) === 'receipt_actions_failed',
        );
        $this->assertSame(TelegramOutboundEffectState::Failed, $failed->state);
        $this->assertCount(1, $failureMetrics);
    }

    public function test_explicit_panel_resend_preserves_the_receipt_reference_and_safe_capabilities(): void
    {
        Queue::fake();
        [$order, $secret] = $this->paidOrder(withCredential: true, locale: 'en');
        $receipt = app(TelegramOutboundEffectService::class)->recordReceipt($order);
        $this->assertNotNull($receipt);
        $receipt->forceFill([
            'state' => TelegramOutboundEffectState::Sent,
            'telegram_message_id' => '424407',
            'sent_at' => now(),
        ])->save();
        $order->forceFill(['telegram_receipt_sent_at' => now()])->save();
        $panel = app(TelegramOutboundEffectService::class)->recordReceiptActionPanel($receipt);
        $this->assertNotNull($panel);
        $panel->forceFill([
            'state' => TelegramOutboundEffectState::Unknown,
            'unknown_at' => now(),
        ])->save();

        $resend = app(TelegramOutboundEffectService::class)->resend(
            $panel,
            User::factory()->create(),
        );

        $this->assertSame(TelegramOutboundEffectState::Pending, $resend->state);
        $this->assertSame($panel->effect_group_key, $resend->effect_group_key);
        $this->assertSame($panel->outcome_metadata['receipt_group_key'], $resend->outcome_metadata['receipt_group_key']);
        $this->assertTrue($resend->outcome_metadata['txt_available']);
        $this->assertStringNotContainsString($secret, json_encode($resend->getAttributes(), JSON_THROW_ON_ERROR));
    }

    #[DataProvider('shortLegacyCredentials')]
    public function test_one_short_legacy_credential_stays_in_chat_with_custom_warning_icons(string $secret): void
    {
        Queue::fake();
        TelegramUiIcon::query()->where('key', 'message.warning')->sole()->update([
            'custom_emoji_id' => '5420323339723881652',
        ]);
        app(CustomEmojiCapability::class)->markAvailable();
        [$order] = $this->paidOrder(withCredential: true, locale: 'id');
        $order->orderItems()->sole()->stockItems()->sole()->update(['secret' => $secret]);
        $this->fakeTelegramHttp();

        app(TelegramReceiptNotifier::class)->send($order->fresh());

        $this->assertCount(0, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendDocument')));
        $receiptText = '';
        foreach (Http::recorded() as [$request]) {
            $receiptText .= (string) $this->telegramRequestField($request, 'caption');
            $receiptText .= (string) $this->telegramRequestField($request, 'text');
        }
        $this->assertStringContainsString($secret, $receiptText);
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
    }

    public static function shortLegacyCredentials(): array
    {
        return [
            'short link' => ['https://example.test/download?token=short'],
            'longer line still fits chat' => ['https://example.test/download?token='.str_repeat('x', 700)],
            'short multiline credential' => [str_repeat("https://example.test/a\n", 31).'last-line'],
            'caption-sized credential' => [str_repeat('x', 1024)],
        ];
    }

    /** @return array{0: Order, 1?: string} */
    #[DataProvider('automaticTxtCredentials')]
    public function test_large_credentials_are_delivered_once_as_txt_with_one_support_panel(string $secret, int $count): void
    {
        Queue::fake();
        config()->set('services.telegram.admin_contact_url', 'https://t.me/lhie_support');
        [$order] = $this->paidOrder(withCredential: true, locale: 'id');
        $item = $order->orderItems()->sole();
        $item->stockItems()->delete();
        $item->update(['quantity' => $count]);
        for ($i = 0; $i < $count; $i++) {
            ProductStockItem::create(['product_id' => $item->product_id, 'order_item_id' => $item->id, 'secret' => $secret.$i]);
        }
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendDocument' => Http::response(['ok' => true, 'result' => ['message_id' => 424499]]),
        ]);
        app(TelegramReceiptNotifier::class)->send($order->fresh());
        app(TelegramReceiptNotifier::class)->send($order->fresh());

        $document = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendDocument'))->sole()[0];
        $payload = app(TelegramPaidOrderTxtExporter::class)->export($order->fresh(), $order->customer);
        $this->assertTrue($document->hasFile('document', $payload->documentContents(), $payload->filename()));
        $this->assertStringContainsString(str_contains($secret, '|') ? 'password0' : $secret.'0', $payload->documentContents());
        $this->assertStringContainsString('Invoice : '.url(app(StorefrontInvoiceUrl::class)->show($order)), $payload->documentContents());
        $this->assertFalse(filter_var($this->telegramRequestField($document, 'protect_content'), FILTER_VALIDATE_BOOLEAN));
        $this->assertLessThanOrEqual(1024, mb_strlen((string) $this->telegramRequestField($document, 'caption')));
        $photo = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto'))->sole()[0];
        $this->assertStringNotContainsString($secret, (string) $this->telegramRequestField($photo, 'caption'));
        $this->assertEmpty($this->telegramRequestField($photo, 'reply_markup'));
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
        $this->assertSame(424499, $order->fresh()->telegram_credential_message_id);
        $panel = TelegramOutboundEffect::query()->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_ORDER_ACTIONS)->sole();
        app(ReceiptActionPanelDeliveryPipeline::class)->deliver($panel);
        $message = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage'))->sole()[0];
        $markup = $this->telegramRequestField($message, 'reply_markup');
        $markup = is_array($markup) ? $markup : json_decode($markup, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('https://t.me/lhie_support', data_get($markup, 'inline_keyboard.1.0.url'));
        foreach (TelegramOutboundEffect::all() as $effect) {
            $this->assertStringNotContainsString($secret, json_encode($effect->getAttributes(), JSON_THROW_ON_ERROR));
        }
    }

    public static function automaticTxtCredentials(): array
    {
        return [
            'two accounts' => ['user@example.test|password', 2],
            'one long link' => ['https://example.test/download?token='.str_repeat('x', 1200), 1],
            'just beyond caption-sized credential' => [str_repeat('x', 1024), 1],
            'link exceeds chat limit' => ['https://example.test/download?token='.str_repeat('x', 5000), 1],
            'multiline links' => [str_repeat("https://example.test/download\n", 60), 1],
        ];
    }

    public function test_ambiguous_automatic_txt_does_not_resend_or_complete_receipt(): void
    {
        Queue::fake();
        [$order] = $this->paidOrder(withCredential: true, locale: 'en');
        $order->orderItems()->sole()->stockItems()->sole()->update(['secret' => str_repeat('long-secret', 100)]);
        $first = app(TelegramOutboundEffectService::class)->recordReceipt($order->fresh());
        $document = TelegramOutboundEffect::query()->where('effect_group_key', $first->effect_group_key)->where('sequence', 2)->sole();
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendDocument' => Http::failedConnection('timeout'),
        ]);
        $pipeline = app(ReceiptDeliveryPipeline::class);
        $pipeline->deliver($first, dispatchNext: false);
        $pipeline->deliver($document, dispatchNext: false);
        $pipeline->deliver($document->fresh(), dispatchNext: false);
        $this->assertSame(TelegramOutboundEffectState::Unknown, $document->fresh()->state);
        $this->assertNull($order->fresh()->telegram_receipt_sent_at);
        $this->assertCount(1, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendDocument')));
        $this->assertSame(0, TelegramOutboundEffect::query()->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_ORDER_ACTIONS)->count());
    }

    public function test_automatic_txt_photo_rejection_delivers_fallback_and_support_panel(): void
    {
        Queue::fake();
        [$order] = $this->paidOrder(withCredential: true, locale: 'en');
        $order->orderItems()->sole()->stockItems()->sole()->update(['secret' => str_repeat('long-secret', 100)]);
        $first = app(TelegramOutboundEffectService::class)->recordReceipt($order->fresh());
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendPhoto' => Http::response(['ok' => false, 'description' => 'photo rejected'], 400),
            'https://api.telegram.org/*/sendDocument' => Http::response(['ok' => true, 'result' => ['message_id' => 424498]]),
        ]);
        app(ReceiptDeliveryPipeline::class)->deliver($first, dispatchNext: false);
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
        $panel = TelegramOutboundEffect::query()->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_ORDER_ACTIONS)->sole();
        app(ReceiptActionPanelDeliveryPipeline::class)->deliver($panel);
        $this->assertSame(TelegramOutboundEffectState::Sent, $panel->fresh()->state);
        $this->assertCount(1, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendDocument')));
        $panel->delete();
        $this->assertSame(1, app(ReceiptDeliveryPipeline::class)->reconcileMissingActionPanels());
        $this->assertSame(1, TelegramOutboundEffect::query()->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_ORDER_ACTIONS)->count());
    }

    public function test_existing_photo_caption_group_keeps_its_chat_delivery(): void
    {
        Queue::fake();
        [$order] = $this->paidOrder(withCredential: true, locale: 'en');
        $order->orderItems()->sole()->stockItems()->sole()->update(['secret' => str_repeat('long-secret', 120)]);
        $order = $order->fresh();
        $notifier = app(TelegramReceiptNotifier::class);
        $legacyPlan = $notifier->receiptPlan($order, documentDelivery: false, compact: false);
        $first = app(TelegramOutboundEffectService::class)->recordReceipt($order);
        $effects = TelegramOutboundEffect::query()->where('effect_group_key', $first->effect_group_key)->orderBy('sequence')->get();
        $this->assertSame($legacyPlan->photoUnitCount(), $effects->count());
        foreach ($effects as $effect) {
            $metadata = $effect->outcome_metadata;
            unset($metadata['receipt_layout_version']);
            $metadata['render_mode'] = 'photo_caption';
            $effect->update(['outcome_metadata' => $metadata, 'telegram_method' => $effect->sequence === 1 ? 'sendPhoto' : 'sendMessage']);
        }
        $this->fakeTelegramHttp();
        foreach ($effects as $effect) {
            app(ReceiptDeliveryPipeline::class)->deliver($effect->fresh(), dispatchNext: false);
        }
        $this->assertCount(0, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendDocument')));
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
    }

    public function test_automatic_txt_rejects_a_group_chat_target_before_sending(): void
    {
        Queue::fake();
        [$order] = $this->paidOrder(withCredential: true, locale: 'en', telegramId: -100123);
        $order->orderItems()->sole()->stockItems()->sole()->update(['secret' => str_repeat('long-secret', 120)]);
        $this->fakeTelegramHttp();
        app(TelegramReceiptNotifier::class)->send($order->fresh());
        Http::assertNothingSent();
        $this->assertNull($order->fresh()->telegram_receipt_sent_at);
    }

    private function paidOrder(bool $withCredential, string $locale, int $telegramId = 424001): array
    {
        $customer = Customer::factory()->create([
            'telegram_id' => $telegramId,
            'locale' => $locale,
        ]);
        $product = Product::factory()->create(['name' => 'Panel Product']);
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-424-PANEL',
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

        if (! $withCredential) {
            return [$order];
        }

        $secret = 'panel@example.test|Panel-password|Panel-mfa';
        ProductStockItem::create([
            'product_id' => $product->id,
            'order_item_id' => $item->id,
            'secret' => $secret,
        ]);

        return [$order, $secret];
    }
}
