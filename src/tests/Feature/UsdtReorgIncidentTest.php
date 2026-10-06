<?php

namespace Tests\Feature;

use App\Enums\TelegramNavigationMediaKind;
use App\Enums\TelegramNavigationScreen;
use App\Enums\UserRole;
use App\Exceptions\BalanceAdjustmentRejected;
use App\Exceptions\UsdtBalanceFrozenException;
use App\Models\Customer;
use App\Models\CustomerUsdtFundingPolicy;
use App\Models\CustomerUsdtTopUp;
use App\Models\ManualPaymentSetting;
use App\Models\TelegramNavigationRoot;
use App\Models\UsdtReorgIncident;
use App\Models\User;
use App\Services\Bsc\BscVerificationRpcClientInterface;
use App\Services\Telegram\TelegramUsdtFundingActions;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\UsdtPurchase;
use App\Services\UsdtReceivers\UsdtDepositReceiverRegistry;
use App\Services\UsdtTopUps\BscUsdtTransferIngestion;
use App\Services\UsdtTopUps\UsdtReorgIncidentService;
use App\Services\UsdtTopUps\UsdtTopUpPreparation;
use App\Services\UsdtTopUps\UsdtTopUpSettlement;
use App\Services\UsdtTreasury\UsdtTreasuryReconciliation;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Support\BscUsdtRailRpcFixture;
use Tests\TestCase;

class UsdtReorgIncidentTest extends TestCase
{
    protected Customer $customer;

    protected CustomerUsdtTopUp $target;

