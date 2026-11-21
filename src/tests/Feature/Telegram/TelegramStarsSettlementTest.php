<?php

namespace Tests\Feature\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\ProcessTelegramUpdate;
use App\Jobs\SettleTelegramStarsUpdate;
use App\Models\ManualFulfillmentReview;
use App\Models\TelegramUpdate;
use App\Services\OrderLifecycle;
use App\Services\TelegramStars\TelegramStarsChargeIdentity;
use App\Services\TelegramStars\TelegramStarsSettlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsStarsTransport;
use Tests\TestCase;

final class TelegramStarsSettlementTest extends TestCase
{
    use BuildsStarsTransport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureStarsTransport();
    }

    public function test_lost_wakeup_is_recovered_while_paused_and_replays_do_not_repeat_paid_effects(): void
    {
        $order = $this->starsOrder();
        $target = $order->payments->sole()->telegramStarsDetail;
        $this->acknowledgeStarsInvoiceFixture();
        $this->starsWebhook($this->starsQuery($target))->assertJsonPath('ok', true);
        DB::table('stars_pricing_policies')->update(['issuance_enabled' => false, 'idr_per_usd' => 29_000]);
        config(['services.telegram.token' => '123456:rotated-fixture']);

        $success = $this->starsSuccess($target, 201);
        $this->starsWebhook($success, 'wrong-secret')->assertForbidden();
        $this->assertDatabaseMissing('telegram_updates', ['update_id' => 201]);
        $this->starsWebhook($success)->assertOk();
        $this->starsWebhook($success)->assertOk();
        $this->starsWebhook($this->starsSuccess($target, 201, ['telegram_payment_charge_id' => 'another']))->assertServiceUnavailable();
        Queue::assertPushed(SettleTelegramStarsUpdate::class, 1);
        Queue::assertNotPushed(ProcessTelegramUpdate::class);
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);

        // Do not run the enqueued job: the scheduler's durable scan must
        // authorize the original order without contacting Telegram.
        $this->artisan('telegram:recover-stars-payments')->assertSuccessful();
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame($target->total_xtr, $order->payments()->sole()->amount);
        $this->assertDatabaseHas('external_money_receipts', [
            'provider' => 'telegram_stars', 'currency' => 'XTR', 'amount_units' => $target->total_xtr,
            'reference' => TelegramStarsChargeIdentity::hash('fixture-charge'),
        ]);
        $this->assertDatabaseHas('inventory_movements', ['order_id' => $order->id, 'type' => 'sale', 'quantity' => -1]);
        $effects = DB::table('telegram_outbound_effects')->count();
        $paidAt = $order->payments()->sole()->paid_at;

        $this->travel(1)->minutes();
        $this->starsWebhook($this->starsSuccess($target, 202))->assertOk();
        $this->starsWebhook($this->starsSuccess($target, 203, ['telegram_payment_charge_id' => 'another']))->assertOk();
        $this->starsWebhook($this->starsSuccess($target, 204, ['telegram_payment_charge_id' => 'another']))->assertOk();
        $this->artisan('telegram:recover-stars-payments')->assertSuccessful();
        $this->artisan('telegram:recover-stars-payments')->expectsOutputToContain('examined=0')->assertSuccessful();
        $this->assertEquals($paidAt, $order->payments()->sole()->paid_at);
        $this->assertDatabaseCount('external_money_receipts', 2); // Additional charge is claimed for review, not spent.
        $this->assertDatabaseCount('telegram_stars_settlements', 1);
        $this->assertDatabaseCount('telegram_stars_payment_reviews', 1);
        $this->assertDatabaseHas('telegram_stars_payment_reviews', ['reason' => 'additional_charge', 'payment_id' => $target->payment_id]);
        $this->assertSame(1, DB::table('inventory_movements')->where('type', 'sale')->count());
        $this->assertDatabaseCount('telegram_outbound_effects', $effects);
        $this->assertDatabaseEmpty('store_balance_entries');
        $this->assertDatabaseEmpty('customer_usdt_balance_entries');
        Http::assertNothingSent();
    }

    public function test_wrong_amount_currency_actor_chat_and_unapproved_or_ambiguous_evidence_cannot_sell(): void
    {
        $order = $this->starsOrder();
        $target = $order->payments->sole()->telegramStarsDetail;
        $this->acknowledgeStarsInvoiceFixture();
        $this->consume($this->starsSuccess($target, 210));
        $this->starsWebhook($this->starsQuery($target))->assertJsonPath('ok', true);
        foreach ([
            [$this->starsSuccess($target, 211, ['total_amount' => $target->total_xtr + 1]), 'amount_mismatch'],
            [$this->starsSuccess($target, 212, ['currency' => 'USD']), 'currency_mismatch'],
            [$this->starsSuccess($target, 213, [], ['from' => ['id' => $target->telegram_user_id + 1]]), 'actor_mismatch'],
            [$this->starsSuccess($target, 214, ['telegram_payment_charge_id' => 'group'], ['chat' => ['id' => $target->chat_id, 'type' => 'group']]), 'actor_mismatch'],
            [$this->starsSuccess($target, 215, ['invoice_payload' => $target->invoice_payload.' ']), 'unknown_invoice'],
            [$this->starsSuccess($target, 216, ['is_recurring' => true]), 'unsupported_subscription'],
        ] as [$payload, $reason]) {
            $this->consume($payload);
            $this->assertDatabaseHas('telegram_stars_payment_reviews', [
                'reason' => $reason, 'telegram_update_id' => TelegramUpdate::where('update_id', $payload['update_id'])->value('id'),
            ]);
        }
        $this->assertDatabaseHas('telegram_stars_payment_reviews', ['reason' => 'missing_approval']);

        $success = $this->starsSuccess($target, 217);
        $refund = $success;
        $refund['update_id'] = 218;
        $refund['message']['refunded_payment'] = $refund['message']['successful_payment'];
        unset($refund['message']['successful_payment']);
        $this->starsWebhook($refund)->assertOk();
        $this->consume($success);
        $this->assertDatabaseHas('telegram_stars_payment_reviews', ['reason' => 'refund_evidence_present']);
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
        $this->assertDatabaseCount('external_money_receipts', 1); // Only the exact charge with reversal evidence.
        $this->assertDatabaseEmpty('telegram_stars_settlements');
        $this->assertDatabaseMissing('inventory_movements', ['order_id' => $order->id, 'type' => 'sale']);
        $this->assertDatabaseCount('telegram_outbound_effects', 1);
        Http::assertNothingSent();
    }

    public function test_late_money_is_paid_and_held_for_review_without_credentials(): void
    {
        config(['services.telegram.admin_telegram_chat_id' => '999']);
        $order = $this->starsOrder();
        $target = $order->payments->sole()->telegramStarsDetail;
        $this->acknowledgeStarsInvoiceFixture();
        $this->starsWebhook($this->starsQuery($target))->assertJsonPath('ok', true);
        $this->travelTo($order->expires_at->copy()->addSecond());
        $this->assertTrue(app(OrderLifecycle::class)->applyPendingExpiry($order)->newlyApplied);
        $this->consume($this->starsSuccess($target, 220));
        $this->consume($this->starsSuccess($target, 221));
        $this->assertSame(OrderStatus::Expired, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertDatabaseHas('telegram_stars_settlements', ['payment_id' => $target->payment_id, 'late' => true]);
        $this->assertDatabaseHas('manual_fulfillment_reviews', [
            'order_id' => $order->id, 'currency' => 'XTR', 'amount' => $target->total_xtr, 'reason' => ManualFulfillmentReview::REASON_LATE_PAYMENT,
        ]);
        $this->assertDatabaseCount('manual_fulfillment_reviews', 1);
        $this->assertDatabaseHas('inventory_movements', ['order_id' => $order->id, 'type' => 'release']);
        $this->assertDatabaseMissing('inventory_movements', ['order_id' => $order->id, 'type' => 'sale']);
        $this->assertDatabaseCount('telegram_outbound_effects', 2); // Invoice + owner review only.
        Http::assertNothingSent();
    }

    public function test_bot_namespaces_are_stable_across_rotation_but_do_not_authorize_another_bot(): void
    {
        $target = $this->starsOrder()->payments->sole()->telegramStarsDetail;
        $this->acknowledgeStarsInvoiceFixture();
        $this->starsWebhook($this->starsQuery($target))->assertJsonPath('ok', true);
        config(['services.telegram.token' => '654321:fixture-token']);
        $this->starsWebhook($this->starsQuery($target, 102))->assertJsonPath('ok', false);
        $this->consume($this->starsSuccess($target, 230));
        $this->assertDatabaseHas('telegram_stars_payment_reviews', ['reason' => 'missing_approval']);
        config(['services.telegram.token' => '123456:new-token']);
        $this->consume($this->starsSuccess($target, 231));
        $this->assertDatabaseCount('telegram_stars_settlements', 1);
        Http::assertNothingSent();
    }

    private function consume(array $payload): void
    {
        $this->starsWebhook($payload)->assertOk();
        $id = TelegramUpdate::where('update_id', $payload['update_id'])->value('id');
        app(TelegramStarsSettlement::class)->process($id);
        $this->assertNotNull(TelegramUpdate::findOrFail($id)->processed_at);
    }
}
