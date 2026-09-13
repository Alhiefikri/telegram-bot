# Store Balance late settlement and owner alerting

## Status

Accepted - 2026-09-13

## Decision

A verified provider success that arrives after a Store Balance top-up deadline
has already passed is real money, so it settles the same single credit instead
of waiting for a manual owner compensation. Late settlement keeps every
existing settlement guard: the evidence must be trusted and match the top-up's
provider, merchant reference, currency, payment method, requested amount, and
provider total, and it is refused for `paid` and `compensated` top-ups. It
differs from normal settlement only in dropping the status window
(`pending`, `payable`, `awaiting_confirmation`) and the "deadline in the
future" condition.

The economic identity is unchanged: the credit still uses
`balance_top_up:<id>:credit` and source `balance_top_up:<id>`, so a late
settlement racing a callback, a reconciler, or the expiry sweep can never
double-credit. A late settlement records `provider_create_state`
`reconciled_<provider status>`, which reuses the existing delayed-recovery
presentation for the customer, and audits `store_balance.top_up.late_settled`
with the previous status so operators can tell it apart from an in-window
settlement.

Owner compensation remains the path for genuinely ambiguous money. To make
that path proactive instead of dashboard-polling, a new durable outbound family
`admin.balance_top_up_owner_alert` alerts the configured admin chat once per
top-up that may carry money but could not be settled automatically:

- `recovery_pending` (provider outcome unknown after recovery),
- `invalid_provider_result` (conflicting or unusable provider evidence),
- `expired` with a customer payment proof (manual rail proof that arrived but
  was never confirmed before the deadline).

`StoreBalanceTopUpOutbound::recoverMissingOwnerAlerts` performs the source
discovery, the effect key is per top-up (`balance-top-up-owner-alert:<id>:v1`),
and delivery re-checks eligibility so an already credited or compensated
top-up is finalized without sending. The Admin Dashboard compensation form now
prefills the stored provider reference, so releasing a genuine compensation
does not require retyping evidence that the system already holds.

Late settlement is service-level policy, and each rail decides when it may
present late evidence. KlikQRIS opens it for terminal-but-unresolved rows in
both authoritative reconciliation and paid-callback processing, so a verified
success still settles a top-up the expiry sweep already closed. The manual
rail keeps its operator-decision semantics: approval after the deadline still
returns `Expired`, and the owner alert plus compensation path remains
responsible for a proof that arrived too late. Sakurupiah keeps its current
terminal short-circuit until it has an equivalent discovery path, because its
balance top-ups are only re-checked by the scheduled reconciler.

## Consequences

- A verified late provider success no longer enters the compensation queue; the
  customer receives the existing "delayed payment was recovered" receipt and
  the balance is credited once. KlikQRIS reconciliation and paid callbacks may
  now perform an authoritative status lookup for an expired or review-state
  top-up, so webhook cost follows real provider traffic rather than the
  scheduled sweep.
- Plain expired, never-paid top-ups remain silent for the owner alert family;
  they still appear in the compensation queue as before.
- `TelegramOutboundEffectType` gains
  `admin.balance_top_up_owner_alert`; dispatcher, policy limits, recovery, and
  the outbound architecture documentation register the new family. No migration
  is required because `effect_type` is a string column.
- `KlikQrisNativeQrisPostgresConcurrencyTest` now asserts that a
  reconciliation/expiry race settles exactly one late credit instead of
  asserting that an expired top-up is never credited.
- The distinction from the Order-scoped "Late Payment" definition stays
  explicit: late Order payments still require manual review, while Store
  Balance top-ups settle automatically when the provider evidence is
  unambiguous.
