# Production Benchmark

Issue: #66

Run the production-like Docker benchmark from the repository root:

```bash
./scripts/benchmark-production.sh
```

The command starts the regular `docker-compose.yml` stack with project name `telegram-bot-dogfood`, recreates containers so Docker and Nginx config changes are represented, enables migrations only in the isolated benchmark override, waits for `/up`, seeds namespaced benchmark fixtures, exercises HTTP/admin/webhook/handler/command scenarios, writes a report, and exits non-zero when budgets or final queue state fail.

Reports are written under:

```text
artifacts/production-benchmark/<timestamp>/report.json
artifacts/production-benchmark/<timestamp>/report.md
```

## PostgreSQL Production Observability

The production Compose database enables `pg_stat_statements`, logs statements
that run for at least 500 ms, and uses bounded Docker log rotation (10 MiB × 3).
Bind parameter values are excluded from statement logs. Postgres restarts to
load the shared library; the migration installs the extension in the application
database.

After the configuration deploy is healthy, start a fresh 24-hour measurement
window once, as the database owner:

```sql
SELECT pg_stat_statements_reset();
SELECT stats_reset FROM pg_stat_statements_info;
```

Do not reset statistics again during the window. After at least 24 hours, capture
the top 20 statements for the current database and attach the output, including
`stats_reset`, to issue #517. Review query text before sharing; redact any
literal values.

```sql
SELECT queryid, calls,
       round(total_exec_time::numeric, 2) AS total_exec_ms,
       round(mean_exec_time::numeric, 2) AS mean_exec_ms,
       rows, shared_blks_hit, shared_blks_read, query
FROM pg_stat_statements
WHERE dbid = (SELECT oid FROM pg_database WHERE datname = current_database())
ORDER BY total_exec_time DESC
LIMIT 20;
```

Use `EXPLAIN` without `ANALYZE` when reviewing a candidate query in production;
it reports the planned index/scan without executing the statement. Do not add a
candidate index until its query and plan are supported by the captured workload.

## Paid Invoice Renderer Budget

Run the renderer-only production-equivalent benchmark:

```bash
./scripts/benchmark-paid-invoice.sh
```

It builds the PHP 8.4 app image, runs one CLI worker under the app service's 1.50 CPU / 896 MiB limits, and measures representative and pathological invoice inputs. Each report records wall-time samples, peak RSS delta, PNG bytes, dimensions, and pass/fail status for the 250 ms, 24 MiB, and 300 KB budgets.

The redacted report is written to:

```text
docs/benchmarks/issue-327-paid-invoice.json
```

Override `PAID_INVOICE_BENCHMARK_RUNS`, `PAID_INVOICE_BENCHMARK_WARMUP`, `PAID_INVOICE_BENCHMARK_OUTPUT`, or `BENCHMARK_COMPOSE_PROJECT` when comparing runs.

## Default Runtime

The command supplies safe dogfood defaults when the environment does not provide them:

- `WEB_PORT=18080`
- `BASE_URL=http://127.0.0.1:18080`
- `BENCHMARK_COMPOSE_PROJECT=telegram-bot-dogfood`
- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=https://benchmark.localhost.test`
- `POSTGRES_PASSWORD=dogfood_password`
- `TELEGRAM_BOT_TOKEN=benchmark-token`
- `TELEGRAM_WEBHOOK_SECRET=benchmark-secret`
- `DOGFOOD_TIMING_ENABLED=true`
- `DOGFOOD_TIMING_LOG_CHANNEL=stderr`
- `DOGFOOD_FAKE_TELEGRAM_OUTBOUND=true`

Docker Compose pins application lifecycle, session, queue, and performance
settings; those are not benchmark `.env` overrides. Override the listed
dogfood inputs by exporting the variable before running the command.

## Runtime Request and Worker Bounds

Telegram Bot API calls use a 10-second overall HTTP timeout and a 3-second connection-setup timeout. The connection timeout is within the overall budget, not an additional three seconds. The same budget applies to multipart photo/document uploads; there is no separately longer upload timeout. Interactive update jobs retain their existing retry/backoff behavior, while a timeout for a durable Telegram Outbound Effect remains ambiguous and requires Manual Delivery Review rather than automatic resend.

The production app image runs PHP-FPM in dynamic mode with `pm.max_children=6`, `pm.start_servers=2`, `pm.min_spare_servers=2`, `pm.max_spare_servers=4`, `pm.max_requests=500`, and `request_terminate_timeout=60s`. Nginx's FastCGI read timeout is 65 seconds, leaving a five-second margin beyond FPM's request limit. These limits apply to FPM requests; the app container also runs nine supervised queue workers under the Compose 1.50-CPU/896-MiB cap; see [the queue topology note](telegram-queue-topology.md) for the current consumer list, timeout margins and the unverified memory headroom caveat.

The pool settings are a resource bound, not proof that peak FPM plus queue-worker memory fits the container. The benchmark records final container memory, not a peak-memory/OOM trace; green `ops:health` does not demonstrate FPM capacity. For production acceptance, capture peak app memory/OOM and restart state, storefront latency/502s under representative load, and PostgreSQL connection counts before and after rollout.

## Coverage

The benchmark covers:

