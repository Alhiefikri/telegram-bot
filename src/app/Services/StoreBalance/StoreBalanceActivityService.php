<?php

namespace App\Services\StoreBalance;

use App\Enums\StoreBalanceEntryKind;
use App\Models\Customer;
use App\Models\CustomerBalanceAccount;
use App\Models\Order;
use App\Models\StoreBalanceEntry;
use App\Models\StoreBalanceTopUp;
use App\Services\KlikQris\KlikQrisNativeQrisOutcome;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\KlikQris\KlikQrisTopUpPresentation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class StoreBalanceActivityService
{
    public const PAGE_SIZE = 5;

    private const BALANCE_TOP_UP_SOURCE = 'balance_top_up';

    private const ORDER_SOURCE = 'order';

    /**
     * @var list<string>
     */
    private const TOP_UP_COLUMNS = [
        'id',
        'customer_id',
        'amount',
        'created_at',
    ];

    public function __construct(
        private readonly KlikQrisNativeQrisService $nativeQris,
    ) {}

    public function firstPage(Customer $customer): StoreBalanceActivityPage
    {
        return $this->page($customer, null, false);
    }

    public function olderPage(Customer $customer, StoreBalanceActivityCursor $cursor): ?StoreBalanceActivityPage
    {
        return $this->page($customer, $cursor, false);
    }

    public function newerPage(Customer $customer, StoreBalanceActivityCursor $cursor): ?StoreBalanceActivityPage
    {
        return $this->page($customer, $cursor, true);
    }

    public function detail(Customer $customer, string $type, int $id): ?StoreBalanceActivityDetail
    {
        if ($id < 1) {
            return null;
        }

        if ($type === StoreBalanceActivityCursor::TYPE_TOP_UP) {
            return $this->topUpDetail($customer, $id);
        }

        if ($type !== StoreBalanceActivityCursor::TYPE_ENTRY) {
            return null;
        }

        $accountId = $this->accountId($customer);
        if ($accountId === null) {
            return null;
        }

        $entry = StoreBalanceEntry::query()
            ->select(['id', 'customer_balance_account_id', 'kind', 'amount', 'source_type', 'source_id', 'created_at'])
            ->where('customer_balance_account_id', $accountId)
            ->whereKey($id)
            ->first();
        if ($entry === null) {
            return null;
        }

        $orders = $this->ordersForEntries($customer->id, collect([$entry]));
        $related = $this->originalDebitForEntry($accountId, $entry);
        $relatedOrders = $this->ordersForEntries($customer->id, $related === null ? collect() : collect([$related]));
        $item = $this->entryItem($entry, $orders, $related);
        $relatedItem = $related === null ? null : $this->entryItem($related, $relatedOrders, null);

        return new StoreBalanceActivityDetail($item, $relatedItem);
    }

    private function topUpDetail(Customer $customer, int $id): ?StoreBalanceActivityDetail
    {
        $topUp = StoreBalanceTopUp::query()
            ->select(self::TOP_UP_COLUMNS)
            ->where('customer_id', $customer->id)
            ->whereKey($id)
            ->first();
        if ($topUp === null) {
            return null;
        }

        $accountId = $this->accountId($customer);
        $credit = $accountId === null
            ? null
            : StoreBalanceEntry::query()
                ->select(['id', 'customer_balance_account_id', 'kind', 'amount', 'source_type', 'source_id', 'created_at'])
                ->where('customer_balance_account_id', $accountId)
                ->where('source_type', self::BALANCE_TOP_UP_SOURCE)
                ->where('kind', StoreBalanceEntryKind::TopUpCredit->value)
                ->where('source_id', (string) $topUp->getKey())
                ->first();
        $presentation = $this->nativeQris->topUpPresentation($topUp);

        return new StoreBalanceActivityDetail($this->topUpItem($topUp, $credit, $presentation));
    }

    private function page(
        Customer $customer,
        ?StoreBalanceActivityCursor $cursor,
        bool $newer,
    ): ?StoreBalanceActivityPage {
        if ($cursor !== null && ! $this->cursorExists($customer, $cursor)) {
            return null;
        }

        $accountId = $this->accountId($customer);
        $topUps = $this->topUpCandidates($customer->id, $cursor, $newer);
        $entries = $accountId === null
            ? collect()
            : $this->entryCandidates($customer->id, $accountId, $cursor, $newer);
        $credits = $this->creditEntriesForTopUps($accountId, $topUps);
        $presentations = $this->nativeQris->topUpPresentations($topUps);
        $orders = $this->ordersForEntries($customer->id, $entries);
        $originals = $accountId === null
            ? collect()
            : $this->originalDebitsForEntries($accountId, $entries);

        $items = [];
        foreach ($topUps as $topUp) {
            $creditsByTopUp = $credits->get((string) $topUp->getKey());
            $presentation = $presentations[(string) $topUp->getKey()]
                ?? $this->nativeQris->topUpPresentation($topUp);
            $items[] = $this->topUpItem($topUp, $creditsByTopUp, $presentation);
        }
        foreach ($entries as $entry) {

            $items[] = $this->entryItem($entry, $orders, $originals->get((string) $entry->source_id));
        }

        $this->sortItems($items);
        $hasMoreInDirection = count($items) > self::PAGE_SIZE;
        $visibleItems = $newer
            ? array_slice($items, -self::PAGE_SIZE)
            : array_slice($items, 0, self::PAGE_SIZE);

        if ($visibleItems === []) {
            return new StoreBalanceActivityPage(
                items: [],
                hasOlder: $newer && $cursor !== null,
                hasNewer: ! $newer && $cursor !== null,
                olderCursor: $newer ? $cursor : null,
                newerCursor: ! $newer && $cursor !== null ? $cursor : null,
            );
        }

        $first = $visibleItems[0];
        $last = $visibleItems[array_key_last($visibleItems)];

        return new StoreBalanceActivityPage(
            items: $visibleItems,
            hasOlder: $newer ? $cursor !== null : $hasMoreInDirection,
            hasNewer: $newer ? $hasMoreInDirection : $cursor !== null,
            olderCursor: $newer || $hasMoreInDirection ? $last->cursor() : null,
            newerCursor: $newer && $hasMoreInDirection
                ? $first->cursor()
                : (! $newer && $cursor !== null ? $first->cursor() : null),
        );
    }

    private function cursorExists(Customer $customer, StoreBalanceActivityCursor $cursor): bool
    {
        if ($cursor->type === StoreBalanceActivityCursor::TYPE_TOP_UP) {
            return StoreBalanceTopUp::query()
                ->where('customer_id', $customer->id)
                ->whereKey($cursor->id)
                ->exists();
        }

        $accountId = $this->accountId($customer);
        if ($accountId === null) {
            return false;
        }

        return StoreBalanceEntry::query()
            ->where('customer_balance_account_id', $accountId)
            ->whereKey($cursor->id)
            ->exists();
    }

    private function accountId(Customer $customer): ?int
    {
        $accountId = CustomerBalanceAccount::query()
            ->where('customer_id', $customer->id)
            ->value('id');

        return $accountId === null ? null : (int) $accountId;
    }

    /**
     * @return Collection<int, StoreBalanceTopUp>
     */
    private function topUpCandidates(
        int $customerId,
        ?StoreBalanceActivityCursor $cursor,
        bool $newer,
    ): Collection {
        $query = StoreBalanceTopUp::query()
            ->select(self::TOP_UP_COLUMNS)
            ->where('customer_id', $customerId);
        if ($cursor !== null) {
            $this->applyCursor($query, $cursor, StoreBalanceActivityCursor::TOP_UP_RANK, $newer);
        }

        return $query
            ->orderBy('created_at', $newer ? 'asc' : 'desc')
            ->orderBy('id', $newer ? 'asc' : 'desc')
            ->limit(self::PAGE_SIZE + 1)
            ->get();
    }

    /**
     * @return Collection<int, StoreBalanceEntry>
     */
    private function entryCandidates(
        int $customerId,
        int $accountId,
        ?StoreBalanceActivityCursor $cursor,
        bool $newer,
    ): Collection {
        $query = StoreBalanceEntry::query()
            ->select(['id', 'customer_balance_account_id', 'kind', 'amount', 'source_type', 'source_id', 'created_at'])
            ->where('customer_balance_account_id', $accountId)
            ->where(function (Builder $query) use ($customerId): void {
                $query->where('source_type', '!=', self::BALANCE_TOP_UP_SOURCE)
                    ->orWhere(function (Builder $query) use ($customerId): void {
                        $query->where('source_type', self::BALANCE_TOP_UP_SOURCE)
                            ->whereNotExists(function ($topUps) use ($customerId): void {
                                $topUps->selectRaw('1')
                                    ->from('store_balance_top_ups')
                                    ->where('customer_id', $customerId)
                                    ->whereRaw('CAST(store_balance_top_ups.id AS TEXT) = store_balance_entries.source_id');
                            });
                    });
            });
        if ($cursor !== null) {
            $this->applyCursor($query, $cursor, StoreBalanceActivityCursor::ENTRY_RANK, $newer);
        }

        return $query
            ->orderBy('created_at', $newer ? 'asc' : 'desc')
            ->orderBy('id', $newer ? 'asc' : 'desc')
            ->limit(self::PAGE_SIZE + 1)
            ->get();
    }

    /**
     * @param  Builder<StoreBalanceTopUp|StoreBalanceEntry>  $query
     */
    private function applyCursor(
        Builder $query,
        StoreBalanceActivityCursor $cursor,
        int $sourceRank,
        bool $newer,
    ): void {
        $timestamp = $cursor->occurredAt();
        $timestampOperator = $newer ? '>' : '<';
        $sameTimestampOperator = $newer ? '>' : '<';

        if (($newer && $sourceRank < $cursor->rank()) || (! $newer && $sourceRank > $cursor->rank())) {
            $query->where('created_at', $newer ? '>=' : '<=', $timestamp);

            return;
        }

        if (($newer && $sourceRank > $cursor->rank()) || (! $newer && $sourceRank < $cursor->rank())) {
            $query->where('created_at', $newer ? '>' : '<', $timestamp);

            return;
        }
        $query->where(function (Builder $same) use ($timestamp, $sameTimestampOperator, $cursor): void {
            $same->where('created_at', $sameTimestampOperator, $timestamp)
                ->orWhere(function (Builder $tie) use ($timestamp, $cursor, $sameTimestampOperator): void {
                    $tie->where('created_at', '=', $timestamp)
                        ->where('id', $sameTimestampOperator, $cursor->id);
                });
        });
    }

    /**
     * @param  Collection<int, StoreBalanceTopUp>  $topUps
     * @return Collection<string, StoreBalanceEntry>
     */
    private function creditEntriesForTopUps(?int $accountId, Collection $topUps): Collection
    {
        $ids = $topUps->modelKeys();
        if ($accountId === null || $ids === []) {
            return collect();
        }

        return StoreBalanceEntry::query()
            ->select(['id', 'customer_balance_account_id', 'kind', 'amount', 'source_type', 'source_id', 'created_at'])
            ->where('customer_balance_account_id', $accountId)
            ->where('source_type', self::BALANCE_TOP_UP_SOURCE)
            ->where('kind', StoreBalanceEntryKind::TopUpCredit->value)
            ->whereIn('source_id', array_map('strval', $ids))
            ->get()
            ->keyBy(fn (StoreBalanceEntry $entry): string => (string) $entry->source_id);
    }

    /**
     * @param  Collection<int, StoreBalanceEntry>  $entries
     * @return Collection<string, Order>
     */
    private function ordersForEntries(int $customerId, Collection $entries): Collection
    {
        $ids = $entries
            ->filter(fn (StoreBalanceEntry $entry): bool => $entry->source_type === self::ORDER_SOURCE && $entry->source_id !== '')
            ->map(fn (StoreBalanceEntry $entry): string => (string) $entry->source_id)
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

    /**
     * @param  Collection<int, StoreBalanceEntry>  $entries
     * @return Collection<string, StoreBalanceEntry>
     */
    private function originalDebitsForEntries(int $accountId, Collection $entries): Collection
    {
        $ids = $entries
            ->filter(fn (StoreBalanceEntry $entry): bool => $entry->kind === StoreBalanceEntryKind::RefundCredit
                && $entry->source_type === self::ORDER_SOURCE
                && $entry->source_id !== '')
            ->map(fn (StoreBalanceEntry $entry): string => (string) $entry->source_id)
            ->unique()
            ->values()
            ->all();
        if ($ids === []) {
            return collect();
        }

        return StoreBalanceEntry::query()
            ->select(['id', 'customer_balance_account_id', 'kind', 'amount', 'source_type', 'source_id', 'created_at'])
            ->where('customer_balance_account_id', $accountId)
            ->where('kind', StoreBalanceEntryKind::PurchaseDebit->value)
            ->where('source_type', self::ORDER_SOURCE)
            ->whereIn('source_id', $ids)
            ->get()
            ->keyBy(fn (StoreBalanceEntry $entry): string => (string) $entry->source_id);
    }

    private function originalDebitForEntry(int $accountId, StoreBalanceEntry $entry): ?StoreBalanceEntry
    {
        if ($entry->kind !== StoreBalanceEntryKind::RefundCredit || $entry->source_type !== self::ORDER_SOURCE) {
            return null;
        }

        return StoreBalanceEntry::query()
            ->select(['id', 'customer_balance_account_id', 'kind', 'amount', 'source_type', 'source_id', 'created_at'])
            ->where('customer_balance_account_id', $accountId)
            ->where('kind', StoreBalanceEntryKind::PurchaseDebit->value)
            ->where('source_type', self::ORDER_SOURCE)
            ->where('source_id', (string) $entry->source_id)
            ->first();
    }

    private function topUpItem(
        StoreBalanceTopUp $topUp,
        ?StoreBalanceEntry $credit,
        KlikQrisTopUpPresentation $presentation,
    ): StoreBalanceActivityItem {
        $creditMatchesRequest = $credit !== null
            && $credit->kind === StoreBalanceEntryKind::TopUpCredit
            && (int) $credit->amount === (int) $topUp->amount
            && (int) $credit->amount > 0;
        $state = $this->topUpState($presentation, $creditMatchesRequest);
        $creditedAmount = $creditMatchesRequest ? (int) $credit->amount : null;

        return new StoreBalanceActivityItem(
            type: StoreBalanceActivityItem::TYPE_TOP_UP,
            id: (int) $topUp->getKey(),
            occurredAt: $this->immutable($topUp->created_at),
            kind: StoreBalanceActivityItem::KIND_TOP_UP,
            state: $state,
            amount: $creditedAmount ?? (int) $topUp->amount,
            signedAmount: $creditedAmount,
            reference: '#'.(int) $topUp->getKey(),
            requestedAmount: (int) $topUp->amount,
            creditedAmount: $creditedAmount,
            topUpCanCheck: $presentation->canCheck(),
            topUpCanRetry: $presentation->canRetry(),
        );
    }

    /**
     * @param  Collection<string, Order>  $orders
     */
    private function entryItem(
        StoreBalanceEntry $entry,
        Collection $orders,
        ?StoreBalanceEntry $related,
    ): StoreBalanceActivityItem {
        $kind = match ($entry->kind) {
            StoreBalanceEntryKind::TopUpCredit => StoreBalanceActivityItem::KIND_TOP_UP,
            StoreBalanceEntryKind::PurchaseDebit => StoreBalanceActivityItem::KIND_PURCHASE,
            StoreBalanceEntryKind::RefundCredit => StoreBalanceActivityItem::KIND_REFUND,
        };
        $state = match ($entry->kind) {
            StoreBalanceEntryKind::TopUpCredit => StoreBalanceActivityItem::STATE_CREDITED,
            StoreBalanceEntryKind::PurchaseDebit => StoreBalanceActivityItem::STATE_DEBITED,
            StoreBalanceEntryKind::RefundCredit => StoreBalanceActivityItem::STATE_REFUNDED,
        };
        $amount = (int) $entry->amount;
        $order = $entry->source_type === self::ORDER_SOURCE
            ? $orders->get((string) $entry->source_id)
            : null;

        return new StoreBalanceActivityItem(
            type: StoreBalanceActivityItem::TYPE_ENTRY,
            id: (int) $entry->getKey(),
            occurredAt: $this->immutable($entry->created_at),
            kind: $kind,
            state: $state,
            amount: $amount,
            signedAmount: $amount * $entry->kind->direction(),
            reference: $this->entryReference($entry, $order),
            relatedEntryId: $related?->id,
        );
    }

    private function topUpState(
        KlikQrisTopUpPresentation $presentation,
        bool $creditMatchesRequest,
    ): string {
        if ($presentation->isPaid()) {
            if (! $creditMatchesRequest) {
                return StoreBalanceActivityItem::STATE_REVIEW;
            }

            return $presentation->isRecovered()
                ? StoreBalanceActivityItem::STATE_RECOVERED
                : StoreBalanceActivityItem::STATE_CREDITED;
        }
        if ($presentation->isManualReview()) {
            return StoreBalanceActivityItem::STATE_REVIEW;
        }

        return match ($presentation->outcome()) {
            KlikQrisNativeQrisOutcome::Expired => StoreBalanceActivityItem::STATE_EXPIRED,
            KlikQrisNativeQrisOutcome::InvalidProviderResult => StoreBalanceActivityItem::STATE_REVIEW,
            KlikQrisNativeQrisOutcome::RecoveryPending => StoreBalanceActivityItem::STATE_UNCERTAIN,
            KlikQrisNativeQrisOutcome::Creating => $presentation->canRetry()
                ? StoreBalanceActivityItem::STATE_FAILED
                : StoreBalanceActivityItem::STATE_PENDING,
            KlikQrisNativeQrisOutcome::Payable,
            KlikQrisNativeQrisOutcome::ProviderTotalPending => StoreBalanceActivityItem::STATE_PENDING,
        };
    }

    private function entryReference(StoreBalanceEntry $entry, ?Order $order): string
    {
        if ($order !== null) {
            return '#'.(string) $order->invoice_number;
        }

        return '#'.(int) $entry->getKey();
    }

    /**
     * @param  list<StoreBalanceActivityItem>  $items
     */
    private function sortItems(array &$items): void
    {
        usort($items, static function (StoreBalanceActivityItem $left, StoreBalanceActivityItem $right): int {
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
        if ($date instanceof CarbonInterface) {
            return CarbonImmutable::instance($date);
        }

        return CarbonImmutable::parse((string) $date);
    }
}
