<?php

namespace Tests\Feature\Telegram;

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
use App\Services\OrderCheckoutService;
use App\Services\OrderStateMachine;
use App\Services\Telegram\ReceiptDeliveryPipeline;
use App\Services\Telegram\TelegramOutboundEffectReviewService;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Http\Client\ConnectionException;
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

        $this->assertCount(1, $receiptEffects);
        $this->assertSame(
            TelegramOutboundEffectService::receiptEffectGroupKey($order->id),
            $receiptEffects->sole()->effect_group_key,
        );
        $this->assertSame(TelegramOutboundEffectState::Pending, $receiptEffects->sole()->state);
        $this->assertSame('sendMessage', $receiptEffects->sole()->telegram_method);
        $this->assertStringNotContainsString('buyer@example', json_encode($receiptEffects->sole()->getAttributes(), JSON_THROW_ON_ERROR));
        Queue::assertPushed(SendTelegramReceipt::class, 1);
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

    public function test_ordered_receipt_stops_after_an_ambiguous_chunk_without_resending_previous_credentials(): void
    {
        $order = $this->multiChunkPaidOrder();
        $outboundEffects = app(TelegramOutboundEffectService::class);
        $first = $outboundEffects->recordReceipt($order);
        $this->assertNotNull($first);

        $sendCount = 0;
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendMessage' => function () use (&$sendCount): mixed {
                $sendCount++;
                if ($sendCount === 2) {
                    throw new ConnectionException('timeout after Telegram accepted the credential chunk');
                }

                return Http::response(['ok' => true, 'result' => ['message_id' => 700 + $sendCount]]);
            },
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $pipeline = app(ReceiptDeliveryPipeline::class);
        $pipeline->deliver($first, dispatchNext: false);
        $second = TelegramOutboundEffect::query()
            ->where('effect_group_key', $first->effect_group_key)
            ->where('sequence', '>', $first->sequence)
            ->orderBy('sequence')
            ->first();

        $this->assertNotNull($second);
        $pipeline->deliver($second, dispatchNext: false);
        $pipeline->deliver($second, dispatchNext: false);

        $this->assertSame(2, $sendCount);
        $this->assertSame(TelegramOutboundEffectState::Sent, $first->fresh()->state);
        $this->assertSame(TelegramOutboundEffectState::Unknown, $second->fresh()->state);
        $this->assertNull($order->fresh()->telegram_receipt_sent_at);
        $this->assertStringNotContainsString(
            'buyer0@example.test',
            json_encode($second->fresh()->getAttributes(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_marking_the_final_receipt_chunk_sent_completes_the_legacy_projection(): void
    {
        $order = $this->multiChunkPaidOrder();
        $outboundEffects = app(TelegramOutboundEffectService::class);
        $first = $outboundEffects->recordReceipt($order);
        $this->assertNotNull($first);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 900001],
            ]),
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
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
        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response($response)]);

        app(ReceiptDeliveryPipeline::class)->deliver($effect, dispatchNext: false);

        $freshEffect = $effect->fresh();
        $this->assertSame(TelegramOutboundEffectState::Failed, $freshEffect->state);
        $this->assertNull($freshEffect->telegram_message_id);
        $this->assertNull($order->fresh()->telegram_receipt_sent_at);
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
