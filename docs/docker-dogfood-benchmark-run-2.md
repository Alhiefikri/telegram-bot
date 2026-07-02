# Docker Dogfood Benchmark Run 2

Issue: #64
Date: 2026-07-02

## Scope

This run repeats the production-like Docker dogfood benchmark after the run-1 harness blockers were fixed:

- dogfood timing logs use `stderr` and the perf script runs as the `laravel` user;
- dogfood Docker database state is explicit;
- admin mutation timings use an authenticated HTTP runner.

Context source: local repo context and `src/README.md` dogfood instructions. A Context7 tool was not available in this Codex environment.

## Harness

- Compose project: `telegram-bot-dogfood`
- Web port: `18080`
- State policy: fresh dogfood volumes
- Timing channel: `DOGFOOD_TIMING_LOG_CHANNEL=stderr`
- App environment: production
- PHP: 8.4.22
- Laravel: 13.16.1
- DB: PostgreSQL through Docker Compose
- Queue: database
- Telegram API base URL: `https://api.telegram.org`

Preflight first detected old dogfood volumes. The first reset attempt failed because existing dogfood containers still held the volumes, and a warm-state start reproduced an unhealthy app dependency. The project was then stopped with `docker compose -p telegram-bot-dogfood down -v`, preflight confirmed no remaining dogfood volumes, and the benchmark was rerun from fresh state.

Fresh build/start/healthy time: 31 seconds.

## Validation

- `/up` smoke: passed.
- `ops:health --db --queue-schema --supervisor`: passed.
- scheduler heartbeat health: passed.
- Docker browser smoke: 3 passed.
- Failed jobs after perf run: 0.
- Containers after perf run: app, web, db, and scheduler all healthy.

Container memory after perf run:

| Container | Memory |
| --- | ---: |
| web | 1.688 MiB / 128 MiB |
| scheduler | 1.938 MiB / 256 MiB |
| app | 26.19 MiB / 768 MiB |
| db | 15.3 MiB / 768 MiB |

## Perf Results

The perf script produced 59 result rows:

- 51 executed without errors.
- 8 were blocked because optional real external fixtures were not configured.
- 0 error result rows.

Key timings:

| Scenario | Result | Cold | Warm avg | Slowest |
| --- | --- | ---: | ---: | ---: |
| Admin login through HTTP kernel | `302->200` | 489.694 ms | n/a | 489.694 ms |
| Admin payment mode invalid form | `302->200` | 30.267 ms | 28.111 ms | 30.267 ms |
| Admin payment mode valid manual form | `302->200` | 42.601 ms | 43.139 ms | 43.139 ms |
| Admin category create invalid form | `302->200` | 43.630 ms | 46.301 ms | 46.301 ms |
| Admin category create valid form | `302->200` | 58.625 ms | 50.555 ms | 58.625 ms |
| Admin product create invalid form | `302->200` | 49.604 ms | 41.219 ms | 49.604 ms |
| Admin product create valid form | `302->200` | 77.751 ms | 66.917 ms | 77.751 ms |
| Telegram webhook happy path | `200` | 62.992 ms | 18.914 ms | 62.992 ms |
| Telegram duplicate update | `200/200` | 33.859 ms | 31.774 ms | 33.930 ms |
| Telegram invalid secret | `403` | 7.196 ms | 9.538 ms | 10.551 ms |
| Telegram unsupported update | `422` | 15.373 ms | 11.744 ms | 15.373 ms |
| Manual checkout QR happy path | `ok` | 4,874.293 ms | 4,368.479 ms | 4,874.293 ms |
| Manual checkout empty cart | `ok` | 2,563.075 ms | 2,512.540 ms | 2,563.075 ms |
| `orders:expire-pending` | `0` | 13.246 ms | 6.249 ms | 13.246 ms |
| `pakasir:reconcile --chunk=5` | `0` | 9.889 ms | 5.388 ms | 9.889 ms |
| `ops:health --db --queue-schema` | `0` | 17.239 ms | 12.695 ms | 17.239 ms |
| Burst: 10 sequential `/menu` updates | `ok` | n/a | 1,319.937 ms/update | n/a |

