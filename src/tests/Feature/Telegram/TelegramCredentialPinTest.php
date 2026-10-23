<?php

namespace Tests\Feature\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Jobs\PinTelegramCredentialReceipt;
use App\Jobs\SendTelegramReceipt;
use App\Models\Customer;
use App\Models\Order;
use App\Models\TelegramOutboundEffect;
use App\Services\Telegram\ReceiptDeliveryPipeline;
use App\Services\Telegram\TelegramCredentialPinService;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\Telegram\TelegramReceiptNotifier;
use App\Support\QueueTopology;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Support\TelegramBotTestSupport;

final class TelegramCredentialPinTest extends TelegramBotTestSupport
{
    public function test_receipt_pipeline_queues_the_pin_only_after_a_sent_effect(): void
    {
        Queue::fake();
        $order = $this->eligibleOrder();
        $effect = $this->pendingEffect($order);
        $notifier = Mockery::mock(TelegramReceiptNotifier::class);
        $notifier->shouldReceive('sendEffect')
            ->once()
            ->andReturn(['ok' => true, 'result' => ['message_id' => 777001]]);
        $this->fakeTelegramHttp();

        app(ReceiptDeliveryPipeline::class)->deliver($effect, $notifier);

        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state);
        $this->assertCount(
            0,
            Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/pinChatMessage')),
        );
        Queue::assertPushed(
            PinTelegramCredentialReceipt::class,
            fn (PinTelegramCredentialReceipt $job): bool => $job->orderId === (string) $order->getKey()
                && $job->effectId === (string) $effect->getKey()
                && $job->queue === QueueTopology::PINS,
        );
    }

    public function test_successor_dispatch_is_queued_before_the_pin_work(): void
    {
        Queue::fake();
        $order = $this->eligibleOrder();
        $first = $this->pendingEffect($order);
        $second = $this->pendingEffect($order, ['sequence' => 2]);
        $notifier = Mockery::mock(TelegramReceiptNotifier::class);
        $notifier->shouldReceive('sendEffect')
            ->once()
            ->andReturn(['ok' => true, 'result' => ['message_id' => 777002]]);
        $this->fakeTelegramHttp();

        app(ReceiptDeliveryPipeline::class)->deliver($first, $notifier);

        $queued = array_keys(Queue::pushedJobs());
        $this->assertSame(
            [SendTelegramReceipt::class, PinTelegramCredentialReceipt::class],
            array_slice($queued, 0, 2),
        );
        Queue::assertPushed(
            SendTelegramReceipt::class,
            fn (SendTelegramReceipt $job): bool => $job->effectId === (string) $second->getKey(),
        );
    }

    public function test_ambiguous_delivery_never_queues_a_pin_or_changes_the_pointer(): void
    {
        Queue::fake();
        $order = $this->eligibleOrder();
        $effect = $this->pendingEffect($order);
        $notifier = Mockery::mock(TelegramReceiptNotifier::class);
        $notifier->shouldReceive('sendEffect')
            ->once()
            ->andThrow(new ConnectionException('connection lost after request'));
        $this->fakeTelegramHttp();

        app(ReceiptDeliveryPipeline::class)->deliver($effect, $notifier);

        $this->assertSame(TelegramOutboundEffectState::Unknown, $effect->fresh()->state);
        $this->assertNull($order->fresh()->telegram_credential_message_id);
        $this->assertNull($order->fresh()->telegram_credential_pinned_at);
        Queue::assertNotPushed(PinTelegramCredentialReceipt::class);
    }

    public function test_pin_job_pins_the_sent_credential_message_once(): void
    {
        $order = $this->eligibleOrder();
        $effect = $this->sentEffect($order);
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/pinChatMessage' => Http::response(['ok' => true, 'result' => true]),
        ]);

        (new PinTelegramCredentialReceipt(
            (string) $order->getKey(),
            (string) $effect->getKey(),
        ))->handle(app(TelegramCredentialPinService::class));

        $order->refresh();
        $this->assertSame((string) $order->customer->telegram_id, $order->telegram_credential_message_chat_id);
        $this->assertSame(900001, $order->telegram_credential_message_id);
        $this->assertNotNull($order->telegram_credential_pinned_at);
        $this->assertNull($order->telegram_credential_pin_claim_token);
        $this->assertNull($order->telegram_credential_pin_claimed_at);

        $pinRequests = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/pinChatMessage'));
        $this->assertCount(1, $pinRequests);
        $this->assertSame((string) $order->customer->telegram_id, (string) $pinRequests->sole()[0]['chat_id']);
        $this->assertSame('900001', (string) $pinRequests->sole()[0]['message_id']);
        $this->assertTrue(filter_var($pinRequests->sole()[0]['disable_notification'], FILTER_VALIDATE_BOOLEAN));

        $this->assertFalse(app(TelegramCredentialPinService::class)->pin($order->fresh()->load('customer'), $effect->fresh()));
        $this->assertCount(
            1,
            Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/pinChatMessage')),
        );
    }

    public function test_failed_pin_keeps_the_pointer_empty_and_the_existing_recovery_owner_pins_once(): void
    {
        $order = $this->eligibleOrder();
        $effect = $this->sentEffect($order);
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/pinChatMessage' => Http::response(['ok' => false, 'description' => 'Bad Request'], 400),
        ]);

        $this->assertFalse(app(TelegramCredentialPinService::class)->pin($order->fresh()->load('customer'), $effect));
        $order->refresh();
        $this->assertNull($order->telegram_credential_message_id);
        $this->assertNull($order->telegram_credential_pinned_at);
        $this->assertNull($order->telegram_credential_pin_claim_token);
        $this->assertNull($order->telegram_credential_pin_claimed_at);

        Http::swap(new Factory);
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/pinChatMessage' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $this->artisan('telegram:recover-outbound', ['--chunk' => 25])->assertSuccessful();
        $order->refresh();
        $this->assertSame(900001, $order->telegram_credential_message_id);
        $this->assertNotNull($order->telegram_credential_pinned_at);
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state);
        $this->assertSame(1, (int) $effect->fresh()->attempts);
        $this->assertCount(
            1,
            Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/pinChatMessage')),
        );

        $this->artisan('telegram:recover-outbound', ['--chunk' => 25])->assertSuccessful();
        $this->assertSame(900001, $order->fresh()->telegram_credential_message_id);
        $this->assertCount(
            1,
            Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/pinChatMessage')),
        );
    }

    public function test_pin_refuses_unknown_foreign_noncredential_and_already_pinned_effects(): void
    {
        $order = $this->eligibleOrder();
        $otherOrder = $this->eligibleOrder(998878);
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/pinChatMessage' => Http::response(['ok' => true, 'result' => true]),
        ]);
        $service = app(TelegramCredentialPinService::class);

        $unknown = $this->sentEffect($order, [
            'state' => TelegramOutboundEffectState::Unknown->value,
            'telegram_message_id' => 900002,
        ]);
        $foreignChat = $this->sentEffect($order, [
            'telegram_chat_id' => '123456',
            'telegram_message_id' => 900003,
        ]);
        $withoutCredentials = $this->sentEffect($order, [
            'telegram_message_id' => 900004,
            'outcome_metadata' => ['unit_role' => 'invoice'],
        ]);
        $foreignSource = $this->sentEffect($otherOrder, ['telegram_message_id' => 900005]);

        $this->assertFalse($service->pin($order->fresh()->load('customer'), $unknown));
        $this->assertFalse($service->pin($order->fresh()->load('customer'), $foreignChat));
        $this->assertFalse($service->pin($order->fresh()->load('customer'), $withoutCredentials));
        $this->assertFalse($service->pin($order->fresh()->load('customer'), $foreignSource));
        $this->assertCount(
            0,
            Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/pinChatMessage')),
        );

        $pinnedOrder = $order->fresh();
        $pinnedOrder->forceFill([
            'telegram_credential_message_chat_id' => (string) $pinnedOrder->customer->telegram_id,
            'telegram_credential_message_id' => 900006,
            'telegram_credential_pinned_at' => now(),
        ])->save();
        $later = $this->sentEffect($order, ['sequence' => 2, 'telegram_message_id' => 900007]);

        $this->assertFalse($service->pin($pinnedOrder->load('customer'), $later));
        $this->assertSame(900006, $order->fresh()->telegram_credential_message_id);
        $this->assertCount(
            0,
            Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/pinChatMessage')),
        );
    }

    public function test_a_lost_pin_wakeup_is_recovered_without_resending_the_receipt(): void
    {
        $order = $this->eligibleOrder();
        $effect = $this->sentEffect($order);
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/pinChatMessage' => Http::response(['ok' => true, 'result' => true]),
        ]);

        // No pin job ran: this is the Redis wakeup-loss shape.
        $this->assertNull($order->fresh()->telegram_credential_pinned_at);

        $this->artisan('telegram:recover-outbound', ['--chunk' => 25])->assertSuccessful();

        $order->refresh();
        $this->assertSame(900001, $order->telegram_credential_message_id);
        $this->assertNotNull($order->telegram_credential_pinned_at);
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state);
        $this->assertSame(1, (int) $effect->fresh()->attempts);
        $this->assertCount(
            1,
            Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/pinChatMessage')),
        );
    }

    public function test_a_fresh_claim_blocks_and_a_stale_claim_is_reclaimed(): void
    {
        $order = $this->eligibleOrder();
        $fresh = $this->sentEffect($order);
        $service = app(TelegramCredentialPinService::class);
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/pinChatMessage' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $order->forceFill([
            'telegram_credential_pin_claim_token' => 'live-claim',
            'telegram_credential_pin_claimed_at' => now(),
        ])->save();

        $this->assertFalse($service->pin($order->fresh()->load('customer'), $fresh));
        $this->assertCount(
            0,
            Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/pinChatMessage')),
        );
        $this->assertSame('live-claim', $order->fresh()->telegram_credential_pin_claim_token);

        $order->forceFill([
            'telegram_credential_pin_claimed_at' => now()->subSeconds(TelegramCredentialPinService::CLAIM_LEASE_SECONDS + 1),
        ])->save();

        $this->assertTrue($service->pin($order->fresh()->load('customer'), $fresh->fresh()));
        $order->refresh();
        $this->assertSame(900001, $order->telegram_credential_message_id);
        $this->assertNull($order->telegram_credential_pin_claim_token);
        $this->assertNull($order->telegram_credential_pin_claimed_at);
        $this->assertCount(
            1,
            Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/pinChatMessage')),
        );
    }

    public function test_recovery_pins_only_the_first_canonical_credential_message(): void
    {
        $order = $this->eligibleOrder();
        $first = $this->sentEffect($order, ['sequence' => 1, 'telegram_message_id' => 910001]);
        $second = $this->sentEffect($order, ['sequence' => 2, 'telegram_message_id' => 910002]);
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/pinChatMessage' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $pinned = app(TelegramCredentialPinService::class)->reconcileMissingPins(100);

        $this->assertSame(1, $pinned);
        $this->assertSame(910001, $order->fresh()->telegram_credential_message_id);
        $requests = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/pinChatMessage'));
        $this->assertCount(1, $requests);
        $this->assertSame('910001', (string) $requests->sole()[0]['message_id']);
        $this->assertSame((string) $first->getKey(), (string) $first->fresh()->getKey());
        $this->assertSame((string) $second->getKey(), (string) $second->fresh()->getKey());
    }

    private function eligibleOrder(int $telegramId = 998877): Order
    {
        $customer = Customer::factory()->create([
            'telegram_id' => $telegramId,
            'locale' => 'en',
        ]);

        return Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => 10_000,
            'currency' => 'IDR',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function sentEffect(Order $order, array $overrides = []): TelegramOutboundEffect
    {
        $effect = $this->baseEffect($order);
        $effect->forceFill(array_merge([
            'state' => TelegramOutboundEffectState::Sent->value,
            'attempts' => 1,
            'next_attempt_at' => null,
            'sent_at' => now(),
            'telegram_message_id' => 900001,
            'outcome_metadata' => ['contains_credentials' => true],
        ], $overrides))->save();

        return $effect->fresh();
    }

    /** @param array<string, mixed> $overrides */
    private function pendingEffect(Order $order, array $overrides = []): TelegramOutboundEffect
    {
        $effect = $this->baseEffect($order);
        $effect->forceFill(array_merge([
            'state' => TelegramOutboundEffectState::Pending->value,
            'next_attempt_at' => now(),
            'outcome_metadata' => ['contains_credentials' => true],
        ], $overrides))->save();

        return $effect->fresh();
    }

    private function baseEffect(Order $order): TelegramOutboundEffect
    {
        return TelegramOutboundEffect::factory()->create([
            'effect_type' => TelegramOutboundEffectType::ReceiptCredentials->value,
            'source_type' => Order::class,
            'source_id' => (string) $order->getKey(),
            'telegram_chat_id' => (string) $order->customer->telegram_id,
            'queue' => QueueTopology::RECEIPTS,
            'telegram_method' => 'sendMessage',
            'effect_group_key' => TelegramOutboundEffectService::receiptEffectGroupKey((string) $order->getKey()),
            'template_version' => TelegramOutboundEffectService::TEMPLATE_RECEIPT_TEXT_FALLBACK,
        ]);
    }
}
