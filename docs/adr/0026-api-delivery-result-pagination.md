# API delivery result pagination

## Status

Accepted — 2026-10-18. Implements [#642](https://github.com/Alhiefikri/telegram-bot/issues/642)
under the #617 delivery contract.

## Context

`GET /api/v1/orders/{id}/delivery` projected every releasable unit and serialized the
complete response before checking the 1 MiB bound. A valid 100-unit structured purchase
with maximum-length fields (1,024-character password, 3,500-character Auth Token,
2,000-character tutorial per unit) produced a 1,132,300-byte body and a permanent 503.
The purchase was paid and its units allocated, but no continuation contract existed, so
the Customer could never recover the same result through the API.

The endpoint must stay a recovery read of the existing allocation: it may not purchase,
reallocate, debit, refund or become a second fulfillment path. It must not decrypt
credentials outside the requested chunk, and pagination must not be confused with an
honest partial supplier release.

## Decision

Add a bounded unit traversal to the existing owned delivery endpoint.

- **Request.** Optional `limit` (1–100, default 100) and opaque `cursor`. The cursor is
  an authenticated encrypted token containing the last included allocated-unit id; raw
  internal ids are not exposed. A malformed, forged or foreign cursor and an out-of-range
  limit are `422 invalid_input` with no financial effect.
- **Response.** `DeliveryResult` keeps `id`, `state`, `progress`, `units` and
  `usage_guides`, and gains a required `pagination: {next_cursor, has_more}`. `state` and
  `progress` always describe the whole Order. `has_more` only means more units remain to
  read; it is never reported as `partial` supplier fulfillment.
- **Traversal.** Non-retired allocated rows are ordered by ascending identity. One
  look-ahead row decides whether another page exists but is never decrypted or projected.
  The page is byte-sized from the exact JSON each unit will emit, including
  maximum-length fields and escaping, under a 1 MiB bound; the page ends early with a
  cursor when needed. A genuinely oversized single unit still fails closed with 503.
- **Stable numbering.** `units[].number` is offset by the count of active units at or
  before the cursor, so it remains the shared presentation's logical account number
  across pages instead of restarting at 1.
- **Entitlement on every page.** Each request re-runs ownership, paid/lifecycle,
  retirement, review/refund and supplier partial-release authority while holding the
  owned Order shared. A continuation cannot outlive a revocation; it returns the honest
  `not_ready` result with no units.
- **No new money or fulfillment path.** The endpoint still reads only already-authorized
  rows, returns `no-store, private`, and never writes money, inventory or outbound work.

## Consequences

- Small Orders return fully in one response without pagination input; maximum-size
  Orders are recoverable across a bounded number of pages.
- The documented delivery-read budget (10 requests/minute/account) remains unchanged.
  A maximum-size Order needs several pages but is no longer permanently unavailable.
- Paginated reads do not change allocation, debit, supplier purchase or retirement
  behavior. Retired units never appear, and a retirement between pages simply removes
  that unit from the remaining traversal.
- Canonical OpenAPI/Markdown/HTML schemas, samples and executable examples are updated
  together with the implementation. The diagnostic decision record does not authorize
  production exposure; existing capability and financial gates remain unchanged.
