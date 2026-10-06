<?php

namespace App\Services\UsdtBalance;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UsdtBalanceEntryKind;
use App\Exceptions\InvalidOrderTransition;
use App\Models\Customer;
use App\Models\CustomerUsdtBalanceAccount;
use App\Models\CustomerUsdtBalanceEntry;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\Payment;
use App\Models\SupplierFulfillment;
use App\Models\WarrantyClaim;
use App\Services\Suppliers\SupplierFulfillmentService;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\Warranty\MissingFulfillmentRefundService;
use App\Services\Warranty\RefundAllocation;
use App\Services\Warranty\WarrantyClaimService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Original currency/debit authority; callers acquire account before Order resources. */
final class UsdtOrderRefundService
{
    public function full(Customer $customer, Order $order, Payment $payment): UsdtBalanceMutationResult
    {
        return DB::transaction(function () use ($customer, $order, $payment): UsdtBalanceMutationResult {
            $ledger = app(CustomerUsdtBalanceLedger::class);
            $account = $ledger->lockForTransaction($customer);
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $payments = Payment::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
            $payment = $payments->firstWhere('id', $payment->id);
            if ($payment === null) {
                throw new InvalidOrderTransition('Refund Payment is not attached to the Order.');
            }
            app(SupplierFulfillmentService::class)->assertRefundable($order);
            $original = $this->original($account, $order, $payment);
            $this->assertRegisteredRefunds($account, $original, $payment);
            $existing = CustomerUsdtBalanceEntry::query()->where('original_entry_id', $original->id)
                ->where('kind', UsdtBalanceEntryKind::RefundCredit)->where('source_type', 'order')->first();
            $refunded = (int) CustomerUsdtBalanceEntry::query()->where('original_entry_id', $original->id)
                ->where('kind', UsdtBalanceEntryKind::RefundCredit)->sum('amount_micro');
            $remaining = (int) $original->amount_micro - $refunded;
            if ($existing !== null) {
                $remaining = (int) $existing->amount_micro;
            } elseif ($remaining === 0) {
                return $this->withReceipt(UsdtBalanceMutationResult::replayed(CustomerUsdtBalanceEntry::query()
                    ->where('original_entry_id', $original->id)->where('kind', UsdtBalanceEntryKind::RefundCredit)->latest('id')->firstOrFail()));
            }

            return $this->withReceipt($ledger->refundEntitlement($customer, new UsdtRefund($customer->id,
                UsdtMicroAmount::fromUnits($remaining), (string) $order->id, (string) $order->id,
                $original->id, (string) $payment->id, fullOrder: true), 'order:'.$order->id.':usdt-refund'));
        }, 5);
    }

    public function partial(Customer $customer, OrderRefund $refund): UsdtBalanceMutationResult
    {
        return DB::transaction(function () use ($customer, $refund): UsdtBalanceMutationResult {
            $ledger = app(CustomerUsdtBalanceLedger::class);
            $account = $ledger->lockForTransaction($customer);
            $original = CustomerUsdtBalanceEntry::query()->where('customer_usdt_balance_account_id', $account->id)
                ->where('source_type', 'order')->where('source_id', $refund->order_id)
                ->where('kind', UsdtBalanceEntryKind::PurchaseDebit)->firstOrFail();

            return $this->withReceipt($ledger->refundEntitlement($customer, new UsdtRefund($customer->id,
                UsdtMicroAmount::fromUnits($refund->amount), (string) $refund->id, $refund->order_id,
                $original->id, $refund->payment_id), 'order-refund:'.$refund->id));
        }, 5);
    }

