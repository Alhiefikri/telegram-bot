# Task 6 Report: Deliver native KlikQRIS directly in Telegram

## Commit

- Commit: `f0f204a feat: send KlikQRIS directly in Telegram`
- Working tree after commit verification: `git status --short` returned empty before this SDD report follow-up was requested.
- This follow-up writes only this SDD report file and does not change source files.

## Files changed in `f0f204a`

### Created

- `src/app/Jobs/SendKlikQrisCheckoutInstructions.php`
- `src/app/Services/KlikQris/KlikQrisQrisImage.php`
- `src/tests/Feature/Telegram/KlikQrisCheckoutTest.php`
- `src/tests/Feature/Telegram/KlikQrisExpiredNotifierTest.php`
- `src/tests/Unit/Services/KlikQris/KlikQrisQrisImageTest.php`
- `task-6-report.md`

### Modified

- `src/app/Services/Telegram/Callbacks/TelegramCheckoutCallbackHandler.php`
- `src/app/Services/Telegram/TelegramCheckoutIntents.php`
- `src/app/Services/Telegram/TelegramConfiguredCheckoutPaymentIntentSelector.php`
- `src/app/Services/Telegram/TelegramOrderExpiredNotifier.php`
- `src/app/Services/Telegram/TelegramPaymentInstructionRenderer.php`
- `src/tests/Feature/Telegram/CartCheckoutTest.php`

## RED/GREEN TDD evidence

### RED

Initial focused RED command:

```bash
cd src && php -d opcache.enable_cli=0 artisan test tests/Feature/Telegram/KlikQrisCheckoutTest.php tests/Unit/Services/KlikQris/KlikQrisQrisImageTest.php tests/Feature/Telegram/KlikQrisExpiredNotifierTest.php
```

Observed expected Task 6 failures after fixture cleanup:

- Missing `App\Jobs\SendKlikQrisCheckoutInstructions`.
- Missing `App\Services\KlikQris\KlikQrisQrisImage`.
- Checkout path still rejected `CheckoutPostCreationBehavior::KlikQrisInstructions` through the temporary pre-check/dispatch guard.
- Expired order notifier still used generic Order Again copy rather than KlikQRIS Create New QRIS copy.

### GREEN

Focused GREEN command after implementation:

```bash
cd src && php -d opcache.enable_cli=0 artisan test tests/Feature/Telegram/KlikQrisCheckoutTest.php tests/Unit/Services/KlikQris/KlikQrisQrisImageTest.php tests/Feature/Telegram/KlikQrisExpiredNotifierTest.php
```

Result:

- 9 tests passed.
- 59 assertions passed.

## Request and image security tests

Implemented `KlikQrisQrisImage::contents(KlikQrisTransaction $transaction): string` and covered it with `tests/Unit/Services/KlikQris/KlikQrisQrisImageTest.php`.

Coverage:

- Prefers allowlisted HTTPS `klikqris.com` sandbox `qris_url` download before base64 fallback.
- Uses Task 5 URL allowlist through `KlikQrisTransaction::isAllowedSandboxQrisUrl()`.
- Rejects non-HTTPS and non-`klikqris.com` URLs.
- Disables redirects with `allow_redirects => false`.
- Requires HTTP status 200.
- Requires `Content-Type` containing `image/png`.
- Validates PNG magic bytes, not only headers or filename.
- Rejects oversized remote bodies.
- Falls back to in-memory `data:image/png;base64,...` only when URL download fails.
- Strictly rejects malformed base64, non-data URI strings, SVG/HTML payloads, JPEG data URIs, and oversized decoded base64.
- Uses a generic `InvalidArgumentException('KlikQRIS QR image is unavailable.')` so remote response bodies, URLs, API keys, signatures, and other secrets are not exposed in the exception message.
- No raw base64 persistence was added.
- Renderer filename is `.png`, and accepted bytes are normalized to PNG only.

## Job uniqueness, retry, and idempotency evidence

Implemented `SendKlikQrisCheckoutInstructions` with the same core semantics as `SendPakasirCheckoutInstructions`:

- Implements `ShouldBeUnique` and `ShouldQueue`.
- `uniqueId()` returns the order id.
- `uniqueFor = 3600`.
- `tries = 3`.
- `backoff()` returns `[5, 30, 120]`.
- Uses `manual_checkout_customer_notification_started_at` as the send claim.
- Uses `manual_checkout_customer_notified_at` as the delivery completion/idempotency marker.
- Uses active-claim lease logic from Pakasir based on `services.telegram.claim_lease_seconds`.
- Selects only the `provider = klikqris` payment from the order payment collection.
- On send failure, clears the started claim and rethrows so retry can proceed.
- On send success, marks notified and clears started claim.
- `ensureCreated` is called inside `TelegramCheckoutIntents::sendKlikQrisPaymentInstructions`, not inside the checkout pre-persistence path.
- Delivery claim owns the Telegram send. Confirmed successful sends are deduped by `manual_checkout_customer_notified_at`; ambiguous Telegram acceptance followed by timeout/crash may still duplicate on retry because Telegram has no idempotency key.

