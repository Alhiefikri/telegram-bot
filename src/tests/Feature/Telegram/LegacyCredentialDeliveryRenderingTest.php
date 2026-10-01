<?php

namespace Tests\Feature\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\TelegramUiIcon;
use App\Services\Telegram\CustomEmojiCapability;
use App\Services\Telegram\TelegramMessageUnit;
use App\Services\Telegram\TelegramOrderIntents;
use App\Services\Telegram\TelegramReceiptNotifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TelegramBotTestSupport;

class LegacyCredentialDeliveryRenderingTest extends TelegramBotTestSupport
{
    #[DataProvider('premiumIconReceiptCases')]
    public function test_paid_legacy_receipt_with_premium_icons_delivers_copyable_credentials(
        string $productName,
        bool $rejectPhoto,
    ): void {
        Queue::fake();
        $customer = Customer::factory()->create(['telegram_id' => 445568, 'locale' => 'id']);
        $email = 'buyer@example.test';
        $password = 'Päss😀word<&>';
        $order = $this->legacyPaidOrder($customer, $email.'|'.$password);
        $order->orderItems()->sole()->update([
            'product_name' => $productName,
            'product_icon' => '🎵',
            'product_custom_emoji_id' => '5368324170671202286',
        ]);
        foreach (['message.receipt', 'message.payment_card', 'message.account'] as $key) {
            TelegramUiIcon::query()->where('key', $key)->sole()->update([
                'custom_emoji_id' => '5368324170671202286',
            ]);
        }
        app(CustomEmojiCapability::class)->markAvailable();

        $deliveredMethods = [];
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($rejectPhoto, $email, $password, &$deliveredMethods) {
            $photo = str_ends_with($request->url(), '/sendPhoto');
            $text = (string) $this->telegramRequestField($request, $photo ? 'caption' : 'text');
            $entities = $this->telegramRequestField($request, $photo ? 'caption_entities' : 'entities') ?? [];
            $entities = is_string($entities) ? json_decode($entities, true, flags: JSON_THROW_ON_ERROR) : $entities;
            $codes = array_values(array_filter($entities, fn (array $entity): bool => $entity['type'] === 'code'));
            $values = array_map(fn (array $entity): string => $this->telegramEntityText($text, $entity), $codes);

            // Telegram rejects invalid ranges in both captions and text. Model
            // that response so a swallowed send error still fails completion.
            if ($values !== [$email, $password] || ($photo && $rejectPhoto)) {
                return Http::response(['ok' => false, 'description' => 'Bad Request: invalid message entities'], 400);
            }

            $this->assertStringNotContainsString('<tg-emoji', $text);
            $this->assertTrue(collect($entities)->contains(fn (array $entity): bool => $entity['type'] === 'custom_emoji'));
            $this->assertTrue(filter_var($this->telegramRequestField($request, 'protect_content'), FILTER_VALIDATE_BOOLEAN));
            $deliveredMethods[] = $photo ? 'sendPhoto' : 'sendMessage';

            return Http::response(['ok' => true, 'result' => ['message_id' => 123458]]);
        });

        app(TelegramReceiptNotifier::class)->send($order->fresh());

        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
        $this->assertSame([$rejectPhoto ? 'sendMessage' : 'sendPhoto'], $deliveredMethods);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
    }

    public static function premiumIconReceiptCases(): iterable
    {
        foreach (['SPOTIFY 2 MONTH PRIVATE', 'PRIME VIDEO PRIVATE'] as $productName) {
            yield $productName.' photo' => [$productName, false];
            yield $productName.' text fallback' => [$productName, true];
        }
    }

    public function test_legacy_receipt_and_order_detail_do_not_format_auth_token_tutorial_text(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 445566, 'locale' => 'id']);
        $order = $this->legacyPaidOrder($customer);

        $this->fakeTelegramHttp();

        $rendered = app(TelegramReceiptNotifier::class)->render($order->fresh());
        $credentialUnit = collect($rendered['units'])->first(
            fn (TelegramMessageUnit $unit): bool => str_contains($unit->text(), 'email : legacy@example.test'),
        );
        $tutorialUnit = collect($rendered['units'])->first(
            fn (TelegramMessageUnit $unit): bool => str_contains($unit->text(), 'Auth Token : tutorial text'),
        );

        $this->assertInstanceOf(TelegramMessageUnit::class, $credentialUnit);
        $this->assertInstanceOf(TelegramMessageUnit::class, $tutorialUnit);
        $this->assertCount(3, $credentialUnit->entities());
        $tutorialPosition = strpos($tutorialUnit->text(), 'Auth Token : tutorial text');
        $this->assertNotFalse($tutorialPosition);
        $tutorialOffset = intdiv(
            strlen(mb_convert_encoding(
                substr($tutorialUnit->text(), 0, $tutorialPosition),
                'UTF-16LE',
                'UTF-8',
            )),
            2,
        );
        $this->assertFalse(collect($tutorialUnit->entities())->contains(
            fn (array $entity): bool => $entity['offset'] === $tutorialOffset,
        ));

        app(TelegramOrderIntents::class)->sendOrderDetail(
            $customer,
            (string) $customer->telegram_id,
            $order->invoice_number,
        );

        $orderDetail = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '445566'
            && str_contains((string) ($request['text'] ?? ''), 'Auth Token : tutorial text'))
            ->last();

        $this->assertNotNull($orderDetail);
        $this->assertCount(3, $orderDetail[0]['entities'] ?? []);
    }

    public function test_legacy_two_part_receipt_and_order_detail_are_labeled_without_mfa(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 445567, 'locale' => 'id']);
        $order = $this->legacyPaidOrder($customer, 'legacy-two-part@example.test|Legacy-password');

        $this->fakeTelegramHttp();

        $rendered = app(TelegramReceiptNotifier::class)->render($order->fresh());
        $credentialUnit = collect($rendered['units'])->first(
            fn (TelegramMessageUnit $unit): bool => str_contains($unit->text(), 'email : legacy-two-part@example.test'),
        );

        $this->assertInstanceOf(TelegramMessageUnit::class, $credentialUnit);
        $this->assertStringContainsString('password : Legacy-password', $credentialUnit->text());
        $this->assertStringNotContainsString('legacy-two-part@example.test|Legacy-password', $credentialUnit->text());
        $this->assertCount(2, $credentialUnit->entities());

        app(TelegramOrderIntents::class)->sendOrderDetail(
            $customer,
            (string) $customer->telegram_id,
            $order->invoice_number,
        );

        $orderDetail = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '445567'
            && str_contains((string) ($request['text'] ?? ''), 'email : legacy-two-part@example.test'))
            ->last();

        $this->assertNotNull($orderDetail);
        $this->assertStringContainsString('password : Legacy-password', (string) ($orderDetail[0]['text'] ?? ''));
        $this->assertStringNotContainsString('legacy-two-part@example.test|Legacy-password', (string) ($orderDetail[0]['text'] ?? ''));
        $this->assertCount(2, $orderDetail[0]['entities'] ?? []);
    }

    private function legacyPaidOrder(
        Customer $customer,
        string $secret = 'legacy@example.test|Legacy-password|Legacy-mfa',
    ): Order {
        $product = Product::factory()->create([
            'name' => 'Legacy Bundle',
            'delivery_note' => 'Auth Token : tutorial text',
        ]);
        $order = Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
        ]);
        ProductStockItem::create([
            'product_id' => $product->id,
            'order_item_id' => $item->id,
            'secret' => $secret,
        ]);
        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        return $order;
    }
}