- `/up`, `/`, and `/admin/login` over the exposed Docker Nginx service.
- `/admin/login` page concurrency at 10 workers.
- Valid admin login with a seeded dogfood owner account, plus a follow-up dashboard request to verify the session.
- Telegram webhook invalid secret, unsupported update, valid update, and duplicate update paths.
- Telegram handler product, quantity, and checkout happy paths through the real webhook, Redis queue, and Supervisor worker.
- Manual checkout queue behavior across the `default` update worker, `telegram-outbound` checkout-instruction worker, and independent `notifications` worker, including the timing events emitted for order checkout, QR file read, customer `sendPhoto`, admin `sendMessage`, receipt, and Sales Proof work.
- Operational commands: `ops:health --db --queue-schema --queue-depth --supervisor`, scheduler heartbeat health, `orders:expire-pending`, and `ops:check-failed-jobs --max=0`.
- Final queue depth, failed job count, status counts, p50/p95/max latency, and container memory.

Docker Compose uses Redis for queue, cache, session, and lock state. Redis has a 96 MiB memory cap, no persistence, and no persistent volume; PostgreSQL remains the durable domain database and failed-job store. Redis restart therefore loses outstanding queue jobs and session/cache/lock state. The release startup gate refuses to start Redis-backed services while database queue rows remain, and automatic rollback to the database driver is blocked until all Redis queues are empty. See [ADR 0014](adr/0014-redis-runtime-state.md) and the [cutover runbook](runbook-redis-runtime-cutover.md).

## Latest Issue 70 Run

On 2026-07-02, issue #70 was verified with clean dogfood volumes by running:

```bash
BENCHMARK_COMPOSE_PROJECT=telegram-bot-dogfood-issue70 WEB_PORT=18081 BASE_URL=http://127.0.0.1:18081 ./scripts/benchmark-production.sh
```

Result: passed. The report measured webhook ACK p95 at 31.049 ms, handler drain at 4133.749 ms, final queue depth at 0, and failed jobs at 0. This run used fake Telegram outbound for safe synthetic handler coverage, but exercised the then-current Docker/Nginx/PostgreSQL/Supervisor queue topology with split `default` and combined `telegram-outbound,notifications` workers. Current runs additionally use Redis for cache/session/locks/queue and the later issue #171 topology with independent `default`, `telegram-outbound`, `notifications`, `telegram-broadcast`, and `telegram-stock` pools, and from issue #651 `payments` and `telegram-receipts` as separate supervised queues.

## Synthetic Versus Real External Calls

The current supervised topology separates `default` interactions, `payments` verification, `telegram-receipts` credential delivery, and `notifications` email/admin/proof work instead of combining `payments,default` or receipts with notifications. See [the queue topology note](telegram-queue-topology.md).

Synthetic Telegram handler scenarios set `DOGFOOD_FAKE_TELEGRAM_OUTBOUND=true` by default. This replaces outbound Telegram API calls with `FakeTelegramBotClient` inside the Docker app worker, so the benchmark does not send real Telegram messages while still exercising the application handler, queue, database, cart, checkout, and manual-payment code paths.

The benchmark does not fake inbound webhook validation. It still posts JSON updates to `POST /api/webhook/telegram` with the configured webhook secret through Nginx.


Use this benchmark as the default synthetic Docker release gate. Use `src/scripts/perf-dogfood.php` for the broader dogfood scenario matrix: with `DOGFOOD_FAKE_TELEGRAM_OUTBOUND=true` it reports `run_mode.mode=synthetic`, uses fake Telegram outbound, blocks absent real-external fixtures, and includes `final_queue_state` for total depth across operational queues and failed-job checks. With fake outbound disabled, that script is a real-external harness and should only be run with disposable real Telegram, Store Channel, callback, and photo fixtures.

## Budgets

Defaults:

- `BENCHMARK_HTTP_P95_MS=1000`
- `BENCHMARK_STOREFRONT_CONCURRENT_P95_MS=500`
- `BENCHMARK_ADMIN_LOGIN_P95_MS=500`
- `BENCHMARK_WEBHOOK_ACK_P95_MS=500`
- `BENCHMARK_HANDLER_DRAIN_MAX_MS=15000`
- `BENCHMARK_APP_MEMORY_MAX_MIB=768`

The command exits non-zero when:

- HTTP p95 exceeds `BENCHMARK_HTTP_P95_MS`.
- Storefront `/` concurrency-10 p95 exceeds `BENCHMARK_STOREFRONT_CONCURRENT_P95_MS`.
- Admin login p95 exceeds `BENCHMARK_ADMIN_LOGIN_P95_MS`.
- Telegram webhook ACK p95 exceeds `BENCHMARK_WEBHOOK_ACK_P95_MS`.
- handler queue drain exceeds `BENCHMARK_HANDLER_DRAIN_MAX_MS`;
- final `jobs` queue depth is not `0`;
- final failed jobs count is not `0`;
- app container memory exceeds `BENCHMARK_APP_MEMORY_MAX_MIB`;
- any measured operational command exits non-zero.

## Warm State

The command does not delete Docker volumes. If dogfood volumes already exist, the run uses warm/reused state. For a fresh run, preserve any artifacts you need and run:

```bash
./scripts/dogfood-volume-preflight.sh --reset --yes
```
