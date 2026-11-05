# 0029 — Timed release of unpaid USD funding targets

Status: accepted (2026-10-19)

## Context

The single active funding target per Customer is protected while money is
possible: expiry moves a reservation to `expired_unresolved` and keeps holding
the slot until a settlement or a typed release resolves it. Nothing released an
unpaid target automatically. A Customer who chose an amount, saw the payment
instructions and never paid therefore could not start another USD top-up — and
in practice needed an operator to intervene.

## Decision

- New Binance Pay and BEP20 top-ups have a 15-minute payment window. Confirmed
  historical targets retain their original snapshotted deadlines. A target with
  no economic resolution and no in-flight settlement lease is eligible for
  release as soon as its deadline passes.
- The release writes one immutable resolution row of the new closed kind
  `expired_unpaid` and the typed reservation release in the same transaction.
  It records no ledger entry and moves no money.
- The sweep (`usdt-funding:release-expired --limit=25`, every minute) skips a
  target when a resolution already exists, when settlement work holds an active
  lease, or when either work row is already terminal. A Customer who opens a new
  amount also triggers release of an eligible expired target without waiting for
  the sweep. A concurrent settlement or review always wins.
- Credit terms for money discovered after release are unchanged: settlement
  paths seeing the existing resolution route the transfer to manual review
  instead of crediting a released reservation, including an on-time transfer.
  Binance recovery requires a pending Order ID hint and searches the target
  window; it does not automatically discover unsubmitted or later transfers.
- The Customer-facing status of a released target is the new terminal state
  `expired_unpaid`: it explains that the window closed with no payment detected,
  offers no check/lookup buttons, and promises no credit.

- The Customer can also close one unpaid target on demand using the
  danger-styled cancel button on the payment instructions. The transactional
  close records an immutable `cancelled_by_customer` resolution, skips targets
  with an active settlement lease, and never moves money. Private `/start`
  returns to navigation without cancelling the target; it can be reopened.
- Successful inline cancellation deletes the payment instruction message,
  clears the pending lookup prompt, and sends Home as the new canonical root.
  The immutable target/resolution history remains available.

## Consequences

- Abandoning an unpaid target no longer blocks the next USD top-up after its
  deadline, subject to the settlement and review protections above.
- The PostgreSQL resolution-kind constraint widens to include
  `expired_unpaid`; SQLite lanes enforce the same closed set in code.
- Unpaid releases are visible in the resolution log (kind, timestamp) without
  adding an audit table.
- The sweep defaults to zero additional grace; its optional `--grace-minutes`
  argument remains available for manual operations. Released transfers need
  operator handling; the Binance review flow currently requires an unresolved
  target and cannot directly credit an already released one.
