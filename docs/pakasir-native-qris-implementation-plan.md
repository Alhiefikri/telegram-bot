# Pakasir Native QRIS implementation plan

Status: planning only. Hosted Pakasir checkout must remain available as fallback.

## Goal

Add Pakasir Native QRIS checkout for the Store Bot so customers can scan a QRIS image directly in Telegram after checkout, without needing to open the hosted Pakasir page. The hosted Pakasir URL stays available as a fallback button and as the rollback path.

## Source of truth

Official Pakasir docs: <https://pakasir.com/p/docs>

Required Pakasir behavior:

- Hosted URL remains: `https://app.pakasir.com/pay/{slug}/{amount}?order_id={order_id}` with optional `redirect` and `qris_only=1`.
- Native QRIS create uses `POST https://app.pakasir.com/api/transactioncreate/qris`.
- Request body uses configured project slug/API key plus internal payment `merchant_ref` and `amount`.
- Response `payment.payment_number` is a QR string. The app must render it into a QR image.
- Webhook remains untrusted intake; paid state still requires Transaction Detail API verification.
- Transaction Detail API remains the verification source of truth.

## Current repo map

- `PakasirClient` only supports hosted URL generation and Transaction Detail lookup today.
- `PakasirProviderAdapter` is the seam for adding native QRIS creation.
- `PakasirCallbackProcessor` already validates callback amount/project/order/status and verifies with Transaction Detail before marking paid.
- `OrderCheckoutService` creates local order/payment and reserves stock in a DB transaction. Pakasir API calls must stay outside this transaction.
- `TelegramCheckoutIntents::sendPakasirPaymentInstructions()` currently renders a QR from the hosted URL. Native mode must render from Pakasir `payment_number` instead.
- `Payment` lacks first-class fields for provider payload, fee, total payment, provider method, provider expiry, and QRIS payment number.
- `ManualPaymentSetting.payment_mode` can switch the dashboard to Pakasir; enabling Pakasir must validate Pakasir credentials/config.

## Target customer flow

1. Customer checks out in the Store Bot.
2. App creates local order/payment and reserves stock.
3. If `PAKASIR_CHECKOUT_FLOW=api_qris`, app creates Pakasir QRIS transaction after the local DB commit.
4. Bot sends a QRIS image generated from `payment.payment_number`.
5. Bot caption shows invoice, items, product total, Pakasir fee/total payment if returned, and expiry.
6. Bot includes fallback button to hosted Pakasir page.
7. Customer can tap `Cek Status Pembayaran`; app checks Transaction Detail for pending Pakasir payment.
8. Pakasir webhook or reconciliation marks payment paid only after Transaction Detail confirms completed.
9. Receipt and inventory behavior continue through `OrderStateMachine`.

## Configuration plan

Keep provider-level mode:

```env
TELEGRAM_PAYMENT_MODE=pakasir
```

Add checkout-flow mode:

```env
PAKASIR_CHECKOUT_FLOW=hosted
# allowed: hosted, api_qris
```

Default must be `hosted` so deploys do not change customer behavior until staging proves native QRIS.

Recommended env cleanup:

```env
PAKASIR_REDIRECT_URL=
```

Leave redirect blank by default. Hosted fallback still works without redirect, and examples should not point to placeholder domains.

## Data model plan

Add nullable fields to `payments`:

- `provider_payload` JSONB: sanitized Pakasir create response.
- `provider_fee` BIGINT nullable: Pakasir `fee`.
- `provider_total_payment` BIGINT nullable: Pakasir `total_payment`.
- `provider_payment_method` string nullable: expected `qris`.
- `provider_created_at` timestamp nullable: native create persisted time.
- `provider_expires_at` timestamp nullable: Pakasir `expired_at`.
- `provider_payment_number` text nullable with encrypted cast: clear QR string for re-rendering.
- `provider_payment_number_hash` char(64) nullable indexed: safe audit/debug reference.

Do not store the raw QR string in cleartext JSON payloads or `provider_reference`. `provider_reference` is short/unique and should remain available for actual provider IDs if Pakasir later returns one.

