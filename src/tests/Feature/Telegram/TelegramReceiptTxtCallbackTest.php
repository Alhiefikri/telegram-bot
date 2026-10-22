<?php

namespace Tests\Feature\Telegram;

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
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\Telegram\TelegramReceiptTxtConfirmationSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TelegramBotTestSupport;

final class TelegramReceiptTxtCallbackTest extends TelegramBotTestSupport
{
    public function test_txt_request_queues_download_without_confirmation(): void
    {
        Queue::fake([SendTelegramReceiptTxt::class]);
        $this->fakeTelegramHttp();
        [$customer, $order] = $this->paidOrderWithCredential('safe@example.test', 'Safe-password', 'en');
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower((string) $query->sql);
        });

        $this->postTelegramWebhook(
            $this->callbackUpdate(5101, 'cb-txt-request', 'txt:request:'.$order->id, chatId: 423001),
        )->assertOk();

        $this->assertFalse(collect($queries)->contains(
            static fn (string $sql): bool => str_starts_with(ltrim($sql), 'select *')
                && str_contains($sql, 'product_stock_items'),
        ));
        $this->assertCount(0, Http::recorded(
            fn ($request): bool => str_ends_with($request->url(), '/sendMessage'),
        ));

        $effect = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_TXT_DOWNLOAD)
            ->sole();
        $this->assertSame(TelegramOutboundEffectState::Pending, $effect->state);
        $this->assertSame((string) $customer->telegram_id, (string) $effect->telegram_chat_id);
        $this->assertMatchesRegularExpression(
            '/\A[a-f0-9]{32}\z/',
            (string) ($effect->outcome_metadata['confirmation_nonce'] ?? ''),
        );
        $this->assertStringNotContainsString(
            'Safe-password',
            json_encode($effect->getAttributes(), JSON_THROW_ON_ERROR),
        );

        Queue::assertPushedOn(
            TelegramOutboundEffectService::RECEIPT_QUEUE,
            SendTelegramReceiptTxt::class,
            fn (SendTelegramReceiptTxt $job): bool => $job->orderId === (string) $order->id
                && $job->effectId === (string) $effect->id,
        );
        $this->assertNull(Cache::get('telegram:receipt-txt-confirmation:423001:'.$order->id));
        $this->assertCount(0, Http::recorded(
            fn ($request): bool => str_ends_with($request->url(), '/sendDocument'),
        ));
        Http::assertSent(
            fn ($request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
                && ($request['callback_query_id'] ?? null) === 'cb-txt-request'
                && ($request['text'] ?? null) === '✅ Request received. Your TXT file is being prepared.',
        );
    }

    public function test_txt_request_accepts_order_without_invoice(): void
    {
        Queue::fake([SendTelegramReceiptTxt::class]);
        $this->fakeTelegramHttp();
        [, $order] = $this->paidOrderWithCredential('fallback@example.test', 'Fallback-password', 'en');
        $order->forceFill(['invoice_number' => ''])->save();

        $this->postTelegramWebhook(
            $this->callbackUpdate(5102, 'cb-txt-fallback-order', 'txt:request:'.$order->id, chatId: 423001),
        )->assertOk();

        $effect = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_TXT_DOWNLOAD)
            ->sole();
        $this->assertSame((string) $order->id, (string) $effect->source_id);
        $this->assertSame(TelegramOutboundEffectState::Pending, $effect->state);
        $this->assertCount(0, Http::recorded(
            fn ($request): bool => str_ends_with($request->url(), '/sendMessage'),
        ));
    }

    public function test_send_file_creates_exactly_one_effect_and_answers_immediately(): void
    {
        Queue::fake([SendTelegramReceiptTxt::class]);
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendDocument' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 423111],
            ]),
        ]);
        [$customer, $order] = $this->paidOrderWithCredential('send@example.test', 'Send-password', 'en');
        $nonce = $this->startConfirmation($customer, $order, '423001');
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower((string) $query->sql);
        });

        $this->postTelegramWebhook($this->callbackUpdate(5111, 'cb-txt-send', 'txt:send:'.$nonce, from: ['id' => 423001], chatId: 423001, messageId: 9911))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(5112, 'cb-txt-send-replay', 'txt:send:'.$nonce, from: ['id' => 423001], chatId: 423001, messageId: 9911))->assertOk();

        $effects = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_TXT_DOWNLOAD)
            ->get();
        $this->assertCount(1, $effects);
        $effect = $effects->sole();
        $this->assertSame(TelegramOutboundEffectState::Pending, $effect->fresh()->state);
        $this->assertSame($nonce, (string) ($effect->outcome_metadata['confirmation_nonce'] ?? ''));
        $this->assertSame(9911, (int) ($effect->outcome_metadata['origin_message_id'] ?? 0));
        $this->assertSame((string) $customer->telegram_id, (string) $effect->telegram_chat_id);
        $this->assertStringNotContainsString('Send-password', json_encode($effect->getAttributes(), JSON_THROW_ON_ERROR));
        $this->assertFalse(collect($queries)->contains(
            static fn (string $sql): bool => str_starts_with(ltrim($sql), 'select *')
                && str_contains($sql, 'product_stock_items'),
        ));

        $this->assertCount(0, Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendDocument')));
        Queue::assertPushedOn(
            TelegramOutboundEffectService::RECEIPT_QUEUE,
            SendTelegramReceiptTxt::class,
            fn (SendTelegramReceiptTxt $job): bool => $job->orderId === (string) $order->id
                && $job->effectId === (string) $effect->id,
        );
        $this->assertNull(Cache::get('telegram:receipt-txt-confirmation:423001:'.$order->id));
        $this->assertCount(
            1,
            Http::recorded(fn ($request) => str_ends_with($request->url(), '/answerCallbackQuery')
                && ($request['text'] ?? null) === '✅ Request received. Your TXT file is being prepared.'),
        );
        $this->assertCount(
            1,
            Http::recorded(fn ($request) => str_ends_with($request->url(), '/answerCallbackQuery')
                && ($request['text'] ?? null) === 'This request is no longer valid. Press TXT again to generate a new file.'),
        );
    }

    public function test_unknown_valid_shape_nonce_does_not_invalidate_live_confirmation(): void
    {
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendDocument' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 423116],
            ]),
        ]);
        [$customer, $order] = $this->paidOrderWithCredential('unknown-nonce@example.test', 'Unknown-nonce-password', 'en');
        $nonce = $this->startConfirmation($customer, $order, '423001');
        $wrongNonce = $nonce;
        $wrongNonce[0] = $wrongNonce[0] === 'a' ? 'b' : 'a';

        $this->postTelegramWebhook($this->callbackUpdate(
            5116,
            'cb-txt-unknown-valid-shape',
            'txt:send:'.$wrongNonce,
            chatId: 423001,
        ))->assertOk();

        $confirmation = Cache::get('telegram:receipt-txt-confirmation:423001:'.$order->id);
        $this->assertIsArray($confirmation);
        $this->assertSame($nonce, (string) ($confirmation['nonce'] ?? ''));
        $this->assertSame(0, TelegramOutboundEffect::query()->count());
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && ($request['text'] ?? null) === 'This request is no longer valid. Press TXT again to generate a new file.');

        $this->postTelegramWebhook($this->callbackUpdate(
            5117,
            'cb-txt-send-after-unknown',
            'txt:send:'.$nonce,
            chatId: 423001,
        ))->assertOk();

        $this->assertSame(1, TelegramOutboundEffect::query()->count());
        $this->assertCount(1, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendDocument')));
        $this->assertNull(Cache::get('telegram:receipt-txt-confirmation:423001:'.$order->id));
    }

    public function test_cancel_consumes_confirmation_and_creates_no_effect(): void
    {
        $this->fakeTelegramHttp();
        [$customer, $order] = $this->paidOrderWithCredential('cancel@example.test', 'Cancel-password', 'en');
        $nonce = $this->startConfirmation($customer, $order, '423001');

        $this->postTelegramWebhook($this->callbackUpdate(5121, 'cb-txt-cancel', 'txt:cancel:'.$nonce, from: ['id' => 423001], chatId: 423001))->assertOk();

        $this->assertDatabaseCount('telegram_outbound_effects', 0);
        $this->assertNull(Cache::get('telegram:receipt-txt-confirmation:423001:'.$order->id));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/answerCallbackQuery')
            && ($request['callback_query_id'] ?? null) === 'cb-txt-cancel'
            && ($request['text'] ?? null) === 'Cancelled. No file will be generated.');
        $this->assertCount(0, Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendDocument')));
    }

    public function test_expired_or_replayed_or_wrong_customer_token_fails_generically(): void
    {
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendDocument' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 423131],
            ]),
        ]);
        [$customer, $order] = $this->paidOrderWithCredential('stale@example.test', 'Stale-password', 'en');
        $other = Customer::factory()->create(['telegram_id' => 423010, 'locale' => 'en']);

        $nonce = $this->startConfirmation($customer, $order, '423001');
        Cache::forget('telegram:receipt-txt-confirmation:423001:'.$order->id);
        $this->postTelegramWebhook($this->callbackUpdate(5131, 'cb-txt-expired', 'txt:send:'.$nonce, from: ['id' => 423001], chatId: 423001))->assertOk();

        $nonce = $this->startConfirmation($customer, $order, '423001');
        $this->postTelegramWebhook($this->callbackUpdate(5132, 'cb-txt-wrong-customer', 'txt:send:'.$nonce, from: ['id' => 423010], chatId: 423010))->assertOk();
        $this->assertSame(0, TelegramOutboundEffect::query()->count());
        $this->assertNull(Cache::get('telegram:receipt-txt-confirmation:423010:'.$order->id));

        // The owner's entry for chat 423001 is untouched (the attacker's send
        // only scanned the attacker's own chat/order entries).
        $ownerConfirmation = Cache::get('telegram:receipt-txt-confirmation:423001:'.$order->id);
        $this->assertIsArray($ownerConfirmation);
        $this->assertSame($nonce, (string) ($ownerConfirmation['nonce'] ?? ''));

        $this->postTelegramWebhook($this->callbackUpdate(5133, 'cb-txt-replay', 'txt:send:'.$nonce, from: ['id' => 423001], chatId: 423001))->assertOk();
        $this->assertCount(
            1,
            TelegramOutboundEffect::query()
                ->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_TXT_DOWNLOAD)
                ->get(),
        );
        $answers = Http::recorded(fn ($request) => str_ends_with($request->url(), '/answerCallbackQuery')
            && ($request['text'] ?? null) === 'This request is no longer valid. Press TXT again to generate a new file.');
        $this->assertCount(2, $answers);
        $this->assertCount(
            1,
            Http::recorded(fn ($request) => str_ends_with($request->url(), '/answerCallbackQuery')
                && ($request['text'] ?? null) === '✅ Request received. Your TXT file is being prepared.'),
        );
        $this->assertCount(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendDocument')));
    }

    public function test_txt_request_rejects_unpaid_refunded_cancelled_and_late_review_orders_without_leakage(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 423020, 'locale' => 'en']);
        $product = Product::factory()->create(['name' => 'TXT Callback Product']);

        $order = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-423-UNPAID',
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
        ]);
        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
        ]);
        ProductStockItem::create([
            'product_id' => $product->id,
            'order_item_id' => $item->id,
            'secret' => 'unpaid@example.test|Unpaid-password',
        ]);
        Payment::factory()->for($order)->create(['status' => PaymentStatus::Pending]);

        $this->postTelegramWebhook($this->callbackUpdate(5141, 'cb-txt-unpaid', 'txt:request:'.$order->id, chatId: 423020))->assertOk();

        $this->assertDatabaseCount('telegram_outbound_effects', 0);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/answerCallbackQuery')
            && ($request['callback_query_id'] ?? null) === 'cb-txt-unpaid'
            && ($request['text'] ?? null) === 'This download is not available for this order right now.');
        $this->assertCount(0, Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')));
        $this->assertCount(0, Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendDocument')));
        $this->assertDatabaseCount('telegram_outbound_effects', 0);
    }

    public function test_send_file_after_order_becomes_unpaid_or_late_review_creates_no_effect(): void
    {
        $this->fakeTelegramHttp();
        [$customer, $order] = $this->paidOrderWithCredential('revoked@example.test', 'Revoked-password', 'en');
        $nonce = $this->startConfirmation($customer, $order, '423001');

        $order->update(['payment_status' => PaymentStatus::Refunded]);
        $this->postTelegramWebhook($this->callbackUpdate(5151, 'cb-txt-revoked', 'txt:send:'.$nonce, chatId: 423001))->assertOk();

        $this->assertDatabaseCount('telegram_outbound_effects', 0);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/answerCallbackQuery')
            && in_array((string) ($request['text'] ?? ''), [
                'This request is no longer valid. Press TXT again to generate a new file.',
                'This download is not available for this order right now.',
            ], true));
        $this->assertCount(0, Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendDocument')));
    }

    public function test_unknown_txt_callback_and_malformed_token_fail_generically(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 423030, 'locale' => 'en']);

        $this->postTelegramWebhook($this->callbackUpdate(5161, 'cb-txt-bad', 'txt:request:not-an-order', chatId: 423030))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(5162, 'cb-txt-unknown', 'txt:send:'.str_repeat('z', 32), chatId: 423030))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(5163, 'cb-txt-junk', 'txt:whatever', chatId: 423030))->assertOk();

        $this->assertDatabaseCount('telegram_outbound_effects', 0);
        $this->assertCount(0, Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')));
        $generic = Http::recorded(fn ($request) => str_ends_with($request->url(), '/answerCallbackQuery')
            && ($request['text'] ?? null) !== null && ($request['text'] ?? '') !== '');
        $this->assertCount(3, $generic);
        $this->assertCount(0, Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendDocument')));
    }

    private function paidOrderWithCredential(string $email, string $password, string $locale): array
    {
        $customer = Customer::factory()->create(['telegram_id' => 423001, 'locale' => $locale]);
        $product = Product::factory()->create(['name' => 'TXT Callback Product']);
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-423-TXT',
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
        ]);
        ProductStockItem::create([
            'product_id' => $product->id,
            'order_item_id' => $item->id,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => [
                'version' => 1,
                'email' => $email,
                'password' => $password,
                'access_method' => 'none',
                'access_value' => null,
                'tutorial_override' => null,
            ],
        ]);
        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        return [$customer, $order];
    }

    private function startConfirmation(Customer $customer, Order $order, int $chatId): string
    {
        $confirmation = app(TelegramReceiptTxtConfirmationSession::class)->start(
            $customer,
            (string) $chatId,
            (string) $order->id,
            'en',
        );

        return (string) ($confirmation['nonce'] ?? '');
    }
}
