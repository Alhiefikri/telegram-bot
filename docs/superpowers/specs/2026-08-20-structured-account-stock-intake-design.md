# Structured Account Stock Intake Design

## Context

The admin dashboard currently adds credential-managed stock through one textarea where every non-empty line becomes one `ProductStockItem`. This quick path is efficient for legacy `email|password|MFA`, license keys, and other free-form secrets, but it cannot represent one multi-line account without incorrectly increasing stock for every line.

Customer delivery already has important foundations that must remain intact:

- `ProductStockItem.secret` is encrypted;
- checkout allocates stock items under the existing product/order locks;
- credentials are revealed only for paid eligible Orders;
- Telegram receipt content is rendered in memory and is not persisted in the Telegram Outbound Effect ledger;
- email/password/MFA values recognized by the legacy formatter use Telegram `code` entities;
- Web Storefront credential pages use paid-order access controls, `no-store`, and `no-referrer` behavior;
- delivered or possibly exposed credentials never return to Available stock.

This design adds a structured account path while preserving the legacy path and all existing inventory, payment, access, and durable-delivery invariants.

## Goal

Owner/Admin users can atomically add one or more Structured Account Credentials containing required email/password data, one optional OTP Mail Link or Auth Token, and an optional tutorial override. Store Bot and Web Storefront present the same account grouping after authoritative payment, with copy-friendly secrets and correctly associated OTP actions.

## Domain Contract

Two Stock Intake modes coexist:

- **Quick Input** retains the current contract: every non-empty line is one legacy Product Stock Item and existing rendering remains unchanged.
- **Account Input** creates Structured Account Credentials: one account card is exactly one Product Stock Item regardless of line breaks inside password, token, or tutorial fields.

Account Input requires:

- one syntactically valid email;
- one non-empty password;
- exactly one access-method state: `none`, `otp_mail_link`, or `auth_token`;
- an access value when and only when the selected method requires it;
- an optional tutorial override.

An OTP Mail Link and Auth Token cannot coexist on one Product Stock Item. Product `delivery_note` remains the default tutorial; an item override replaces it only for that item. Product `usage_guide_*` remains a separate shared guide and is never reclassified as OTP access.

A Product may contain legacy and structured Product Stock Items concurrently. No explicit Product stock mode is introduced, and existing rows are not rewritten merely because the new intake path exists.

## Admin Experience

### Entry point and layout

The existing **Stok** action opens a wider responsive drawer/full-screen modal with two tabs:

1. **Input Cepat** — the existing textarea and line-count behavior;
2. **Input Akun** — the new structured account editor.

Input Cepat remains available as the operational fallback. Input Akun starts with one blank account card and supports at most 20 cards per submission so validation, review, request size, and transaction duration remain bounded. Larger free-form batches continue through Input Cepat.

### Account card

Each numbered card contains:

- Email, required, maximum 254 characters;
- Password, required, maximum 1,024 characters;
- segmented access choice: **Tidak ada**, **OTP Mail Link**, or **Auth Token**;
- OTP Mail Link, conditionally required, HTTPS URL, maximum 2,048 characters;
- Auth Token, conditionally required, maximum 3,500 characters;
- tutorial behavior: use Product default, or expose an optional override textarea capped at 2,000 characters;
- remove-card action.

**Tambah akun** appends a blank card. The interface does not duplicate an entire credential card because accidental credential duplication is financially dangerous. The primary action includes the pending count, such as **Review 3 akun**.

### Review and confirmation

Account Input has a mandatory review state before commit. It shows:

- total account count;
- card-scoped validation results without echoing secrets into an error log;
- duplicate status within the submission and against the persistent identity registry;
- OTP destination domain, never a truncated/misleading alternative host;
- whether each account uses the Product tutorial or an override;
- a customer-shaped preview grouped by account number.

The commit action says **Tambah N stok**. Any edit returns the submission to review-required state.

Closing dirty Account Input requires confirmation. Raw credential drafts are held only in page memory. They are not written to local/session storage, query strings, analytics, or server-side validation flash data. Account Input submits through an authenticated JSON request so a validation failure can leave the browser's in-memory form intact while returning only item-indexed safe errors.

### Success and failure

Success redirects/focuses the existing Product return target, reports only the count, and refreshes stock summaries. A failed submission adds nothing and identifies cards by number and field, not by raw email/password/token/link values.

