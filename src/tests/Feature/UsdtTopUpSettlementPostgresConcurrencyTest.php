<?php

namespace Tests\Feature;

use App\Enums\TelegramNavigationMediaKind;
use App\Enums\TelegramNavigationScreen;
use App\Enums\UserRole;
use App\Exceptions\CustomerUsdtFundingReservationConflict;
use App\Models\Customer;
use App\Models\CustomerUsdtFundingPolicy;
use App\Models\CustomerUsdtTopUp;
use App\Models\ManualPaymentSetting;
use App\Models\TelegramNavigationRoot;
use App\Models\User;
use App\Services\Bsc\BscVerificationRpcClientInterface;
use App\Services\Telegram\TelegramUsdtFundingActions;
use App\Services\UsdtBalance\UsdtReceiptReconciliation;
use App\Services\UsdtFunding\CustomerUsdtFundingRelease;
use App\Services\UsdtFunding\CustomerUsdtFundingReservationService;
use App\Services\UsdtReceivers\UsdtDepositReceiverRegistry;
use App\Services\UsdtTopUps\UsdtTopUpPreparation;
use App\Services\UsdtTopUps\UsdtTopUpSettlement;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RunsPostgresWorkers;
use Tests\Support\BscUsdtRailRpcFixture;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
final class UsdtTopUpSettlementPostgresConcurrencyTest extends TestCase
{
    use RunsPostgresWorkers;

    private bool $ownsDatabase = false;

    private Customer $customer;

    private CustomerUsdtTopUp $target;

