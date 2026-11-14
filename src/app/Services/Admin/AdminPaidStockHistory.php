<?php

namespace App\Services\Admin;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Throwable;

final class AdminPaidStockHistory
{
    private const PAGE_SIZE = 25;

    /** @return array{items: Collection, pagination: array{previousUrl: ?string, nextUrl: ?string}} */
    public function page(Product $product, ?string $encodedCursor = null): array
    {
        $cursor = $encodedCursor === null ? null : $this->decodeCursor($product, $encodedCursor);
        $windowEnd = $cursor === null ? CarbonImmutable::now()->startOfSecond() : CarbonImmutable::createFromTimestamp($cursor['window'], config('app.timezone'));
        $base = $this->query([(int) $product->getKey()], $windowEnd);
        $query = clone $base;
        $previous = $cursor !== null && $cursor['previous'];
        if ($cursor !== null) {
            $this->seek($query, $cursor['paid_at'], $cursor['id'], $previous);
        }
        $direction = $previous ? 'asc' : 'desc';
        $items = $query->select('product_stock_items.*', 'paid_history.history_paid_at')
            ->with(['orderItem.order.payments'])
            ->orderBy('paid_history.history_paid_at', $direction)
            ->orderBy('product_stock_items.id', $direction)
            ->limit(self::PAGE_SIZE + 1)
            ->get();
        $hasMore = $items->count() > self::PAGE_SIZE;
        $items = new Collection($items->take(self::PAGE_SIZE)->all());
        if ($previous) {
            $items = new Collection($items->reverse()->values()->all());
        }
        $previousUrl = null;
        $nextUrl = null;
        if ($items->isNotEmpty()) {
            $first = $items->first();
            $last = $items->last();
            $hasPrevious = $previous ? $hasMore : ($cursor !== null && $this->seek(clone $base, $first->history_paid_at, (int) $first->id, true)->exists());
            $hasNext = ! $previous ? $hasMore : $this->seek(clone $base, $last->history_paid_at, (int) $last->id, false)->exists();
            $previousUrl = $hasPrevious ? $this->url($product, $windowEnd, $first, true) : null;
            $nextUrl = $hasNext ? $this->url($product, $windowEnd, $last, false) : null;
        }

        return ['items' => $items, 'pagination' => ['previousUrl' => $previousUrl, 'nextUrl' => $nextUrl]];
    }

    /** @return array<int, array{items: Collection, pagination: array{previousUrl: ?string, nextUrl: ?string}}> */
    public function firstPages(Collection $products): array
    {
        if ($products->isEmpty()) {
            return [];
        }
        if ($products->count() === 1) {
            $product = $products->first();

            return [(int) $product->id => $this->page($product)];
        }

        $windowEnd = CarbonImmutable::now()->startOfSecond();
        $items = $this->query($products->modelKeys(), $windowEnd)
            ->select('product_stock_items.*', 'paid_history.history_paid_at')
            ->with(['orderItem.order.payments'])
            ->orderByDesc('paid_history.history_paid_at')
            ->orderByDesc('product_stock_items.id')
            ->groupLimit(self::PAGE_SIZE + 1, 'product_stock_items.product_id')
            ->get()
            ->groupBy('product_id');
        $pages = [];
        foreach ($products as $product) {
            $rows = $items->get($product->id, new Collection);
            $pageItems = new Collection($rows->take(self::PAGE_SIZE)->all());
            $pages[(int) $product->id] = [
                'items' => $pageItems,
                'pagination' => [
                    'previousUrl' => null,
                    'nextUrl' => $rows->count() > self::PAGE_SIZE
                        ? $this->url($product, $windowEnd, $pageItems->last(), false)
                        : null,
                ],
            ];
        }

        return $pages;
    }

