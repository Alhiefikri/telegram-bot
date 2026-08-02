# Binance Sequential Unique Amount Allocation Design

## Goal

Replace the legacy random Binance Pay amount surcharge with deterministic, smallest-available sequential allocation while `amount_unique` verification remains in use.

For a catalog amount of `1.000` USDT, a pending legacy Binance checkout must pay `1.000` when that amount is free, then `1.001`, `1.002`, and so on only while lower slots are occupied. A buyer must never receive an arbitrary random surcharge such as `1.700`.

## Scope

This change applies only to payments created with all of the following attributes:

- `provider = manual`
- `method = BINANCE_PAY_ID`
- `binance_verification_mode = amount_unique`
- automatic Binance verification enabled

`order_id` payments retain their exact catalog amount and never use the allocator. Non-Binance payments are unchanged.

## Allocation Rule

Amounts are stored as integer micro-USDT. One displayed Binance unique-amount increment is `1_000` micro-USDT (`0.001` USDT).

Given a catalog amount `base`:

1. Start with `candidate = base`; do not round it up and do not add an initial offset.
2. Read active legacy Binance payment amounts from a contiguous, fixed-size range beginning at `candidate`.
3. Choose the smallest amount in that range that is not occupied.
4. If every amount in the range is occupied, advance by one range and repeat.
5. Return the selected amount.

Thus, for `base = 1_000_000` micro-USDT:

| Occupied active amounts | Allocated amount |
| --- | --- |
| none | `1.000` |
| `1.000` | `1.001` |
| `1.000`, `1.001` | `1.002` |

Allocation need not preserve a historic sequence after an amount becomes available. If `1.000` is no longer occupied, the next checkout reuses `1.000`.

## Occupancy

Only payments that are still `pending`, have auto-verification enabled, and are in `amount_unique` mode occupy an amount. Paid, failed, cancelled, and expired payments do not occupy amounts. The current database partial unique index uses the same predicate and remains the authoritative concurrency boundary.

## Concurrency

The allocator runs inside the existing checkout transaction. A batch read is advisory: two concurrent checkouts may see the same free slot. The existing partial unique index permits only one insert for that active amount. The losing checkout already retries its transaction; on retry it re-reads occupancy and chooses the next free sequential slot.

No database-vendor-specific gap locks, advisory locks, or new reservation table are introduced. This preserves PostgreSQL/MySQL compatibility and avoids lifecycle cleanup for claim records.

## Performance

The allocator reads occupied values in a bounded batch rather than issuing one database query per candidate. It uses the existing indexed `amount` column plus the existing partial predicate. Normal checkout performs one indexed range query and a small in-memory scan. Additional range queries occur only when an entire batch is occupied, which requires a large number of simultaneous pending checkouts with the same or lower base amount.

The old random-offset configuration is removed because no random range remains in the contract.

## Error Handling

Allocation continues through additional batches rather than failing merely because a configured random range is exhausted. The existing transaction retry limit remains responsible for bounded handling of persistent unique-index contention; a final unique constraint failure still follows the current checkout error path.

## Tests

Tests must cover:

- a free catalog amount is returned unchanged;
- occupied slots produce the immediate next mill-USDT amount;
- a gap is reused before higher occupied amounts;
- a full initial range continues into the next range;
- terminal and expired payments do not occupy a slot;
- non-Binance and `order_id` payments remain exact and bypass the allocator;
- concurrent checkout safety remains enforced by the partial unique index and retry path.

## Non-Goals

- Activating or changing the guarded `order_id` rollout.
- Changing Binance transaction verification semantics.
- Altering historical payment amounts.
- Charging a fee or adding a surcharge outside the smallest necessary `0.001` USDT increments.
