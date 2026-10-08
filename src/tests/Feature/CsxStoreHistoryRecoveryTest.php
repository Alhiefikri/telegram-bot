<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\SupplierProvider;
use App\Enums\TelegramOutboundEffectState;
use App\Exceptions\InvalidOrderTransition;
use App\Jobs\ProcessSupplierFulfillment;
use App\Jobs\SendTelegramReceipt;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\ProductSupplierSource;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierFulfillment;
use App\Models\SupplierOrderHistoryReconciliation;
use App\Models\SupplierPricePolicy;
use App\Models\SupplierPurchase;
use App\Models\User;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\Credentials\CredentialDeliveryEligibility;
use App\Services\OrderCheckoutService;
use App\Services\OrderLifecycle;
use App\Services\Suppliers\SupplierAvailability;
use App\Services\Suppliers\SupplierFulfillmentService;
use App\Services\Suppliers\SupplierOrderHistoryReconciliationService;
use App\Services\Telegram\ReceiptDeliveryPipeline;
use App\Services\Telegram\TelegramOutboundEffectLedgerProtocol;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NonTransactionalDatabaseTestCase;

final class CsxStoreHistoryRecoveryTest extends NonTransactionalDatabaseTestCase
{
    private const SECRET = 'synthetic-csx-history-secret-never-retain';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        $this->travelTo(now()->setDate(2026, 10, 8)->setTime(12, 0, 0));
    }

    public function test_scan_gets_only_first_page_and_keeps_multiple_candidates_without_economic_or_delivery_effects(): void
    {
        [$order, $fulfillment, $owner, $purchase] = $this->ambiguousOrder();
        $before = $this->economicState($order, $fulfillment);
        $rows = [
            $this->historyRow('candidate-A', ['created_at' => '2026-10-08 11:59:00', 'products' => [self::SECRET], 'api_key' => self::SECRET]),
            $this->historyRow('candidate-B', ['created_at' => '2026-10-08 19:00:00', 'password' => self::SECRET, 'payload' => ['secret' => self::SECRET]]),
            $this->historyRow('other-sku', ['service_id' => 'unrelated-sku']),
        ];
        $this->fakeHistory($this->historyEnvelope($rows) + ['credentials' => self::SECRET]);

        $scan = $this->scan($fulfillment, $owner, $purchase);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && strtok($request->url(), '?') === SupplierProvider::CSxStore->baseUrl().'/orders');
        $this->assertSame($before, $this->economicState($order, $fulfillment));
        $this->assertSame(SupplierOrderHistoryReconciliation::OUTCOME_CANDIDATE_ORDERS, $scan->outcome);
        $this->assertSame(['candidate-A', 'candidate-B'], $scan->candidate_order_ids);
        $this->assertSame('first_page', $scan->coverage_kind);
        $this->assertSame('unknown', $scan->history_timezone);
        $this->assertNull($scan->coverage_start);
        $this->assertNull($scan->coverage_end);
        $this->assertSame(3, $scan->row_count);
        $this->assertSame($this->version($purchase), $scan->purchase_version);
        $this->assertSame($purchase->claim_token, $scan->purchase_claim_token);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $scan->response_sha256);
        $facts = $scan->candidate_facts;
        $this->assertCount(2, $facts);
        $allowed = ['order_id', 'product_id', 'quantity', 'total_micros', 'currency', 'status', 'created_at_raw', 'timezone_qualification', 'match_reasons', 'limitations'];
        foreach ($facts as $fact) {
            $this->assertEqualsCanonicalizing($allowed, array_keys($fact));
            $this->assertSame('csx-history-sku', (string) $fact['product_id']);
            $this->assertSame(1, $fact['quantity']);
            $this->assertSame(1_000_000, $fact['total_micros']);
            $this->assertSame('USD', $fact['currency']);
            $this->assertSame('completed', $fact['status']);
            $this->assertSame('unknown_offset_free', $fact['timezone_qualification']);
            $this->assertContains('sku_matches', $fact['match_reasons']);
            $this->assertContains('identity_not_proven', $fact['limitations']);
        }
        $this->assertSame('2026-10-08 11:59:00', $facts[0]['created_at_raw']);
        $this->assertSame('2026-10-08 19:00:00', $facts[1]['created_at_raw']);
        $stored = (string) DB::table('supplier_order_history_reconciliations')->where('id', $scan->id)->value('candidate_facts');
        $this->assertStringNotContainsString('candidate-A', $stored);
        $this->assertStringNotContainsString(self::SECRET, $stored);
        $this->assertStringNotContainsString(self::SECRET, json_encode($scan->toArray(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString(self::SECRET, AuditLog::query()->get()->toJson());
        $this->actingAs($owner)->get(route('admin.suppliers.purchases'))->assertOk()
            ->assertSee('candidate-A')->assertSee('candidate-B')->assertDontSee(self::SECRET);
        $this->assertFalse(app(CredentialDeliveryEligibility::class)->allows($order->fresh(), $order->customer));
        Queue::assertNotPushed(SendTelegramReceipt::class);
    }

    public function test_transport_failed_or_claim_changed_during_scan_never_releases_hold_or_exposes_candidates(): void
    {
        [$order, $fulfillment, $owner, $purchase] = $this->ambiguousOrder();
        $before = $this->economicState($order, $fulfillment);
        $this->resetHttp();
        Http::fake(fn () => throw new ConnectionException(self::SECRET));
        $failed = $this->scan($fulfillment, $owner, $purchase);
        $this->assertSame('unavailable', $failed->outcome);
        $this->assertSame('transport', $failed->error_code);
        $this->assertSame($before, $this->economicState($order, $fulfillment));
        $this->assertStringNotContainsString(self::SECRET, AuditLog::query()->get()->toJson());
        $this->resetHttp();
        Http::fake(function () use ($purchase) {
            SupplierPurchase::findOrFail($purchase->id)->update(['claim_token' => (string) Str::uuid()]);

            return Http::response($this->historyEnvelope([$this->historyRow()]));
        });
        $stale = $this->scan($fulfillment, $owner, $purchase);
        $this->assertSame('invalid', $stale->outcome);
        $this->assertSame('stale_purchase_scan', $stale->error_code);
        $this->assertSame([], $stale->candidate_facts);
        $this->assertSame([], $stale->candidate_order_ids);
        $this->assertSame($before, $this->economicState($order, $fulfillment));
        Queue::assertNotPushed(SendTelegramReceipt::class);
    }

    public static function nonAuthoritativeHistory(): array
    {
        $row = ['order_id' => 'old-unrelated-order', 'service_id' => 'unrelated-sku', 'quantity' => 1,
            'total_cost' => '1', 'status' => 'completed', 'created_at' => '2026-10-07 00:00:00'];
        $envelope = ['success' => true, 'page' => 1, 'limit' => 100, 'total_orders' => 1, 'total_pages' => 1, 'orders' => [$row]];
        $hundred = [];
        for ($index = 0; $index < 100; $index++) {
            $hundred[] = array_replace($row, ['order_id' => 'unrelated-'.$index]);
        }

        return [
            'first page is not authoritative absence' => [$envelope, 200, 'first_page'],
            'empty first page' => [array_replace($envelope, ['total_orders' => 0, 'total_pages' => 0, 'orders' => []]), 200, 'first_page'],
            'more pages reported' => [array_replace($envelope, ['total_orders' => 101, 'total_pages' => 2]), 200, 'truncated'],
            'saturated first page' => [array_replace($envelope, ['total_orders' => 100, 'orders' => $hundred]), 200, 'truncated'],
            'provider failure' => [['success' => false, 'message' => self::SECRET], 200, null],
            'rate limited history' => [['error' => self::SECRET], 429, null],
            'transport failure status' => [['error' => self::SECRET], 503, null],
            'malformed envelope' => [['success' => true, 'orders' => self::SECRET], 200, null],
            'malformed row' => [array_replace($envelope, ['orders' => [array_replace($row, ['total_cost' => 'not-money'])]]), 200, null],
            'fractional micro cost' => [array_replace($envelope, ['orders' => [array_replace($row, ['total_cost' => '1.0000001'])]]), 200, null],
            'wrong returned page' => [array_replace($envelope, ['page' => 2]), 200, null],
            'unpersistable reported count' => [array_replace($envelope, ['total_orders' => 2_147_483_648]), 200, null],
            'too many returned rows' => [array_replace($envelope, ['total_orders' => 101, 'total_pages' => 2, 'orders' => array_merge($hundred, [$row])]), 200, null],
        ];
    }

    #[DataProvider('nonAuthoritativeHistory')]
    public function test_first_page_truncated_empty_error_and_malformed_scans_cannot_authorize_no_debit(array $payload, int $status, ?string $coverage): void
    {
        [$order, $fulfillment, $owner, $purchase] = $this->ambiguousOrder();
        $before = $this->economicState($order, $fulfillment);
        $this->fakeHistory($payload, $status);
        $scan = $this->scan($fulfillment, $owner, $purchase);

        Http::assertSentCount(1);
        $this->assertNotSame(SupplierOrderHistoryReconciliation::OUTCOME_NO_ORDER, $scan->outcome);
        $this->assertLessThanOrEqual(100, $scan->row_count);
        if ($coverage !== null) {
            $this->assertSame($coverage, $scan->coverage_kind);
        } else {
            $this->assertContains($scan->outcome, ['unavailable', 'inconclusive']);
            $this->assertNotNull($scan->error_code);
        }
        $this->assertSame($before, $this->economicState($order, $fulfillment));
        $this->assertStringNotContainsString(self::SECRET, $scan->toJson());
        try {
            app(SupplierFulfillmentService::class)->confirmNoDebit(
                $fulfillment->id, $owner, '', 'Synthetic first-page absence is not no-debit proof.',
                evidenceSource: 'provider_history', historyReconciliationId: $scan->id,
            );
            $this->fail('CSx first-page history must never authorize releasing an ambiguous hold.');
        } catch (InvalidOrderTransition) {
            $this->assertSame($before, $this->economicState($order, $fulfillment));
            $this->assertDatabaseMissing('supplier_purchase_evidence', ['kind' => 'no_debit']);
        }
    }

    public function test_csx_ambiguous_purchase_never_scans_history_automatically_or_repeats_post(): void
    {
        [$order, $fulfillment] = $this->ambiguousOrder();
        $before = $this->economicState($order, $fulfillment);
        $this->resetHttp();
        Http::fake(fn () => Http::response($this->historyEnvelope([])));

        $this->assertNull(app(SupplierOrderHistoryReconciliationService::class)->reconcileAutomatically($fulfillment->id));
        for ($attempt = 0; $attempt < 4; $attempt++) {
            app(SupplierFulfillmentService::class)->recover();
            (new ProcessSupplierFulfillment($fulfillment->id))->handle(app(SupplierFulfillmentService::class));
            $this->travel(5)->minutes();
        }

        Http::assertNothingSent();
        $this->assertSame(0, SupplierOrderHistoryReconciliation::query()->count());
        $this->assertSame($before, $this->economicState($order, $fulfillment));
    }

    public static function restrictedSessions(): array
    {
        $cases = [];
        foreach (['history', 'history_confirm'] as $action) {
            foreach (['finance', 'support'] as $role) {
                $cases[$action.' '.$role] = [$action, $role, ['auth.password_confirmed_at' => time()], 403];
            }
            $cases[$action.' absent password confirmation'] = [$action, 'owner', [], 428];
            $cases[$action.' expired password confirmation'] = [$action, 'admin', ['auth.password_confirmed_at' => time() - 901], 428];
        }

        return $cases;
    }

    #[DataProvider('restrictedSessions')]
    public function test_admin_history_actions_require_manager_authorization_and_recent_password(string $action, string $role, array $session, int $status): void
    {
        [$order, $fulfillment, $owner, $purchase] = $this->ambiguousOrder();
        $this->fakeHistory($this->historyEnvelope([$this->historyRow()]));
        $scan = $this->scan($fulfillment, $owner, $purchase);
        $before = $this->economicState($order, $fulfillment);
        $this->resetHttp();
        $actor = User::factory()->create(['role' => $role]);

        $this->actingAs($actor)->withSession($session)->postJson(route('admin.suppliers.operate', $fulfillment),
            $this->form($action, $purchase, $scan))->assertStatus($status)->assertDontSee(self::SECRET);

        Http::assertNothingSent();
        $this->assertSame(1, SupplierOrderHistoryReconciliation::query()->count());
        $this->assertSame($before, $this->economicState($order, $fulfillment));
    }

    public function test_owner_scan_form_needs_no_guessed_timezone_and_binds_purchase_version(): void
    {
        [$order, $fulfillment, $owner, $purchase] = $this->ambiguousOrder();
        $before = $this->economicState($order, $fulfillment);
        $this->fakeHistory($this->historyEnvelope([$this->historyRow()]));

        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.suppliers.operate', $fulfillment), $this->form('history', $purchase))
            ->assertRedirect(route('admin.suppliers.purchases'));

        $scan = $purchase->historyReconciliations()->sole();
        $this->assertSame('unknown', $scan->history_timezone);
        $this->assertSame(['chosen-original-order'], $scan->candidate_order_ids);
        $this->assertSame($before, $this->economicState($order, $fulfillment));
        $page = $this->get(route('admin.suppliers.purchases'))->assertOk();
        $page->assertSee('history_confirm')->assertSee('history_reconciliation_id')->assertSee('purchase_version')
            ->assertSee($this->version($purchase))->assertSee('chosen-original-order');
    }

    public function test_unauthenticated_history_scan_and_confirmation_redirect_without_supplier_transport(): void
    {
        [$order, $fulfillment, $owner, $purchase] = $this->ambiguousOrder();
        $this->fakeHistory($this->historyEnvelope([$this->historyRow()]));
        $scan = $this->scan($fulfillment, $owner, $purchase);
        $before = $this->economicState($order, $fulfillment);
        $this->resetHttp();
        foreach (['history', 'history_confirm'] as $action) {
            $this->post(route('admin.suppliers.operate', $fulfillment), $this->form($action, $purchase, $scan))
                ->assertRedirect(route('admin.login'));
        }
        Http::assertNothingSent();
        $this->assertSame($before, $this->economicState($order, $fulfillment));
        $this->assertSame(1, SupplierOrderHistoryReconciliation::query()->count());
    }

    public static function incompleteForms(): array
    {
        return [
            'scan missing purchase' => ['history', 'purchase_id'],
            'scan missing version' => ['history', 'purchase_version'],
            'confirmation missing purchase' => ['history_confirm', 'purchase_id'],
            'confirmation missing version' => ['history_confirm', 'purchase_version'],
            'confirmation missing scan' => ['history_confirm', 'history_reconciliation_id'],
            'confirmation missing selection' => ['history_confirm', 'supplier_order_id'],
            'confirmation missing proof note' => ['history_confirm', 'proof_note'],
        ];
    }

    #[DataProvider('incompleteForms')]
    public function test_history_forms_require_identity_version_scan_selection_and_operator_note(string $action, string $field): void
    {
        [$order, $fulfillment, $owner, $purchase] = $this->ambiguousOrder();
        $this->fakeHistory($this->historyEnvelope([$this->historyRow()]));
        $scan = $this->scan($fulfillment, $owner, $purchase);
        $form = $this->form($action, $purchase, $scan);
        unset($form[$field]);
        $before = $this->economicState($order, $fulfillment);
        $this->resetHttp();

        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('admin.suppliers.operate', $fulfillment), $form)
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        Http::assertNothingSent();
        $this->assertSame($before, $this->economicState($order, $fulfillment));
        $this->assertSame(1, SupplierOrderHistoryReconciliation::query()->count());
    }

    public static function staleForms(): array
    {
        return [
            'scan wrong purchase' => ['history', 'wrong_purchase'],
            'scan stale purchase version' => ['history', 'stale_version'],
            'confirmation wrong purchase' => ['history_confirm', 'wrong_purchase'],
            'confirmation stale purchase version' => ['history_confirm', 'stale_version'],
            'confirmation expired scan' => ['history_confirm', 'expired_scan'],
            'confirmation superseded scan' => ['history_confirm', 'superseded_scan'],
            'confirmation unscanned order' => ['history_confirm', 'unscanned_order'],
        ];
    }

    #[DataProvider('staleForms')]
    public function test_stale_purchase_version_scan_and_unselected_candidate_forms_fail_before_transport(string $action, string $scenario): void
    {
        [$order, $fulfillment, $owner, $purchase] = $this->ambiguousOrder();
        $this->fakeHistory($this->historyEnvelope([$this->historyRow()]));
        $scan = $this->scan($fulfillment, $owner, $purchase);
        $form = $this->form($action, $purchase, $scan);
        if ($scenario === 'wrong_purchase') {
            $form['purchase_id'] = $purchase->id + 1000;
        } elseif ($scenario === 'stale_version') {
            $this->travel(2)->seconds();
            $purchase->forceFill(['updated_at' => now()])->save();
        } elseif ($scenario === 'expired_scan') {
            $this->travel(16)->minutes();
        } elseif ($scenario === 'superseded_scan') {
            $this->travel(2)->seconds();
            $this->scan($fulfillment, $owner, $purchase->fresh());
        } elseif ($scenario === 'unscanned_order') {
            $form['supplier_order_id'] = 'not-in-this-scan';
        }
        $before = $this->economicState($order, $fulfillment);
        $scanCount = SupplierOrderHistoryReconciliation::query()->count();
        $this->resetHttp();

        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('admin.suppliers.operate', $fulfillment), $form)->assertStatus(409);

        Http::assertNothingSent();
        $this->assertSame($scanCount, SupplierOrderHistoryReconciliation::query()->count());
        $this->assertSame($before, $this->economicState($order, $fulfillment));
        $this->assertDatabaseMissing('supplier_purchase_evidence', ['kind' => 'debit_confirmed']);
    }

    public static function untrustedExactOrder(): array
    {
        return [
            'wrong SKU' => [['service_id' => 'unrelated-sku']],
            'missing SKU' => [['service_id' => null]],
            'wrong quantity' => [['quantity' => 2]],
            'wrong exact cost' => [['total_cost' => '1.000001']],
            'missing cost' => [['total_cost' => null]],
            'wrong original order' => [['order_id' => 'different-order']],
            'failed status with credentials' => [['status' => 'failed']],
            'missing success authority' => [['success' => null]],
            'false success with credentials' => [['success' => false]],
        ];
    }

    #[DataProvider('untrustedExactOrder')]
    public function test_confirmation_rechecks_exact_order_identity_sku_quantity_cost_and_success_before_settlement(array $overrides): void
    {
        [$order, $fulfillment, $owner, $purchase] = $this->ambiguousOrder();
        $this->fakeHistory($this->historyEnvelope([$this->historyRow()]));
        $scan = $this->scan($fulfillment, $owner, $purchase);
        $this->fakeExactOrder(array_replace($this->exactOrder(), $overrides));

        try {
            $this->confirm($fulfillment, $owner, $purchase, $scan);
            $this->fail('Untrusted or economically conflicting exact-order evidence must fail closed.');
        } catch (InvalidOrderTransition) {
            $this->assertUnsettled($order, $fulfillment);
            $this->assertDatabaseMissing('supplier_purchase_evidence', ['kind' => 'debit_confirmed']);
        }
        Http::assertSentCount(1);
        Queue::assertNotPushed(SendTelegramReceipt::class);
    }

    public function test_completed_order_with_incomplete_credentials_confirms_only_economics_not_customer_delivery(): void
    {
        [$order, $fulfillment, $owner, $purchase] = $this->ambiguousOrder(2);
        $this->fakeHistory($this->historyEnvelope([$this->historyRow(overrides: ['quantity' => 2, 'total_cost' => '2'])]));
        $scan = $this->scan($fulfillment, $owner, $purchase);
        $this->fakeExactOrder($this->exactOrder(['quantity' => 2, 'total_cost' => '2', 'products' => ['only-one-synthetic-unit']]));

        $confirmed = $this->confirm($fulfillment, $owner, $purchase, $scan);

        $this->assertSame(2, $confirmed->observed_quantity);
        $this->assertSame(2_000_000, $confirmed->actual_cost_micros);
        $this->assertNotNull($confirmed->committed_at);
        $this->assertSame(8_000_000, Supplier::sole()->balance_micros);
        $this->assertSame(0, Supplier::sole()->reserved_balance_micros);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        $this->assertSame(0, ProductStockItem::query()->where('order_item_id', $fulfillment->order_item_id)->count());
        $this->assertFalse(app(CredentialDeliveryEligibility::class)->allows($order->fresh(), $order->customer));
        $this->assertNull(app(TelegramOutboundEffectService::class)->recordReceipt($order->fresh()));
        $this->assertNull($order->fresh()->telegram_receipt_sent_at);
        Queue::assertNotPushed(SendTelegramReceipt::class);
    }

    public function test_supplier_order_identity_already_owned_by_another_purchase_cannot_be_reallocated_or_debited(): void
    {
        [$order, $fulfillment, $owner, $purchase] = $this->ambiguousOrder();
        SupplierPurchase::create([
            'supplier_fulfillment_id' => $fulfillment->id, 'supplier_id' => $purchase->supplier_id,
            'supplier_catalog_item_id' => $purchase->supplier_catalog_item_id, 'reference' => (string) Str::uuid(),
            'supplier_order_id' => 'chosen-original-order', 'quantity' => 1, 'expected_cost_micros' => 1_000_000,
            'actual_cost_micros' => 1_000_000, 'observed_quantity' => 1, 'exchange_rate_idr' => 1000,
            'state' => 'settled', 'committed_at' => now()->subHour(),
        ]);
        $this->fakeHistory($this->historyEnvelope([$this->historyRow()]));
        $scan = $this->scan($fulfillment, $owner, $purchase);
        $this->fakeExactOrder($this->exactOrder());

        try {
            $this->confirm($fulfillment, $owner, $purchase, $scan);
            $this->fail('A supplier order ID claimed by another purchase must never be attached twice.');
        } catch (InvalidOrderTransition) {
            $this->assertUnsettled($order, $fulfillment);
            $this->assertSame(2, SupplierPurchase::query()->count());
            $this->assertSame(1, SupplierPurchase::query()->where('supplier_order_id', 'chosen-original-order')->count());
            $this->assertDatabaseMissing('supplier_purchase_evidence', ['supplier_purchase_id' => $purchase->id, 'kind' => 'debit_confirmed']);
        }
    }

    public function test_lost_original_post_scan_chosen_confirmation_and_receipt_jobs_settle_and_acknowledge_once(): void
    {
        [$order, $fulfillment, $owner, $purchase] = $this->ambiguousOrder();
        $item = $order->orderItems()->sole();
        $this->fakeHistory($this->historyEnvelope([$this->historyRow('decoy-order'), $this->historyRow()]));
        $scan = $this->scan($fulfillment, $owner, $purchase);
        $this->assertUnsettled($order, $fulfillment);
        $this->fakeExactOrder($this->exactOrder());
        $form = $this->form('history_confirm', $purchase, $scan);

        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.suppliers.operate', $fulfillment), $form)
            ->assertRedirect(route('admin.suppliers.purchases'));
        $this->post(route('admin.suppliers.operate', $fulfillment), $form)
            ->assertRedirect(route('admin.suppliers.purchases'));

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === SupplierProvider::CSxStore->baseUrl().'/order/chosen-original-order');
        $confirmed = $purchase->fresh();
        $this->assertSame(1, $fulfillment->purchases()->count());
        $this->assertSame('chosen-original-order', $confirmed->supplier_order_id);
        $this->assertSame(1, $confirmed->observed_quantity);
        $this->assertSame(1_000_000, $confirmed->actual_cost_micros);
        $this->assertNotNull($confirmed->committed_at);
        $this->assertSame(1, $confirmed->evidence()->where('kind', 'debit_confirmed')->count());
        $this->assertSame(9_000_000, Supplier::sole()->balance_micros);
        $this->assertSame(0, Supplier::sole()->reserved_balance_micros);
        $this->assertSame(0, SupplierCatalogItem::sole()->reserved_quantity);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $stock = ProductStockItem::query()->sole();
        $this->assertSame($item->id, $stock->order_item_id);
        $this->assertSame($item->product_id, $stock->product_id);
        $this->assertSame($purchase->id, $stock->supplier_purchase_id);
        $this->assertSame(1, $stock->supplier_unit_index);
        $this->assertSame('chosen-original-synthetic-credential', $stock->secret);
        $this->assertSame(0, Product::findOrFail($item->product_id)->stock);
        $this->assertNull($order->fresh()->telegram_receipt_sent_at);
        $this->assertTrue(app(CredentialDeliveryEligibility::class)->allows($order->fresh(), $order->customer));

        config()->set('services.telegram.token', 'synthetic-test-token');
        $this->resetHttp();
        $messageId = 587000;
        Http::fake(['https://api.telegram.org/*' => function () use (&$messageId) {
            return Http::response(['ok' => true, 'result' => ['message_id' => ++$messageId]]);
        }]);
        $outbound = app(TelegramOutboundEffectService::class);
        $pipeline = app(ReceiptDeliveryPipeline::class);
        $receiptJob = new SendTelegramReceipt((string) $order->id);
        $receiptJob->handle($outbound, $pipeline);
        $group = TelegramOutboundEffectService::receiptEffectGroupKey((string) $order->id);
        $ledger = app(TelegramOutboundEffectLedgerProtocol::class);
        $effect = $ledger->nextReceiptEffect($group);
        while ($effect !== null) {
            (new SendTelegramReceipt((string) $order->id, effectId: (string) $effect->id))->handle($outbound, $pipeline);
            $effect = $ledger->nextReceiptEffect($group, (int) $effect->sequence);
        }
        $effects = $order->telegramOutboundEffects()->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS)->get();
        $this->assertSame(1, $effects->pluck('effect_group_key')->unique()->count());
        foreach ($effects as $effect) {
            $this->assertSame(TelegramOutboundEffectState::Sent, $effect->state);
            $this->assertNotNull($effect->sent_at);
            $this->assertNotNull($effect->telegram_message_id);
            $this->assertSame(1, $effect->attempts);
            $this->assertSame((string) $order->customer->telegram_id, (string) $effect->telegram_chat_id);
        }
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
        $acknowledgements = $effects->map(fn ($effect): array => [$effect->id, $effect->telegram_message_id, $effect->sent_at->toIso8601String(), $effect->attempts])->all();
        $receiptJob->handle($outbound, $pipeline);
        $again = $order->telegramOutboundEffects()->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS)->get();
        $this->assertSame($acknowledgements, $again->map(fn ($effect): array => [$effect->id, $effect->telegram_message_id, $effect->sent_at->toIso8601String(), $effect->attempts])->all());
        $this->assertSame(1, $fulfillment->purchases()->count());
        $this->assertSame(1, $item->stockItems()->count());
        $this->assertSame(1, $confirmed->evidence()->where('kind', 'debit_confirmed')->count());
        $this->assertSame(9_000_000, Supplier::sole()->balance_micros);
    }

    private function ambiguousOrder(int $quantity = 1): array
    {
        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0, 'price_amount' => 5000]);
        $supplier = Supplier::forceCreate([
            'name' => 'Synthetic CSx recovery', 'provider' => 'csxstore', 'api_key' => 'synthetic-auth-key-not-delivery-secret',
            'currency' => 'USD', 'currency_confirmed' => true, 'exchange_rate_idr' => 1000,
            'balance_micros' => 10_000_000, 'is_active' => true,
            'synced_at' => now(), 'sync_expires_at' => now()->addMinutes(5),
        ]);
        $sku = SupplierCatalogItem::create([
            'supplier_id' => $supplier->id, 'external_id' => 'csx-history-sku', 'name' => 'Synthetic CSx SKU',
            'unit_price_micros' => 1_000_000, 'stock' => 10, 'orderable' => true, 'requires_input' => false,
            'synced_at' => now(), 'price_tiers' => [], 'bulk_discounts' => [],
        ]);
        SupplierPricePolicy::create(['product_id' => $product->id, 'mode' => 'manual', 'minimum_profit_idr' => 500, 'rounding_idr' => 1]);
        ProductSupplierSource::create(['product_id' => $product->id, 'supplier_catalog_item_id' => $sku->id, 'is_active' => true, 'priority' => 0]);
        app(SupplierAvailability::class)->refreshProducts([$product->id]);
        $customer = Customer::factory()->create(['telegram_id' => 798587]);
        $cart = Cart::factory()->create(['customer_id' => $customer->id, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => $quantity]);
        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::manualQris());
        Http::assertNothingSent();
        app(OrderLifecycle::class)->applyManualCompletion($order);
        $fulfillment = SupplierFulfillment::query()->where('order_id', $order->id)->sole();
        Http::fake(function ($request) {
            $this->assertSame('POST', $request->method());
            throw new ConnectionException('Synthetic response lost after possible provider debit.');
        });
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $purchase = SupplierPurchase::query()->sole();
        $this->assertSame('ambiguous', $purchase->state);
        $this->assertNotNull($purchase->dispatched_at);
        $this->assertNull($purchase->supplier_order_id);
        $this->assertNull($purchase->committed_at);
        $this->assertSame($quantity * 1_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(1, DB::table('supplier_purchase_dispatches')->where('supplier_purchase_id', $purchase->id)->count());
        $owner = User::factory()->create(['role' => 'owner']);

        return [$order, $fulfillment->fresh(), $owner, $purchase];
    }

    private function historyRow(string $id = 'chosen-original-order', array $overrides = []): array
    {
        return array_replace(['order_id' => $id, 'service_id' => 'csx-history-sku', 'quantity' => 1,
            'total_cost' => '1.000000', 'status' => 'completed', 'created_at' => '2026-10-08 12:00:00'], $overrides);
    }

    private function historyEnvelope(array $rows): array
    {
        return ['success' => true, 'page' => 1, 'limit' => 100, 'total_orders' => count($rows),
            'total_pages' => $rows === [] ? 0 : 1, 'orders' => $rows];
    }

    private function exactOrder(array $overrides = []): array
    {
        return array_replace(['success' => true, 'order_id' => 'chosen-original-order', 'service_id' => 'csx-history-sku',
            'quantity' => 1, 'total_cost' => '1.000000', 'status' => 'completed',
            'products' => ['chosen-original-synthetic-credential']], $overrides);
    }

    private function resetHttp(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    private function fakeHistory(array $payload, int $status = 200): void
    {
        $this->resetHttp();
        Http::fake(function ($request) use ($payload, $status) {
            $this->assertSame('GET', $request->method());
            $this->assertSame(SupplierProvider::CSxStore->baseUrl().'/orders', strtok($request->url(), '?'));

            return Http::response($payload, $status);
        });
    }

    private function fakeExactOrder(array $payload): void
    {
        $this->resetHttp();
        Http::fake(function ($request) use ($payload) {
            $this->assertSame('GET', $request->method());
            $this->assertSame(SupplierProvider::CSxStore->baseUrl().'/order/chosen-original-order', $request->url());

            return Http::response($payload);
        });
    }

    private function version(SupplierPurchase $purchase): string
    {
        return $purchase->updated_at->format('Y-m-d H:i:s');
    }

    private function scan(SupplierFulfillment $fulfillment, User $actor, SupplierPurchase $purchase): SupplierOrderHistoryReconciliation
    {
        return app(SupplierOrderHistoryReconciliationService::class)->reconcile(
            $fulfillment->id, $actor, '', null, $purchase->id, $this->version($purchase),
        );
    }

    private function confirm(SupplierFulfillment $fulfillment, User $actor, SupplierPurchase $purchase, SupplierOrderHistoryReconciliation $scan): SupplierPurchase
    {
        return app(SupplierOrderHistoryReconciliationService::class)->confirmCandidate(
            $fulfillment->id, $actor, $scan->id, 'chosen-original-order', 'Synthetic operator checks exact original order.',
            null, $purchase->id, $this->version($purchase),
        );
    }

    private function form(string $action, SupplierPurchase $purchase, ?SupplierOrderHistoryReconciliation $scan = null): array
    {
        $form = ['action' => $action, 'purchase_id' => $purchase->id, 'purchase_version' => $this->version($purchase)];
        if ($action === 'history_confirm') {
            $form += ['history_reconciliation_id' => $scan->id, 'supplier_order_id' => 'chosen-original-order',
                'proof_note' => 'Synthetic operator checks exact original order.'];
        }

        return $form;
    }

    private function economicState(Order $order, SupplierFulfillment $fulfillment): array
    {
        $purchase = $fulfillment->fresh()->currentPurchase;

        return [
            'order' => [$order->fresh()->order_status, $order->fresh()->payment_status, $order->fresh()->telegram_receipt_sent_at],
            'purchase' => $purchase->only(['id', 'state', 'supplier_order_id', 'actual_cost_micros', 'observed_quantity', 'committed_at']),
            'supplier' => Supplier::findOrFail($purchase->supplier_id)->only(['balance_micros', 'reserved_balance_micros']),
            'catalog' => SupplierCatalogItem::findOrFail($purchase->supplier_catalog_item_id)->only(['stock', 'reserved_quantity']),
            'purchases' => $fulfillment->purchases()->count(),
            'evidence' => $purchase->evidence()->count(),
            'allocations' => ProductStockItem::query()->where('order_item_id', $fulfillment->order_item_id)->count(),
            'receipts' => $order->telegramOutboundEffects()->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS)->count(),
        ];
    }

    private function assertUnsettled(Order $order, SupplierFulfillment $fulfillment): void
    {
        $purchase = $fulfillment->fresh()->currentPurchase;
        $this->assertNull($purchase->committed_at);
        $this->assertNull($purchase->supplier_order_id);
        $this->assertSame(10_000_000, Supplier::findOrFail($purchase->supplier_id)->balance_micros);
        $this->assertSame($purchase->quantity * 1_000_000, Supplier::findOrFail($purchase->supplier_id)->reserved_balance_micros);
        $this->assertSame($purchase->quantity, SupplierCatalogItem::findOrFail($purchase->supplier_catalog_item_id)->reserved_quantity);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        $this->assertSame(0, ProductStockItem::query()->where('order_item_id', $fulfillment->order_item_id)->count());
        $this->assertNull($order->fresh()->telegram_receipt_sent_at);
    }
}
