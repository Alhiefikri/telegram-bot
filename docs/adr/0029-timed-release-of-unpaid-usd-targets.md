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

- After the payment deadline plus a grace window, a confirmed target with no
  economic resolution and no in-flight settlement lease is released.
- The release writes one immutable resolution row of the new closed kind
  `expired_unpaid` and the typed reservation release in the same transaction.
  It records no ledger entry and moves no money.
- The sweep (`usdt-funding:release-expired --limit=25 --grace-minutes=60`, every
  ten minutes) skips a target when a resolution already exists, when settlement
  work holds an active lease, or when either work row is already terminal. A
  concurrent settlement or review always wins.
- Credit terms for late money are unchanged: every settlement path sees the
  existing resolution and routes the transfer to manual review instead of
  crediting a released reservation.
- The Customer-facing status of a released target is the new terminal state
  `expired_unpaid`: it explains that the window closed with no payment detected,
  offers no check/lookup buttons, and promises no credit.

- The Customer can also close one unpaid target on demand: the payment
  instructions carry a danger-styled cancel button, and a private `/start`
  cancels the open target so a wrongly chosen amount never has to be paid. Both
  paths use the same transactional close with the immutable
  `cancelled_by_customer` resolution, skip targets with an active settlement
  lease, and never move money.
- Successful inline cancellation deletes the payment instruction message,
  clears the pending lookup prompt, and sends Home as the new canonical root.
  The immutable target/resolution history remains available.

## Consequences

- Abandoning an unpaid target no longer blocks the next USD top-up; the slot is
  free after the grace window.
- The PostgreSQL resolution-kind constraint widens to include
  `expired_unpaid`; SQLite lanes enforce the same closed set in code.
- Unpaid releases are visible in the resolution log (kind, timestamp) without
  adding an audit table.
- The grace window is currently a scheduler option, not an Admin-editable
  setting. Tuning it from the Dashboard remains a follow-up if the Owner wants
  to change the default without a deploy.
