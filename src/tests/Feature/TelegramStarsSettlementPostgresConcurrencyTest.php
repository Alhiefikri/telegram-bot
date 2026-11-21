<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\SendTelegramReceipt;
use App\Models\Order;
use App\Models\TelegramUpdate;
use App\Services\OrderLifecycle;
use App\Services\TelegramStars\TelegramStarsSettlement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Concerns\BuildsStarsTransport;
use Tests\Concerns\RunsPostgresWorkers;
use Tests\Support\PostgresTestDatabase;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
final class TelegramStarsSettlementPostgresConcurrencyTest extends TestCase
{
    use BuildsStarsTransport;
    use RunsPostgresWorkers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requirePostgresConcurrency('Telegram Stars settlement');
        $this->assertSame(0, PostgresTestDatabase::migrateFresh());
        $this->configureStarsTransport();
        config(['services.telegram.admin_telegram_chat_id' => '999']);
    }

    public function test_duplicate_update_and_competing_charges_have_one_receipt_sale_and_paid_handoff(): void
    {
        $order = $this->approvedOrder();
        $target = $order->payments->sole()->telegramStarsDetail;
        $updates = [
            $this->store($this->starsSuccess($target, 201)),
            $this->store($this->starsSuccess($target, 202)),
            $this->store($this->starsSuccess($target, 203, ['telegram_payment_charge_id' => 'other-charge'])),
        ];
        $jobs = [$updates[0], $updates[0], ...$updates];
        $results = $this->runConcurrentWorkers(count($jobs), function (int $i) use ($jobs): string {
            app(TelegramStarsSettlement::class)->process($jobs[$i]);

            return (string) Queue::pushed(SendTelegramReceipt::class)->count();
        }, 'stars-charge-race');
        $this->assertWorkersSucceeded($results);
        $this->assertSame(1, array_sum(array_column($results, 'value')));
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertDatabaseCount('telegram_stars_settlements', 1);
        $this->assertDatabaseCount('external_money_receipts', 2);
        $this->assertSame(1, DB::table('inventory_movements')->where('type', 'sale')->count());
        $this->assertDatabaseHas('telegram_stars_payment_reviews', ['reason' => 'additional_charge']);
        $this->assertSame(0, TelegramUpdate::whereIn('id', $updates)->whereNull('processed_at')->count());
        $this->assertDatabaseCount('telegram_outbound_effects', 2); // Invoice ACK + one paid admin obligation.
        Http::assertNothingSent();
    }

    public function test_one_charge_racing_across_two_targets_can_only_buy_one_order(): void
    {
        $first = $this->approvedOrder();
        $second = $this->approvedOrder(102);
        $ids = [
            $this->store($this->starsSuccess($first->payments->sole()->telegramStarsDetail, 201)),
            $this->store($this->starsSuccess($second->payments->sole()->telegramStarsDetail, 202)),
        ];
        $results = $this->runConcurrentWorkers(2, function (int $i) use ($ids): string {
            app(TelegramStarsSettlement::class)->process($ids[$i]);

            return 'processed';
        }, 'stars-cross-target');
        $this->assertWorkersSucceeded($results);
        $this->assertDatabaseCount('telegram_stars_settlements', 1);
        $this->assertDatabaseCount('external_money_receipts', 1);
        $this->assertDatabaseHas('telegram_stars_payment_reviews', ['reason' => 'receipt_conflict']);
        $this->assertEqualsCanonicalizing([PaymentStatus::Paid, PaymentStatus::Pending], [$first->fresh()->payment_status, $second->fresh()->payment_status]);
        $this->assertSame(1, DB::table('inventory_movements')->where('type', 'sale')->count());
        $this->assertDatabaseCount('telegram_outbound_effects', 3);
    }

    public function test_expiry_racing_with_settlement_either_fulfills_or_records_late_money_never_both(): void
    {
        $order = $this->approvedOrder();
        $this->travelTo($order->expires_at->copy()->addSecond());
        $id = $this->store($this->starsSuccess($order->payments->sole()->telegramStarsDetail, 201));
        $results = $this->runConcurrentWorkers(2, function (int $i) use ($order, $id): string {
            if ($i === 0) {
                app(TelegramStarsSettlement::class)->process($id);

                return 'settled';
            }

            return app(OrderLifecycle::class)->applyPendingExpiry($order)->newlyApplied ? 'expired' : 'held';
        }, 'stars-settlement-expiry');
        $this->assertWorkersSucceeded($results);
        $late = $results[1]['value'] === 'expired';
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame($late ? OrderStatus::Expired : OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertDatabaseHas('telegram_stars_settlements', ['late' => $late]);
        $this->assertSame($late ? 0 : 1, DB::table('inventory_movements')->where('type', 'sale')->count());
        $this->assertSame($late ? 1 : 0, DB::table('inventory_movements')->where('type', 'release')->count());
        $this->assertDatabaseCount('manual_fulfillment_reviews', $late ? 1 : 0);
    }

    public function test_database_failure_at_outbound_insert_rolls_back_receipt_sale_paid_and_inbox(): void
    {
        $order = $this->approvedOrder();
        $id = $this->store($this->starsSuccess($order->payments->sole()->telegramStarsDetail, 201));
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION fail_stars_outbound() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'simulated outbound insert failure';
            END;
            $$;
            CREATE TRIGGER fail_stars_outbound BEFORE INSERT ON telegram_outbound_effects
            FOR EACH ROW EXECUTE FUNCTION fail_stars_outbound();
            SQL);
        try {
            app(TelegramStarsSettlement::class)->process($id);
            $this->fail('Expected the outbound write to fail.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('inbox remains recoverable', $error->getMessage());
            $this->assertNull($error->getPrevious());
        } finally {
            DB::unprepared('DROP TRIGGER fail_stars_outbound ON telegram_outbound_effects; DROP FUNCTION fail_stars_outbound();');
        }
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
        $this->assertSame(PaymentStatus::Pending, $order->payments()->sole()->status);
        $this->assertNull(TelegramUpdate::findOrFail($id)->processed_at);
        $this->assertDatabaseEmpty('external_money_receipts');
        $this->assertDatabaseEmpty('external_money_receipt_aliases');
        $this->assertDatabaseEmpty('telegram_stars_settlements');
        $this->assertDatabaseMissing('inventory_movements', ['order_id' => $order->id, 'type' => 'sale']);
        $this->assertDatabaseCount('telegram_outbound_effects', 1);
        Queue::assertNotPushed(SendTelegramReceipt::class);
        $this->artisan('telegram:recover-stars-payments')->assertSuccessful();
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertDatabaseCount('external_money_receipts', 1);
        $this->assertDatabaseCount('telegram_stars_settlements', 1);
        Queue::assertPushed(SendTelegramReceipt::class, 1);
        Http::assertNothingSent();
    }

    public function test_history_distinguishes_the_incoming_credit_and_same_id_debit_and_never_settles_a_missed_webhook(): void
    {
        $settled = $this->approvedOrder();
        $target = $settled->payments->sole()->telegramStarsDetail;
        app(TelegramStarsSettlement::class)->process($this->store($this->starsSuccess($target, 201)));
        $missed = $this->approvedOrder(102);
        $missingTarget = $missed->payments->sole()->telegramStarsDetail;
        $partner = static fn ($detail): array => [
            'type' => 'user', 'transaction_type' => 'invoice_payment', 'invoice_payload' => $detail->invoice_payload,
            'user' => ['id' => $detail->telegram_user_id],
        ];
        Http::fake(['*/getStarTransactions' => function ($request) use ($target, $missingTarget, $partner) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame(['offset' => 100, 'limit' => 100], $request->data());

            return Http::response(['ok' => true, 'result' => ['transactions' => [
                ['id' => 'fixture-charge', 'amount' => $target->total_xtr, 'source' => $partner($target)],
                ['id' => 'fixture-charge', 'amount' => $target->total_xtr, 'receiver' => $partner($target)],
                ['id' => 'missed-charge', 'amount' => $missingTarget->total_xtr, 'source' => $partner($missingTarget)],
                ['id' => 'wrong-amount', 'amount' => $missingTarget->total_xtr + 1, 'source' => $partner($missingTarget)],
                ['id' => 'ambiguous', 'amount' => $target->total_xtr, 'source' => $partner($target), 'receiver' => $partner($target)],
                ['id' => 'ads', 'amount' => 5, 'receiver' => ['type' => 'telegram_ads']],
                ['id' => 'incomplete', 'amount' => 5, 'source' => ['type' => 'user', 'transaction_type' => 'invoice_payment']],
            ]]]);
        }]);
        DB::table('stars_pricing_policies')->update(['issuance_enabled' => false]);
        $this->artisan('telegram:report-stars-history', ['--offset' => 100, '--pages' => 5])
            ->expectsOutput('{"scanned":7,"next_offset":null,"findings":[{"offset":101,"reason":"outgoing_invoice_review"},{"offset":102,"reason":"missing_settlement"},{"offset":103,"reason":"invoice_mismatch"},{"offset":104,"reason":"ambiguous_direction"},{"offset":106,"reason":"incomplete_evidence"}]}')
            ->assertFailed();
        Http::assertSentCount(1);
        $this->assertSame(PaymentStatus::Pending, $missed->fresh()->payment_status);
        $this->assertDatabaseCount('telegram_stars_settlements', 1);
        $this->assertDatabaseCount('external_money_receipts', 1);
        $this->assertDatabaseMissing('inventory_movements', ['order_id' => $missed->id, 'type' => 'sale']);
    }

    private function approvedOrder(int $queryId = 101): Order
    {
        $order = $this->starsOrder('settlement-fixture-'.$queryId);
        $target = $order->payments->sole()->telegramStarsDetail;
        $this->acknowledgeStarsInvoiceFixture($target);
        $this->starsWebhook($this->starsQuery($target, $queryId))->assertJsonPath('ok', true);

        return $order;
    }

    private function store(array $payload): int
    {
        $this->starsWebhook($payload)->assertOk();

        return TelegramUpdate::where('update_id', $payload['update_id'])->value('id');
    }
}
