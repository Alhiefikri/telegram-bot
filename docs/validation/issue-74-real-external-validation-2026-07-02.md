# Issue 74 Real External Validation Report

Date: 2026-07-02

Status: **passed for manual-payment pre-production scope**.

This run validated the live Telegram integration through a public HTTPS webhook, real Telegram outbound delivery, the Store Channel community gate, manual payment proof forwarding, admin approval, receipt delivery, and Sales Proof Post publishing. Pakasir was intentionally out of scope because the dogfood environment was configured for manual payment mode.

No bot token, webhook secret, private chat ID, Telegram file ID, Pakasir key, or Store Channel private identifier is recorded here.

## Scope

| Area | Result | Notes |
| --- | --- | --- |
| Public HTTPS app URL | Passed | Public `/up` returned HTTP 200 through the dogfood Docker web service. |
| Telegram webhook | Passed | Public webhook accepted real-flow test updates with the configured secret and queued processing through the worker. |
| Buyer flow | Passed | `/start`, `/menu`, catalog, search, product detail, add cart, checkout, `/status`, and cancel/empty-cart coverage were exercised against the real bot path. |
| Manual checkout notification | Passed | Customer QR/manual payment instructions and admin checkout notification were delivered after the missing public QR file was restored in the Docker public volume. |
| Payment proof photo | Passed | A real Telegram photo file reference was submitted with `/payment INVOICE`; the proof was forwarded to admin and the buyer confirmation was sent. |
| Admin approve | Passed | Admin approve callback completed the manual order, marked payment paid, allocated one stock item, and queued receipt delivery. |
| Telegram receipt | Passed | Buyer receipt was sent and `telegram_receipt_sent_at` was set. |
| Store Channel gate | Passed | Member identity was allowed; non-member identity was prompted by the community gate. |
| Store Channel test send | Passed | Admin-authenticated Store Channel test posted successfully and stored a successful test result. |
| Sales Proof Post | Passed | Sales Proof Post was published once with status `sent` and no recorded error. |
| Broadcast | Skipped by scope | Not run because it would message active real users; issue scope allowed broadcast only when safe disposable recipients are available. |
| Pakasir | Out of scope | `TELEGRAM_PAYMENT_MODE=manual`; no Pakasir live transaction was used. |
| Final operations | Passed | Queue depth was `0`, failed jobs was `0`, and ops health returned DB, queue schema, and Supervisor OK. |

## Final Order Evidence

| Check | Result |
| --- | --- |
| Latest dogfood order | Completed manual order |
| Order status | `completed` |
| Payment status | `paid` |
| Payment provider | `manual` |
| Stock allocation | 1 stock item allocated |
| Receipt | Sent |
| Sales Proof Post | `sent`, attempts `1`, `last_error=null` |
| Queue depth | `0` |
| Failed jobs | `0` |

## Remediations During Run

| Blocker | Resolution |
| --- | --- |
| Public URL initially returned ngrok offline 404 | Restarted ngrok against the Docker web port and verified public `/up` returned 200. |
| Docker image did not expose the latest webhook command/migration | Rebuilt and restarted the Docker stack from current source. |
| Existing PostgreSQL volume expected the original database/user names | Restored dogfood `.env` database name/user to match the existing volume and reset the role password. |
| Stale failed jobs existed before the clean run | Reviewed them as historical failures, then flushed before the final dogfood run. |
| Manual QR file was missing from the Docker public volume | Restored `public/qr-lhie.jpg` inside the app container public volume and retried the notification job successfully. |
| Log permissions blocked earlier worker writes | Fixed storage log ownership/permissions before rerunning the affected path. |

## Commands Used

Representative command forms from the run:

```bash
docker compose up -d --build
docker compose ps
docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan telegram:webhook:set
docker compose exec -T app php artisan ops:health --db --queue-schema --supervisor
docker compose exec -T app php artisan queue:flush
docker compose exec -T app php artisan queue:retry all
npm run test:browser:docker
```

The real Telegram webhook posts were sent to `APP_URL/api/webhook/telegram` with the configured `X-Telegram-Bot-Api-Secret-Token`; payloads and fixture values are intentionally omitted from this report.

## Final Verification

```text
DB: ok
Queue schema: ok
Supervisor: ok
jobs_depth: 0
failed_jobs: 0
Docker browser smoke: 3 passed
```

## Residual Notes

Telegram `getWebhookInfo` may retain historical `last_error_*` fields from earlier outage windows even after the current run is clean. Use current public `/up`, successful webhook POSTs, drained queue, failed job count, and current Docker logs for the final run window.
