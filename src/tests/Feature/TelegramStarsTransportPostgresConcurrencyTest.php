<?php

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Enums\UserRole;
use App\Exceptions\TelegramStarsPaymentVerifying;
use App\Jobs\SendTelegramStarsInvoice;
use App\Models\InventoryMovement;
use App\Models\TelegramOutboundEffect;
use App\Models\User;
use App\Services\OrderLifecycle;
use App\Services\Telegram\TelegramOutboundEffectLedgerProtocol;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\TelegramStars\TelegramStarsInvoiceOutbound;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\BuildsStarsTransport;
use Tests\Concerns\RunsPostgresWorkers;
use Tests\Support\PostgresTestDatabase;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
final class TelegramStarsTransportPostgresConcurrencyTest extends TestCase
{
    use BuildsStarsTransport;
    use RunsPostgresWorkers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requirePostgresConcurrency('Telegram Stars transport');
        $this->assertSame(0, PostgresTestDatabase::migrateFresh());
        $this->configureStarsTransport();
    }

    public function test_invoice_send_is_private_after_commit_and_records_one_ack(): void
    {
        $order = $this->starsOrder();
        $target = $order->payments->sole()->telegramStarsDetail;
        $effect = TelegramOutboundEffect::sole();
        Http::assertNothingSent();
        Queue::fake(); // Drop the first post-commit wakeup.
        $this->artisan('telegram:recover-outbound')->assertSuccessful();
        Queue::assertPushed(SendTelegramStarsInvoice::class, fn ($job): bool => $job->effectId === $effect->id);
        Http::fake(['*/sendInvoice' => function () use ($target) {
            $this->assertSame(0, DB::transactionLevel());

            return Http::response(['ok' => true, 'result' => ['message_id' => 701, 'chat' => ['id' => $target->chat_id, 'type' => 'private']]]);
        }]);

        $sender = app(TelegramStarsInvoiceOutbound::class);
        $sender->deliver($effect->id);
        $sender->deliver($effect->id);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->data() === [
            'chat_id' => $target->chat_id,
            'title' => 'Telegram Stars',
            'description' => 'Order '.$target->merchant_ref.' · '.$target->total_xtr.' XTR',
            'payload' => $target->invoice_payload,
            'provider_token' => '',
            'currency' => 'XTR',
            'prices' => [['label' => 'Telegram Stars', 'amount' => $target->total_xtr]],
            'start_parameter' => 'stars-invoice',
        ]);
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state);
        $this->assertSame('701', (string) $effect->fresh()->telegram_message_id);
        $this->assertTrue($this->approve($this->starsQuery($target)));
    }

    public function test_invoice_timeout_remains_unknown_and_is_not_automatically_resent(): void
    {
        $target = $this->starsOrder()->payments->sole()->telegramStarsDetail;
        $effect = TelegramOutboundEffect::sole();
        $attempts = 0;
        $canAck = false;
        Http::fake(['*/sendInvoice' => function () use (&$attempts, &$canAck, $target) {
            $attempts++;

            return $canAck
                ? Http::response(['ok' => true, 'result' => ['message_id' => 702, 'chat' => ['id' => $target->chat_id, 'type' => 'private']]])
                : Http::failedConnection('simulated timeout');
        }]);
        $sender = app(TelegramStarsInvoiceOutbound::class);

        DB::table('stars_pricing_policies')->update(['issuance_enabled' => false]);
        $sender->deliver($effect->id);
        $this->assertSame(TelegramOutboundEffectState::Pending, $effect->fresh()->state);
        DB::table('stars_pricing_policies')->update(['issuance_enabled' => true]);
        $sender->deliver($effect->id);
        $sender->deliver($effect->id);

        $this->assertSame(1, $attempts);
        $this->assertSame(TelegramOutboundEffectState::Unknown, $effect->fresh()->state);
        $this->assertNull($effect->fresh()->telegram_message_id);
        $this->assertFalse(app(TelegramOutboundEffectLedgerProtocol::class)->readyToDispatchQuery()->whereKey($effect->id)->exists());
        $this->assertFalse($this->approve($this->starsQuery($target)));
        $this->assertDatabaseCount('orders', 1);

        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $resend = app(TelegramOutboundEffectService::class)->resend($effect->fresh(), $owner);
        $canAck = true;
        $sender->deliver($resend->id);
        $this->assertSame(2, $attempts);
        Http::assertSent(fn ($request) => $request['payload'] === $target->invoice_payload
            && $request['chat_id'] === $target->chat_id);
        $this->assertSame($effect->source_id, $resend->source_id);
        $this->assertSame(TelegramOutboundEffectState::Sent, $resend->fresh()->state);
        $this->assertFalse($this->approve($this->starsQuery($target))); // Prior denial doesn't flip.
        $this->assertTrue($this->approve($this->starsQuery($target, 102)));
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_two_queries_compete_for_one_claim_and_the_http_lock_wait_is_bounded(): void
    {
        $order = $this->starsOrder();
        $target = $order->payments->sole()->telegramStarsDetail;
        $this->acknowledgeStarsInvoiceFixture();
        config(['database.connections.stars_blocker' => config('database.connections.pgsql')]);
        $blocker = DB::connection('stars_blocker');
        $blocker->beginTransaction();
        try {
            $blocker->table('orders')->where('id', $order->id)->lockForUpdate()->first();
            $started = hrtime(true);
            $this->assertFalse($this->approve($this->starsQuery($target, 100)));
            $this->assertLessThan(2_000_000_000, hrtime(true) - $started);
            $this->assertDatabaseEmpty('telegram_stars_approvals');
        } finally {
            $blocker->rollBack();
            DB::purge('stars_blocker');
        }
        $this->assertFalse($this->approve($this->starsQuery($target, 100)));
        $this->assertDatabaseHas('telegram_updates', ['update_id' => 100, 'stars_approved' => false]);

        $results = $this->runConcurrentWorkers(2, fn (int $index): string => $this->approve($this->starsQuery($target, 201 + $index)) ? 'approved' : 'denied', 'stars-approval');
        $this->assertWorkersSucceeded($results);
        $this->assertEqualsCanonicalizing(['approved', 'denied'], array_column($results, 'value'));
        $this->assertDatabaseCount('telegram_stars_approvals', 1);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
    }

    public static function releaseKinds(): array
    {
        return [['cancel'], ['expire']];
    }

    #[DataProvider('releaseKinds')]
    public function test_release_racing_with_approval_cannot_release_an_approved_reservation(string $release): void
    {
        $order = $this->starsOrder();
        $target = $order->payments->sole()->telegramStarsDetail;
        $this->acknowledgeStarsInvoiceFixture();
        $results = $this->runConcurrentWorkers(2, function (int $index) use ($order, $target, $release): string {
            $deadline = $order->expires_at->copy();
            if ($index === 0) {
                $this->travelTo($deadline->copy()->subSecond());

                return $this->approve($this->starsQuery($target)) ? 'approved' : 'denied';
            }

            // Both requests straddle the deadline; select the timestamp at
            // which each actor enters instead of relying on scheduler timing.
            $this->travelTo($deadline);
            try {
                $lifecycle = app(OrderLifecycle::class);
                $outcome = $release === 'cancel' ? $lifecycle->applyPendingCancellation($order) : $lifecycle->applyPendingExpiry($order);

                return $outcome->newlyApplied ? 'released' : 'held';
            } catch (TelegramStarsPaymentVerifying) {
                return 'held';
            }
        }, 'stars-'.$release);

        $this->assertWorkersSucceeded($results);
        $approved = $results[0]['value'] === 'approved';
        $this->assertSame($approved ? 'held' : 'released', $results[1]['value']);
        $this->assertDatabaseCount('telegram_stars_approvals', $approved ? 1 : 0);
        $this->assertSame($approved ? 0 : 1, InventoryMovement::where('type', InventoryMovementType::Release)->count());
        $this->assertSame($approved ? OrderStatus::PendingPayment : ($release === 'cancel' ? OrderStatus::Cancelled : OrderStatus::Expired), $order->fresh()->order_status);
        $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
    }

    private function approve(array $payload): bool
    {
        $response = $this->postJson('/api/webhook/telegram', $payload, ['X-Telegram-Bot-Api-Secret-Token' => 'fixture-secret'])->assertOk();
        $this->assertIsBool($response->json('ok'));

        return $response->json('ok');
    }
}
