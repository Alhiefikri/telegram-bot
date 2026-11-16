<?php

namespace Tests\Feature;

use App\Enums\BalancePaymentPreference;
use App\Http\Controllers\CustomerApiOrderController;
use App\Models\Customer;
use App\Models\CustomerApiOrderClaim;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Services\CustomerApi\CustomerApiCredentials;
use App\Services\CustomerApi\CustomerApiFailure;
use App\Services\CustomerApi\CustomerApiPrincipal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class CustomerApiConsentBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private CustomerApiPrincipal $principal;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        $customer = Customer::factory()->create();
        $customer->forceFill(['balance_payment_preference' => BalancePaymentPreference::UsdFirst])->save();
        $credentials = app(CustomerApiCredentials::class);
        $this->principal = $credentials->authenticate('Bearer '.$credentials->generate($customer)->takeSecret());
        $this->product = Product::factory()->create(['price_amount' => 15_000, 'usdt_price_amount' => 2_000_000, 'stock' => 1, 'reserved_stock' => 0]);
        ProductStockItem::create(['product_id' => $this->product->id, 'secret' => 'consent-test-stock']);
    }

    public function test_explicit_invalid_totals_are_rejected_before_claiming(): void
    {
        foreach ([null, new \stdClass, ['IDR' => null], ['USD' => null], ['IDR' => ''], ['USD' => ''], ['USD' => '2.0000000'], ['USD' => '0.0000010']] as $index => $totals) {
            $failure = $this->failure(['expected_totals' => $totals], 'invalid-'.$index);
            $this->assertSame(422, $failure->status);
            $this->assertSame('invalid_input', $failure->machineCode);
        }

        $this->assertSame(0, CustomerApiOrderClaim::count());
        $this->assertSame(0, Order::count());
    }

    public function test_explicit_null_preference_is_rejected_before_claiming(): void
    {
        $failure = $this->failure(['preference' => null, 'expected_totals' => ['USD' => '2']], 'null-preference');

        $this->assertSame(422, $failure->status);
        $this->assertSame('invalid_input', $failure->machineCode);
        $this->assertSame(0, CustomerApiOrderClaim::count());
    }

    public function test_valid_six_decimal_total_reaches_checkout_and_omitted_totals_require_consent(): void
    {
        $valid = $this->failure(['preference' => 'usd_only', 'expected_totals' => ['USD' => '2.000000']], 'valid-total');
        $this->assertSame('insufficient_funds', $valid->machineCode);

        $missing = $this->failure(['preference' => 'usd_first'], 'omitted-totals');
        $this->assertSame(422, $missing->status);
        $this->assertSame('invalid_input', $missing->machineCode);
        $this->assertSame(0, Order::count());
    }

    public function test_stale_secondary_is_reported_before_missing_primary_for_both_preferences(): void
    {
        foreach ([
            ['preference' => 'usd_first', 'expected_totals' => ['IDR' => '16000']],
            ['preference' => 'idr_first', 'expected_totals' => ['USD' => '3']],
        ] as $index => $body) {
            $failure = $this->failure($body, 'stale-secondary-'.$index);
            $this->assertSame(409, $failure->status);
            $this->assertSame('price_changed', $failure->machineCode);
            $this->assertSame([['currency' => 'IDR', 'total' => '15000'], ['currency' => 'USD', 'total' => '2']], $failure->details['quotes']);
        }

        $this->assertSame(0, Order::count());
    }

    private function failure(array $body, string $key): CustomerApiFailure
    {
        $body += ['product_id' => (string) $this->product->id, 'quantity' => 1];
        $request = Request::create('/api/v1/orders', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
        $request->headers->set('Idempotency-Key', $key);
        $request->attributes->set('customer_api_principal', $this->principal);
        $request->attributes->set('customer_api_request_id', 'consent-test');

        try {
            app()->call([app(CustomerApiOrderController::class), 'store'], ['request' => $request]);
        } catch (CustomerApiFailure $failure) {
            return $failure;
        }

        $this->fail('An unsafe API order request was accepted.');
    }
}
