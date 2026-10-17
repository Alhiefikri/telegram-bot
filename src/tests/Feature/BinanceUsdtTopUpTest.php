<?php

namespace Tests\Feature;

use App\Enums\CustomerUsdtFundingRail;
use App\Models\Customer;
use App\Models\CustomerUsdtFundingIntent;
use App\Models\CustomerUsdtFundingPolicy;
use App\Models\CustomerUsdtFundingReservation;
use App\Models\CustomerUsdtTopUp;
use App\Models\ManualPaymentSetting;
use App\Models\TelegramNavigationRoot;
use App\Services\Telegram\TelegramUsdtFundingActions;
use App\Services\UsdtTopUps\BinanceUsdtTopUpSettlement;
use App\Services\UsdtTopUps\UsdtTopUpStatusReader;
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
        $this->assertFalse($service->check($target->id));
        $this->assertSame(1, DB::table('customer_usdt_top_up_resolutions')->count());
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->count());
        Http::assertSentCount(2);
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
        $actions = app(TelegramUsdtFundingActions::class);
        $preview = $actions->preview((string) $telegramId, (string) $telegramId, 'private', 100, $amount, CustomerUsdtFundingRail::BinancePay);
        $this->assertSame('opened', $preview->outcome);

        return $actions->callback((string) $telegramId, (string) $telegramId, 'private', 100, 'usdt:confirm:'.$preview->intent->nonce)->topUp;
    }
}
