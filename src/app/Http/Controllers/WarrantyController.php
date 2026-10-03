<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidOrderTransition;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\SupplierAccountUnit;
use App\Models\SupplierFulfillment;
use App\Models\WarrantyClaim;
use App\Services\Credentials\CredentialDeliveryEligibility;
use App\Services\Telegram\TelegramLocale;
use App\Services\Telegram\TelegramSupportContact;
use App\Services\Warranty\MissingFulfillmentRefundService;
use App\Services\Warranty\RefundProof;
use App\Services\Warranty\WarrantyAccountService;
use App\Services\Warranty\WarrantyClaimService;
use App\Services\Warranty\WarrantyOrderAccess;
use App\Services\Warranty\WarrantySettlementService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

final class WarrantyController extends Controller
{
    public function show(Request $request, Order $order, WarrantyOrderAccess $access)
    {
        $access->authorize($request, $order, allowSignedGrant: true);
        $units = SupplierAccountUnit::query()->where('order_id', $order->id)->with(['stockItem', 'orderItem'])->orderBy('unit_number')->orderBy('id')->paginate(50)->appends(['locale' => $access->locale($request, $order)]);
        $claims = WarrantyClaim::query()->whereIn('supplier_account_unit_id', $units->pluck('id'))->get()->keyBy('supplier_account_unit_id');

        return response()->view('warranty.show', [
            'order' => $order, 'units' => $units, 'claims' => $claims, 'locale' => $access->locale($request, $order),
            'supportUrl' => app(TelegramSupportContact::class)->resolve()['url'] ?? null,
            'canRevealCredentials' => app(CredentialDeliveryEligibility::class)->allows($order, $order->customer),
            'missingFulfillments' => SupplierFulfillment::query()->where('order_id', $order->id)->where('state', 'review')->with('orderItem')->get(),
            'missingRefunds' => OrderRefund::where('order_id', $order->id)->whereNotNull('supplier_fulfillment_id')->get(),
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function claim(Request $request, Order $order, WarrantyOrderAccess $access, WarrantyClaimService $claims)
    {
        $access->authorize($request, $order);
        $valid = Validator::make($request->only(['unit_id', 'note']), ['unit_id' => ['required', 'uuid'], 'note' => ['nullable', 'string', 'max:500']]);
        $file = $request->file('proof');
        if ($valid->fails() || $file === null || ! $file->isValid() || $file->getSize() > RefundProof::MAX_BYTES) {
            return $this->unavailable($request, $order, $access);
        }
        $unit = SupplierAccountUnit::query()->where('order_id', $order->id)->where('public_id', $request->input('unit_id'))->firstOrFail();
        try {
            $claims->submit($order->customer, $unit->public_id, RefundProof::fromBytes($file->getContent()), (string) $request->input('note', ''));
        } catch (InvalidOrderTransition|InvalidArgumentException|ModelNotFoundException) {
            return $this->unavailable($request, $order, $access);
        }

        return redirect()->route('warranty.show', $order)->with('status', app(TelegramLocale::class)->translateLocale($access->locale($request, $order), 'order.warranty.accepted'));
    }

    public function choose(Request $request, Order $order, WarrantyOrderAccess $access, WarrantySettlementService $settlements)
    {
        $access->authorize($request, $order);
        $claim = WarrantyClaim::query()->where('order_id', $order->id)->where('public_id', $request->input('claim_id'))->firstOrFail();
        try {
            $settlements->choose($order->customer, $claim->public_id, (string) $request->input('choice'));
        } catch (InvalidOrderTransition|InvalidArgumentException|ModelNotFoundException) {
            return $this->unavailable($request, $order, $access);
        }

        return redirect()->route('warranty.show', $order);
    }

    public function confirm(Request $request, Order $order, WarrantyOrderAccess $access, WarrantyAccountService $accounts)
    {
        $access->authorize($request, $order);
        $validator = Validator::make($request->only(['unit_id', 'closes_refund_window']), ['unit_id' => ['required', 'uuid'], 'closes_refund_window' => ['required', 'accepted']]);
        if ($validator->fails()) {
            return $this->unavailable($request, $order, $access);
        }
        $unit = SupplierAccountUnit::query()->where('order_id', $order->id)->where('public_id', $request->input('unit_id'))->firstOrFail();
        try {
            $confirmed = $accounts->confirm($order->customer, $unit->public_id);
        } catch (InvalidOrderTransition|InvalidArgumentException|ModelNotFoundException) {
            return $this->unavailable($request, $order, $access);
        }

        return redirect()->route('warranty.show', $order)->with('status', app(TelegramLocale::class)->translateLocale($access->locale($request, $order), $confirmed->confirmation_state === 'confirmed' ? 'order.warranty.confirmed' : 'order.warranty.confirmation_pending'));
    }

    public function refundMissing(Request $request, Order $order, WarrantyOrderAccess $access, MissingFulfillmentRefundService $refunds)
    {
        $access->authorize($request, $order);
        $item = $order->orderItems()->whereKey($request->input('order_item_id'))->firstOrFail();
        try {
            $refunds->request($order->customer, $item->id);
        } catch (InvalidOrderTransition|InvalidArgumentException) {
            return $this->unavailable($request, $order, $access);
        }

        return redirect()->route('warranty.show', $order);
    }

    private function unavailable(Request $request, Order $order, WarrantyOrderAccess $access)
    {
        // Notes/files are never flashed into session old input.
        return redirect()->route('warranty.show', $order)->withErrors(['claim' => app(TelegramLocale::class)->translateLocale($access->locale($request, $order), 'order.warranty.unavailable')]);
    }
}
