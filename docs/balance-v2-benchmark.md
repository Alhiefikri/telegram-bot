# Balance V2 performance evidence (#560)

Run `scripts/balance-v2-benchmark.sh` from the repository root. It creates disposable PostgreSQL 16 and Redis 7 containers on randomly allocated loopback ports. It never accepts an existing database or Redis endpoint. Both containers are removed on exit; timestamped evidence remains under ignored `artifacts/balance-v2/`. The optional Predis transport is installed from the isolated, locked Composer manifest in `scripts/fixtures/balance-v2/`; it changes no application dependency or production configuration.

`--smoke` checks the harness with 100 Customers and 10,000 entries per currency. `--quick` uses the representative dataset with short diagnostic durations. Neither mode proves the 30-minute capacity gate. The default profile seeds 10,000 Customers, **1,000,000 entries in each currency**, and balanced USDT accounting counterparts. Each USDT account starts with one synthetic 100 USDT funding receipt (including registry alias and parent claim) and 99 micro-unit purchases. Fixture history uses synthetic source identities; it is not evidence of real deposits, custody backing or qualified providers.

The default runs 50 reads/s and 10 postings/s **per currency concurrently** for 1,800 seconds, then a 60-second 5x burst, then a 60-second hot-account phase at the burst rate. Twenty-four independent PHP workers reopen their own PostgreSQL connections. Each operation has a fixed scheduled arrival: the report includes both execution latency and latency from its scheduled arrival so saturation cannot disappear through coordinated omission. Distinct writers use disjoint account sets; hot writers deliberately share one account. USDT postings call the typed ledger purchase boundary; IDR postings call the existing full-history-validated credit boundary. This measures internal owners, not complete checkout/inventory/controller latency.

Evidence includes:

- Commit SHA, dirty patch/status, copies and SHA-256 of the harness, locked transport dependencies, CPU/memory/filesystem details and Docker configuration.
- Dataset/stack versions, raw per-worker observations and p50/p95/p99 for latency, scheduled latency, scheduling lag, query count, SQL duration and lock-query duration; completed throughput and error counts.
- Real owner-generated `EXPLAIN (ANALYZE, BUFFERS)` plans, PostgreSQL lock-wait samples at 100ms and server lock-wait logs. A `FOR UPDATE` duration includes execution and transport and is a conservative wait bound, not a fabricated exact lock-wait measurement.
- Four-process funding-reservation saturation for distinct and hot Customers, per-operation throughput/query/lock/latency distributions and a no-oversubscription check. Approved H0-v1 removes business exposure caps; the measured constraint is one active funding target per Customer. The shared policy safety lock remains explicit.
- Bounded provider scheduler ticks through outage and recovery, request counts, durable checkpoints, receiver fairness and scheduler query plans. RPC fixture methods reject calls inside DB transactions. Synthetic observations never qualify provider access or authorize money.
- Actual Laravel Redis queue jobs lost with `FLUSHDB` on the isolated Redis connection, durable PostgreSQL rediscovery through `telegram:recover-outbound`, handler delivery against HTTP fixtures, drained backlog and replay without additional delivery.
- Full USDT account-history reconciliation in 200-account pages with a persisted diagnostic checkpoint, exact accounting comparisons, independently paginated receipt/credit reconciliation and bounded IDR signed-history checks after load. Money guards remain enabled throughout.

The draft targets are p95 internal read ≤100ms, p95 internal posting ≤250ms and p99 different-account lock wait ≤500ms. The latency SLO measures time inside the service invocation; offered-arrival latency and producer scheduling lag remain separate measurements so they expose queuing without changing the stated service SLO. The burst must drain before the hot-account phase starts. A failure exits nonzero and retains raw evidence; targets are not automatically relaxed. Smoke/quick success is never described as V2 acceptance. Production capacity requires representative deployment hardware and operator sign-off; this harness touches only local disposable fixtures.