    private BscUsdtRailRpcFixture $rpc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requirePostgresConcurrency('U7 settlement');
        $this->ownsDatabase = true;
        Artisan::call('migrate:fresh', ['--force' => true]);
        Queue::fake();
        $this->customer = Customer::factory()->create(['telegram_id' => 951001]);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        TelegramNavigationRoot::query()->create([
            'customer_id' => $this->customer->id, 'chat_id' => '951001', 'message_id' => 100,
            'media_kind' => TelegramNavigationMediaKind::Text, 'screen' => TelegramNavigationScreen::Home,
            'locale' => 'id', 'generation' => 1,
        ]);
        ManualPaymentSetting::create(['is_active' => true, 'payment_mode' => 'manual', 'bsc_min_confirmations' => 3, 'bsc_payment_ttl_minutes' => 15]);
        CustomerUsdtFundingPolicy::current()->update(['funding_enabled' => true, 'version' => 1]);
        $registry = app(UsdtDepositReceiverRegistry::class);
        $registry->manualVerify($registry->register($this->customer, BscUsdtRailRpcFixture::ADDRESS, $owner)->receiver, true, $owner);
        $this->rpc = new BscUsdtRailRpcFixture;
        $this->app->instance(BscVerificationRpcClientInterface::class, $this->rpc);
        $actions = app(TelegramUsdtFundingActions::class);
        $nonce = $actions->preview('951001', '951001', 'private', 100, '1')->intent->nonce;
        $this->target = $actions->callback('951001', '951001', 'private', 100, 'usdt:confirm:'.$nonce)->topUp;
        app(UsdtTopUpPreparation::class)->prepare($this->target->id);
        $this->rpc->timestamp = now()->timestamp + 5;
        app(UsdtTopUpSettlement::class)->submit($this->customer, $this->target->id, BscUsdtRailRpcFixture::TX);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->ownsDatabase) {
                Artisan::call('migrate:fresh', ['--force' => true]);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_process_death_after_durable_claim_is_recovered_without_a_second_effect(): void
    {
        $parentPid = getmypid();
        $results = $this->runConcurrentWorkers(1, function () use ($parentPid): string {
            // The claim transaction has committed before the RPC inspection
            // begins. Killing this worker therefore leaves only a leased,
            // durable database obligation for the recovery sweep to reclaim.
            $this->rpc->onCall = static function () use ($parentPid): void {
                if (getmypid() !== $parentPid) {
                    posix_kill(getmypid(), SIGKILL);
                }
            };

            app(UsdtTopUpSettlement::class)->check($this->target->id);

            return 'unexpectedly_completed';
        }, 'usdt-settlement-crash');

        $this->assertFalse($results[0]['ok'], json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(SIGKILL, $results[0]['signal'] ?? null, json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());

        $this->travel(3)->minutes();
        app(UsdtTopUpSettlement::class)->recover();

        $this->assertSingleSettlement();
    }

    public function test_callback_poll_and_expiry_commit_one_economic_resolution(): void
    {
        $this->travel(20)->minutes();
        $results = $this->runConcurrentWorkers(3, function (int $index): string {
            if ($index === 0) {
                app(UsdtTopUpSettlement::class)->submit($this->customer, $this->target->id, BscUsdtRailRpcFixture::TX);
            } elseif ($index === 1) {
                app(CustomerUsdtFundingReservationService::class)->holdExpired();
            }
            app(UsdtTopUpSettlement::class)->recover();

            return 'processed';
        });
        $this->assertWorkersSucceeded($results);
        $this->assertSingleSettlement();
        $this->assertDatabaseConstraintRejects(fn () => DB::table('customer_usdt_top_up_resolutions')->update(['kind' => 'externally_refunded']), 'immutable');
        $this->assertDatabaseConstraintRejects(fn () => DB::table('customer_usdt_top_up_evidence')->delete(), 'immutable');
        $this->assertDatabaseConstraintRejects(fn () => DB::table('customer_usdt_top_up_settlement_work')->update(['state' => 'awaiting_payment', 'version' => DB::raw('version + 1')]), 'immutable');
    }

    public function test_compensation_racing_settlement_resolves_once_and_never_reopens(): void
    {
        $results = $this->runConcurrentWorkers(2, function (int $index): string {
            if ($index === 0) {
                app(UsdtTopUpSettlement::class)->recover();

                return 'checked';
            }
            try {
                app(CustomerUsdtFundingReservationService::class)->release($this->customer, $this->target->reservation_id,
                    CustomerUsdtFundingRelease::compensated('review:race', 'owner:1'));

                return 'compensated';
            } catch (CustomerUsdtFundingReservationConflict) {
                return 'already_settled';
            }
        });
        $this->assertWorkersSucceeded($results);
        // SKIP LOCKED can defer a target while the rejected release holds it.
        // The persisted due work must settle on the next recovery sweep.
        app(UsdtTopUpSettlement::class)->recover();
        $resolution = DB::table('customer_usdt_top_up_resolutions')->sole();
        $this->assertSame('settled', $resolution->kind);
        $this->assertSame($resolution->kind === 'settled' ? 1 : 0, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame($resolution->kind === 'settled' ? 1_000_000 : 0, (int) DB::table('customer_usdt_balance_accounts')->value('balance_micro'));
        $this->assertSame(1, DB::table('customer_usdt_top_up_resolutions')->count());
        $this->assertNotNull(DB::table('customer_usdt_funding_reservations')->value('resolved_at'));
    }

    public function test_external_return_racing_settlement_does_not_double_resolve(): void
    {
        $results = $this->runConcurrentWorkers(2, function (int $index): string {
            if ($index === 0) {
                app(UsdtTopUpSettlement::class)->recover();

                return 'checked';
            }
            try {
                app(CustomerUsdtFundingReservationService::class)->release($this->customer, $this->target->reservation_id,
                    CustomerUsdtFundingRelease::externallyReturned('return:race', 'owner:1'));

                return 'returned';
            } catch (CustomerUsdtFundingReservationConflict) {
                return 'already_settled';
            }
        });
        $this->assertWorkersSucceeded($results);
        // A skipped live lock is durable deferral, not a lost settlement.
        app(UsdtTopUpSettlement::class)->recover();
        $kind = DB::table('customer_usdt_top_up_resolutions')->value('kind');
        $this->assertSame('settled', $kind);
        $this->assertSame($kind === 'settled' ? 1 : 0, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame(1, DB::table('customer_usdt_top_up_resolutions')->count());
    }

    public function test_parallel_mismatched_deposit_recovery_records_one_unresolved_receipt_without_credit(): void
    {
        $this->rpc->receipt['logs'] = [$this->rpc->log(amount: '1250000000000000000')];
        $results = $this->runConcurrentWorkers(3, fn (): string => app(UsdtTopUpSettlement::class)->recover() ? 'checked' : 'deferred');
        $this->assertWorkersSucceeded($results);
        app(UsdtTopUpSettlement::class)->recover();
        $this->assertSame('manual_review', DB::table('customer_usdt_top_up_settlement_work')->value('state'));
        $this->assertSame(1, DB::table('external_money_receipts')->count());
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame(0, DB::table('customer_usdt_top_up_resolutions')->count());
        $this->assertSame('1250000', app(UsdtReceiptReconciliation::class)->inspect(null, null, null, 100)->unresolvedDepositMicro->toString());
    }

    public function test_abandoned_lease_is_reclaimed_by_only_one_worker(): void
    {
        DB::table('customer_usdt_top_up_settlement_work')->update([
            'version' => DB::raw('version + 1'), 'lease_token' => str_repeat('f', 32), 'lease_until' => now()->subMinute(),
        ]);
        $results = $this->runConcurrentWorkers(3, fn (): string => app(UsdtTopUpSettlement::class)->recover() ? 'recovered' : 'owned');
        $this->assertWorkersSucceeded($results);
        $this->assertSingleSettlement();
        $this->assertNull(DB::table('customer_usdt_top_up_settlement_work')->value('lease_token'));
    }

    private function assertSingleSettlement(): void
    {
        foreach (['customer_usdt_top_up_resolutions', 'customer_usdt_balance_entries', 'customer_usdt_balance_accounting_events', 'external_money_receipts', 'telegram_outbound_effects'] as $table) {
            $this->assertSame(1, DB::table($table)->count(), $table);
        }
        $this->assertSame(1_000_000, (int) DB::table('customer_usdt_balance_accounts')->value('balance_micro'));
        $this->assertSame('settled', DB::table('customer_usdt_top_up_settlement_work')->value('state'));
    }
}
