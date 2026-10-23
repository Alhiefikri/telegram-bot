<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\TelegramOutboundEffect;
use App\Services\Telegram\ReceiptDeliveryPipeline;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\RunsPostgresRace;
use Tests\TestCase;

/**
 * Two real worker processes race the same receipt wakeup. The ledger must let
 * exactly one own each ordered chunk and the loser must not resend a chunk.
 */
#[Group('concurrency')]
#[Group('postgres-release')]
final class TelegramReceiptPostgresConcurrencyTest extends TestCase
{
    use RunsPostgresRace;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->concurrencyLaneAvailable()) {
            $this->markTestSkipped('Receipt races require the isolated PostgreSQL concurrency lane.');
        }

        // The receipt notifier refuses to build a payload without a bot token,
        // and CI intentionally ships an empty .env.
        config(['services.telegram.token' => 'receipt-race-token']);

        $this->assertSame(0, PostgresTestDatabase::migrateFresh());
    }

    public function test_two_workers_keep_multi_chunk_receipt_order_and_deliver_each_chunk_once(): void
    {
        [$order, $first, $effects] = $this->multiChunkReceipt();
        $this->assertGreaterThan(1, $effects->count(), 'The fixture must produce more than one receipt chunk.');

        $deliver = function () use ($first): int {
            Http::swap(new Factory);
            Http::fake([
                'https://api.telegram.org/*/sendPhoto' => Http::response([
                    'ok' => true,
                    'result' => ['message_id' => random_int(700000, 799999), 'photo' => [['file_id' => 'race-photo']]],
                ]),
                'https://api.telegram.org/*/sendMessage' => Http::response([
                    'ok' => true,
                    'result' => ['message_id' => random_int(700000, 799999)],
                ]),
                'https://api.telegram.org/*/sendDocument' => Http::response([
                    'ok' => true,
                    'result' => ['message_id' => random_int(700000, 799999), 'document' => ['file_id' => 'race-document']],
                ]),
                'https://api.telegram.org/*/pinChatMessage' => Http::response(['ok' => true, 'result' => true]),
            ]);

            app(ReceiptDeliveryPipeline::class)->deliver($first->fresh());

            return $this->deliverySends();
        };

        $workerSends = array_sum($this->race([$deliver, $deliver]));

        Http::swap(new Factory);
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => true,
                'result' => ['message_id' => random_int(700000, 799999), 'photo' => [['file_id' => 'race-photo']]],
            ]),
            'https://api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => random_int(700000, 799999)],
            ]),
            'https://api.telegram.org/*/sendDocument' => Http::response([
                'ok' => true,
                'result' => ['message_id' => random_int(700000, 799999), 'document' => ['file_id' => 'race-document']],
            ]),
            'https://api.telegram.org/*/pinChatMessage' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $drainedSends = 0;
        foreach ($effects as $effect) {
            $before = $this->deliverySends();
            app(ReceiptDeliveryPipeline::class)->deliver($effect->fresh(), dispatchNext: false);
            $drainedSends += $this->deliverySends() - $before;
        }

        $credentialEffects = TelegramOutboundEffect::query()
            ->where('effect_group_key', $first->effect_group_key)
            ->orderBy('sequence')
            ->get();
        $allEffects = TelegramOutboundEffect::query()
            ->where('source_type', Order::class)
            ->where('source_id', (string) $order->getKey())
            ->whereIn('effect_type', TelegramOutboundEffectService::RECEIPT_EFFECT_TYPES)
            ->get();

        $this->assertSame(
            $allEffects->count(),
            $workerSends + $drainedSends,
            'Every receipt delivery unit must be sent exactly once across the racing workers.',
        );

        $sentSequences = [];
        foreach ($credentialEffects as $effect) {
            $fresh = $effect->fresh();
            $this->assertSame(TelegramOutboundEffectState::Sent, $fresh->state);
            $this->assertNotNull($fresh->telegram_message_id);
            $this->assertSame(1, $fresh->attempts, 'The losing worker must not consume a claim attempt.');
            $sentSequences[] = (int) $fresh->sequence;
        }

        foreach ($allEffects as $effect) {
            $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state);
        }

        $this->assertSame(range(1, $credentialEffects->count()), $sentSequences);
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
    }

    /**
     * @return array{Order, TelegramOutboundEffect, Collection<int, TelegramOutboundEffect>}
     */
    private function multiChunkReceipt(): array
    {
        $customer = Customer::factory()->create(['telegram_id' => 987651, 'locale' => 'en']);
        $product = Product::factory()->create(['name' => 'Receipt Race Bundle']);
        $order = Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 12,
        ]);
        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        for ($index = 0; $index < 12; $index++) {
            ProductStockItem::create([
                'product_id' => $product->id,
                'order_item_id' => $item->id,
                'secret' => 'race'.$index.'@example.test|Password-'.$index.'-'.str_repeat('y', 180).'|MFA-'.$index,
            ]);
        }

        $order = $order->fresh();
        $first = app(TelegramOutboundEffectService::class)->recordReceipt($order);
        $this->assertNotNull($first);

        $effects = TelegramOutboundEffect::query()
            ->where('effect_group_key', $first->effect_group_key)
            ->orderBy('sequence')
            ->get();

        return [$order, $first, $effects];
    }

    private function deliverySends(): int
    {
        return Http::recorded()
            ->filter(static fn (array $record): bool => preg_match(
                '#/(sendMessage|sendPhoto|sendDocument)$#',
                $record[0]->url(),
            ) === 1)
            ->count();
    }

    private function concurrencyLaneAvailable(): bool
    {
        return DB::getDriverName() === 'pgsql'
            && (string) env('RUN_CONCURRENCY_TESTS', '') === '1'
            && function_exists('pcntl_fork');
    }
}
