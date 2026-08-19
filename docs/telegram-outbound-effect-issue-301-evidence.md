# Issue #301 — scope and release evidence

Date: 2026-08-19

This record closes the documentation and release-readiness part of #286. It
contains counts, test names, commit references, and environment results only.
It does not retain credentials, raw Telegram payloads, QR media, claim tokens,
or customer delivery content.

## Scope boundary

The final Telegram Outbound Effect scope is represented by:

- [#297](https://github.com/Alhiefikri/telegram-bot/issues/297) — reject
  invalid Telegram message identifiers;
- [#298](https://github.com/Alhiefikri/telegram-bot/issues/298) — make the
  ledger protocol the only delivery-state seam;
- [#299](https://github.com/Alhiefikri/telegram-bot/issues/299) — add
  PostgreSQL race and deletion-test evidence; and
- [#300](https://github.com/Alhiefikri/telegram-bot/issues/300) — make
  deferred Admin Broadcast materialization recoverable.

Commit
[81e1fa9](https://github.com/Alhiefikri/telegram-bot/commit/81e1fa9)
(fix: normalize existing USDT product prices) is a standalone product-admin
change. It is excluded from the #286 architecture scope and has no dependency
on the Telegram Outbound Effect ledger. Shared main history is not rewritten;
the commit remains independently identifiable for product-admin review or
cherry-pick.

## Committed documentation

- [Telegram Outbound Effect architecture and recovery contract](telegram-outbound-effect-architecture.md)
  records the sole ledger seam, family-adapter locality, state contract,
  recovery sequence, Manual Delivery Review, deferred Admin Broadcast
  handoff, scope boundary, and non-goals.
- [ADR-0007](adr/0007-telegram-outbound-effects.md) records the final protocol
  boundary and the recovery rules as architectural decisions.
- [Telegram Outbound Effect contract runbook](runbook-telegram-outbound-effect-contract.md)
  records deployment, runtime recovery, Admin Broadcast handoff, and review
  procedures.
- [The codebase guide](agents/codebase.md) links the implementation map and
  test entry points.
- [CONTEXT.md](../CONTEXT.md) defines the canonical terms Telegram Outbound
  Effect, Ambiguous Telegram Delivery, and Manual Delivery Review.

## Verification

| Gate | Result |
| --- | --- |
| Focused outbound/family suite | PASS — 211 tests, 1,249 assertions |
| Protocol deletion/contract suite | PASS — 3 tests, 93 assertions |
| Full SQLite suite | 1,547 passed, 38 skipped, 3 unrelated failures in the dirty-worktree audit/translation tests |
| Concurrency preflight regression script | PASS — invalid artifact paths are rejected before database setup |
| Default PostgreSQL concurrency gate | BLOCKED before database setup — repository artifact directory is not writable |
| Writable temporary-artifact PostgreSQL gate | BLOCKED before database setup — PHP CLI does not have pdo_pgsql |
| PostgreSQL Telegram race evidence from #299 | PASS in the committed release evidence — 3 race tests, 24 assertions, disposable PostgreSQL 16 |
| Sensitive-data review | PASS — documentation and evidence contain no credentials, payloads, QR media, claim tokens, or customer delivery content |

The focused suite covered the ledger protocol, all durable delivery families,
operator review, Admin Broadcast recovery, invalid Telegram responses, and the
recovery command. The deletion test is
TelegramOutboundEffectProtocolBoundaryTest::test_removed_delivery_state_surface_does_not_reappear_in_callers.
The full-suite failures are outside this documentation change and remain
isolated to the pre-existing dirty-worktree audit/translation work.

The PostgreSQL race class cannot run in this PHP environment without pdo_pgsql;
the gate fails closed rather than silently skipping the release check. The
previous committed #299 run remains the authoritative PostgreSQL race evidence,
and the current preflight result is recorded here for reproducibility.
