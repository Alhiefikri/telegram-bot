# Task 7 Report: KlikQRIS sandbox callback verification

## Scope

Implemented Task 7 only in `/home/alhiefikri/telegram-bot/.worktrees/klikqris-sandbox`.

Files changed:

- `src/app/Http/Controllers/KlikQrisSandboxWebhookController.php`
- `src/app/Services/KlikQris/KlikQrisCallbackProcessor.php`
- `src/app/Services/KlikQris/KlikQrisPaymentEventLogger.php`
- `src/app/Bootstrap/ApplicationRateLimiterRegistrar.php`
- `src/routes/api.php`
- `src/tests/Feature/KlikQrisWebhookTest.php`

`task-7-brief.md` was not present in the worktree root, so I used the user-provided binding brief plus:

- `docs/superpowers/plans/2026-07-28-klikqris-sandbox-integration.md`
- `docs/superpowers/specs/2026-07-28-klikqris-sandbox-integration-design.md`
- Existing Pakasir webhook/event patterns
- Existing KlikQRIS callback/status/client/event/payment patterns
- Existing order lifecycle paid transition and receipt dispatch behavior

No real environment credentials were inspected. Tests use fake KlikQRIS config and `Http::fake()` only.

## TDD evidence

### RED

Command:

```bash
cd src && php -d opcache.enable_cli=0 artisan test tests/Feature/KlikQrisWebhookTest.php
```

Observed result before production implementation:

- 9 failed tests.
- Failures were expected `404` responses for missing `/api/webhook/klikqris/sandbox` route/controller behavior.

### GREEN

Command:

```bash
cd src && php -d opcache.enable_cli=0 artisan test tests/Feature/KlikQrisWebhookTest.php
```

Observed result after implementation:

- 9 passed.
- 56 assertions.

### Post-Pint verification

Command:

```bash
cd src && php -d opcache.enable_cli=0 artisan test tests/Feature/KlikQrisWebhookTest.php
```

Observed result after formatting:

- 9 passed.
- 56 assertions.

## Behavior covered

- `POST /api/webhook/klikqris/sandbox` exists with `throttle:klikqris-sandbox-webhook`.
- Limiter keys by source IP and uses `services.klikqris.webhook_rate_limit_per_minute`.
- Pakasir webhook route and limiter remain present and covered by regression tests.
- Wrong content type returns 400 before status API or event logging.
- Invalid JSON returns 400 before status API or event logging.
- Malformed schema returns 422 before status API or event logging.
- Accepted, unknown, mismatch, non-final, duplicate, and outage cases return exactly `{"success":true}`.
- Unknown order logs a sanitized unprocessed event and does not call status API.
- Non-`PAID` callback logs a sanitized unprocessed event and does not call status API.
- Callback amount or total mismatch logs a sanitized unprocessed event and does not call status API.
- `PAID` callback never fulfills from callback or signature alone.
- Fulfillment requires authoritative status API `SUCCESS` plus identity, base amount, and total amount match.
- Authoritative `PENDING` leaves payment pending and event unprocessed.
- Authoritative mismatch leaves payment pending and event unprocessed.
- Status/readiness outage after callback event storage returns 200 and leaves event unprocessed for later reconciliation.
- Recursive sanitizer removes top-level and nested `signature` values from persisted raw payload.
- Event idempotency hash is built from sanitized canonical payload, so duplicate callbacks with different signatures coalesce and increment `received_count`.
- Duplicate callbacks fulfill once and queue one receipt job.
- `processReconciliationForPayment(Payment $payment, KlikQrisTransaction $transaction)` shares the same authoritative/idempotent fulfillment core without trusting callback input.

## Verification commands

```bash
cd src && php -d opcache.enable_cli=0 artisan test tests/Feature/KlikQrisWebhookTest.php
```

Result: 9 passed, 56 assertions.

```bash
cd src && php -d opcache.enable_cli=0 artisan test tests/Feature/PakasirWebhookTest.php tests/Feature/KlikQrisGatewayTest.php tests/Feature/KlikQrisNativeQrisTest.php tests/Feature/Telegram/KlikQrisCheckoutTest.php
```

Result: 64 passed, 483 assertions.

```bash
cd src && ./vendor/bin/pint --dirty
```

Result: PASS, 6 files.

## Notes and follow-ups

- Task 8 can recover unprocessed KlikQRIS callback/reconciliation events through `processReconciliationForPayment()` using already-fetched authoritative transactions.
- No production webhook or production switch was added.
- No Docker refresh was performed locally. Because PHP route/service files changed, the running Docker app should be refreshed before local runtime validation if needed.
