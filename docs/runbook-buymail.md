# BuyMail reseller setup and warranty operations

## Dashboard setup

1. Activate reseller API in the [BuyMail portal](https://buymail.simuru-store.com/reseller) after cumulative deposits satisfy its threshold. The provider documents Rp100,000; verify its current account terms.
2. Admin → Supplier Reseller → confirm password → add BuyMail. Select IDR, conversion rate **1**, API key, private name, active setting and low-balance threshold. Keys are encrypted in database; blank updates preserve existing values. No ENV key or rebuild is needed.
3. Set the Dashboard-displayed HTTPS webhook URL in the portal. Save the one-time signing secret in the Dashboard signing-secret field. Enable the provider IP allowlist for the store server. Rotation immediately invalidates the old key; coordinate it with active attempts. A replacement key must verify the same provider account. Different accounts need separate connections.
   Verified same-account rotation preserves existing holds and allows exact GET recovery even if an original purchase is unresolved. Each purchase/deposit intent retains a private credential fingerprint. An unknown original purchase is never POST-replayed across a key change because the provider does not document cross-key idempotency scope. Unknown deposit creation likewise requires review after rotation; known order/deposit IDs remain readable with the verified new key.
4. Sync profile. BuyMail exposes one Gmail pool, mapped internally as `gmail`; it does not provide a products/SKU endpoint. Import creates an inactive store-owned draft, or explicitly map an equivalent existing Product. Set retail pricing, margin, guide and supported warranty terms in Product/Admin.
   If the provider reports refunds disabled, the Gmail source declines new purchases; stock observations remain visible privately and Dashboard displays the reason.
5. Fund Supplier Balance via the private BuyMail deposit page, or the supplier portal. Creating a deposit produces payment instructions; it does not pay them automatically. Only one unresolved deposit is allowed. Supplier funding never credits Customer Store Balance.
6. Keep Buyer selling disabled until live contract qualification and operational tests below are satisfied. Development tests use fake HTTP and do not authorize spending live funds.

## Purchasing and recovery

Customer browsing, cart creation and pending payment do not buy supplier accounts. Authoritative Paid settlement creates a durable acquisition. Slow buying uses `supplier` queue connection, queue `suppliers`: HTTP 95s, job/worker 120s, retry_after 180s, lease five minutes. Urgent uploads/reads use separate `supplier-claims` worker.

That worker checks `supplier-refunds` first for new proof uploads, then `supplier-claims` for status reads/deposits/webhooks. Supplier history and polling cannot leave a new time-limited refund upload behind their entire backlog. Proof decoding additionally bounds dimensions/pixels to prevent compressed-image memory exhaustion while preserving the original screenshot bytes.

A timeout is uncertain: keep funds/SKU commitments and recover the original reference or order. Replays use the original immutable body/key inside the seven-day documented idempotency retention with a safety margin. Never choose a fresh key for an unresolved original intent. Known IDs are recovered by GET. Documented no-debit errors permit safe fallback; generic proxy 500, HTML, malformed response and lost response do not.

`partial` charges only returned units. Allocate and expose ready units immediately because their warranty clock is already running. Further acquisition requests only proven missing quantity with distinct references and cumulative cost limits. Automatic remainder attempts are bounded; Admin monitor retains unresolved work. Manual completion must provide only missing credentials. No completed credential returns to Available inventory after any refund.

When remaining units are definitively not acquired, the buyer can request a refund for those units from the same private support page. Store Balance is credited once at the immutable retail amount; external refunds appear in Admin warranty monitoring and need a confirmed transfer. Delivered healthy accounts remain available. Unknown purchases never count as missing units. A supplier daily-limit response pauses new purchasing until the documented midnight GMT+7 reset, even if the profile still shows stock.

Profile cannot reserve supplier stock or price. BuyMail documents no quote/max-price field; margin prechecks and buffers cannot prevent an unannounced price increase during POST. Over-ceiling economic evidence is retained for review. Do not claim the system guarantees zero commercial loss from provider behavior or reversal.

## Claims and buyer resolution

Buyer opens Account support and refunds from their private Telegram receipt/order detail or owned Web invoice. Indonesian and English (also Malay) guidance covers proof, eligibility, deadline and settlement states. A signed Telegram link creates a scoped 30-minute session grant; Web uses Customer ownership or existing Recovery Key access. Invoice number alone does not grant access.

Proof is original phone Google-app JPEG/PNG <=4 MB with visible status bar; note <=500 characters. Browser screenshots are not accepted by the provider. Actual per-account `refund_deadline` controls submission, normally two hours after supplier delivery, not buyer message access. Timely local upload is not proof of timely supplier acceptance.

A timed-out upload leaves the stored original proof untouched as `submission_unknown`; the next authoritative GET is the only automatic recovery. When that GET finds no refund object for the same account, the deadline is still open and the claim is `submission_unknown` or `review`, Owner/Admin may use **Resubmit bukti original** in the private monitor. The action performs its own authoritative GET first, refuses whenever any refund object is present, records a new audit entry and re-dispatches the stored original proof to the urgent upload worker; it is never automatic and never a blind retry. The refund proof upload transport timeout is 45 seconds, below the 60-second claim worker timeout, leaving room for the follow-up GET.

Accepted upload remains pending verification. Authoritative account GET decides approved/rejected/reversed/cancelled. Seller dispute links and original encrypted screenshots are available privately to Owner/Admin. Wrong-password change older than one hour and Family Link are documented eligible examples; changes by buyer, new device/location and bulk sending are excluded. Clarify the provider's computer-first-login versus phone-proof guidance before promising a safe recovery procedure.

After verified approval, buyer chooses refund or replacement. Store Balance refunds credit immutable retail entitlement exactly once; externally funded refunds remain pending until an operator records actual transfer success with exact amount/currency/reference. A partial refund leaves healthy accounts/Payment Paid. Full refund thereafter uses only the remaining debit. A full external refund whose remainder has no verified claim (supplier refusal, store goodwill) records that remainder as one confirmed OrderRefund from the operator's exact transfer evidence on the Manual Fulfillment Review action; every registered refund still pending or uncertain must be confirmed or recovered first. Uncertain external transfer must retain its original reference and be recovered before another transfer.

Replacement uses a separate purchase intent, no buyer charge, and the original account is permanently retired. Do not switch to refund while replacement acquisition is uncertain. Proven rejection or zero debit/delivery allows switching. Replacement credentials are accessed from the private order and account-support page, with their own provider deadline. Supplier reversal never automatically removes a completed buyer refund; monitor liability and supplier funds privately.

If an already acquired replacement exceeds its expected cost, Admin must acknowledge its exact actual IDR cost before delivering the stored account. This action never buys or debits again, still requires complete trusted acquisition and rejects account-identity collisions. Unannounced supplier price changes cannot be prevented by the API's undocumented price-lock behavior; operator approval records the commercial decision instead of hiding it.

Explicit “Confirm account works” closes its refund window immediately and requires the buyer's acknowledgement. Delivery/receipt/page-open never performs confirmation automatically.

## Durable recovery and monitoring

Scheduler invokes:

```sh
php artisan suppliers:sync --limit=5
php artisan suppliers:recover --limit=25
php artisan suppliers:recover-warranty --limit=25
php artisan suppliers:notify-warranty-deadlines --limit=25
```

Buyer reminders are materialized at most once per supplier account unit 30 minutes before its `refund_deadline` and delivered through the existing Telegram outbound ledger. A unit is skipped when its stock item is retired, confirmation already closed the window, a claim exists, the order is not paid/claimable, the buyer has no Telegram chat, or the deadline has passed; delivery re-checks the same gates and finalizes without sending when the state changed. The unit marker and effect commit in PostgreSQL before dispatch, `suppliers:recover-warranty` re-discovers due reminders after Redis loss, and an ambiguous send keeps the ledger's manual delivery review instead of an automatic resend.

Warranty discovery re-enqueues due claim reads/uploads, replacement acquisitions, account confirmations, supplier deposits and webhook inbox work after Redis loss. Signed inbox verifies HMAC over raw body, timestamp ±5 minutes, event ID/type match and dedupe. GET is the source of truth even for correctly signed events. Telegram status updates use the existing outbound ledger; ambiguous sends require existing manual delivery review.

Monitor Admin supplier purchases, warranty cases, pending transfers, deposit state, failed jobs and both supplier worker pools. The Admin warranty page also lists unclaimed units whose deadline falls within the next 60 minutes together with whether the reminder was materialized or the buyer has no Telegram chat. Deadline-sensitive claims take priority in a separate worker. Do not paste credentials/screenshots/raw supplier replies into logs, tickets or public messages. Private proof downloads require recent password confirmation.

## Provider contract qualification

Public documentation gives incomplete GET/list envelopes. The implemented normalized reader expects `{order}`, `{account}`, `{deposit}`, `{orders:[...]}` and `{deposits:[...]}` (account/deposit singular raw rows are accepted). Confirm these with authenticated **read-only** calls before activating production. Validate reference filtering, idempotency across rotation/restart, partial zero delivery, refund statuses after reversal/cancellation, signing retry timestamps, deadline/outage rules and daily remaining capacity. The shown daily reset is GMT+7, unlike WITA.

Only after an explicitly authorized controlled spend, validate actual debit, account IDs/credentials, partial handling, retrieval, supported warranty submission and outcome. No key has been supplied for development and no live request/purchase/refund was made. Implementation tests establish store behavior under simulated contracts, not provider account validity or warranty outcome.
