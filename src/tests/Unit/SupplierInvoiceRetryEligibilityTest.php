<?php

namespace Tests\Unit;

use App\Models\SupplierFulfillment;
use App\Models\SupplierManualHandoff;
use App\Models\SupplierPurchase;
use App\Services\Suppliers\SupplierFulfillmentService;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SupplierInvoiceRetryEligibilityTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'nothing dispatched' => [true, ['current_purchase_id' => null], []],
            'definite rejection' => [true, [], [[]]],
            'multiple definite rejections' => [true, [], [['id' => 8], []]],
            'missing current purchase' => [false, [], [['id' => 8]]],
            'unavailable mapping' => [false, ['reserved_catalog_item_id' => null], [[]]],
            'manual takeover' => [false, ['manual_takeover_at' => '2026-01-01 00:00:00'], [[]]],
            'external handoff' => [false, [], [[]], true],
            'still processing' => [false, ['state' => 'pending'], [[]]],
            'already delivered' => [false, ['state' => 'delivered'], [[]]],
            'ambiguous' => [false, [], [['state' => 'ambiguous']]],
            'old ambiguous purchase' => [false, [], [['id' => 8, 'state' => 'ambiguous'], []]],
            'known supplier order' => [false, [], [['supplier_order_id' => 'supplier-order']]],
            'cost evidence even when zero' => [false, [], [['actual_cost_micros' => 0]]],
            'committed debit' => [false, [], [['committed_at' => '2026-01-01 00:00:00']]],
            'active claim token' => [false, [], [['claim_token' => 'active']]],
            'active lease' => [false, [], [['lease_expires_at' => '2026-11-24 12:01:00']]],
            'expired lease on rejected purchase' => [true, [], [['lease_expires_at' => '2026-11-24 11:59:00']]],
        ];
    }

    #[DataProvider('cases')]
    public function test_eligible_supplier_retry_is_offered_only_without_conflicting_evidence(bool $expected, array $attributes, array $purchases, bool $handedOff = false): void
    {
        $this->travelTo(now()->setDate(2026, 11, 24)->setTime(12, 0));
        $fulfillment = (new SupplierFulfillment)->forceFill($attributes + [
            'state' => 'review', 'reserved_catalog_item_id' => 3, 'current_purchase_id' => 9,
        ]);
        $fulfillment->setRelation('purchases', new Collection(array_map(
            fn (array $row) => (new SupplierPurchase)->forceFill($row + ['id' => 9, 'state' => 'rejected']),
            $purchases,
        )));
        $fulfillment->setRelation('manualHandoffs', new Collection($handedOff ? [new SupplierManualHandoff] : []));

        self::assertSame($expected, app(SupplierFulfillmentService::class)->canOfferInvoiceRetry($fulfillment));
    }
}