Amount rules:

- `payments.amount` remains the internal product/order amount.
- Send `payments.amount` to Pakasir as `amount`.
- Verify webhook/detail `amount` against `payments.amount` unless staging proves Pakasir sends `total_payment` instead.
- Display `provider_total_payment` to the customer as the QRIS payable amount when present.
- Staging must prove fee and total-payment semantics before production cutover.

## Idempotency and failure rules

- `transactioncreate/qris` runs after local checkout DB commit, never inside stock reservation transaction.
- Native create must be idempotent through `PakasirNativeQrisService::ensureCreated(Payment $payment)`.
- `ensureCreated()` first returns persisted native fields if present.
- Use a local lock keyed by payment ID before calling Pakasir.
- Persist provider fields before sending Telegram photo.
- If Pakasir create fails, keep order/payment pending and send hosted fallback instructions.
- If Telegram `sendPhoto` fails after create succeeds, retry/resend uses encrypted persisted `provider_payment_number`; do not create another Pakasir transaction.
- Existing scheduled expiry releases stock locally; do not block local expiry on Pakasir cancel.
- Transaction cancel is phase 2 after staging proves semantics.

## Issue breakdown and skill triage

| Issue | Scope | Triage label | Suggested skill/context |
| --- | --- | --- | --- |
| [#91](https://github.com/Alhiefikri/telegram-bot/issues/91) | Pakasir native client, DTO, payment schema, persistence | `ready-for-agent` | `codebase-design`, `tdd` |
| [#92](https://github.com/Alhiefikri/telegram-bot/issues/92) | Telegram native QRIS checkout UX, hosted fallback, idempotent create use | `ready-for-agent` | `tdd`, Telegram checkout context |
| [#93](https://github.com/Alhiefikri/telegram-bot/issues/93) | Webhook/reconciliation/status/expiry hardening for native QRIS | `ready-for-agent` | `tdd`, payment lifecycle context |
| [#94](https://github.com/Alhiefikri/telegram-bot/issues/94) | Admin/config/env/docs updates for hosted vs native rollout | `ready-for-agent` | config/docs context |
| [#95](https://github.com/Alhiefikri/telegram-bot/issues/95) | Staging validation and production cutover checklist | `ready-for-human`, `needs-info` | real Pakasir/Telegram credentials required |

Keep issue bodies concise. Each issue should include the files to inspect, explicit non-goals, acceptance criteria, and focused test commands. Do not put the full planning document into every issue.

## Staging questions that must be answered

- Does duplicate `transactioncreate/qris` for the same `order_id` return the same transaction, error, or create a duplicate?
- Does webhook/detail `amount` equal original amount or `total_payment` for native QRIS?
- Is `fee` always present? Is `total_payment = amount + fee` always true?
- Does Transaction Detail return fee/total/expiry/payment number for pending native payments?
- Does hosted fallback work for the same `order_id` after native transaction creation?
- Is payment simulation available for the Pakasir project and does it trigger the same webhook shape?
- What exactly happens after Pakasir `expired_at` and after optional transaction cancel?

## Rollout summary

1. Ship code with `PAKASIR_CHECKOUT_FLOW=hosted`.
2. Validate hosted flow still works.
3. Enable `api_qris` only in staging first.
4. Prove amount/fee/expiry semantics.
5. Run a small live transaction only if sandbox cannot prove provider behavior.
6. Enable production by changing `PAKASIR_CHECKOUT_FLOW=api_qris`.
7. Roll back instantly with `PAKASIR_CHECKOUT_FLOW=hosted`.

Production deploy source remains manual GitHub Actions deploy from `main`. Production Compose project must remain `telegram-bot-prod`.

After env/config changes that affect the running bot, refresh with:

```bash
docker compose -p telegram-bot-prod --env-file .env up -d --build --remove-orphans
docker compose -p telegram-bot-prod --env-file .env ps
docker compose -p telegram-bot-prod --env-file .env exec -T app php artisan ops:health --db --queue-schema --supervisor
```
