<?php

namespace Tests\Feature\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Jobs\DeleteBuyerCheckoutMessages;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TelegramOutboundEffect;
use App\Services\OrderLifecycle;
use App\Services\Telegram\CheckoutInstructionDeliveryPipeline;
use App\Services\Telegram\TelegramBuyerCheckoutInstructionCleaner;
use App\Services\Telegram\TelegramCheckoutIntents;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NonTransactionalDatabaseTestCase;

final class TelegramCheckoutMessageCleanupTest extends NonTransactionalDatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.telegram.token', 'bot-token');
        config()->set('queue.default', 'sync');
        Queue::fake()->except(DeleteBuyerCheckoutMessages::class);
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    #[DataProvider('qrisProviders')]
    public function test_cancellation_removes_qris_and_order_detail_without_waiting_for_recovery(string $provider, string $method): void
    {
        $order = Order::factory()->create([
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'manual_checkout_customer_notification_chat_id' => '777',
            'manual_checkout_customer_notification_message_id' => 701,
            'buyer_order_detail_message_chat_id' => '777',
            'buyer_order_detail_message_id' => 702,
        ]);
        Payment::factory()->for($order)->create(['provider' => $provider, 'method' => $method, 'status' => PaymentStatus::Pending]);

        app(OrderLifecycle::class)->applyPendingCancellation($order);

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->order_status);
        $this->assertDeletedMessages([701, 702]);
        app(TelegramBuyerCheckoutInstructionCleaner::class)->deleteTrackedMessages($order);
        $this->assertDeletedMessages([701, 702]);
    }

    #[DataProvider('qrisProviders')]
    public function test_paid_qris_is_kept_until_receipt_is_delivered(string $provider, string $method): void
    {
        $order = Order::factory()->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'manual_checkout_customer_notification_chat_id' => '777',
            'manual_checkout_customer_notification_message_id' => 701,
        ]);
        Payment::factory()->for($order)->create(['provider' => $provider, 'method' => $method, 'status' => PaymentStatus::Paid]);
        $cleaner = app(TelegramBuyerCheckoutInstructionCleaner::class);

        $cleaner->deleteTrackedMessages($order);
        Http::assertNothingSent();
        $order->update(['telegram_receipt_sent_at' => now()]);
        $cleaner->deleteTrackedMessages($order);
        $this->assertDeletedMessages([701]);
    }

    public function test_cancellation_removes_recovered_checkout_messages_without_deleting_the_receipt(): void
    {
        $order = Order::factory()->create([
            'order_status' => OrderStatus::Cancelled,
            'manual_checkout_customer_notification_chat_id' => '777',
            'manual_checkout_customer_notification_message_id' => 701,
        ]);
        foreach ([701, 703] as $messageId) {
            TelegramOutboundEffect::factory()->create([
                'source_type' => Order::class,
                'source_id' => $order->id,
                'effect_type' => TelegramOutboundEffectService::TYPE_SAKURUPIAH_CHECKOUT,
                'state' => TelegramOutboundEffectState::Sent,
                'telegram_chat_id' => '777',
                'telegram_message_id' => (string) $messageId,
                'sent_at' => now(),
            ]);
        }
        TelegramOutboundEffect::factory()->create([
            'source_type' => Order::class,
            'source_id' => $order->id,
            'effect_type' => TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS,
            'state' => TelegramOutboundEffectState::Sent,
            'telegram_chat_id' => '777',
            'telegram_message_id' => '704',
            'sent_at' => now(),
        ]);

        $cleaner = app(TelegramBuyerCheckoutInstructionCleaner::class);
        $cleaner->deleteTrackedMessages($order);
        $cleaner->deleteTrackedMessages($order);

        $this->assertDeletedMessages([701, 703]);
    }

    public function test_checkout_response_arriving_after_cancellation_is_cleaned_up_immediately(): void
    {
        $order = Order::factory()->create([
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'expires_at' => now()->addMinutes(15),
        ]);
        $order->customer->update(['telegram_id' => '777']);
        Payment::factory()->for($order)->create([
            'provider' => 'manual',
            'method' => 'QR_MANUAL',
            'status' => PaymentStatus::Pending,
        ]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($order) {
            if (str_ends_with($request->url(), '/sendPhoto') || str_ends_with($request->url(), '/sendMessage')) {
                app(OrderLifecycle::class)->applyPendingCancellation($order);

                return Http::response(['ok' => true, 'result' => ['message_id' => 703]]);
            }

            return Http::response(['ok' => true]);
        });
        $effect = app(TelegramOutboundEffectService::class)->materializeCheckoutEffectForOrder(
            (string) $order->id,
            TelegramOutboundEffectService::TYPE_MANUAL_CHECKOUT,
        );

        app(CheckoutInstructionDeliveryPipeline::class)->deliver($effect, app(TelegramCheckoutIntents::class));

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->order_status);
        $this->assertDeletedMessages([703]);
    }

    public function test_recovery_deletes_ledger_only_checkout_messages(): void
    {
        $order = Order::factory()->create(['order_status' => OrderStatus::Cancelled]);
        TelegramOutboundEffect::factory()->create([
            'source_type' => Order::class,
            'source_id' => $order->id,
            'effect_type' => TelegramOutboundEffectService::TYPE_SAKURUPIAH_CHECKOUT,
            'state' => TelegramOutboundEffectState::Sent,
            'telegram_chat_id' => '777',
            'telegram_message_id' => '703',
            'sent_at' => now(),
        ]);

        $this->artisan('telegram:recover-outbound', ['--chunk' => 1])->assertSuccessful();
        $this->artisan('telegram:recover-outbound', ['--chunk' => 1])->assertSuccessful();

        $this->assertDeletedMessages([703]);
    }

    public static function qrisProviders(): array
    {
        return [
            'KlikQRIS' => ['klikqris', 'QRIS'],
            'Sakurupiah' => ['sakurupiah', 'QRISC'],
            'historical Pakasir' => ['pakasir', 'QRIS'],
            'manual QRIS' => ['manual', 'QR_MANUAL'],
        ];
    }

    private function assertDeletedMessages(array $expected): void
    {
        $requests = Http::recorded(fn ($request) => str_ends_with($request->url(), '/deleteMessage'));
        $this->assertEqualsCanonicalizing($expected, $requests->map(fn (array $record) => (int) $record[0]['message_id'])->values()->all());
        foreach ($requests as [$request]) {
            $this->assertSame('777', (string) $request['chat_id']);
        }
    }
}
