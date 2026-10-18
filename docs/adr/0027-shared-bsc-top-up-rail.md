# 0027 — Shared-address BSC USDT top-up rail

Status: accepted (2026-10-19)

## Context

The H0-v1 USDT funding design used one exclusive BSC address per Customer.
That remains the strongest ownership attribution model, but production has no
approved receiver provisioner and manual registration does not scale to the
store's customer base. The legacy `bsc_usdt` rail therefore cannot be offered
to most Customers.

The checkout rail already uses one shared address with a unique amount. That
pattern is unsafe for top-ups by itself: a transfer carries no Customer
identity, so amount matching can collide or be claimed by the wrong Customer.
The Owner explicitly chose a shared-address top-up flow with a Customer-submitted
transaction hash, provided the system verifies the chain evidence and never
credits the same transfer twice.

## Decision

Add a new `bsc_shared` rail:

- One active shared BSC address is stored in `usdt_shared_top_up_receivers`
  and permanently claimed in `usdt_receiver_address_claims` under the
  `shared_top_up` channel. It can never also serve checkout or a
  Customer-exclusive deposit binding.
- Each intent receives a unique exact payable amount. The requested base is
  used when free; otherwise a bounded micro-USDT suffix (at most 1% of the
  base) is added. The full payable amount is credited, so the suffix is not a
  hidden fee.
- The Customer sends the exact amount to the shared address and submits the
  transaction hash or a BscScan transaction link. The hash/link is only a
  lookup hint.
- The settlement verifies one successful USDT BEP20 Transfer to the snapshot
  address, the exact payable amount, the frozen invoice window, canonical
  block facts, minimum confirmations and finalized-block rules.
- The global external-money receipt registry claims the `(chain, tx hash, log
  index)` identity once. A second target submitting the same transfer is
  routed to manual review, never credited twice.
- Ambiguous amount collisions, wrong token/network/receiver, out-of-window
  transfers and unverifiable receipts stay unresolved for Owner review.

## Consequences

- The rail does not prove that the Customer owns the sending wallet. The
  first Customer to submit a public transaction hash could claim a transfer
  made by someone else. Unique payable amounts, the short payment window,
  one-time receipt claim and manual review reduce but do not eliminate this
  risk. High-value or anomalous payments must be reviewed.
- One shared address concentrates custody. The application stores only the
  public address and no key material; the Owner remains responsible for
  custody, monitoring and withdrawals.
- The legacy `bsc_usdt` rail and its historical rows remain readable; new
  funding intents use `bsc_shared`.
- The release-readiness report still treats primary BSC activation as NO-GO
  until the H1 custody/legal/provenance/treasury evidence is qualified. This
  decision changes the rail shape, not that gate.
