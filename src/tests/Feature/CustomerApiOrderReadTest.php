<?php

namespace Tests\Feature;

use App\Enums\CredentialAccessMethod;
use App\Enums\CredentialFormat;
use App\Enums\OrderStatus;
use App\Http\Controllers\CustomerApiOrderReadController;
use App\Models\Customer;
use App\Models\ManualFulfillmentReview;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Services\Credentials\StructuredCredentialPayload;
use App\Services\CustomerApi\CustomerApiCredentials;
use App\Services\CustomerApi\CustomerApiFailure;
use App\Services\CustomerApi\CustomerApiOrderCheckout;
use App\Services\CustomerApi\CustomerApiOrderRequest;
use App\Services\CustomerApi\CustomerApiPrincipal;
use App\Services\OrderLifecycle;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\VerifiedUsdtFunding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CustomerApiOrderReadTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private CustomerApiPrincipal $principal;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        $this->customer = Customer::factory()->create();
        $keys = app(CustomerApiCredentials::class);
        $this->principal = $keys->authenticate('Bearer '.$keys->generate($this->customer)->takeSecret());
        app(CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp($this->customer,
            new VerifiedUsdtFunding($this->customer->id, UsdtMicroAmount::fromUnits(500_000_000), 'read-funding', 'read-receipt', 'v1'), 'read-funding');
    }

    public function test_rotated_key_recovers_identical_structured_units_without_money_inventory_or_transport_effects(): void
    {
        $order = $this->buy(2, true);
        $keys = app(CustomerApiCredentials::class);
        $nonce = $keys->prepareRotation($this->customer, 10);
        $this->principal = $keys->authenticate('Bearer '.$keys->rotate($this->customer, 10, $nonce)->takeSecret());
        $before = $this->economics();
        $first = $this->read('delivery', $order->id)['data'];
        $this->assertSame('ready', $first['state']);
        $this->assertSame(ProductStockItem::whereNotNull('order_item_id')->orderBy('id')->pluck('id')->map(strval(...))->all(), array_column($first['units'], 'id'));
        $this->assertSame('test0@example.com', $first['units'][0]['email']);
        $this->assertSame('password-0', $first['units'][0]['password']);
        $this->assertSame('auth_token', $first['units'][0]['access_method']);
        $this->assertSame('token-0', $first['units'][0]['access_value']);
        $this->assertSame('Per-item tutorial', $first['units'][0]['tutorial']);
        $this->assertSame([['title' => 'Usage', 'url' => 'https://example.com/guide']], $first['usage_guides']);
        $this->assertSame($first, $this->read('delivery', $order->id)['data']);
        $summary = $this->read('show', $order->id)['data'];
        $this->assertSame('USD', $summary['currency']);
        $this->assertSame('4', $summary['amount']);
        $this->assertSame(2, $summary['fulfillment']['released_units']);
        $this->assertSame('complete', $summary['fulfillment']['state']);
        $this->assertStringNotContainsString('password-0', json_encode($summary));
        $this->assertSame($before, $this->economics());
        Http::assertNothingSent();
    }

    public function test_foreign_missing_malformed_and_retail_orders_are_indistinguishable_and_history_is_account_scoped(): void
    {
        $owned = $this->buy();
        $ownedPrincipal = $this->principal;
        $retail = Order::factory()->create(['customer_id' => $this->customer->id]);
        $other = Customer::factory()->create();
        $keys = app(CustomerApiCredentials::class);
        $foreignPrincipal = $keys->authenticate('Bearer '.$keys->generate($other)->takeSecret());
        foreach (['show', 'delivery'] as $method) {
            $failures = [];
            foreach ([$retail->id, (string) Str::uuid(), '1', 'bad-id'] as $id) {
                $failures[] = $this->failure($method, $id);
            }
            $this->principal = $foreignPrincipal;
            $failures[] = $this->failure($method, $owned->id);
            foreach ($failures as $failure) {
                $this->assertSame([404, 'not_found', 'Resource not found.'], $failure);
            }
            $this->assertSame([], $this->read('index')['data']);
            $this->principal = $ownedPrincipal;
        }
    }

    public function test_oversized_delivery_fails_closed_without_changing_the_purchase(): void
    {
        $order = $this->buy();
        ProductStockItem::sole()->update(['secret' => str_repeat('x', 1_048_577)]);
        $before = $this->economics();
        $this->assertSame([503, 'temporarily_unavailable', 'Delivery is temporarily unavailable.'], $this->failure('delivery', $order->id));
        $this->assertSame($before, $this->economics());
        $this->assertSame(1, $this->read('show', $order->id)['data']['fulfillment']['released_units']);
    }

    public function test_unconfirmed_late_review_and_full_refund_never_release_credentials(): void
    {
        $order = $this->buy();
        $payment = $order->payments()->sole();
        $payment->update(['paid_at' => null]);
        $this->assertSame([], $this->read('delivery', $order->id)['data']['units']);
        $payment->update(['paid_at' => now()]);
        $review = ManualFulfillmentReview::create(['order_id' => $order->id, 'payment_id' => $payment->id,
            'amount' => $payment->amount, 'currency' => $payment->currency, 'reason' => 'late_payment', 'state' => 'pending']);
        $this->assertSame('review', $this->read('show', $order->id)['data']['fulfillment']['state']);
        $this->assertSame([], $this->read('delivery', $order->id)['data']['units']);
        $review->update(['state' => 'fulfilled']);
        $this->assertSame('ready', $this->read('delivery', $order->id)['data']['state']);
        app(OrderLifecycle::class)->applyPaymentRefunded($payment);
        $summary = $this->read('show', $order->id)['data'];
        $this->assertSame(['state' => 'refunded', 'currency' => 'USD', 'confirmed_amount' => '2', 'pending_amount' => '0'], $summary['refund']);
        $this->assertSame(0, $summary['fulfillment']['released_units']);
        $this->assertSame([], $this->read('delivery', $order->id)['data']['units']);
        $this->assertNotNull(ProductStockItem::sole()->retired_at);
    }

    public function test_history_is_keyset_bounded_and_relationship_queries_do_not_grow_or_decrypt_credentials(): void
    {
        for ($index = 0; $index < 100; $index++) {
            $this->buy();
        }
        DB::table('product_stock_items')->update(['secret' => 'invalid ciphertext']);
        $counts = [];
        foreach ([1, 20, 100] as $limit) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $page = $this->read('index', query: ['limit' => (string) $limit]);
            $counts[] = count(DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertCount($limit, $page['data']);
            foreach ($page['data'] as $summary) {
                $this->assertSame(1, $summary['fulfillment']['released_units']);
                $this->assertArrayNotHasKey('units', $summary);
            }
        }
        $this->assertSame(min($counts), max($counts), 'Relationship queries must not grow with page size.');
        $first = $this->read('index', query: ['limit' => '20']);
        $next = $this->read('index', query: ['limit' => '100', 'cursor' => $first['pagination']['next_cursor']]);
        $this->assertSame(Order::orderBy('id')->pluck('id')->all(), array_merge(array_column($first['data'], 'id'), array_column($next['data'], 'id')));
        $this->assertFalse($next['pagination']['has_more']);
        $this->assertNull($next['pagination']['next_cursor']);
        $this->assertSame([], $this->read('index', query: ['status' => OrderStatus::PendingPayment->value])['data']);
        $this->assertSame([503, 'temporarily_unavailable', 'Delivery is temporarily unavailable.'], $this->failure('delivery', $first['data'][0]['id']));
    }

    public function test_malformed_filters_are_rejected_without_accessing_results(): void
    {
        foreach ([['limit' => '101'], ['limit' => '0'], ['limit' => ['20']], ['cursor' => 'no'], ['cursor' => ['x']], ['status' => 'paid'], ['status' => ['processing']], ['secret' => 'x']] as $query) {
            $this->assertSame(422, $this->failure('index', query: $query)[0]);
        }
    }

    public function test_valid_maximum_field_purchase_is_recoverable_across_bounded_delivery_pages(): void
    {
        $order = $this->buy(100, structured: true, maximumFields: true);
        $before = $this->economics();
        $ids = ProductStockItem::query()->whereNotNull('order_item_id')->orderBy('id')->pluck('id')->map(strval(...))->all();

        $seen = [];
        $numbers = [];
        $cursor = null;
        $pages = 0;
        $first = null;
        do {
            $query = $cursor === null ? [] : ['cursor' => $cursor];
            $page = $this->read('delivery', $order->id, $query)['data'];
            $pages++;
            $this->assertSame('ready', $page['state']);
            $this->assertSame(100, $page['progress']['ordered_units']);
            $this->assertSame(100, $page['progress']['released_units']);
            $this->assertLessThanOrEqual(1_048_576, strlen(json_encode(['data' => $page], JSON_THROW_ON_ERROR)));
            foreach ($page['units'] as $unit) {
                $this->assertSame(1024, mb_strlen($unit['password']));
                $this->assertSame(3500, mb_strlen($unit['access_value']));
                $this->assertSame(2000, mb_strlen($unit['tutorial']));
                $seen[] = $unit['id'];
                $numbers[] = $unit['number'];
            }
            $first ??= $page;
            $this->assertSame($page['pagination']['has_more'], $page['pagination']['next_cursor'] !== null);
            $cursor = $page['pagination']['next_cursor'];
        } while ($cursor !== null);

        $this->assertGreaterThan(1, $pages, 'The maximum-length fixture must exceed one page.');
        $this->assertCount(100, $seen);
        $this->assertSame($ids, $seen);
        $this->assertSame(range(1, 100), $numbers, 'Logical account numbers must stay stable across pages.');
        $this->assertSame($first['units'], $this->read('delivery', $order->id)['data']['units']);
        $this->assertSame($before, $this->economics());
    }

    public function test_delivery_continuation_rejects_foreign_malformed_cursors_and_invalid_limits(): void
    {
        $order = $this->buy(3, structured: true);
        $first = $this->read('delivery', $order->id, ['limit' => '1'])['data'];
        $this->assertCount(1, $first['units']);
        $this->assertSame(1, $first['units'][0]['number']);
        $this->assertSame('ready', $first['state']);
        $this->assertTrue($first['pagination']['has_more']);
        $repeat = $this->read('delivery', $order->id, ['limit' => '1'])['data'];
        $this->assertSame($first['units'], $repeat['units']);
        $this->assertTrue($repeat['pagination']['has_more']);

        $second = $this->read('delivery', $order->id, ['limit' => '1', 'cursor' => $first['pagination']['next_cursor']])['data'];
        $this->assertCount(1, $second['units']);
        $this->assertSame(2, $second['units'][0]['number']);
        $this->assertNotSame($first['units'][0]['id'], $second['units'][0]['id']);
        $third = $this->read('delivery', $order->id, ['limit' => '1', 'cursor' => $second['pagination']['next_cursor']])['data'];
        $this->assertSame(3, $third['units'][0]['number']);
        $this->assertFalse($third['pagination']['has_more']);
        $this->assertNull($third['pagination']['next_cursor']);

        $other = $this->buy(2, structured: true);
        $foreignCursor = $this->read('delivery', $other->id, ['limit' => '1'])['data']['pagination']['next_cursor'];
        $this->assertNotNull($foreignCursor);
        $before = $this->economics();
        foreach ([
            ['cursor' => 'no'], ['cursor' => '0'], ['cursor' => '9999999999999999999'],
            ['cursor' => ['1']], ['limit' => '0'], ['limit' => '101'],
            ['limit' => ['1']], ['unknown' => 'x'], ['cursor' => $foreignCursor],
        ] as $query) {
            $this->assertSame(
                [422, 'invalid_input', 'The request contains unsupported or malformed inputs.'],
                $this->failure('delivery', $order->id, $query),
            );
        }
        $this->assertSame($before, $this->economics());
    }

    public function test_delivery_continuation_handles_retirement_between_pages_and_an_empty_tail(): void
    {
        $order = $this->buy(3, structured: true);
        $first = $this->read('delivery', $order->id, ['limit' => '2'])['data'];
        $this->assertSame([1, 2], array_column($first['units'], 'number'));
        $cursor = $first['pagination']['next_cursor'];
        $itemId = $order->orderItems()->sole()->id;
        $stocks = ProductStockItem::query()->where('order_item_id', $itemId)->orderBy('id')->get();

        $stocks[1]->update(['retired_at' => now()]);
        $tail = $this->read('delivery', $order->id, ['limit' => '2', 'cursor' => $cursor])['data'];
        $this->assertSame([2], array_column($tail['units'], 'number'));
        $this->assertSame('partial', $tail['state']);
        $this->assertFalse($tail['pagination']['has_more']);

        $stocks[2]->update(['retired_at' => now()]);
        $empty = $this->read('delivery', $order->id, ['limit' => '2', 'cursor' => $cursor])['data'];
        $this->assertSame('partial', $empty['state']);
        $this->assertSame([], $empty['units']);
        $this->assertSame(['next_cursor' => null, 'has_more' => false], $empty['pagination']);
    }

    public function test_delivery_query_count_does_not_grow_with_the_page_size(): void
    {
        $order = $this->buy(100, structured: true);
        $counts = [];
        foreach ([1, 20, 100] as $limit) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->read('delivery', $order->id, ['limit' => (string) $limit]);
            $counts[] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }
        $this->assertSame(min($counts), max($counts), 'Delivery queries must be bounded by the requested chunk.');
    }

    public function test_delivery_continuation_rechecks_entitlement_and_stops_after_revocation(): void
    {
        $other = $this->buy(2, structured: true);
        $foreignCursor = $this->read('delivery', $other->id, ['limit' => '1'])['data']['pagination']['next_cursor'];
        $order = $this->buy(3, structured: true);
        $first = $this->read('delivery', $order->id, ['limit' => '1'])['data'];
        $cursor = $first['pagination']['next_cursor'];
        $this->assertNotNull($cursor);

        app(OrderLifecycle::class)->applyPaymentRefunded($order->payments()->sole());

        // Cursor validation precedes the availability early return: a foreign
        // cursor is still rejected after revocation.
        $this->assertSame(
            [422, 'invalid_input', 'The request contains unsupported or malformed inputs.'],
            $this->failure('delivery', $order->id, ['cursor' => $foreignCursor]),
        );
        $page = $this->read('delivery', $order->id, ['limit' => '1', 'cursor' => $cursor])['data'];
        $this->assertSame('not_ready', $page['state']);
        $this->assertSame([], $page['units']);
        $this->assertSame(['next_cursor' => null, 'has_more' => false], $page['pagination']);
        $this->assertStringNotContainsString('password-0', json_encode($page, JSON_THROW_ON_ERROR));
    }

    private function buy(int $quantity = 1, bool $structured = false, bool $maximumFields = false): Order
    {
        $product = Product::factory()->create(['price_amount' => 15000, 'usdt_price_amount' => 2_000_000,
            'stock' => $quantity, 'reserved_stock' => 0, 'delivery_note' => 'Default tutorial',
            'usage_guide_title' => 'Usage', 'usage_guide_url' => 'https://example.com/guide']);
        for ($index = 0; $index < $quantity; $index++) {
            $payload = $maximumFields
                ? StructuredCredentialPayload::fromValues(
                    'test'.$index.'@example.com',
                    str_repeat('p', 1024),
                    CredentialAccessMethod::AuthToken,
                    str_pad('token-'.$index.'-', 3500, 't'),
                    str_repeat('u', 2000),
                )->toArray()
                : ($structured ? [
                    'version' => 1, 'email' => 'test'.$index.'@example.com', 'password' => 'password-'.$index,
                    'access_method' => 'auth_token', 'access_value' => 'token-'.$index, 'tutorial_override' => 'Per-item tutorial',
                ] : null);
            ProductStockItem::create(['product_id' => $product->id] + ($payload !== null ? [
                'credential_format' => CredentialFormat::StructuredAccount, 'credential_payload' => $payload,
            ] : ['secret' => 'legacy-secret-'.$product->id]));
        }
        $http = Request::create('/api/v1/orders', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'product_id' => (string) $product->id, 'quantity' => $quantity, 'preference' => 'usd_only',
            'expected_totals' => ['USD' => (string) ($quantity * 2)],
        ], JSON_THROW_ON_ERROR));
        $http->headers->set('Idempotency-Key', 'order-'.$product->id);

        return app(CustomerApiOrderCheckout::class)->place($this->principal, CustomerApiOrderRequest::fromRequest($http))->order;
    }

    private function read(string $method, ?string $id = null, array $query = []): array
    {
        $request = Request::create('/api/v1/orders', 'GET', $query);
        $request->attributes->set('customer_api_principal', $this->principal);

        return app()->call([app(CustomerApiOrderReadController::class), $method], ['request' => $request, 'id' => $id])->getData(true);
    }

    private function failure(string $method, ?string $id = null, array $query = []): array
    {
        try {
            $this->read($method, $id, $query);
        } catch (CustomerApiFailure $failure) {
            return [$failure->status, $failure->machineCode, $failure->getMessage()];
        }
        $this->fail('An unsafe result read was accepted.');
    }

    private function economics(): array
    {
        return [DB::table('customer_usdt_balance_entries')->orderBy('id')->get()->toJson(),
            DB::table('product_stock_items')->orderBy('id')->get()->toJson(),
            DB::table('inventory_movements')->orderBy('id')->get()->toJson(),
            DB::table('supplier_purchases')->orderBy('id')->get()->toJson()];
    }
}
