# Taste

## Domain and data modelling
- Represents money/state domains as an append-only immutable ledger plus an atomically maintained projection, with a per-source idempotency key and an immutable economic source identity, so replays return the existing result instead of a second effect. Confidence: 0.75
- Treats one service as the sole seam allowed to mutate such state; models elsewhere must not write the balance directly (projection column deliberately not mass assignable). Confidence: 0.7
- Enforces invariants in the database as the final guard — PostgreSQL check constraints (non-negative balance/amount, positive amount) and unique indexes over idempotency/source claims — not only in application code. Confidence: 0.7
- Prefers fail-safe behaviour over fabricated data: when a projection cannot be reconciled with its source of truth, render an explicit localized "unavailable" state rather than a number. Confidence: 0.65
- Keeps automated reconciliation/checking read-only and advisory (a `store-balance:check`-style command that reports mismatches and exits non-zero); never silently repairs state — a repair must be its own explicit, idempotent, audited operation. Confidence: 0.65

## Code and testing conventions (Laravel/PHP)
- Writes `final` classes with constructor property promotion, explicit return types, enums that carry their own behaviour (`direction()`, `isCredit()`), and model guards that throw on update/delete of immutable rows. Confidence: 0.6
- Tests new concurrency-sensitive behaviour with process-level races (pcntl fork, ready/start markers, result files) behind a dedicated group whose harness refuses to run unless it connects to an isolated test database (`DB_CONNECTION=pgsql`, database name must match test, not production). Confidence: 0.65
- Covers database constraints in tests too: assert that raw inserts violate the expected named constraint. Confidence: 0.55

## Localization
- Every user-facing string ships in all supported locales (id/en/ms) with identical placeholders, validated by a translation audit test; localized English is the technical default while the product copy stays Indonesian/Malay-flavored. Confidence: 0.65