Aggregate dogfood timing logs:

| Metric | Count | Average | P95 | Max |
| --- | ---: | ---: | ---: | ---: |
| Queue wait | 74 | 17.865 ms | 27 ms | 43 ms |
| Handler duration | 74 | 1,838.166 ms | 2,676.700 ms | 4,839.330 ms |
| Telegram outbound HTTP | 106 | 1,259.419 ms | 1,489.474 ms | 2,355.472 ms |

Manual checkout QR breakdown:

| Run | Total | Queue wait | Worker processing | Dominant internal step |
| --- | ---: | ---: | ---: | --- |
| cold | 4,874.293 ms | 14 ms | 4,839.330 ms | `manual_customer_send_photo` at 2,355.540 ms |
| warm-1 | 4,368.479 ms | 13 ms | 4,331.630 ms | `manual_customer_send_photo` at 2,004.610 ms |

The manual checkout path is still dominated by real Telegram outbound calls, not DB time or QR file reads.

## Comparison With Run 1

Run 1 findings from issue #60:

- webhook ack sequential average: 73.57 ms;
- concurrent 10 valid updates average: 320.91 ms;
- queue wait average under burst: 13.44 s, p95 27.40 s, max 28.95 s;
- worker handler average: 1.43 s;
- Telegram outbound average: 1.30 s, p95 1.45 s;
- manual checkout: about 4.4-4.9 s;
- failed jobs: 3.

Run 2 interpretation:

- The harness noise is resolved for this controlled run: fresh DB state, healthy containers, safe `stderr` timing logs, and zero failed jobs.
- Warm webhook ack improved in this script-level run: happy-path warm average was 18.914 ms.
- Queue wait did not reproduce the run-1 burst backlog. Run 2 queue wait averaged 17.865 ms with p95 27 ms and max 43 ms.
- Telegram outbound latency reproduced. Run 2 outbound average was 1.259 s with p95 1.489 s.
- Manual checkout duration reproduced at roughly 4.37-4.87 s, with customer `sendPhoto` plus admin `sendMessage` as the main cost.
- Admin mutation scenarios are now measurable through authenticated form/session flow and did not hit CSRF errors.

## Issue 65 Queue Wait Decision

Issue #65 asked whether burst queue wait was caused by one worker plus real Telegram outbound latency. Run 2 does not support changing worker count or queue topology yet:

- the run used a single Supervisor queue worker, matching production-like Docker;
- queue wait stayed low at 17.865 ms average, 27 ms p95, and 43 ms max;
- failed jobs stayed at zero;
- handler duration and manual checkout duration were still dominated by real Telegram outbound calls.

Historical decision for this run: keep the one-worker Docker default until a clean benchmark reproduces sustained queue wait. This was superseded by issue #70, which isolates manual checkout Telegram outbound work into a separate `telegram-outbound` queue after order checkout has committed. Newer benchmark interpretation should compare update-worker processing against manual outbound job queue wait/processing instead of treating the whole manual checkout as one update-worker handler.

## Blocked External Fixtures

The following real-network scenarios were intentionally marked blocked rather than faked:

- manual payment proof photo: missing `DOGFOOD_TELEGRAM_PHOTO_FILE_ID`;
- Store Channel send and Community Gate: missing Store Channel fixture variables;
- Sales Proof Post publish/retry: missing Store Channel fixture variables;
- Pakasir transaction-detail: missing `DOGFOOD_PAKASIR_PENDING_ORDER_ID` and `DOGFOOD_PAKASIR_PENDING_AMOUNT`;
- real Telegram callback-answer: missing `DOGFOOD_TELEGRAM_CALLBACK_QUERY_ID`.

## Artifacts

Ignored local artifacts were written under `.slim/deepwork/issue-64-run2/`:

- `perf-dogfood.json`
- `perf-summary.json`
- `perf-dogfood.stderr`
- `compose-up-fresh.log`
- `smoke-production.txt`
- `browser-docker-smoke.txt`
- `failed-jobs-check.txt`
- `container-stats-after-perf.txt`
- `docker-logs-tail.txt`

The dogfood stack was left running for inspection at `http://127.0.0.1:18080`.
