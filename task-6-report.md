# Task 6 Report: Deliver native KlikQRIS directly in Telegram

## Scope

Implemented Task 6 only in the `feature/klikqris-sandbox` worktree.

Task 6 now delivers native KlikQRIS QRIS checkouts directly through Telegram `sendPhoto` when the sandbox selector is ready, while leaving production fail-closed and preserving existing manual, Pakasir, and Binance behavior when KlikQRIS is disabled.

## Implementation summary

- Added `App\Jobs\SendKlikQrisCheckoutInstructions`.
  - Mirrors `SendPakasirCheckoutInstructions` claim, retry, idempotency, `ShouldBeUnique`, and backoff semantics.
  - Uses `provider = klikqris`.
  - Runs on the existing `telegram-outbound` queue when dispatched from checkout.
  - `ensureCreated` remains owned by the delivery path through `TelegramCheckoutIntents::sendKlikQrisPaymentInstructions`.
  - Delivery claim owns Telegram send, preventing duplicate Telegram photos on retry.

- Added `App\Services\KlikQris\KlikQrisQrisImage`.
  - Prefers downloading the allowlisted transaction `qris_url`.
  - Enforces HTTPS `klikqris.com` sandbox QR URL shape through the Task 5 allowlist.
  - Disables redirects.
  - Uses bounded timeouts.
  - Enforces maximum body size.
  - Requires HTTP 200, `image/png` content type, and PNG magic bytes.
  - Falls back to in-memory `data:image/png;base64,...` only when URL download fails.
  - Rejects malformed, oversized, non-PNG, JPEG, SVG, HTML, redirect, and untrusted URL inputs.
  - Does not persist raw base64 and uses a generic error message without leaking remote response bodies, URLs, API keys, or signatures.

- Wired the real Task 4 selector seam.
  - `TelegramCheckoutIntents::requestedPaymentMode()` returns `klikqris` only when `KlikQrisSandboxCheckoutSelector::enabled()` is true.
  - If disabled, existing manual/Pakasir selection is unchanged.
  - `TelegramConfiguredCheckoutPaymentIntentSelector` dispatches `CheckoutPaymentIntents::klikQris()` only for explicit `paymentMode = klikqris`.
  - `TelegramCheckoutCallbackHandler` replaced the temporary KlikQRIS rejection with actual dispatch for `CheckoutPostCreationBehavior::KlikQrisInstructions`.
  - Pre-persistence behavior support still uses an exhaustive enum match, so unknown future enum behavior remains fail-safe at PHP match/runtime level.

- Added direct Telegram renderer support.
  - `TelegramPaymentInstructionRenderer::klikQris()` sends QR bytes directly as `klikqris-{invoice}.png`.
  - Caption uses `$payment->klikQrisTotalAmountDisplay()` for the exact final total.
  - Caption includes invoice, the exact English warning `Do not round or change any digit`, and the provider deadline with 15-minute wording.
  - Caption avoids hosted URLs, redirects, Popup Snap, API keys, signatures, and raw base64.
  - Buttons are exactly status `order:{invoice}` and cancellation `buyer:cancel-pending:{order-id}`.

- Updated expired KlikQRIS copy.
  - Expired KlikQRIS orders now show `🔄 Buat QRIS Baru` / `🔄 Create New QRIS`.
  - Callback remains `katalog`, forcing a fresh catalog/cart/checkout path rather than resurrecting old order/cart state.
  - Non-KlikQRIS expired order copy remains unchanged.

## TDD evidence

### RED phase

Command:

```bash
cd src && php -d opcache.enable_cli=0 artisan test tests/Feature/Telegram/KlikQrisCheckoutTest.php tests/Unit/Services/KlikQris/KlikQrisQrisImageTest.php tests/Feature/Telegram/KlikQrisExpiredNotifierTest.php
```

Expected failing behavior was observed before production implementation:

- `SendKlikQrisCheckoutInstructions` did not exist.
- `KlikQrisQrisImage` did not exist.
- Existing checkout path still rejected `CheckoutPostCreationBehavior::KlikQrisInstructions`.
- Expired KlikQRIS copy still used the generic Order Again copy.

I fixed initial test fixture errors until failures targeted Task 6 behavior rather than bad setup.

### GREEN phase

Focused Task 6 command after implementation:

```bash
cd src && php -d opcache.enable_cli=0 artisan test tests/Feature/Telegram/KlikQrisCheckoutTest.php tests/Unit/Services/KlikQris/KlikQrisQrisImageTest.php tests/Feature/Telegram/KlikQrisExpiredNotifierTest.php
```

Result:

- 9 tests passed.
- 59 assertions passed.

## Regression verification

Fresh post-Pint verification command:

```bash
cd src && php -d opcache.enable_cli=0 artisan test tests/Feature/Telegram/KlikQrisCheckoutTest.php tests/Feature/Telegram/KlikQrisExpiredNotifierTest.php tests/Unit/Services/KlikQris/KlikQrisQrisImageTest.php tests/Unit/Services/Telegram/TelegramPaymentInstructionRendererTest.php tests/Feature/Telegram/WebhookIngressTest.php tests/Feature/Telegram/CartCheckoutTest.php && ./vendor/bin/pint --dirty --test
```

Result:

- 79 tests passed.
- 742 assertions passed.
- Pint passed on 11 files.

## Requirement checklist

- Sandbox selector only enables KlikQRIS in local/testing readiness: covered by selector tests and existing readiness seam.
- Production fail-closed/admin switch absent: no production admin switch added, existing readiness gate retained.
- Disabled KlikQRIS preserves manual/Pakasir selection: covered by selector regression.
- Direct Telegram `sendPhoto`: implemented through `TelegramBotClient::sendPhoto` with QR bytes.
- No redirect/hosted payment URL/Popup Snap: KlikQRIS renderer sends no URL button and caption has no hosted URL/redirect/Popup Snap copy.
- Exact total from `$payment->klikQrisTotalAmountDisplay()`: renderer uses payment helper directly.
- Warning copy: caption includes `Do not round or change any digit`.
- Invoice and 15-minute/provider deadline: covered in sendPhoto test.
- No signature/API key/base64/redirect URL in caption/log/error: caption tests cover caption. QR image service uses generic exception messages.
- Checkout uses Task 4 selector seam: implemented in `TelegramConfiguredCheckoutPaymentIntentSelector` and `TelegramCheckoutIntents`.
- Unique job mirrors Pakasir semantics with provider `klikqris`: implemented and tested for retry claim release and idempotent duplicate skip.
- `ensureCreated` owns create and delivery claim owns send: implemented in `sendKlikQrisPaymentInstructions` and job handle flow.
- QR image service allowlist, no redirects, bounded timeouts/body size, HTTP/content/magic validation: implemented and tested.
- Base64 fallback only on URL download failure: implemented and tested.
- No raw base64 persistence: unchanged persistence sanitization from Task 5, image service only consumes transaction memory.
- sendPhoto buttons: covered by test.
- Expired KlikQRIS copy/button: covered by test.
- No Pakasir/manual instruction job on KlikQRIS checkout: covered by new Task 6 tests and updated CartCheckout regression.

## Notes

No real environment credentials were read. All provider and Telegram interactions in tests used fakes.

No production deploy was performed. No Docker refresh was performed per instruction.