Test evidence:

- `test_klikqris_job_releases_claim_after_definite_send_failure_and_keeps_provider_transaction`
  - First mocked send throws a definite exception.
  - Claim is released.
  - Notified marker remains null.
  - Retry succeeds.
  - Existing `provider = klikqris` payment count remains one.

- `test_klikqris_job_dedupes_confirmed_successful_send_but_keeps_at_least_once_retry_semantics`
  - Runs the job twice after one confirmed successful Telegram send.
  - Only one Telegram `sendPhoto` request is recorded for this sequential confirmed-success path.
  - `ensureCreated` is called once.
  - The test name and report intentionally do not claim exactly-once behavior for ambiguous timeout/crash after Telegram acceptance.

## Selector, caption, and expiry coverage

### Selector

Implemented the real Task 4 selector seam:

- `TelegramCheckoutIntents::requestedPaymentMode()` returns `klikqris` only when `KlikQrisSandboxCheckoutSelector::enabled()` reports readiness.
- When sandbox readiness is disabled, it returns the existing manual/Pakasir result unchanged.
- `TelegramCheckoutIntents::paymentMode()` returns `klikqris` only for explicit KlikQRIS requested mode, otherwise delegates to the existing Pakasir readiness fallback logic.
- `TelegramConfiguredCheckoutPaymentIntentSelector` maps `paymentMode = klikqris` to `CheckoutPaymentIntents::klikQris()`.
- `TelegramCheckoutCallbackHandler` now dispatches `SendKlikQrisCheckoutInstructions` for `CheckoutPostCreationBehavior::KlikQrisInstructions` on `telegram-outbound`.
- The temporary KlikQRIS pre-check rejection was removed.
- The post-creation behavior guard remains an exhaustive `match`, preserving safety for future behavior changes.

Test evidence:

- `test_checkout_current_dispatches_only_klikqris_instruction_job_when_sandbox_is_ready`
  - Ready sandbox selects `requestedPaymentMode = klikqris` and `paymentMode = klikqris`.
  - Payment intent provider is `klikqris`.
  - Post-creation behavior is `KlikQrisInstructions`.
  - Dispatches `SendKlikQrisCheckoutInstructions` on `telegram-outbound`.
  - Does not dispatch Pakasir or manual instruction jobs.

- `test_sandbox_selector_is_fail_closed_when_not_ready_and_keeps_existing_pakasir_selection`
  - Disabled sandbox preserves existing Pakasir selection and does not dispatch KlikQRIS.

- Updated `CartCheckoutTest` regression:
  - `test_telegram_dispatches_klik_qris_checkout_intent_without_pakasir_or_manual_instruction_jobs`
  - Runs a synchronous Telegram checkout with faked KlikQRIS create and QR image requests.
  - Creates a KlikQRIS payment.
  - Sends a direct Telegram photo.
  - Records no Pakasir HTTP request.

### Caption and buttons

Implemented `TelegramPaymentInstructionRenderer::klikQris()`:

- Sends direct image bytes with filename `klikqris-{invoice}.png`.
- Uses `$payment->klikQrisTotalAmountDisplay()` for the exact final provider total.
- Includes the exact English warning: `Do not round or change any digit`.
- Includes invoice.
- Includes provider deadline and explicit 15-minute copy.
- Does not include hosted payment URL, redirect URL, Popup Snap, signature, API key, or base64 in caption.
- Buttons are exactly:
  - status callback: `order:{invoice}`
  - cancellation callback: `buyer:cancel-pending:{order-id}`

Test evidence:

- `test_klikqris_job_sends_one_direct_telegram_photo_with_safe_caption_and_buttons`
  - Asserts `sendPhoto` body contains PNG bytes and `klikqris-{invoice}.png` filename.
  - Asserts caption contains exact total `Rp 1.016`, warning text, invoice, `15 minutes`, and deadline time.
  - Asserts caption excludes `https://`, `Popup Snap`, signature token, and raw base64 wording.
  - Asserts status/cancel callback data in multipart request body.

### Expiry

Updated `TelegramOrderExpiredNotifier`:

- Loads payments with expired order notification.
- If any payment provider is `klikqris`, button copy becomes:
  - Indonesian: `🔄 Buat QRIS Baru`
  - English: `🔄 Create New QRIS`
- Callback remains `katalog`.
- It does not point to `order:{invoice}` or `buyer:cancel-pending:{order-id}` and does not resurrect old order/cart state.
- Non-KlikQRIS expired copy remains the prior generic Order Again copy.

Test evidence:

- `test_expired_klikqris_order_uses_create_new_qris_copy_and_catalog_callback`
  - Asserts English Create New QRIS copy.
  - Asserts callback `katalog`.
  - Asserts no stale order/cancel callback data in reply markup.

## Regression verification

Fresh post-format verification command:

