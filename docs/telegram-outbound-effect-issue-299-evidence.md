# Issue #299 — Telegram Outbound Effect verification evidence

This record covers the PostgreSQL race and deletion/contract evidence added
for issue #299. It contains counts and test names only; no credentials,
Telegram payloads, claim tokens, or delivery content are retained.

## Added coverage

| Invariant | Test | Result |
| --- | --- | --- |
| Two workers cannot claim one effect simultaneously | `TelegramOutboundEffectPostgresConcurrencyTest::test_two_postgres_workers_cannot_claim_one_effect_simultaneously` | PASS |
| An expired lease becomes `unknown` and rejects the old worker | `TelegramOutboundEffectPostgresConcurrencyTest::test_expired_lease_recovery_cannot_be_overwritten_by_the_old_worker` | PASS |
| A stale token cannot finalize after a later owner claims the effect | `TelegramOutboundEffectPostgresConcurrencyTest::test_an_old_claim_token_cannot_finalize_after_a_later_owner_claims_the_effect` | PASS |
| Removed delivery-state façade stays deleted | `TelegramOutboundEffectProtocolBoundaryTest::test_removed_delivery_state_surface_does_not_reappear_in_callers` | PASS |

The focused PostgreSQL class passed 3 tests and 24 assertions against a
disposable PostgreSQL 16 database. The disposable database and container were
removed after the run.

## Verification commands

| Gate | Result |
| --- | --- |
| Focused outbound suite | PASS — 93 tests, 513 assertions |
| Protocol and deletion/contract checks | PASS — 21 tests, 139 assertions |
| New PostgreSQL race class | PASS — 3 tests, 24 assertions |
| Full SQLite suite | Existing dirty-worktree baseline — 1,546 passed, 38 skipped, 3 unrelated failures in `AuditValidationTemporaryTest` and `TelegramTranslationAuditTest` |
| Full PostgreSQL concurrency gate | 68 of 69 tests passed; one unrelated existing `ProductionConcurrencyTest::test_concurrent_admin_login_failures_share_one_atomic_database_cache_counter` assertion remains nondeterministic on this host (`8` observed vs `7` expected) |
| Gate preflight checks | PASS — invalid artifact paths and a PHP CLI without `pdo_pgsql` fail before database creation or PHPUnit execution |
| PHP syntax, Pint, and `git diff --check` | PASS |

The PostgreSQL gate continues to use `--fail-on-skipped`; the new race class
does not silently skip when the gate is running with its PostgreSQL preflight.