Context7 references used for instrumentation: PostgreSQL 16 [EXPLAIN/BUFFERS](https://www.postgresql.org/docs/16/using-explain.html), [wait events](https://www.postgresql.org/docs/16/monitoring-stats.html), [pg_locks](https://www.postgresql.org/docs/16/view-pg-locks.html); Laravel 13 [query listeners](https://github.com/laravel/docs/blob/13.x/database.md), [Predis connections](https://github.com/laravel/docs/blob/13.x/redis.md) and [after-commit Redis queues](https://github.com/laravel/docs/blob/13.x/queues.md).

## Report-generation correction after #574

A disposable `--smoke` reproduction found two evidence-delivery bugs: report rows omitted `Completed/s` because the `sprintf()` format had one fewer conversion than its arguments, and the wrapper copied the evaluator without executing it. The report now includes both the lock-query p99 bound and measured throughput. The wrapper automatically writes `acceptance-evaluation.json` and `evaluation.log` whenever `result.json` exists, even after an SLO failure, while preserving nonzero benchmark/evaluator exits. Runs that abort before producing results are not evaluated.

The corrected smoke run retained evidence at `artifacts/balance-v2/20261007T065754Z-312388/`: all 12 report rows matched `load.json`, all seven negative controls passed, 100 accounts in each currency and 100 synthetic receipts reconciled without findings, and reservation/provider/Redis-loss probes passed. Evaluation of the original failing smoke retained `passed = false` and exit 1. These are diagnostic runs, not a new 30-minute capacity qualification; thresholds and ledger behavior are unchanged.

Formatting semantics were checked through Context7 against the PHP [`sprintf()` manual](https://www.php.net/manual/en/function.sprintf.php): each conversion specification consumes a parameter.

## Current acceptance status

## Measured run

The full local run used commit `bf0f0041ad1120a25b8cdf02fac0131e703e5ede`, PHP 8.5.9, Laravel 13.34.0, PostgreSQL 16.15 on x86_64, Docker host capacity 8 CPUs / 7.6 GiB and overlayfs storage. It seeded 10,000 Customers with 1,000,000 entries per currency, plus 10,000 synthetic claimed USDT receipt/alias/parent-claim records. The steady phase ran 1,800.082 seconds; the burst ran 60.409 seconds; the hot-account phase ran 70.690 seconds, including 10.690 seconds to drain its queue. Twenty-four independent PHP processes each owned their PostgreSQL connection.

| Phase | Path | Samples | p50 ms | p95 ms | p99 ms | Arrival p95 ms | Queries p95 | Lock-query p99 ms | Completed / s |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Steady | USDT read | 90,000 | 1.70 | 2.91 | 10.35 | 3.04 | 1 | 0 | 50.00 |
| Steady | USDT posting | 18,000 | 14.00 | 65.59 | 132.78 | 67.51 | 8 | 1.68 | 10.00 |
| Steady | IDR read | 90,000 | 1.64 | 2.79 | 10.58 | 2.92 | 1 | 0 | 50.00 |
| Steady | IDR posting | 18,000 | 10.37 | 33.93 | 89.65 | 35.55 | 6 | 1.64 | 10.00 |
| 5x burst | USDT read | 15,000 | 2.56 | 14.69 | 28.87 | 20.14 | 1 | 0 | 248.31 |
| 5x burst | USDT posting | 3,000 | 23.82 | 76.58 | 271.26 | 838.64 | 8 | 8.03 | 49.66 |
| 5x burst | IDR read | 15,000 | 2.35 | 15.44 | 31.62 | 21.38 | 1 | 0 | 248.31 |
| 5x burst | IDR posting | 3,000 | 19.34 | 73.13 | 224.57 | 695.76 | 6 | 8.92 | 49.66 |
| Hot account | USDT read | 15,000 | 2.36 | 6.75 | 11.11 | 7.33 | 1 | 0 | 212.19 |
| Hot account | USDT posting | 3,000 | 76.58 | 172.22 | 341.45 | 11,620.72 | 8 | 230.93 | 42.44 |
| Hot account | IDR read | 15,000 | 2.11 | 5.89 | 10.56 | 6.65 | 1 | 0 | 212.19 |
| Hot account | IDR posting | 3,000 | 79.52 | 175.91 | 351.15 | 13,387.71 | 6 | 271.69 | 42.44 |

Internal p95 latency met the draft targets in every phase. The distinct-account `FOR UPDATE` duration p99 was 1.68/1.64 ms steady and 8.03/8.92 ms in the burst for USDT/IDR; this includes execution and transport and is an upper bound on lock wait. The hot-account p99 was 230.93/271.69 ms. Arrival latency makes queueing visible: the hot-account phase drained in 10.690 seconds after its 60-second offered interval. The burst drained in 0.409 seconds after its 60-second interval. The final evaluator treats arrival latency as separate evidence; it does not omit it or relabel it as service latency.

Post-load validation inspected 10,000 USDT and 10,000 IDR accounts: zero findings in either ledger, zero USDT accounting-event mismatches, and all 10,000 synthetic claimed funding receipts matched with zero findings. Full USDT ledger reconciliation took 11.680 seconds (200-account page p50 220.324ms / p95 277.191ms / p99 478.894ms); receipt reconciliation took 1.682 seconds.

The first full-run plan exposed an important problem: a scoped `WHERE IN` query was decorrelated by PostgreSQL into a sequential scan of all 1,024,000 entries on every account page. The PostgreSQL path now uses correlated `LATERAL` aggregates and latest-entry lookups; SQLite retains its correlated scalar projections. A subsequent million-entry diagnostic's plan visited 201 accounts and read 101 entries per account using an index-only scan plus a bitmap heap scan for the signed sum; it did not scan the ledger table globally. That statement took 44.118ms. Across 10,000 accounts, this diagnostic reconciled the ledger in 2.912 seconds (200-account page p50 54.578ms / p95 78.379ms / p99 98.758ms) and receipts in 2.041 seconds, with zero findings. It overlapped regression tests, so these figures verify the query shape and correctness rather than isolated capacity.

Reservation contention covered four processes and 1,000 distinct Customers (59.66 reservations/s, p95 79.60ms, policy/account lock-query p99 72.58ms). Four processes targeting one hot Customer measured 89.89 attempts/s, p95 66.97ms; 999 attempts returned the existing active target and one created it. There were no duplicate active reservations. Provider recovery exercised six outage and six recovery ticks, with a shared 32-operation budget, 12 receivers, 100 synthetic observed logs and all checkpoints caught up. Outbound recovery lost 500 real Laravel Redis-queue wakeups, rediscovered and sent all 500 durable effects once through the existing handler/HTTP fixture, then replayed them with zero additional HTTP requests. The oldest work was 3.307 seconds at dispatch; backlog drained in 26.723 seconds.

The full run originally marked `load_targets_passed` false because a draft p99 scheduled-arrival ≤250ms gate had been added. That arrival-latency gate is not in issue #560. The separate evaluator applies the issue's internal-invocation p95 SLOs, exact populations, distinct-account lock bound and drained-work check; it records the full run's result/load SHA-256 values and seven counterfactual failure checks. It passes the original measurements. The original result flag and all scheduled-arrival observations remain intact and visible in the artifact directory.

Other probe results: four-process reservations reached 59.66/s (1,000 distinct Customers, p95 79.60ms / p99 110.36ms, 9 queries per operation at p95) and 89.89/s against one hot Customer (p95 66.97ms / p99 92.48ms; one reservation created, 999 reused it). History-page p95 was 18.38/20.27ms for 25/100 USDT items with four queries and 7.29/31.01ms for IDR with three queries. Six outage and six recovery ticks scanned 12 receivers; each recovery tick respected the 32-operation budget, observed 100 synthetic logs and caught all checkpoints up. Redis recovery lost and rediscovered 500 jobs, delivered 500 once, added no requests on replay, and drained the backlog in 26.723 seconds; oldest work was 3.307 seconds before recovery.

Raw per-worker records, query plans, PostgreSQL wait samples/logs and hardware details remain under the ignored artifact directory `artifacts/balance-v2/20261006T162648Z-339828/`.

The final query correction passed 62 SQLite balance/reconciliation tests (434 assertions) and 9 PostgreSQL reconciliation, unfreeze and ingestion-regression tests (141 assertions). The broader PostgreSQL gate had passed 225 tests (2,139 assertions, no skips or failures) before the final LATERAL correction.

Provider, RPC, custody and Telegram values are synthetic fixtures; they prove adapter budgets and durable-recovery behavior only. They do not qualify production receivers, custody, legal permission, treasury backing or live Telegram delivery. The run used local disposable hardware and does not establish capacity on the production VPS. No production deployment, live funds or funding activation occurred.

Diagnostics found and reproduced receiver starvation after outage recovery: second-resolution `last_attempt_at` tied all previously visited receivers, so rapid budgeted ticks repeatedly selected low receiver IDs. The scheduling timestamp now retains microseconds in PostgreSQL and in the writer. The same-second regression is included in the PostgreSQL release manifest and concurrency gate.

The initial million-entry diagnostic recorded the unbounded grouped-query plan and its 58.168-second reconciliation. The post-change million-entry `--quick` artifact is `artifacts/balance-v2/20261006T230721Z-7838/`; it is diagnostic only and ran alongside regression work.
