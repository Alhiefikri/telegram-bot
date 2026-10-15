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
use App\Services\Telegram\TelegramMessageUnit;
use App\Services\Telegram\TelegramOrderIntents;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\Telegram\TelegramPaidOrderTxtExporter;
use App\Services\Telegram\TelegramReceiptNotifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Support\TelegramBotTestSupport;

class StructuredCredentialDeliveryTest extends TelegramBotTestSupport
{
    public function test_explicit_chat_receipt_keeps_each_structured_account_and_otp_button_together(): void
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
                'Päss😀<&>`<tg-emoji emoji-id="123">🔐</tg-emoji>',
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

        $plan = app(TelegramReceiptNotifier::class)->receiptPlan($order->fresh(), documentDelivery: false);
        $this->assertFalse($plan->usesDocument());
        $this->assertSame(1, $plan->photoUnitCount());
        $unit = $plan->photoUnit(1);
        $caption = $unit->text();
        $this->assertStringContainsString('mail.example.test', $caption);
        $this->assertStringNotContainsString($otpUrl, $caption);
        $this->assertStringContainsString('fake-auth-token-value', $caption);

        $markup = $unit->replyMarkup();
        $this->assertSame('Buka OTP Mail #1', data_get($markup, 'inline_keyboard.0.0.text'));
        $this->assertSame($otpUrl, data_get($markup, 'inline_keyboard.0.0.url'));
        $this->assertStringContainsString('Tutorial akun OTP.', $caption);