## Persistence

### Product Stock Item representation

Extend `product_stock_items` additively:

- `credential_format`, default `legacy`, allowed values `legacy` and `structured_account`;
- nullable encrypted-array `credential_payload` for structured data;
- retain encrypted `secret` for legacy rows and make it nullable only when a valid structured payload is present.

Structured payload version 1 contains:

- normalized display email;
- password;
- access method;
- nullable access value;
- nullable tutorial override;
- payload version.

Payload validation is owned by the Stock Intake module, not Eloquent casts, controllers, views, or delivery adapters.

### Credential identity registry

Create a persistent credential identity registry containing only keyed fingerprints and safe references/metadata. It never contains plaintext credentials. At minimum it stores:

- identity kind (`email`, and `auth_token` when present);
- HMAC fingerprint using a dedicated application key;
- first-seen Product/stock-item references where safe;
- timestamps;
- a unique constraint on identity kind plus fingerprint.

Email normalization is trim plus lowercase only. Do not apply provider-specific dot/plus transformations that could merge distinct mailboxes. Passwords are never fingerprinted. OTP Mail URLs may be shared and are not identities. Auth Tokens receive exact-value fingerprints so the same token cannot be assigned to different emails.

Registry rows survive Product Stock Item deletion. Therefore an identity that entered the registry cannot be re-added with a changed password or after deletion/retirement. Available structured items should be edited instead of deleted-and-recreated when correcting a typo.

### Legacy backfill limitation

Before enabling Account Input, run an application-level backfill that decrypts existing Product Stock Items in bounded chunks, extracts only confidently recognized emails/tokens, and populates the registry. It reports conflicts using row IDs and counts, never plaintext.

Credentials hard-deleted before this registry exists cannot be reconstructed because the current audit/log design correctly did not retain their plaintext. The rollout guarantee is therefore:

- all confidently parseable Product Stock Items still present at rollout are registered;
- every future structured identity is registered atomically;
- future deletion does not remove identity history;
- no claim is made about unrecoverable rows deleted before rollout.

Production enablement stops if the backfill finds unresolved duplicate identities among retained rows.

## Stock Intake Module and Interface

Add a deep `ProductStockIntake` module at the positive-stock-adjustment seam. Its small interface accepts a Product, one idempotent submission containing legacy or structured items, the actor, and request context; it returns a safe result containing Product/movement IDs, counts, and availability before/after.

The module owns:

- mode-specific normalization and validation;
- batch limits;
- email/token fingerprinting;
- duplicate checks and unique-conflict mapping;
- idempotency;
- Product row locking;
- Product Stock Item creation;
- numeric stock increment;
- one matching InventoryMovement;
- safe audit metadata;
- stock-notification materialization/dispatch handoff after commit.

Controllers and adapters never directly increment `Product.stock`, create stock rows, or write fingerprints.

Every submission carries a client-generated random UUID. A persisted unique operation key makes browser double-click, timeout retry, and duplicate POST return the original safe result without adding stock or notifications twice.

Inside one database transaction the implementation:

1. claims or reuses the idempotency key;
2. locks the Product;
3. validates all normalized items;
4. claims every credential identity through unique registry rows;
5. creates every Product Stock Item;
6. increments Product stock by exactly the created item count;
7. creates one adjustment InventoryMovement with the same quantity;
8. writes one safe audit event;
9. commits all changes together.

No partial success is possible. Restock effects are materialized transactionally where required by the existing pipeline and dispatched only after commit. Credential values never enter InventoryMovement notes, audit values, notification payloads, dogfood timing, exception messages, or job serialization.

## Inventory and Credential Lifecycle

Existing allocation and lifecycle semantics remain authoritative:

- Available Product Stock Items may be edited or removed according to policy.
- Checkout locks and allocates an Available item before reservation commits.
- Reserved items are immutable.
- A valid pre-exposure cancellation/expiry releases the reservation and may make the item Available/editable again.
- Paid items remain permanently associated with their OrderItem and are never reassigned.
- Delivered or Ambiguous Telegram Delivery credentials are potentially exposed and never return to Available stock.
- Refund after exposure retires rather than restocks the credential.
- A paid credential correction requires an explicit fulfillment/review workflow; this feature does not mutate historical paid credentials.

