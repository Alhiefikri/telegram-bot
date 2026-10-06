<?php

namespace App\Services\UsdtBalance;

use App\Enums\UsdtBalanceEntryKind;
use App\Models\Customer;
use App\Models\CustomerUsdtBalanceAccount;
use App\Models\CustomerUsdtBalanceEntry;
use App\Models\CustomerUsdtTopUp;
use App\Models\Order;
use App\Services\UsdtTopUps\UsdtTopUpStatusReader;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Bounded, keyset-paginated read model for one Customer's USDT balance
 * history. Targets that have not reached a terminal resolution and immutable
 * ledger entries are merged into one ordered stream; the page size defaults to
 * 25 and is rejected above 100. Nothing here mutates money.
 */
final class CustomerUsdtActivityService
{
    public const DEFAULT_PAGE_SIZE = 25;

    public const MAX_PAGE_SIZE = 100;

    private const ORDER_SOURCE = 'order';

    private const REFUND_SOURCE = 'order_refund';

    private const TOP_UP_SOURCE = 'customer_usdt_top_up';

    /** @var list<string> */
    private const TARGET_COLUMNS = [
        'id',
        'intent_id',
        'customer_id',
        'reference',
        'amount_micro',
        'fee_micro',
        'payable_micro',
        'expires_at',
        'created_at',
        'payment_snapshot',
    ];

    /** @var list<string> */
    private const ENTRY_COLUMNS = [
        'id',
        'customer_usdt_balance_account_id',
        'kind',
        'amount_micro',
        'source_type',
        'source_id',
        'refund_order_id',
        'created_at',
    ];

    public function __construct(private readonly UsdtTopUpStatusReader $statuses) {}

    public function firstPage(Customer $customer, int $limit = self::DEFAULT_PAGE_SIZE): CustomerUsdtActivityPage
    {
        return $this->page($customer, null, false, $limit)
            ?? new CustomerUsdtActivityPage([], false, false, null, null);
    }

    public function olderPage(
        Customer $customer,
        CustomerUsdtActivityCursor $cursor,
        int $limit = self::DEFAULT_PAGE_SIZE,
    ): ?CustomerUsdtActivityPage {
        return $this->page($customer, $cursor, false, $limit);
    }

    public function newerPage(
        Customer $customer,
        CustomerUsdtActivityCursor $cursor,
        int $limit = self::DEFAULT_PAGE_SIZE,
    ): ?CustomerUsdtActivityPage {
        return $this->page($customer, $cursor, true, $limit);
    }

    private function page(
        Customer $customer,
        ?CustomerUsdtActivityCursor $cursor,
        bool $newer,
        int $limit,
    ): ?CustomerUsdtActivityPage {
        $limit = $this->limit($limit);
        if ($cursor !== null && ! $this->cursorExists($customer, $cursor)) {
            return null;
        }

        $targets = $this->targetCandidates($customer->id, $cursor, $newer, $limit);
        $accountId = $this->accountId($customer);
        $entries = $accountId === null
            ? collect()
            : $this->entryCandidates($accountId, $cursor, $newer, $limit);

        $statuses = $this->statuses->forMany($targets);
        $orders = $this->ordersForEntries($customer->id, $entries);

        $items = [];
        foreach ($targets as $target) {
            $status = $statuses[(string) $target->id] ?? null;
            if ($status === null) {
                continue;
            }
            $items[] = new CustomerUsdtActivityItem(
                type: CustomerUsdtActivityItem::TYPE_TARGET,
                cursorId: (int) $target->intent_id,
                targetId: (string) $target->id,
                occurredAt: $this->immutable($target->created_at),
                kind: CustomerUsdtActivityItem::KIND_TOP_UP,
                state: $status->state,
                amountMicro: (int) $target->amount_micro,
                signedMicro: null,
                reference: (string) $target->reference,
            );
        }
        foreach ($entries as $entry) {
            $items[] = $this->entryItem($entry, $orders->get($this->orderIdForEntry($entry)));
        }

        $this->sortItems($items);
        $hasMoreInDirection = count($items) > $limit;
        // sortItems always yields newest-first, so both walks take the $limit
        // rows closest to the cursor: the top for a descending walk and the
        // bottom for an ascending one. A page never skips the region between
        // itself and its cursor.
        $visibleItems = $newer
            ? array_slice($items, -$limit)
            : array_slice($items, 0, $limit);

        if ($visibleItems === []) {
            return new CustomerUsdtActivityPage(
                items: [],
                hasOlder: $newer && $cursor !== null,
                hasNewer: ! $newer && $cursor !== null,
                olderCursor: $newer ? $cursor : null,
                newerCursor: ! $newer && $cursor !== null ? $cursor : null,
            );
        }

        $first = $visibleItems[0];
        $last = $visibleItems[array_key_last($visibleItems)];

        return new CustomerUsdtActivityPage(
            items: $visibleItems,
            hasOlder: $newer ? $cursor !== null : $hasMoreInDirection,
            hasNewer: $newer ? $hasMoreInDirection : $cursor !== null,
            olderCursor: $newer ? ($cursor !== null ? $last->cursor() : null) : ($hasMoreInDirection ? $last->cursor() : null),
            newerCursor: $newer
                ? ($hasMoreInDirection ? $first->cursor() : null)
                : ($cursor !== null ? $first->cursor() : null),
        );
    }

