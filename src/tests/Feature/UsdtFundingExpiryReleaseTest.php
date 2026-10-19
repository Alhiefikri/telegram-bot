<?php

namespace Tests\Feature;

use App\Enums\CustomerUsdtFundingRail;
use App\Enums\TelegramNavigationMediaKind;
use App\Enums\TelegramNavigationScreen;
use App\Models\Customer;
use App\Models\CustomerUsdtFundingPolicy;
use App\Models\CustomerUsdtTopUp;
use App\Models\ManualPaymentSetting;
use App\Models\TelegramNavigationRoot;
use App\Models\User;
use App\Services\Telegram\TelegramUsdtBalanceIntents;
use App\Services\Telegram\TelegramUsdtFundingActions;
use App\Services\UsdtFunding\CustomerUsdtFundingReservationService;
use App\Services\UsdtTopUps\UsdtTopUpStatus;
use App\Services\UsdtTopUps\UsdtTopUpStatusReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The single active-funding slot must not stay occupied forever by a target
 * nobody paid. The sweep below only runs after the payment window plus grace,
 * never settles money, and every release lands in the immutable resolution log.
 */
final class UsdtFundingExpiryReleaseTest extends TestCase
{
    protected Customer $customer;

    protected TelegramNavigationRoot $root;

    protected CustomerUsdtTopUp $target;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-10-19 08:00:00 UTC'));
        Http::preventStrayRequests();
        Queue::fake();
        $this->customer = Customer::factory()->create(['telegram_id' => 951001, 'locale' => 'id']);
        User::factory()->create(['role' => 'owner']);
        $this->root = TelegramNavigationRoot::query()->create([
            'customer_id' => $this->customer->id, 'chat_id' => '951001', 'message_id' => 100,
            'media_kind' => TelegramNavigationMediaKind::Text, 'screen' => TelegramNavigationScreen::Home,
            'locale' => 'id', 'generation' => 1,
        ]);
        ManualPaymentSetting::create([
            'is_active' => true,
            'payment_mode' => 'manual',
            'bsc_min_confirmations' => 3,
            'bsc_payment_ttl_minutes' => 15,
            'binance_pay_enabled' => true,
        ]);
        CustomerUsdtFundingPolicy::current()->update(['funding_enabled' => true, 'version' => 1]);
        config([
            'services.binance.api_key' => 'fixture-key',
            'services.binance.api_secret' => 'fixture-secret',
            'services.binance.pay_id' => '12345678',
            'services.binance.pay_uid' => '',
        ]);
        $this->target = $this->openBinanceTarget('1.5');
    }

    public function test_unpaid_target_is_released_after_the_grace_and_frees_the_slot(): void
    {
        $this->expirePastGrace();

        $this->artisan('usdt-funding:release-expired', ['--grace-minutes' => 60, '--json' => true])
            ->assertSuccessful();

        $resolution = DB::table('customer_usdt_top_up_resolutions')->sole();
        $this->assertSame('expired_unpaid', $resolution->kind);
        $this->assertNull($resolution->entry_id, 'An expired unpaid resolution never carries a ledger entry.');

        $this->assertSame('released', DB::table('customer_usdt_funding_reservations')->value('state'));
        $this->assertSame('expired_unpaid', DB::table('customer_usdt_funding_reservations')->value('resolution_kind'));

        $this->assertNull(app(CustomerUsdtFundingReservationService::class)->activeForCustomer($this->customer));
        $this->assertSame(UsdtTopUpStatus::STATE_EXPIRED_UNPAID, app(UsdtTopUpStatusReader::class)->for($this->target)->state);
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame(0, DB::table('telegram_outbound_effects')->count());

        // The Customer can start a fresh target immediately.
        $fresh = $this->openBinanceTarget('3');
        $this->assertSame(2, CustomerUsdtTopUp::query()->count());
        $this->assertSame($fresh->id, CustomerUsdtTopUp::query()->where('id', '!=', $this->target->id)->sole()->id);
        $this->assertSame(1, DB::table('customer_usdt_funding_reservations')->whereNull('resolved_at')->count());
    }

    public function test_release_waits_for_the_grace_window(): void
    {
        $this->travel(21)->minutes();
        $this->artisan('usdt-funding:recover')->assertSuccessful();
        $this->assertSame('expired_unresolved', DB::table('customer_usdt_funding_reservations')->value('state'));

        // Deadline passed, but only nineteen minutes of the grace window elapsed.
        $this->travel(19)->minutes();
        $this->artisan('usdt-funding:release-expired', ['--grace-minutes' => 60, '--json' => true])
            ->assertSuccessful();

        $this->assertSame(0, DB::table('customer_usdt_top_up_resolutions')->count());
        $this->assertSame('expired_unresolved', DB::table('customer_usdt_funding_reservations')->value('state'));
        $this->assertSame(UsdtTopUpStatus::STATE_EXPIRED_UNRESOLVED, app(UsdtTopUpStatusReader::class)->for($this->target)->state);
    }

    public function test_release_skips_targets_with_an_economic_resolution(): void
    {
        $this->expirePastGrace();
        DB::table('customer_usdt_top_up_resolutions')->insert([
            'top_up_id' => (string) $this->target->id, 'kind' => 'rejected', 'receipt_id' => null,
            'entry_id' => null, 'evidence_id' => null, 'late' => false, 'created_at' => now(),
        ]);

        $this->artisan('usdt-funding:release-expired', ['--grace-minutes' => 60, '--json' => true])
            ->assertSuccessful();

        $this->assertSame('expired_unresolved', DB::table('customer_usdt_funding_reservations')->value('state'));
        $this->assertSame('rejected', DB::table('customer_usdt_top_up_resolutions')->value('kind'));
    }

    public function test_release_skips_targets_with_an_active_settlement_lease(): void
    {
        $this->expirePastGrace();
        DB::table('customer_usdt_top_up_settlement_work')->where('top_up_id', $this->target->id)
            ->update(['lease_until' => now()->addMinutes(5), 'version' => DB::raw('version + 1')]);

        $this->artisan('usdt-funding:release-expired', ['--grace-minutes' => 60, '--json' => true])
            ->assertSuccessful();

        $this->assertSame(0, DB::table('customer_usdt_top_up_resolutions')->count());
        $this->assertSame('expired_unresolved', DB::table('customer_usdt_funding_reservations')->value('state'));
    }

    public function test_status_surface_shows_expired_unpaid_without_a_credit_promise(): void
    {
        $this->expirePastGrace();
        $this->artisan('usdt-funding:release-expired', ['--grace-minutes' => 60, '--json' => true])->assertSuccessful();

        $surface = app(TelegramUsdtBalanceIntents::class)->statusSurface(
            $this->customer,
            $this->target->fresh(),
            app(UsdtTopUpStatusReader::class)->for($this->target),
        );
        $markup = json_encode($surface->keyboard, JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('Batas pembayaran lewat dan tidak ada pembayaran yang terdeteksi', $surface->caption);
        $this->assertStringContainsString('Kedaluwarsa tanpa pembayaran', $surface->caption);
        $this->assertStringNotContainsString('Kredit:', $surface->caption);
        $this->assertStringNotContainsString('usdt:check:', $markup);
        $this->assertStringNotContainsString('usdt:order:', $markup);
    }

    public function test_customer_cancel_releases_the_slot_before_the_deadline(): void
    {
        $actions = app(TelegramUsdtFundingActions::class);
        $this->assertSame(1, $actions->cancelOpenTargets($this->customer));

        $this->assertSame('released', DB::table('customer_usdt_funding_reservations')->value('state'));
        $this->assertSame('cancelled_by_customer', DB::table('customer_usdt_top_up_resolutions')->value('kind'));
        $this->assertSame('cancelled_by_customer', DB::table('customer_usdt_funding_reservations')->value('resolution_kind'));
        $this->assertNull(app(CustomerUsdtFundingReservationService::class)->activeForCustomer($this->customer));
        $this->assertSame(UsdtTopUpStatus::STATE_CANCELLED_BY_CUSTOMER, app(UsdtTopUpStatusReader::class)->for($this->target)->state);
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());

        // Replaying the same start-over is idempotent and creates no second target.
        $this->assertSame(0, $actions->cancelOpenTargets($this->customer));
        $this->assertSame(1, CustomerUsdtTopUp::query()->count());

        // The Customer can pick a different amount immediately.
        $fresh = $this->openBinanceTarget('5');
        $this->assertSame(5_000_000, (int) $fresh->amount_micro);
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
    }

    public function test_customer_cancel_is_rejected_while_a_worker_holds_a_lease(): void
    {
        DB::table('customer_usdt_top_up_work')->where('top_up_id', $this->target->id)
            ->update(['lease_until' => now()->addMinutes(2)]);

        $this->assertSame(0, app(TelegramUsdtFundingActions::class)->cancelOpenTargets($this->customer));
        $this->assertSame(0, DB::table('customer_usdt_top_up_resolutions')->count());
        $this->assertSame('reserved', DB::table('customer_usdt_funding_reservations')->value('state'));
    }

    private function expirePastGrace(): void
    {
        $this->travel(21)->minutes();
        $this->artisan('usdt-funding:recover')->assertSuccessful();
        $this->assertSame('expired_unresolved', DB::table('customer_usdt_funding_reservations')->value('state'));
        $this->travel(61)->minutes();
    }

    private function openBinanceTarget(string $amount): CustomerUsdtTopUp
    {
        $result = app(TelegramUsdtFundingActions::class)->openConfirmedIntent(
            '951001',
            '951001',
            'private',
            100,
            $amount,
            CustomerUsdtFundingRail::BinancePay,
        );
        $this->assertSame('confirmed', $result->outcome);

        return $result->topUp;
    }
}
