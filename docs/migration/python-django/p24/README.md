# P24: complete Store Bot commands and admin presentation (#706)

Authority: [#706](https://github.com/Alhiefikri/telegram-bot/issues/706),
[live canonical parent #679](https://github.com/Alhiefikri/telegram-bot/issues/679),
and the P00 parity matrix F01/F02/F04/F05/F06. Implementation branch:
`migration/p24-bot-parity-706`, based on
`migration/python-django-experiment` after the #705 merge. This slice is local
and synthetic; it does not activate production, CI/CD, webhooks, provider
rails, workers or Telegram credentials.

## Boundaries

- Command classification is pure (`navigation/intents.py`). The processor only
  orchestrates already-authorized owners; handlers never invent prices, stock,
  credentials or payment authority.
- Private-chat admission remains the P06 rule: the canonical Customer,
  Telegram identity and chat must agree. Group updates stay `foreign`; no
  financial or account surface is rendered for them.
- Root-backed screens (language, support, orders, month history, home) still
  render on the canonical root with the P06 generation/lease fence. One-off
  screens that PHP sent as separate messages (account, status, order detail,
  stock, admin capture, maintenance status, API confirmation/secret) are sent
  once and never become a second navigation root.
- Order detail, order status and month history are customer-scoped reads.
  Credentials are added only through `ReceiptSource.load_customer()` and the
  shared paid-delivery gate; a missing/retired credential fails closed to the
  header plus guidance, never a fabricated or partial payload.
- `stock_messages()` renders the public snapshot in bounded chunks. Buy links
  use the P01-verified `getMe` identity; when that identity is unavailable the
  label remains text and no fake URL is produced. Premium icons keep the
  database custom-emoji override and Unicode fallback; unavailable stock is
  omitted from `/stock`.
- `/batal` abandons only the Customer's own active Customer-origin carts.
  `/cancel` is admin-only and delegates to the existing `OrderLifecycle`
  pending-cancellation owner.
- Waitlist mail and Admin Broadcast remain P25 (#707) and F35-owned. This
  slice does not create a new waitlist command or resend notification. The
  PHP waitlist job/route surface stays in the P00 inventory for #707.

## API management

`navigation/api_management.py` is a thin adapter over the P20
`customer_api.credentials` owner and `Admission` limiter:

- `api:menu`, `api:rotate`, `api:disable`, `api:preferences`,
  `api:generate`, `api:preference:<value>` and the request-bound
  `api:(rotate|disable):<nonce>` callbacks are private-root only.
- Every mutation rechecks the Customer/chat binding and the current root
  message inside one transaction before delegating. The shared key-operation
  limiter remains Redis-backed and fails closed.
- A newly issued secret is consumed once, sent as its own HTML message, and
  never enters navigation state, the root projection, a queue, a cache or a
  log. A failed/ambiguous send is not retried; the screen reports
  `account.api.display_failed` and only the masked identity remains.
- API disabled/maintenance renders the explicit maintenance screen and
  rejects stale `api:*` callbacks with the existing maintenance notice.

## Admin capture

`navigation/admin_capture.py` ports `/admin` Product copy editing and catalog/
menu/message icon capture:

- Pending content/icon prompts live in `navigation_sessions.state` with a
  5–10 minute deadline. The next private message is matched to the prompt;
  `/batal` and `/cancel` clear it. `/admin` always wins over a pending icon
  prompt.
- Product copy uses the localized columns and `telegram_content` metadata
  bound to the exact text. Saves lock the Product row, compare the old text
  and metadata, enforce the 3000 UTF-16 unit Product Details + Refund Policy
  budget, then write one audit row. A changed row is rejected instead of
  overwriting newer work. The relay returns only a message identity, so the
  admin preview cannot assert Telegram's echoed custom-emoji entities;
  persisted metadata and the P06 delivery entity checks remain authoritative.
- Icons accept one custom-emoji entity (identifier plus API-provided glyph)
  or one plain Unicode emoji. The update compares the old icon/custom values
  so a concurrent edit cannot be silently replaced. `clear` restores the
  family/list behavior without exposing credentials.
- Normal navigation grants stay read-only. `navigation/bot_grants.sql` adds
  the `/batal` cart write; `navigation/admin_grants.sql` adds only the Product,
  Product Family, `telegram_ui_icons` and audit columns used by this flow.
  Startup installs neither file.
- Admin authority is the database `manual_payment_settings` chat id, never a
  customer/API session or an ENV-declared boolean. Non-admin callbacks return
  the existing `router.access_denied` feedback. The admin dashboard button is
  shown only when `CANDIDATE_ADMIN_DASHBOARD_URL` is an explicit public HTTPS
  setting; it is not inferred from a customer-facing base URL.

## Provisioning and verification

Apply grants explicitly in a disposable namespace, after the existing P06
navigation grants and P20 credential grants when API management is exercised:

```sh
psql -f candidate/lhie_candidate/navigation/bot_grants.sql
psql -f candidate/lhie_candidate/navigation/admin_grants.sql
```

Focused local tests:

```sh
cd candidate
.venv/bin/python -m unittest \
  tests.test_bot_parity tests.test_bot_parity_processor \
  tests.test_catalog_presentation tests.test_navigation_checkout \
  tests.test_navigation_ingress tests.test_navigation_roots \
  tests.test_receipt_render
```

Observed on 2026-11-08 with Python 3.13.14: 99 tests passed (19 new), no
skips in that set. The new tests cover command aliases/privacy, language/
support/account/status/month-history screens, order-detail header and
credential unit boundaries, stock chunking and buy links, API surface
redaction, admin affordances, UTF-16 entity validation, callback/root
routing, stale callbacks, `/batal`, admin denial and owner-scoped order detail.
The wider discovery lane reaches provider tests that require a disposable
PostgreSQL; without that harness those connection failures are environment
errors, not assertions changed here. A fresh disposable PostgreSQL E2E proof
was not run in this workspace because the local P01 state held a live
Testing Bot configuration and was not reset; the P24 grants and SQL paths
remain the explicit pre-H2 rerun boundary, and no production/Testing Bot
message was sent.

Context7 was consulted against `/django/django/5.2.18` for
[atomic/on_commit semantics](https://docs.djangoproject.com/en/5.2/topics/db/transactions/)
and [transaction test behavior](https://docs.djangoproject.com/en/5.2/topics/testing/tools/):
mutation flows use one synchronous `transaction.atomic()` owner, no network or
Telegram send runs inside the money/root transaction, and the secret send
happens after the credential transaction commits.

## Evidence limits

No production token, real customer data, provider call, webhook mutation,
deployment, restart or CI/CD run was used. Telegram custom-emoji rendering and
real-device media behavior remain the P27/human gate. This README is the P24
evidence record; the P06 smoke and existing receipt tests remain the shared
root/credential protocol evidence.
