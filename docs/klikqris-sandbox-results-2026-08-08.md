# KlikQRIS Sandbox Qualification Results — 2026-08-08

## Decision

**PASS — all 12 sandbox scenarios are qualified.** This evidence does not authorize production credentials, production mode, production traffic, deployment, or a provider cutover.

The detailed operator evidence was recorded under issue #197 and completed by its follow-up. This repository copy is intentionally sanitized: identifiers are redacted and only bounded observations are retained.

## Scenario results

| # | Scenario | Result | Safe evidence |
| --- | --- | --- | --- |
| 1 | Testing Bot checkout creates a payable QRIS transaction | PASS | Non-production checkout created one KlikQRIS payment in the sandbox. |
| 2 | QR is displayed and readable | PASS | The PNG reference decoded successfully; the image and QR payload were not retained. |
| 3 | Display nominal equals provider `total_amount` | PASS | The UI and stored projection preserved the provider's exact total representation. |
| 4 | Dashboard simulation triggers a webhook | PASS | The sandbox simulator callback was received and acknowledged. |
| 5 | Authoritative status marks the order paid once | PASS | Provider `SUCCESS` was used as the paid signal; one order/payment and one sale movement resulted. |
| 6 | Telegram receipt is sent once | PASS | Exactly one receipt marker was recorded for the paid order. |
| 7 | Duplicate webhook is idempotent | PASS | Replayed delivery did not create a second sale, fulfillment, or receipt. |
| 8 | No-webhook reconciliation finds the payment | PASS | Reconciliation obtained authoritative status and paid the pending order. |
| 9 | Expiry and fresh checkout | PASS | The expired QR became unpayable and a new checkout used a distinct disposable transaction. |
| 10 | Create/status transport outage recovery | PASS | A create attempt moved to an unknown outcome; status recovery did not retry create or create a duplicate provider transaction. |
| 11 | Fake callback and nominal mismatch cannot pay | PASS | Invalid callback signals left the payment/order pending with no sale movement. |
| 12 | Storefront/admin observability is correct and secret-safe | PASS | Exact provider total display was preserved and credential, token, signature, QR payload, and raw-response fields were absent. |

## Additional race evidence

The provider-backed callback/reconciliation overlap and duplicate delivery were also qualified. The final state contained one payment, one paid order, one sale movement, one fulfillment effect, and one Telegram receipt marker. Duplicate delivery increased only the bounded callback receipt count.

## Production boundary

Sandbox qualification is not production authorization. No production credentials were requested or stored, no production traffic was enabled, Pakasir was not disabled, and rollout issue #201 was not executed.
