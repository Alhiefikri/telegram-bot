<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Models\Customer;
use App\Models\Order;
use App\Models\TelegramOutboundEffect;
use App\Services\Telegram\TelegramCredentialPinService;
use App\Support\QueueTopology;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\RunsPostgresRace;
use Tests\TestCase;

/**
 * Two real processes race the same Order's credential pin. The atomic claim
 * must let exactly one pin a message and keep one canonical pointer.
 */
#[Group('concurrency')]
#[Group('postgres-release')]
final class TelegramCredentialPinPostgresConcurrencyTest extends TestCase
{
    use RunsPostgresRace;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->concurrencyLaneAvailable()) {
            $this->markTestSkipped('Pin races require the isolated PostgreSQL concurrency lane.');
        }

        $this->assertSame(0, PostgresTestDatabase::migrateFresh());
    }

    public function test_two_workers_pin_exactly_one_canonical_message(): void
    {
        [$order, $first, $second] = $this->pinCandidates();

        $results = $this->race([
            fn (): bool => $this->pinInWorker((string) $order->getKey(), (string) $first->getKey()),
            fn (): bool => $this->pinInWorker((string) $order->getKey(), (string) $second->getKey()),
        ]);

        $this->assertSame(1, count(array_filter($results)), 'Exactly one worker may pin and record the pointer.');
        $order->refresh();
        $this->assertSame(
            $results[0] ? 920001 : 920002,
            $order->telegram_credential_message_id,
        );
        $this->assertNotNull($order->telegram_credential_pinned_at);
        $this->assertNull($order->telegram_credential_pin_claim_token);
        $this->assertNull($order->telegram_credential_pin_claimed_at);
    }

    public function test_a_stale_claim_after_worker_death_is_reclaimed_by_one_worker(): void
    {
        [$order, $first] = $this->pinCandidates();
        $order->forceFill([
            'telegram_credential_pin_claim_token' => 'dead-worker-claim',
            'telegram_credential_pin_claimed_at' => now()->subSeconds(TelegramCredentialPinService::CLAIM_LEASE_SECONDS + 5),
        ])->save();

        $results = $this->race([
            fn (): bool => $this->pinInWorker((string) $order->getKey(), (string) $first->getKey()),
            fn (): bool => $this->pinInWorker((string) $order->getKey(), (string) $first->getKey()),
        ]);

        $this->assertSame(1, count(array_filter($results)), 'Only the claim winner may complete the pin.');
        $order->refresh();
        $this->assertSame(920001, $order->telegram_credential_message_id);
        $this->assertNotNull($order->telegram_credential_pinned_at);
        $this->assertNull($order->telegram_credential_pin_claim_token);
    }

    private function pinInWorker(string $orderId, string $effectId): bool
    {
        Http::swap(new Factory);
        Http::fake([
            'https://api.telegram.org/*/pinChatMessage' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $order = Order::query()->with('customer')->findOrFail($orderId);
        $effect = TelegramOutboundEffect::query()->findOrFail($effectId);

        return app(TelegramCredentialPinService::class)->pin($order, $effect);
    }

    /** @return array{Order, TelegramOutboundEffect, TelegramOutboundEffect} */
    private function pinCandidates(): array
    {
        $customer = Customer::factory()->create(['telegram_id' => 987652, 'locale' => 'en']);
        $order = Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
        ]);

        $first = $this->sentEffect($order, 920001);
        $second = $this->sentEffect($order, 920002);

        return [$order->fresh(), $first, $second];
    }

    private function sentEffect(Order $order, int $messageId): TelegramOutboundEffect
    {
        $effect = TelegramOutboundEffect::factory()->create([
            'effect_type' => TelegramOutboundEffectType::ReceiptCredentials->value,
            'source_type' => Order::class,
            'source_id' => (string) $order->getKey(),
            'telegram_chat_id' => (string) $order->customer->telegram_id,
            'queue' => QueueTopology::RECEIPTS,
            'telegram_method' => 'sendMessage',
        ]);
        $effect->forceFill([
            'state' => TelegramOutboundEffectState::Sent->value,
            'attempts' => 1,
            'next_attempt_at' => null,
            'sent_at' => now(),
            'telegram_message_id' => $messageId,
            'outcome_metadata' => ['contains_credentials' => true],
        ])->save();

        return $effect->fresh();
    }

    private function concurrencyLaneAvailable(): bool
    {
        return DB::getDriverName() === 'pgsql'
            && (string) env('RUN_CONCURRENCY_TESTS', '') === '1'
            && function_exists('pcntl_fork');
    }
}
