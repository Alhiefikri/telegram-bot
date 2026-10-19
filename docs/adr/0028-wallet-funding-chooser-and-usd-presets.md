# 0028 — Wallet funding chooser, USD amount presets, one-step target confirmation

Status: accepted (2026-10-19)

## Context

The Store Bot exposed two parallel funding entries on the Saldo screen:
"Tambah Saldo" for IDR/QRIS and "Top-up USDT" for the USDT rails. The IDR path
asked for a currency first and a rail second; the USD path asked the Customer to
type an exact amount, rendered a separate confirmation screen and only then the
payment instructions. Durable state made that worse: when an earlier funding
target was still open, a new amount request resolved to `active_target` and the
surface continued with the previously requested amount, which read as the new
request being ignored.

## Decision

- One wallet screen shows the IDR balance, the USDT balance and the newest rows
  of both ledgers merged by time. One Add-Balance chooser offers one entry per
  funding rail — QRIS (IDR), Binance Pay (USD), USDT BEP20 (USD) — because the
  currency is a property of the rail, not a separate choice.
- USD amounts come from the Owner-editable policy preset list
  (`customer_usdt_funding_policies.preset_amounts_micro`, at most 8 positive
  micro-USDT values) plus a typed custom amount. Presets never cap or reserve
  money. Each button carries a signed, versioned identity and no amount, so the
  current policy resolves the exact micro-USDT value at tap time and a stale
  button re-renders the chooser instead of funding a changed amount.
- Choosing an amount opens and confirms the funding intent in one action
  (`TelegramUsdtFundingActions::openConfirmedIntent`), and the instructions
  surface is the confirmation record. All durable invariants stay with the
  existing services: one open target per Customer across rails, immutable target
  snapshot, reservation with the snapshotted payable deadline, and credit only
  from authoritative rail evidence.
- An already-open target is shown as itself with an explicit notice. A new
  amount request can never silently reuse the previously requested amount and
  never creates a second target.
- A pasted Binance Order ID or BSC transaction hash first renders a transient
  checking surface, then the authoritative lookup runs synchronously and the
  durable result replaces the same message. The hint is still only a hint.

## Consequences

- The preview/confirm screen and the `usdt:confirm`/`usdt:cancel` callbacks are
  removed. A stale such callback renders the generic stale surface.
- Preset buttons are invalidated by any policy version change; editing the list
  is audited and versioned like every other USDT funding policy change.
- The chooser, amount list and confirmation copy are localized (id/en/ms) and
  the new method buttons own distinct editable icon slots.
- An open target still occupies the single active-funding slot until settlement
  or a typed release resolution; an abandoned unpaid target blocks a new USD
  top-up until the existing rail/owner resolution path resolves it. That
  lifecycle is unchanged by this decision and remains tracked separately.
