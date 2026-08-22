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
use App\Services\PaidInvoice\PaidInvoiceRenderer;
use App\Services\Telegram\ReceiptDeliveryPipeline;
use App\Services\Telegram\TelegramOutboundEffectReviewService;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\Telegram\TelegramReceiptNotifier;
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

        $this->assertCount(2, $receiptEffects);
        $this->assertSame(
            TelegramOutboundEffectService::receiptEffectGroupKey($order->id),
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
        $groupKey = TelegramOutboundEffectService::receiptEffectGroupKey($order->id);
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
        $pipeline->deliver($credential, dispatchNext: false);

        $this->assertSame(1, $photoCount);
        $this->assertSame(2, $sendCount);
        $this->assertSame(TelegramOutboundEffectState::Sent, $first->fresh()->state);
        $this->assertSame(TelegramOutboundEffectState::Sent, $summary->fresh()->state);
        $this->assertSame(TelegramOutboundEffectState::Unknown, $credential->fresh()->state);
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
