# Telegram: USDT balance, top-up and personal history (#554)

U9 implements work package **P4/P7** of [#536](https://github.com/Alhiefikri/telegram-bot/issues/536) on top of the closed dependencies U6 [#551](https://github.com/Alhiefikri/telegram-bot/issues/551), U7 [#552](https://github.com/Alhiefikri/telegram-bot/issues/552) and U8 [#553](https://github.com/Alhiefikri/telegram-bot/issues/553). It adds the private-chat presentation for the Customer USDT Balance and wires the customer entry points of the existing U6/US7 seams: no new money writer, no new provider call, and no second Telegram send path.

## 1. What this package adds

| Piece | Owner | Money effect |
| --- | --- | --- |
| USDT section of the private Saldo screen | `TelegramMenuIntents::usdtBalanceSection()` | none: projection read with honest unavailable/frozen states |
| USDT chooser, exact preview, status and history surfaces | `TelegramUsdtBalanceIntents` | none: rendering only |
| USDT callback + `/usdt` command adapter | `TelegramUsdtFundingCallbackHandler` | delegates to U6 confirm/cancel/hints; never credits |
| `/usdt <amount>` preview | `TelegramUsdtFundingActions::preview()` (U6) | none before Confirm |
| bounded personal history read model | `CustomerUsdtActivityService` | none: keyset read of ledger entries and open targets |
| `usdt:check` / `usdt:hash` status actions | `UsdtTopUpStatusReader`, `UsdtTopUpSettlement::submit()` (U7) | hint only; never evidence |
| hash-input prompt state | `TelegramUsdtFundingSession` | none: which target the next hash message belongs to |

IDR Store Balance behaviour and the QRIS/top-up/instruction flow are unchanged: the existing rows keep their callbacks and semantics, and only the private Saldo screen gained a USDT section plus its own screens. Group chats still get the public-safe surface only.

## 2. Screen contracts

- **Private Saldo screen.** The IDR line stays first; the USDT section then shows `💎 Saldo USDT: … USDT` with `balance:usdt` (Top-up) and `usdt:history` rows, or a localized unavailable/frozen line. A drifted projection and a frozen account never render a fabricated `0 USDT`, and neither offers a new funding action while the history stays readable. The top-up row only appears when `CustomerUsdtFundingGate::availability()` says the rail is available.
- **`/usdt` (or the Top-up row).** Shows network/token/application fee and the exact-amount command hint. H0-v1 disables presets, so the amount entry is the custom `/usdt <amount>` path; any other `usdt:` callback (for example a stale preset) resolves to the localized stale surface instead of an unknown action.
- **Preview.** One U6 `CustomerUsdtFundingIntent` bound to the current canonical message: requested credit, application fee, exact total to send, network, token contract and exclusive receiver from the intent snapshot, plus the confirmation deadline and the wrong-network warning. Confirm/cancel use `usdt:confirm:<nonce>` / `usdt:cancel:<nonce>`. Opening or replaying a preview writes no reservation, target, rail invoice, receipt, ledger entry or outbound effect.
- **Status.** Rendered from the immutable target snapshot plus durable work/resolution rows via `UsdtTopUpStatusReader`. The reader derives `preparing`, `awaiting_payment`, `expired_unresolved`, `manual_review`, `settled`, `resolved_by_compensation`, `externally_refunded` or `rejected`, and a `settled` resolution is only reported as credited when its linked ledger entry row really exists. Payment instructions are only shown once preparation is `ready`. `usdt:check:<topUpId>` re-reads durable state (no RPC in the webhook); `usdt:hash:<topUpId>` prompts the next private message for a BSC transaction hash, which U7 stores as a durable bounded hint.
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
- New USDT funding still fails closed outside disposable `local`/`testing`: production funding authorization, custody, legal and provider qualification remain H1/H2 gates, and no deployment, real deposit or production configuration is authorized by this package.
- `usdt:check` refreshes durable state instead of performing RPC inside a webhook; the scheduled `usdt-funding:recover` (bounded leasing) remains the only inspection driver.
- The settled presentation is verified against a really settled U7 target in `UsdtBalanceSettledPresentationTest`, which extends `UsdtTopUpTestCase` because preparing a rail must run outside a wrapping test transaction.
- Telegram messages cannot be proven delivered from the app's side; ambiguous send recovery stays with the existing outbound/navigation seams.