        $entities = $unit->entities();
        $blocks = array_values(array_filter($entities, fn (array $entity): bool => $entity['type'] === 'pre'));
        $this->assertSame([
            'otp@example.test | Päss😀<&>`<tg-emoji emoji-id="123">🔐</tg-emoji>',
            'auth@example.test | Auth-password | fake-auth-token-value',
        ], array_map(fn (array $entity): string => $this->telegramEntityText($caption, $entity), $blocks));
        $this->assertSame(['copy', 'copy'], array_column($blocks, 'language'));
    }

    public function test_single_short_auth_token_and_shared_note_are_counted_once_for_chat_delivery(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 778898, 'locale' => 'id']);
        [$order, $item] = $this->paidOrder($customer, 'Short Token Bundle');
        $token = str_repeat('T', 700);
        $item->update([
            'delivery_note' => str_repeat('n', 200),
            'delivery_note_snapshotted' => true,
        ]);
        ProductStockItem::create([
            'product_id' => $item->product_id,
            'order_item_id' => $item->id,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => StructuredCredentialPayload::fromValues(
                'short-token@example.test',
                'Password',
                CredentialAccessMethod::AuthToken,
                $token,
            )->toArray(),
        ]);

        $this->fakeTelegramHttp();
        app(TelegramReceiptNotifier::class)->send($order->fresh());

        $this->assertCount(0, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendDocument')));
        $receiptText = '';
        foreach (Http::recorded() as [$request]) {
            $receiptText .= (string) $this->telegramRequestField($request, 'caption');
            $receiptText .= (string) $this->telegramRequestField($request, 'text');
        }
        $this->assertSame(1, substr_count($receiptText, $token));
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
    }

    public function test_long_auth_token_is_delivered_intact_in_a_txt_file(): void
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

        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendDocument' => Http::response(['ok' => true, 'result' => ['message_id' => 7788991]]),
        ]);
        app(TelegramReceiptNotifier::class)->send($order->fresh());
        $document = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendDocument'))->sole()[0];
        $payload = app(TelegramPaidOrderTxtExporter::class)->export($order->fresh(), $customer);
        $this->assertTrue($document->hasFile('document', $payload->documentContents(), $payload->filename()));
        $this->assertStringContainsString('long-token@example.test', $payload->documentContents());
        $this->assertSame(1, substr_count($payload->documentContents(), $token));
        $this->assertStringContainsString('Auth Token : '.$token, $payload->documentContents());
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
    }

    public function test_grouped_long_auth_token_keeps_product_and_account_context(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 778800, 'locale' => 'id']);
        [$order, $item] = $this->paidOrder($customer, 'Grouped Long Token Bundle');
        $token = str_repeat('T', 3400);
        $tutorial = str_repeat('Tutorial ', 100);

        ProductStockItem::create([
            'product_id' => $item->product_id,
            'order_item_id' => $item->id,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => StructuredCredentialPayload::fromValues(
                'long-grouped@example.test',
                'Password',
                CredentialAccessMethod::AuthToken,
                $token,
                $tutorial,
            )->toArray(),
        ]);
        ProductStockItem::create([
            'product_id' => $item->product_id,
            'order_item_id' => $item->id,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => StructuredCredentialPayload::fromValues(
                'other-grouped@example.test',
                'Other-password',
                CredentialAccessMethod::OtpMailLink,
                'https://mail.other-grouped.example.test/otp',
            )->toArray(),
        ]);

        $detail = app(TelegramReceiptNotifier::class)->renderProductDetail($order->fresh());
        $tokenUnits = array_values(array_filter(
            $detail['units'],
            fn (TelegramMessageUnit $unit): bool => str_contains($unit->text(), $token),
        ));

        $this->assertCount(1, $tokenUnits);
        $tokenUnit = $tokenUnits[0];
        $this->assertStringContainsString('Grouped Long Token Bundle', $tokenUnit->text());
        $this->assertStringContainsString('Data akun #1', $tokenUnit->text());

        $allText = implode("\n", array_map(
            fn (TelegramMessageUnit $unit): string => $unit->text(),
            $detail['units'],
        ));
        $this->assertSame(1, substr_count($allText, $token));

        $entities = $tokenUnit->entities();
        $this->assertCount(1, $entities);
        $this->assertSame(
            'long-grouped@example.test | Password | '.$token,
            $this->telegramEntityText($tokenUnit->text(), $entities[0]),
        );
        $this->assertSame('pre', $entities[0]['type']);
        $this->assertSame('copy', $entities[0]['language']);
    }

    public function test_account_and_long_token_split_only_between_complete_copy_blocks(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 778801, 'locale' => 'id']);
        [$order, $item] = $this->paidOrder($customer, 'Token Boundary');
        $password = str_repeat('P', 600);
        $token = str_repeat('T', 3500);
        ProductStockItem::create([
            'product_id' => $item->product_id,
            'order_item_id' => $item->id,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => StructuredCredentialPayload::fromValues(
                'boundary@example.test',
                $password,
                CredentialAccessMethod::AuthToken,
                $token,
            )->toArray(),
        ]);

        $detail = app(TelegramReceiptNotifier::class)->renderProductDetail($order->fresh());
        $copied = [];
        foreach ($detail['units'] as $unit) {
            $this->assertLessThanOrEqual(3900, $unit->characterLength());
            foreach ($unit->entities() as $entity) {
                $this->assertSame('pre', $entity['type']);
                $copied[] = $this->telegramEntityText($unit->text(), $entity);
            }
        }
        $this->assertSame(['boundary@example.test | '.$password, $token], $copied);
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

        $invoice = app(TelegramOutboundEffectService::class)->recordReceipt($order->fresh());
        $this->assertNotNull($invoice);
        $next = TelegramOutboundEffect::query()
            ->where('effect_group_key', $invoice->effect_group_key)
            ->where('sequence', '>', $invoice->sequence)
            ->orderBy('sequence')
            ->first();
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => function (): never {
                throw new ConnectionException('transport timeout after Telegram accepted the request');
            },
            'https://api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 902],
            ]),
        ]);

        app(ReceiptDeliveryPipeline::class)->deliver($invoice, dispatchNext: false);

        $this->assertSame('unknown', $invoice->fresh()->state->value ?? $invoice->fresh()->state);
        $this->assertStringNotContainsString($secret, json_encode($invoice->fresh()->getAttributes(), JSON_THROW_ON_ERROR));
        if ($next !== null) {
            app(ReceiptDeliveryPipeline::class)->deliver($next, dispatchNext: false);
            $this->assertSame('unknown', $next->fresh()->state->value ?? $next->fresh()->state);
        }
        $this->assertNull($order->fresh()->telegram_receipt_sent_at);
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
