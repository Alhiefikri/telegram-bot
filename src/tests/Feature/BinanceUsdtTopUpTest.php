<?php

namespace Tests\Feature;

use App\Enums\CustomerUsdtFundingRail;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\CustomerUsdtFundingIntent;
use App\Models\CustomerUsdtFundingPolicy;
use App\Models\CustomerUsdtFundingReservation;
use App\Models\CustomerUsdtTopUp;
use App\Models\ManualPaymentSetting;
use App\Models\TelegramNavigationRoot;
use App\Models\UsdtTopUpReviewRequest;
use App\Models\User;
use App\Services\Telegram\TelegramUsdtFundingActions;
use App\Services\UsdtTopUps\BinanceUsdtTopUpSettlement;
use App\Services\UsdtTopUps\UsdtTopUpReview;
use App\Services\UsdtTopUps\UsdtTopUpStatusReader;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class BinanceUsdtTopUpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--force' => true]);
        Http::preventStrayRequests();
        Queue::fake();
        config(['services.binance.api_key' => 'test-key', 'services.binance.api_secret' => 'test-secret',
            'services.binance.pay_id' => '12345678', 'services.binance.pay_uid' => '', 'services.binance.history_cache_seconds' => 0]);
        ManualPaymentSetting::create(['is_active' => true, 'payment_mode' => 'manual', 'binance_pay_enabled' => true]);
        CustomerUsdtFundingPolicy::current()->update(['funding_enabled' => true, 'version' => 1]);
    }

    public function test_verified_receipt_credits_once_and_cannot_be_reused_by_another_customer(): void
    {
        $target = $this->target(771001);
        Http::fake(['*sapi/v1/pay/transactions*' => Http::response(['code' => '000000', 'success' => true, 'data' => [[
            'orderId' => '445011487581044736', 'transactionId' => 'TX-445011487581044736',
            'amount' => '1.5', 'currency' => 'USDT', 'transactionTime' => now()->getTimestampMs(),
            'orderType' => 'C2C', 'receiverInfo' => ['accountId' => '12345678'],
        ]]])]);
        $service = app(BinanceUsdtTopUpSettlement::class);
        $customer = Customer::query()->findOrFail($target->customer_id);
        $service->submit($customer, $target->id, '445011487581044736');
        Artisan::call('usdt-funding:recover');
        $this->assertSame('settled', DB::table('customer_usdt_top_up_resolutions')->value('kind'));
        $this->assertSame(1500000, (int) DB::table('customer_usdt_balance_entries')->sum('amount_micro'));
        $service->submit($customer, $target->id, '445011487581044736');
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->count());
        $other = $this->target(771002);
        $service->submit(Customer::query()->findOrFail($other->customer_id), $other->id, '445011487581044736');
        $service->check($other->id);
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame('manual_review', DB::table('customer_usdt_top_up_settlement_work')->where('top_up_id', $other->id)->value('state'));
    }

    #[DataProvider('compatibleTransactionIdentifiers')]
    public function test_transaction_id_accepted_by_product_checkout_also_credits_top_up_once(string $orderId): void
    {
        $this->freezeTime();
        $target = $this->target(771030);
        $this->fakeHistory($this->transaction($target, ['orderId' => $orderId]));
        $service = app(BinanceUsdtTopUpSettlement::class);
        $customer = Customer::findOrFail($target->customer_id);
        $service->submit($customer, $target->id, 'TX-445011487581044736');
        $service->check($target->id);
        $this->assertSame('settled', DB::table('customer_usdt_top_up_settlement_work')->sole()->state);
        $this->assertSame(1_500_000, (int) DB::table('customer_usdt_balance_accounts')->sole()->balance_micro);
        $this->assertSame('settled', DB::table('customer_usdt_top_up_resolutions')->sole()->kind);
        $service->submit($customer, $target->id, $orderId !== '' ? $orderId : 'TX-445011487581044736');
        $this->travel(61)->seconds();
        $service->recover();
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame(1, DB::table('external_money_receipts')->count());
        $this->assertSame(1_500_000, (int) DB::table('customer_usdt_balance_accounts')->sole()->balance_micro);
    }

    public static function compatibleTransactionIdentifiers(): array
    {
        return ['distinct Order ID' => ['445011487581044736'], 'transaction ID only' => ['']];
    }

    #[DataProvider('compatibleTransactionIdentifiers')]
    public function test_owner_can_resolve_an_amount_mismatch_submitted_by_transaction_id(string $orderId): void
    {
        $this->freezeTime();
        $target = $this->target(771038);
        $this->fakeHistory($this->transaction($target, ['orderId' => $orderId, 'amount' => '2']));
        $settlement = app(BinanceUsdtTopUpSettlement::class);
        $settlement->submit(Customer::findOrFail($target->customer_id), $target->id, 'TX-445011487581044736');
        $settlement->check($target->id);
        $this->assertSame('manual_review', DB::table('customer_usdt_top_up_settlement_work')->sole()->state);
        $owner = $this->reviewOwner();
        $review = app(UsdtTopUpReview::class);
        $preview = $review->request($owner, $target->id, 'settled', '2', 'TX-445011487581044736', 'Verified transfer amount mismatch');
        $review->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password');
        $this->assertSame(2_000_000, (int) DB::table('customer_usdt_balance_accounts')->sole()->balance_micro);
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame(1, DB::table('external_money_receipts')->count());
    }

    public function test_second_transfer_after_settlement_is_claimed_for_review_without_another_credit(): void
    {
        $this->freezeTime();
        $target = $this->target(771018);
        $customer = Customer::findOrFail($target->customer_id);
        $service = app(BinanceUsdtTopUpSettlement::class);
        $first = $this->transaction($target);
        $second = $this->transaction($target, ['orderId' => 'ORDER-P2', 'transactionId' => 'TX-P2']);
        Http::fake(['*sapi/v1/pay/transactions*' => Http::response([
            'code' => '000000', 'success' => true, 'data' => [$first, $second],
        ])]);
        $service->submit($customer, $target->id, $first['orderId']);
        $this->assertTrue($service->check($target->id));
        $resolution = DB::table('customer_usdt_top_up_resolutions')->sole();
        $entry = DB::table('customer_usdt_balance_entries')->sole();
        $service->submit($customer, $target->id, $second['orderId']);
        $this->travel(61)->seconds();
        $this->assertTrue($service->check($target->id));
        $this->assertSame(2, DB::table('external_money_receipts')->count());
        $this->assertSame(2, DB::table('customer_usdt_top_up_evidence')->count());
        $this->assertSame('review', DB::table('customer_usdt_top_up_inbox')->where('provider_reference', 'ORDER-P2')->value('state'));
        $work = DB::table('customer_usdt_top_up_settlement_work')->sole();
        $this->assertSame('manual_review', $work->state);
        $this->assertSame('receipt_after_resolution', $work->last_error);
        $this->assertEquals($resolution, DB::table('customer_usdt_top_up_resolutions')->sole());
        $this->assertEquals($entry, DB::table('customer_usdt_balance_entries')->sole());
        $this->assertSame(1500000, (int) DB::table('customer_usdt_balance_accounts')->sole()->balance_micro);
        $this->assertSame(1, DB::table('telegram_outbound_effects')->count());
        $settledHint = DB::table('customer_usdt_top_up_inbox')->where('state', 'settled')->sole();
        $this->assertSame($settledHint->id, DB::table('customer_usdt_top_up_evidence')->where('id', $resolution->evidence_id)->value('inbox_id'));
        $this->assertSame((int) $resolution->entry_id, (int) $entry->id);
        $this->assertSame($target->id, $entry->source_id);
        foreach ([$first, $second] as $transfer) {
            $service->submit($customer, $target->id, $transfer['orderId']);
        }
        $this->travel(61)->seconds();
        $this->assertFalse($service->check($target->id));
        $service->recover();
        $this->assertSame(2, DB::table('external_money_receipts')->count());
        $this->assertSame(2, DB::table('customer_usdt_top_up_evidence')->count());
        $this->assertEquals($entry, DB::table('customer_usdt_balance_entries')->sole());
        Http::assertSentCount(2);
    }

    #[DataProvider('postSettlementInvalidTransfers')]
    public function test_post_settlement_invalid_transfer_does_not_claim_new_money(array $changes, string $error, string $inboxState): void
    {
        $this->freezeTime();
        $target = $this->target(771019);
        $customer = Customer::findOrFail($target->customer_id);
        $service = app(BinanceUsdtTopUpSettlement::class);
        Http::fake(['*sapi/v1/pay/transactions*' => Http::response([
            'code' => '000000', 'success' => true, 'data' => [
                $this->transaction($target),
                $this->transaction($target, ['orderId' => 'ORDER-P2', ...$changes]),
            ],
        ])]);
        $service->submit($customer, $target->id, '445011487581044736');
        $this->assertTrue($service->check($target->id));
        $resolution = DB::table('customer_usdt_top_up_resolutions')->sole();
        $service->submit($customer, $target->id, 'ORDER-P2');
        $this->travel(61)->seconds();
        $this->assertTrue($service->check($target->id));
        $this->assertSame(1, DB::table('external_money_receipts')->count());
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame(2, DB::table('customer_usdt_top_up_evidence')->count());
        $this->assertEquals($resolution, DB::table('customer_usdt_top_up_resolutions')->sole());
        $this->assertSame($inboxState, DB::table('customer_usdt_top_up_inbox')->where('provider_reference', 'ORDER-P2')->value('state'));
        $work = DB::table('customer_usdt_top_up_settlement_work')->sole();
        $this->assertSame('manual_review', $work->state);
        $this->assertSame($error, $work->last_error);
    }

    public static function postSettlementInvalidTransfers(): array
    {
        return [
            'amount mismatch' => [['transactionId' => 'TX-P2', 'amount' => '2'], 'binance_snapshot_or_amount_mismatch', 'review'],
            'receiver mismatch' => [['transactionId' => 'TX-P2', 'receiverInfo' => ['accountId' => '99999999']], 'binance_snapshot_or_amount_mismatch', 'review'],
            'claimed order alias' => [['transactionId' => '445011487581044736'], 'receipt_conflict', 'pending'],
        ];
    }

    public function test_wrong_order_hint_does_not_starve_a_later_correct_hint(): void
    {
        $this->freezeTime();
        $target = $this->target(771014);
        $customer = Customer::findOrFail($target->customer_id);
        $this->fakeHistory($this->transaction($target));
        $service = app(BinanceUsdtTopUpSettlement::class);
        $service->submit($customer, $target->id, 'not-a-real-order-id');
        $this->assertTrue($service->check($target->id));
        $service->submit($customer, $target->id, '445011487581044736');
        $this->travel(61)->seconds();
        $this->assertTrue($service->check($target->id));
        $this->assertSame('settled', DB::table('customer_usdt_top_up_resolutions')->sole()->kind);
        $this->assertSame(1500000, (int) DB::table('customer_usdt_balance_entries')->sole()->amount_micro);
        Http::assertSentCount(2);
        $this->travel(10)->minutes();
        $service->submit($customer, $target->id, 'another-wrong-id');
        $this->assertTrue($service->check($target->id));
        $this->assertSame(1, DB::table('customer_usdt_top_up_resolutions')->count());
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame('pending', DB::table('customer_usdt_top_up_inbox')->where('provider_reference', 'another-wrong-id')->value('state'));
        $this->assertSame('binance_order_not_found', DB::table('customer_usdt_top_up_settlement_work')->sole()->last_error);
        Http::assertSentCount(3);
    }

    #[DataProvider('nonSettlingHints')]
    public function test_non_settling_hint_backoff_bounds_history_reads(bool $receiptConflict): void
    {
        $this->freezeTime();
        $service = app(BinanceUsdtTopUpSettlement::class);
        if ($receiptConflict) {
            $owner = $this->target(771015);
            $this->fakeHistory($this->transaction($owner));
            $service->submit(Customer::findOrFail($owner->customer_id), $owner->id, '445011487581044736');
            $service->check($owner->id);
        } else {
            Http::fake(['*sapi/v1/pay/transactions*' => Http::response(['code' => '000000', 'success' => true, 'data' => []])]);
        }
        $target = $this->target(771016);
        $service->submit(Customer::findOrFail($target->customer_id), $target->id, '445011487581044736');
        $requests = $receiptConflict ? 1 : 0;
        foreach ([10, 20, 40, 80, 160, 300, 300] as $index => $delay) {
            $checkedAt = now()->toImmutable();
            $this->assertTrue($service->check($target->id));
            $requests++;
            $hint = DB::table('customer_usdt_top_up_inbox')->where('top_up_id', $target->id)->sole();
            $this->assertSame($index + 1, (int) $hint->attempts);
            $this->assertSame($checkedAt->addSeconds($delay)->toDateTimeString(), $hint->next_check_at);
            $this->travel(max(60, $delay) - 1)->seconds();
            $this->assertFalse($service->check($target->id));
            Http::assertSentCount($requests);
            $this->travel(61)->seconds();
        }
        $this->assertSame($receiptConflict ? 1 : 0, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame(0, DB::table('customer_usdt_top_up_resolutions')->where('top_up_id', $target->id)->count());
    }

    public static function nonSettlingHints(): array
    {
        return ['missing order' => [false], 'receipt conflict' => [true]];
    }

    #[DataProvider('historyAvailability')]
    public function test_target_inspection_budget_survives_expiry_and_new_hints(bool $available): void
    {
        $this->freezeTime();
        config(['services.binance.history_max_attempts' => 1]);
        $target = $this->target(771017);
        $customer = Customer::findOrFail($target->customer_id);
        Http::fake(['*sapi/v1/pay/transactions*' => Http::response(
            $available ? ['code' => '000000', 'success' => true, 'data' => []] : ['code' => 'ERROR', 'success' => false],
            $available ? 200 : 503,
        )]);
        $service = app(BinanceUsdtTopUpSettlement::class);
        $service->submit($customer, $target->id, 'never-matches');
        for ($i = 0; $i < 100; $i++) {
            $service->recover();
            $this->travel(10)->minutes();
        }
        $service->submit($customer, $target->id, 'new-hint-after-exhaustion');
        $service->recover();
        $this->travel(1)->day();
        $this->assertFalse($service->check($target->id));
        Http::assertSentCount(100);
        $work = DB::table('customer_usdt_top_up_settlement_work')->sole();
        $this->assertSame(100, (int) $work->attempts);
        $this->assertSame('expired_unresolved', $work->state);
        $this->assertSame('expired_unresolved', app(UsdtTopUpStatusReader::class)->for($target)->state);
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame(0, DB::table('customer_usdt_top_up_resolutions')->count());
        $this->assertNull(DB::table('customer_usdt_funding_reservations')->sole()->resolved_at);
    }

    public static function historyAvailability(): array
    {
        return ['not found' => [true], 'provider unavailable' => [false]];
    }

    public function test_mismatched_amount_does_not_credit(): void
    {
        $target = $this->target(771003);
        Http::fake(['*sapi/v1/pay/transactions*' => Http::response(['code' => '000000', 'success' => true, 'data' => [[
            'orderId' => '445011487581044736', 'transactionId' => 'TX-445011487581044736',
            'amount' => '2', 'currency' => 'USDT', 'transactionTime' => now()->getTimestampMs(),
            'receiverInfo' => ['accountId' => '12345678'],
        ]]])]);
        $service = app(BinanceUsdtTopUpSettlement::class);
        $service->submit(Customer::query()->findOrFail($target->customer_id), $target->id, '445011487581044736');
        $service->check($target->id);
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame('manual_review', DB::table('customer_usdt_top_up_settlement_work')->value('state'));
    }

    public function test_decimal_top_up_credits_exactly_the_zero_fee_amount_despite_configured_fee(): void
    {
        CustomerUsdtFundingPolicy::current()->update(['application_fee_micro' => 200_000]);
        $target = $this->target(771004, '0.30');
        $this->fakeHistory($this->transaction($target, ['amount' => '0.300000']));
        $service = app(BinanceUsdtTopUpSettlement::class);
        $service->submit(Customer::findOrFail($target->customer_id), $target->id, '445011487581044736');
        $this->assertTrue($service->check($target->id));
        $this->assertSame(300_000, (int) DB::table('customer_usdt_balance_entries')->sole()->amount_micro);
        $this->assertSame(300_000, (int) DB::table('customer_usdt_balance_accounts')->sole()->balance_micro);
        $this->assertSame(0, $target->fee_micro);
        $this->assertSame(300_000, $target->payable_micro);
        $this->assertSame(0, DB::table('customer_usdt_top_up_fee_events')->count());
        $this->assertSame(1, DB::table('telegram_outbound_effects')->count());
    }

    #[DataProvider('invalidIncomingTransfers')]
    public function test_authoritative_mismatch_never_credits(array $changes): void
    {
        $target = $this->target(771005);
        $this->fakeHistory($this->transaction($target, $changes));
        $service = app(BinanceUsdtTopUpSettlement::class);
        $service->submit(Customer::findOrFail($target->customer_id), $target->id, '445011487581044736');
        $service->check($target->id);
        $this->assertSame('manual_review', DB::table('customer_usdt_top_up_settlement_work')->value('state'));
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame(0, DB::table('external_money_receipts')->count());
        $this->assertSame(0, DB::table('customer_usdt_top_up_resolutions')->count());
        $this->assertSame(0, DB::table('telegram_outbound_effects')->count());
    }

    public static function invalidIncomingTransfers(): array
    {
        return [
            'underpaid by one micro' => [['amount' => '1.499999']],
            'overpaid by one micro' => [['amount' => '1.500001']],
            'zero transfer' => [['amount' => '0']],
            'missing receiver' => [['receiverInfo' => []]],
            'Pay ID cannot match a conflicting account via binanceId' => [['receiverInfo' => ['accountId' => '87654321', 'binanceId' => '12345678']]],
            'pending' => [['status' => 'PENDING']],
            'processing' => [['status' => 'PROCESSING']],
            'cancelled' => [['status' => 'CANCELLED']],
            'refunded' => [['status' => 'REFUNDED']],
            'unknown status' => [['status' => 'UNKNOWN']],
            'receiver' => [['receiverInfo' => ['accountId' => '99999999']]],
            'asset' => [['currency' => 'USDC']],
            'failed' => [['status' => 'FAILED']],
            'outgoing' => [['amount' => '-1.5']],
            'before target' => [['transactionTime' => 1]],
            'future' => [['transactionTime' => PHP_INT_MAX]],
        ];
    }

    public function test_missing_order_and_unavailable_history_remain_unresolved(): void
    {
        $target = $this->target(771006);
        config(['services.binance.history_max_attempts' => 1]);
        Http::fake(['*sapi/v1/pay/transactions*' => Http::sequence()
            ->push(['code' => '000000', 'success' => true, 'data' => [$this->transaction($target, ['orderId' => 'different-order'])]])
            ->push(['code' => 'ERROR', 'success' => false], 503)]);
        $service = app(BinanceUsdtTopUpSettlement::class);
        $service->submit(Customer::findOrFail($target->customer_id), $target->id, '445011487581044736');
        $service->check($target->id);
        $this->assertSame('binance_order_not_found', DB::table('customer_usdt_top_up_settlement_work')->value('last_error'));
        $this->travel(61)->seconds();
        $service->recover();
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame(0, DB::table('customer_usdt_top_up_resolutions')->count());
        $this->assertSame(0, DB::table('external_money_receipts')->count());
        $this->assertSame(0, DB::table('telegram_outbound_effects')->count());
        $this->assertSame('pending', DB::table('customer_usdt_top_up_inbox')->value('state'));
    }

    public function test_incomplete_history_cannot_credit_even_when_a_matching_transfer_is_present(): void
    {
        $target = $this->target(771012, '0.30');
        config(['services.binance.history_max_pages' => 1]);
        $transactions = array_fill(0, 100, $this->transaction($target, ['amount' => '0.30']));
        Http::fake(['*sapi/v1/pay/transactions*' => Http::response(['code' => '000000', 'success' => true, 'data' => $transactions])]);
        $service = app(BinanceUsdtTopUpSettlement::class);
        $service->submit(Customer::findOrFail($target->customer_id), $target->id, '445011487581044736');
        $service->recover();
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame(0, DB::table('customer_usdt_top_up_resolutions')->count());
        $this->assertSame(0, DB::table('external_money_receipts')->count());
        $this->assertSame('pending', DB::table('customer_usdt_top_up_inbox')->value('state'));
        Http::assertSentCount(1);
    }

    public function test_after_deadline_transfer_is_reviewed_without_credit(): void
    {
        $target = $this->target(771013);
        $this->travelTo($target->expires_at->addMinute());
        $this->fakeHistory($this->transaction($target, ['transactionTime' => $target->expires_at->addSecond()->getTimestampMs()]));
        $service = app(BinanceUsdtTopUpSettlement::class);
        $service->submit(Customer::findOrFail($target->customer_id), $target->id, '445011487581044736');
        $service->check($target->id);
        $this->assertSame('manual_review', DB::table('customer_usdt_top_up_settlement_work')->value('state'));
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame(0, DB::table('external_money_receipts')->count());
    }

    #[DataProvider('failedStatusFields')]
    public function test_cached_failed_transfer_evidence_cannot_become_a_success(string $field): void
    {
        $this->freezeTime();
        config(['services.binance.history_cache_seconds' => 20]);
        $first = $this->target(771010);
        $second = $this->target(771011);
        $payload = $this->transaction($first);
        unset($payload['status']);
        $payload[$field] = 'FAILED';
        $this->fakeHistory($payload);
        $service = app(BinanceUsdtTopUpSettlement::class);
        foreach ([$first, $second] as $target) {
            $service->submit(Customer::findOrFail($target->customer_id), $target->id, '445011487581044736');
            $this->assertTrue($service->check($target->id));
            $this->assertSame('manual_review', DB::table('customer_usdt_top_up_settlement_work')->where('top_up_id', $target->id)->value('state'));
        }
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame(0, DB::table('external_money_receipts')->count());
        Http::assertSentCount(1);
    }

    public static function failedStatusFields(): array
    {
        return [['status'], ['transactionStatus']];
    }

    public function test_distinct_order_hints_and_checks_cannot_revoke_an_active_inspection_lease(): void
    {
        $target = $this->target(771007);
        $customer = Customer::findOrFail($target->customer_id);
        $service = app(BinanceUsdtTopUpSettlement::class);
        $service->submit($customer, $target->id, '445011487581044736');
        Http::fake(['*sapi/v1/pay/transactions*' => function () use ($target, $customer, $service) {
            $this->assertSame(0, DB::transactionLevel());
            $lease = DB::table('customer_usdt_top_up_settlement_work')->sole();
            for ($i = 0; $i < 10; $i++) {
                $service->submit($customer, $target->id, 'new-order-'.$i);
                $this->assertFalse($service->check($target->id));
            }
            $current = DB::table('customer_usdt_top_up_settlement_work')->sole();
            $this->assertSame($lease->lease_token, $current->lease_token);
            $this->assertSame($lease->lease_until, $current->lease_until);
            $this->assertSame($lease->version, $current->version);

            return Http::response(['code' => '000000', 'success' => true, 'data' => [$this->transaction($target)]]);
        }]);
        $this->assertTrue($service->check($target->id));
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame('settled', DB::table('customer_usdt_top_up_resolutions')->value('kind'));
        Http::assertSentCount(1);
    }

    public function test_distinct_hints_cannot_reset_retry_cooldown_or_bypass_persistent_hint_budget(): void
    {
        $target = $this->target(771008);
        $customer = Customer::findOrFail($target->customer_id);
        $service = app(BinanceUsdtTopUpSettlement::class);
        $service->submit($customer, $target->id, '445011487581044736');
        Http::fake(['*sapi/v1/pay/transactions*' => Http::response(['code' => '000000', 'success' => true, 'data' => []])]);
        $this->assertTrue($service->check($target->id));
        $cooldown = DB::table('customer_usdt_top_up_settlement_work')->sole();
        for ($i = 1; $i < 100; $i++) {
            $service->submit($customer, $target->id, 'new-order-'.$i);
            $this->assertFalse($service->check($target->id));
        }
        $service->submit($customer, $target->id, '445011487581044736');
        $this->assertSame($cooldown->next_check_at, DB::table('customer_usdt_top_up_settlement_work')->value('next_check_at'));
        $this->assertSame($cooldown->version, DB::table('customer_usdt_top_up_settlement_work')->value('version'));
        Http::assertSentCount(1);
        try {
            $service->submit($customer, $target->id, 'over-budget');
            $this->fail('New Order IDs must not bypass the persistent target budget.');
        } catch (InvalidArgumentException) {
            $this->assertSame(100, DB::table('customer_usdt_top_up_inbox')->count());
        }
        $this->travel(61)->seconds();
        $this->assertTrue($service->check($target->id));
        Http::assertSentCount(2);
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
    }

    public function test_confirmed_historical_fee_and_receiver_snapshots_still_settle_unchanged(): void
    {
        $target = $this->historicalTarget();
        config(['services.binance.pay_id' => '87654321']);
        CustomerUsdtFundingPolicy::current()->update(['application_fee_micro' => 900_000, 'version' => 2]);
        $this->fakeHistory($this->transaction($target, ['amount' => '0.4']));
        $service = app(BinanceUsdtTopUpSettlement::class);
        $service->submit(Customer::findOrFail($target->customer_id), $target->id, '445011487581044736');
        $this->assertTrue($service->check($target->id));
        $this->assertSame(300_000, (int) DB::table('customer_usdt_balance_entries')->value('amount_micro'));
        $this->assertSame(100_000, (int) DB::table('customer_usdt_top_up_fee_events')->value('amount_micro'));
        $this->assertSame(100_000, $target->fresh()->fee_micro);
        $this->assertSame(400_000, $target->fresh()->payable_micro);
        $this->assertSame('12345678', $target->fresh()->payment_snapshot['receiver_pay_id']);
        $this->assertSame(1, DB::table('customer_usdt_top_up_resolutions')->count());
    }

    public function test_owner_can_resolve_binance_amount_mismatch_once(): void
    {
        $target = $this->target(771020);
        $this->fakeHistory($this->transaction($target, ['amount' => '2']));
        $settlement = app(BinanceUsdtTopUpSettlement::class);
        $settlement->submit(Customer::findOrFail($target->customer_id), $target->id, '445011487581044736');
        $settlement->check($target->id);
        $this->assertSame('manual_review', DB::table('customer_usdt_top_up_settlement_work')->value('state'));
        $owner = $this->reviewOwner();
        $review = app(UsdtTopUpReview::class);
        $preview = $review->request($owner, $target->id, 'settled', '2',
            '445011487581044736', 'Resolve verified Binance amount mismatch');
        $resolution = $review->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password');
        $replay = $review->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password');
        $this->assertSame($resolution->entry_id, $replay->entry_id);
        $this->assertSame(2_000_000, (int) DB::table('customer_usdt_balance_entries')->sole()->amount_micro);
        $this->assertSame(1, DB::table('external_money_receipts')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'usdt_top_up.review_resolved')->count());
        $this->assertSame(1, DB::table('telegram_outbound_effects')->where('effect_key', 'balance-adjustment:usdt-review:'.$preview->id.':owner:v1')->count());
        $this->assertSame('settled', DB::table('customer_usdt_top_up_settlement_work')->value('state'));
    }

    public function test_owner_can_credit_expired_binance_deposit_using_original_fee_and_receiver(): void
    {
        $target = $this->historicalTarget();
        $original = $target->fresh()->getAttributes();
        $this->travel(16)->minutes();
        DB::table('customer_usdt_top_up_settlement_work')->where('top_up_id', $target->id)->update(['state' => 'expired_unresolved', 'version' => DB::raw('version + 1')]);
        config(['services.binance.pay_id' => '87654321']);
        CustomerUsdtFundingPolicy::current()->update(['application_fee_micro' => 900_000, 'version' => 2]);
        $this->fakeHistory($this->transaction($target, ['amount' => '0.6', 'transactionTime' => now()->getTimestampMs()]));
        $owner = $this->reviewOwner();
        $review = app(UsdtTopUpReview::class);
        $preview = $review->request($owner, $target->id, 'settled', '0.6', '445011487581044736', 'Verified late deposit');
        $resolution = $review->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password');
        $this->assertTrue((bool) $resolution->late);
        $this->assertSame(500_000, (int) DB::table('customer_usdt_balance_entries')->sole()->amount_micro);
        $this->assertSame(100_000, (int) DB::table('customer_usdt_top_up_fee_events')->sole()->amount_micro);
        $this->assertSame('settled', CustomerUsdtFundingReservation::findOrFail($target->reservation_id)->state->value);
        $this->assertSame($original, $target->fresh()->getAttributes());
    }

    #[DataProvider('reviewMismatchCases')]
    public function test_owner_can_reject_but_cannot_credit_invalid_binance_transfer(array $changes): void
    {
        $target = $this->target(771021);
        $changes = array_replace($changes, ['amount' => '2']);
        if (isset($changes['transactionTime'])) {
            $changes['transactionTime'] = $target->created_at->subSecond()->getTimestampMs();
        }
        $this->fakeHistory($this->transaction($target, $changes));
        $settlement = app(BinanceUsdtTopUpSettlement::class);
        $settlement->submit(Customer::findOrFail($target->customer_id), $target->id, '445011487581044736');
        $settlement->check($target->id);
        $owner = $this->reviewOwner();
        $review = app(UsdtTopUpReview::class);
        try {
            $review->request($owner, $target->id, 'settled', '2', '445011487581044736', 'Must not credit mismatch');
            $this->fail('Invalid provider transfer authorized credit.');
        } catch (DomainException) {
            $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
        }
        $preview = $review->request($owner, $target->id, 'rejected', '2', '445011487581044736', 'Reject verified mismatch');
        $resolution = $review->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password');
        $review->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password');
        $this->assertSame('rejected', $resolution->kind);
        $this->assertNull($resolution->entry_id);
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame('released', CustomerUsdtFundingReservation::findOrFail($target->reservation_id)->state->value);
        $this->assertSame('rejected', DB::table('customer_usdt_top_up_settlement_work')->value('state'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'usdt_top_up.review_resolved')->count());
        $this->assertSame(1, DB::table('telegram_outbound_effects')->where('effect_key', 'balance-adjustment:usdt-review:'.$preview->id.':owner:v1')->count());
    }

    public static function reviewMismatchCases(): array
    {
        return [
            'asset' => [['currency' => 'BTC']],
            'receiver' => [['receiverInfo' => ['accountId' => '87654321']]],
            'window' => [['transactionTime' => 1]],
            'failed status' => [['status' => 'FAILED']],
            'unknown status' => [['status' => '']],
        ];
    }

    #[DataProvider('reviewDecisionCases')]
    public function test_owner_decision_requires_uncached_provider_verification(string $decision): void
    {
        config(['services.binance.history_cache_seconds' => 60]);
        $target = $this->target(771022);
        $payload = $this->transaction($target, ['amount' => '2']);
        Http::fake(['*sapi/v1/pay/transactions*' => Http::sequence()
            ->push(['code' => '000000', 'success' => true, 'data' => [$payload]])
            ->push(['code' => '000000', 'success' => true, 'data' => [$payload]])
            ->push(['code' => '000000', 'success' => true, 'data' => []])]);
        $settlement = app(BinanceUsdtTopUpSettlement::class);
        $settlement->submit(Customer::findOrFail($target->customer_id), $target->id, '445011487581044736');
        $settlement->check($target->id);
        $owner = $this->reviewOwner();
        $review = app(UsdtTopUpReview::class);
        $preview = $review->request($owner, $target->id, $decision, '2', '445011487581044736', 'Fresh verification required');
        try {
            $review->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password');
            $this->fail('Stored or cached evidence authorized a decision.');
        } catch (DomainException) {
            Http::assertSentCount(3);
            $this->assertSame(0, DB::table('customer_usdt_top_up_resolutions')->count());
            $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
            $this->assertSame(0, DB::table('external_money_receipts')->count());
        }
    }

    public static function reviewDecisionCases(): array
    {
        return [['settled'], ['rejected']];
    }

    public function test_owner_binance_review_uses_existing_admin_routes(): void
    {
        $target = $this->target(771023);
        DB::table('customer_usdt_top_up_settlement_work')->where('top_up_id', $target->id)->update(['state' => 'manual_review', 'version' => DB::raw('version + 1')]);
        $this->fakeHistory($this->transaction($target, ['amount' => '2']));
        $this->reviewOwner();
        $this->get(route('admin.store-balance.usdt-review.index', ['top_up' => $target->id]))->assertOk();
        $this->post(route('admin.store-balance.usdt-review.previews.store'), [
            'top_up_id' => $target->id, 'decision' => 'settled', 'amount' => '2',
            'provider_reference' => '445011487581044736', 'reason' => 'Provider verified credit',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $preview = UsdtTopUpReviewRequest::query()->sole();
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->get(route('admin.store-balance.usdt-review.index', ['preview' => $preview->id]))->assertOk();
        $this->post(route('admin.store-balance.usdt-review.previews.confirm', $preview->id), [
            'fingerprint' => $preview->fingerprint, 'version' => 1, 'password' => 'wrong-password',
        ])->assertSessionHasErrors('review');
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
        $this->post(route('admin.store-balance.usdt-review.previews.confirm', $preview->id), [
            'fingerprint' => $preview->fingerprint, 'version' => 1, 'password' => 'owner-password',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(2_000_000, (int) DB::table('customer_usdt_balance_entries')->sole()->amount_micro);
    }

    public function test_review_cannot_reuse_receipt_or_order_alias_claimed_by_another_customer(): void
    {
        $this->freezeTime();
        $first = $this->target(771024);
        $second = $this->target(771025);
        $aliasPayload = $this->transaction($second, ['transactionId' => 'different-transaction']);
        Http::fake(['*sapi/v1/pay/transactions*' => Http::sequence()
            ->push(['code' => '000000', 'success' => true, 'data' => [$this->transaction($first)]])
            ->push(['code' => '000000', 'success' => true, 'data' => [$aliasPayload]])
            ->push(['code' => '000000', 'success' => true, 'data' => [$aliasPayload]])]);
        $settlement = app(BinanceUsdtTopUpSettlement::class);
        $settlement->submit(Customer::findOrFail($first->customer_id), $first->id, '445011487581044736');
        $settlement->check($first->id);
        DB::table('customer_usdt_top_up_settlement_work')->where('top_up_id', $second->id)
            ->update(['state' => 'manual_review', 'version' => DB::raw('version + 1')]);
        $owner = $this->reviewOwner();
        $review = app(UsdtTopUpReview::class);
        $preview = $review->request($owner, $second->id, 'settled', '1.5', '445011487581044736', 'Conflicting alias');
        try {
            $review->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password');
            $this->fail('A claimed Order ID funded another Customer.');
        } catch (DomainException) {
            $this->assertSame(1, DB::table('customer_usdt_balance_entries')->count());
            $this->assertSame(1, DB::table('external_money_receipts')->count());
            $this->assertSame(0, DB::table('customer_usdt_top_up_resolutions')->where('top_up_id', $second->id)->count());
            $this->assertSame(0, DB::table('audit_logs')->where('action', 'usdt_top_up.review_resolved')->count());
        }
    }

    public function test_provider_outage_cannot_finalize_stored_binance_review(): void
    {
        $target = $this->target(771026);
        DB::table('customer_usdt_top_up_settlement_work')->where('top_up_id', $target->id)
            ->update(['state' => 'expired_unresolved', 'version' => DB::raw('version + 1')]);
        Http::fake(['*sapi/v1/pay/transactions*' => Http::sequence()
            ->push(['code' => '000000', 'success' => true, 'data' => [$this->transaction($target)]])
            ->push(['code' => 'ERROR', 'success' => false], 503)]);
        $owner = $this->reviewOwner();
        $review = app(UsdtTopUpReview::class);
        $preview = $review->request($owner, $target->id, 'settled', '1.5', '445011487581044736', 'Provider outage');
        config(['services.binance.history_max_attempts' => 1]);
        try {
            $review->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password');
            $this->fail('Unavailable provider authorized credit.');
        } catch (DomainException) {
            $this->assertSame(0, DB::table('customer_usdt_top_up_resolutions')->count());
            $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
            $this->assertSame(0, DB::table('external_money_receipts')->count());
        }
    }

    #[DataProvider('acceptedReceiverShapes')]
    public function test_valid_receiver_namespaces_and_success_statuses_credit_exact_micro_usdt(array $receiver, bool $uidOnly, array $status): void
    {
        $this->freezeTime();
        if ($uidOnly) {
            config(['services.binance.pay_id' => '', 'services.binance.pay_uid' => '11223344']);
        }
        $target = $this->target(771031, '0.000001');
        $payload = $this->transaction($target, ['amount' => '0.000001000000000000', 'receiverInfo' => $receiver]);
        unset($payload['status']);
        $this->fakeHistory(array_replace($payload, $status));
        $service = app(BinanceUsdtTopUpSettlement::class);
        $service->submit(Customer::findOrFail($target->customer_id), $target->id, '445011487581044736');
        $service->check($target->id);
        $this->assertSame('settled', DB::table('customer_usdt_top_up_resolutions')->sole()->kind);
        $this->assertSame(1, (int) DB::table('customer_usdt_balance_accounts')->sole()->balance_micro);
        $this->assertSame(1, (int) DB::table('customer_usdt_balance_entries')->sole()->amount_micro);
        $this->assertSame(0, DB::table('store_balance_entries')->count());
    }

    public static function acceptedReceiverShapes(): array
    {
        return [
            'Pay ID via accountId with COMPLETED' => [['accountId' => '12345678'], false, ['status' => 'COMPLETED']],
            'C2C Pay ID via binanceId with PAID' => [['binanceId' => '12345678'], false, ['status' => 'PAID']],
            'UID namespace with transactionStatus' => [['binanceId' => '11223344'], true, ['transactionStatus' => 'SUCCESS']],
            'legacy history without status' => [['accountId' => '12345678'], false, []],
        ];
    }

    #[DataProvider('malformedProviderTransfers')]
    public function test_malformed_provider_transfer_stays_unresolved_without_claiming_money(array $changes): void
    {
        $target = $this->target(771032);
        $this->fakeHistory($this->transaction($target, $changes));
        $service = app(BinanceUsdtTopUpSettlement::class);
        $service->submit(Customer::findOrFail($target->customer_id), $target->id, '445011487581044736');
        $service->recover();
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame(0, DB::table('external_money_receipts')->count());
        $this->assertSame(0, DB::table('customer_usdt_top_up_resolutions')->count());
        $this->assertSame('pending', DB::table('customer_usdt_top_up_inbox')->sole()->state);
    }

    public static function malformedProviderTransfers(): array
    {
        return [
            'nested transfer status' => [['status' => ['value' => 'FAILED']]],
            'boolean transfer status' => [['status' => false]],
            'nested alternate status' => [['status' => null, 'transactionStatus' => ['value' => 'FAILED']]],
            'sub-micro precision' => [['amount' => '1.5000001']],
            'overflow' => [['amount' => '9223372036854.775808']],
            'exponential notation' => [['amount' => '1.5e0']],
            'missing transaction identity' => [['transactionId' => '']],
            'nested amount' => [['amount' => ['value' => '1.5']]],
            'malformed timestamp' => [['transactionTime' => 'yesterday']],
        ];
    }

    #[DataProvider('paymentWindowBoundaries')]
    public function test_payment_timestamp_boundaries_are_exact_even_when_verification_is_late(string $boundary, int $offsetMs, bool $settles): void
    {
        $this->travelTo(now()->startOfSecond());
        $target = $this->target(771033);
        $timestamp = ($boundary === 'start' ? $target->created_at : $target->expires_at)->getTimestampMs() + $offsetMs;
        $this->travelTo($target->expires_at->toImmutable()->addMinute());
        $this->fakeHistory($this->transaction($target, ['transactionTime' => $timestamp]));
        $service = app(BinanceUsdtTopUpSettlement::class);
        $service->submit(Customer::findOrFail($target->customer_id), $target->id, '445011487581044736');
        $service->check($target->id);
        $this->assertSame($settles ? 'settled' : 'manual_review', DB::table('customer_usdt_top_up_settlement_work')->sole()->state);
        if ($settles) {
            $this->assertSame(1_500_000, (int) DB::table('customer_usdt_balance_accounts')->sole()->balance_micro);
            $this->assertTrue((bool) DB::table('customer_usdt_top_up_resolutions')->sole()->late);
        } else {
            $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
            $this->assertSame(0, DB::table('external_money_receipts')->count());
        }
    }

    public static function paymentWindowBoundaries(): array
    {
        return [
            'exact creation time' => ['start', 0, true],
            'one millisecond before creation' => ['start', -1, false],
            'exact deadline' => ['end', 0, true],
            'one millisecond after deadline' => ['end', 1, false],
        ];
    }

    public function test_foreign_customer_cannot_submit_a_hint_for_another_customers_target(): void
    {
        $target = $this->target(771034);
        $foreign = Customer::factory()->create();
        try {
            app(BinanceUsdtTopUpSettlement::class)->submit($foreign, $target->id, '445011487581044736');
            $this->fail('Foreign customer supplied a funding hint.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('customer_usdt_top_up_inbox')->count());
            $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
            Http::assertNothingSent();
        }
    }

    public function test_transfer_after_customer_cancel_is_held_for_review_without_reopening_resolution(): void
    {
        $target = $this->target(771035);
        $customer = Customer::findOrFail($target->customer_id);
        $this->assertTrue(app(TelegramUsdtFundingActions::class)->cancelTarget($customer, $target->id));
        $resolution = DB::table('customer_usdt_top_up_resolutions')->sole();
        $this->fakeHistory($this->transaction($target));
        $service = app(BinanceUsdtTopUpSettlement::class);
        $service->submit($customer, $target->id, '445011487581044736');
        $service->check($target->id);
        $this->assertSame('manual_review', DB::table('customer_usdt_top_up_settlement_work')->sole()->state);
        $this->assertSame('receipt_after_resolution', DB::table('customer_usdt_top_up_settlement_work')->sole()->last_error);
        $this->assertEquals($resolution, DB::table('customer_usdt_top_up_resolutions')->sole());
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame(1, DB::table('external_money_receipts')->count());
    }

    public function test_expired_worker_cannot_credit_and_recovery_settles_once(): void
    {
        $this->freezeTime();
        $target = $this->target(771036);
        $service = app(BinanceUsdtTopUpSettlement::class);
        $service->submit(Customer::findOrFail($target->customer_id), $target->id, '445011487581044736');
        $firstLookup = true;
        Http::fake(['*sapi/v1/pay/transactions*' => function () use ($target, &$firstLookup) {
            if ($firstLookup) {
                $firstLookup = false;
                $this->travel(121)->seconds();
            }

            return Http::response(['code' => '000000', 'success' => true, 'data' => [$this->transaction($target)]]);
        }]);
        $service->check($target->id);
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame(0, DB::table('external_money_receipts')->count());
        $service->recover();
        $this->assertSame(1_500_000, (int) DB::table('customer_usdt_balance_accounts')->sole()->balance_micro);
        $this->assertSame(1, DB::table('customer_usdt_top_up_resolutions')->count());
        $service->recover();
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->count());
    }

    public function test_receipt_obligation_failure_rolls_back_credit_and_global_claim_then_recovers_once(): void
    {
        $target = $this->target(771037);
        $this->fakeHistory($this->transaction($target));
        $service = app(BinanceUsdtTopUpSettlement::class);
        $service->submit(Customer::findOrFail($target->customer_id), $target->id, '445011487581044736');
        DB::unprepared("CREATE TRIGGER reject_binance_receipt BEFORE INSERT ON telegram_outbound_effects BEGIN SELECT RAISE(ABORT, 'receipt obligation failed'); END");
        try {
            $service->recover();
            foreach (['customer_usdt_balance_entries', 'customer_usdt_balance_accounting_events', 'customer_usdt_top_up_resolutions', 'external_money_receipts'] as $table) {
                $this->assertSame(0, DB::table($table)->count(), $table);
            }
            $this->assertNull(CustomerUsdtFundingReservation::findOrFail($target->reservation_id)->resolved_at);
        } finally {
            DB::unprepared('DROP TRIGGER reject_binance_receipt');
        }
        $this->travel(3)->minutes();
        $service->recover();
        $this->assertSame(1_500_000, (int) DB::table('customer_usdt_balance_accounts')->sole()->balance_micro);
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame(1, DB::table('telegram_outbound_effects')->count());
    }

    #[DataProvider('providerFailures')]
    public function test_provider_failure_never_credits_and_later_recovery_settles_once(int $status, mixed $body): void
    {
        $this->freezeTime();
        config(['services.binance.history_max_attempts' => 1]);
        $target = $this->target(771039);
        Http::fake(['*sapi/v1/pay/transactions*' => Http::sequence()
            ->push($body, $status)
            ->push(['code' => '000000', 'success' => true, 'data' => [$this->transaction($target)]])]);
        $service = app(BinanceUsdtTopUpSettlement::class);
        $service->submit(Customer::findOrFail($target->customer_id), $target->id, '445011487581044736');
        $service->recover();
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame(0, DB::table('external_money_receipts')->count());
        $this->assertSame(0, DB::table('customer_usdt_top_up_resolutions')->count());
        $this->assertNull(CustomerUsdtFundingReservation::findOrFail($target->reservation_id)->resolved_at);
        $this->travel(3)->minutes();
        $service->recover();
        $service->recover();
        $this->assertSame(1_500_000, (int) DB::table('customer_usdt_balance_accounts')->sole()->balance_micro);
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame('settled', DB::table('customer_usdt_top_up_resolutions')->sole()->kind);
    }

    public static function providerFailures(): array
    {
        return [
            'rate limit' => [429, ['code' => 'RATE_LIMIT', 'success' => false]],
            'request timeout' => [408, ['code' => 'TIMEOUT', 'success' => false]],
            'provider unavailable' => [503, ['code' => 'UNAVAILABLE', 'success' => false]],
            'unauthorized credential' => [401, ['code' => 'UNAUTHORIZED', 'success' => false]],
            'invalid signature' => [400, ['code' => '100001001', 'success' => false]],
            'API rejection over HTTP 200' => [200, ['code' => 'REJECTED', 'success' => false]],
            'malformed response' => [200, '<html>upstream error</html>'],
            'malformed history list' => [200, ['code' => '000000', 'success' => true, 'data' => 'not a list']],
        ];
    }

    public function test_transient_rate_limit_then_success_credits_once_within_bounded_transport_retries(): void
    {
        $target = $this->target(771040);
        config(['services.binance.history_max_attempts' => 2, 'services.binance.history_retry_delay_ms' => 0]);
        Http::fake(['*sapi/v1/pay/transactions*' => Http::sequence()
            ->push(['code' => 'RATE_LIMIT', 'success' => false], 429, ['Retry-After' => '0'])
            ->push(['code' => '000000', 'success' => true, 'data' => [$this->transaction($target)]])]);
        $service = app(BinanceUsdtTopUpSettlement::class);
        $service->submit(Customer::findOrFail($target->customer_id), $target->id, '445011487581044736');
        $service->recover();
        $this->assertSame(1_500_000, (int) DB::table('customer_usdt_balance_accounts')->sole()->balance_micro);
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame(1, DB::table('external_money_receipts')->count());
        $service->recover();
        $this->assertSame(1, DB::table('customer_usdt_top_up_resolutions')->count());
        Http::assertSentCount(2);
    }

    private function reviewOwner(): User
    {
        $owner = User::factory()->create(['role' => UserRole::Owner, 'password' => 'owner-password']);
        $this->actingAs($owner);
        $session = app('session')->driver();
        $session->start();
        request()->setLaravelSession($session);
        config(['services.telegram.admin_telegram_chat_id' => '998877']);

        return $owner;
    }

    private function fakeHistory(array $transaction): void
    {
        Http::fake(['*sapi/v1/pay/transactions*' => function () use ($transaction) {
            $this->assertSame(0, DB::transactionLevel());

            return Http::response(['code' => '000000', 'success' => true, 'data' => [$transaction]]);
        }]);
    }

    private function transaction(CustomerUsdtTopUp $target, array $changes = []): array
    {
        return array_replace([
            'orderId' => '445011487581044736', 'transactionId' => 'TX-445011487581044736',
            'amount' => '1.5', 'currency' => 'USDT', 'transactionTime' => $target->created_at->getTimestampMs(),
            'status' => 'SUCCESS', 'receiverInfo' => ['accountId' => '12345678'],
        ], $changes);
    }

    private function historicalTarget(): CustomerUsdtTopUp
    {
        $customer = Customer::factory()->create(['telegram_id' => 771009]);
        $root = TelegramNavigationRoot::query()->create(['customer_id' => $customer->id, 'chat_id' => '771009',
            'message_id' => 100, 'media_kind' => 'text', 'screen' => 'home', 'locale' => 'en', 'generation' => 1]);
        $id = (string) Str::uuid();
        $intent = CustomerUsdtFundingIntent::create([
            'customer_id' => $customer->id, 'telegram_navigation_root_id' => $root->id, 'chat_id' => '771009',
            'canonical_message_id' => 100, 'root_generation' => 1, 'nonce' => bin2hex(random_bytes(16)),
            'currency' => 'USDT', 'rail' => 'binance_pay', 'amount_micro' => 300_000, 'fee_micro' => 100_000, 'payable_micro' => 400_000,
            'policy_version' => 1, 'terms_version' => 1, 'receiver_id' => null,
            'payment_snapshot' => ['receiver_pay_id' => '12345678', 'receiver_uid' => null, 'payment_ttl_seconds' => 900],
            'request_fingerprint' => hash('sha256', $id), 'state' => 'confirmed', 'version' => 2,
            'created_at' => now(), 'expires_at' => now()->addMinutes(10), 'claimed_at' => now(),
        ]);
        $reservation = CustomerUsdtFundingReservation::create([
            'customer_id' => $customer->id, 'source_type' => 'customer_usdt_top_up', 'source_id' => $id, 'rail' => 'binance_pay',
            'amount_micro' => 300_000, 'fee_micro' => 100_000, 'policy_version' => 1, 'terms_version' => 1,
            'request_fingerprint' => hash('sha256', $id), 'state' => 'reserved', 'reserved_at' => now(), 'expires_at' => now()->addMinutes(15),
        ]);
        $target = CustomerUsdtTopUp::create($intent->only(['customer_id', 'currency', 'rail', 'amount_micro', 'fee_micro',
            'payable_micro', 'policy_version', 'terms_version', 'receiver_id', 'payment_snapshot', 'request_fingerprint']) + [
                'id' => $id, 'reference' => 'usdt:'.$id, 'intent_id' => $intent->id, 'reservation_id' => $reservation->id,
                'created_at' => now(), 'expires_at' => now()->addMinutes(15),
            ]);
        DB::table('customer_usdt_top_up_settlement_work')->insert(['top_up_id' => $id, 'next_check_at' => now(), 'updated_at' => now()]);

        return $target;
    }

    private function target(int $telegramId, string $amount = '1.5'): CustomerUsdtTopUp
    {
        $customer = Customer::factory()->create(['telegram_id' => $telegramId]);
        TelegramNavigationRoot::query()->create(['customer_id' => $customer->id, 'chat_id' => (string) $telegramId,
            'message_id' => 100, 'media_kind' => 'text', 'screen' => 'home', 'locale' => 'id', 'generation' => 1]);
        $result = app(TelegramUsdtFundingActions::class)->openConfirmedIntent(
            (string) $telegramId, (string) $telegramId, 'private', 100, $amount, CustomerUsdtFundingRail::BinancePay,
        );
        $this->assertSame('confirmed', $result->outcome);
        $this->assertNotNull($result->topUp);

        return $result->topUp;
    }
}
