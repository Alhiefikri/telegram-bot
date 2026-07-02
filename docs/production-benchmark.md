# Production Benchmark

Issue: #66

Run the production-like Docker benchmark from the repository root:

```bash
./scripts/benchmark-production.sh
```

The command starts the regular `docker-compose.yml` stack with project name `telegram-bot-dogfood`, recreates containers so Docker and Nginx config changes are represented, waits for `/up`, seeds namespaced benchmark fixtures, exercises HTTP/admin/webhook/handler/command scenarios, writes a report, and exits non-zero when budgets or final queue state fail.

Reports are written under:

```text
artifacts/production-benchmark/<timestamp>/report.json
artifacts/production-benchmark/<timestamp>/report.md
```

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
- `TELEGRAM_PAYMENT_MODE=manual`
- `APP_OPTIMIZE=true`
- `SESSION_DRIVER=cookie`
- `DOGFOOD_TIMING_ENABLED=true`
- `DOGFOOD_TIMING_LOG_CHANNEL=stderr`
- `DOGFOOD_FAKE_TELEGRAM_OUTBOUND=true`

Override any of these by exporting the variable before running the command.

## Coverage

The benchmark covers:

- `/up`, `/`, and `/admin/login` over the exposed Docker Nginx service.
- `/admin/login` page concurrency at 10 workers.
- Valid admin login with a seeded dogfood owner account, plus a follow-up dashboard request to verify the session.
- Telegram webhook invalid secret, unsupported update, valid update, and duplicate update paths.
- Telegram handler product, quantity, and checkout happy paths through the real webhook, database queue, and Supervisor worker.
- Manual checkout queue behavior across the `default` update worker and `telegram-outbound` notification worker, including the timing events emitted for order checkout, QR file read, customer `sendPhoto`, and admin `sendMessage`.
- Operational commands: `ops:health --db --queue-schema --supervisor`, scheduler heartbeat health, `orders:expire-pending`, `pakasir:reconcile --chunk=5`, and `ops:check-failed-jobs --max=0`.
- Final queue depth, failed job count, status counts, p50/p95/max latency, and container memory.

The Docker default uses Laravel's encrypted cookie session driver for admin web sessions. This keeps login/logout and CSRF behavior stateful for the browser while avoiding database writes for anonymous `/admin/login` page requests under concurrency. Set `SESSION_DRIVER=database` before running the benchmark if you need to compare the older database-backed session path.

## Latest Issue 70 Run

On 2026-07-02, issue #70 was verified with clean dogfood volumes by running:

```bash
BENCHMARK_COMPOSE_PROJECT=telegram-bot-dogfood-issue70 WEB_PORT=18081 BASE_URL=http://127.0.0.1:18081 ./scripts/benchmark-production.sh
```

Result: passed. The report measured webhook ACK p95 at 31.049 ms, handler drain at 4133.749 ms, final queue depth at 0, and failed jobs at 0. This run used fake Telegram outbound for safe synthetic handler coverage, but exercised the real Docker/Nginx/PostgreSQL/Supervisor queue topology with the split `default` and `telegram-outbound,notifications` workers.

## Synthetic Versus Real External Calls

Synthetic Telegram handler scenarios set `DOGFOOD_FAKE_TELEGRAM_OUTBOUND=true` by default. This replaces outbound Telegram API calls with `FakeTelegramBotClient` inside the Docker app worker, so the benchmark does not send real Telegram messages while still exercising the application handler, queue, database, cart, checkout, and manual-payment code paths.

The benchmark does not fake inbound webhook validation. It still posts JSON updates to `POST /api/webhook/telegram` with the configured webhook secret through Nginx.

Pakasir reconciliation is run as an operational command. With the default manual payment mode and no Pakasir fixtures, it measures the empty/safe command path. Real Pakasir transaction-detail benchmarking still requires the external fixtures documented in `src/README.md` and should use `src/scripts/perf-dogfood.php` for those fixture-specific claims.

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