    public function validate(CustomerUsdtBalanceAccount $account, UsdtRefund $source): void
    {
        $order = Order::query()->whereKey($source->orderId)->lockForUpdate()->firstOrFail();
        $payments = Payment::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
        $payment = $payments->firstWhere('id', $source->paymentId);
        if ($payment === null) {
            throw new InvalidArgumentException('USDT refund requires its original Payment.');
        }
        app(SupplierFulfillmentService::class)->assertRefundable($order);
        $original = $this->original($account, $order, $payment);
        if ((int) $original->id !== $source->originalEntryId) {
            throw new InvalidArgumentException('USDT refund names a foreign original debit.');
        }
        if ($payment->status !== PaymentStatus::Paid || $order->payment_status !== PaymentStatus::Paid
            || ! in_array($order->order_status, [OrderStatus::Processing, OrderStatus::Completed], true)) {
            throw new InvalidArgumentException('USDT refund requires a paid Order.');
        }
        $refunded = (int) CustomerUsdtBalanceEntry::query()->where('original_entry_id', $original->id)
            ->where('kind', UsdtBalanceEntryKind::RefundCredit)->sum('amount_micro');
        $remaining = (int) $original->amount_micro - $refunded;
        if ($source->amountMicro() > $remaining) {
            throw new InvalidArgumentException('USDT refund exceeds the remaining original purchase debit.');
        }
        if ($source->fullOrder) {
            $this->assertRegisteredRefunds($account, $original, $payment);
            if ($source->refundId !== $order->id || $source->amountMicro() !== $remaining) {
                throw new InvalidArgumentException('Full USDT refund must credit exactly the original debit remainder.');
            }

            return;
        }
        $initial = OrderRefund::query()->findOrFail($source->refundId);
        $authorized = false;
        if ($initial->warranty_claim_id !== null && $initial->supplier_fulfillment_id === null) {
            [$claim, $claimOrder, $unit] = app(WarrantyClaimService::class)->lockForSettlement(WarrantyClaim::findOrFail($initial->warranty_claim_id));
            $item = $unit->orderItem;
            $authorized = $claimOrder->id === $order->id && $claim->order_id === $order->id
                && (int) $claim->customer_id === (int) $account->customer_id
                && $claim->state === 'approved' && $claim->verified_at !== null && $claim->settlement_choice === 'refund'
                && $claim->settlement_state === 'refund_pending' && $unit->order_id === $order->id
                && (int) $unit->id === (int) $initial->supplier_account_unit_id
                && $unit->retail_currency === 'USDT' && $unit->retail_amount === $source->amountMicro()
                && $item->order_id === $order->id && (int) $item->id === (int) $unit->order_item_id
                && $item->usdt_subtotal !== null && $unit->unit_number > 0 && $unit->unit_number <= $item->quantity
                && RefundAllocation::unit($item->usdt_subtotal, $item->quantity, $unit->unit_number) === $source->amountMicro();
        } elseif ($initial->supplier_fulfillment_id !== null && $initial->warranty_claim_id === null && $initial->supplier_account_unit_id === null) {
            $f = SupplierFulfillment::query()->whereKey($initial->supplier_fulfillment_id)->lockForUpdate()->firstOrFail();
            $authorized = $f->order_id === $order->id && $f->orderItem->order_id === $order->id
                && $f->state === 'refund_pending' && $f->reserved_quantity === 0
                && (int) $initial->missing_quantity === $f->quantity - MissingFulfillmentRefundService::acquired($f)
                && $initial->amount === MissingFulfillmentRefundService::entitlement($f, 'USDT');
        }
        $refund = OrderRefund::query()->whereKey($initial->id)->lockForUpdate()->firstOrFail();
        if (! $authorized || $refund->order_id !== $order->id || $refund->payment_id !== $payment->id
            || (int) $refund->customer_id !== (int) $account->customer_id || $refund->currency !== 'USDT'
            || $refund->amount !== $source->amountMicro() || $refund->state !== 'requested'
            || $refund->external_reference_hash !== null) {
            throw new InvalidArgumentException('USDT partial refund requires its verified original-currency entitlement.');
        }
    }

    private function original(CustomerUsdtBalanceAccount $account, Order $order, Payment $payment): CustomerUsdtBalanceEntry
    {
        if ((int) $order->customer_id !== (int) $account->customer_id || $order->currency !== 'USDT'
            || $payment->provider !== 'usdt_balance' || $payment->method !== 'USDT_BALANCE'
            || $payment->currency !== 'USDT' || $payment->amount <= 0 || $payment->paid_at === null) {
            throw new InvalidArgumentException('USDT refund does not match the original Customer, currency and Payment.');
        }
        $original = CustomerUsdtBalanceEntry::query()->where('customer_usdt_balance_account_id', $account->id)
            ->where('source_type', 'order')->where('source_id', $order->id)
            ->where('kind', UsdtBalanceEntryKind::PurchaseDebit)->first();
        $purchase = new UsdtPurchase((int) $account->customer_id, UsdtMicroAmount::fromUnits($payment->amount), $order->id, $payment->id);
        if ($original === null || $original->amount_micro !== $payment->amount
            || $original->request_fingerprint !== $purchase->fingerprint()) {
            throw new InvalidArgumentException('USDT refund requires the unchanged original purchase debit and Payment.');
        }

        return $original;
    }

    private function assertRegisteredRefunds(CustomerUsdtBalanceAccount $account, CustomerUsdtBalanceEntry $original, Payment $payment): void
    {
        $refunds = OrderRefund::query()->where('payment_id', $payment->id)->orderBy('id')->lockForUpdate()->get();
        $entries = CustomerUsdtBalanceEntry::query()->where('original_entry_id', $original->id)
            ->where('kind', UsdtBalanceEntryKind::RefundCredit)->where('source_type', 'order_refund')->get()->keyBy('source_id');
        foreach ($refunds as $refund) {
            $entry = $entries->get($refund->id);
            if ($refund->state !== 'confirmed' || $refund->external_reference_hash !== null || $entry === null
                || $refund->currency !== 'USDT' || (int) $refund->customer_id !== (int) $account->customer_id
                || $refund->order_id !== $payment->order_id || $entry->refund_order_id !== $payment->order_id
                || $entry->amount_micro !== $refund->amount) {
                throw new InvalidArgumentException('Resolve registered refund entitlements before the full USDT refund.');
            }
        }
    }

    private function withReceipt(UsdtBalanceMutationResult $result): UsdtBalanceMutationResult
    {
        $entry = CustomerUsdtBalanceEntry::findOrFail($result->entryId);
        $effect = app(TelegramOutboundEffectService::class)->recordUsdtRefundReceipt($entry);
        if ($effect !== null) {
            app(TelegramOutboundEffectDispatcher::class)->dispatch($effect);
        }

        return $result;
    }
}
