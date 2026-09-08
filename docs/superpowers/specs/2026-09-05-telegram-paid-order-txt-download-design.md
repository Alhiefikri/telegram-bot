# Telegram Paid Order TXT Download and Action Panel

- **Status:** Implemented — release gate #428 closed after owner-confirmed testing.
- **Date:** 2026-09-05
- **Primary sources:** [Telegram Bot API — sendDocument](https://core.telegram.org/bots/api#senddocument), [Telegram Bot API — answerCallbackQuery](https://core.telegram.org/bots/api#answercallbackquery), and [Telegram Bot API — InlineKeyboardButton](https://core.telegram.org/bots/api#inlinekeyboardbutton).
- **Companion documents:** [`2026-08-22-telegram-combined-paid-receipt-rfc.md`](2026-08-22-telegram-combined-paid-receipt-rfc.md) and [`ADR-0007`](../../adr/0007-telegram-outbound-effects.md).
- **Scope:** Store Bot buyer experience after a paid Telegram Order: a post-receipt action panel, explicit full-credential TXT export, existing Buy Again/Main Menu actions, and the contract boundary for Report Issue.
- **Out of scope:** Excel export, changing payment or fulfillment state machines, removing protection from the normal receipt, Web Storefront changes, new environment variables, new queues, or storing generated files.
- **Decision owner:** Product/system design.
- **Brand canonical:** `LHIE STORE`.

## 1. Executive decision

The Store Bot will retain the current protected buyer receipt and add a separate action panel after the receipt delivery group completes. The panel offers an explicit TXT export. TXT is generated only after a second confirmation and is sent as a Telegram document without `protect_content`, because Telegram's protection prevents saving/forwarding and would defeat the requested download behavior.

The selected export policy is **full TXT**:

- email;
- password;
- Auth Token, when present;
- full validated OTP Mail Link, when present;
- Product/order context and item counts;
- the same authorized Product delivery note and usage-guide data that the buyer may already receive in the receipt.

The full export is an intentional privacy downgrade for that one document. The confirmation copy must state that the file can be saved, copied, and forwarded and cannot be recalled after download.

The implementation must use the existing paid credential presentation seam and the Telegram Outbound Effect Ledger. It must not parse encrypted stock payloads in a callback handler, place plaintext credentials in a queue payload/effect row/log, or send the document through an untracked direct-send path.

The expected VPS impact is low: bounded Order graph loading, in-memory UTF-8 string assembly, and one `sendDocument` request per confirmed download. No permanent file, database blob, browser storage, or new worker pool is required.

## 2. Evidence and current implementation

### 2.1 Current receipt protection

`TelegramReceiptNotifier::sendEffect()` currently sends the new receipt photo and continuation messages with `protect_content => true`:

- `src/app/Services/Telegram/TelegramReceiptNotifier.php:402-442`.

That explains the current Telegram client behavior for the paid receipt: forwarding and saving are intentionally restricted, and some current mobile clients also restrict screenshot/copy actions for protected content. The feature must not claim that Telegram can prevent every screenshot or notification preview; it only adds an explicit export path.

### 2.2 Current delivery and presentation seams

The existing implementation already provides the needed domain seams:

- `CredentialDeliveryPresentation` performs customer ownership, paid eligibility, lifecycle projection, late-payment-review protection, deterministic ordering, and structured/legacy credential projection.
- `TelegramCredentialDeliveryRenderer` owns Telegram credential formatting, access-method presentation, code entities, OTP buttons, Product notes, and usage-guide actions.
- `TelegramReceiptNotifier` owns buyer receipt rendering and Telegram adapter behavior.
- `TelegramOutboundEffectLedgerProtocol` owns durable claim, lease, ambiguity, retry, and finalization state.
- `TelegramOutboundEffectDispatcher` owns effect-type-to-job mapping.
- `ReceiptDeliveryPipeline` owns receipt delivery ordering and completion projection.

Relevant files:

- `src/app/Services/Credentials/CredentialDeliveryPresentation.php`
- `src/app/Services/Telegram/TelegramCredentialDeliveryRenderer.php`
- `src/app/Services/Telegram/TelegramReceiptNotifier.php`
- `src/app/Services/Telegram/TelegramOutboundEffectService.php`
- `src/app/Services/Telegram/TelegramOutboundEffectDispatcher.php`
- `src/app/Services/Telegram/ReceiptDeliveryPipeline.php`
- `src/app/Services/Telegram/TelegramBotClient.php`
- `src/app/Enums/TelegramOutboundEffectType.php`

### 2.3 Gaps

The current client has `sendMessage()` and `sendPhoto()` but no `sendDocument()` method:

- `src/app/Services/Telegram/TelegramBotClient.php:30-38,137-158`.

The current receipt reply markup exposes usage-guide buttons and the existing Support URL only:

- `src/app/Services/Telegram/TelegramReceiptNotifier.php:813-832`.

There is no current order-linked `Report issue` callback or ticket model. The existing `/support` route is a generic support surface, not an in-chat order issue workflow.

### 2.4 External transport contract

Telegram's official `sendDocument` method accepts an uploaded `InputFile`, caption/options, and `protect_content`. The documented Bot API limit is 50 MB, but this feature will enforce a much smaller application-level output bound. A document that must be downloadable cannot set `protect_content=true`.

Reference: [Telegram Bot API — sendDocument](https://core.telegram.org/bots/api#senddocument).

## 3. Problem statement

A new Telegram mobile client behavior prevents buyers from using the normal protected receipt as a practical backup: they cannot screenshot, forward, or copy account credentials. The existing receipt is intentionally protected for privacy, so simply removing protection would expose every paid receipt to forwarding and saving without an explicit buyer decision.

The product needs a deliberate, understandable escape hatch:

1. keep the normal receipt protected;
2. let the buyer request a file explicitly;
3. require confirmation before generating a full credential export;
4. make the file downloadable in Telegram;
5. keep the operation authorized, bounded, durable, and observable;
6. avoid materially increasing VPS work.

## 4. Goals

1. Deliver one separate buyer action panel after a new protected receipt completes.
2. Provide a TXT button; do not implement Excel.
3. Show a confirmation message before generating a file.
4. Generate a full UTF-8 TXT export in memory.
5. Include all authorized credential fields, including full OTP Mail Link and Auth Token, because the selected policy is full export.
6. Send the document with `protect_content=false` or an omitted protection option.
7. Restrict the export to the exact paying Customer and eligible paid Order.
8. Preserve all existing payment, Order, inventory, credential, and receipt state-machine ownership.
9. Use the existing Outbound Effect Ledger for the action panel and file delivery.
10. Keep plaintext outside durable persistence boundaries.
11. Make duplicate clicks, retries, timeouts, and stale confirmations deterministic.
12. Localize all customer-facing copy through the existing `TelegramLocale` catalog.
13. Keep existing legacy receipt effects immutable and avoid mass-resending old paid Orders.
14. Provide a credible test, smoke, performance, and rollback path.

## 5. Non-goals

- No Excel/XLSX/CSV export.
- No change to the current protected receipt's `protect_content=true` behavior.
- No automatic file delivery immediately after payment.
- No file generation for unpaid, refunded, cancelled, expired, or open-late-review Orders.
- No new payment provider, payment callback, inventory, or fulfillment behavior.
- No Recovery Key export. Recovery Key remains one-time and separate from Telegram credential delivery.
- No permanent TXT file storage.
- No new ENV setting for presentation, export policy, icons, labels, or product content.
- No new queue name or worker pool.
- No browser/localStorage/sessionStorage credential copy.
- No promise that Telegram's protected-content feature blocks every screenshot, preview, or device-level capture.
- No full two-way support chat unless the separate Report Issue workstream is implemented; the action panel must not contain a misleading no-op button.

## 6. Product behavior

### 6.1 Preconditions for the action panel

The panel is materialized only after the canonical new receipt group has completed successfully:

- Order has a canonical paid Payment;
- Order is in an eligible paid-delivery lifecycle projection;
- Customer has a Telegram ID;
- all canonical receipt chunks are `sent`;
- `orders.telegram_receipt_sent_at` has been set;
- the receipt group uses the new photo-caption template/version that opts into the panel.

The panel is a convenience delivery, not a fulfillment prerequisite. If the panel fails or becomes ambiguous, the successful credential receipt remains delivered and the Order's receipt completion projection is not rolled back.

Orders whose receipt group was already materialized under a legacy template are not mass-upgraded. An explicit new receipt resend may create a new-version receipt and a new panel according to the normal resend policy.

### 6.2 Action panel message

The panel is a separate `sendMessage` after the final receipt chunk. It must not be appended to the credential-bearing caption or packed into a receipt continuation chunk.

Canonical v1 action-panel copy uses the existing generic Support behavior. It must not promise in-chat issue resolution until a separate order-linked issue intake exists.

English:

```text
🛟 Need help with this order? Tap Support below and include your Order ID so we can help.
```

Indonesian:

```text
🛟 Butuh bantuan dengan pesanan ini? Tekan Support di bawah dan sertakan Order ID agar kami dapat membantu.
```

Malay:

```text
🛟 Perlukan bantuan dengan pesanan ini? Tekan Support di bawah dan sertakan Order ID supaya kami boleh membantu.
```

All copies must be stored as locale keys, not hardcoded in a callback handler. Existing supported locales remain `id`, `en`, and `ms`.

### 6.3 Action panel keyboard

Because Excel is out of scope, TXT uses a full-width row:

```text
[ ⬇️ TXT                 ]
[ 🛟 Support             ]
[ 🎁 Buy Again           ]
[ 📦 Main Menu           ]
```

Localized labels are required. Use one inline keyboard. TXT, Buy Again, and Main Menu are callback buttons; Support uses the configured existing support URL. The panel must remain attached to the paid Order context and contain no credential, Auth Token, OTP URL, Recovery Key, or other secret.

If the paid Order has no deliverable credential, omit the TXT row. Keep only actions that remain meaningful:

```text
[ 🛟 Support             ]
[ 🎁 Buy Again           ]
[ 📦 Main Menu           ]
```

### 6.4 Buy Again

`Buy Again` must reuse the existing catalog/menu adapter:

- acknowledge the callback;
- clear stale product/order interaction session state;
- call the existing public catalog entry point;
- do not create a new Cart, Order, Payment, or reservation merely by pressing the button;
- preserve Checkout Maintenance behavior.

The implementation may call the existing catalog method behind the callback rather than inventing a second catalog route.

### 6.5 Main Menu

`Main Menu` must reuse `TelegramMenuIntents::sendMainMenu()` and its existing locale-aware keyboard. It must not duplicate the menu keyboard or introduce a second menu state machine.

### 6.6 TXT request and confirmation

When the buyer presses TXT:

1. acknowledge the callback promptly;
2. validate the Order ID/customer context and current paid eligibility;
3. create a short-lived confirmation context bound to the exact Customer and Telegram chat;
4. send a new confirmation message;
5. do not hydrate or render credential values until the buyer confirms.

Suggested English confirmation:

```text
⬇️ Confirm Download

📦 Product: Gemini AI Pro 18m
🧾 Order: AKU-021B2BC4
📎 Format: TXT (.txt)
📊 Items: 10

The file will contain your complete product credentials.
It can be saved, copied, or forwarded after download.

Generate this file and send it here?
```

Buttons:

```text
[ ✅ Send file ] [ ❌ Cancel ]
```

Suggested Indonesian confirmation:

```text
⬇️ Konfirmasi Download

📦 Produk: Gemini AI Pro 18m
🧾 Order: AKU-021B2BC4
📎 Format: TXT (.txt)
📊 Item: 10

File ini berisi credential produk lengkap.
Setelah di-download, file dapat disimpan, disalin, atau diteruskan.

Buat file ini dan kirim di sini?
```

The summary rules are:

- `Product` is one line per Product for a multi-Product Order; do not silently discard additional Products.
- `Order` uses the immutable invoice number, falling back to the stable Order key only when the invoice number is genuinely absent.
- `Items` is the sum of immutable `OrderItem.quantity`, not the number of visible lines and not a guessed count from text.
- The summary contains no credential value or full OTP URL.
- Product names and invoice numbers are treated as untrusted display values and must not be allowed to alter callback parsing or filename paths.

### 6.7 Confirmed file delivery

When the buyer presses `Send file`:

1. consume the confirmation context exactly once;
2. re-check Customer ownership, Telegram chat, paid status, lifecycle eligibility, and absence of open late-payment review;
3. create one durable TXT-download Outbound Effect;
4. acknowledge that generation has started;
5. let the existing queue worker render and send the document;
6. remove or disable the confirmation buttons after a definite successful send when the origin message ID is available; this cleanup is best effort and is not delivery state;
7. never send an empty, partial, or redacted-as-success file if credential projection fails.

The successful Telegram document should use a safe filename such as:

```text
lhie-store-AKU-021B2BC4.txt
```

The filename must be derived from the immutable invoice/order identifier and sanitized to a conservative ASCII character set. It must not contain Product-authored arbitrary path separators or control characters.

The document caption may be short and non-secret:

```text
✅ Your complete product credentials are attached.
Keep this file private.
```

The document itself must be sent with `protect_content=false` or with the option omitted. A protected document would not satisfy the user requirement.

### 6.8 Cancelled or stale confirmation

If the buyer presses Cancel:

- consume the confirmation context;
- send a short localized acknowledgement or edit the confirmation message;
- do not load credential values;
- do not create an Outbound Effect;
- do not generate a file.

If a confirmation expires, the bot must report that the confirmation is no longer valid and require the buyer to press TXT again. It must not guess the Order from a stale confirmation or silently generate a file.

## 7. TXT document contract

### 7.1 Document boundary

Introduce one deep in-memory exporter seam, for example:

```php
final class TelegramPaidOrderTxtExporter
{
    public function export(Order $order, Customer $customer): TelegramDocumentPayload;
}
```

The exact class name may follow repository naming, but the interface must remain small. The returned `TelegramDocumentPayload` is transient and must not be serializable, cacheable, loggable, or persisted.

The exporter owns:

- eager loading of the complete Order graph for one render attempt;
- calling `CredentialDeliveryPresentation::forCustomer()` once;
- stable Product/account ordering;
- full export-only access-value rendering;
- UTF-8 text assembly;
- filename generation;
- byte/character bounds;
- fail-closed behavior for malformed credential data.

It must not own:

- payment transitions;
- Order lifecycle changes;
- Outbound Effect claim/finalization;
- callback parsing;
- queue dispatch;
- admin issue handling.

`TelegramCredentialDeliveryRenderer` may expose a small internal field projection needed by the exporter, but the exporter must not duplicate encrypted-payload parsing or invent a second paid eligibility implementation.

### 7.2 Source data and ordering

The exporter loads and uses:

- `Order` and its Customer;
- canonical paid Payment and payment metadata needed for the summary;
- `OrderItem` immutable Product name/quantity/delivery-note/usage-guide snapshots;
- allocated `ProductStockItem` rows in deterministic order;
- existing structured or legacy credential projection.

The order is:

1. OrderItem ascending stable ID/order;
2. Product Stock Item ascending stable ID/order;
3. account number restarting at 1 for each Product section.

The exporter must eager-load the complete graph before iterating accounts. Query count must not grow with account count.

### 7.3 Full export fields

For each structured account:

```text
Account #<n>
email : <email>
password : <password>
access: OTP Mail Link
OTP Mail Link : <full validated HTTPS URL>
```

or:

```text
Account #<n>
email : <email>
password : <password>
access: Auth Token
Auth Token : <token>
```

For access method `none`:

```text
Account #<n>
email : <email>
password : <password>
```

For a legacy credential, preserve the existing safe formatted credential text. Do not infer structured fields from free-form text merely to make the export look uniform.

After the account group, include Product delivery note/tutorial when present. Include each usage guide at most once per logical Product, using the same safe URL projection as the current receipt. The full OTP URL is the deliberate exception: it is allowed only inside the explicitly requested TXT document.

Do not include:

- Recovery Key;
- checkout key;
- payment provider API data;
- raw payment proof;
- internal database IDs unrelated to the Order/invoice summary;
- customer email/phone unless it is already part of the credential itself;
- secret values in any non-document metadata.

### 7.4 Text encoding and bounds

The file contract is:

- UTF-8 without a BOM;
- `\n` line endings;
- plain text, no Markdown requirement;
- stable labels and deterministic ordering;
- no secret field split or truncation;
- no binary or archive format;
- maximum internal output bound well below Telegram's 50 MB transport limit.

Use an application-level bound such as 1 MiB unless an implementation review selects a smaller value. A normal 1–20 account export should be far below that bound. If the bound is exceeded, fail closed and show safe support copy; do not truncate credentials or send a partial file.

### 7.5 Failure behavior

The exporter must fail before transport when:

- the Customer does not own the Order;
- the Order is not currently eligible for paid delivery;
- a canonical paid Payment cannot be established;
- there is an open late-payment review;
- allocated credential count and projected credential count disagree;
- a structured credential or required access value is malformed;
- an OTP URL fails the existing HTTPS/host/length validation;
- a legacy payload cannot be safely represented;
- the output exceeds the local bound.

A render failure must not produce a partial TXT, a blank success document, or an exception containing secret values.

## 8. Authorization and privacy invariants

All gates apply both when the confirmation is shown and again immediately before document rendering/sending:

1. `Order.customer_id` equals the callback Customer ID.
2. Customer Telegram ID equals the exact effect target chat ID.
3. Order has `payment_status=paid`.
4. Payment has `status=paid` and a valid paid timestamp.
5. Order lifecycle projection allows paid delivery.
6. Order has no open late-payment/manual-fulfillment review that blocks credential delivery.
7. The credential projection is obtained through `CredentialDeliveryPresentation::forCustomer()`.
8. Only the Order's immutable snapshots and allocated Product Stock Items are rendered.
9. No Recovery Key is included.
10. No credential is accepted from callback data, cache data, request text, or user-provided filename input.
11. No secret is written to `TelegramOutboundEffect.outcome_metadata`.
12. No secret is written to queue payloads, logs, metrics, audit metadata, exception messages, browser state, or temporary durable storage.
13. The full OTP URL exists only in transient exporter memory and the outbound multipart document request.
14. The document's `protect_content` option is deliberately false/omitted and is surfaced as a privacy warning in customer copy.
15. The normal receipt photo and continuation messages remain protected.

Already downloaded files cannot be revoked. If a later refund/cancellation happens, a new download request must be denied; previously sent files remain outside server control.

## 9. Telegram Outbound Effect design

### 9.1 Action panel effect

The action panel is a separate effect group materialized only after a new-version receipt group completes. Proposed values:

```text
effect_type:       receipt.order_actions
template_version:  receipt-actions-v1
telegram_method:   sendMessage
queue:             notifications
target_type:       customer
source_type:       Order
source_id:         Order ID
```

Proposed deterministic key:

```text
order:<order_id>:telegram_receipt_actions:v1:<receipt_group_version>
```

Safe `outcome_metadata` may contain only:

- `delivery_context=receipt_actions`;
- `action_count`;
- `txt_available` boolean;
- `product_count`;
- `item_count`;
- `receipt_group_key` or a safe version/category identifier;
- coarse copy/keyboard size bucket.

It must not contain the panel's rendered text if the text includes arbitrary Product names, and it must never contain credentials or URLs.

The action panel is independent after receipt completion:

- panel `failed`/`unknown` does not roll back the paid Order or receipt marker;
- panel delivery uses the shared ledger claim/finalize protocol;
- panel ambiguity is not blindly retried;
- scheduled recovery may materialize a missing panel for a completed new-version receipt group;
- old legacy receipt groups are not backfilled in bulk.

No new `orders.*` marker is required if the panel's deterministic effect group is the source of truth. If implementation adds a marker for operational efficiency, it must be a safe projection and must not become a second delivery state machine.

### 9.2 TXT download effect

Each confirmed download request creates one effect:

```text
effect_type:       receipt.txt_download
template_version:  receipt-txt-v1
telegram_method:   sendDocument
queue:             notifications
target_type:       customer
source_type:       Order
source_id:         Order ID
```

Proposed key shape:

```text
order:<order_id>:telegram_txt_download:<request_nonce>:v1
```

`request_nonce` is a random opaque request identity, not a secret-derived value. A repeated callback for the same confirmation must resolve to the same effect or a no-op; it must not create two effects from one button press.

Safe metadata may contain:

- `delivery_context=explicit_txt_download`;
- `format=txt`;
- `product_count`;
- `item_count`;
- coarse `byte_size_bucket` after rendering, if needed;
- `origin_message_id`, if used for best-effort confirmation cleanup;
- `confirmation_nonce` reference only if it is not itself secret-bearing.

It must not contain:

- file contents;
- email/password/Auth Token/OTP URL;
- raw rendered text;
- secret-derived hashes/fingerprints;
- credential-bearing caption or keyboard payload.

The document is rendered only when the effect worker claims the effect. The queued job carries an Order/effect ID and timing metadata, never the document bytes or `CredentialDelivery` object.

### 9.3 Delivery state and ambiguity

`sendDocument` is subject to the same ambiguity problem as `sendMessage` and `sendPhoto`: Telegram has no documented outbound idempotency key that lets the server prove a timeout did not deliver the file.

Required behavior:

- successful response with a positive Telegram message ID → `sent`;
- definite pre-send validation failure or terminal Telegram rejection → `failed`;
- bounded retryable transport/429 failure → shared policy retry on the same effect;
- timeout, connection loss, worker interruption after request start, or uncertain response → `unknown`/`manual_review`;
- `unknown` must not automatically resend the TXT;
- an explicit new TXT click may create a new request after a clear duplicate-risk acknowledgement if product policy allows it;
- no automatic whole-receipt resend is triggered by a TXT delivery ambiguity.

The document pipeline must use the same claim-token fencing and response message-ID validation already used by receipt delivery.

### 9.4 Queue and worker

Use the existing `notifications` queue. Do not add an ENV variable or a worker pool.

The action panel can use a dedicated job or a small effect-family job. The TXT document must be sent through a job because:

- it keeps Telegram network latency out of the callback handler;
- it prevents callback requests from carrying credential-bearing strings;
- it makes delivery outcome observable;
- it keeps retries and ambiguity within the existing ledger policy;
- it allows the renderer to release transient plaintext after the attempt.

The callback response should be immediate and safe, for example:

```text
✅ Request received. Your TXT file is being prepared.
```

The worker then sends the document as a separate message.

## 10. Callback and confirmation state

### 10.1 Callback data

Callback data must remain short and contain no secret. Telegram inline callback payloads are bounded, so do not embed product names, credential values, OTP URLs, or arbitrary Product text.

The action panel may use an order UUID or a compact signed Order token. The preferred rule is:

- panel callback identifies the Order or a signed compact context;
- handler re-queries the Customer-owned Order;
- confirmation callback uses a random opaque nonce;
- server-side short-lived state maps nonce → Customer ID, chat ID, Order ID, and expiry;
- confirmation state is single-use.

A confirmation nonce must not be accepted from another Customer or Telegram chat, even if it is copied into another callback request.

### 10.2 Confirmation TTL

Use a short TTL, such as 10 minutes, for the confirmation context. The exact TTL is a product/configuration decision but must be bounded and documented. Do not create a new ENV variable for it; use a code constant unless operations later proves a deployment knob necessary.

Cache state may contain only identifiers and safe summary data. It must not contain `CredentialDelivery`, credential strings, OTP URLs, or rendered file content.

### 10.3 Callback handling rules

`TXT` callback:

- reject malformed/stale token with generic localized copy;
- re-check Customer ownership and paid status;
- do not reveal whether another Order exists;
- send confirmation with safe summary;
- no file generation.

`Send file` callback:

- consume nonce atomically;
- reject replay;
- create exactly one TXT effect for that nonce;
- return immediately;
- no direct credential rendering in the callback path.

`Cancel` callback:

- consume nonce atomically;
- no TXT effect;
- no credential loading.

Buttons from a stale action panel must be safe after refund/cancellation: answer with a generic unavailable message and do not expose internal status details.

## 11. Report Issue contract

### 11.1 Current gap

The repository currently has generic `/support` handling and a configured support contact, but no order-linked issue intake or ticket aggregate. A button labelled `Report issue` with the promise “we'll fix it right here” would be misleading if it only opens a generic DM/WhatsApp link.

### 11.2 Chosen v1 behavior

For this implementation, `Support` uses the repository's existing configured Support URL/contact. It is the safe default because the repository has no order-linked issue intake or ticket aggregate.

The v1 flow is:

1. Buyer presses `Support` on the paid Order panel.
2. Telegram opens the existing Support destination.
3. The localized panel copy asks the buyer to include the Order ID.
4. No issue record, free-text capture, credential echo, or new support state is created by this feature.

The action must be useful and must not acknowledge a nonexistent in-chat issue workflow. A future order-linked issue intake may add localized categories, short descriptions, durable issue state, and redacted admin notification as a separate feature.

### 11.3 Future issue intake security

If the future issue intake is implemented, issue descriptions may contain credentials pasted by the buyer. Therefore:

- do not put raw descriptions in logs or metrics;
- encrypt any durable description if a new issue table is introduced;
- do not echo the submitted description back unnecessarily;
- keep the issue callback/order scope customer-bound;
- do not include TXT contents or OTP URLs in admin effect metadata;
- use a separate issue state model rather than overloading payment or receipt state.

The future Report Issue workstream is not required for TXT rendering. Until it exists, the action panel uses the generic Support label and copy defined in Section 6.

## 12. Localization contract

Add locale keys in `src/lang/id.json`, `src/lang/en.json`, and `src/lang/ms.json` for:

- action-panel lead text;
- TXT button;
- Support button;
- Buy Again button;
- Main Menu button;
- TXT confirmation title;
- Product/Order/Format/Items labels;
- full-export privacy warning;
- Send File and Cancel buttons;
- request-started message;
- stale/expired confirmation message;
- unavailable/not-eligible message;
- successful document caption;
- generation/render failure message;
- generic Support copy.

Use `TelegramLocale::translate()` or `translateLocale()` consistently. Do not derive language from Telegram's current client language after the Order; use the stored Customer locale used by the receipt.

The current `TelegramLocale` falls back to English for null/unsupported locale. This behavior remains unchanged unless a separate product decision changes it.

## 13. Architecture and file ownership

### 13.1 Proposed source changes

The implementation should be organized around these ownership boundaries:

| Area | Owner | Responsibility |
| --- | --- | --- |
| Telegram transport | `TelegramBotClient` | Add `sendDocument()` multipart transport and safe response handling. |
| Test transport | `FakeTelegramBotClient` | Record `sendDocument` calls without credential output. |
| TXT presentation | `TelegramPaidOrderTxtExporter` or equivalent | Build transient full TXT payload from authorized presentation. |
| Document payload | `TelegramDocumentPayload` or equivalent | Hold bytes/name/options in memory only; reject serialization/debug exposure. |
| Action panel copy/keyboard | `TelegramReceiptActionPanel` or receipt presentation adapter | Localized safe text and callbacks; no credential parsing. |
| Receipt handoff | `ReceiptDeliveryPipeline` / effect service | Materialize panel only after new receipt group completion and preserve recovery. |
| Effect family | `TelegramOutboundEffectService` | Safe effect rows, deterministic keys, allowed metadata, idempotency. |
| Queue mapping | `TelegramOutboundEffectDispatcher` | Map action/document effect types to jobs on `notifications`. |
| Panel delivery | dedicated action-panel pipeline or small adapter | Claim/finalize `sendMessage`; no second state machine. |
| TXT delivery | dedicated TXT pipeline/job | Claim/finalize `sendDocument`; render just-in-time; no secret persistence. |
| Callback routing | new callback handler | Ownership, nonce, confirmation, effect creation, and safe acknowledgement. |
| Buy Again/Main Menu | existing catalog/menu adapters | Reuse existing entry points and clear interaction state. |
| Support fallback | existing configured Support route | Open real Support behavior without promising in-chat issue resolution. |

### 13.2 Transport method contract

Proposed client method:

```php
public function sendDocument(
    int|string $chatId,
    string $documentContents,
    string $filename,
    ?string $caption = null,
    array $replyMarkup = [],
    array $options = [],
): array;
```

Required behavior:

- attach the content as multipart `document`;
- merge `chat_id` and safe options;
- encode reply markup consistently with `sendPhoto` where needed;
- preserve `caption_entities` encoding rules if captions use entities;
- require a successful Bot API response;
- let the shared response validator require a positive message ID;
- never log request bodies or document contents.

The fake client must record method/metadata for assertions but must not expose or dump document contents in failure messages.

### 13.3 No new generic file storage abstraction

Do not add S3/object storage, local file storage, download URLs, or a generic attachment repository for this feature. A transient string is sufficient for the selected TXT format. If a future export becomes large or binary, that should be a separate design with a new evidence/security review.

## 14. Performance and VPS plan

### 14.1 Expected work

One confirmed TXT request performs:

1. one bounded Order/Payment/OrderItem/Product/StockItem load;
2. one paid credential projection;
3. one bounded in-memory text assembly;
4. one Telegram multipart upload;
5. one ledger finalization.

The feature must not query once per account. It must not render an invoice image for the TXT path. It must not call a provider, payment API, RPC, or browser renderer.

### 14.2 Resource controls

Required controls:

- local maximum output bytes, proposed 1 MiB;
- bounded account/item count consistent with actual Order constraints;
- confirmation TTL;
- per-Customer/per-Order download rate limit to prevent accidental or malicious spam;
- no automatic retry after ambiguous document delivery;
- no permanent file cache;
- no secret-bearing metrics.

A conservative starting policy is one active confirmation and one pending TXT effect per Customer/Order/request nonce. Repeated explicit requests may be allowed after the previous request is `sent` or resolved, subject to a bounded rate limit.

### 14.3 Measurement plan

Measure in production-equivalent Docker/PostgreSQL, not only SQLite:

- query count for 1, 5, 20, and 50 credentials;
- TXT render duration;
- worker RSS before/after render;
- output byte count;
- queue wait and document Telegram duration;
- effect completion time;
- failure/ambiguity categories;
- no-secret log/effect/queue scan.

Acceptance is:

- query count remains bounded and independent of credential count;
- output stays under the local bound;
- TXT rendering does not materially exceed the existing receipt render budget;
- no additional worker pool or ENV is required;
- a normal 10-item export completes through the existing `notifications` worker under normal Telegram availability.

Exact p95 thresholds should be recorded from the first benchmark and then made a release gate. Existing receipt benchmark targets in `2026-08-22-telegram-combined-paid-receipt-rfc.md` remain the reference for the shared receipt path.

**[INFERENCE]** For the requested 10-item scenario, the TXT string should be much smaller and cheaper to assemble than the existing invoice PNG. This must still be verified with a benchmark because actual credential lengths and worker configuration are runtime facts.

## 15. Observability and safe operational metadata

Add safe effect/template counters for:

- action panel materialized/sent/failed/unknown;
- TXT request accepted/rejected/stale;
- TXT effect sent/failed/unknown/manual-review;
- output size bucket;
- Product/item count bucket;
- render duration bucket;
- queue wait/delivery duration bucket;
- authorization rejection category;
- rate-limit rejection category.

Never record:

- file text;
- email/password/Auth Token;
- full OTP URL;
- document hash derived from content;
- raw Telegram request/response body;
- Product delivery note if it can contain credentials;
- customer-submitted issue text in metrics.

Correlation fields may use the existing `DogfoodTiming::correlation()` behavior for Order, Customer, Effect, and callback IDs. Correlation values must not be derived from secret content.

The operational review projection must display only safe categories and counts. A support operator who needs to verify a file delivery may inspect Telegram using the effect's safe chat/message IDs, not the stored file contents.

## 16. Rollout plan

### Phase 0 — specification gate

- Approve this specification and the full-export privacy decision.
- Confirm that downloadable TXT intentionally has no Telegram content protection.
- Confirm the v1 Support fallback; do not add order-linked issue intake to this release.
- No source, schema, queue, or production change before approval.

### Phase 1 — transport and exporter

- Add `sendDocument()` to `TelegramBotClient` and `FakeTelegramBotClient`.
- Add transient document payload type.
- Add TXT exporter using `CredentialDeliveryPresentation`.
- Add unit tests for structured `none`, OTP, Auth Token, legacy, multi-Product, notes/guides, URL validation, ordering, bounds, and fail-closed behavior.
- Do not expose the new button yet.

### Phase 2 — durable TXT delivery

- Add TXT effect type/template and safe policy metadata.
- Add document delivery pipeline/job and dispatcher mapping.
- Add claim/finalize/ambiguity/retry behavior.
- Add callback confirmation nonce state and customer/order re-checks.
- Add feature tests using fake Telegram HTTP/client transport.

### Phase 3 — post-receipt action panel

- Add localized action-panel presenter and new panel effect/template.
- Materialize/dispatch only after a new receipt group completes.
- Add recovery for the committed receipt-complete → panel-effect handoff.
- Add TXT callback to the panel.
- Add Buy Again/Main Menu callbacks by reusing existing adapters.
- Keep old receipt effect groups immutable and do not mass-send panels to old paid Orders.

### Phase 4 — support action

- Add the existing Support URL/contact as an inline action.
- Use the generic localized Support copy and ask the buyer to include the Order ID.
- Do not add free-text capture, ticket persistence, or a stronger in-chat repair promise.
- Treat order-linked Report Issue intake as a separate future feature.

### Phase 5 — staging verification

- Run focused automated tests.
- Run the real application with a synthetic paid Order and a test Telegram target.
- Confirm Android/iOS latest Telegram behavior: protected receipt remains protected, explicit TXT document downloads, document can be opened, and no credential appears in the panel/confirmation.
- Confirm queue recovery and ambiguous delivery behavior.
- Run the bounded performance benchmark.
- Review logs/effect rows/queue payloads for secret absence.

### Phase 6 — production activation

- Deploy PHP/application changes atomically through the existing GitHub Actions/VPS process.
- Ensure the existing `notifications` worker is serving the effect queue.
- Monitor safe counts and failures for the observation window.
- Do not enable a hidden ENV flag; the versioned effect/template and persisted state determine behavior.

## 17. Rollback plan

Rollback is application-version-aware and must preserve effect rows.

- Do not delete action-panel or TXT effect rows.
- Do not reset `unknown`/`manual_review` effects to `pending`.
- Do not auto-resend documents during rollback.
- Deploy a compatibility version that can read the new effect/template rows before pausing or disabling new dispatch.
- If the previous binary cannot recognize the new effect types, deploy a forward compatibility patch or pause those jobs; do not downgrade blindly.
- Existing receipt groups and `telegram_receipt_sent_at` remain unchanged.
- A failed panel does not require paid-order rollback.
- A confirmed TXT file already accepted by Telegram cannot be revoked.
- If a security incident occurs, disable new TXT action materialization at the application release/effect policy boundary, preserve the ledger for audit, and keep the normal protected receipt path available.
- Do not remove encrypted Product Stock Items or rewrite credential payloads.

## 18. Acceptance matrix

Every case must assert observable behavior and the protected invariant. Fixtures must be synthetic and must never be copied into logs or artifacts.

| ID | Scenario | Expected result | Invariant |
| --- | --- | --- | --- |
| A1 | New paid Order, one structured `none` account | Protected receipt completes, then one action-panel message with TXT/Report/Buy/Menu actions | Panel is ordered after receipt; receipt remains protected |
| A2 | New paid Order, OTP account | TXT confirmation can be shown; exported TXT contains full validated OTP URL; receipt text contains only domain/button | Full URL is export-only and never appears in safe metadata |
| A3 | New paid Order, Auth Token account | TXT contains Auth Token; document is downloadable; receipt remains protected | Explicit opt-in is the only unprotected path |
| A4 | Legacy credential | Export preserves safe legacy formatted text; no schema guessing | Legacy compatibility and fail-closed rules |
| A5 | Multi-Product Order | Confirmation lists all Products; TXT contains all Products in deterministic order | No silent item loss |
| A6 | Ten-item Order | Summary reports total item quantity; all allocated accounts occur exactly once | Quantity/account invariant |
| A7 | Product note and usage guide | Note/guide included according to existing snapshot/dedup rules | No duplicate or mutable Product lookup |
| A8 | No credential projection | TXT row omitted; safe panel remains; no empty document | No misleading success file |
| A9 | Unpaid Order | TXT callback rejected; no confirmation/file/effect | Paid authorization |
| A10 | Different Customer callback | Generic rejection; no Order existence leakage | Customer ownership |
| A11 | Refunded/cancelled/expired Order | Existing panel button becomes unavailable; no file | Current-state recheck |
| A12 | Open late-payment review | Confirmation/file rejected | Manual review protection |
| A13 | Stale confirmation | Rejected after TTL; no credential hydration | Short-lived one-use state |
| A14 | Cancel button | No file/effect; confirmation consumed | Explicit consent |
| A15 | Double Send callback | One effect/document request for one nonce | Idempotency |
| A16 | Telegram 429/definite retryable error | Shared bounded retry policy applies | No unbounded worker loop |
| A17 | Telegram timeout after upload starts | Effect becomes `unknown`/manual review; no blind resend | Ambiguous delivery safety |
| A18 | Telegram terminal rejection before send | Effect is safely failed; no secret in error | Safe failure classification |
| A19 | Worker dies before dispatch | Recovery finds committed pending effect and dispatches it | Durable handoff |
| A20 | Worker dies after possible document acceptance | Recovery does not resend automatically | Duplicate credential/file avoidance |
| A21 | Document renderer throws | No partial/empty file; safe failed effect | Fail closed |
| A22 | Output exceeds internal bound | No truncation; safe failure/support message | Bounded resource use |
| A23 | File filename injection input | Conservative sanitized filename | Path/control safety |
| A24 | `protect_content` inspection | Receipt photo/continuations are true; TXT document is false/omitted | Transport contract |
| A25 | Panel localization | `id`, `en`, `ms` copy follows Customer locale | Locale ownership |
| A26 | Buy Again | Existing catalog opens; no new Order merely from click | Existing catalog ownership |
| A27 | Main Menu | Existing menu adapter opens; no duplicate keyboard logic | Existing menu ownership |
| A28 | Report Issue generic fallback | If issue workflow is absent, button opens real Support behavior and copy does not promise in-chat fixing | No-op/misleading UX prevention |
| A29 | Report Issue intake, if enabled | Category and text are bound to Order; admin receives safe operational notification | Issue scoping/privacy |
| A30 | Existing legacy receipt effect | No action panel is silently injected or receipt rewritten | Backward compatibility |
| A31 | Explicit receipt resend | New-version receipt/panel behavior follows resend policy; old effect remains immutable | Durable history |
| A32 | Secret scan | No credential/file text/OTP URL in effect metadata, queue payload, logs, audit, or metrics | No-secret persistence |
| A33 | Query benchmark | Query count remains bounded for 1/5/20/50 accounts | No N+1 |
| A34 | Mobile smoke test | Latest Telegram client can download and open TXT after explicit confirmation | Real surface behavior |

## 19. Test plan and verification commands

### 19.1 Unit tests

Add or extend tests under `src/tests/Unit/` for:

- TXT document formatting and deterministic ordering;
- full OTP URL/Auth Token inclusion only in the transient document payload;
- legacy credential handling;
- output byte bound and filename sanitization;
- document payload serialization/debug rejection;
- safe metadata allowlist;
- confirmation nonce validation/expiry/one-use behavior.

Unit tests must not assert implementation text by source inspection; they must assert formatted output and privacy boundaries through the module interface.

### 19.2 Feature tests

Add focused feature coverage under `src/tests/Feature/Telegram/`, likely:

- `TelegramPaidOrderTxtDownloadTest.php`;
- `TelegramReceiptActionPanelTest.php`;
- extensions to `TelegramReceiptOutboundEffectTest.php` for post-receipt ordering and compatibility.

Required assertions include:

- exact Telegram method (`sendDocument` for the file);
- positive response/message-ID finalization;
- `protect_content` true on normal receipt and false/absent on TXT;
- full export fields in the document body;
- no full OTP URL in panel, confirmation, effect metadata, queue payload, or logs;
- ownership/status rejection;
- no duplicate effect for replayed confirmation;
- queue/recovery/ambiguity states;
- no Excel effect/button;
- all locales;
- legacy groups unchanged.

### 19.3 Focused commands

Run from `src/` after implementation:

```bash
php artisan test --filter=TelegramPaidOrderTxtDownloadTest
php artisan test --filter=TelegramReceiptActionPanelTest
php artisan test --filter=TelegramReceiptOutboundEffectTest
```

Then run the complete project suite:

```bash
composer test
```

If the implementation adds PostgreSQL locking/concurrency coverage for effect creation or confirmation replay, run the project's concurrency lane:

```bash
composer test:concurrency-gate
```

The final verification report must distinguish focused tests, full tests, concurrency evidence, real Telegram staging smoke, and performance benchmark results. A passing SQLite suite alone does not prove PostgreSQL lease/idempotency behavior.

### 19.4 Real-surface smoke scenario

Using a staging/test Telegram target and synthetic credentials:

1. Create a paid eligible Order with 10 items.
2. Let the normal receipt group deliver.
3. Verify the action panel appears below the final receipt chunk.
4. Press TXT.
5. Verify confirmation summary and privacy warning.
6. Press Cancel; confirm no document request.
7. Press TXT again and Send file.
8. Verify Telegram receives a `.txt` document and the client can download/open it.
9. Verify document body contains all synthetic fields, including full OTP URL/Auth Token.
10. Verify receipt remains protected and panel/confirmation do not contain credential values.
11. Re-submit the same callback and verify no duplicate effect from one confirmation nonce.
12. Simulate timeout/worker interruption and verify `unknown` without automatic duplicate send.

## 20. Implementation checklist and commit order

The implementation should be split into small reviewable changes with each commit preserving a runnable application:

1. Add `sendDocument()` transport and fake transport coverage.
2. Add transient document payload and TXT exporter.
3. Add TXT exporter unit/feature contract tests and secret-boundary tests.
4. Add TXT effect type/template, safe metadata, policy, job, and dispatcher mapping.
5. Add confirmation nonce state and TXT callback handler.
6. Add document delivery pipeline with claim/finalize/ambiguity/retry behavior.
7. Add localized action-panel copy and panel effect.
8. Add post-receipt handoff/recovery for the panel.
9. Wire Buy Again/Main Menu to existing adapters.
10. Wire the generic Support fallback action.
11. Add performance benchmark and safe observability counters.
12. Run focused tests, full tests, concurrency evidence where applicable, staging smoke, and secret scans.

No commit may add an environment variable, a second outbound ledger, a plaintext credential cache, or a permanent generated-file table.

## 21. Decisions before implementation

The following product decisions are resolved for this plan:

- export format: TXT only;
- export content: full credential export;
- full OTP Mail Link: included in TXT;
- Auth Token: included in TXT;
- normal receipt: remains protected;
- TXT document: intentionally unprotected/downloadable;
- file generation: explicit confirmation only;
- file lifetime: transient on VPS; Telegram owns the delivered document copy;
- queue: existing `notifications` queue;
- Excel: not implemented;
- Report Issue v1: existing generic Support route with copy that includes the Order ID;
- order-linked issue intake: deferred to a separate feature.

The implementation is complete after release gate #428. No action-panel text may claim in-chat issue resolution until the deferred issue-intake feature is implemented and approved.