    /** @param list<int> $productIds */
    private function query(array $productIds, CarbonImmutable $windowEnd): Builder
    {
        $windowStart = $windowEnd->subDays(30);
        // Discard pre-window payments before aggregation, but not post-window payments:
        // a newer successful payment must still exclude its Order from this fixed window.
        // Undated legacy PAID records remain in global totals without a synthetic date.
        $payments = Payment::query()
            ->select('order_id')
            ->selectRaw('MAX(paid_at) AS history_paid_at')
            ->where('status', PaymentStatus::Paid->value)
            ->where('paid_at', '>=', $windowStart->toDateTimeString())
            ->whereIn('order_id', function ($query) use ($productIds): void {
                $query->select('order_items.order_id')->from('order_items')
                    ->join('product_stock_items', 'product_stock_items.order_item_id', '=', 'order_items.id')
                    ->whereIn('product_stock_items.product_id', $productIds)
                    ->whereNull('product_stock_items.retired_at');
            })
            ->groupBy('order_id');

        return ProductStockItem::query()
            ->join('order_items', 'product_stock_items.order_item_id', '=', 'order_items.id')
            ->joinSub($payments, 'paid_history', 'paid_history.order_id', '=', 'order_items.order_id')
            ->whereIn('product_stock_items.product_id', $productIds)
            ->whereNull('product_stock_items.retired_at')
            ->whereBetween('paid_history.history_paid_at', [$windowStart->toDateTimeString(), $windowEnd->toDateTimeString()]);
    }

    private function seek(Builder $query, string $paidAt, int $id, bool $previous): Builder
    {
        $operator = $previous ? '>' : '<';

        return $query->where(function ($query) use ($paidAt, $id, $operator): void {
            $query->where('paid_history.history_paid_at', $operator, $paidAt)
                ->orWhere(function ($query) use ($paidAt, $id, $operator): void {
                    $query->where('paid_history.history_paid_at', $paidAt)
                        ->where('product_stock_items.id', $operator, $id);
                });
        });
    }

    private function url(Product $product, CarbonImmutable $windowEnd, ProductStockItem $item, bool $previous): string
    {
        return route('admin.products.stock-items.index', [
            'product' => $product,
            'paid_only' => 1,
            'paid_cursor' => Crypt::encryptString(json_encode([
                'product' => (int) $product->getKey(),
                'window' => $windowEnd->getTimestamp(),
                'paid_at' => $item->history_paid_at,
                'id' => (int) $item->id,
                'previous' => $previous,
            ], JSON_THROW_ON_ERROR)),
        ]);
    }

    /** @return array{product: int, window: int, paid_at: string, id: int, previous: bool} */
    private function decodeCursor(Product $product, string $encodedCursor): array
    {
        try {
            $cursor = json_decode(Crypt::decryptString($encodedCursor), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($cursor)
                || ($cursor['product'] ?? null) !== (int) $product->getKey()
                || ! is_int($cursor['window'] ?? null)
                || $cursor['window'] <= 0
                || $cursor['window'] > CarbonImmutable::now()->getTimestamp()
                || ! is_string($cursor['paid_at'] ?? null)
                || ! is_int($cursor['id'] ?? null)
                || $cursor['id'] <= 0
                || ! is_bool($cursor['previous'] ?? null)) {
                throw new \InvalidArgumentException('Invalid paid history cursor.');
            }
            $paidAt = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $cursor['paid_at'], config('app.timezone'));
            $windowEnd = CarbonImmutable::createFromTimestamp($cursor['window'], config('app.timezone'));
            if ($paidAt === false || $paidAt->toDateTimeString() !== $cursor['paid_at']
                || $paidAt->lessThan($windowEnd->subDays(30)) || $paidAt->greaterThan($windowEnd)) {
                throw new \InvalidArgumentException('Invalid paid history window.');
            }

            return $cursor;
        } catch (Throwable) {
            throw ValidationException::withMessages(['paid_cursor' => 'Navigasi riwayat PAID tidak valid. Muat ulang Kelola akun.']);
        }
    }
}
