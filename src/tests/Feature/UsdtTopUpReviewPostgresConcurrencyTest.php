<?php

namespace Tests\Feature;

use App\Enums\TelegramNavigationMediaKind;
use App\Enums\TelegramNavigationScreen;
use App\Enums\UserRole;
use App\Exceptions\BalanceAdjustmentRejected;
use App\Models\Customer;
use App\Models\CustomerUsdtFundingPolicy;
use App\Models\CustomerUsdtTopUp;
use App\Models\ManualPaymentSetting;
use App\Models\TelegramNavigationRoot;
use App\Models\User;
use App\Services\Bsc\BscVerificationRpcClientInterface;
use App\Services\Telegram\TelegramUsdtFundingActions;
use App\Services\UsdtReceivers\UsdtDepositReceiverRegistry;
use App\Services\UsdtTopUps\UsdtTopUpPreparation;
use App\Services\UsdtTopUps\UsdtTopUpReview;
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
final class UsdtTopUpReviewPostgresConcurrencyTest extends TestCase
{
    use RunsPostgresWorkers;

    private bool $ownsDatabase = false;

    private User $owner;

    private Customer $customer;

    private CustomerUsdtTopUp $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requirePostgresConcurrency('U8 review');
        $this->ownsDatabase = true;
        Artisan::call('migrate:fresh', ['--force' => true]);
        Queue::fake();
        $this->customer = Customer::factory()->create(['telegram_id' => 951001]);
        $owner = User::factory()->create(['role' => UserRole::Owner, 'password' => 'owner-password']);
        $this->owner = $owner;
        $this->actingAs($owner);
        $session = app('session')->driver();
        $session->start();
        request()->setLaravelSession($session);
        config(['services.telegram.admin_telegram_chat_id' => '998877']);
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
        $this->target = $actions->openConfirmedIntent('951001', '951001', 'private', 100, '1')->topUp;
        app(UsdtTopUpPreparation::class)->prepare($this->target->id);
        $rpc->timestamp = now()->timestamp + 5;
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

    public function test_review_compensation_racing_auto_settlement_preserves_one_economic_resolution(): void
    {
        $review = app(UsdtTopUpReview::class);
        $preview = $review->request($this->owner, $this->target->id, 'resolved_by_compensation', '1',
            BscUsdtRailRpcFixture::TX, 'Verified deposit race');
        $results = $this->runConcurrentWorkers(2, function (int $index) use ($preview): string {
            if ($index === 0) {
                app(UsdtTopUpSettlement::class)->recover();

                return 'checked';
            }
            try {
                app(UsdtTopUpReview::class)->confirm($this->owner,
                    $preview->id, $preview->fingerprint, 1, 'owner-password');

                return 'confirmed';
            } catch (\DomainException) {
                return 'fenced';
            }
        });
        $this->assertWorkersSucceeded($results);
        $this->assertSame(1, DB::table('customer_usdt_top_up_resolutions')->count());
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame(1_000_000, (int) DB::table('customer_usdt_balance_accounts')->value('balance_micro'));
        $this->assertDatabaseConstraintRejects(fn () => DB::table('usdt_top_up_review_requests')->update(['amount_micro' => 2_000_000]), 'immutable');
    }

    public function test_two_owner_confirmations_race_to_one_request_bound_credit(): void
    {
        $review = app(UsdtTopUpReview::class);
        $preview = $review->request($this->owner, $this->target->id, 'settled', '1',
            BscUsdtRailRpcFixture::TX, 'Concurrent Owner retry');
        $results = $this->runConcurrentWorkers(2, function () use ($preview): string {
            try {
                app(UsdtTopUpReview::class)->confirm($this->owner,
                    $preview->id, $preview->fingerprint, 1, 'owner-password');

                return 'confirmed';
            } catch (BalanceAdjustmentRejected|\DomainException) {
                return 'fenced';
            }
        });
        $this->assertWorkersSucceeded($results);
        $this->assertSame(1, DB::table('customer_usdt_top_up_resolutions')->count());
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame(1, DB::table('external_money_receipts')->count());
    }

    public function test_external_return_racing_auto_settlement_cannot_resolve_twice(): void
    {
        $service = app(UsdtTopUpReview::class);
        $destination = '0x'.str_repeat('7', 40);
        $authorization = $service->authorizeExternalReturn($this->customer, $this->target->id, '1', $destination, str_repeat('n', 32));
        $rpc = app(BscVerificationRpcClientInterface::class);
        $inbound = $rpc->receipt;
        $outbound = $inbound;
        $outbound['transactionHash'] = '0x'.str_repeat('c', 64);
        $outbound['logs'] = [$rpc->log(receiver: $destination)];
        $outbound['logs'][0]['transactionHash'] = $outbound['transactionHash'];
        $outbound['logs'][0]['topics'][1] = '0x'.str_repeat('0', 24).substr(BscUsdtRailRpcFixture::ADDRESS, 2);
        $calls = 0;
        $rpc->onCall = function (string $method) use ($rpc, $inbound, $outbound, &$calls): void {
            if ($method === 'transactionReceipt') {
                $rpc->receipt = (++$calls % 2) === 1 ? $inbound : $outbound;
            }
        };
        $preview = $service->request($this->owner, $this->target->id, 'externally_refunded', '1',
            BscUsdtRailRpcFixture::TX, 'Verified outgoing return race', $outbound['transactionHash'], $authorization->id);
        $results = $this->runConcurrentWorkers(2, function (int $index) use ($preview): string {
            if ($index === 0) {
                app(UsdtTopUpSettlement::class)->recover();

                return 'checked';
            }
            try {
                app(UsdtTopUpReview::class)->confirm($this->owner,
                    $preview->id, $preview->fingerprint, 1, 'owner-password');

                return 'confirmed';
            } catch (\DomainException) {
                return 'fenced';
            }
        });
        $this->assertWorkersSucceeded($results);
        $resolution = DB::table('customer_usdt_top_up_resolutions')->sole();
        $this->assertContains($resolution->kind, ['settled', 'externally_refunded']);
        $this->assertSame($resolution->kind === 'settled' ? 1 : 0, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame(1, DB::table('customer_usdt_top_up_resolutions')->count());
        $this->assertNotNull(DB::table('customer_usdt_funding_reservations')->value('resolved_at'));
    }
}
