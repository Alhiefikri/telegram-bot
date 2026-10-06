<?php

namespace App\Services\StoreBalance;

use App\Enums\StoreBalanceEntryKind;
use App\Enums\StoreBalanceTopUpOutcome;
use App\Enums\StoreBalanceTopUpStatus;
use App\Models\Customer;
use App\Models\CustomerBalanceAccount;
use App\Models\Order;
use App\Models\StoreBalanceEntry;
use App\Models\StoreBalanceTopUp;
use App\Services\StoreBalance\TopUp\StoreBalanceTopUpPresentation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class StoreBalanceActivityService
{
    public const PAGE_SIZE = 5;

    private const BALANCE_TOP_UP_SOURCE = 'balance_top_up';

    private const OWNER_COMPENSATION_SOURCE = 'owner_compensation';

    private const ORDER_SOURCE = 'order';

    /**
     * @var list<string>
     */
    private const TOP_UP_COLUMNS = [
        'id',
        'customer_id',
        'provider',
        'provider_payment_method',
        'provider_create_state',
        'provider_expires_at',
        'expires_at',
        'amount',
        'status',
        'created_at',
    ];

    public function __construct(
        private readonly StoreBalanceTopUpService $topUps,
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
            ->select(['id', 'customer_balance_account_id', 'kind', 'amount', 'source_type', 'source_id', 'refund_order_id', 'created_at'])
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
                ->select(['id', 'customer_balance_account_id', 'kind', 'amount', 'source_type', 'source_id', 'refund_order_id', 'created_at'])
                ->where('customer_balance_account_id', $accountId)
                ->whereIn('kind', [
                    StoreBalanceEntryKind::TopUpCredit->value,
                    StoreBalanceEntryKind::OwnerCompensationCredit->value,
                ])
                ->where(function (Builder $query) use ($topUp): void {
                    $query
                        ->where(function (Builder $query) use ($topUp): void {
                            $query
                                ->where('source_type', self::BALANCE_TOP_UP_SOURCE)
                                ->where('source_id', (string) $topUp->getKey());
                        })
                        ->orWhere(function (Builder $query) use ($topUp): void {
                            $query
                                ->where('source_type', self::OWNER_COMPENSATION_SOURCE)
                                ->where('source_id', 'top_up:'.$topUp->getKey());
                        });
                })
                ->first();
        $presentation = $this->topUps->presentation($topUp);

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
        $orders = $this->ordersForEntries($customer->id, $entries);
        $originals = $accountId === null
            ? collect()
            : $this->originalDebitsForEntries($accountId, $entries);

        $items = [];
        foreach ($topUps as $topUp) {
            $creditsByTopUp = $credits->get((string) $topUp->getKey());
            $items[] = $this->topUpItem($topUp, $creditsByTopUp, $this->topUps->presentation($topUp));
        }
        foreach ($entries as $entry) {

            $items[] = $this->entryItem($entry, $orders, $originals->get((string) $this->orderIdForEntry($entry)));
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
            ->select(['id', 'customer_balance_account_id', 'kind', 'amount', 'source_type', 'source_id', 'refund_order_id', 'created_at'])
            ->where('customer_balance_account_id', $accountId)
            ->where(function (Builder $query) use ($customerId): void {
                $query->whereNotIn('source_type', [
                    self::BALANCE_TOP_UP_SOURCE,
                    self::OWNER_COMPENSATION_SOURCE,
                ])->orWhere(function (Builder $query) use ($customerId): void {
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
     * Rows are ordered by (created_at, rank, id) descending. Whether a row of
     * another source belongs beyond the cursor depends on how this source's
     * rank compares with the cursor's rank: a lower-ranked row may share the
     * cursor timestamp and still belong to the page, a higher-ranked one may
     * not. Comparing only the timestamp drops same-second rows at a boundary.
     *
     * @param  Builder<StoreBalanceTopUp|StoreBalanceEntry>  $query
     */
    private function applyCursor(
        Builder $query,
        StoreBalanceActivityCursor $cursor,
        int $sourceRank,
        bool $newer,
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

        $query->where(function (Builder $same) use ($timestamp, $operator, $cursor): void {
            $same->where('created_at', $operator, $timestamp)
                ->orWhere(function (Builder $tie) use ($timestamp, $operator, $cursor): void {
                    $tie->where('created_at', '=', $timestamp)
                        ->where('id', $operator, $cursor->id);
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
            ->select(['id', 'customer_balance_account_id', 'kind', 'amount', 'source_type', 'source_id', 'refund_order_id', 'created_at'])
            ->where('customer_balance_account_id', $accountId)
            ->whereIn('kind', [
                StoreBalanceEntryKind::TopUpCredit->value,
                StoreBalanceEntryKind::OwnerCompensationCredit->value,
            ])
            ->where(function (Builder $query) use ($ids): void {
                $query
                    ->where(function (Builder $query) use ($ids): void {
                        $query
                            ->where('source_type', self::BALANCE_TOP_UP_SOURCE)
                            ->whereIn('source_id', array_map('strval', $ids));
                    })
                    ->orWhere(function (Builder $query) use ($ids): void {
                        $query
                            ->where('source_type', self::OWNER_COMPENSATION_SOURCE)
                            ->whereIn('source_id', array_map(
                                static fn (int|string $id): string => 'top_up:'.$id,
                                $ids,
                            ));
                    });
            })
            ->get()
            ->keyBy(function (StoreBalanceEntry $entry): string {
                return $entry->source_type === self::OWNER_COMPENSATION_SOURCE
                    ? substr((string) $entry->source_id, strlen('top_up:'))
                    : (string) $entry->source_id;
            });
    }

    /**
     * @param  Collection<int, StoreBalanceEntry>  $entries
     * @return Collection<string, Order>
     */
    private function ordersForEntries(int $customerId, Collection $entries): Collection
    {
        $ids = $entries
            ->filter(fn (StoreBalanceEntry $entry): bool => $this->orderIdForEntry($entry) !== null)
            ->map(fn (StoreBalanceEntry $entry): string => (string) $this->orderIdForEntry($entry))
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
                && $this->orderIdForEntry($entry) !== null)
            ->map(fn (StoreBalanceEntry $entry): string => (string) $this->orderIdForEntry($entry))
            ->unique()
            ->values()
            ->all();
        if ($ids === []) {
            return collect();
        }

        return StoreBalanceEntry::query()
            ->select(['id', 'customer_balance_account_id', 'kind', 'amount', 'source_type', 'source_id', 'refund_order_id', 'created_at'])
            ->where('customer_balance_account_id', $accountId)
            ->where('kind', StoreBalanceEntryKind::PurchaseDebit->value)
            ->where('source_type', self::ORDER_SOURCE)
            ->whereIn('source_id', $ids)
            ->get()
            ->keyBy(fn (StoreBalanceEntry $entry): string => (string) $entry->source_id);
    }

    private function originalDebitForEntry(int $accountId, StoreBalanceEntry $entry): ?StoreBalanceEntry
    {
        if ($entry->kind !== StoreBalanceEntryKind::RefundCredit || $this->orderIdForEntry($entry) === null) {
            return null;
        }

        return StoreBalanceEntry::query()
            ->select(['id', 'customer_balance_account_id', 'kind', 'amount', 'source_type', 'source_id', 'refund_order_id', 'created_at'])
            ->where('customer_balance_account_id', $accountId)
            ->where('kind', StoreBalanceEntryKind::PurchaseDebit->value)
            ->where('source_type', self::ORDER_SOURCE)
            ->where('source_id', $this->orderIdForEntry($entry))
            ->first();
    }

    private function topUpItem(
        StoreBalanceTopUp $topUp,
        ?StoreBalanceEntry $credit,
        StoreBalanceTopUpPresentation $presentation,
    ): StoreBalanceActivityItem {
        $creditMatchesRequest = $credit !== null
            && in_array($credit->kind, [
                StoreBalanceEntryKind::TopUpCredit,
                StoreBalanceEntryKind::OwnerCompensationCredit,
            ], true)
            && (int) $credit->amount === (int) $topUp->amount
            && (int) $credit->amount > 0;
        $state = $this->topUpState($presentation, $creditMatchesRequest, $topUp->status);
        $creditedAmount = $creditMatchesRequest ? (int) $credit->amount : null;
        $isCompensated = $topUp->status === StoreBalanceTopUpStatus::Compensated;

        return new StoreBalanceActivityItem(
            type: StoreBalanceActivityItem::TYPE_TOP_UP,
            id: (int) $topUp->getKey(),
            occurredAt: $this->immutable($topUp->created_at),
            kind: $isCompensated
                ? StoreBalanceActivityItem::KIND_COMPENSATION
                : StoreBalanceActivityItem::KIND_TOP_UP,
            state: $state,
            amount: $creditedAmount ?? (int) $topUp->amount,
            signedAmount: $creditedAmount,
            reference: '#'.(int) $topUp->getKey(),
            requestedAmount: (int) $topUp->amount,
            creditedAmount: $creditedAmount,
            topUpCanCheck: ! $isCompensated && $presentation->canCheck(),
            topUpCanRetry: ! $isCompensated && $presentation->canRetry(),
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
            StoreBalanceEntryKind::AdminAdjustmentCredit => StoreBalanceActivityItem::KIND_ADJUSTMENT,
            StoreBalanceEntryKind::AdminAdjustmentReversal => StoreBalanceActivityItem::KIND_ADJUSTMENT_REVERSAL,
            StoreBalanceEntryKind::TopUpCredit => StoreBalanceActivityItem::KIND_TOP_UP,
            StoreBalanceEntryKind::OwnerCompensationCredit => StoreBalanceActivityItem::KIND_COMPENSATION,
            StoreBalanceEntryKind::PurchaseDebit => StoreBalanceActivityItem::KIND_PURCHASE,
            StoreBalanceEntryKind::RefundCredit => StoreBalanceActivityItem::KIND_REFUND,
        };
        $state = match ($entry->kind) {
            StoreBalanceEntryKind::AdminAdjustmentCredit => StoreBalanceActivityItem::STATE_CREDITED,
            StoreBalanceEntryKind::AdminAdjustmentReversal => StoreBalanceActivityItem::STATE_DEBITED,
            StoreBalanceEntryKind::TopUpCredit => StoreBalanceActivityItem::STATE_CREDITED,
            StoreBalanceEntryKind::OwnerCompensationCredit => StoreBalanceActivityItem::STATE_COMPENSATED,
            StoreBalanceEntryKind::PurchaseDebit => StoreBalanceActivityItem::STATE_DEBITED,
            StoreBalanceEntryKind::RefundCredit => StoreBalanceActivityItem::STATE_REFUNDED,
        };
        $amount = (int) $entry->amount;
        $order = $this->orderIdForEntry($entry) !== null
            ? $orders->get($this->orderIdForEntry($entry))
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
        StoreBalanceTopUpPresentation $presentation,
        bool $creditMatchesRequest,
        StoreBalanceTopUpStatus $status,
    ): string {
        if ($status === StoreBalanceTopUpStatus::Compensated) {
            return StoreBalanceActivityItem::STATE_COMPENSATED;
        }

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
            StoreBalanceTopUpOutcome::Expired => StoreBalanceActivityItem::STATE_EXPIRED,
            StoreBalanceTopUpOutcome::InvalidProviderResult => StoreBalanceActivityItem::STATE_REVIEW,
            StoreBalanceTopUpOutcome::RecoveryPending => StoreBalanceActivityItem::STATE_UNCERTAIN,
            StoreBalanceTopUpOutcome::Creating,
            StoreBalanceTopUpOutcome::ProviderUnavailable => $presentation->canRetry()
                ? StoreBalanceActivityItem::STATE_FAILED
                : StoreBalanceActivityItem::STATE_PENDING,
            StoreBalanceTopUpOutcome::Payable,
            StoreBalanceTopUpOutcome::AwaitingConfirmation,
            StoreBalanceTopUpOutcome::ProviderTotalPending => StoreBalanceActivityItem::STATE_PENDING,
        };

    }

    private function entryReference(StoreBalanceEntry $entry, ?Order $order): string
    {
        if ($order !== null) {
            return '#'.(string) $order->invoice_number;
        }

        return '#'.(int) $entry->getKey();
    }

    private function orderIdForEntry(StoreBalanceEntry $entry): ?string
    {
        if ($entry->source_type === self::ORDER_SOURCE && $entry->source_id !== '') {
            return (string) $entry->source_id;
        }

        return $entry->source_type === 'order_refund' && $entry->kind === StoreBalanceEntryKind::RefundCredit
            ? $entry->refund_order_id : null;
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