The Stock Intake change must not alter Order/payment state machines, allocation ordering, release/retirement rules, `availableStock()`, or InventoryLedger ownership of reservation/sale/release.

## Credential Delivery Presentation Module

Add one in-memory `CredentialDeliveryPresentation` module used by both Store Bot and Web Storefront. Its interface accepts an eligible paid Order and returns ordered Product/account groups with:

- account number;
- email;
- password;
- optional access method/value;
- effective tutorial;
- Product usage guide metadata.

The module rechecks paid/fulfillment eligibility, loads allocated stock items, handles legacy formatting through the existing `CredentialDeliveryFormatter`, resolves Product-default versus item tutorial, and returns no credential data for an ineligible Order. Its DTOs must never be cached, queued, logged, or persisted to the outbound-effect ledger.

This is a real seam: Telegram and Web are separate adapters over the same presentation contract. Neither adapter parses encrypted payloads or decides lifecycle eligibility.

## Store Bot Experience

Each Structured Account Credential is rendered as an indivisible numbered account group:

```text
Account #1

Email
user@example.com

Password
secret-password
```

Email, password, and Auth Token values use Telegram `code` entities so supported Telegram clients display monospace copy affordances without separate copy buttons.

For OTP Mail Link:

- show the validated destination domain in text;
- attach one inline button labelled **Buka OTP Mail #N** to the matching account delivery unit;
- never print the raw URL or place tokens in callback data.

For Auth Token:

- show an **Auth Token Outlook/Hotmail** label;
- render the full token as one `code` entity;
- never convert it to a URL or button.

Tutorial is escaped/plain Telegram text. If an Auth Token would exceed the safe account-message budget, it is sent as its own ordered credential unit, still atomically represented by one code entity. Field and tutorial limits must ensure no single value exceeds Telegram's message limit.

Receipt delivery remains owned by the existing durable receipt family. Account messages/buttons participate in its ordered sequence. An ambiguous send enters the existing Ambiguous Telegram Delivery/Manual Delivery Review behavior and is not blindly resent. Rendered credentials and OTP URLs are generated only at send time and are absent from durable effect state.

## Web Storefront Experience

The paid-order credential surface renders one card per account with the same ordering and effective tutorial as Store Bot:

- visible monospace email/password/token values;
- the entire value area is keyboard/click actionable for copy;
- brief non-secret `Tersalin` feedback;
- one **Buka OTP Mail** action for OTP access;
- existing Product guide and copy-all behavior adapted to structured groups.

OTP links use `rel="noreferrer noopener"`, do not become referrers, and are never loaded by the server. Existing paid-state, customer ownership/Recovery Key, rate limiting, `Cache-Control: no-store, private`, and `Referrer-Policy: no-referrer` controls remain mandatory.

## Manage Stock

The existing stock-management view supports both formats:

- legacy Available rows retain the current textarea editor;
- structured Available rows use the account fields and conditional access editor;
- Reserved, Paid, retired, and repair-required rows are read-only;
- no bulk reveal/export action is added;
- audit records indicate a safe structured-field change without old/new credential values.

Editing an Available structured email/token updates the identity registry atomically. The old fingerprint remains as historical no-reuse evidence; the new fingerprint must be globally unclaimed. Editing password/tutorial alone does not change identity rows.

## URL and Input Safety

OTP Mail validation requires:

- absolute HTTPS URL;
- non-empty valid hostname;
- no username/password userinfo;
- no `javascript:`, `data:`, `file:`, protocol-relative, or malformed targets;
- normalized display domain derived by the server, never trusted from the client.

Query strings/fragments may be retained because provider OTP links can contain opaque access material. The application never fetches, expands, previews, or follows the URL server-side. Owner/Admin are trusted to choose the provider destination, while the visible domain lets the customer inspect where the button leads.

## Authorization and Sensitive-Data Rules

Existing Product stock policy remains authoritative: only Owner/Admin may intake or edit stock. Finance/Support remain read-only.

Credential plaintext must never appear in:

- AuditLog old/new values;
- InventoryMovement notes;
- application/request/error logs;
- validation errors;
- queue serialization;
- Telegram Outbound Effect content/metadata;
- analytics/dogfood timing;
- URL/query parameters;
- browser local/session storage;
- documentation, screenshots, or test failure artifacts.