    protected BscUsdtRailRpcFixture $rpc;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-10-06 08:00:00 UTC'));
        Http::preventStrayRequests();
        Queue::fake();
        $this->rpc = new BscUsdtRailRpcFixture;
        $this->app->instance(BscVerificationRpcClientInterface::class, $this->rpc);
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
        $actions = app(TelegramUsdtFundingActions::class);
        $nonce = $actions->preview('951001', '951001', 'private', 100, '1')->intent->nonce;
        $this->target = $actions->callback('951001', '951001', 'private', 100, 'usdt:confirm:'.$nonce)->topUp;
        app(UsdtTopUpPreparation::class)->prepare($this->target->id);
        $this->rpc->timestamp = now()->timestamp + 5;
    }

    protected function settle(): void
    {
        $service = app(UsdtTopUpSettlement::class);
        $service->submit($this->customer, $this->target->id, BscUsdtRailRpcFixture::TX);
        $service->check($this->target->id);
    }

    public function test_settled_reorg_freezes_account_once_without_rewriting_money_or_receipt(): void
    {
        $this->settle();
        $before = DB::table('customer_usdt_balance_entries')->get()->toJson();
        $receipt = DB::table('external_money_receipts')->get()->toJson();
        $this->forkBlock();
        $service = app(UsdtReorgIncidentService::class);
        $this->assertTrue($service->check($this->target->id));
        $this->assertFalse($service->check($this->target->id));
        $incident = UsdtReorgIncident::query()->sole();
        $this->assertSame(1_000_000, $incident->exposure_micro);
        $this->assertSame(0, $incident->spent_exposure_micro);
        $this->assertSame($before, DB::table('customer_usdt_balance_entries')->get()->toJson());
        $this->assertSame($receipt, DB::table('external_money_receipts')->get()->toJson());
        $this->assertSame(1_000_000, (int) DB::table('customer_usdt_balance_accounts')->value('balance_micro'));
        $this->assertSame('usdt_reorg_incident:'.$incident->id, DB::table('customer_usdt_balance_accounts')->value('frozen_reason'));
        $audit = DB::table('audit_logs')->where('action', 'usdt_reorg.detected')->sole();
        $this->assertStringNotContainsString(BscUsdtRailRpcFixture::TX, $audit->new_values);
        $this->expectException(UsdtBalanceFrozenException::class);
        $this->spend(1);
    }

    public function test_spent_reorg_remains_visible_loss_even_with_zero_liability(): void
    {
        $this->settle();
        $this->spend(1_000_000);
        $this->forkBlock();
        $this->assertTrue(app(UsdtReorgIncidentService::class)->check($this->target->id));
        $this->assertSame(1_000_000, UsdtReorgIncident::query()->sole()->spent_exposure_micro);
        $this->assertSame(0, (int) DB::table('customer_usdt_balance_accounts')->value('balance_micro'));
        $report = app(UsdtTreasuryReconciliation::class)->inspect(null, BigInteger::zero(), BigInteger::zero());
        $this->assertTrue($report->isFinding());
        $this->assertContains('unresolved_reorg_loss', $report->findings);
        $this->assertSame('1000000', $report->toArray()['reorg_spent_exposure_micro']);
        $this->assertSame(2, DB::table('customer_usdt_balance_entries')->count());
    }

    public function test_presettlement_reorg_and_rpc_absence_do_not_create_credit_or_incident(): void
    {
        $this->forkBlock();
        $this->assertFalse(app(UsdtReorgIncidentService::class)->check($this->target->id));
        $this->settle();
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame(0, UsdtReorgIncident::query()->count());
    }

    public function test_unavailable_canonical_block_is_not_reorg_evidence(): void
    {
        $this->settle();
        $this->rpc->blockOverrides[100] = null;
        $this->assertFalse(app(UsdtReorgIncidentService::class)->check($this->target->id));
        $this->assertNull(DB::table('customer_usdt_balance_accounts')->value('frozen_reason'));
    }

    public function test_checkpoint_change_during_rpc_invalidates_prepared_detection(): void
    {
        $this->settle();
        $this->forkBlock();
        $changed = false;
        $this->rpc->onCall = function (string $method) use (&$changed): void {
            if (! $changed && $method === 'blockByNumber:100') {
                $changed = true;
                DB::table('usdt_bsc_scan_checkpoints')->where('receiver_id', $this->target->receiver_id)
                    ->update(['version' => DB::raw('version + 1')]);
            }
        };
        $this->assertFalse(app(UsdtReorgIncidentService::class)->check($this->target->id));
        $this->assertSame(0, UsdtReorgIncident::query()->count());
        $this->rpc->onCall = null;
        $this->assertTrue(app(UsdtReorgIncidentService::class)->check($this->target->id));
    }

    public function test_scanner_checkpoint_reorg_persists_receiver_evidence_and_freezes_settled_exposure_on_recovery(): void
    {
        $this->settle();
        $scanner = app(BscUsdtTransferIngestion::class);
        $this->rpc->head = 120;
        $this->rpc->finalized = 115;
        $firstBlock = (int) DB::table('usdt_bsc_scan_checkpoints')->where('receiver_id', $this->target->receiver_id)->value('first_block');
        $scanner->scan($this->target->receiver_id, $firstBlock);
        $this->rpc->finalized = 116;
        $this->forkBlock(115);
        $report = $scanner->scan($this->target->receiver_id, $firstBlock);
        $this->assertSame('checkpoint_reorg', $report['reason']);
        $this->assertSame(1, DB::table('usdt_reorg_receiver_evidence')->count());
        $this->assertSame('usdt_receiver_reorg:'.$this->target->receiver_id, DB::table('customer_usdt_balance_accounts')->value('frozen_reason'));
        $this->assertSame(1, app(UsdtReorgIncidentService::class)->recover(1));
        $this->assertSame('checkpoint_reorg', UsdtReorgIncident::query()->sole()->reason);
        $this->assertNotNull(UsdtReorgIncident::query()->sole()->evidence['receiver_detection']);
    }

    public function test_durable_keyset_monitor_rotates_after_rpc_failure_and_revisits(): void
    {
        $this->settle();
        $this->rpc->failMethods = ['chainId'];
        $this->assertSame(0, app(UsdtReorgIncidentService::class)->recover(1));
        $this->assertGreaterThan(0, (int) DB::table('usdt_reorg_monitor')->value('cursor'));
        $this->assertSame(0, app(UsdtReorgIncidentService::class)->recover(1));
        $this->assertSame(0, (int) DB::table('usdt_reorg_monitor')->value('cursor'));
        $this->rpc->failMethods = [];
        $this->forkBlock();
        $this->assertSame(1, app(UsdtReorgIncidentService::class)->recover(1));
    }

    public function test_recovery_case_requires_action_password_and_remains_unresolved_loss(): void
    {
        $this->settle();
        $this->spend(1_000_000);
        $this->forkBlock();
        $service = app(UsdtReorgIncidentService::class);
        $service->check($this->target->id);
        $owner = $this->ownerSession();
        $nonce = (string) Str::uuid();
        $request = $service->requestRecovery($owner, UsdtReorgIncident::query()->sole()->id, $nonce, 'Investigate treasury recovery');
        $this->assertSame($request->id, $service->requestRecovery($owner, $request->incident_id, $nonce, $request->reason)->id);
        try {
            $service->confirmRecovery($owner, $request->id, 'wrong');
            $this->fail('Password is required for the particular action.');
        } catch (BalanceAdjustmentRejected) {
            $this->assertSame(0, DB::table('usdt_reorg_recovery_cases')->count());
        }
        $case = $service->confirmRecovery($owner, $request->id, 'password', $request->fingerprint);
        $this->assertSame('authorized_unresolved', $case->status);
        $this->assertSame($case->id, $service->confirmRecovery($owner, $request->id, 'password')->id);
        $report = app(UsdtTreasuryReconciliation::class)->inspect(null, BigInteger::zero(), BigInteger::zero())->toArray();
        $this->assertSame(1, $report['authorized_recovery_case_count']);
        $this->assertSame('1000000', $report['reorg_spent_exposure_micro']);
        $this->assertSame(0, (int) DB::table('customer_usdt_balance_accounts')->value('balance_micro'));
        $this->assertSame(2, DB::table('customer_usdt_balance_entries')->count());
        $this->assertNotNull(DB::table('customer_usdt_balance_accounts')->value('frozen_reason'));
    }

    public function test_recovery_changed_nonce_content_and_revoked_owner_are_rejected(): void
    {
        $this->settle();
        $this->forkBlock();
        $service = app(UsdtReorgIncidentService::class);
        $service->check($this->target->id);
        $owner = $this->ownerSession();
        $nonce = (string) Str::uuid();
        $request = $service->requestRecovery($owner, UsdtReorgIncident::query()->sole()->id, $nonce, 'Review loss');
        try {
            $service->requestRecovery($owner, $request->incident_id, $nonce, 'Changed decision');
            $this->fail('Immutable request content must match.');
        } catch (InvalidArgumentException) {
            $this->assertSame(1, DB::table('usdt_reorg_recovery_requests')->count());
        }
        $owner->update(['role' => UserRole::Admin]);
        $this->expectException(AuthorizationException::class);
        $service->confirmRecovery($owner, $request->id, 'password');
    }

    public function test_reconciliation_cli_prints_unresolved_reorg_loss(): void
    {
        $this->settle();
        $this->spend(1_000_000);
        $this->forkBlock();
        app(UsdtReorgIncidentService::class)->check($this->target->id);
        $this->artisan('usdt-balance:reconcile', ['--no-freeze' => true])
            ->expectsOutputToContain('REORG_LOSS incidents=1 exposure_micro=1000000 spent_exposure_micro=1000000')
            ->assertExitCode(1);
    }

    public function test_unstable_canonical_rpc_evidence_does_not_freeze(): void
    {
        $this->settle();
        $this->forkBlock();
        $reads = 0;
        $this->rpc->onCall = function (string $method) use (&$reads): void {
            if ($method === 'blockByNumber:100' && ++$reads === 2) {
                unset($this->rpc->blockOverrides[100]);
            }
        };
        $this->assertFalse(app(UsdtReorgIncidentService::class)->check($this->target->id));
        $this->assertSame(0, UsdtReorgIncident::query()->count());
        $this->assertNull(DB::table('customer_usdt_balance_accounts')->value('frozen_reason'));
    }

    public function test_recovery_rejects_stale_session_and_tampered_preview(): void
    {
        $this->settle();
        $this->forkBlock();
        $service = app(UsdtReorgIncidentService::class);
        $service->check($this->target->id);
        $owner = $this->ownerSession();
        $request = $service->requestRecovery($owner, UsdtReorgIncident::query()->sole()->id, (string) Str::uuid(), 'Review loss');
        try {
            $service->confirmRecovery($owner, $request->id, 'password', str_repeat('a', 64));
            $this->fail('Tampered preview must fail before password authorization.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, DB::table('usdt_reorg_recovery_cases')->count());
        }
        request()->session()->regenerate();
        $this->expectException(AuthorizationException::class);
        $service->confirmRecovery($owner, $request->id, 'password');
    }

    protected function forkBlock(int $number = 100): void
    {
        $this->rpc->blockOverrides[$number] = ['number' => '0x'.dechex($number),
            'hash' => '0x'.str_repeat('f', 64), 'timestamp' => '0x'.dechex($this->rpc->timestamp)];
    }

    private function spend(int $amount): void
    {
        app(CustomerUsdtBalanceLedger::class)->debitPurchase($this->customer,
            new UsdtPurchase($this->customer->id, UsdtMicroAmount::fromUnits($amount), 'sandbox-order', 'sandbox-payment'),
            'sandbox-purchase:'.$amount);
    }

    private function ownerSession(): User
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->actingAs($owner);
        $session = app('session.store');
        $session->start();
        request()->setLaravelSession($session);
        config(['services.telegram.admin_telegram_chat_id' => '998877']);

        return $owner;
    }
}
