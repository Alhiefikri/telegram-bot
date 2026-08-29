# Security & Performance Hardening — Audit Findings and Work Plan

**Date:** 2026-07-26
**Status:** Audit complete. A first batch of safe fixes is applied and test-verified (full suite: 728 tests, 0 failures). The remaining items below are documented so any session can pick them up without re-auditing.

> **Ringkasan (ID):** Audit keamanan + performa sudah selesai. 6 perbaikan aman **sudah diterapkan & lulus tes** (lihat Bagian A). Yang **belum** dikerjakan dan butuh keputusan ada di Bagian B (urut prioritas) — yang paling penting soal **uang** (B2 pembayaran telat, B3 refund). Bagian D = hal yang sudah terbukti aman, jangan diaudit ulang.

---

## How to use this doc

Pick an item from **Part B** (highest priority first). Each item lists: the exact symbols/files, the problem, the fix (including the existing pattern to copy), how to verify, and any decision needed. Read **Part 0** first — it contains environment gotchas that will otherwise waste time.

---

## Part 0 — Environment & workflow (read first)

- **`.env` has `APP_ENV=production`.** `App\Bootstrap\ProductionConfigurationValidator::assertValid()` is called from `AppServiceProvider::boot()` on **every** `php artisan` invocation (including `php artisan test`), *before* PHPUnit swaps in the `testing` env. **Do not** add a hard boot-time `throw` for a production-only required var that the local `.env` doesn't set — it breaks the test runner and all local tooling. (This is why the `ADMIN_ALLOWED_LOGINS` enforcement in B1 is documented, not enforced in code.)
- **Tests run on SQLite `:memory:`** (`phpunit.xml`). Any raw SQL must be SQLite-portable (use `CASE`, not `GREATEST`). Run: `php artisan test` (or a path/`--filter`). App code lives under `src/`.
- **CI gates on Pint** (`./vendor/bin/pint --test`) — fixers in play include `fully_qualified_strict_types` (import types, don't inline-FQN) and `ordered_imports`. Run `./vendor/bin/pint <files>` before finishing.
- **CI gates on migration filename prefixes** (see commit "Gate code style and migration prefixes in CI"). New migrations use the `YYYY_MM_DD_NNNNNN_*` convention already present.
- **Queue topology (already well-designed):** four named queues — `default` (inbound Telegram updates), `telegram-outbound` (buyer sends), `notifications` (order lifecycle), `telegram-broadcast` (fan-outs). Workers configured in `supervisord.conf` / `docker-compose.yml`. Reuse `telegram-broadcast` for any new fan-out.

---

## Part A — Already applied and verified

| File | Change |
|------|--------|
| `src/database/migrations/2026_07_26_000001_add_performance_indexes.php` (new) | Indexes: `order_items.order_id`, `order_items.product_id`, `product_stock_items.order_item_id`, `orders.created_at`, `payment_events.received_at`, `audit_logs.created_at`, `sales_proof_posts.status` |
| `src/app/Services/PaymentReceiptReport.php` | Added `idrNetTotalCanonical()` — sums the read-only balance **in SQL** instead of hydrating every paid payment. Extracted `canonicalPaidPaymentsQuery()`. Semantics identical to `idrNetTotal(canonicalPaidPayments())`. |
| `src/app/Services/Admin/AdminDashboardData.php` | `metrics()` now calls `idrNetTotalCanonical()` for `read_only_balance_amount` |
| `src/app/Http/Requests/Admin/AdminProductFormRequest.php` | Image rule gains `dimensions:max_width=6000,max_height=6000` (blocks decompression-bomb DoS) |
| `src/app/Storefront/StorefrontCatalogSearch.php` | Escapes LIKE wildcards `%` `_` `\` (mirrors Telegram path) |
| `nginx/default.conf` | `X-Content-Type-Options: nosniff`, CSP `frame-ancestors 'self' https://*.telegram.org` (anti-clickjacking, Telegram Mini App still works), HSTS |
| `docker-scheduler.sh` | Drops to `laravel` user (was running `schedule:run` as container root) |
| `.env.example`, `src/.env.example` | Documented `ADMIN_ALLOWED_LOGINS` and its fail-open risk |
| `src/database/migrations/2026_07_26_000002_add_scheduler_recovery_indexes.php` (new) | **B6** — 7 pgsql-guarded partial indexes for the every-minute recovery schedulers (`telegram_updates` + 6 on `orders`). No-op on SQLite; validated on Postgres by CI's `postgres-release-gate`. See B6 below. |
| `src/app/Storefront/StorefrontCatalogPageBuilder.php` | **B7** — featured product derived from the already-fetched catalog collection when unfiltered (`featuredFromCatalog()`), removing a duplicate heavy products query on the hot home render path; search path still uses the global query. See B7 below. |
| `src/tests/Feature/StorefrontCatalogPageBuilderTest.php` | **B7** — 2 tests: query-dedup guard + search-keeps-global-featured behavior lock |

Verified: `php artisan test` → 730 tests, 714 passed, 16 skipped, 0 failed (B6/B7 batch added 2 tests). Changed files pass Pint.

---

## Part B — Remaining work (prioritized)

### B1 — Admin dashboard is fail-open (MEDIUM) — **operator action, not code**

- **Where:** `src/config/auth.php:34` (`admin_allowed_logins` defaults to `env('ADMIN_ALLOWED_LOGINS')` → `null`); `src/app/Models/User.php:45-47` returns `true` for any role-holder when the allow list is empty.
- **Problem:** With `ADMIN_ALLOWED_LOGINS` unset (current production state), *any* account holding any admin role can access the dashboard. Bounded by: no self-registration, per-action policies still enforced.
- **Action:** Set `ADMIN_ALLOWED_LOGINS=<admin-email(s)>` in the production `.env` (comma-separated emails/usernames). Must be done by someone who knows the real admin accounts — a wrong value locks the admin out.
- **Optional code follow-up:** once the value is set in prod, a check can be re-added to `ProductionConfigurationValidator` — but see Part 0 (the local `.env` is `production`, so also set the var locally or the validator throws in tooling).

### B2 — Late payments capture money with nothing delivered (HIGH) — **money**

> **UPDATE 2026-08-26:** the retirement lane now records provider cancellation work in `ProviderCancellationAttempts`, dispatches the compatibility job on the supervised `notifications` queue, and blocks the retirement contract until every dispatchable/retryable attempt is terminal.

- **Where / evidence:**
  - Release happens in `OrderStateMachine::applyReleaseTransition` (line 340; releases reservations + credentials at 364-365), reached from `orders:expire-pending` (`routes/console.php`), `AdminOrderCancelReleaseController`, and Telegram buyer cancel.
  - A late payment on the still-live QR lands in `OrderStateMachine::markLatePaymentPaid` (line 296) → payment becomes `Paid` on an Expired/Cancelled order, **no goods, no admin notification** (only `DeleteAdminPendingOrderNotification`; surfaced only by a passive dashboard badge).
- **Fix:**
  1. Keep `ProviderCancellationAttempts::schedule()` in the release transaction and process the durable attempt through the compatibility job; do not delete that lane until the retirement gate is satisfied.
  2. In `markLatePaymentPaid`, when the transition is newly applied, dispatch an admin notification (a late-payment needs a human) instead of relying on the badge.
- **Verify:** feature test — create order → expire → simulate a Pakasir "completed" callback → assert (a) no credentials delivered, (b) admin notified, (c) if the cancel was called, the provider cancel path was invoked. Use the existing `FakeTelegramBotClient` and Pakasir test doubles.
- **Related (do together):** B3-credentials and the Telegram verify-before-cancel gap (see C-list).

### B3 — No refund execution/tracking path (HIGH) — **money + product decision**

> **⚠️ UPDATE 2026-07-26 — credential-safety change attempted and reverted.** Making `releaseRefundedOrderResources` stop re-pooling delivered credentials (option A) **breaks two named tests**: `OrderCartDomainTest::test_processing_refund_releases_credential_without_releasing_sold_stock` and `::test_completed_refund_keeps_order_completed_and_releases_credential`. Both explicitly assert that refunding a *delivered* order returns the credential to the pool (`order_item_id` → null). So current re-pooling is **intentional, named, tested behavior**, not an oversight. Option A is a deliberate design reversal (re-selling a returned credential vs. rotating/retiring it) — a product decision. The refund *execution* path (wiring `applyPaymentRefunded` to an admin action) is still genuinely missing and can be added, but decide the credential policy first and update those two tests accordingly.

- **Where:** `OrderStateMachine::applyPaymentRefunded` (line 135) has **no production caller** (only the `OrderLifecycle` interface at `OrderLifecycle.php:16` and `tests/Feature/OrderCartDomainTest.php`). `releaseRefundedOrderResources` (line 397) is invoked at line 150 when a refund is applied.
- **Problem:** Refunded-in-real-life money stays `Paid` forever and is counted as revenue by `PaymentReceiptReport`. There's no admin action to record a refund.
- **Fix:** add an admin refund operation (dashboard button + Telegram intent) that calls `applyPaymentRefunded`; clear any "late-paid attention" state once `payment_status === Refunded` (the projection already handles this).
- **⚠️ Decision needed (credential handling on refund of a *delivered* order):** `releaseRefundedOrderResources` currently nulls `order_item_id` on already-delivered `ProductStockItem`s (LOW-1), which re-pools a credential the refunded buyer already received (`ProductCredentialAllocator` allocates lowest-id-first, so it gets re-sold to the next buyer). Choose:
  - **(A, recommended)** mark those credentials consumed/compromised (keep `order_item_id`, or add a `compromised_at` flag) so they are never re-sold — prevents the refunded buyer retaining access to an account sold to someone else.
  - **(B)** restore `product.stock` in the same transaction and re-pool — cheaper on inventory, but the refunded buyer still holds the credential.
- **Verify:** feature test for the refund action (state transitions + revenue exclusion) and for the chosen credential behavior.

### B4 — Synchronous Telegram fan-out on request/inline paths (CRITICAL/HIGH perf) — ✅ bot broadcast path fixed 2026-08-13

> **✅ APPLIED for the live bot-admin path.** `TelegramBroadcastIntents` now materializes a broadcast snapshot (including localized reply markup) and dispatches `SendTelegramBroadcastBatch` on `telegram-broadcast`. The `ProcessTelegramUpdate` worker acknowledges and queues the work without looping over customers; the existing per-recipient claim, retry, and idempotency pipeline is reused.

- **Where:** `TelegramBroadcastService::sendToActiveUsers` (line 134) sends to every customer serially with a blocking `sendMessage` (10s timeout each). Callers:
  - **Live:** `SendProductStockNotifications` job (`tries=1`, no `$timeout`) → `TelegramProductNotificationService::notifyStockAdded` → `sendToActiveUsers`. Truncates & is unrecoverable on timeout.
  - **Fixed:** `TelegramBroadcastIntents` (bot-initiated admin broadcast) now queues the durable batch pipeline instead of running inside `ProcessTelegramUpdate` on the `default` queue.
  - **Latent:** `AdminProductCreateController.php:49` → `notifyProductCreated` (currently a no-op because products are created with `stock=0`; becomes live if that changes).
- **Fix:** route all three through the durable batched pipeline that the **dashboard broadcast already uses correctly** — copy the shape of `TelegramBroadcastService::queueAdminBroadcast` (line 31), `SendTelegramBroadcastBatch`, and `ProductPriceChangeNotificationService` / `SendProductPriceChangeNotifications`: materialize recipients, batch 25, per-recipient claim tokens + attempts, self-requeue with backoff, on the `telegram-broadcast` queue. Reply "queued" immediately from the bot path.
- **Verify:** assert the request/job enqueues batches instead of sending inline; assert a mid-run failure resumes without duplicate sends (claim tokens).

### B5 — Pakasir verify blocks the request path ~31s > 15s single-flight lease (HIGH perf)

- **Where:** `PakasirClient` transaction-detail retry (≈3×10s + backoff). Request-path call sites: `PakasirWebhookController` → `PakasirCallbackProcessor::verifyWithPakasir`; and `StorefrontShopPaymentStatusRefresh` (invoice status endpoint). Lease: `StorefrontShopPaymentRefreshCoordinator` + `config('services.pakasir.status_refresh_lock_lease_seconds')` = 15s.
- **Problem:** during a Pakasir brownout, each call pins an FPM worker up to ~31s (pool = 12 → exhaustion → site-wide 502), and the single-flight lock lease expires mid-call, so a second concurrent provider call proceeds (breaks the single-flight guarantee from commit 9cfbd3f).
- **Fix (pick one, prefer the first):** give request-path calls a smaller retry budget (attempts=1–2 with ~5s timeout) via a config profile so worst case < lease; **or** raise the lease above worst case (≥35s). Ideally: ack the webhook fast (202) and verify + `applyPaid` in a queued job.
- **Verify:** unit-test the request-path client budget; assert the status endpoint returns its `in_progress` + `retry_after` contract without exceeding the lease.

### B6 — Every-minute schedulers seq-scan unindexed columns (HIGH perf) — **Postgres partial indexes** — ✅ DONE 2026-07-26

> **✅ APPLIED.** `src/database/migrations/2026_07_26_000002_add_scheduler_recovery_indexes.php` adds 7 pgsql-guarded partial indexes (raw `DB::statement` `CREATE INDEX IF NOT EXISTS`, guarded by `if (DB::getDriverName() !== 'pgsql') return;`). Each predicate mirrors a recovery query's WHERE so PostgreSQL keeps only in-flight rows in each index (finished rows excluded → tiny indexes, zero write overhead on the common already-delivered path). SQLite tests skip it (no-op) and stay green; the raw SQL is exercised against real Postgres 16 by CI's `postgres-release-gate` job (`migrate:fresh` at `.github/workflows/ci.yml:205`), which is the validation path since no local Postgres is reachable.

- **Indexes:** `telegram_updates_recovery_idx` on `telegram_updates(id) WHERE processed_at IS NULL AND terminal_failed_at IS NULL`; and on `orders(id)` — `orders_recover_receipt_idx`, `orders_recover_admin_pending_idx`, `orders_recover_admin_paid_idx`, `orders_recover_admin_expired_idx` (each `WHERE <sent_at> IS NULL AND <started_at> IS NOT NULL`), `orders_recover_manual_notify_idx` (same shape), and `orders_recover_binance_delete_idx` (`WHERE manual_checkout_customer_notification_message_id IS NOT NULL AND manual_checkout_customer_notification_deleted_at IS NULL`).
- **Why the predicate matches even with `<= cutoff`:** the recovery queries use `WHERE <sent_at> IS NULL AND <started_at> <= :cutoff`; `<started_at> <= :cutoff` implies `<started_at> IS NOT NULL`, so PostgreSQL's `predicate_implied_by` treats the partial index as usable. Indexed on `(id)` to also serve `ORDER BY id` / `chunkById`.
- **Note:** the `ProductPriceChangeEvent` recovery branch (OR + whereHas over `product_price_change_recipients`) was intentionally left un-indexed here — that table is low-volume (admin price changes are infrequent) and the OR/EXISTS shape doesn't fit a single clean partial predicate.

### B7 — Featured product ran its heaviest query twice (HIGH perf) — ✅ DONE 2026-07-26

> **✅ APPLIED (featured-product dedup) + scope correction on `/shop`.** `StorefrontCatalogPageBuilder::build()` now derives the featured product from the already-fetched `$catalogProducts` collection when the catalog is unfiltered (`$search === ''`) via the new `featuredFromCatalog()`, instead of re-running the whole products query. This removes one withSum-correlated + availability-EXISTS query on every home cache-miss render (the storefront cache is invalidated on **every Product/Category save** — incl. stock reservations — so that path is hot). Search results still call the global `featuredProduct()` query so the featured slot stays independent of the active filter (locked by `test_featured_product_stays_global_when_search_filters_it_out`; dedup locked by `test_featured_product_reuses_catalog_without_a_second_products_query`).
>
> **Correctness basis:** `presentation->isAvailable` (`availableStock() > 0`) is exactly equivalent to the `availableForPurchase()` scope, and both queries share `storefrontOrder()` (available-first), so the first available entry in the fetched catalog == `availableForPurchase()->storefrontOrder()->first()` when unfiltered.
>
> **Shop catalog boundary (updated for #378, 2026-08-29).** `StorefrontShopController` is now the live handler for `/shop`. It renders the dedicated catalog with a bounded 24-item page, validated `q`, `category`, `sort`, and `page` state, and intentionally stays uncached. `/` remains the curated homepage served through `StorefrontResponseCache`; arbitrary shop query values never become homepage cache-key inputs.
>
- **Verify:** focused storefront catalog tests cover the dedicated route, pagination, category navigation, sort state, invalid values, and product return context. Homepage cache tests remain in `StorefrontShopCatalogTest`.

---

## Part C — Medium / Low backlog (schedule later)

- **Telegram cancel lacks verify-before-cancel** (money race): `TelegramOrderCallbackHandler::cancelPendingOrder` (~73-98) cancels directly; port the guard from `StorefrontShopOrderCancelController` (~25-49) which verifies `transactionDetail` first and applies paid if completed. Do with B2.
- **Web repeat-purchase dead-end** (lost revenue): `OrderCheckoutService` lookup by `checkout_key` (~line 50) has no status filter and returns terminal orders; session `checkout_client_key` (`StorefrontShopCheckoutController:29-33`) never rotates; `checkout_key` nulled only on Cancelled, not Expired (`OrderStateMachine:370-374`). Fix: rotate the session key after checkout, and/or skip terminal orders in the lookup + null `checkout_key` on expiry.
- **Cancelled-cart re-checkout latent bug**: `OrderCheckoutService` reactivation branch (~56-63) will always violate the `orders.cart_id` unique constraint; currently unreachable. Delete the branch or null `cart_id` on release.
- **Admin login hardening**: timing-based user enumeration (`AdminLoginController` — `Hash::check` short-circuits when user absent); throttle keyed on `login|IP` only (no per-account lockout). Add a dummy hash on the not-found path + an account-scoped limiter.
- **`TRUSTED_PROXIES=127.0.0.1`** doesn't match the nginx container IP, so per-IP throttles collapse onto one bucket (DoS lever on webhooks). Set to the compose subnet; also set `TRUSTED_HOSTS`.
- **Perf mediums:** admin dashboard builds every tab per request (`AdminDashboardData:37-53` — render only the active tab); earnings chart aggregates in PHP (`AdminDashboardStatsData:195-219` — do it in SQL, reuse the balance query); DB-backed cache/session/queue with no GC on expired versioned homepage-cache rows (add a daily prune); `binancePayEnabled()` / `ManualPaymentSetting::current()` not memoized (`TelegramCheckoutIntents:69-71` — memoize); invoice QR re-rendered per view (`StorefrontShopSignedInvoice:111` — cache).
- **Infra lows:** `Dockerfile` never applies `php.ini-production` (so `zend.exception_ignore_args` stays off — exception args reach logs); deploy uses TOFU `ssh-keyscan` (`deploy-production.yml`, `recover-failed-jobs.yml` — pin the host key); benchmark stack default creds (`docker-compose.benchmark.yml:10`); base images pinned by mutable tag (pin by digest); untrack `.claude/settings.local.json`; optional history rewrite to drop the `cloudflared.deb` blob.
- **Webhook defense-in-depth:** Pakasir callback has no inbound signature (safe today via server-to-server re-verify) and writes a `payment_events` row before rejecting unknown orders (`PakasirCallbackProcessor` — move the unknown-payment check before `events->log()`).

---

## Part D — Verified secure — **do not re-audit**

These were checked by the audit and are correctly implemented; treat as trusted unless the relevant code changes:

- **Payment forgery blocked** — every Pakasir callback re-verifies against the authenticated provider API (`PakasirCallbackProcessor::verifyWithPakasir` → `PakasirClient::transactionDetail`) with strict int amount/project/order/completed checks before `applyPaid`. Fails closed if unconfigured.
- **Telegram webhook** — `X-Telegram-Bot-Api-Secret-Token` validated with `hash_equals`, fails closed in prod; replay-proof via `telegram_updates.update_id` unique + `insertOrIgnore` + atomic DB claim (`TelegramUpdateReplayGuard`).
- **No IDOR** — orders use `HasUuids`; access gated by HMAC signed URLs (`ValidateStorefrontSignedInvoice`), per-order session grants (`StorefrontOrderAccess`), or `customer_id` scoping.
- **Magic-link login** — 256-bit token, SHA-256 at rest, single-use, 15-min, verified under `lockForUpdate` + `hash_equals`, delivered in URL fragment. Order-claim requires the recovery key and refuses owned orders.
- **Money correctness** — prices read from DB under `FOR UPDATE`; client sends only product_id/qty; all-integer money; payment idempotency via unique `(provider, event_type, idempotency_key)` + coalescing + `applyPaidTransition` row locks; stock/credential races serialized under the product row lock with CHECK constraints; state machine rejects illegal transitions.
- **Input** — no SQL injection (all raw SQL parameterized), no exploitable mass assignment, uploads re-encoded to WebP with UUID names, Blade output escaped, no command/path injection, open-redirect defended.
- **Secrets** — none committed anywhere in tree or history; log redaction (`TelegramBotApiRedactor`, `PakasirPayloadSanitizer`, `AuditLogger`) is real and consistently applied. CI is SHA-pinned with `composer`/`npm audit` + dependabot.
- **Response cache** — homepage cache is stampede-safe (double-checked `Cache::lock` + graceful uncached fallback), O(1) version-bump invalidation.
