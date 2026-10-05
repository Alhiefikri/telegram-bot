<?php

namespace App\Services\UsdtBalance;

use Brick\Math\BigInteger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Idempotent, bounded consumption of the append-only USDT accounting event
 * stream into per-leg aggregates. One accounting event is claimed by a named
 * consumer exactly once through a unique (consumer, event) row; the claim and
 * the aggregate delta commit in the same transaction, so a crash or duplicate
 * delivery can only replay a bounded batch and never double count. Aggregates
 * are keyed by balanced leg pair, so no single global clearing row is locked
 * or summed on the posting path.
 */
final class UsdtAccountingAggregator
{
    public const DEFAULT_CONSUMER = 'reporting-v1';

    public const MAX_BATCH = 1000;

    private const CONSUMER_PATTERN = '/^[a-z0-9][a-z0-9_\-]{0,63}$/';

    public function catchUp(string $consumer, int $limit): UsdtAccountingAggregationResult
    {
        $this->assertConsumer($consumer);
        if ($limit < 1 || $limit > self::MAX_BATCH) {
            throw new InvalidArgumentException('USDT accounting aggregation limit must be between 1 and '.self::MAX_BATCH.'.');
        }

        $events = $this->nextEvents($consumer, $limit);
        $processed = 0;
        $lastEventId = null;

        if ($events->isNotEmpty()) {
            DB::transaction(function () use ($events, $consumer, &$processed, &$lastEventId): void {
                foreach ($events as $event) {
                    $lastEventId = (int) $event->id;
                    if (! $this->claim($consumer, $event)) {
                        continue;
                    }

                    $this->apply($consumer, $event);
                    $processed++;
                }
            }, 5);
        }

        return new UsdtAccountingAggregationResult($consumer, $processed, $lastEventId);
    }

    public function status(string $consumer = self::DEFAULT_CONSUMER): UsdtAccountingStatus
    {
        $this->assertConsumer($consumer);

        $lastClaimed = DB::table('usdt_balance_accounting_aggregate_claims')
            ->where('consumer', $consumer)
            ->max('customer_usdt_balance_accounting_event_id');

        $pending = (int) DB::table('customer_usdt_balance_accounting_events')
            ->when($lastClaimed !== null, fn ($query) => $query->where('id', '>', (int) $lastClaimed))
            ->count();

        $claimedEvents = (int) DB::table('usdt_balance_accounting_aggregate_claims')
            ->where('consumer', $consumer)
            ->count();

        $claimedMicro = $this->exactSum(DB::table('usdt_balance_accounting_aggregate_claims as claims')
            ->join(
                'customer_usdt_balance_accounting_events as events',
                'events.id',
                '=',
                'claims.customer_usdt_balance_accounting_event_id',
            )
            ->where('claims.consumer', $consumer)
            ->sum('events.amount_micro'));

        $aggregateQuery = DB::table('usdt_balance_accounting_aggregates')->where('consumer', $consumer);
        $aggregatedEvents = (int) $aggregateQuery->clone()->sum('entries_count');
        $aggregatedMicro = $this->exactSum($aggregateQuery->clone()->sum('amount_micro'));

        $legs = $aggregateQuery
            ->orderBy('debit_account')
            ->orderBy('credit_account')
            ->get(['currency', 'debit_account', 'credit_account', 'entries_count', 'amount_micro'])
            ->map(static fn (object $leg): array => [
                'currency' => (string) $leg->currency,
                'debit_account' => (string) $leg->debit_account,
                'credit_account' => (string) $leg->credit_account,
                'entries_count' => (int) $leg->entries_count,
                'amount_micro' => (string) (int) $leg->amount_micro,
            ])
            ->all();

        return new UsdtAccountingStatus(
            consumer: $consumer,
            pendingEvents: $pending,
            claimedEvents: $claimedEvents,
            aggregatedEvents: $aggregatedEvents,
            claimedMicro: $claimedMicro,
            aggregatedMicro: $aggregatedMicro,
            legs: $legs,
        );
    }

    private function nextEvents(string $consumer, int $limit)
    {
        $lastClaimed = DB::table('usdt_balance_accounting_aggregate_claims')
            ->where('consumer', $consumer)
            ->max('customer_usdt_balance_accounting_event_id');

        return DB::table('customer_usdt_balance_accounting_events')
            ->when($lastClaimed !== null, fn ($query) => $query->where('id', '>', (int) $lastClaimed))
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'currency', 'amount_micro', 'debit_account', 'credit_account']);
    }

    private function claim(string $consumer, object $event): bool
    {
        $inserted = DB::table('usdt_balance_accounting_aggregate_claims')->insertOrIgnore([
            'consumer' => $consumer,
            'currency' => (string) $event->currency,
            'customer_usdt_balance_accounting_event_id' => (int) $event->id,
            'created_at' => now(),
        ]);

        return $inserted === 1;
    }

    private function apply(string $consumer, object $event): void
    {
        $amount = (int) $event->amount_micro;
        $eventId = (int) $event->id;

        $updated = $this->aggregateQuery($consumer, $event)->update([
            'entries_count' => DB::raw('entries_count + 1'),
            'amount_micro' => DB::raw("amount_micro + {$amount}"),
            'first_event_id' => DB::raw("CASE WHEN first_event_id < {$eventId} THEN first_event_id ELSE {$eventId} END"),
            'last_event_id' => DB::raw("CASE WHEN last_event_id > {$eventId} THEN last_event_id ELSE {$eventId} END"),
            'updated_at' => now(),
        ]);

        if ($updated === 1) {
            return;
        }

        try {
            DB::table('usdt_balance_accounting_aggregates')->insert([
                'consumer' => $consumer,
                'currency' => (string) $event->currency,
                'debit_account' => (string) $event->debit_account,
                'credit_account' => (string) $event->credit_account,
                'entries_count' => 1,
                'amount_micro' => $amount,
                'first_event_id' => $eventId,
                'last_event_id' => $eventId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent consumer inserted the leg row first; the claim
            // uniqueness already guarantees this event's delta is applied once,
            // so the retry can only land on that row.
            $retried = $this->aggregateQuery($consumer, $event)->update([
                'entries_count' => DB::raw('entries_count + 1'),
                'amount_micro' => DB::raw("amount_micro + {$amount}"),
                'first_event_id' => DB::raw("CASE WHEN first_event_id < {$eventId} THEN first_event_id ELSE {$eventId} END"),
                'last_event_id' => DB::raw("CASE WHEN last_event_id > {$eventId} THEN last_event_id ELSE {$eventId} END"),
                'updated_at' => now(),
            ]);

            if ($retried !== 1) {
                throw $exception;
            }
        }
    }

    private function aggregateQuery(string $consumer, object $event)
    {
        return DB::table('usdt_balance_accounting_aggregates')
            ->where('consumer', $consumer)
            ->where('currency', (string) $event->currency)
            ->where('debit_account', (string) $event->debit_account)
            ->where('credit_account', (string) $event->credit_account);
    }

    private function exactSum(int|string|float|null $value): BigInteger
    {
        if ($value === null || $value === '') {
            return BigInteger::zero();
        }

        if (is_float($value)) {
            throw new RuntimeException('USDT accounting aggregate exceeds the supported exact integer range.');
        }

        return BigInteger::of((string) $value);
    }

    private function assertConsumer(string $consumer): void
    {
        if (preg_match(self::CONSUMER_PATTERN, $consumer) !== 1) {
            throw new InvalidArgumentException('USDT accounting consumer must be a lowercase name of at most 64 characters.');
        }
    }
}
