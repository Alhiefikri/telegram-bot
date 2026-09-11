<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Enums\KlikQrisEnvironment;
use App\Enums\PaymentStatus;
use App\Enums\StoreBalanceEntryKind;
use App\Enums\StoreBalanceFundingIntentStatus;
use App\Enums\StoreBalanceTopUpStatus;
use App\Enums\TelegramNavigationMediaKind;
use App\Enums\TelegramNavigationScreen;
use App\Enums\TelegramOutboundEffectType;
use App\Enums\UserRole;
use App\Exceptions\InsufficientStoreBalance;
use App\Exceptions\StoreBalanceFundingPolicyConflict;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\CustomerBalanceAccount;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\StoreBalanceCompensation;
use App\Models\StoreBalanceEntry;
use App\Models\StoreBalanceFundingIntent;
use App\Models\StoreBalanceFundingPolicy;
use App\Models\StoreBalanceTopUp;
use App\Models\StoreBalanceTopUpEvent;
use App\Models\TelegramNavigationRoot;
use App\Models\TelegramOutboundEffect;
use App\Models\User;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\KlikQris\KlikQrisCallback;
use App\Services\KlikQris\KlikQrisTransaction;
use App\Services\KlikQris\StoreBalanceTopUpEventLogger;
use App\Services\OrderCheckoutService;
use App\Services\OrderStateMachine;
use App\Services\StoreBalance\StoreBalanceActivityService;
use App\Services\StoreBalance\StoreBalanceFundingIntentInput;
use App\Services\StoreBalance\StoreBalanceFundingIntentService;
use App\Services\StoreBalance\StoreBalanceFundingPolicyManager;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\StoreBalance\StoreBalanceOwnerCompensationService;
use App\Services\StoreBalance\StoreBalanceReconciliation;
use App\Services\StoreBalance\StoreBalanceTopUpService;
use App\Services\Telegram\StoreBalanceTopUpOutbound;
use App\Support\PaymentProvider;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;
use Throwable;

#[Group('concurrency')]
#[Group('postgres-release')]
final class StoreBalancePostgresConcurrencyTest extends TestCase
{
    /** @var list<int> */
    private array $createdCustomerIds = [];

