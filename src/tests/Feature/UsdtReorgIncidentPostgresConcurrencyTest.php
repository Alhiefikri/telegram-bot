<?php

namespace Tests\Feature;

use App\Enums\TelegramNavigationMediaKind;
use App\Enums\TelegramNavigationScreen;
use App\Enums\UsdtBalanceUnfreezeStatus;
use App\Enums\UserRole;
use App\Exceptions\BalanceAdjustmentRejected;
use App\Exceptions\UsdtBalanceFrozenException;
use App\Models\Customer;
use App\Models\CustomerUsdtFundingPolicy;
use App\Models\CustomerUsdtTopUp;
use App\Models\ManualPaymentSetting;
use App\Models\TelegramNavigationRoot;
use App\Models\User;
use App\Services\Bsc\BscVerificationRpcClientInterface;
use App\Services\Operations\UsdtBalanceAccountUnfreeze;
use App\Services\Telegram\TelegramUsdtFundingActions;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\UsdtPurchase;
use App\Services\UsdtReceivers\UsdtDepositReceiverRegistry;
use App\Services\UsdtTopUps\BscUsdtTransferIngestion;
use App\Services\UsdtTopUps\UsdtReorgIncidentService;
use App\Services\UsdtTopUps\UsdtTopUpPreparation;
use App\Services\UsdtTopUps\UsdtTopUpSettlement;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RunsPostgresWorkers;
use Tests\Support\BscUsdtRailRpcFixture;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
final class UsdtReorgIncidentPostgresConcurrencyTest extends TestCase
{
    use RunsPostgresWorkers;

    private bool $ownsDatabase = false;

    private Customer $customer;

    private CustomerUsdtTopUp $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requirePostgresConcurrency('U8 reorg');
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
        $rpc = new BscUsdtRailRpcFixture;
        $this->app->instance(BscVerificationRpcClientInterface::class, $rpc);
        $actions = app(TelegramUsdtFundingActions::class);
        $nonce = $actions->preview('951001', '951001', 'private', 100, '1')->intent->nonce;
        $this->target = $actions->callback('951001', '951001', 'private', 100, 'usdt:confirm:'.$nonce)->topUp;
        app(UsdtTopUpPreparation::class)->prepare($this->target->id);
        $rpc->timestamp = now()->timestamp + 5;
        app(UsdtTopUpSettlement::class)->submit($this->customer, $this->target->id, BscUsdtRailRpcFixture::TX);
        app(UsdtTopUpSettlement::class)->check($this->target->id);
        $rpc->blockOverrides[100] = ['number' => '0x64', 'hash' => '0x'.str_repeat('f', 64),
            'timestamp' => '0x'.dechex($rpc->timestamp)];
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

