<?php

namespace Tests\Feature\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Jobs\SendTelegramReceipt;
use App\Models\Customer;
use App\Models\Order;
use App\Models\TelegramOutboundEffect;
use App\Services\Telegram\ReceiptDeliveryPipeline;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\Telegram\TelegramReceiptNotifier;
use App\Support\QueueTopology;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TelegramBotTestSupport;

final class TelegramReceiptWithoutPinsTest extends TelegramBotTestSupport
{
    public function test_new_credential_receipt_completes_without_pin_activity(): void
    {
        Queue::fake();
        $order = $this->eligibleOrder();
        $effect = $this->pendingEffect($order);
        $notifier = Mockery::mock(TelegramReceiptNotifier::class);
        $notifier->shouldReceive('sendEffect')
            ->once()
            ->andReturn(['ok' => true, 'result' => ['message_id' => 777001]]);
        $this->fakeTelegramHttp();

        $pipeline = app(ReceiptDeliveryPipeline::class);
        $pipeline->deliver($effect, $notifier);
        $pipeline->deliver($effect->fresh(), $notifier);

        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state);
        $this->assertSame('777001', (string) $effect->fresh()->telegram_message_id);
        $this->assertSame(1, (int) $effect->fresh()->attempts);
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
        $this->assertSame($this->emptyPinHistory(), $this->pinHistory($order));
        Queue::assertNotPushed(SendTelegramReceipt::class);
        $this->assertNoPinActivity();
    }

    public function test_multipart_receipt_keeps_successor_ordering_and_completes_only_after_the_last_unit(): void
    {
        Queue::fake();
        $order = $this->eligibleOrder();
        $first = $this->pendingEffect($order);
        $second = $this->pendingEffect($order, ['sequence' => 2]);
        $notifier = Mockery::mock(TelegramReceiptNotifier::class);
        $notifier->shouldReceive('sendEffect')
            ->once()
            ->withArgs(fn (Order $sentOrder, TelegramOutboundEffect $sentEffect): bool => $sentOrder->is($order) && $sentEffect->is($first))
            ->ordered()
            ->andReturn(['ok' => true, 'result' => ['message_id' => 777002]]);
        $notifier->shouldReceive('sendEffect')
            ->once()
            ->withArgs(fn (Order $sentOrder, TelegramOutboundEffect $sentEffect): bool => $sentOrder->is($order) && $sentEffect->is($second))
            ->ordered()
            ->andReturn(['ok' => true, 'result' => ['message_id' => 777003]]);
        $this->fakeTelegramHttp();
        $pipeline = app(ReceiptDeliveryPipeline::class);

        $pipeline->deliver($second, $notifier);
        $this->assertSame(TelegramOutboundEffectState::Pending, $second->fresh()->state);
        $this->assertSame(0, (int) $second->fresh()->attempts);
        $pipeline->deliver($first, $notifier);

        $this->assertSame(TelegramOutboundEffectState::Sent, $first->fresh()->state);
        $this->assertSame(TelegramOutboundEffectState::Pending, $second->fresh()->state);
        $this->assertNull($order->fresh()->telegram_receipt_sent_at);
        Queue::assertPushedTimes(SendTelegramReceipt::class, 1);
        Queue::assertPushed(
            SendTelegramReceipt::class,
            fn (SendTelegramReceipt $job): bool => $job->effectId === (string) $second->getKey()
                && $job->queue === QueueTopology::RECEIPTS,
        );
        $this->assertNoPinActivity();

        $pipeline->deliver($second->fresh(), $notifier);
        $pipeline->deliver($first->fresh(), $notifier);
        $pipeline->deliver($second->fresh(), $notifier);

        $this->assertSame(TelegramOutboundEffectState::Sent, $second->fresh()->state);
        $this->assertSame('777003', (string) $second->fresh()->telegram_message_id);
        $this->assertSame(1, (int) $first->fresh()->attempts);
        $this->assertSame(1, (int) $second->fresh()->attempts);
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
        $this->assertSame($this->emptyPinHistory(), $this->pinHistory($order));
        Queue::assertPushedTimes(SendTelegramReceipt::class, 1);
        $this->assertNoPinActivity();
    }

    public function test_recovery_dispatches_a_lost_successor_without_resending_the_sent_unit(): void
    {
        Queue::fake();
        $order = $this->eligibleOrder();
        $first = $this->sentEffect($order);
        $second = $this->pendingEffect($order, ['sequence' => 2]);
        $this->fakeTelegramHttp();

        $this->artisan('telegram:recover-outbound', ['--chunk' => 25])->assertSuccessful();

        Queue::assertPushedTimes(SendTelegramReceipt::class, 1);
        Queue::assertPushed(
            SendTelegramReceipt::class,
            fn (SendTelegramReceipt $job): bool => $job->effectId === (string) $second->getKey()
                && $job->enqueueStage === SendTelegramReceipt::ENQUEUE_RECOVERY,
        );
        $this->assertSame(TelegramOutboundEffectState::Sent, $first->fresh()->state);
        $this->assertSame(1, (int) $first->fresh()->attempts);
        $this->assertSame(TelegramOutboundEffectState::Pending, $second->fresh()->state);
        $this->assertNull($order->fresh()->telegram_receipt_sent_at);
        $this->assertSame($this->emptyPinHistory(), $this->pinHistory($order));
        Http::assertNothingSent();
        $this->assertNoPinActivity();
    }

    #[DataProvider('historicalPinCases')]
    public function test_repeated_recovery_repairs_receipt_completion_without_resending_or_mutating_pin_history(bool $withHistory): void
    {
        Queue::fake();
        $order = $this->eligibleOrder();
        if ($withHistory) {
            $order->forceFill([
                'telegram_credential_message_chat_id' => (string) $order->customer->telegram_id,
                'telegram_credential_message_id' => 800001,
                'telegram_credential_pinned_at' => now()->subDays(2),
                'telegram_credential_pin_claim_token' => 'historical-abandoned-claim',
                'telegram_credential_pin_claimed_at' => now()->subDay(),
            ])->save();
        }
        $history = $this->pinHistory($order);
        $first = $this->sentEffect($order);
        $second = $this->sentEffect($order, ['sequence' => 2, 'telegram_message_id' => 900002]);
        $this->fakeTelegramHttp();

        $this->artisan('telegram:recover-outbound', ['--chunk' => 25])->assertSuccessful();
        $completedAt = $order->fresh()->getRawOriginal('telegram_receipt_sent_at');
        $this->assertNotNull($completedAt);
        $this->artisan('telegram:recover-outbound', ['--chunk' => 25])->assertSuccessful();

        $this->assertSame($completedAt, $order->fresh()->getRawOriginal('telegram_receipt_sent_at'));
        foreach ([$first, $second] as $effect) {
            $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state);
            $this->assertSame(1, (int) $effect->fresh()->attempts);
            $this->assertSame((string) $effect->telegram_message_id, (string) $effect->fresh()->telegram_message_id);
        }
        $this->assertSame($history, $this->pinHistory($order));
        Queue::assertNotPushed(SendTelegramReceipt::class);
        Http::assertNothingSent();
        $this->assertNoPinActivity();
    }

    public static function historicalPinCases(): array
    {
        return [
            'never pinned' => [false],
            'historical pin and stale claim' => [true],
        ];
    }

    public function test_unknown_credential_delivery_is_not_blindly_resent_by_repeated_recovery(): void
    {
        Queue::fake();
        $order = $this->eligibleOrder();
        $effect = $this->pendingEffect($order);
        $notifier = Mockery::mock(TelegramReceiptNotifier::class);
        $notifier->shouldReceive('sendEffect')
            ->once()
            ->andThrow(new ConnectionException('connection lost after request'));
        $this->fakeTelegramHttp();
        $pipeline = app(ReceiptDeliveryPipeline::class);

        $pipeline->deliver($effect, $notifier);
        $this->artisan('telegram:recover-outbound', ['--chunk' => 25])->assertSuccessful();
        $this->artisan('telegram:recover-outbound', ['--chunk' => 25])->assertSuccessful();
        $pipeline->deliver($effect->fresh(), $notifier);

        $this->assertSame(TelegramOutboundEffectState::Unknown, $effect->fresh()->state);
        $this->assertSame(1, (int) $effect->fresh()->attempts);
        $this->assertNull($effect->fresh()->telegram_message_id);
        $this->assertNull($order->fresh()->telegram_receipt_sent_at);
        $this->assertSame($this->emptyPinHistory(), $this->pinHistory($order));
        Queue::assertNotPushed(SendTelegramReceipt::class);
        Http::assertNothingSent();
        $this->assertNoPinActivity();
    }

    private function assertNoPinActivity(): void
    {
        $this->assertCount(
            0,
            Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/pinChatMessage')
                || str_ends_with($request->url(), '/unpinChatMessage')),
        );
        foreach (Queue::pushedJobs() as $jobs) {
            foreach ($jobs as $queued) {
                $this->assertNotSame('telegram-pins', $queued['job']->queue);
            }
        }
    }

    private function emptyPinHistory(): array
    {
        return [
            'telegram_credential_message_chat_id' => null,
            'telegram_credential_message_id' => null,
            'telegram_credential_pinned_at' => null,
            'telegram_credential_pin_claim_token' => null,
            'telegram_credential_pin_claimed_at' => null,
        ];
    }

    private function pinHistory(Order $order): array
    {
        $history = $order->fresh()->getRawOriginal();

        return array_replace($this->emptyPinHistory(), array_intersect_key($history, $this->emptyPinHistory()));
    }

    private function eligibleOrder(): Order
    {
        $customer = Customer::factory()->create([
            'telegram_id' => 998877,
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
        return $this->pendingEffect($order, array_merge([
            'state' => TelegramOutboundEffectState::Sent->value,
            'attempts' => 1,
            'next_attempt_at' => null,
            'sent_at' => now(),
            'telegram_message_id' => 900001,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function pendingEffect(Order $order, array $overrides = []): TelegramOutboundEffect
    {
        return TelegramOutboundEffect::factory()->create(array_merge([
            'effect_type' => TelegramOutboundEffectType::ReceiptCredentials->value,
            'source_type' => Order::class,
            'source_id' => (string) $order->getKey(),
            'telegram_chat_id' => (string) $order->customer->telegram_id,
            'queue' => QueueTopology::RECEIPTS,
            'telegram_method' => 'sendMessage',
            'effect_group_key' => TelegramOutboundEffectService::receiptEffectGroupKey((string) $order->getKey()),
            'template_version' => TelegramOutboundEffectService::TEMPLATE_RECEIPT_TEXT_FALLBACK,
            'state' => TelegramOutboundEffectState::Pending->value,
            'next_attempt_at' => now(),
            'outcome_metadata' => ['contains_credentials' => true],
        ], $overrides));
    }
}
