<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Services\Suppliers\BuyMailClient;
use App\Services\Suppliers\SupplierClient;
use App\Services\Suppliers\SupplierOrderResult;
use App\Services\Warranty\RefundProof;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class BuyMailClientTest extends TestCase
{
    private function supplier(): Supplier
    {
        return (new Supplier)->forceFill([
            'id' => 321, 'provider' => 'buymail', 'api_key' => 'fixture-buymail-key',
            'currency' => 'IDR', 'exchange_rate_idr' => 1, 'currency_confirmed' => true, 'is_active' => true,
        ]);
    }

    public function test_profile_is_a_read_only_idr_catalog_without_inventing_a_provider_sku(): void
    {
        Http::fake(function ($request, $options) {
            $this->assertSame('GET', $request->method());
            $this->assertSame('https://buymail.simuru-store.com/api/reseller/profile', $request->url());
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer fixture-buymail-key'));
            $this->assertFalse($request->hasHeader('X-API-Key'));
            $this->assertFalse($options['allow_redirects']);

            return Http::response([
                'id' => '123456789', 'currency' => 'IDR', 'balance' => 250000,
                'price_per_account' => 4800, 'stock_available' => 412,
                'refund_window_minutes' => 120, 'refunds_enabled' => true,
                'limits' => ['max_quantity_per_order' => 50, 'requests_per_minute' => 120],
            ]);
        });

        $snapshot = app(SupplierClient::class)->snapshot($this->supplier());
        $this->assertSame('IDR', $snapshot->currency);
        $this->assertSame(250_000_000_000, $snapshot->balanceMicros);
        $this->assertCount(1, $snapshot->products);
        $this->assertSame(4_800_000_000, $snapshot->products[0]->unitPriceMicros);
        $this->assertSame(412, $snapshot->products[0]->stock);
        Http::assertSentCount(1);
    }

    public function test_purchase_retains_account_identity_and_deadline_with_a_stable_reference_and_key(): void
    {
        Http::fake(function ($request, $options) {
            $this->assertSame('POST', $request->method());
            $this->assertSame('https://buymail.simuru-store.com/api/reseller/orders', $request->url());
            $this->assertSame(['quantity' => 2, 'reference' => 'local-intent-1'], $request->data());
            $this->assertTrue($request->hasHeader('Idempotency-Key', 'local-intent-1'));
            $this->assertGreaterThanOrEqual(90, $options['timeout']);

            return Http::response($this->order(), 201);
        });
        $supplier = $this->supplier();
        $item = (new SupplierCatalogItem)->forceFill(['supplier_id' => 321, 'external_id' => 'gmail', 'orderable' => true, 'requires_input' => false]);
        $result = app(SupplierClient::class)->purchase($supplier, $item, 2, 'local-intent-1');
        $this->assertSame('delivered', $result->outcome);
        $this->assertSame('ORD1', $result->orderId);
        $this->assertSame(9_600_000_000, $result->costMicros);
        $this->assertTrue($result->identityTrusted);
        $this->assertTrue($result->deliveryTrusted);
        $this->assertSame('PR1', $result->accountUnits[0]['id']);
        $this->assertSame('2026-10-03T12:00:00Z', $result->accountUnits[0]['refund_deadline']);
        Http::assertSentCount(1);
    }

    private function order(): array
    {
        return ['order' => [
            'id' => 'ORD1', 'reference' => 'local-intent-1', 'status' => 'completed',
            'quantity_requested' => 2, 'quantity_delivered' => 2, 'unit_price' => 4800,
            'amount_charged' => 9600, 'currency' => 'IDR',
            'accounts' => array_map(fn ($i) => [
                'id' => 'PR'.$i, 'order_id' => 'ORD1', 'email' => 'fixture'.$i.'@gmail.com',
                'password' => 'synthetic-password-'.$i, 'status' => 'active', 'price' => 4800,
                'refund_deadline' => '2026-10-03T12:00:00Z', 'refund' => null,
            ], [1, 2]),
        ], 'balance' => 240400];
    }

    public function test_partial_result_reports_the_actual_debit_and_only_the_delivered_units(): void
    {
        $payload = $this->order();
        $payload['order']['status'] = 'partial';
        $payload['order']['quantity_delivered'] = 1;
        $payload['order']['amount_charged'] = 4800;
        $payload['order']['accounts'] = [$payload['order']['accounts'][0]];
        Http::fake(fn () => Http::response($payload, 201));
        $result = $this->purchase();
        $this->assertSame('partial', $result->outcome);
        $this->assertSame(1, $result->quantity);
        $this->assertSame(2, $result->requestedQuantity);
        $this->assertSame(4_800_000_000, $result->costMicros);
        $this->assertCount(1, $result->accountUnits);
    }

    public static function invalidOrders(): array
    {
        return [
            'wrong reference' => ['reference', 'another-buyer'],
            'wrong currency' => ['currency', 'USD'],
            'wrong charge' => ['amount_charged', 9500],
            'decimal charge' => ['amount_charged', 9600.5],
            'wrong requested count' => ['quantity_requested', 3],
            'missing units' => ['accounts', []],
            'unknown state' => ['status', 'success'],
            'partial marked complete' => ['quantity_delivered', 1],
            'oversized count' => ['quantity_requested', 51],
        ];
    }

    #[DataProvider('invalidOrders')]
    public function test_untrusted_order_payload_never_authorizes_delivery_or_a_new_purchase(string $field, mixed $value): void
    {
        $payload = $this->order();
        $payload['order'][$field] = $value;
        Http::fake(fn () => Http::response($payload, 201));
        $result = $this->purchase();
        $this->assertSame('ambiguous', $result->outcome);
        $this->assertFalse($result->identityTrusted);
        $this->assertFalse($result->deliveryTrusted);
        $this->assertSame([], $result->deliveries);
        Http::assertSentCount(1);
    }

    public function test_only_a_documented_error_code_establishes_a_rejected_purchase(): void
    {
        Http::fake(fn () => Http::response(['error' => ['code' => 'purchase_failed', 'message' => 'private']], 500));
        $this->assertSame('rejected', $this->purchase()->outcome);
    }

    private function purchase(): SupplierOrderResult
    {
        $item = (new SupplierCatalogItem)->forceFill(['supplier_id' => 321, 'external_id' => 'gmail', 'orderable' => true, 'requires_input' => false]);

        return app(SupplierClient::class)->purchase($this->supplier(), $item, 2, 'local-intent-1');
    }

    public function test_reference_recovery_reuses_the_original_order_without_any_post(): void
    {
        Http::fake(function ($request) {
            $this->assertSame('GET', $request->method());
            $this->assertStringContainsString('/orders?', $request->url());
            $this->assertSame('local-intent-1', $request['reference']);

            return Http::response(['orders' => [$this->order()['order']]]);
        });
        $result = app(BuyMailClient::class)->recoverPurchase($this->supplier(), 2, 'local-intent-1', now()->subMinute());
        $this->assertSame('delivered', $result->outcome);
        $this->assertSame('ORD1', $result->orderId);
        Http::assertSentCount(1);
    }

    public function test_refund_upload_uses_original_png_and_does_not_treat_accepted_as_approved(): void
    {
        $proof = RefundProof::fromBytes(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgQAYAAA4AAbGa6gYAAAAASUVORK5CYII='));
        Http::fake(function ($request) use ($proof) {
            $this->assertSame('https://buymail.simuru-store.com/api/reseller/accounts/PR1/refund', $request->url());
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer fixture-buymail-key'));
            $this->assertTrue($request->isMultipart());
            $this->assertTrue($request->hasFile('proof', $proof->bytes));

            return Http::response(['account' => [
                'id' => 'PR1', 'order_id' => 'ORD1', 'status' => 'refund_pending', 'price' => 4800,
                'refund_deadline' => '2026-10-03T12:00:00Z', 'refund' => ['status' => 'pending'],
            ]], 202);
        });
        $account = app(BuyMailClient::class)->requestRefund($this->supplier(), 'PR1', 'ORD1', $proof, 'Wrong password');
        $this->assertSame('pending', $account['refund_status']);
        $this->assertSame('refund_pending', $account['status']);
        Http::assertSentCount(1);
    }

    public function test_supplier_deposits_have_their_own_idempotent_instruction_and_read_cancel_contract(): void
    {
        Http::fake(function ($request) {
            $row = ['id' => 'TOPUP1', 'amount' => 100000, 'currency' => 'IDR', 'status' => 'pending', 'payment_url' => 'https://payments.example.test/topup1', 'expires_at' => '2026-10-03T12:00:00Z'];
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/deposits')) {
                $this->assertSame(['amount' => 100000], $request->data());
                $this->assertTrue($request->hasHeader('Idempotency-Key', 'deposit-intent-1'));
            }
            if (str_ends_with($request->url(), '/cancel')) {
                $row['status'] = 'cancelled';
            }

            return Http::response(str_contains($request->url(), '/deposits?') ? ['deposits' => [$row]] : ['deposit' => $row]);
        });
        $client = app(BuyMailClient::class);
        $deposit = $client->createDeposit($this->supplier(), 100000, 'deposit-intent-1');
        $this->assertSame('TOPUP1', $deposit['id']);
        $this->assertSame(100000, $deposit['amount']);
        $this->assertSame('pending', $client->deposit($this->supplier(), 'TOPUP1')['status']);
        $this->assertCount(1, $client->deposits($this->supplier()));
        $this->assertSame('cancelled', $client->cancelDeposit($this->supplier(), 'TOPUP1')['status']);
        Http::assertSentCount(4);
    }

    public function test_rate_limit_honors_retry_after_before_sending_another_request(): void
    {
        Http::fake(fn () => Http::response(['error' => ['code' => 'rate_limited']], 429, ['Retry-After' => '120']));
        $this->assertSame('rejected', $this->purchase()->outcome);
        $this->assertSame('rate_limited', $this->purchase()->errorCode);
        Http::assertSentCount(1);
    }

    public function test_account_with_a_refund_in_progress_retains_economic_evidence_but_cannot_be_delivered(): void
    {
        $payload = $this->order();
        $payload['order']['accounts'][0]['status'] = 'refund_pending';
        Http::fake(fn () => Http::response($payload, 201));
        $result = $this->purchase();
        $this->assertTrue($result->identityTrusted);
        $this->assertSame(9_600_000_000, $result->costMicros);
        $this->assertFalse($result->deliveryTrusted);
        $this->assertSame('ambiguous', $result->outcome);
    }

    public function test_disabled_supplier_refunds_cannot_advertise_new_warranty_backed_purchases(): void
    {
        Http::fake(fn () => Http::response([
            'id' => '123', 'currency' => 'IDR', 'balance' => 250000, 'price_per_account' => 4800,
            'stock_available' => 412, 'refund_window_minutes' => 120, 'refunds_enabled' => false,
            'limits' => ['max_quantity_per_order' => 50],
        ]));
        $snapshot = app(SupplierClient::class)->snapshot($this->supplier());
        $this->assertFalse($snapshot->products[0]->orderable);
        $this->assertSame(412, $snapshot->products[0]->stock);
        $this->assertSame(250_000_000_000, $snapshot->balanceMicros);
    }

    public function test_reference_recovery_after_key_rotation_never_reposts_an_unknown_original_purchase(): void
    {
        Http::fake(function ($request) {
            $this->assertSame('GET', $request->method());

            return Http::response(['orders' => []]);
        });
        $result = app(BuyMailClient::class)->recoverPurchase($this->supplier(), 2, 'local-intent-1', now()->subMinute(), allowReplay: false);
        $this->assertSame('ambiguous', $result->outcome);
        Http::assertSentCount(1);
    }
}