    public function test_parallel_monitor_and_manual_check_freeze_once_and_preserve_immutable_history(): void
    {
        $results = $this->runConcurrentWorkers(3, function (int $index): string {
            $service = app(UsdtReorgIncidentService::class);
            $index === 0 ? $service->check($this->target->id) : $service->recover(1);

            return 'checked';
        });
        $this->assertWorkersSucceeded($results);
        $this->assertSame(1, DB::table('usdt_reorg_incidents')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'usdt_reorg.detected')->count());
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame(1_000_000, (int) DB::table('customer_usdt_balance_accounts')->value('balance_micro'));
        $this->assertNotNull(DB::table('customer_usdt_balance_accounts')->value('frozen_reason'));
        $this->assertDatabaseConstraintRejects(fn () => DB::table('usdt_reorg_incidents')->update(['spent_exposure_micro' => 1]), 'immutable');
        $this->assertDatabaseConstraintRejects(fn () => DB::table('usdt_reorg_incidents')->delete(), 'immutable');
        $this->assertDatabaseConstraintRejects(fn () => DB::table('customer_usdt_top_up_resolutions')->delete(), 'immutable');
    }

    public function test_purchase_racing_reorg_freeze_has_no_negative_balance_or_automatic_debit(): void
    {
        $results = $this->runConcurrentWorkers(2, function (int $index): string {
            if ($index === 0) {
                app(UsdtReorgIncidentService::class)->check($this->target->id);

                return 'contained';
            }
            try {
                app(CustomerUsdtBalanceLedger::class)->debitPurchase($this->customer,
                    new UsdtPurchase($this->customer->id, UsdtMicroAmount::fromUnits(1_000_000), 'sandbox-order', 'sandbox-payment'),
                    'sandbox-order-purchase');

                return 'spent';
            } catch (UsdtBalanceFrozenException) {
                return 'frozen';
            }
        });
        $this->assertWorkersSucceeded($results);
        $incident = DB::table('usdt_reorg_incidents')->sole();
        $balance = (int) DB::table('customer_usdt_balance_accounts')->value('balance_micro');
        $this->assertContains($balance, [0, 1_000_000]);
        $this->assertSame(1_000_000 - $balance, (int) $incident->spent_exposure_micro);
        $this->assertSame($balance, (int) $incident->available_exposure_micro);
        $this->assertSame($balance === 0 ? 2 : 1, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame(0, DB::table('usdt_reorg_recovery_cases')->count());
    }

    public static function receiverFreezeReasons(): array
    {
        return [
            'receiver reorg freeze' => [null],
            'reorg detected while already drift-frozen' => ['reconciliation_drift:balance_mismatch'],
        ];
    }

    #[DataProvider('receiverFreezeReasons')]
    public function test_receiver_reorg_blocks_unfreeze_and_spending_before_incident_materialization(?string $existingFreeze): void
    {
        $rpc = app(BscVerificationRpcClientInterface::class);
        $scanner = app(BscUsdtTransferIngestion::class);
        $rpc->head = 120;
        $rpc->finalized = 115;
        $firstBlock = (int) DB::table('usdt_bsc_scan_checkpoints')->where('receiver_id', $this->target->receiver_id)->value('first_block');
        $scanner->scan($this->target->receiver_id, $firstBlock);
        DB::table('customer_usdt_balance_accounts')->update(['frozen_reason' => $existingFreeze]);
        $rpc->finalized = 116;
        $rpc->blockOverrides[115] = ['number' => '0x73', 'hash' => '0x'.str_repeat('f', 64),
            'timestamp' => '0x'.dechex($rpc->timestamp)];
        $this->assertSame('checkpoint_reorg', $scanner->scan($this->target->receiver_id, $firstBlock)['reason']);
        $this->assertSame(1, DB::table('usdt_reorg_receiver_evidence')->count());
        $this->assertSame(0, DB::table('usdt_reorg_incidents')->count());
        $frozenReason = $existingFreeze ?? 'usdt_receiver_reorg:'.$this->target->receiver_id;
        $this->assertSame($frozenReason, DB::table('customer_usdt_balance_accounts')->value('frozen_reason'));
        $entries = DB::table('customer_usdt_balance_entries')->get()->toJson();
        $receipts = DB::table('external_money_receipts')->get()->toJson();
        $preview = app(UsdtBalanceAccountUnfreeze::class)->unfreeze($this->customer, 'attempt', 'operator', dryRun: true);

        $results = $this->runConcurrentWorkers(3, function (int $index): string {
            $status = app(UsdtBalanceAccountUnfreeze::class)->unfreeze($this->customer, 'attempt', 'operator')->status;
            try {
                app(CustomerUsdtBalanceLedger::class)->debitPurchase($this->customer,
                    new UsdtPurchase($this->customer->id, UsdtMicroAmount::fromUnits(1), 'gap-order-'.$index, 'gap-payment-'.$index),
                    'gap-purchase-'.$index);

                return $status->value.':spent';
            } catch (UsdtBalanceFrozenException) {
                return $status->value.':frozen';
            }
        });
        $this->assertWorkersSucceeded($results);
        $this->assertSame(['reorg_hold:frozen', 'reorg_hold:frozen', 'reorg_hold:frozen'], array_column($results, 'value'));
        $this->assertSame(UsdtBalanceUnfreezeStatus::ReorgHold, $preview->status);
        $this->assertSame($frozenReason, DB::table('customer_usdt_balance_accounts')->value('frozen_reason'));
        $this->assertSame(1_000_000, (int) DB::table('customer_usdt_balance_accounts')->value('balance_micro'));
        $this->assertSame($entries, DB::table('customer_usdt_balance_entries')->get()->toJson());
        $this->assertSame($receipts, DB::table('external_money_receipts')->get()->toJson());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'usdt_balance.account_unfrozen')->count());
        $this->assertSame(0, DB::table('usdt_reorg_incidents')->count());
        $this->assertSame(1, app(UsdtReorgIncidentService::class)->recover(1));
        $this->assertSame(UsdtBalanceUnfreezeStatus::ReorgHold,
            app(UsdtBalanceAccountUnfreeze::class)->unfreeze($this->customer, 'attempt', 'operator')->status);
    }

    public function test_parallel_owner_confirmation_persists_one_authorized_unresolved_case(): void
    {
        $service = app(UsdtReorgIncidentService::class);
        $service->check($this->target->id);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->actingAs($owner);
        $session = app('session.store');
        $session->start();
        request()->setLaravelSession($session);
        config(['services.telegram.admin_telegram_chat_id' => '998877']);
        $request = $service->requestRecovery($owner, (int) DB::table('usdt_reorg_incidents')->value('id'),
            (string) Str::uuid(), 'Investigate real treasury recovery');
        $results = $this->runConcurrentWorkers(2, function () use ($owner, $request): string {
            try {
                app(UsdtReorgIncidentService::class)->confirmRecovery($owner, $request->id, 'password', $request->fingerprint);

                return 'authorized';
            } catch (BalanceAdjustmentRejected) {
                return 'auth_busy';
            }
        });
        $this->assertWorkersSucceeded($results);
        $this->assertSame(1, DB::table('usdt_reorg_recovery_cases')->count());
        $this->assertSame('authorized_unresolved', DB::table('usdt_reorg_recovery_cases')->value('status'));
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame(1_000_000, (int) DB::table('customer_usdt_balance_accounts')->value('balance_micro'));
        $this->assertNotNull(DB::table('customer_usdt_balance_accounts')->value('frozen_reason'));
        $this->assertDatabaseConstraintRejects(fn () => DB::table('usdt_reorg_recovery_cases')->update(['status' => 'resolved']), 'immutable');
        $this->assertDatabaseConstraintRejects(fn () => DB::table('usdt_reorg_recovery_requests')->update(['reason' => 'Changed']), 'immutable');
    }
}