    /** @var list<int> */
    private array $createdProductIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 123456]]),
        ]);

        if (DB::getDriverName() !== 'pgsql' || (string) env('RUN_CONCURRENCY_TESTS', '') !== '1') {
            return;
        }

        $this->assertSafeConcurrencyDatabase();
        if (! Schema::hasTable('store_balance_entries')) {
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        }
    }

    protected function tearDown(): void
    {
        $this->cleanupFixtures();

        parent::tearDown();
    }

    private function cleanupFixtures(): void
    {
        if ($this->createdCustomerIds === [] || ! Schema::hasTable('store_balance_entries')) {
            return;
        }

        $orderIds = Order::query()
            ->whereIn('customer_id', $this->createdCustomerIds)
            ->pluck('id')
            ->all();

        if ($orderIds !== []) {
            $paymentIds = Payment::query()
                ->whereIn('order_id', $orderIds)
                ->pluck('id')
                ->all();

            if ($paymentIds !== []) {
                foreach ([
                    'bsc_payment_transfer_evidence',
                    'payment_bsc_details',
                    'payment_events',
                    'manual_fulfillment_reviews',
                    'provider_cancellation_attempts',
                ] as $table) {
                    DB::table($table)->whereIn('payment_id', $paymentIds)->delete();
                }
            }

            DB::table('order_notification_events')->whereIn('order_id', $orderIds)->delete();
            DB::table('sales_proof_posts')->whereIn('order_id', $orderIds)->delete();

            $movementIds = DB::table('inventory_movements')
                ->whereIn('order_id', $orderIds)
                ->pluck('id')
                ->all();

            if ($movementIds !== []) {
                DB::table('product_stock_intake_operations')->whereIn('inventory_movement_id', $movementIds)->delete();
                DB::table('product_waitlist_events')->whereIn('inventory_movement_id', $movementIds)->delete();
                DB::table('stock_notification_events')->whereIn('inventory_movement_id', $movementIds)->delete();
                DB::table('inventory_movements')->whereIn('id', $movementIds)->delete();
            }

            DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
            if ($paymentIds !== []) {
                DB::table('payments')->whereIn('id', $paymentIds)->delete();
            }
            DB::table('orders')->whereIn('id', $orderIds)->delete();
        }

        $cartIds = DB::table('carts')
            ->whereIn('customer_id', $this->createdCustomerIds)
            ->pluck('id')
            ->all();

        if ($cartIds !== []) {
            DB::table('cart_items')->whereIn('cart_id', $cartIds)->delete();
        }
        DB::table('carts')->whereIn('customer_id', $this->createdCustomerIds)->delete();
        DB::table('store_balance_compensations')
            ->whereIn('customer_id', $this->createdCustomerIds)
            ->delete();
        Customer::query()->whereKey($this->createdCustomerIds)->delete();

        if ($this->createdProductIds !== [] && Schema::hasTable('products')) {
            DB::table('product_stock_items')->whereIn('product_id', $this->createdProductIds)->delete();
            Product::query()->whereKey($this->createdProductIds)->delete();
        }
    }

    public function test_postgres_competing_policy_updates_allow_one_stale_version_winner(): void
    {
        $this->requirePostgresConcurrency();

        $owners = User::factory()->count(2)->create(['role' => UserRole::Owner]);
        $ownerIds = $owners->modelKeys();

        $results = $this->runConcurrentWorkers(2, function (int $index) use ($ownerIds): string {
            try {
                app(StoreBalanceFundingPolicyManager::class)->change(
                    minimumAmount: '5000',
                    presetAmounts: $index === 0 ? [5000, 10000] : [5000, 25000],
                    expectedVersion: 0,
                    actor: User::query()->findOrFail($ownerIds[$index]),
                    ip: '198.51.100.'.($index + 10),
                );

                return 'Applied';
            } catch (StoreBalanceFundingPolicyConflict) {
                return 'Conflict';
            }
        });

        $this->assertWorkersSucceeded($results);
        $outcomes = array_map(static fn (array $result): string => (string) ($result['value'] ?? ''), $results);
        sort($outcomes);
        $this->assertSame(['Applied', 'Conflict'], $outcomes, json_encode($results, JSON_THROW_ON_ERROR));

        $policy = StoreBalanceFundingPolicy::query()->sole();
        $this->assertSame(1, $policy->policyVersion());
        $this->assertContains($policy->presetAmounts(), [[5000, 10000], [5000, 25000]]);
        $this->assertSame(1, AuditLog::query()->where('action', 'store_balance.funding_policy.updated')->count());
    }

    public function test_postgres_competing_deposit_submissions_reuse_one_pending_intent(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(45470);
        $root = $this->createFundingRoot($customer, 7700);

        $results = $this->runConcurrentWorkers(2, function () use ($customer, $root): string {
            $opening = app(StoreBalanceFundingIntentService::class)->open(
                Customer::query()->findOrFail($customer->id),
                (string) $customer->telegram_id,
                $root->id,
                $root->message_id,
                StoreBalanceFundingIntentInput::custom('25000'),
            );

            return $opening->intent?->nonce ?? (string) $opening->rejection;
        });

        $this->assertWorkersSucceeded($results);
        $this->assertSame(
            $results[0]['value'] ?? null,
            $results[1]['value'] ?? null,
        );
        $this->assertSame(1, StoreBalanceFundingIntent::query()->count());
        $this->assertSame(StoreBalanceFundingIntentStatus::Pending, StoreBalanceFundingIntent::query()->sole()->status);
    }

    public function test_postgres_competing_confirmation_claims_have_one_winner_and_one_replay(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(45471);
        $root = $this->createFundingRoot($customer, 7701);
        $opening = app(StoreBalanceFundingIntentService::class)->open(
            $customer,
            (string) $customer->telegram_id,
            $root->id,
            $root->message_id,
            StoreBalanceFundingIntentInput::custom('25000'),
        );
        $this->assertTrue($opening->accepted());
        $nonce = $opening->intent->nonce;

        $results = $this->runConcurrentWorkers(2, function () use ($customer, $root, $nonce): string {
            return app(StoreBalanceFundingIntentService::class)->claim(
                Customer::query()->findOrFail($customer->id),
                (string) $customer->telegram_id,
                $root->id,
                $root->message_id,
                $nonce,
                'confirm',
            )->outcome->value;
        });

        $this->assertWorkersSucceeded($results);
        $outcomes = array_map(static fn (array $result): string => (string) ($result['value'] ?? ''), $results);
        sort($outcomes);
        $this->assertSame(['already_confirmed', 'confirmed'], $outcomes);
        $this->assertSame(StoreBalanceFundingIntentStatus::Confirmed, StoreBalanceFundingIntent::query()->sole()->status);
    }

    private function createFundingRoot(Customer $customer, int $messageId): TelegramNavigationRoot
    {
        return TelegramNavigationRoot::query()->create([
            'customer_id' => $customer->id,
            'chat_id' => (string) $customer->telegram_id,
            'message_id' => $messageId,
            'media_kind' => TelegramNavigationMediaKind::Photo,
            'screen' => TelegramNavigationScreen::AddBalance,
            'locale' => $customer->locale,
            'generation' => 1,
        ]);
    }

    public function test_postgres_workers_settle_one_idempotency_claim_exactly_once(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(45401);

        $results = $this->runConcurrentWorkers(2, function () use ($customer): string {
            $result = app(StoreBalanceLedger::class)->credit(
                Customer::query()->findOrFail($customer->id),
                25000,
                StoreBalanceEntryKind::TopUpCredit,
                'balance_top_up',
                '7001',
                'balance_top_up:7001:credit',
            );

            return $result->applied ? 'Applied' : 'Replayed';
        });

        $this->assertWorkersSucceeded($results);
        $outcomes = array_map(static fn (array $result): string => (string) ($result['value'] ?? ''), $results);
        sort($outcomes);
        $this->assertSame(['Applied', 'Replayed'], $outcomes, json_encode($results, JSON_THROW_ON_ERROR));

        $this->assertSame(1, StoreBalanceEntry::query()->count());
        $this->assertSame(25000, $this->balanceOf($customer));
        $this->assertProjectionMatchesLedger($customer, 25000);
    }

    public function test_postgres_duplicate_top_up_callbacks_credit_once_and_replay_the_event(): void
    {
        $this->requirePostgresConcurrency();

        $topUp = $this->createSettlementTopUp(45430, 'callback-race');
        $callbackPayload = $this->topUpCallbackPayload($topUp);
        $statusPayload = $this->topUpStatusPayload($topUp);

        $results = $this->runConcurrentWorkers(2, function () use ($topUp, $callbackPayload, $statusPayload): string {
            $callback = KlikQrisCallback::fromPayload($callbackPayload);
            $transaction = KlikQrisTransaction::fromStatusPayload($statusPayload, KlikQrisEnvironment::Sandbox);
            $event = app(StoreBalanceTopUpService::class)->settleAuthoritativeTransaction(
                StoreBalanceTopUp::query()->findOrFail($topUp->id),
                $transaction,
                fn (StoreBalanceTopUp $lockedTopUp): StoreBalanceTopUpEvent => app(StoreBalanceTopUpEventLogger::class)->logCallback($callback, $lockedTopUp),
            );

            return $event->processed_at === null ? 'unprocessed' : 'processed';
        });

        $this->assertWorkersSucceeded($results);
        $this->assertSame(['processed', 'processed'], array_values(array_map(
            static fn (array $result): string => (string) ($result['value'] ?? ''),
            $results,
        )));
        $this->assertSame(StoreBalanceTopUpStatus::Paid, $topUp->fresh()->status);
        $this->assertSame(1, StoreBalanceEntry::query()->count());
        $this->assertSame(1, StoreBalanceTopUpEvent::query()->where('event_type', 'callback')->count());
        $this->assertSame(2, (int) StoreBalanceTopUpEvent::query()->where('event_type', 'callback')->value('received_count'));
        $this->assertSame(8000, StoreBalanceEntry::query()->sole()->amount);
        $this->assertProjectionMatchesLedger($topUp->customer, 8000);
    }

    public function test_postgres_callback_and_reconciliation_race_share_one_credit_projection(): void
    {
        $this->requirePostgresConcurrency();

        $topUp = $this->createSettlementTopUp(45431, 'callback-reconciliation-race');
        $callbackPayload = $this->topUpCallbackPayload($topUp);
        $statusPayload = $this->topUpStatusPayload($topUp);

        $results = $this->runConcurrentWorkers(2, function (int $index) use ($topUp, $callbackPayload, $statusPayload): string {
            $callback = KlikQrisCallback::fromPayload($callbackPayload);
            $transaction = KlikQrisTransaction::fromStatusPayload($statusPayload, KlikQrisEnvironment::Sandbox);
            $event = app(StoreBalanceTopUpService::class)->settleAuthoritativeTransaction(
                StoreBalanceTopUp::query()->findOrFail($topUp->id),
                $transaction,
                function (StoreBalanceTopUp $lockedTopUp) use ($callback, $transaction, $index): StoreBalanceTopUpEvent {
                    $logger = app(StoreBalanceTopUpEventLogger::class);

                    return $index === 0
                        ? $logger->logCallback($callback, $lockedTopUp)
                        : $logger->logReconciliation($transaction, $lockedTopUp);
                },
            );

            return $event->processed_at === null ? 'unprocessed' : 'processed';
        });

        $this->assertWorkersSucceeded($results);
        $this->assertSame(['processed', 'processed'], array_values(array_map(
            static fn (array $result): string => (string) ($result['value'] ?? ''),
            $results,
        )));
        $this->assertSame(StoreBalanceTopUpStatus::Paid, $topUp->fresh()->status);
        $this->assertSame(1, StoreBalanceEntry::query()->count());

        $this->assertSame(1, StoreBalanceTopUpEvent::query()->where('event_type', 'callback')->count());
        $this->assertSame(1, StoreBalanceTopUpEvent::query()->where('event_type', 'reconciliation')->count());
        $this->assertSame(8000, StoreBalanceEntry::query()->sole()->amount);
        $this->assertProjectionMatchesLedger($topUp->customer, 8000);
    }

    public function test_postgres_concurrent_store_balance_outbound_discovery_reuses_one_effect_per_family(): void
    {
        $this->requirePostgresConcurrency();

        $instructionTopUp = $this->createSettlementTopUp(45434, 'outbound-instruction-race');
        $instructionTopUp->forceFill([
            'chat_id' => (string) $instructionTopUp->customer->telegram_id,
        ])->save();
        $receiptTopUp = $this->createSettlementTopUp(45435, 'outbound-receipt-race');
        $receiptTopUp->forceFill([
            'chat_id' => (string) $receiptTopUp->customer->telegram_id,
            'status' => StoreBalanceTopUpStatus::Paid,
            'paid_at' => now()->subMinute(),
            'settled_at' => now()->subMinute(),
        ])->save();
        Queue::fake();

        $results = $this->runConcurrentWorkers(2, function (): string {
            $outbound = app(StoreBalanceTopUpOutbound::class);

            return (string) ($outbound->recoverMissingInstructions(1) + $outbound->recoverMissingReceipts(1));
        });

        $this->assertWorkersSucceeded($results);
        $outcomes = array_map(static fn (array $result): int => (int) ($result['value'] ?? -1), $results);
        $this->assertGreaterThan(0, max($outcomes), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertLessThanOrEqual(2, max($outcomes), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, TelegramOutboundEffect::query()
            ->where('source_id', (string) $instructionTopUp->getKey())
            ->where('effect_type', TelegramOutboundEffectType::BalanceTopUpInstructions->value)
            ->count());
        $this->assertSame(1, TelegramOutboundEffect::query()
            ->where('source_id', (string) $receiptTopUp->getKey())
            ->where('effect_type', TelegramOutboundEffectType::BalanceTopUpReceipt->value)
            ->count());
        $this->assertSame(2, TelegramOutboundEffect::query()
            ->whereIn('source_id', [
                (string) $instructionTopUp->getKey(),
                (string) $receiptTopUp->getKey(),
            ])
            ->count());
    }

    public function test_postgres_competing_owner_compensations_create_one_credit_and_one_resolution(): void
    {
        $this->requirePostgresConcurrency();

        $topUp = $this->createSettlementTopUp(45432, 'owner-compensation-race');
        $topUp->forceFill(['status' => StoreBalanceTopUpStatus::Expired])->save();
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $results = $this->runConcurrentWorkers(2, function (int $index) use ($topUp, $owner): string {
            $result = app(StoreBalanceOwnerCompensationService::class)->release(
                topUpId: $topUp->id,
                actor: User::query()->findOrFail($owner->id),
                providerReference: 'OWNER-RACE-'.$index,
                reason: 'Concurrent owner resolution.',
            );

            return $result->applied ? 'Applied' : 'Replayed';
        });

        $this->assertWorkersSucceeded($results);
        $outcomes = array_map(static fn (array $result): string => (string) ($result['value'] ?? ''), $results);
        sort($outcomes);
        $this->assertSame(['Applied', 'Replayed'], $outcomes, json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(StoreBalanceTopUpStatus::Compensated, $topUp->fresh()->status);
        $this->assertSame(1, StoreBalanceCompensation::query()->count());
        $this->assertSame(1, StoreBalanceEntry::query()->where('kind', StoreBalanceEntryKind::OwnerCompensationCredit)->count());
        $this->assertSame(0, StoreBalanceEntry::query()->where('kind', StoreBalanceEntryKind::TopUpCredit)->count());
        $this->assertSame(8000, $this->balanceOf($topUp->customer));
        $this->assertProjectionMatchesLedger($topUp->customer, 8000);
    }

    public function test_postgres_owner_compensation_and_provider_callback_race_never_credit_twice_or_restore_paid_status(): void
    {
        $this->requirePostgresConcurrency();

        $topUp = $this->createSettlementTopUp(45433, 'owner-callback-race');
        $topUp->forceFill(['status' => StoreBalanceTopUpStatus::Expired])->save();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $callbackPayload = $this->topUpCallbackPayload($topUp);
        $statusPayload = $this->topUpStatusPayload($topUp);

        $results = $this->runConcurrentWorkers(2, function (int $index) use ($topUp, $owner, $callbackPayload, $statusPayload): string {
            if ($index === 0) {
                return app(StoreBalanceOwnerCompensationService::class)->release(
                    topUpId: $topUp->id,
                    actor: User::query()->findOrFail($owner->id),
                    providerReference: 'OWNER-CALLBACK-RACE',
                    reason: 'Provider callback race resolution.',
                )->applied ? 'compensation_applied' : 'compensation_replayed';
            }

            $callback = KlikQrisCallback::fromPayload($callbackPayload);
            $transaction = KlikQrisTransaction::fromStatusPayload($statusPayload, KlikQrisEnvironment::Sandbox);
            app(StoreBalanceTopUpService::class)->settleAuthoritativeTransaction(
                StoreBalanceTopUp::query()->findOrFail($topUp->id),
                $transaction,
                fn (StoreBalanceTopUp $lockedTopUp): StoreBalanceTopUpEvent => app(StoreBalanceTopUpEventLogger::class)->logCallback($callback, $lockedTopUp),
            );

            return 'callback_checked';
        });

        $this->assertWorkersSucceeded($results);
        $this->assertSame(StoreBalanceTopUpStatus::Compensated, $topUp->fresh()->status);
        $this->assertSame(1, StoreBalanceCompensation::query()->count());
        $this->assertSame(1, StoreBalanceEntry::query()->where('kind', StoreBalanceEntryKind::OwnerCompensationCredit)->count());
        $this->assertSame(0, StoreBalanceEntry::query()->where('kind', StoreBalanceEntryKind::TopUpCredit)->count());
        $this->assertSame(8000, $this->balanceOf($topUp->customer));
        $this->assertProjectionMatchesLedger($topUp->customer, 8000);
    }

    public function test_postgres_competing_debits_never_commit_a_negative_balance(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(45402);
        app(StoreBalanceLedger::class)->credit($customer, 25000, StoreBalanceEntryKind::TopUpCredit, 'balance_top_up', '7002', 'balance_top_up:7002:credit');

        $results = $this->runConcurrentWorkers(2, function (int $index) use ($customer): string {
            $result = app(StoreBalanceLedger::class)->debit(
                Customer::query()->findOrFail($customer->id),
                20000,
                'order',
                '800'.$index,
                'order:800'.$index.':debit',
            );

            return $result->applied ? 'Applied' : 'Replayed';
        });

        $this->assertCount(1, array_filter($results, static fn (array $result): bool => ($result['value'] ?? null) === 'Applied'), json_encode($results, JSON_THROW_ON_ERROR));
        $failures = array_values(array_filter($results, static fn (array $result): bool => ($result['ok'] ?? false) === false));
        $this->assertCount(1, $failures, json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(InsufficientStoreBalance::class, $failures[0]['class'] ?? null);
        $this->assertSame(1, $failures[0]['exit_code'] ?? null);

        $this->assertSame(5000, $this->balanceOf($customer));
        $this->assertSame(1, StoreBalanceEntry::query()->where('kind', StoreBalanceEntryKind::PurchaseDebit)->count());
        $this->assertSame(2, StoreBalanceEntry::query()->count());
        $this->assertProjectionMatchesLedger($customer, 5000);
    }

    public function test_postgres_competing_store_balance_checkouts_commit_one_order_without_overspending(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(45408);
        $cartIds = [];
        for ($index = 0; $index < 2; $index++) {
            $product = Product::factory()->create([
                'price_amount' => 20_000,
                'stock' => 1,
                'reserved_stock' => 0,
                'is_active' => true,
            ]);
            $this->createdProductIds[] = $product->id;
            $cart = Cart::factory()->create([
                'customer_id' => $customer->id,
                'status' => CartStatus::Active,
                'expires_at' => now()->addHour(),
            ]);
            CartItem::factory()->create([
                'cart_id' => $cart->id,
                'product_id' => $product->id,
                'quantity' => 1,
            ]);
            $cartIds[] = $cart->id;
        }
        app(StoreBalanceLedger::class)->credit(
            $customer,
            25_000,
            StoreBalanceEntryKind::TopUpCredit,
            'balance_top_up',
            '45408',
            'balance_top_up:45408:credit',
        );

        $results = $this->runConcurrentWorkers(2, function (int $index) use ($cartIds): string {
            $order = app(OrderCheckoutService::class)->checkout(
                Cart::query()->findOrFail($cartIds[$index]),
                CheckoutPaymentIntents::storeBalance(),
                'postgres-balance-checkout:'.$index,
            );

            return $order->payment_status->value;
        });

        $successes = array_values(array_filter($results, static fn (array $result): bool => ($result['value'] ?? null) === PaymentStatus::Paid->value));
        $failures = array_values(array_filter($results, static fn (array $result): bool => ($result['ok'] ?? false) === false));
        $this->assertCount(1, $successes, json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertCount(1, $failures, json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(InsufficientStoreBalance::class, $failures[0]['class'] ?? null);
        $this->assertSame(1, Order::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(1, StoreBalanceEntry::query()->where('kind', StoreBalanceEntryKind::PurchaseDebit)->count());
        $this->assertSame(5_000, $this->balanceOf($customer));
        $this->assertProjectionMatchesLedger($customer, 5_000);
    }

    public function test_postgres_same_checkout_key_reuses_one_paid_order_and_one_debit(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(45409);
        $product = Product::factory()->create([
            'price_amount' => 20_000,
            'stock' => 1,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        $this->createdProductIds[] = $product->id;
        $cart = Cart::factory()->create([
            'customer_id' => $customer->id,
            'status' => CartStatus::Active,
            'expires_at' => now()->addHour(),
        ]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);
        app(StoreBalanceLedger::class)->credit(
            $customer,
            20_000,
            StoreBalanceEntryKind::TopUpCredit,
            'balance_top_up',
            '45409',
            'balance_top_up:45409:credit',
        );

        $results = $this->runConcurrentWorkers(2, function () use ($cart): string {
            $order = app(OrderCheckoutService::class)->checkout(
                Cart::query()->findOrFail($cart->id),
                CheckoutPaymentIntents::storeBalance(),
                'postgres-balance-same-intent',
            );

            return $order->payment_status->value;
        });

        $this->assertWorkersSucceeded($results);
        $this->assertSame([PaymentStatus::Paid->value, PaymentStatus::Paid->value], array_map(static fn (array $result): string => (string) ($result['value'] ?? ''), $results));
        $this->assertSame(1, Order::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(1, StoreBalanceEntry::query()->where('kind', StoreBalanceEntryKind::PurchaseDebit)->count());
        $this->assertSame(0, $this->balanceOf($customer));
        $this->assertProjectionMatchesLedger($customer, 0);
    }

    public function test_postgres_concurrent_store_balance_refunds_credit_once_through_lifecycle(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(45410);
        $product = Product::factory()->create([
            'price_amount' => 20_000,
            'stock' => 2,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        $this->createdProductIds[] = $product->id;
        $cart = Cart::factory()->create([
            'customer_id' => $customer->id,
            'status' => CartStatus::Active,
            'expires_at' => now()->addHour(),
        ]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);
        app(StoreBalanceLedger::class)->credit(
            $customer,
            20_000,
            StoreBalanceEntryKind::TopUpCredit,
            'balance_top_up',
            '45410',
            'balance_top_up:45410:credit',
        );

        $order = app(OrderCheckoutService::class)->checkout(
            $cart,
            CheckoutPaymentIntents::storeBalance(),
            'postgres-balance-refund',
        );
        $paymentId = $order->payments()->sole()->id;
        $results = $this->runConcurrentWorkers(2, function () use ($paymentId): string {
            $result = app(OrderStateMachine::class)->applyPaymentRefunded(
                Payment::query()->findOrFail($paymentId),
            );

            return $result->newlyApplied ? 'Applied' : 'Replayed';
        });

        $this->assertWorkersSucceeded($results);
        $outcomes = array_map(static fn (array $result): string => (string) ($result['value'] ?? ''), $results);
        sort($outcomes);
        $this->assertSame(['Applied', 'Replayed'], $outcomes, json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(PaymentStatus::Refunded->value, $order->fresh()->payment_status->value);
        $this->assertSame(20_000, $this->balanceOf($customer));
        $this->assertSame(1, StoreBalanceEntry::query()->where('kind', StoreBalanceEntryKind::PurchaseDebit)->count());
        $this->assertSame(1, StoreBalanceEntry::query()->where('kind', StoreBalanceEntryKind::RefundCredit)->count());
        $this->assertProjectionMatchesLedger($customer, 20_000);
    }

    public function test_postgres_store_balance_refund_serializes_with_another_account_mutation(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(45411);
        $product = Product::factory()->create([
            'price_amount' => 20_000,
            'stock' => 2,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        $this->createdProductIds[] = $product->id;
        $cart = Cart::factory()->create([
            'customer_id' => $customer->id,
            'status' => CartStatus::Active,
            'expires_at' => now()->addHour(),
        ]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);
        app(StoreBalanceLedger::class)->credit(
            $customer,
            20_000,
            StoreBalanceEntryKind::TopUpCredit,
            'balance_top_up',
            '45411',
            'balance_top_up:45411:credit',
        );

        $order = app(OrderCheckoutService::class)->checkout(
            $cart,
            CheckoutPaymentIntents::storeBalance(),
            'postgres-balance-refund-mutation',
        );
        $paymentId = $order->payments()->sole()->id;

        $results = $this->runConcurrentWorkers(2, function (int $index) use ($customer, $paymentId): string {
            if ($index === 0) {
                $result = app(OrderStateMachine::class)->applyPaymentRefunded(
                    Payment::query()->findOrFail($paymentId),
                );

                return $result->newlyApplied ? 'RefundApplied' : 'RefundReplayed';
            }

            $result = app(StoreBalanceLedger::class)->credit(
                Customer::query()->findOrFail($customer->id),
                5_000,
                StoreBalanceEntryKind::TopUpCredit,
                'balance_top_up',
                '45411-race',
                'balance_top_up:45411-race:credit',
            );

            return $result->applied ? 'BalanceApplied' : 'BalanceReplayed';
        });

        $this->assertWorkersSucceeded($results);
        $outcomes = array_map(static fn (array $result): string => (string) ($result['value'] ?? ''), $results);
        sort($outcomes);
        $this->assertSame(['BalanceApplied', 'RefundApplied'], $outcomes, json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(25_000, $this->balanceOf($customer));
        $this->assertSame(1, StoreBalanceEntry::query()->where('kind', StoreBalanceEntryKind::PurchaseDebit)->count());
        $this->assertSame(1, StoreBalanceEntry::query()->where('kind', StoreBalanceEntryKind::RefundCredit)->count());
        $this->assertSame(4, StoreBalanceEntry::query()->count());
        $this->assertProjectionMatchesLedger($customer, 25_000);
    }

    public function test_postgres_parallel_credits_reconstruct_from_immutable_entries(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(45403);

        $results = $this->runConcurrentWorkers(4, function (int $index) use ($customer): string {
            $amount = ($index + 1) * 1000;

            $result = app(StoreBalanceLedger::class)->credit(
                Customer::query()->findOrFail($customer->id),
                $amount,
                StoreBalanceEntryKind::TopUpCredit,
                'balance_top_up',
                '71'.$index,
                'balance_top_up:71'.$index.':credit',
            );

            return $result->applied ? 'Applied' : 'Replayed';
        });

        $this->assertWorkersSucceeded($results);
        $this->assertSame(['Applied', 'Applied', 'Applied', 'Applied'], array_map(static fn (array $result): string => (string) ($result['value'] ?? ''), $results));

        $this->assertSame(4, StoreBalanceEntry::query()->count());
        $this->assertSame(10000, $this->balanceOf($customer));
        $this->assertProjectionMatchesLedger($customer, 10000);
    }

    public function test_postgres_duplicate_refund_source_identity_settles_one_economic_effect(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(45404);
        $ledger = app(StoreBalanceLedger::class);
        $ledger->credit($customer, 9000, StoreBalanceEntryKind::TopUpCredit, 'balance_top_up', '9001', 'balance_top_up:9001:credit');
        $ledger->debit($customer, 9000, 'order', '9001', 'order:9001:debit');

        $results = $this->runConcurrentWorkers(2, function (int $index) use ($customer): string {
            $result = app(StoreBalanceLedger::class)->refund(
                Customer::query()->findOrFail($customer->id),
                9000,
                'order',
                '9001',
                'order:9001:refund:attempt-'.$index,
            );

            return $result->applied ? 'Applied' : 'Replayed';
        });

        $this->assertWorkersSucceeded($results);
        $outcomes = array_map(static fn (array $result): string => (string) ($result['value'] ?? ''), $results);
        sort($outcomes);
        $this->assertSame(['Applied', 'Replayed'], $outcomes, json_encode($results, JSON_THROW_ON_ERROR));

        $this->assertSame(3, StoreBalanceEntry::query()->count());
        $this->assertSame(9000, $this->balanceOf($customer));
        $this->assertSame(1, StoreBalanceEntry::query()->where('kind', StoreBalanceEntryKind::RefundCredit)->count());
        $this->assertProjectionMatchesLedger($customer, 9000);
    }

    public function test_postgres_constraints_reject_negative_balance_and_duplicate_claims(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(45405);
        app(StoreBalanceLedger::class)->credit($customer, 1000, StoreBalanceEntryKind::TopUpCredit, 'balance_top_up', '7005', 'balance_top_up:7005:credit');
        $accountId = (int) CustomerBalanceAccount::query()->where('customer_id', $customer->id)->value('id');

        $this->assertDatabaseConstraintRejects(
            fn () => DB::table('customer_balance_accounts')->where('id', $accountId)->update(['balance' => -1]),
            'customer_balance_accounts_balance_nonnegative',
        );

        $this->assertDatabaseConstraintRejects(fn () => DB::table('store_balance_entries')->insert([
            'customer_balance_account_id' => $accountId,
            'kind' => StoreBalanceEntryKind::TopUpCredit->value,
            'amount' => 1000,
            'balance_after' => 2000,
            'source_type' => 'balance_top_up',
            'source_id' => '7005',
            'idempotency_key' => 'balance_top_up:7005:replay',
            'created_at' => now(),
        ]), 'store_balance_entries_source_unique');

        $this->assertDatabaseConstraintRejects(fn () => DB::table('store_balance_entries')->insert([
            'customer_balance_account_id' => $accountId,
            'kind' => StoreBalanceEntryKind::PurchaseDebit->value,
            'amount' => 0,
            'balance_after' => 1000,
            'source_type' => 'order',
            'source_id' => '7005',
            'idempotency_key' => 'order:7005:zero',
            'created_at' => now(),
        ]), 'store_balance_entries_amount_positive');

        $this->assertSame(1000, $this->balanceOf($customer));
        $this->assertSame(1, StoreBalanceEntry::query()->count());
        $this->assertProjectionMatchesLedger($customer, 1000);
    }

    public function test_postgres_account_creation_race_survives_a_rolled_back_winner(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(45407);

        // Simulate the losing side of an account-creation race: the winner's
        // transaction created the row and then rolled back, so the loser must
        // not observe a half-created account.
        try {
            DB::transaction(function () use ($customer): void {
                CustomerBalanceAccount::query()->create(['customer_id' => $customer->id]);
                throw new RuntimeException('rolled back create');
            });
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertSame(0, CustomerBalanceAccount::query()->where('customer_id', $customer->id)->count());

        $result = app(StoreBalanceLedger::class)->credit(
            $customer,
            6000,
            StoreBalanceEntryKind::TopUpCredit,
            'balance_top_up',
            '7007',
            'balance_top_up:7007:credit',
        );

        $this->assertTrue($result->applied);
        $this->assertSame(6000, $this->balanceOf($customer));
        $this->assertProjectionMatchesLedger($customer, 6000);
    }

    private function assertDatabaseConstraintRejects(callable $statement, string $expectedConstraint): void
    {
        try {
            $statement();
            $this->fail("The database constraint [{$expectedConstraint}] was expected to reject this Store Balance mutation.");
        } catch (QueryException $exception) {
            $this->assertStringContainsString($expectedConstraint, $exception->getMessage());
        }
    }

    public function test_postgres_readers_never_observe_a_ledger_projection_divergence(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(45406);
        $directory = $this->createWorkspace();
        $children = [];

        try {
            DB::disconnect();
            $children[] = $this->forkWorker($directory, 0, function () use ($customer, $directory): string {
                for ($index = 1; $index <= 15; $index++) {
                    app(StoreBalanceLedger::class)->credit(
                        Customer::query()->findOrFail($customer->id),
                        1000,
                        StoreBalanceEntryKind::TopUpCredit,
                        'balance_top_up',
                        '85'.$index,
                        'balance_top_up:85'.$index.':credit',
                    );
                }

                touch($directory.'/writer-done');

                return 'Credited';
            });

            $children[] = $this->forkWorker($directory, 1, function () use ($customer, $directory): string {
                $samples = 0;
                $divergences = 0;
                $deadline = microtime(true) + 20;

                while (! is_file($directory.'/writer-done') && microtime(true) < $deadline) {
                    $snapshot = app(StoreBalanceLedger::class)->snapshot(Customer::query()->findOrFail($customer->id));
                    $report = app(StoreBalanceReconciliation::class)->inspect($customer, 10);
                    if (! $snapshot->isAvailable() || ! $report->isConsistent()) {
                        $divergences++;
                    }
                    $samples++;
                    usleep(2_000);
                }

                return json_encode(['samples' => $samples, 'divergences' => $divergences], JSON_THROW_ON_ERROR);
            });

            foreach ([0, 1] as $index) {
                $this->waitForMarker($directory.'/ready-'.$index);
            }
            touch($directory.'/start');

            $results = $this->collectResults($children, $directory);
        } finally {
            $this->cleanupWorkspace($directory);
            DB::reconnect();
        }

        $this->assertWorkersSucceeded($results);
        $reader = json_decode((string) ($results[1]['value'] ?? ''), true, flags: JSON_THROW_ON_ERROR);
        $this->assertGreaterThanOrEqual(3, $reader['samples']);
        $this->assertSame(0, $reader['divergences']);
        $this->assertSame(15000, $this->balanceOf($customer));
        $this->assertProjectionMatchesLedger($customer, 15000);
    }

    public function test_postgres_activity_pages_read_each_committed_effect_during_refund_mutations(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(45412);
        app(StoreBalanceLedger::class)->credit(
            $customer,
            20_000,
            StoreBalanceEntryKind::TopUpCredit,
            'balance_top_up',
            'activity-seed',
            'balance_top_up:activity-seed:credit',
        );

        $results = $this->runConcurrentWorkers(2, function (int $index) use ($customer): string {
            if ($index === 0) {
                $ledger = app(StoreBalanceLedger::class);
                for ($mutation = 1; $mutation <= 10; $mutation++) {
                    $fresh = Customer::query()->findOrFail($customer->id);
                    $ledger->credit(
                        $fresh,
                        1_000,
                        StoreBalanceEntryKind::TopUpCredit,
                        'balance_top_up',
                        'activity-race-credit-'.$mutation,
                        'balance_top_up:activity-race-credit-'.$mutation.':credit',
                    );
                    $orderSourceId = '00000000-0000-0000-0000-'.str_pad((string) $mutation, 12, '0', STR_PAD_LEFT);
                    $ledger->debit(
                        Customer::query()->findOrFail($customer->id),
                        500,
                        'order',
                        $orderSourceId,
                        'order:'.$orderSourceId.':debit',
                    );
                    $ledger->refund(
                        Customer::query()->findOrFail($customer->id),
                        500,
                        'order',
                        $orderSourceId,
                        'order:'.$orderSourceId.':refund',
                    );
                    usleep(2_000);
                }

                return 'writer';
            }

            $samples = 0;
            $duplicatePages = 0;
            $activity = app(StoreBalanceActivityService::class);
            for ($sample = 0; $sample < 30; $sample++) {
                $page = $activity->firstPage(Customer::query()->findOrFail($customer->id));
                $ids = array_map(
                    static fn ($item): string => $item->type.':'.$item->id,
                    $page->items,
                );
                if (count($ids) !== count(array_unique($ids))) {
                    $duplicatePages++;
                }
                $samples++;
                usleep(2_000);
            }

            return json_encode([
                'samples' => $samples,
                'duplicate_pages' => $duplicatePages,
            ], JSON_THROW_ON_ERROR);
        });

        $this->assertWorkersSucceeded($results);
        $reader = json_decode((string) ($results[1]['value'] ?? ''), true, flags: JSON_THROW_ON_ERROR);
        $this->assertGreaterThanOrEqual(3, $reader['samples']);
        $this->assertSame(0, $reader['duplicate_pages']);

        $customer = $customer->fresh();
        $service = app(StoreBalanceActivityService::class);
        $page = $service->firstPage($customer);
        $ids = [];
        do {
            foreach ($page->items as $item) {
                $ids[] = $item->type.':'.$item->id;
            }
            if (! $page->hasOlder) {
                break;
            }
            $page = $service->olderPage($customer, $page->olderCursor);
            $this->assertNotNull($page);
        } while (true);

        $this->assertSame(31, StoreBalanceEntry::query()->whereHas('account', fn ($query) => $query->where('customer_id', $customer->id))->count());
        $this->assertCount(31, $ids);
        $this->assertCount(31, array_unique($ids));
        $this->assertSame(30_000, $this->balanceOf($customer));
        $this->assertProjectionMatchesLedger($customer, 30_000);
    }

    private function createSettlementTopUp(int $telegramId, string $suffix): StoreBalanceTopUp
    {
        $customer = $this->createCustomer($telegramId);
        $root = $this->createFundingRoot($customer, 9700 + $customer->id);
        $expiresAt = now()->addMinutes(15);
        $intent = StoreBalanceFundingIntent::query()->create([
            'customer_id' => $customer->id,
            'telegram_navigation_root_id' => $root->id,
            'chat_id' => (string) $customer->telegram_id,
            'canonical_message_id' => $root->message_id,
            'policy_version' => 1,
            'amount' => 8000,
            'nonce' => 'concurrency-'.$suffix.'-'.bin2hex(random_bytes(8)),
            'status' => StoreBalanceFundingIntentStatus::Confirmed,
            'expires_at' => $expiresAt,
            'claimed_at' => now(),
        ]);
        $merchantRef = 'BAL-CONC-'.$suffix;

        return StoreBalanceTopUp::query()->create([
            'customer_id' => $customer->id,
            'store_balance_funding_intent_id' => $intent->id,
            'telegram_navigation_root_id' => $root->id,
            'chat_id' => 'untrusted-'.$telegramId,
            'canonical_message_id' => $root->message_id,
            'locale' => 'en',
            'provider' => PaymentProvider::KLIKQRIS,
            'merchant_ref' => $merchantRef,
            'idempotency_key' => 'balance-top-up:concurrency:'.$suffix,
            'currency' => 'IDR',
            'amount' => 8000,
            'status' => StoreBalanceTopUpStatus::Payable,
            'provider_reference' => 'provider-reference-'.$suffix,
            'provider_payload' => ['data' => [
                'order_id' => $merchantRef,
                'amount' => 8000,
                'total_amount' => 8016,
                'status' => 'PENDING',
                'expired_at' => $expiresAt->toISOString(),
            ]],
            'provider_qris_url' => 'https://klikqris.com/storage/sandbox/sandbox_sample.png',
            'provider_fee' => 16,
            'provider_total_payment' => 8016,
            'provider_payment_method' => 'qris',
            'provider_created_at' => now(),
            'provider_expires_at' => $expiresAt,
            'expires_at' => $expiresAt,
            'provider_signature' => 'settlement-signature',
            'provider_create_state' => 'provider_SUCCESS',
        ]);
    }

    /** @return array<string, mixed> */
    private function topUpCallbackPayload(StoreBalanceTopUp $topUp): array
    {
        return [
            'order_id' => $topUp->merchant_ref,
            'amount' => (int) $topUp->amount,
            'total_amount' => (int) $topUp->provider_total_payment,
            'status' => 'SUCCESS',
            'signature' => 'settlement-signature',
            'provider_reference' => $topUp->provider_reference,
        ];
    }

    /** @return array<string, mixed> */
    private function topUpStatusPayload(StoreBalanceTopUp $topUp): array
    {
        return [
            ...$this->topUpCallbackPayload($topUp),
            'fee' => (int) $topUp->provider_fee,
            'paid_at' => now()->toISOString(),
            'expired_at' => $topUp->provider_expires_at->toISOString(),
        ];
    }

    private function createCustomer(int $telegramId): Customer
    {
        $customer = Customer::factory()->create(['telegram_id' => $telegramId, 'locale' => 'en']);
        $this->createdCustomerIds[] = $customer->id;

        return $customer;
    }

    private function balanceOf(Customer $customer): int
    {
        return (int) CustomerBalanceAccount::query()->where('customer_id', $customer->id)->value('balance');
    }

    private function assertProjectionMatchesLedger(Customer $customer, int $expected): void
    {
        $report = app(StoreBalanceReconciliation::class)->inspect($customer, 10);
        $this->assertTrue($report->isConsistent(), 'Projection must reconstruct from the immutable ledger entries.');
        $snapshot = app(StoreBalanceLedger::class)->snapshot($customer);
        $this->assertTrue($snapshot->isAvailable());
        $this->assertSame($expected, $snapshot->balance());
        $this->assertGreaterThanOrEqual(0, $snapshot->balance());
    }

    /** @param list<array{ok: bool, value?: string, class?: string, exit_code: int}> $results */
    private function assertWorkersSucceeded(array $results): void
    {
        foreach ($results as $result) {
            $this->assertTrue($result['ok'] ?? false, json_encode($results, JSON_THROW_ON_ERROR));
            $this->assertSame(0, $result['exit_code'] ?? null, json_encode($results, JSON_THROW_ON_ERROR));
        }
    }

    private function requirePostgresConcurrency(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Store Balance PostgreSQL races require the pgsql test connection.');
        }

        if ((string) env('RUN_CONCURRENCY_TESTS', '') !== '1') {
            $this->markTestSkipped('Set RUN_CONCURRENCY_TESTS=1 to run process-level PostgreSQL races.');
        }

        $this->assertSafeConcurrencyDatabase();
    }

    private function assertSafeConcurrencyDatabase(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->fail('The pcntl extension is required for Store Balance PostgreSQL races.');
        }

        if (! extension_loaded('pdo_pgsql')) {
            $this->fail('The pdo_pgsql extension is required for Store Balance PostgreSQL races.');
        }

        if (app()->environment() !== 'testing') {
            $this->fail('Refusing Store Balance PostgreSQL races outside APP_ENV=testing.');
        }

        if (config('database.default') !== 'pgsql' || config('database.connections.pgsql.driver') !== 'pgsql') {
            $this->fail('Store Balance PostgreSQL races require DB_CONNECTION=pgsql.');
        }

        if ((string) config('database.connections.pgsql.url') !== '' || (string) env('DB_URL', '') !== '') {
            $this->fail('Refusing Store Balance PostgreSQL races while DB_URL is configured.');
        }

        $database = (string) config('database.connections.pgsql.database');
        if (preg_match('/(^|[_-])(prod|production|live|main)([_-]|$)/i', $database) === 1
            || ! preg_match('/(^|[_-])(test|testing)([_-]|$)/i', $database)) {
            $this->fail("Store Balance PostgreSQL races require an isolated test database; got [{$database}].");
        }

        $actualDatabase = (string) DB::selectOne('select current_database() as database')->database;
        if ($actualDatabase !== $database) {
            $this->fail("Connected database [{$actualDatabase}] does not match configured database [{$database}].");
        }
    }

    /**
     * @param  callable(int): string  $work
     * @return list<array{ok: bool, value?: string, class?: string, exit_code: int}>
     */
    private function runConcurrentWorkers(int $count, callable $work): array
    {
        $directory = $this->createWorkspace();
        $children = [];

        try {
            DB::disconnect();
            for ($index = 0; $index < $count; $index++) {
                $children[$index] = $this->forkWorker($directory, $index, $work);
            }

            foreach (range(0, $count - 1) as $index) {
                $this->waitForMarker($directory.'/ready-'.$index);
            }
            touch($directory.'/start');

            return $this->collectResults($children, $directory);
        } finally {
            $this->cleanupWorkspace($directory);
            DB::reconnect();
        }
    }

    private function createWorkspace(): string
    {
        $directory = sys_get_temp_dir().'/store-balance-concurrency-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700) && ! is_dir($directory)) {
            $this->fail('Unable to create Store Balance concurrency workspace.');
        }

        return $directory;
    }

    /**
     * @param  callable(int): string  $work
     */
    private function forkWorker(string $directory, int $index, callable $work): int
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('Unable to fork Store Balance concurrency worker.');
        }

        if ($pid === 0) {
            $this->runWorker(
                $work,
                $index,
                $directory.'/ready-'.$index,
                $directory.'/start',
                $directory.'/result-'.$index.'.json',
            );
        }

        return $pid;
    }

    /**
     * @param  array<int, int>  $children
     * @return list<array{ok: bool, value?: string, class?: string, exit_code: int}>
     */
    private function collectResults(array $children, string $directory): array
    {
        $results = [];
        foreach ($children as $index => $pid) {
            $status = 0;
            pcntl_waitpid($pid, $status);
            $resultPath = $directory.'/result-'.$index.'.json';
            $result = is_file($resultPath)
                ? json_decode((string) file_get_contents($resultPath), true, flags: JSON_THROW_ON_ERROR)
                : ['ok' => false, 'class' => RuntimeException::class];
            $result['exit_code'] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 255;
            $results[] = $result;
        }

        return $results;
    }

    private function cleanupWorkspace(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($directory);
    }

    /**
     * @param  callable(int): string  $work
     */
    private function runWorker(
        callable $work,
        int $index,
        string $readyFile,
        string $startFile,
        string $resultFile,
    ): never {
        try {
            DB::disconnect();
            touch($readyFile);
            $this->waitForMarker($startFile);
            $value = $work($index);
            file_put_contents($resultFile, json_encode(['ok' => true, 'value' => $value], JSON_THROW_ON_ERROR), LOCK_EX);
            exit(0);
        } catch (Throwable $exception) {
            file_put_contents($resultFile, json_encode(['ok' => false, 'class' => $exception::class], JSON_THROW_ON_ERROR), LOCK_EX);
            exit(1);
        }
    }

    private function waitForMarker(string $path): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($path)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Timed out waiting for Store Balance concurrency worker.');
            }
            usleep(10_000);
        }
    }
}
