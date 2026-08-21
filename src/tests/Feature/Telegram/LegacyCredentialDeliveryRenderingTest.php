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
use App\Services\Telegram\TelegramMessageUnit;
use App\Services\Telegram\TelegramOrderIntents;
use App\Services\Telegram\TelegramReceiptNotifier;
use Illuminate\Support\Facades\Http;
use Tests\Support\TelegramBotTestSupport;

class LegacyCredentialDeliveryRenderingTest extends TelegramBotTestSupport
{
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
        $this->assertCount(0, $tutorialUnit->entities());

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

    private function legacyPaidOrder(Customer $customer): Order
    {
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
            'secret' => 'legacy@example.test|Legacy-password|Legacy-mfa',
        ]);
        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        return $order;
    }
}
