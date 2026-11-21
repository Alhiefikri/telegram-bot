<?php

namespace Tests\Feature\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\ProcessTelegramUpdate;
use App\Models\Customer;
use App\Models\TelegramUpdate;
use App\Services\CustomerCancellationOutcome;
use App\Services\CustomerPendingOrderCancellation;
use App\Services\OrderLifecycle;
use App\Services\Telegram\TelegramChatInteractionLock;
use App\Services\Telegram\TelegramUpdateProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsStarsTransport;
use Tests\TestCase;

final class TelegramStarsWebhookTest extends TestCase
{
    use BuildsStarsTransport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureStarsTransport();
    }

    public function test_approval_ignores_chat_backlog_is_durable_and_does_not_extend_grace(): void
    {
        $this->freezeSecond();
        $order = $this->starsOrder();
        $target = $order->payments->sole()->telegramStarsDetail;
        $this->acknowledgeStarsInvoiceFixture();
        $this->travelTo($order->expires_at->copy()->subSecond());
        TelegramUpdate::factory()->create(['update_id' => 7, 'processed_at' => null]);

        $query = $this->starsQuery($target);
        $started = hrtime(true);
        app(TelegramChatInteractionLock::class)->withLock((string) $target->chat_id, function () use ($query): void {
            $this->webhook($query)->assertOk()->assertExactJson([
                'method' => 'answerPreCheckoutQuery', 'pre_checkout_query_id' => 'query-101', 'ok' => true,
            ]);
        });
        $this->assertLessThan(2_000_000_000, hrtime(true) - $started);
        $claim = DB::table('telegram_stars_approvals')->sole();
        $this->assertSame($target->payment_id, $claim->payment_id);
        $this->assertTrue((bool) TelegramUpdate::where('update_id', 101)->sole()->stars_approved);
        $this->assertSame(PaymentStatus::Pending, $order->payments()->sole()->status);
        $this->assertDatabaseMissing('inventory_movements', ['order_id' => $order->id, 'type' => 'sale']);
        Queue::assertNotPushed(ProcessTelegramUpdate::class);
        Http::assertNothingSent(); // Answer is the Bot API method in the webhook response.

        $this->travel(2)->seconds();
        DB::table('stars_pricing_policies')->update(['issuance_enabled' => false]);
        $this->webhook($query)->assertJsonPath('ok', true);
        $this->webhook($this->starsQuery($target, 102, ['id' => 'query-101']))->assertJsonPath('ok', true);
        $this->webhook($this->starsQuery($target, 103))->assertJsonPath('ok', false);
        $this->assertEquals($claim, DB::table('telegram_stars_approvals')->sole());
        $this->assertFalse(app(OrderLifecycle::class)->applyPendingExpiry($order)->newlyApplied);
        $this->assertFalse(app(OrderLifecycle::class)->applyPaymentExpired($order->payments->sole())->newlyApplied);
        $this->assertSame(CustomerCancellationOutcome::PaymentVerifying, app(CustomerPendingOrderCancellation::class)->cancel($order));

        $this->travel(118)->seconds();
        $this->webhook($query)->assertJsonPath('ok', false);
        $this->assertTrue(app(OrderLifecycle::class)->applyPendingExpiry($order)->newlyApplied);
        $this->assertFalse(app(OrderLifecycle::class)->applyPendingExpiry($order)->newlyApplied);
        $this->assertSame(OrderStatus::Expired, $order->fresh()->order_status);
        $this->assertDatabaseHas('inventory_movements', ['order_id' => $order->id, 'type' => 'release', 'quantity' => -1]);
    }

    public function test_missing_secret_wrong_identity_amount_payload_gate_and_reservation_fail_closed(): void
    {
        $target = $this->starsOrder()->payments->sole()->telegramStarsDetail;
        $this->webhook($this->starsQuery($target), 'wrong')->assertForbidden();
        config(['services.telegram.webhook_secret' => '']);
        $this->webhook($this->starsQuery($target))->assertForbidden();
        $this->assertDatabaseEmpty('telegram_updates');
        config(['services.telegram.webhook_secret' => 'fixture-secret']);

        $this->webhook($this->starsQuery($target, 101))->assertJsonPath('ok', false); // No ACK.
        $this->acknowledgeStarsInvoiceFixture();
        foreach ([
            ['from' => ['id' => $target->telegram_user_id + 1]],
            ['total_amount' => $target->total_xtr + 1],
            ['currency' => 'USD'],
            ['invoice_payload' => $target->invoice_payload.' '],
        ] as $index => $override) {
            $this->webhook($this->starsQuery($target, 102 + $index, $override))->assertJsonPath('ok', false);
        }
        $this->webhook($this->starsQuery($target, 110, ['total_amount' => (string) $target->total_xtr]))->assertUnprocessable();
        DB::table('stars_pricing_policies')->update(['issuance_enabled' => false]);
        $this->webhook($this->starsQuery($target, 111))->assertJsonPath('ok', false);
        DB::table('stars_pricing_policies')->update(['issuance_enabled' => true]);
        $this->webhook($this->starsQuery($target, 112, ['id' => 'query-111']))->assertJsonPath('ok', false);
        DB::table('product_stock_items')->update(['order_item_id' => null]);
        $this->webhook($this->starsQuery($target, 113))->assertJsonPath('ok', false);
        $this->assertDatabaseEmpty('telegram_stars_approvals');
    }

    public function test_success_and_refund_are_deduplicated_and_minimized_while_paused_without_entering_chat_processing(): void
    {
        DB::table('stars_pricing_policies')->update(['issuance_enabled' => false]);
        $money = ['currency' => 'XTR', 'total_amount' => 50, 'invoice_payload' => 'opaque', 'telegram_payment_charge_id' => 'tg-charge'];

        foreach (['successful_payment', 'refunded_payment'] as $index => $kind) {
            $message = ['message_id' => 4, 'chat' => ['id' => 44, 'type' => 'private']];
            if ($index === 0) {
                $message['from'] = ['id' => 44];
            }
            $payload = ['update_id' => 301 + $index, 'message' => $message + [
                $kind => $money + ['provider_payment_charge_id' => 'extra-id', 'order_info' => ['email' => 'discard@example.test']],
                'text' => '/start', 'pinned_message' => ['text' => 'discard me'],
            ]];
            $this->webhook($payload)->assertOk()->assertExactJson(['ok' => true]);
            $this->webhook($payload)->assertOk();

            $stored = TelegramUpdate::where('update_id', $payload['update_id'])->sole();
            $this->assertEquals($message + [$kind => $money], $stored->payload['message']);
            app(TelegramUpdateProcessor::class)->process($stored);
            $this->assertNull($stored->fresh()->processed_at);
        }
        $this->artisan('telegram:recover-updates')->expectsOutputToContain('dispatched=0')->assertSuccessful();
        Queue::assertNotPushed(ProcessTelegramUpdate::class);
        $this->assertSame(0, Customer::count());
        $this->assertDatabaseCount('telegram_updates', 2);
        Http::assertNothingSent();
    }

    public function test_inbox_failure_is_not_an_ack_for_money_events(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('This inbox failure fixture uses a SQLite temporary trigger.');
        }
        DB::statement("CREATE TEMP TRIGGER stars_inbox_offline BEFORE INSERT ON telegram_updates BEGIN SELECT RAISE(ABORT, 'inbox unavailable'); END");
        try {
            $this->webhook(['update_id' => 500, 'message' => [
                'message_id' => 1, 'from' => ['id' => 44], 'chat' => ['id' => 44, 'type' => 'private'],
                'successful_payment' => ['currency' => 'XTR', 'total_amount' => 50, 'invoice_payload' => 'opaque', 'telegram_payment_charge_id' => 'tg-charge'],
            ]])->assertServiceUnavailable();
            $this->assertDatabaseEmpty('telegram_updates');
        } finally {
            DB::statement('DROP TRIGGER stars_inbox_offline');
        }
    }

    private function webhook(array $update, string $secret = 'fixture-secret'): TestResponse
    {
        return $this->postJson('/api/webhook/telegram', $update, ['X-Telegram-Bot-Api-Secret-Token' => $secret]);
    }
}
