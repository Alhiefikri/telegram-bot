# Store Balance top-up payment rails

## Status

Accepted - 2026-09-12

## Decision

A Store Balance top-up follows the store's selected payment provider exactly
like checkout does. `manual`, `klikqris`, and `sakurupiah` are selectable
top-up rails; the retired `pakasir` provider is not, and an unknown provider
fails closed to `manual`.

The provider is resolved once, at funding confirmation, from
`ManualPaymentSetting::current()`, and snapshotted on `store_balance_top_ups`
together with the payment setting identity and version. Every later lifecycle,
presentation, recovery, settlement, and expiry decision is taken from the
top-up's own provider. Switching the store provider affects only top-ups
confirmed after the switch; it never rewrites, re-targets, or invalidates an
existing top-up. This mirrors the Payment rule that historical providers remain
immutable.

Provider behavior lives behind one seam, `StoreBalanceTopUpLifecycle`,
resolved by `StoreBalanceTopUpLifecycleResolver` from the top-up's provider:

- `KlikQrisStoreBalanceTopUpLifecycle` delegates to the existing
  `KlikQrisNativeQrisService`; the Payment/checkout path is unchanged.
- `SakurupiahStoreBalanceTopUpLifecycle` creates and refreshes a `QRISC`
  invoice through the existing Sakurupiah adapter and readiness gates. The EMV
  QR payload is stored encrypted on the top-up and rendered to a PNG locally;
  a provider-hosted QR image is never trusted. Fee and total follow the
  existing QRIS fee contract, while the ledger always credits the requested
  amount and never the fee.
- `ManualStoreBalanceTopUpLifecycle` creates a top-up in the new
  `awaiting_confirmation` status. The customer receives the store's static QR
  instructions with the credit amount and deadline; an Owner/Admin/Finance
  operator confirms or rejects it from the admin Telegram notification.

Rails are consumed through provider-neutral results
(`StoreBalanceTopUpLifecycleResult`, `StoreBalanceTopUpPayable`,
`StoreBalanceTopUpPresentation`, `StoreBalanceTopUpOutcome`). Persistence
state, provider payloads, and provider-specific vocabulary do not cross that
boundary.

Money movement stays where it already is. `StoreBalanceTopUpService` remains
the owner of the top-up transaction, lock order, eligibility, settlement,
status transitions, and the single ledger credit identity
(`balance_top_up:<id>:credit`). A rail may only produce
`StoreBalanceTopUpSettlementEvidence` after its own authoritative
verification: KlikQRIS keeps its persisted-signature equality, Sakurupiah
performs an authoritative status lookup with identity, amount, total, and fee
matching, and the manual rail produces a confirmed operator decision instead
of provider evidence. Duplicate evidence, duplicate callbacks, duplicate
reconciliation, and replayed operator actions return the existing economic
result rather than a second credit.

`StoreBalanceTopUpDecisionRecorder` scopes observation identity to the provider,
top-up ID, event type, and canonical payload. Amount and operator alone cannot
identify a manual confirmation: one operator may confirm several top-ups with
the same amount. Settlement still rejects an event owned by another top-up.
The per-top-up ledger credit key and receipt effect key remain unchanged.
Existing events keep their recorded keys and ownership; this fix needs no
history rewrite or balance repair, and paid top-ups remain replay-only.

Regression coverage: `StoreBalanceTopUpManualRailTest` drives successive
same-amount Telegram approvals and replays, including a legacy event key.
`StoreBalancePostgresConcurrencyTest` races four approvals across two
same-amount manual top-ups for different Customers and checks one credit and
one receipt effect per top-up.

A rail that is not operationally ready fails closed before any provider
request. The top-up records `provider_unavailable`, no QR is claimed or sent,
the customer sees a localized "payment method is not ready" state with Support
as the next step, and admins receive a review notification. Silent retry loops
are not acceptable: a configuration failure must be observable and honest.

Every top-up rail is an IDR QRIS-shaped rail. Crypto rails (Binance Pay,
USDT BEP20) remain customer-selected checkout rails only and are not part of
Store Balance funding.

## Consequences

- `StoreBalanceTopUpStatus` gains `awaiting_confirmation`; manual top-ups
  transition `awaiting_confirmation -> paid` on operator confirmation,
  `-> failed` on rejection, and `-> expired` at the deadline without any
  credit.
- Provider-specific `provider_create_state` vocabulary becomes rail-owned.
  Existing KlikQRIS top-ups keep their states and remain interpretable.
- `store_balance_top_ups` gains `payment_setting_id`,
  `payment_provider_version`, and an encrypted `provider_qris_payload` column;
  the expand migration is additive and never rewrites existing rows.
- Top-up effect templates and effect keys are provider-scoped while staying in
  the existing `balance_top_up` instruction and receipt families, so the
  outbound ledger protocol, ambiguity handling, recovery, and Manual Delivery
  Review keep their single ownership.
- A new admin-facing outbound family delivers the manual review notification
  with approve/reject actions; operator decisions are audited and idempotent.
- The Sakurupiah webhook routes by merchant reference to either an Order
  Payment or a Store Balance top-up while preserving its existing
  authentication, ack-then-verify, lock, and idempotency contract.
- `klikqris:reconcile-balance-top-ups` keeps its KlikQRIS predicate;
  `sakurupiah:reconcile-balance-top-ups` is added for the new rail, and a
  bounded expiry sweep covers rails that need no provider lookup.
- Documentation that described Store Balance top-up as KlikQRIS-only
  (`CONTEXT.md`, `docs/agents/codebase.md`, runbooks) is updated in the same
  change; funding policy, ledger identity, and exact-credit invariants are
  unchanged.