```bash
cd src && php -d opcache.enable_cli=0 artisan test tests/Feature/Telegram/KlikQrisCheckoutTest.php tests/Feature/Telegram/KlikQrisExpiredNotifierTest.php tests/Unit/Services/KlikQris/KlikQrisQrisImageTest.php tests/Unit/Services/Telegram/TelegramPaymentInstructionRendererTest.php tests/Feature/Telegram/WebhookIngressTest.php tests/Feature/Telegram/CartCheckoutTest.php && ./vendor/bin/pint --dirty --test
```

Result:

- 79 tests passed.
- 742 assertions passed.
- Pint `--dirty --test` passed on 11 files.

Regression areas included:

- New KlikQRIS Telegram checkout tests.
- New KlikQRIS expired-notifier tests.
- New KlikQRIS QR image security tests.
- Existing Telegram payment instruction renderer regressions.
- Existing Telegram webhook ingress regressions.
- Existing CartCheckout manual/Pakasir/Binance regressions.
- Updated CartCheckout KlikQRIS regression for Task 6 behavior.

## Concerns and follow-ups

- The SDD report was initially written only at repository root as `task-6-report.md`; this file corrects the requested SDD workspace location.
- No production deploy was run.
- No Docker refresh was run per instruction.
- Because app/job/service code changed, a Docker refresh will be needed later if applying locally, using the existing project name per repo instructions.
- No real environment credentials were read. Tests use faked Telegram and KlikQRIS responses only.

## Fix Round 1 Report

### Review source

- Review file: `.superpowers/sdd/2026-07-28-klikqris-sandbox-integration/task-6-review.md`
- Base commit reviewed: `f0f204a feat: send KlikQRIS directly in Telegram`

### Fixes made

1. Exact KlikQRIS caption amount format
   - Updated `Payment::klikQrisTotalAmountDisplay()` to format from `provider_total_payment` as `IDR 1,016.00` style.
   - It no longer uses locale-dependent Indonesian `Rp 1.016` formatting.
   - Updated `KlikQrisCheckoutTest` and `KlikQrisProviderReadinessTest` expectations.

2. Bounded QR image download before materialization
   - Reworked `KlikQrisQrisImage::download()` to use a bounded `php://temp` sink with `MAX_BYTES + 1` memory threshold.
   - Configures Laravel/Guzzle options:
     - `allow_redirects => false`
     - `stream => true`
     - `sink => $sink`
     - `on_headers` Content-Length precheck
     - `progress` downloaded byte limit
   - Does not call `$response->body()` for remote QR downloads.
   - Rewinds and reads at most `MAX_BYTES + 1` from the bounded sink after response completion.
   - Validates status, Content-Type, Content-Length, PNG magic bytes, and size after rewind.
   - Closes the sink in success and exception paths.
   - Keeps generic `KlikQRIS QR image is unavailable.` exceptions.
   - Added tests for streaming options and Content-Length oversize fallback as realistically as Laravel HTTP fakes permit.

3. Telegram delivery semantics wording
   - Renamed tests to avoid exactly-once implications.
   - Reports now state the proven semantics: confirmed successful sends are deduped by the notified timestamp, definite thrown failures release the claim for retry, and ambiguous Telegram acceptance followed by timeout/crash can duplicate on retry because Telegram has no idempotency key.
   - No at-most-once behavior was introduced, avoiding silent loss of QR instructions.

### Focused fix verification

Command:

```bash
cd src && php -d opcache.enable_cli=0 artisan test tests/Feature/Telegram/KlikQrisCheckoutTest.php tests/Unit/Services/KlikQris/KlikQrisQrisImageTest.php tests/Unit/Services/KlikQris/KlikQrisProviderReadinessTest.php --filter='klikqris|bounded|oversized_content_length|native_klikqris'
```

Result:

- 18 tests passed.
- 100 assertions passed.

### Remaining concerns

- Telegram Bot API still has no idempotency key for `sendPhoto`.
- The implementation intentionally preserves the existing Pakasir-like at-least-once outbound model.
- Ambiguous timeout/crash after Telegram acceptance may duplicate QR media on retry.
- No production deploy or Docker refresh was performed.

### Full Fix Round 1 regression verification

Fresh post-format command:

```bash
cd src && php -d opcache.enable_cli=0 artisan test tests/Feature/Telegram/KlikQrisCheckoutTest.php tests/Feature/Telegram/KlikQrisExpiredNotifierTest.php tests/Unit/Services/KlikQris/KlikQrisQrisImageTest.php tests/Unit/Services/KlikQris/KlikQrisProviderReadinessTest.php tests/Unit/Services/Telegram/TelegramPaymentInstructionRendererTest.php tests/Feature/Telegram/WebhookIngressTest.php tests/Feature/Telegram/CartCheckoutTest.php && ./vendor/bin/pint --dirty --test
```

Result:

- 89 tests passed.
- 784 assertions passed.
- Pint `--dirty --test` passed on 6 files.