Safe operational metadata is limited to Product/stock-item/movement IDs, format, item count, actor, timestamps, access-method kind, and redacted error codes. Tests use unmistakably fake credentials and must not dump decrypted payloads on failure.

## Failure Behavior

- Any invalid item rejects the entire submission and preserves server state.
- A duplicate in the same submission identifies both item numbers without printing the identity.
- A registry conflict from another concurrent intake maps to a safe duplicate error and rolls back stock/items/movement/audit.
- A duplicate idempotency key returns the first committed result; a key reused with a different Product or payload fails closed.
- Audit or movement failure rolls back identity claims and stock items.
- Post-commit notification failure does not roll back stock; the existing durable notification recovery owns delivery.
- A stale admin dashboard can add stock safely because Product locking and duplicate constraints are authoritative.
- A credential delivery ambiguity does not reallocate, edit, release, or automatically resend the account.

## Acceptance Criteria

1. Owner/Admin can still use Input Cepat with identical line-count, encryption, stock, audit, and notification behavior.
2. Account Input creates exactly one Product Stock Item per account card regardless of multiline fields.
3. Email/password are required; access is none, one OTP Mail Link, or one Auth Token; tutorial override is optional.
4. Review is mandatory and commit is all-or-nothing.
5. Double-submit/retry creates one intake, one stock delta, one InventoryMovement, one audit event, and one restock-notification transition.
6. Duplicate email and Auth Token detection works within a submission, against Available/Reserved/Paid/retired/deleted registered identities, and under concurrent PostgreSQL requests.
7. Numeric stock, stock-item count, reservation state, and `availableStock()` remain consistent after success and every failure.
8. Available structured items can be edited safely; Reserved/Paid/exposed items cannot.
9. Paid Store Bot delivery groups every account, applies code entities to copyable values, associates OTP button #N with account #N, and never persists rendered secrets.
10. Ambiguous Telegram credential delivery follows Manual Delivery Review and never blind-resends or restocks.
11. Paid Web Storefront/Recovery Key delivery shows the same ordered data, click-to-copy values, OTP action, effective tutorial, and existing private/no-store headers.
12. Pending, cancelled, expired, unpaid, unauthorized, or foreign-customer access reveals no credential payload or OTP URL on either channel.
13. Logs, audits, movements, jobs, effects, validation responses, browser storage, docs, and artifacts contain no credential plaintext.
14. Rollout backfill registers every confidently parseable retained legacy identity, reports only safe conflicts, and blocks enablement on unresolved conflicts.
15. Responsive browser coverage proves both intake modes, 1/20-card review, validation focus, dirty-close confirmation, and customer copy/link controls at supported widths.

## Rollout and Rollback

1. Deploy additive schema, registry, and code with Account Input disabled.
2. Run registry backfill in bounded chunks and resolve duplicate row IDs without exposing plaintext in artifacts.
3. Run focused/full test gates and PostgreSQL concurrency proof.
4. Enable Account Input for Owner/Admin while Input Cepat remains available.
5. Add one controlled structured account in a non-production environment; verify admin review, stock movement, checkout allocation/release, paid Telegram delivery, Web Recovery Key delivery, and no secret persistence.
6. Enable production UI only after the controlled proof passes.

Operational rollback disables Account Input while preserving structured rows and their delivery support. Existing structured credentials must remain allocatable/deliverable after UI rollback. Schema and registry stay in place; deleting them would break no-reuse history and is not a safe rollback.

## Non-Goals

- Removing or reinterpreting legacy Quick Input.
- CSV/file import.
- Supplier/procurement management.
- Provider login checks, OTP mailbox fetching, or token verification.
- Multiple access methods on one account.
- Customer editing of credentials.
- Bulk credential export/reveal.
- Reusing deleted, paid, refunded, or retired identities.
- Migrating every ambiguous legacy free-form row into structured fields.
- Changing checkout/payment/provider state machines.
- Automatically retrying ambiguous credential delivery.

## Documentation Decision

No ADR is required. The design is additive, preserves the legacy path, and follows existing inventory and Telegram Outbound Effect decisions. Canonical domain terms are recorded in `CONTEXT.md`; implementation ownership and test entry points will be added to `docs/agents/codebase.md` when the feature is implemented.
