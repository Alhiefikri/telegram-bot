<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Enums\UserRole;
use App\Jobs\ProcessTelegramUpdate;
use App\Jobs\SettleTelegramStarsUpdate;
use App\Models\ManualFulfillmentReview;
use App\Models\Payment;
use App\Models\TelegramOutboundEffect;
use App\Models\User;
use App\Services\Telegram\TelegramOrderIntents;
use App\Services\TelegramStars\TelegramStarsInvoiceOutbound;
use App\Services\TelegramStars\TelegramStarsOperations;
use App\Services\TelegramStars\TelegramStarsRefunds;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\BuildsStarsTransport;
use Tests\Concerns\RunsPostgresWorkers;
use Tests\Support\PostgresTestDatabase;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
final class TelegramStarsRefundPostgresConcurrencyTest extends TestCase
{
    use BuildsStarsTransport;
    use RunsPostgresWorkers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requirePostgresConcurrency('Telegram Stars refunds');
        $this->assertSame(0, PostgresTestDatabase::migrateFresh());
        $this->configureStarsTransport();
    }

    public function test_two_owner_requests_race_and_only_one_calls_telegram_after_commit(): void
    {
        [$payment, $owner, $receipt] = $this->paid();
        $target = $payment->telegramStarsDetail;
        $this->freezeSecond();
        $results = $this->runConcurrentWorkers(2, function () use ($payment, $owner, $receipt, $target): string {
            Http::fake(['*/refundStarPayment' => function (Request $request) use ($target) {
                $this->assertSame(0, DB::transactionLevel());
                $this->assertSame(['user_id' => $target->telegram_user_id, 'telegram_payment_charge_id' => 'fixture-charge'], $request->data());
                $this->assertDatabaseHas('telegram_stars_refunds', ['state' => 'pending']);

                return Http::response(['ok' => true, 'result' => true]);
            }]);
            app(TelegramStarsRefunds::class)->request($owner, $payment, $receipt, 'Buyer request');

            return (string) Http::recorded()->count();
        }, 'stars-refund-race');
        $this->assertWorkersSucceeded($results);
        $this->assertSame(1, array_sum(array_column($results, 'value')));
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Refunded, $payment->order->fresh()->payment_status);
        $this->assertSame(OrderStatus::Completed, $payment->order->fresh()->order_status);
        $this->assertDatabaseCount('telegram_stars_refunds', 1);
        $this->assertDatabaseHas('telegram_stars_refunds', ['state' => 'confirmed', 'confirmation_source' => 'ack']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'telegram_stars.refund.requested')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'telegram_stars.refund.confirmed')->count());
        $this->assertSame(1, DB::table('inventory_movements')->where('type', 'sale')->count());
        $this->assertDatabaseMissing('inventory_movements', ['type' => 'release']);
        $this->assertDatabaseEmpty('store_balance_entries');
        $this->assertDatabaseEmpty('customer_usdt_balance_entries');
    }

    public function test_timeout_and_mismatched_direction_remain_paid_until_matching_outgoing_history(): void
    {
        [$payment, $owner, $receipt] = $this->paid();
        $target = $payment->telegramStarsDetail;
        $this->freezeSecond();
        Http::fake(['*/refundStarPayment' => Http::failedConnection('ambiguous timeout with private identifiers')]);
        $service = app(TelegramStarsRefunds::class);
        $this->assertSame('review', $service->request($owner, $payment, $receipt, 'Customer requested'));
        $this->assertSame('review', $service->request($owner, $payment, $receipt, 'Do not resend'));
        Http::assertSentCount(1);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->travel(6)->minutes();
        $this->assertSame(1, app(TelegramStarsOperations::class)->counts()['refunds_over_5_min']);

        $partner = [
            'type' => 'user', 'transaction_type' => 'invoice_payment', 'invoice_payload' => $target->invoice_payload,
            'user' => ['id' => $target->telegram_user_id],
        ];
        $row = ['id' => 'fixture-charge', 'amount' => $target->total_xtr, 'date' => now()->timestamp];
        Http::fake(['*/getStarTransactions' => Http::sequence()
            ->push(['ok' => true, 'result' => ['transactions' => [
                $row + ['source' => $partner], // Same ID, but this is an incoming credit.
                $row + ['source' => $partner, 'receiver' => $partner],
                $row + ['receiver' => ['user' => ['id' => $target->telegram_user_id + 1]] + $partner],
            ]]])
            ->push(['ok' => true, 'result' => ['transactions' => [$row + ['receiver' => $partner]]]])]);
        $this->artisan('telegram:report-stars-history', ['--reconcile-refunds' => true])
            ->expectsOutputToContain('ambiguous_direction')->assertFailed();
        $this->assertDatabaseHas('telegram_stars_refund_reviews', ['reason' => 'refund_evidence_mismatch']);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->artisan('telegram:report-stars-history', ['--reconcile-refunds' => true])->assertSuccessful();
        $this->assertDatabaseHas('telegram_stars_refunds', ['state' => 'confirmed', 'confirmation_source' => 'history']);
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $counts = app(TelegramStarsOperations::class)->counts();
        $this->assertEquals($target->total_xtr, $counts['confirmed_refunded_xtr']);
        $this->assertSame(0, $counts['unresolved_refunds']);
    }

    public function test_owner_preview_confirmation_and_lost_webhook_wakeup_during_pause(): void
    {
        [$payment, $owner] = $this->paid(late: true);
        $target = $payment->telegramStarsDetail;
        $order = $payment->order;
        $this->withoutVite();
        $preview = route('admin.stars-refunds.show', $payment);
        $action = route('admin.stars-refunds.store', $payment);
        $form = ['reason' => 'Late payment refund', 'confirm' => '1', 'password' => 'password'];
        $this->get($preview)->assertRedirect(route('admin.login'));
        foreach ([UserRole::Admin, UserRole::Finance, UserRole::Support] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->get(route('admin.stars-refunds.index'))->assertForbidden();
            $this->get($preview)->assertForbidden();
            $this->post($action, $form)->assertForbidden();
        }
        $this->actingAs($owner);
        $this->post($action, $form)->assertSessionHasErrors('reason');
        $this->get(route('admin.stars-refunds.index'))->assertOk()->assertSee('late payment');
        $this->get($preview)->assertOk()->assertSee((string) $target->telegram_user_id)
            ->assertSee($target->total_xtr.' XTR')->assertDontSee('fixture-charge')->assertDontSee($target->invoice_payload);
        $this->post($action, ['password' => 'wrong'] + $form)->assertSessionHasErrors('password');
        Http::assertNothingSent();
        Http::fake(['*/refundStarPayment' => Http::response(['ok' => true, 'result' => 'true'])]); // Not a valid ACK.
        $this->post($action, $form)->assertRedirect($preview)->assertSessionHasNoErrors();
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertDatabaseHas('telegram_stars_refunds', ['state' => 'review']);

        $refund = $this->starsRefund($target, 301);
        $this->starsWebhook($refund, 'wrong')->assertForbidden();
        $this->starsWebhook($this->starsRefund($target, 302, ['total_amount' => $target->total_xtr - 1]))->assertOk();
        $this->artisan('telegram:recover-stars-payments')->assertSuccessful();
        $this->assertDatabaseHas('telegram_stars_refund_reviews', ['reason' => 'refund_evidence_mismatch']);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->starsWebhook($refund)->assertOk();
        $this->starsWebhook($refund)->assertOk();
        Queue::assertNotPushed(ProcessTelegramUpdate::class);
        Queue::assertPushed(SettleTelegramStarsUpdate::class, 3); // Once per distinct financial update.
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->artisan('telegram:recover-stars-payments')->assertSuccessful(); // Simulate a lost queue wake-up.
        $this->artisan('telegram:recover-stars-payments')->expectsOutputToContain('examined=0')->assertSuccessful();
        $this->assertDatabaseHas('telegram_stars_refunds', ['state' => 'confirmed', 'confirmation_source' => 'webhook']);
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Expired, $order->fresh()->order_status);
        $this->assertDatabaseHas('manual_fulfillment_reviews', ['payment_id' => $payment->id, 'state' => ManualFulfillmentReview::STATE_REFUNDED]);
        $this->assertDatabaseMissing('inventory_movements', ['type' => 'sale']);
        $history = app(TelegramOrderIntents::class)->orderHistorySurface($order->customer);
        $this->assertStringContainsString($target->total_xtr.' XTR', $history->keyboard['inline_keyboard'][0][0]['text']);
        $this->assertStringContainsString($target->total_xtr.' XTR', app(TelegramOrderIntents::class)->orderInstruction($order->customer, $order->fresh(), $payment->fresh()));
        Http::assertSentCount(1);
    }

    public function test_unsolicited_reversal_is_review_only_and_blocks_a_new_refund(): void
    {
        [$payment, $owner, $receipt] = $this->paid();
        $target = $payment->telegramStarsDetail;
        $this->starsWebhook($this->starsRefund($target, 301))->assertOk();
        $this->artisan('telegram:recover-stars-payments')->assertSuccessful();
        $this->assertDatabaseHas('telegram_stars_refund_reviews', ['reason' => 'external_reversal_review']);
        $service = app(TelegramStarsRefunds::class);
        $this->assertFalse($service->preview($owner, $payment)['eligible']);
        $this->assertDatabaseEmpty('telegram_stars_refunds');
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        Http::assertNothingSent();
        $this->expectException(\DomainException::class);
        $service->request($owner, $payment, $receipt, 'Do not refund again');
    }

    /** @return array{Payment, User, int} */
    private function paid(bool $late = false): array
    {
        $order = $this->starsOrder();
        $payment = $order->payments->sole();
        $target = $payment->telegramStarsDetail;
        $this->acknowledgeStarsInvoiceFixture($target);
        $this->starsWebhook($this->starsQuery($target))->assertJsonPath('ok', true);
        $unsent = $this->starsOrder('paused-invoice-fixture')->payments->sole();
        $effect = TelegramOutboundEffect::where('effect_type', TelegramOutboundEffectType::TelegramStarsInvoice)
            ->where('source_id', $unsent->id)->sole();
        DB::table('stars_pricing_policies')->update(['issuance_enabled' => false]);
        app(TelegramStarsInvoiceOutbound::class)->deliver($effect->id);
        Http::assertNothingSent();
        $this->assertSame(TelegramOutboundEffectState::Pending, $effect->fresh()->state);
        $this->acknowledgeStarsInvoiceFixture($unsent->telegramStarsDetail);
        $this->starsWebhook($this->starsQuery($unsent->telegramStarsDetail, 102))->assertJsonPath('ok', false);
        if ($late) {
            $this->travelTo($order->expires_at->copy()->addSecond());
            $this->artisan('orders:expire-pending')->assertSuccessful();
        }
        $this->starsWebhook($this->starsSuccess($target, 201))->assertOk();
        $this->artisan('telegram:recover-stars-payments')->assertSuccessful();
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);

        return [$payment->fresh(), User::factory()->create(['role' => UserRole::Owner]), (int) DB::table('telegram_stars_settlements')->sole()->external_money_receipt_id];
    }
}