    private function limit(int $limit): int
    {
        if ($limit < 1 || $limit > self::MAX_PAGE_SIZE) {
            throw new InvalidArgumentException('USDT history page size must be between 1 and 100.');
        }

        return $limit;
    }

    private function cursorExists(Customer $customer, CustomerUsdtActivityCursor $cursor): bool
    {
        if ($cursor->type === CustomerUsdtActivityCursor::TYPE_TARGET) {
            return CustomerUsdtTopUp::query()
                ->where('customer_id', $customer->id)
                ->where('intent_id', $cursor->id)
                ->exists();
        }

        $accountId = $this->accountId($customer);
        if ($accountId === null) {
            return false;
        }

        return CustomerUsdtBalanceEntry::query()
            ->where('customer_usdt_balance_account_id', $accountId)
            ->whereKey($cursor->id)
            ->exists();
    }

    private function accountId(Customer $customer): ?int
    {
        $accountId = CustomerUsdtBalanceAccount::query()
            ->where('customer_id', $customer->id)
            ->value('id');

        return $accountId === null ? null : (int) $accountId;
    }

    /**
     * @return Collection<int, CustomerUsdtTopUp>
     */
    private function targetCandidates(
        int $customerId,
        ?CustomerUsdtActivityCursor $cursor,
        bool $newer,
        int $limit,
    ): Collection {
        $query = CustomerUsdtTopUp::query()
            ->select(self::TARGET_COLUMNS)
            ->where('customer_id', $customerId)
            ->whereNotExists(function ($resolutions): void {
                $resolutions->selectRaw('1')
                    ->from('customer_usdt_top_up_resolutions')
                    ->whereColumn('customer_usdt_top_up_resolutions.top_up_id', 'customer_usdt_top_ups.id');
            });
        if ($cursor !== null) {
            $this->applyCursor($query, $cursor, CustomerUsdtActivityCursor::TARGET_RANK, $newer, 'intent_id');
        }

        return $query
            ->orderBy('created_at', $newer ? 'asc' : 'desc')
            ->orderBy('intent_id', $newer ? 'asc' : 'desc')
            ->limit($limit + 1)
            ->get();
    }

    /**
     * @return Collection<int, CustomerUsdtBalanceEntry>
     */
    private function entryCandidates(
        int $accountId,
        ?CustomerUsdtActivityCursor $cursor,
        bool $newer,
        int $limit,
    ): Collection {
        $query = CustomerUsdtBalanceEntry::query()
            ->select(self::ENTRY_COLUMNS)
            ->where('customer_usdt_balance_account_id', $accountId);
        if ($cursor !== null) {
            $this->applyCursor($query, $cursor, CustomerUsdtActivityCursor::ENTRY_RANK, $newer, 'id');
        }

        return $query
            ->orderBy('created_at', $newer ? 'asc' : 'desc')
            ->orderBy('id', $newer ? 'asc' : 'desc')
            ->limit($limit + 1)
            ->get();
    }

