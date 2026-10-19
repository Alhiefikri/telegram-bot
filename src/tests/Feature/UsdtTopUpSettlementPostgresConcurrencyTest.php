<?php

namespace Tests\Feature;

use App\Enums\BalancePaymentPreference;
use App\Enums\CustomerUsdtFundingRail;
use App\Enums\PaymentStatus;
use App\Enums\TelegramNavigationMediaKind;
use App\Enums\TelegramNavigationScreen;
use App\Enums\UserRole;
use App\Exceptions\CustomerUsdtFundingReservationConflict;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\CustomerUsdtBalanceEntry;
use App\Models\CustomerUsdtFundingPolicy;
use App\Models\CustomerUsdtTopUp;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\TelegramNavigationRoot;
use App\Models\User;
use App\Services\Binance\BinancePaymentVerification;
use App\Services\Bsc\BscVerificationRpcClientInterface;
use App\Services\Checkout\BalanceCheckoutRequest;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\Telegram\TelegramUsdtFundingActions;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\UsdtReceiptReconciliation;
use App\Services\UsdtBalance\VerifiedUsdtFunding;
use App\Services\UsdtFunding\CustomerUsdtFundingRelease;
use App\Services\UsdtFunding\CustomerUsdtFundingReservationService;
use App\Services\UsdtReceivers\UsdtDepositReceiverRegistry;
use App\Services\UsdtTopUps\BinanceUsdtTopUpSettlement;
use App\Services\UsdtTopUps\UsdtTopUpPreparation;
use App\Services\UsdtTopUps\UsdtTopUpSettlement;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
        $this->target = $actions->openConfirmedIntent('951001', '951001', 'private', 100, '1')->topUp;
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

    public function test_binance_transaction_and_order_aliases_race_across_customers_and_checkout_once(): void
    {
        $this->configureBinance();
        $targets = [$this->binanceTarget(951101), $this->binanceTarget(951102)];
        $checkoutCustomer = Customer::factory()->create(['telegram_id' => 951103, 'locale' => 'en']);
        $cart = $this->fundingRaceCart($checkoutCustomer, 300_000);
        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::binancePay(), 'binance-receipt-alias-race');
        $payment = $order->payments()->sole();
        $payment->update(['binance_order_id' => '445011487581044736', 'binance_order_id_submission_version' => 1,
            'binance_order_id_attempts' => 1, 'binance_verification_status' => 'checking']);
        foreach ($targets as $index => $target) {
            app(BinanceUsdtTopUpSettlement::class)->submit(Customer::findOrFail($target->customer_id), $target->id,
                $index === 0 ? '445011487581044736' : '445011487581044737');
        }
        $transaction = $this->binanceTransaction();
        $results = $this->runConcurrentWorkers(3, function (int $index) use ($targets, $payment, $transaction): string {
            $payload = $index === 1
                ? array_replace($transaction, ['transactionId' => $transaction['orderId'], 'orderId' => $transaction['transactionId']])
                : $transaction;
            $this->fakeBinanceHistory($payload);
            if ($index === 2) {
                return app(BinancePaymentVerification::class)->verify(Payment::findOrFail($payment->id))->outcome->value;
            }
            app(BinanceUsdtTopUpSettlement::class)->check($targets[$index]->id);

            return 'checked';
        }, 'binance-alias-settlement-race');
        $this->assertWorkersSucceeded($results);
        $credits = DB::table('customer_usdt_balance_entries')->whereIn('source_id', array_map(fn ($target) => $target->id, $targets))->count();
        $paidCheckout = $payment->fresh()->status === PaymentStatus::Paid ? 1 : 0;
        $this->assertSame(1, $credits + $paidCheckout);
        $this->assertSame(1, DB::table('external_money_receipts')->count());
        $this->assertSame($credits, DB::table('customer_usdt_top_up_resolutions')->count());
        $this->assertSame($credits * 300_000, (int) DB::table('customer_usdt_balance_entries')->sum('amount_micro'));
        foreach ($targets as $target) {
            $settled = DB::table('customer_usdt_top_up_resolutions')->where('top_up_id', $target->id)->exists();
            $this->assertSame($settled ? 'settled' : 'manual_review',
                DB::table('customer_usdt_top_up_settlement_work')->where('top_up_id', $target->id)->value('state'));
        }
    }

    public function test_binance_credit_racing_checkout_keeps_transaction_time_receipt_balances(): void
    {
        $this->configureBinance();
        CustomerUsdtFundingPolicy::current()->update(['application_fee_micro' => 100_000]);
        $target = $this->binanceTarget(951104);
        $customer = Customer::findOrFail($target->customer_id);
        $ledger = app(CustomerUsdtBalanceLedger::class);
        $ledger->creditVerifiedTopUp($customer, new VerifiedUsdtFunding($customer->id, UsdtMicroAmount::fromUnits(500_000),
            'binance-race-opening-funds', 'binance-race-opening-receipt', 'v1'), 'binance-race-opening-funds');
        $cart = $this->fundingRaceCart($customer, 200_000);
        app(BinanceUsdtTopUpSettlement::class)->submit($customer, $target->id, '445011487581044736');
        $results = $this->runConcurrentWorkers(2, function (int $index) use ($target, $cart): string {
            $this->fakeBinanceHistory($this->binanceTransaction());
            if ($index === 0) {
                app(BinanceUsdtTopUpSettlement::class)->check($target->id);

                return 'checked';
            }
            $order = app(OrderCheckoutService::class)->checkout(Cart::findOrFail($cart->id),
                checkoutKey: 'binance-credit-spend-race',
                balanceRequest: new BalanceCheckoutRequest(BalancePaymentPreference::UsdFirst, 10_000, 200_000));
            Http::assertNothingSent();

            return (string) $order->id;
        }, 'binance-credit-spend-race');
        $this->assertWorkersSucceeded($results);
        $this->assertSame(600_000, $ledger->snapshot($customer)->balanceMicro());
        $accepted = Order::findOrFail($results[1]['value']);
        $this->assertSame('USDT', $accepted->currency);
        $this->assertSame('usd_first', $accepted->balance_checkout_snapshot['preference']);
        $this->assertSame(1, ProductStockItem::where('order_item_id', $accepted->orderItems()->sole()->id)->count());
        $this->assertSame(0, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        $resolution = DB::table('customer_usdt_top_up_resolutions')->where('top_up_id', $target->id)->sole();
        $entry = CustomerUsdtBalanceEntry::findOrFail($resolution->entry_id);
        $this->assertSame(300_000, $entry->amount_micro);
        $this->assertContains((int) $entry->balance_after_micro, [600_000, 800_000]);
        $effect = DB::table('telegram_outbound_effects')->where('source_id', $target->id)->sole();
        $metadata = json_decode($effect->outcome_metadata, true, flags: JSON_THROW_ON_ERROR);
        $before = (int) $entry->balance_after_micro - (int) $entry->amount_micro;
        $this->assertStringContainsString('Balance before credit: '.UsdtMicroAmount::formatUnits($before).' USD', $metadata['message']);
        $this->assertStringContainsString('Balance after credit: '.UsdtMicroAmount::formatUnits((int) $entry->balance_after_micro).' USD', $metadata['message']);

        $laterCart = $this->fundingRaceCart($customer, 100_000);
        app(OrderCheckoutService::class)->checkout($laterCart, CheckoutPaymentIntents::usdtBalance(), 'binance-after-credit-spend');
        $this->assertSame(500_000, $ledger->snapshot($customer)->balanceMicro());
        $this->assertNotSame($ledger->snapshot($customer)->balanceMicro(), (int) $entry->balance_after_micro);
        app(BinanceUsdtTopUpSettlement::class)->submit($customer, $target->id, '445011487581044736');
        $this->assertFalse(app(BinanceUsdtTopUpSettlement::class)->check($target->id));
        $this->assertSame($effect->outcome_metadata, DB::table('telegram_outbound_effects')->where('id', $effect->id)->value('outcome_metadata'));
        $this->assertSame(1, DB::table('customer_usdt_top_up_resolutions')->where('top_up_id', $target->id)->count());
        $this->assertSame(0, DB::table('customer_usdt_top_up_fee_events')->count());
    }

    private function configureBinance(): void
    {
        Http::preventStrayRequests();
        config(['services.binance.api_key' => 'fixture-key', 'services.binance.api_secret' => 'fixture-secret',
            'services.binance.pay_id' => '12345678', 'services.binance.pay_uid' => '', 'services.binance.history_cache_seconds' => 0]);
        ManualPaymentSetting::current()->update(['binance_pay_enabled' => true, 'binance_verification_mode' => 'order_id']);
    }

    private function binanceTarget(int $telegramId): CustomerUsdtTopUp
    {
        $customer = Customer::factory()->create(['telegram_id' => $telegramId, 'locale' => 'en']);
        TelegramNavigationRoot::create(['customer_id' => $customer->id, 'chat_id' => (string) $telegramId,
            'message_id' => 100, 'media_kind' => 'text', 'screen' => 'home', 'locale' => 'en', 'generation' => 1]);
        $actions = app(TelegramUsdtFundingActions::class);
        $result = $actions->openConfirmedIntent((string) $telegramId, (string) $telegramId, 'private', 100, '0.30', CustomerUsdtFundingRail::BinancePay);
        $this->assertSame('confirmed', $result->outcome);

        return $result->topUp;
    }

    private function fundingRaceCart(Customer $customer, int $priceMicro): Cart
    {
        $product = Product::factory()->create(['price_amount' => 10_000, 'usdt_price_amount' => $priceMicro, 'stock' => 1, 'reserved_stock' => 0]);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'binance-race-fixture-secret']);
        $cart = Cart::factory()->create(['customer_id' => $customer->id, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1]);

        return $cart;
    }

    private function binanceTransaction(): array
    {
        return ['orderId' => '445011487581044736', 'transactionId' => '445011487581044737',
            'amount' => '0.30', 'currency' => 'USDT', 'transactionTime' => now()->getTimestampMs(),
            'status' => 'SUCCESS', 'receiverInfo' => ['accountId' => '12345678']];
    }

    private function fakeBinanceHistory(array $transaction): void
    {
        Http::fake(['*sapi/v1/pay/transactions*' => function () use ($transaction) {
            $this->assertSame(0, DB::transactionLevel());

            return Http::response(['code' => '000000', 'success' => true, 'data' => [$transaction]]);
        }]);
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
