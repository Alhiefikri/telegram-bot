# Telegram: USDT balance, top-up and personal history (#554)

U9 implements work package **P4/P7** of [#536](https://github.com/Alhiefikri/telegram-bot/issues/536) on top of the closed dependencies U6 [#551](https://github.com/Alhiefikri/telegram-bot/issues/551), U7 [#552](https://github.com/Alhiefikri/telegram-bot/issues/552) and U8 [#553](https://github.com/Alhiefikri/telegram-bot/issues/553). It adds the private-chat presentation for the Customer USDT Balance and wires the customer entry points of the existing U6/US7 seams: no new money writer, no new provider call, and no second Telegram send path.

## 1. What this package adds

| Piece | Owner | Money effect |
| --- | --- | --- |
| USDT section of the private Saldo screen | `TelegramMenuIntents::usdtBalanceSection()` | none: projection read with honest unavailable/frozen states |
| USDT chooser, exact preview, status and history surfaces | `TelegramUsdtBalanceIntents` | none: rendering only |
| USDT callback + `/usdt` command adapter | `TelegramUsdtFundingCallbackHandler` | delegates to U6 confirm/cancel/hints; never credits |
| `/usdt-return <top-up-id> <amount> <destination>` | `TelegramUsdtFundingCallbackHandler` → `TelegramUsdtFundingActions::authorizeExternalReturn()` | records only the Customer-scoped destination authorization; never sends funds |
| `/usdt <amount>` preview | `TelegramUsdtFundingActions::preview()` (U6) | none before Confirm |
| bounded personal history read model | `CustomerUsdtActivityService` | none: keyset read of ledger entries and open targets |
| `usdt:check` / `usdt:hash` status actions | `UsdtTopUpStatusReader`, `UsdtTopUpSettlement::submit()` (U7) | hint only; never evidence |
| hash-input prompt state | `TelegramUsdtFundingSession` | none: which target the next hash message belongs to |

The October 8 rework changes Tambah Saldo into a currency chooser: IDR opens the existing preset/custom QRIS flow; USD opens Binance Pay ID or BSC BEP20, then a private exact-amount prompt. USD means USDT in the existing ledger, not a new fiat ledger or automatic conversion. Confirm creates a rail-specific immutable funding target. Binance instructions show the receiving Pay ID and require a Binance Order ID. BSC instructions show the exclusive BEP20 receiver once preparation is ready; the scanner detects transfers automatically without Customer hash submission.

## 2. Screen contracts

- **Private Saldo screen.** The IDR line stays first; the USDT section then shows `💎 Saldo USDT: … USDT` with `balance:usdt` (Top-up) and `usdt:history` rows, or a localized unavailable/frozen line. A drifted projection and a frozen account never render a fabricated `0 USDT`, and neither offers a new funding action while the history stays readable. The top-up row only appears when `CustomerUsdtFundingGate::availability()` says the rail is available.
- **Amount input.** The selected rail stores a ten-minute Customer/chat/root/message-bound amount prompt. Invalid input retains the prompt. Navigation clears it; unknown/group/stale messages cannot create a target. `/usdt <amount>` remains the direct BSC preview shortcut.
- **Preview.** One Customer/root/message-bound intent snapshots credit, fee, exact payable amount, selected rail and payment terms. Confirm/cancel use opaque nonces; previews have no money effect.
- **Status.** Immutable snapshots plus durable preparation/settlement/resolution rows determine status. A settled label requires the linked ledger entry. Binance targets are immediately ready; BSC instructions wait for successful preparation. Only Binance displays a transaction-input action (`usdt:order:<topUpId>`). BSC displays status refresh, never a hash-request button; automatic scanning and finality verification drive settlement. Existing hash hints from older messages remain optional lookup support, not a payment requirement. Missing/ambiguous/mismatched receipts do not credit funds.
- **History.** `CustomerUsdtActivityService` merges immutable ledger entries (kind/state/signed exact micro amount) with top-up targets that have no resolution yet (requested amount + honest state) into one keyset stream. Page size defaults to 25 and rejects anything above 100; `TelegramUsdtActivityToken` signs and scopes the cursor to one Customer, and an IDR cursor can never be replayed as a USDT cursor (separate token version). The walk compares `(created_at, rank, id)` so same-second rows of either source stay reachable exactly once in both directions. A settled top-up appears as its own ledger entry only, so the receipt/status copy is never a second money row.

Every amount on these screens is exact integer micro-USDT through `UsdtMicroAmount::formatUnits()`; no screen converts USDT to IDR or reads a shared balance cache.

## 3. Ownership, privacy and recovery

- All USDT screens are private-chat only (`allowsNonPrivateChat() === false`, `/usdt` is a private-only command). Group callbacks are answered with `privacy.callback` and group `/usdt` with `privacy.message`, matching the existing Store Balance rule.
- Callback identity is the registered numeric Telegram actor plus the canonical navigation root: `TelegramNavigationPresentation::matchRoot()` fails closed, stale callbacks re-render the chooser on the current root with a localized stale notice, and ambiguous edits keep the existing `TelegramNavigationRenderOutcome` feedback.
- Target lookups are always scoped by `customer_id`; a foreign target id, a foreign cursor token or a replayed cursor cannot expose another Customer's money.
- Settled receipts remain owned by U7 (`UsdtTopUpOutbound` → `telegram_outbound_effects`). U9 records no outbound effect and sends no receipt; status text is presentation, never money authority.

## 4. Corrected integration defects

Two defects were found while integrating U9 and are corrected here with regression tests:

- **U6 intent identity (`UsdtFundingIntentService`).** The canonical render lease advances `telegram_navigation_roots.generation` on **every** canonical edit, so the stored `root_generation` equality check invalidated every preview as soon as the preview itself was rendered on the root: confirm always returned `stale`, and replaying the same amount created a second intent row. Intent identity is now the root row id, chat id and canonical message id; the generation stays a persisted snapshot and render counter, never an identity version. A replaced canonical message still fails the identity check, which is the behaviour the original guard intended. `UsdtFundingIntentTest` covers both directions.
- **IDR history keyset boundary (`StoreBalanceActivityService::applyCursor`).** IDR top-up rows rank lower than ledger entries inside the same timestamp, but the older/newer walk compared only the timestamp, so a top-up row sharing the page-boundary second was dropped entirely (`olderPage` returned no items while `hasNewer` stayed true). The walk now compares `(created_at, rank, id)` lexicographically, so same-second rows of the other source stay reachable exactly once. The previous `test_equal_timestamps_keep_top_up_and_entry_cursor_boundaries_strict` pinned the row-dropping behaviour and was replaced by `test_equal_timestamps_keep_same_second_rows_reachable_in_both_directions` plus `test_same_second_top_up_row_at_a_page_boundary_is_never_lost`. `CustomerUsdtActivityService` uses the same corrected rank-aware predicates from the start.

## 5. Limits and non-goals

- H0-v1 has no presets/business caps, so U9 ships the custom exact-input path only; the superseded preset requirement is recorded here rather than reintroduced as an ENV/Admin knob.
- Funding is no longer limited to `local`/`testing`; configured rails use the same policy, pause and transaction verification checks in production. Deployment and live-money verification are separate operational actions, not performed by this patch.
- Scheduled `usdt-funding:recover` settles Binance Order-ID hints from authoritative Pay history and BSC hints from finalized chain evidence. Binance status refresh can inspect due work; provider I/O stays outside DB transactions. Transaction IDs/hashes are lookup hints, never proof by themselves.
- The settled presentation is verified against a really settled U7 target in `UsdtBalanceSettledPresentationTest`, which extends `UsdtTopUpTestCase` because preparing a rail must run outside a wrapping test transaction.
- Telegram messages cannot be proven delivered from the app's side; ambiguous send recovery stays with the existing outbound/navigation seams.

## 6. Funding regression coverage

- `tests/Unit/Services/Binance/BinanceVerificationContractTest.php`: malformed history, missing required fields, pending/failed/unknown provider status, debit/zero amounts, missing receivers and Pay-ID/UID namespace mismatch.
- `tests/Unit/Services/Binance/BinanceUsdtAmountTest.php`: exact signed micro-USDT conversion, trailing-zero precision, integer limits, overflow and unrepresentable sub-micro amounts.
- `tests/Unit/Services/Telegram/TelegramUsdtFundingSessionTest.php`: rail/target replacement, Customer isolation, navigation cancellation and prompt expiry.
- `tests/Unit/Services/UsdtTopUps/UsdtTopUpStatusTest.php`: unresolved targets stay inspectable; settled, compensated, refunded and rejected targets stop lookup without misreporting refunds as credits.
- `UsdtTopUpSettlementTest::test_bsc_transfer_is_credited_automatically_without_customer_hash_submission`: scheduled scanning credits BSC once without Customer hash input. `BinanceUsdtTopUpTest` covers scheduled Binance settlement and receipt reuse/mismatch.