    /**
     * Rows are ordered by (created_at, rank, id) descending. A page strictly
     * beyond the cursor therefore depends on how this source's rank compares
     * with the cursor's rank: rows of a lower rank may share the cursor
     * timestamp and still belong to the page, while rows of a higher rank may
     * not.
     *
     * @param  Builder<CustomerUsdtTopUp|CustomerUsdtBalanceEntry>  $query
     */
    private function applyCursor(
        Builder $query,
        CustomerUsdtActivityCursor $cursor,
        int $sourceRank,
        bool $newer,
        string $idColumn,
    ): void {
        $timestamp = $cursor->occurredAt();
        $operator = $newer ? '>' : '<';

        if ($newer) {
            if ($sourceRank > $cursor->rank()) {
                $query->where('created_at', '>=', $timestamp);

                return;
            }
            if ($sourceRank < $cursor->rank()) {
                $query->where('created_at', '>', $timestamp);

                return;
            }
        } else {
            if ($sourceRank < $cursor->rank()) {
                $query->where('created_at', '<=', $timestamp);

                return;
            }
            if ($sourceRank > $cursor->rank()) {
                $query->where('created_at', '<', $timestamp);

                return;
            }
        }

        $query->where(function (Builder $same) use ($timestamp, $operator, $cursor, $idColumn): void {
            $same->where('created_at', $operator, $timestamp)
                ->orWhere(function (Builder $tie) use ($timestamp, $operator, $cursor, $idColumn): void {
                    $tie->where('created_at', '=', $timestamp)
                        ->where($idColumn, $operator, $cursor->id);
                });
        });
    }

    /**
     * @param  Collection<int, CustomerUsdtBalanceEntry>  $entries
     * @return Collection<string, Order>
     */
    private function ordersForEntries(int $customerId, Collection $entries): Collection
    {
        $ids = $entries
            ->map(fn (CustomerUsdtBalanceEntry $entry): ?string => $this->orderIdForEntry($entry))
            ->filter()
            ->unique()
            ->values()
            ->all();
        if ($ids === []) {
            return collect();
        }

        return Order::query()
            ->select(['id', 'customer_id', 'invoice_number'])
            ->where('customer_id', $customerId)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy(fn (Order $order): string => (string) $order->getKey());
    }

    private function entryItem(CustomerUsdtBalanceEntry $entry, ?Order $order): CustomerUsdtActivityItem
    {
        $kind = $entry->kind;
        $amount = (int) $entry->amount_micro;

        return new CustomerUsdtActivityItem(
            type: CustomerUsdtActivityItem::TYPE_ENTRY,
            cursorId: (int) $entry->getKey(),
            targetId: null,
            occurredAt: $this->immutable($entry->created_at),
            kind: $kind->value,
            state: match ($kind) {
                UsdtBalanceEntryKind::FundingCredit => CustomerUsdtActivityItem::STATE_CREDITED,
                UsdtBalanceEntryKind::PurchaseDebit => CustomerUsdtActivityItem::STATE_DEBITED,
                UsdtBalanceEntryKind::RefundCredit => CustomerUsdtActivityItem::STATE_REFUNDED,
                UsdtBalanceEntryKind::ReversalDebit => CustomerUsdtActivityItem::STATE_REVERSED,
            },
            amountMicro: $amount,
            signedMicro: $amount * $kind->direction(),
            reference: $this->entryReference($entry, $order),
        );
    }

    private function entryReference(CustomerUsdtBalanceEntry $entry, ?Order $order): string
    {
        if ($entry->source_type === self::TOP_UP_SOURCE && $entry->source_id !== '') {
            return (string) $entry->source_id;
        }
        if ($order !== null) {
            return '#'.(string) $order->invoice_number;
        }

        return '#'.(int) $entry->getKey();
    }

    private function orderIdForEntry(CustomerUsdtBalanceEntry $entry): ?string
    {
        if ($entry->source_type === self::ORDER_SOURCE && $entry->source_id !== '') {
            return (string) $entry->source_id;
        }

        return $entry->source_type === self::REFUND_SOURCE && $entry->refund_order_id !== null
            ? (string) $entry->refund_order_id
            : null;
    }

    /**
     * @param  list<CustomerUsdtActivityItem>  $items
     */
    private function sortItems(array &$items): void
    {
        usort($items, static function (CustomerUsdtActivityItem $left, CustomerUsdtActivityItem $right): int {
            $leftCursor = $left->cursor();
            $rightCursor = $right->cursor();

            if ($leftCursor->occurredAtMicros !== $rightCursor->occurredAtMicros) {
                return $rightCursor->occurredAtMicros <=> $leftCursor->occurredAtMicros;
            }
            if ($leftCursor->rank() !== $rightCursor->rank()) {
                return $rightCursor->rank() <=> $leftCursor->rank();
            }

            return $rightCursor->id <=> $leftCursor->id;
        });
    }

    private function immutable(CarbonInterface|string|null $date): CarbonImmutable
    {
        return $date instanceof CarbonInterface
            ? CarbonImmutable::instance($date)
            : CarbonImmutable::parse((string) $date);
    }
}
