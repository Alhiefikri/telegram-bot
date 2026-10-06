<?php

namespace Tests\Support;

use App\Enums\OrderStatus;
use App\Models\SupplierAccountUnit;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\UsdtPurchase;
use App\Services\UsdtBalance\VerifiedUsdtFunding;
use App\Services\Warranty\RefundProof;
use App\Services\Warranty\WarrantyClaimService;
use Illuminate\Support\Str;

trait BuildsUsdtRefundFixture
{
    use BuildsBuyMailWarrantyFixture;

    protected function usdtRefundFixture(bool $missing = false): array
    {
        [$customer, $unit] = $this->fixture();
        $unit->purchase->update(['reference' => (string) Str::uuid()]);
        $order = $unit->order;
        $order->update(['currency' => 'USDT']);
        $item = $unit->orderItem;
        $item->update(['usdt_subtotal' => 2_000_001, 'usdt_unit_price' => 1_000_000]);
        $payment = $order->payments()->sole();
        $payment->forceFill(['provider' => 'usdt_balance', 'method' => 'USDT_BALANCE',
            'currency' => 'USDT', 'amount' => 2_000_001])->save();
        $unit->update(['retail_currency' => 'USDT', 'retail_amount' => 1_000_001]);
        $ledger = app(CustomerUsdtBalanceLedger::class);
        $ledger->creditVerifiedTopUp($customer, new VerifiedUsdtFunding($customer->id,
            UsdtMicroAmount::fromUnits(5_000_000), 'fixture-'.$customer->id, 'receipt-'.$customer->id, 'v1'), 'fixture-credit-'.$customer->id);
        $debit = $ledger->debitPurchase($customer, new UsdtPurchase($customer->id,
            UsdtMicroAmount::fromUnits($payment->amount), $order->id, $payment->id), 'fixture-debit-'.$order->id);
        if ($missing) {
            $order->update(['order_status' => OrderStatus::Processing]);
            $purchase = $unit->purchase;
            $purchase->update(['state' => 'settled', 'observed_quantity' => 1, 'actual_cost_micros' => 4_800_000_000]);
            $purchase->fulfillment->update(['state' => 'review']);

            return [$customer, $unit->fresh(), $payment, $debit, null];
        }
        $stock = $unit->stockItem->replicate();
        $stock->supplier_unit_index = 2;
        $stock->credential_payload = ['version' => 1, 'email' => 'healthy@gmail.com', 'password' => 'synthetic-password',
            'access_method' => 'none', 'access_value' => null, 'tutorial_override' => null];
        $stock->save();
        $second = $unit->replicate();
        $second->forceFill(['public_id' => (string) Str::uuid(), 'product_stock_item_id' => $stock->id,
            'external_id' => 'PR2', 'unit_number' => 2, 'retail_amount' => 1_000_000])->save();
        $claim = $this->approvedUsdtClaim($customer, $unit->fresh());

        return [$customer, $unit->fresh(), $payment, $debit, $claim];
    }

    protected function approvedUsdtClaim($customer, SupplierAccountUnit $unit)
    {
        $proof = RefundProof::fromBytes(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgQAYAAA4AAbGa6gYAAAAASUVORK5CYII='));
        $claim = app(WarrantyClaimService::class)->submit($customer, $unit->public_id, $proof, 'Wrong password');
        $claim->update(['state' => 'approved', 'verified_at' => now(), 'settlement_state' => 'awaiting_choice']);

        return $claim;
    }
}
