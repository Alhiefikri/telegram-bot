<?php

namespace Tests\Feature\Telegram;

use App\Enums\CredentialAccessMethod;
use App\Enums\CredentialFormat;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\TelegramOutboundEffect;
use App\Services\Credentials\StructuredCredentialPayload;
use App\Services\Telegram\ReceiptDeliveryPipeline;
use App\Services\Telegram\TelegramOrderIntents;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\Telegram\TelegramReceiptNotifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Support\TelegramBotTestSupport;

class StructuredCredentialDeliveryTest extends TelegramBotTestSupport
{
    public function test_receipt_keeps_each_structured_account_and_otp_button_together(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 445566, 'locale' => 'id']);
        [$order, $item] = $this->paidOrder($customer, 'Structured Bundle');
        $otpUrl = 'https://mail.example.test/otp?opaque=fake-otp-value';

        ProductStockItem::create([
            'product_id' => $item->product_id,
            'order_item_id' => $item->id,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => StructuredCredentialPayload::fromValues(
                'otp@example.test',
                'Otp-password',
                CredentialAccessMethod::OtpMailLink,
                $otpUrl,
                'Tutorial akun OTP.',
            )->toArray(),
        ]);
        ProductStockItem::create([
            'product_id' => $item->product_id,
            'order_item_id' => $item->id,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => StructuredCredentialPayload::fromValues(
                'auth@example.test',
                'Auth-password',
                CredentialAccessMethod::AuthToken,
                'fake-auth-token-value',
            )->toArray(),
        ]);

        $this->fakeTelegramHttp();
        app(TelegramReceiptNotifier::class)->send($order->fresh());

        $messages = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '445566');
        $otpMessage = $messages->first(fn ($record): bool => str_contains((string) ($record[0]['text'] ?? ''), 'otp@example.test'))[0];
        $authMessage = $messages->first(fn ($record): bool => str_contains((string) ($record[0]['text'] ?? ''), 'auth@example.test'))[0];

        $this->assertStringContainsString('mail.example.test', $otpMessage['text']);
        $this->assertStringNotContainsString($otpUrl, $otpMessage['text']);
        $this->assertSame('Buka OTP Mail #1', data_get($otpMessage, 'reply_markup.inline_keyboard.0.0.text'));
        $this->assertSame($otpUrl, data_get($otpMessage, 'reply_markup.inline_keyboard.0.0.url'));
        $this->assertStringContainsString('Tutorial akun OTP.', $otpMessage['text']);

        $this->assertStringContainsString('fake-auth-token-value', $authMessage['text']);
        $this->assertStringNotContainsString('fake-auth-token-value', json_encode($authMessage['reply_markup'] ?? [], JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('fake-auth-token-value', json_encode($authMessage['options'] ?? [], JSON_THROW_ON_ERROR));
        $this->assertCount(3, $authMessage['entities'] ?? []);

        foreach (TelegramOutboundEffect::query()->where('source_id', (string) $order->id)->get() as $effect) {
            $attributes = json_encode($effect->getAttributes(), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('fake-auth-token-value', $attributes);
            $this->assertStringNotContainsString($otpUrl, $attributes);
        }
    }

    public function test_long_auth_token_stays_one_code_entity_in_one_bounded_unit(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 778899, 'locale' => 'id']);
        [$order, $item] = $this->paidOrder($customer, 'Long Token Bundle');
        $token = str_repeat('T', 3400);
        ProductStockItem::create([
            'product_id' => $item->product_id,
            'order_item_id' => $item->id,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => StructuredCredentialPayload::fromValues(
                'long-token@example.test',
                'Password',
                CredentialAccessMethod::AuthToken,
                $token,
            )->toArray(),
        ]);

        $this->fakeTelegramHttp();
        app(TelegramReceiptNotifier::class)->send($order->fresh());

        $messages = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'long-token@example.test'));
        $this->assertCount(1, $messages);
        $message = $messages->first()[0];
        $this->assertLessThanOrEqual(3900, mb_strlen((string) $message['text'], 'UTF-8'));
        $this->assertCount(3, $message['entities'] ?? []);
        $this->assertSame(3400, $message['entities'][2]['length']);
    }

    public function test_ambiguous_structured_receipt_enters_manual_review_without_blind_resend(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 990011, 'locale' => 'id']);
        [$order, $item] = $this->paidOrder($customer, 'Ambiguous Bundle');
        $secret = 'ambiguous-auth-token';
        ProductStockItem::create([
            'product_id' => $item->product_id,
            'order_item_id' => $item->id,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => StructuredCredentialPayload::fromValues(
                'ambiguous@example.test',
                'Password',
                CredentialAccessMethod::AuthToken,
                $secret,
            )->toArray(),
        ]);

        $effect = app(TelegramOutboundEffectService::class)->recordReceipt($order->fresh());
        Http::fake([
            'https://api.telegram.org/*/sendMessage' => function (): never {
                throw new ConnectionException('transport timeout after Telegram accepted the request');
            },
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        app(ReceiptDeliveryPipeline::class)->deliver($effect, dispatchNext: false);

        $this->assertSame('unknown', $effect->fresh()->state->value ?? $effect->fresh()->state);
        $this->assertStringNotContainsString($secret, json_encode($effect->fresh()->getAttributes(), JSON_THROW_ON_ERROR));
    }

    public function test_paid_order_detail_uses_the_same_account_unit_and_matching_otp_button(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 112233, 'locale' => 'id']);
        [$order, $item] = $this->paidOrder($customer, 'Order Detail Bundle');
        $otpUrl = 'https://mail.detail.example.test/otp?opaque=detail-otp-value';
        ProductStockItem::create([
            'product_id' => $item->product_id,
            'order_item_id' => $item->id,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => StructuredCredentialPayload::fromValues(
                'detail@example.test',
                'Detail-password',
                CredentialAccessMethod::OtpMailLink,
                $otpUrl,
            )->toArray(),
        ]);

        $this->fakeTelegramHttp();
        app(TelegramOrderIntents::class)->sendOrderDetail(
            $customer,
            (string) $customer->telegram_id,
            $order->invoice_number,
        );

        $messages = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '112233');
        $accountMessage = $messages->first(fn ($record): bool => str_contains((string) ($record[0]['text'] ?? ''), 'detail@example.test'))[0];

        $this->assertStringContainsString('mail.detail.example.test', $accountMessage['text']);
        $this->assertStringNotContainsString($otpUrl, $accountMessage['text']);
        $this->assertSame('Buka OTP Mail #1', data_get($accountMessage, 'reply_markup.inline_keyboard.0.0.text'));
        $this->assertSame($otpUrl, data_get($accountMessage, 'reply_markup.inline_keyboard.0.0.url'));
    }

    /** @return array{0: Order, 1: OrderItem} */
    private function paidOrder(Customer $customer, string $name): array
    {
        $product = Product::factory()->create(['name' => $name]);
        $order = Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
        ]);
        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        return [$order, $item];
    }
}
