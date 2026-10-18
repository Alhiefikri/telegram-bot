<?php

namespace App\Services\CustomerApi;

use App\Enums\PaymentStatus;
use App\Models\ManualFulfillmentReview;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRefund;
use App\Models\ProductStockItem;
use App\Models\SupplierFulfillment;
use App\Services\Credentials\CredentialDeliveryEligibility;
use App\Services\Credentials\CredentialDeliveryPresentation;
use App\Services\UsdtBalance\UsdtMicroAmount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CustomerApiOrders
{
    public const DELIVERY_PAGE_DEFAULT = 100;

    public const DELIVERY_PAGE_MAX = 100;

    private const MAX_ORDER_UNITS = 100;

    /** Leave headroom below the controller's hard 1 MiB response guard. */
    private const DELIVERY_RESPONSE_BUDGET_BYTES = 1_047_552;

    public function __construct(private CredentialDeliveryEligibility $eligibility, private CredentialDeliveryPresentation $presentation) {}

    public function page(CustomerApiPrincipal $principal, int $limit, ?string $cursor, ?string $status): array
    {
        $orders = $this->query($principal)
            ->when($cursor !== null, fn ($q) => $q->where('id', '>', $cursor))
            ->when($status !== null, fn ($q) => $q->where('order_status', $status))
            ->orderBy('id')->limit($limit + 1)->get();
        $more = $orders->count() > $limit;
        $orders = new Collection($orders->take($limit)->all());

        return ['data' => $this->summaries($orders), 'pagination' => [
            'next_cursor' => $more ? (string) $orders->last()->id : null, 'has_more' => $more,
        ]];
    }

    public function detail(CustomerApiPrincipal $principal, string $id): array
    {
        return ['data' => $this->summaries(new Collection([$this->owned($principal, $id)]))[0]];
    }

    public function delivery(CustomerApiPrincipal $principal, string $id, int $limit = self::DELIVERY_PAGE_DEFAULT, ?string $cursor = null): array
    {
        // Refund/warranty/fulfillment writers take the Order exclusively. Keep one
        // release decision and its credential projection together, without network IO.
        if ($limit < 1 || $limit > self::DELIVERY_PAGE_MAX) {
            throw new CustomerApiFailure(422, 'invalid_input', 'The request contains unsupported or malformed inputs.');
        }

        return DB::transaction(function () use ($principal, $id, $limit, $cursor): array {
            $order = $this->owned($principal, $id, true);
            $summary = $this->summaries(new Collection([$order]))[0];
            $result = ['id' => (string) $order->id, 'state' => 'not_ready',
                'progress' => $summary['fulfillment'], 'units' => [], 'usage_guides' => [],
                'pagination' => ['next_cursor' => null, 'has_more' => false]];
            $item = $order->orderItems->count() === 1 ? $order->orderItems->first() : null;
            // Continuation input is validated before availability so a foreign or
            // forged cursor never receives a success-shaped response.
            $cursorId = null;
            if ($cursor !== null) {
                $cursorId = $this->decodeDeliveryCursor($cursor);
                if ($item === null || ! $this->ownsDeliveryUnit($item, $cursorId)) {
                    throw new CustomerApiFailure(422, 'invalid_input', 'The request contains unsupported or malformed inputs.');
                }
            }
            // Eligibility is re-evaluated on every request, so a continuation cannot
            // outlive a refund, review, retirement or supplier release revocation.
            if (! $summary['delivery']['available']) {
                return ['data' => $result];
            }
            if ($item === null || $summary['quantity'] < 1 || $summary['quantity'] > self::MAX_ORDER_UNITS) {
                throw new CustomerApiFailure(503, 'temporarily_unavailable', 'Delivery is temporarily unavailable.');
            }
            $released = (int) $summary['fulfillment']['released_units'];
            if ($released > $summary['quantity']) {
                throw new CustomerApiFailure(503, 'temporarily_unavailable', 'Delivery is temporarily unavailable.');
            }
            // Traverse the immutable allocated-unit identity in ascending order. One
            // extra row is a look-ahead only: it decides whether another page exists
            // and is never decrypted or projected as part of this page.
            $stocks = ProductStockItem::query()->where('order_item_id', $item->id)->whereNull('retired_at')
                ->when($cursorId !== null, fn ($query) => $query->where('id', '>', $cursorId))
                ->orderBy('id')->limit($limit + 1)
                ->with('supplierAccountUnit:id,product_stock_item_id,unit_number')->get();
            if ($stocks->isEmpty()) {
                $result['state'] = $released === $summary['quantity'] ? 'ready' : 'partial';

                return ['data' => $result];
            }
            $pageStocks = $stocks->take($limit)->values();
            // Keep the logical account number stable across pages.
            $accountOffset = $cursorId === null ? 0 : ProductStockItem::query()
                ->where('order_item_id', $item->id)->whereNull('retired_at')->where('id', '<=', $cursorId)->count();
            $item->setRelation('stockItems', $pageStocks);
            $delivery = $this->presentation->forOrder($order, null, $accountOffset);
            if ($delivery->accountCount() !== $pageStocks->count() || $delivery->isEmpty()) {
                throw new CustomerApiFailure(503, 'temporarily_unavailable', 'Delivery is temporarily unavailable.');
            }
            $accounts = $delivery->groups()[0]->accounts();
            $result['usage_guides'] = $delivery->usageGuides();

            // Size each unit with the exact JSON the response will carry, including
            // maximum-length fields and escaping, before adding it to the page.
            $size = $this->encodedSize($result);
            $lastIncluded = null;
            foreach ($pageStocks as $index => $stock) {
                if (count($result['units']) >= $limit) {
                    break;
                }
                $account = $accounts[$index];
                $unit = [
                    'id' => (string) $stock->id, 'product_id' => (string) $item->product_id,
                    'number' => $account->number(), 'format' => $account->isLegacy() ? 'legacy' : 'structured_account',
                    'text' => $account->text(), 'email' => $account->email(), 'password' => $account->password(),
                    'access_method' => $account->accessMethod()?->value, 'access_value' => $account->accessValue(),
                    'reader_mail_link' => $account->readerMailLink(), 'tutorial' => $account->tutorial(),
                ];
                $unitSize = $this->encodedSize($unit) + (count($result['units']) === 0 ? 0 : 1);
                if ($size + $unitSize > self::DELIVERY_RESPONSE_BUDGET_BYTES) {
                    if ($result['units'] === []) {
                        // A single unit cannot be returned within the safe bound.
                        throw new CustomerApiFailure(503, 'temporarily_unavailable', 'Delivery is temporarily unavailable.');
                    }
                    break;
                }
                $result['units'][] = $unit;
                $size += $unitSize;
                $lastIncluded = (string) $stock->id;
            }
            // Whole-Order progress/state stay independent of page continuation.
            $result['state'] = $released === $summary['quantity'] ? 'ready' : 'partial';
            if (count($result['units']) < $stocks->count()) {
                $result['pagination'] = ['next_cursor' => $this->encodeDeliveryCursor((string) $lastIncluded), 'has_more' => true];
            }

            return ['data' => $result];
        });
    }

    private function ownsDeliveryUnit(OrderItem $item, string $unitId): bool
    {
        return ProductStockItem::query()->where('order_item_id', $item->id)->whereKey($unitId)->exists();
    }

    private function decodeDeliveryCursor(string $cursor): string
    {
        try {
            $encoded = strtr($cursor, '-_', '+/');
            $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
            $unitId = Crypt::decryptString($encoded);
        } catch (Throwable) {
            throw new CustomerApiFailure(422, 'invalid_input', 'The request contains unsupported or malformed inputs.');
        }
        if (preg_match('/\A[1-9][0-9]{0,17}\z/D', $unitId) !== 1) {
            throw new CustomerApiFailure(422, 'invalid_input', 'The request contains unsupported or malformed inputs.');
        }

        return $unitId;
    }

    private function encodeDeliveryCursor(string $unitId): string
    {
        try {
            return rtrim(strtr(Crypt::encryptString($unitId), '+/', '-_'), '=');
        } catch (Throwable) {
            throw new CustomerApiFailure(503, 'temporarily_unavailable', 'Delivery is temporarily unavailable.');
        }
    }

    /** @param array<string, mixed> $value */
    private function encodedSize(array $value): int
    {
        try {
            return strlen(json_encode($value, JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            throw new CustomerApiFailure(503, 'temporarily_unavailable', 'Delivery is temporarily unavailable.');
        }
    }

    private function query(CustomerApiPrincipal $principal): Builder
    {
        return Order::query()->select(['id', 'customer_id', 'invoice_number', 'order_status', 'payment_status', 'currency', 'created_at'])
            ->where('customer_id', $principal->customerId)->whereNotNull('api_order_claim_id');
    }

    private function owned(CustomerApiPrincipal $principal, string $id, bool $lock = false): Order
    {
        $order = $this->query($principal)->when($lock, fn ($q) => $q->sharedLock())->find($id);
        if ($order === null) {
            throw new CustomerApiFailure(404, 'not_found', 'Resource not found.');
        }

        return $order;
    }

    /** @param Collection<int, Order> $orders */
    private function summaries(Collection $orders): array
    {
        if ($orders->isEmpty()) {
            return [];
        }
        $orders->loadMissing(['payments:id,order_id,status,currency,amount,paid_at,created_at',
            'orderItems:id,order_id,product_id,product_name,quantity,delivery_note,delivery_note_snapshotted,usage_guide_title,usage_guide_url,usage_guide_snapshotted']);
        $ids = $orders->modelKeys();
        $reasons = $this->eligibility->rejectionReasons($orders);
        $units = DB::table('product_stock_items')->join('order_items', 'order_items.id', '=', 'product_stock_items.order_item_id')
            ->whereIn('order_items.order_id', $ids)->groupBy('order_items.order_id')
            ->selectRaw('order_items.order_id, COUNT(*) AS allocated, SUM(CASE WHEN retired_at IS NULL THEN 1 ELSE 0 END) AS active')->get()->keyBy('order_id');
        $fulfillments = SupplierFulfillment::query()->whereIn('order_id', $ids)->get(['order_id', 'state'])->groupBy('order_id');
        $reviews = ManualFulfillmentReview::query()->whereIn('order_id', $ids)->open()->pluck('order_id')->flip();
        $refunds = OrderRefund::query()->whereIn('order_id', $ids)
            ->get(['order_id', 'amount', 'currency', 'state'])->groupBy('order_id');

        return $orders->map(function (Order $order) use ($units, $fulfillments, $reviews, $refunds, $reasons): array {
            $payment = $order->payments->sortByDesc(fn ($p) => [$p->created_at?->getTimestamp() ?? 0, (string) $p->id])->first();
            $currency = $payment?->currency ?? $order->currency;
            $amount = $payment === null ? null : (int) $payment->amount;
            $quantity = (int) $order->orderItems->sum('quantity');
            $allocated = (int) ($units[$order->id]->allocated ?? 0);
            $released = $reasons[$order->id] === null ? (int) ($units[$order->id]->active ?? 0) : 0;
            $rows = ($refunds[$order->id] ?? collect())->where('currency', $currency);
            $confirmed = $order->payment_status === PaymentStatus::Refunded ? $amount : (int) $rows->where('state', 'confirmed')->sum('amount');
            $pending = (int) $rows->whereIn('state', ['requested', 'uncertain'])->sum('amount');
            $refundState = $order->payment_status === PaymentStatus::Refunded ? 'refunded' : ($pending > 0 ? 'pending' : ($confirmed > 0 ? 'partial' : 'none'));
            $processing = ($fulfillments[$order->id] ?? collect())->contains(fn ($f) => ! in_array($f->state, ['delivered', 'cancelled'], true));
            $review = $reviews->has($order->id);
            $state = match (true) {
                $refundState === 'refunded' => 'refunded',
                $review => 'review',
                $released > 0 && $released < $quantity => 'partial',
                $quantity > 0 && $released === $quantity => 'complete',
                $processing => 'processing',
                default => 'not_ready',
            };

            return [
                'id' => (string) $order->id, 'invoice_number' => $order->invoice_number, 'origin' => 'api',
                'status' => $order->order_status->value, 'payment_status' => $order->payment_status->value,
                'currency' => $currency === 'USDT' ? 'USD' : $currency, 'amount' => $this->money($amount, $currency),
                'quantity' => $quantity, 'items' => $order->orderItems->sortBy('id')->map(fn ($item) => [
                    'product_id' => (string) $item->product_id, 'name' => $item->product_name, 'quantity' => (int) $item->quantity,
                ])->values()->all(),
                'fulfillment' => ['state' => $state, 'ordered_units' => $quantity, 'allocated_units' => $allocated,
                    'released_units' => $released, 'processing' => $processing, 'review_required' => $review],
                'delivery' => ['available' => $released > 0, 'state' => $released === 0 ? 'not_ready' : ($released === $quantity ? 'ready' : 'partial')],
                'refund' => ['state' => $refundState, 'currency' => $currency === 'USDT' ? 'USD' : $currency,
                    'confirmed_amount' => $this->money($confirmed, $currency), 'pending_amount' => $this->money($pending, $currency)],
                'created_at' => $order->created_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    private function money(?int $amount, string $currency): ?string
    {
        return $amount === null ? null : ($currency === 'USDT' ? UsdtMicroAmount::formatUnits($amount) : (string) $amount);
    }
}
