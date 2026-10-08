<?php

namespace Tests\Feature;

use App\Enums\TelegramNavigationMediaKind;
use App\Enums\TelegramNavigationScreen;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\CustomerUsdtFundingIntent;
use App\Models\CustomerUsdtFundingPolicy;
use App\Models\CustomerUsdtTopUp;
use App\Models\ManualPaymentSetting;
use App\Models\TelegramNavigationRoot;
use App\Models\User;
use App\Services\Bsc\BscVerificationRpcClientInterface;
use App\Services\Telegram\TelegramUsdtFundingActions;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtFunding\UsdtFundingIntentResult;
use App\Services\UsdtFunding\UsdtFundingIntentService;
use App\Services\UsdtReceivers\UsdtDepositReceiverRegistry;
use App\Services\UsdtTopUps\UsdtTopUpPreparation;
use App\Services\UsdtTopUps\UsdtTopUpRail;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BscUsdtRailRpcFixture;
use Tests\TestCase;

class UsdtFundingIntentTest extends TestCase
{
    protected Customer $customer;

    protected TelegramNavigationRoot $root;

    protected User $owner;

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
        $this->owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->root = TelegramNavigationRoot::query()->create([
            'customer_id' => $this->customer->id, 'chat_id' => '951001', 'message_id' => 100,
            'media_kind' => TelegramNavigationMediaKind::Text, 'screen' => TelegramNavigationScreen::Home,
            'locale' => 'id', 'generation' => 1,
        ]);
        ManualPaymentSetting::create(['is_active' => true, 'payment_mode' => 'manual', 'bsc_min_confirmations' => 3, 'bsc_payment_ttl_minutes' => 15]);
        CustomerUsdtFundingPolicy::current()->update(['funding_enabled' => true, 'version' => 1]);
        $registry = app(UsdtDepositReceiverRegistry::class);
        $registry->manualVerify($registry->register($this->customer, BscUsdtRailRpcFixture::ADDRESS, $this->owner)->receiver, true, $this->owner);
    }

    public function test_preview_and_duplicate_confirmation_have_no_external_or_money_effect_and_reuse_target(): void
    {
        $opened = $this->preview();
        $this->assertSame('opened', $opened->outcome);
        $this->assertSame($opened->intent->id, $this->preview()->intent->id);
        $this->assertSame([], $this->rpc->calls);
        $this->assertSame(0, DB::table('customer_usdt_funding_reservations')->count());
        $this->assertSame(0, DB::table('usdt_bsc_rail_invoices')->count());
        $first = $this->action($opened->intent->nonce);
        CustomerUsdtFundingPolicy::current()->update(['version' => 2, 'funding_enabled' => false]);
        $second = $this->action($opened->intent->nonce);
        $this->assertSame('confirmed', $first->outcome);
        $this->assertSame($first->topUp->id, $second->topUp->id);
        $this->assertSame('1.000001', UsdtMicroAmount::fromUnits($first->topUp->amount_micro)->decimal());
        $this->assertSame(1, CustomerUsdtTopUp::query()->count());
        $this->assertSame(1, DB::table('customer_usdt_top_up_work')->count());
        $this->assertSame(1, DB::table('customer_usdt_funding_reservations')->count());
        $this->assertSame([], $this->rpc->calls);
        $this->assertNoMoneyOrOutbound();
    }

    public function test_unknown_guest_group_and_cross_actor_are_rejected_without_registering_customer(): void
    {
        $nonce = $this->preview()->intent->nonce;
        $adapter = app(TelegramUsdtFundingActions::class);
        foreach ([['951002', '951002', 'private'], ['951002', '951001', 'private'], ['951001', '-951001', 'group'], ['guest', 'guest', 'private'], ['9999999999999999999', '9999999999999999999', 'private']] as [$actor, $chat, $type]) {
            $this->assertSame('unauthorized', $adapter->preview($actor, $chat, $type, 100, '1')->outcome);
            $this->assertSame('unauthorized', $adapter->callback($actor, $chat, $type, 100, 'usdt:confirm:'.$nonce)->outcome);
        }
        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(0, CustomerUsdtTopUp::query()->count());
        $this->assertNoMoneyOrOutbound();
    }

    public function test_wrong_nonce_is_rejected_and_render_generation_advance_keeps_own_confirmation(): void
    {
        $intent = $this->preview()->intent;
        $this->assertSame('stale', $this->action(str_repeat('f', 32))->outcome);
        $this->assertSame('unauthorized', app(TelegramUsdtFundingActions::class)->callback('951001', '951001', 'private', 101, 'usdt:confirm:'.$intent->nonce)->outcome);

        // Rendering the canonical message advances its render generation; the
        // Customer's own confirmation on the same message stays valid.
        $this->root->update(['generation' => 2]);
        $this->assertSame('confirmed', $this->action($intent->nonce)->outcome);
        $this->assertSame(1, CustomerUsdtTopUp::query()->count());
    }

    public function test_replaced_canonical_message_cannot_confirm_its_old_preview(): void
    {
        $intent = $this->preview()->intent;
        $this->root->update(['message_id' => 101, 'generation' => 2]);

        $this->assertSame('stale', app(UsdtFundingIntentService::class)->claim(
            $this->customer,
            '951001',
            (int) $this->root->id,
            101,
            $intent->nonce,
            'confirm',
        )->outcome);
        $this->assertSame(0, CustomerUsdtTopUp::query()->count());
        $this->assertSame(0, DB::table('customer_usdt_funding_reservations')->count());
    }

    public function test_cancel_and_expire_previews_never_reserve_or_prepare(): void
    {
        $first = $this->preview()->intent;
        $this->assertSame('cancelled', $this->action($first->nonce, 'cancel')->outcome);
        $this->assertSame('cancelled', $this->action($first->nonce)->outcome);
        $second = $this->preview()->intent;
        $this->travel(10)->minutes();
        $this->assertSame('expired', $this->action($second->nonce)->outcome);
        $this->assertSame(0, DB::table('customer_usdt_funding_reservations')->count());
        $this->assertSame([], $this->rpc->calls);
        $this->assertNoMoneyOrOutbound();
    }

    public function test_stale_policy_payment_terms_and_replaced_amount_require_new_confirmation(): void
    {
        $first = $this->preview()->intent;
        CustomerUsdtFundingPolicy::current()->update(['version' => 2]);
        $this->assertSame('stale_policy', $this->action($first->nonce)->outcome);
        $second = $this->preview()->intent;
        ManualPaymentSetting::current()->update(['bsc_min_confirmations' => 9]);
        $this->assertSame('stale_terms', $this->action($second->nonce)->outcome);
        $third = $this->preview()->intent;
        $new = app(TelegramUsdtFundingActions::class)->preview('951001', '951001', 'private', 100, '2');
        $this->assertNotSame($third->nonce, $new->intent->nonce);
        $this->assertSame('superseded', $this->action($third->nonce)->outcome);
        $this->assertSame(0, CustomerUsdtTopUp::query()->count());
    }

    public function test_confirmed_target_keeps_one_active_slot_even_after_payment_deadline(): void
    {
        $target = $this->action($this->preview()->intent->nonce)->topUp;
        $this->assertSame($target->expires_at->format('Y-m-d H:i:s'), DB::table('customer_usdt_funding_reservations')->value('expires_at'));
        $this->travel(16)->minutes();
        $this->artisan('usdt-funding:recover', ['--json' => true])->assertSuccessful();
        $this->assertSame('expired_unresolved', DB::table('customer_usdt_funding_reservations')->value('state'));
        $this->assertSame('expired_unresolved', DB::table('customer_usdt_top_up_work')->value('state'));
        $this->assertSame('active_target', $this->preview()->outcome);
        $this->assertSame($target->id, $this->preview()->topUp->id);
        $this->assertSame(1, CustomerUsdtTopUp::query()->count());
    }

    public function test_rpc_failure_retains_reference_and_recovery_creates_same_target_with_snapshotted_terms(): void
    {
        $target = $this->action($this->preview()->intent->nonce)->topUp;
        $this->rpc->failMethods = ['chainId'];
        $this->assertTrue(app(UsdtTopUpPreparation::class)->prepare($target->id));
        $this->assertSame('creation_unknown', DB::table('customer_usdt_top_up_work')->value('state'));
        $this->assertSame(0, DB::table('usdt_bsc_rail_invoices')->count());
        $this->rpc->failMethods = [];
        ManualPaymentSetting::current()->update(['bsc_min_confirmations' => 9, 'bsc_payment_ttl_minutes' => 2]);
        CustomerUsdtFundingPolicy::current()->update(['version' => 2, 'application_fee_micro' => 100]);
        $this->travel(11)->seconds();
        $this->artisan('usdt-funding:recover')->assertSuccessful();
        $invoice = DB::table('usdt_bsc_rail_invoices')->sole();
        $this->assertSame($target->reference, $invoice->reference);
        $this->assertSame(3, (int) $invoice->minimum_confirmations);
        $this->assertSame($target->expires_at->getTimestamp(), (int) $invoice->expires_at_epoch);
        $this->assertSame($target->payable_micro, (int) $invoice->payable_micro);
        $this->assertSame('ready', DB::table('customer_usdt_top_up_work')->value('state'));
        $this->assertNoMoneyOrOutbound();
    }

    public function test_lost_worker_response_recovers_invoice_after_retirement_without_retargeting(): void
    {
        $target = $this->action($this->preview()->intent->nonce)->topUp;
        $invoice = app(UsdtTopUpRail::class)->createOrRecover($this->customer, $target->reference, UsdtMicroAmount::fromUnits($target->payable_micro), $target->expires_at, $target);
        $registry = app(UsdtDepositReceiverRegistry::class);
        $registry->retire($registry->findByAddress(56, BscUsdtRailRpcFixture::ADDRESS), $this->owner, 'Rotation');
        $this->travel(16)->minutes();
        $this->artisan('usdt-funding:recover')->assertSuccessful();
        $this->assertSame($invoice->id, DB::table('customer_usdt_top_up_work')->value('rail_invoice_id'));
        $this->assertSame(1, DB::table('usdt_bsc_rail_invoices')->count());
    }

    public function test_receiver_rotation_before_preparation_does_not_select_new_receiver(): void
    {
        $target = $this->action($this->preview()->intent->nonce)->topUp;
        $registry = app(UsdtDepositReceiverRegistry::class);
        $registry->retire($registry->findByAddress(56, BscUsdtRailRpcFixture::ADDRESS), $this->owner, 'Rotation');
        $registry->manualVerify($registry->register($this->customer, '0x'.str_repeat('e', 40), $this->owner)->receiver, true, $this->owner);
        app(UsdtTopUpPreparation::class)->prepare($target->id);
        $this->assertSame('creation_unknown', DB::table('customer_usdt_top_up_work')->value('state'));
        $this->assertSame(0, DB::table('usdt_bsc_rail_invoices')->count());
        $this->assertSame(BscUsdtRailRpcFixture::ADDRESS, $target->fresh()->payment_snapshot['receiver_address']);
    }

    public function test_production_funding_uses_configured_rail_without_environment_gate(): void
    {
        $this->app->instance('env', 'production');
        $this->assertSame('opened', $this->preview()->outcome);
        $this->assertSame([], $this->rpc->calls);
        $this->assertSame(1, CustomerUsdtFundingIntent::query()->count());
    }

    public function test_fee_addition_is_exact_and_overflow_is_rejected(): void
    {
        CustomerUsdtFundingPolicy::current()->update(['application_fee_micro' => 2]);
        $opened = $this->preview();
        $this->assertSame(1_000_003, $opened->intent->payable_micro);
        $this->assertSame('unavailable', app(UsdtFundingIntentService::class)->open($this->customer, '951001', $this->root->id, 100, UsdtMicroAmount::fromUnits(PHP_INT_MAX))->outcome);
        $this->assertSame('invalid_amount', app(TelegramUsdtFundingActions::class)->preview('951001', '951001', 'private', 100, '0.0000001')->outcome);
    }

    public function test_work_persistence_failure_rolls_back_confirmation_target_and_reservation(): void
    {
        $intent = $this->preview()->intent;
        DB::unprepared("CREATE TRIGGER reject_usdt_work BEFORE INSERT ON customer_usdt_top_up_work BEGIN SELECT RAISE(ABORT, 'work persistence failed'); END;");
        try {
            $this->action($intent->nonce);
            $this->fail('Confirmation cannot commit without durable work.');
        } catch (QueryException) {
            $this->assertSame('pending', $intent->fresh()->state);
            $this->assertSame(0, CustomerUsdtTopUp::query()->count());
            $this->assertSame(0, DB::table('customer_usdt_funding_reservations')->count());
            $this->assertSame([], $this->rpc->calls);
        }
    }

    public function test_receiver_retirement_before_confirmation_requires_new_preview(): void
    {
        $intent = $this->preview()->intent;
        $registry = app(UsdtDepositReceiverRegistry::class);
        $registry->retire($registry->findByAddress(56, BscUsdtRailRpcFixture::ADDRESS), $this->owner, 'Rotation');
        $this->assertSame('stale_terms', $this->action($intent->nonce)->outcome);
        $this->assertSame(0, CustomerUsdtTopUp::query()->count());
        $this->assertSame(0, DB::table('customer_usdt_funding_reservations')->count());
    }

    public function test_lost_lease_result_cannot_overwrite_new_owner_and_expired_lease_recovers_same_invoice(): void
    {
        $target = $this->action($this->preview()->intent->nonce)->topUp;
        $this->rpc->onCall = function () use ($target): void {
            $this->rpc->onCall = null;
            DB::table('customer_usdt_top_up_work')->where('top_up_id', $target->id)->update([
                'lease_token' => str_repeat('e', 32), 'version' => 10,
            ]);
        };
        app(UsdtTopUpPreparation::class)->prepare($target->id);
        $this->assertSame('creating', DB::table('customer_usdt_top_up_work')->value('state'));
        $this->assertSame(10, DB::table('customer_usdt_top_up_work')->value('version'));
        $this->travel(3)->minutes();
        $this->artisan('usdt-funding:recover')->assertSuccessful();
        $this->assertSame('ready', DB::table('customer_usdt_top_up_work')->value('state'));
        $this->assertSame(1, DB::table('usdt_bsc_rail_invoices')->count());
    }

    protected function preview(): UsdtFundingIntentResult
    {
        return app(TelegramUsdtFundingActions::class)->preview('951001', '951001', 'private', 100, '1.000001');
    }

    protected function action(string $nonce, string $action = 'confirm'): UsdtFundingIntentResult
    {
        return app(TelegramUsdtFundingActions::class)->callback('951001', '951001', 'private', 100, 'usdt:'.$action.':'.$nonce);
    }

    protected function assertNoMoneyOrOutbound(): void
    {
        foreach (['payments', 'customer_usdt_balance_entries', 'store_balance_entries', 'external_money_receipts', 'telegram_outbound_effects'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }
}
