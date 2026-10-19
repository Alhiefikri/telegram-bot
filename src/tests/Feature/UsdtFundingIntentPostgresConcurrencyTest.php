<?php

namespace Tests\Feature;

use App\Enums\TelegramNavigationMediaKind;
use App\Enums\TelegramNavigationScreen;
use App\Enums\UserRole;
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
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RunsPostgresWorkers;
use Tests\Support\BscUsdtRailRpcFixture;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
final class UsdtFundingIntentPostgresConcurrencyTest extends TestCase
{
    use RunsPostgresWorkers;

    private bool $ownsDatabase = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requirePostgresConcurrency('U6 confirmation');
        $this->ownsDatabase = true;
        // Entire database is disposable, explicitly checked by the harness.
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
        $customer = Customer::factory()->create(['telegram_id' => 951001]);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        TelegramNavigationRoot::query()->create([
            'customer_id' => $customer->id, 'chat_id' => '951001', 'message_id' => 100,
            'media_kind' => TelegramNavigationMediaKind::Text, 'screen' => TelegramNavigationScreen::Home, 'locale' => 'id', 'generation' => 1,
        ]);
        ManualPaymentSetting::create(['is_active' => true, 'payment_mode' => 'manual', 'bsc_min_confirmations' => 3, 'bsc_payment_ttl_minutes' => 15]);
        CustomerUsdtFundingPolicy::current()->update(['funding_enabled' => true, 'version' => 1]);
        $registry = app(UsdtDepositReceiverRegistry::class);
        $registry->manualVerify($registry->register($customer, BscUsdtRailRpcFixture::ADDRESS, $owner)->receiver, true, $owner);
        $this->app->instance(BscVerificationRpcClientInterface::class, new BscUsdtRailRpcFixture);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->ownsDatabase) {
                // Do not leak fork-committed immutable history into other lanes.
                Artisan::call('migrate:fresh', ['--force' => true]);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_three_amount_choices_commit_one_target_reservation_and_durable_obligation(): void
    {
        $results = $this->runConcurrentWorkers(3, fn (): string => app(TelegramUsdtFundingActions::class)
            ->openConfirmedIntent('951001', '951001', 'private', 100, '1')->topUp->id);
        $this->assertWorkersSucceeded($results);
        $id = CustomerUsdtTopUp::query()->sole()->id;
        $this->assertSame([$id, $id, $id], array_column($results, 'value'));
        $this->assertSame(1, DB::table('customer_usdt_funding_reservations')->count());
        $this->assertSame(1, DB::table('customer_usdt_top_up_work')->count());
        $this->assertSame(0, DB::table('customer_usdt_funding_intents')->where('state', 'pending')->count());
        $this->assertSame(0, DB::table('usdt_bsc_rail_invoices')->count());
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
    }

    public function test_competing_amount_choices_leave_one_confirmed_target(): void
    {
        $results = $this->runConcurrentWorkers(2, fn (int $index): string => app(TelegramUsdtFundingActions::class)
            ->openConfirmedIntent('951001', '951001', 'private', 100, (string) ($index + 1))->outcome);
        $this->assertWorkersSucceeded($results);
        $this->assertContains('confirmed', array_column($results, 'value'));
        $this->assertSame(1, CustomerUsdtTopUp::query()->count());
        $target = CustomerUsdtTopUp::query()->sole();
        $this->assertContains($target->amount_micro, [1_000_000, 2_000_000]);
        $this->assertSame(1, DB::table('customer_usdt_funding_reservations')->whereNull('resolved_at')->count());
        $this->assertSame(0, DB::table('customer_usdt_funding_intents')->where('state', 'pending')->count());
    }

    public function test_parallel_recovery_prepares_one_invoice_and_immutable_database_snapshot_rejects_mutation(): void
    {
        $adapter = app(TelegramUsdtFundingActions::class);
        $target = $adapter->openConfirmedIntent('951001', '951001', 'private', 100, '1')->topUp;
        $results = $this->runConcurrentWorkers(3, fn (): string => app(UsdtTopUpPreparation::class)->prepare($target->id) ? 'prepared' : 'owned');
        $this->assertWorkersSucceeded($results);
        $this->assertSame(1, count(array_filter(array_column($results, 'value'), fn ($value): bool => $value === 'prepared')));
        $this->assertSame(1, DB::table('usdt_bsc_rail_invoices')->count());
        $this->assertSame('ready', DB::table('customer_usdt_top_up_work')->value('state'));
        foreach ([fn () => DB::table('customer_usdt_top_ups')->where('id', $target->id)->update(['amount_micro' => 2]),
            fn () => DB::table('customer_usdt_top_ups')->where('id', $target->id)->delete(),
            fn () => DB::table('customer_usdt_funding_intents')->update(['state' => 'pending', 'version' => 3])] as $mutation) {
            try {
                $mutation();
                $this->fail('Immutable funding facts must reject mutation.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('immutable', $exception->getMessage());
            }
        }
        $this->assertSame(1_000_000, $target->fresh()->amount_micro);
    }
}
