# Structured Account Stock Intake Implementation Plan

> Implement blockers-first with red-green-refactor. Do not place credential plaintext in test output, logs, snapshots, artifacts, commits, or issue text. Run project-wide format/build/concurrency/browser/review gates only after the focused tickets are green.

**Goal:** Add an atomic Structured Account Credential intake and consistent paid delivery across Store Bot/Web while preserving legacy Quick Input and every existing inventory/payment/durable-delivery invariant.

**Architecture:** A deep `ProductStockIntake` module owns validation, identity registry, idempotency, Product locking, stock-item creation, matching movement, safe audit, and notification handoff. A separate in-memory `CredentialDeliveryPresentation` module rechecks paid eligibility and supplies one shared account-group contract to Telegram and Web adapters. Legacy formatting remains an internal compatibility path.

**Stack:** PHP 8.3, Laravel 13, Eloquent encrypted casts, Blade, Alpine, Vite, PostgreSQL concurrency gate, SQLite feature tests, PHPUnit 12, Playwright.

**Design source:** [`../specs/2026-08-20-structured-account-stock-intake-design.md`](../specs/2026-08-20-structured-account-stock-intake-design.md)

## Global Constraints

- Input Cepat remains behaviorally compatible.
- One structured account card creates exactly one Product Stock Item.
- Account Input is disabled until retained legacy identity backfill succeeds.
- Credential plaintext never enters logs, audit values, movement notes, queue/effect persistence, validation output, browser storage, or artifacts.
- Every intake is idempotent and all-or-nothing.
- Product lock plus unique identity constraints are authoritative under concurrency.
- InventoryLedger continues to own reservation/sale/release; checkout/lifecycle state machines do not move.
- Reserved/Paid/exposed credentials are immutable and never restocked.
- Telegram/Web adapters never parse persistence payloads or independently decide paid eligibility.
- Ambiguous Telegram delivery remains Manual Delivery Review, never blind retry.

## Dependency Graph

```text
SA-1 Data + identity registry
  ├── SA-2 ProductStockIntake module + HTTP contract
  │     └── SA-3 Admin Input Cepat/Input Akun UX
  └── SA-4 CredentialDeliveryPresentation
        ├── SA-5 Store Bot structured delivery
        └── SA-6 Web Storefront structured delivery

SA-2 + SA-3 + SA-5 + SA-6
  └── SA-7 Concurrency, security proof, rollout, docs
```

## SA-1 — Additive Credential Persistence and Identity Registry

**Outcome:** Existing legacy rows remain readable, structured payloads can be stored encrypted, and future identity reuse is prevented by a plaintext-free persistent registry.

**Files:**

- Create: `src/app/Enums/CredentialFormat.php`
- Create: `src/app/Enums/CredentialAccessMethod.php`
- Create: migration adding structured fields to `product_stock_items`
- Create: migration creating the credential identity registry and unique constraints
- Create: `src/app/Models/CredentialIdentity.php`
- Modify: `src/app/Models/ProductStockItem.php`
- Create: structured credential value/normalization classes under `src/app/Services/Credentials/`
- Create: `src/app/Console/Commands/BackfillCredentialIdentityRegistry.php`
- Add focused migration/model/parser/command tests under `src/tests/Unit` and `src/tests/Feature`

### Red

- [ ] Prove all existing legacy rows decrypt/render unchanged after migration.
- [ ] Prove structured payload requires version/email/password/access consistency and is encrypted at rest.
- [ ] Prove email normalization is trim+lowercase only.
- [ ] Prove registry uniqueness is global by identity kind and survives stock-item deletion.
- [ ] Prove password and OTP URL are not identity fingerprints; exact Auth Token is.
- [ ] Prove backfill recognizes only confident retained legacy identities, is resumable/idempotent, and reports row IDs/counts without plaintext.
- [ ] Prove unresolved retained duplicates produce a non-zero command result and no production-ready signal.

### Green

- [ ] Add enum-backed format/access casts and encrypted-array payload cast while retaining encrypted legacy `secret`.
- [ ] Add dedicated HMAC key configuration/readiness validation without logging key/value material.
- [ ] Implement normalization/fingerprinting in one credential module.
- [ ] Implement bounded backfill using row IDs/cursors; never write decrypted values to output.
- [ ] Document the unrecoverable pre-registry hard-deletion limitation in command help/runbook output.

### Refactor and check

- [ ] Ensure invalid format/payload combinations fail before model save through the owning module, with database constraints for coarse nullability/allowed values where portable.
- [ ] Inspect database rows and command output to verify encrypted payload/no plaintext.
- [ ] Run focused tests and `git diff --check`.

**Suggested commit:** `feat: add structured credential identity foundation`

---

## SA-2 — Build the Atomic ProductStockIntake Module

**Blocked by:** SA-1

**Outcome:** Both legacy and structured positive stock additions use one idempotent transactional interface and cannot diverge from numeric stock, movement, audit, or notification state.

**Files:**

- Create: `src/app/Services/ProductStockIntake.php`
- Create: safe intake submission/result/value objects
- Modify or narrow: `src/app/Services/StockAdjustmentService.php`
- Modify: `src/app/Http/Requests/Admin/StoreProductStockRequest.php`
- Create: dedicated structured Account Input request if this keeps validation/error contracts smaller
- Modify: `src/app/Http/Controllers/Admin/AdminProductStockController.php`
- Modify: `src/app/Services/Admin/AdminProductDashboardFormProtocol.php`
- Modify: `src/routes/web.php` only if a dedicated JSON route is cleaner than content negotiation
- Modify: `src/tests/Feature/AdminDashboardStockFlowsTest.php`
- Modify: `src/tests/Feature/AdminCatalogPolicyAndStockAuditTest.php`
- Add focused module/idempotency/duplicate tests

### Red

- [ ] Preserve every current Input Cepat assertion, including blank-line behavior and encrypted legacy values.
- [ ] Add structured success for 1 and 20 items with exactly matching Product stock, ProductStockItem count, one InventoryMovement quantity, one safe audit, and one transition notification.
- [ ] Reject 0/21 items, invalid email/password/access/tutorial limits, OTP non-HTTPS/userinfo URLs, and inconsistent access values with zero writes.
- [ ] Reject duplicate normalized emails and Auth Tokens inside one submission without returning raw identities.
- [ ] Reject any registry conflict against available/reserved/paid/retired/deleted registered history.
- [ ] Prove one invalid item rolls back every identity/item/stock/movement/audit/effect.
- [ ] Prove same operation key/same payload returns the original result; same key/different product or payload fails closed.
- [ ] Prove controller/request/auth failures never flash raw credentials to session.
- [ ] Prove Owner/Admin allowed and Finance/Support forbidden.

### Green

- [ ] Give `ProductStockIntake` one public mutation interface and keep controllers as adapters.
- [ ] Normalize/validate entire submission before transaction, then repeat authoritative identity claims under Product lock/unique constraints.
- [ ] Persist a unique operation key or equivalent durable idempotency record coupled to the successful InventoryMovement.
- [ ] Create all stock rows, numeric delta, movement, audit, and transactional notification materialization together.
- [ ] Dispatch recoverable restock delivery only after commit using the existing queue/pipeline.
- [ ] Return safe JSON item-index errors for Account Input and preserve dashboard redirect behavior for Input Cepat.

### Refactor and check

- [ ] Apply the deletion test: removing `ProductStockIntake` would force validation/locking/idempotency/movement/audit rules into controllers; if not, deepen the module.
- [ ] Confirm `StockAdjustmentService` still owns any legitimate non-intake adjustment without duplicating positive credential intake.
- [ ] Run focused tests and inspect logs/audit/movement rows for secret absence.

**Suggested commit:** `feat: add atomic product stock intake`

---

## SA-3 — Add Responsive Account Input and Structured Stock Management

**Blocked by:** SA-2

**Outcome:** Owner/Admin can use the unchanged quick path or a safe 1–20 account editor with mandatory review; available structured rows can be corrected without exposing bulk secrets.

**Files:**

- Modify: `src/resources/views/admin/dashboard/stock-modals.blade.php`
- Modify: `src/resources/js/admin-dashboard.js`
- Modify: `src/app/Services/Admin/AdminDashboardProductsData.php`
- Modify: `src/app/Services/Admin/AdminProductDashboardState.php`
- Modify: `src/app/Http/Requests/Admin/UpdateProductStockItemRequest.php`
- Modify: `src/app/Http/Controllers/Admin/AdminProductStockItemUpdateController.php`
- Modify: `src/app/Services/ProductStockItemManager.php`
- Modify: `src/tests/Feature/AdminDashboardStockFlowsTest.php`
- Modify: `src/tests/Browser/admin-dashboard-responsive.spec.js`

### Red

- [ ] Input Cepat textarea, count, validation bag, and submission remain available and compatible.
- [ ] Input Akun opens with one blank card, supports add/remove through 20, and never derives item count from internal line breaks.
- [ ] Access selection conditionally exposes exactly one value field and clears stale inactive values.
- [ ] Tutorial defaults to Product delivery note with explicit override control.
- [ ] Any edit invalidates prior review; commit is unavailable until review passes.
- [ ] Review groups by account number, shows safe domain/tutorial source, and does not write secrets to DOM attributes, URLs, storage, or telemetry.
- [ ] Dirty close asks for confirmation; successful commit restores Product return target and count-only status.
- [ ] Structured Available edit performs identity conflict checks; Reserved/Paid/retired rows expose no mutation action.
- [ ] Keyboard/focus/error behavior works at 320, 390, 768, 1280, and 1920px.

### Green

- [ ] Reuse the existing Stok entry point and modal conventions, upgrading Account Input to a wide drawer/full-screen layout without regressing Input Cepat.
- [ ] Keep raw form state in Alpine memory only and submit Account Input through authenticated JSON.
- [ ] Render review from normalized client data for UX, but treat server validation as authoritative.
- [ ] Show validation by account number/field and never echo rejected values in server error copy.
- [ ] Extend Manage Stock payloads with format-specific safe shape; do not add bulk reveal/export.

### Refactor and check

- [ ] Extract reusable account-card/review UI behavior without exposing credential state globally.
- [ ] Run focused feature/browser tests and `npm run build`.
- [ ] Manually inspect narrow mobile scrolling, sticky actions, password-manager autofill behavior, and dirty-close focus restoration.

**Suggested commit:** `feat: add structured account stock editor`

---

## SA-4 — Build Shared Paid Credential Delivery Presentation

**Blocked by:** SA-1

**Outcome:** One in-memory module owns paid eligibility, legacy/structured interpretation, account grouping, effective tutorials, and safe delivery DTOs for both customer adapters.

**Files:**

- Create: `src/app/Services/Credentials/CredentialDeliveryPresentation.php`
- Create: in-memory delivery DTO/value classes
- Modify: `src/app/Services/CredentialDeliveryFormatter.php` only for legacy/internal reuse
- Modify: `src/app/Services/ProductUsageGuide.php` only if shared projection needs a narrower helper
- Add unit tests for eligibility, grouping, formats, tutorial resolution, and absence of serialization/logging

### Red

- [ ] Pending/cancelled/expired/unpaid/foreign access returns no credential presentation.
- [ ] Paid eligible Orders preserve Product and ProductStockItem ordering and number accounts deterministically.
- [ ] Legacy credentials produce the current text/parts behavior.
- [ ] Structured credentials produce email/password, none/OTP/token access, effective tutorial, and usage guide without raw persistence leakage.
- [ ] Mixed legacy/structured ProductStockItems render correctly in one Order.
- [ ] Product default tutorial and item override resolve exactly as designed.
- [ ] Malformed persisted structured payload fails closed and yields a safe operational condition, never partial credential output.

### Green

- [ ] Implement one public paid-order presentation interface with adapters unable to bypass eligibility accidentally.
- [ ] Keep all secret-bearing DTOs in memory and non-serializable by convention/design.
- [ ] Keep legacy parser behind the module rather than duplicating it in Telegram/Web.

### Refactor and check

- [ ] Apply the two-adapter seam test: both Store Bot and Web must consume this interface before calling it complete.
- [ ] Ensure test failure messages compare safe shapes/counts and do not dump secret-bearing DTOs.

**Suggested commit:** `feat: centralize paid credential presentation`

---

## SA-5 — Deliver Structured Credentials through Durable Store Bot Receipts

**Blocked by:** SA-4

**Outcome:** Paid Telegram customers receive correctly grouped copy-friendly accounts and item-matched OTP buttons without weakening ordered durable delivery or persisting rendered credentials.

**Files:**

- Modify: `src/app/Services/Telegram/TelegramReceiptNotifier.php`
- Modify: `src/app/Services/Telegram/TelegramOrderIntents.php`
- Modify: `src/app/Services/Telegram/ReceiptDeliveryPipeline.php` only if account-unit reply markup needs explicit support
- Modify: `src/app/Services/Telegram/TelegramMessageChunker.php` only through a tested atomic-unit interface, not ad hoc string splitting
- Modify: `src/tests/Unit/Services/CredentialDeliveryFormatterTest.php`
- Modify/add Telegram receipt, order-detail, outbound-effect, ambiguity, and chunking tests

### Red

- [ ] Email/password/Auth Token have correct UTF-16 `code` entities and no copy buttons.
- [ ] OTP account #N displays safe domain and has one **Buka OTP Mail #N** URL button attached to its delivery unit.
- [ ] Auth Token is text only and never appears in reply markup/callback data.
- [ ] Multiple accounts cannot cross-associate values, tutorials, buttons, or sequence numbers.
- [ ] Long token remains one code entity in a bounded ordered unit and is never split/truncated silently.
- [ ] Legacy receipt/order-detail output remains compatible.
- [ ] Effect rows, job payloads, exception logs, ambiguity metadata, and review pages contain no credential/link plaintext.
- [ ] Ambiguous account send enters Manual Delivery Review and does not blind-resend, reallocate, or restock.

### Green

- [ ] Replace notifier/order-detail parsing with `CredentialDeliveryPresentation`.
- [ ] Render account groups into explicit text+entities+reply-markup delivery units before the existing ledger send/finalize seam.
- [ ] Keep claim/finalization/recovery exclusively in `TelegramOutboundEffectLedgerProtocol` and the receipt pipeline.
- [ ] Preserve Product guides/support buttons without attaching account OTP actions to the wrong final chunk.

### Refactor and check

- [ ] Verify UTF-16 offsets with Unicode labels/tutorials.
- [ ] Run receipt/outbound focused tests including definite retryable failure and ambiguous transport failure.
- [ ] Inspect serialized jobs/effects and test artifacts for absence of credential values.

**Suggested commit:** `feat: deliver structured accounts in store bot`

---

## SA-6 — Present Structured Credentials in Web Storefront and Recovery Access

**Blocked by:** SA-4

**Outcome:** Paid Web/Recovery Key/customer-account surfaces show the same grouped accounts with click-to-copy values and safe OTP actions under existing access/security headers.

**Files:**

- Modify: `src/app/Storefront/StorefrontShopSignedInvoice.php`
- Modify: `src/app/Storefront/StorefrontShopSignedInvoiceReadResult.php`
- Modify: `src/app/Storefront/StorefrontShopOrderPresenter.php`
- Modify: `src/resources/views/storefront/partials/shop/account-delivery.blade.php`
- Modify: `src/resources/js/storefront.js`
- Modify: relevant Storefront/CustomerAccount/Recovery Key feature tests
- Modify: relevant browser tests for account delivery

### Red

- [ ] Paid eligible signed invoice, Recovery Key, and owned customer Order render identical ordered structured account data.
- [ ] Unpaid/terminal/foreign/invalid access responses contain no credential/OTP values.
- [ ] Email/password/token value regions are focusable/clickable copy targets with accessible feedback.
- [ ] OTP action uses HTTPS target, visible domain context, and `noreferrer noopener`; Auth Token is never a link.
- [ ] Tutorial default/override and Product usage guide remain distinct.
- [ ] Mixed legacy/structured orders and copy-all behavior remain correct.
- [ ] Responses retain private/no-store/no-referrer headers and sensitive pages do not enter response cache.

### Green

- [ ] Consume `CredentialDeliveryPresentation` rather than formatting stock rows inside the invoice builder.
- [ ] Keep Blade escaped and add only safe URL attributes from validated presentation data.
- [ ] Extend existing copy behavior without placing secret values in analytics events or persistent browser storage.

### Refactor and check

- [ ] Run focused Storefront/CustomerAccount/Recovery Key tests and browser coverage.
- [ ] Verify keyboard activation, screen-reader labels, mobile overflow for long tokens, and copy feedback.
- [ ] Run `npm run build`.

**Suggested commit:** `feat: present structured accounts on storefront`

---

## SA-7 — PostgreSQL Race Proof, Security Closure, Docs, and Rollout

**Blocked by:** SA-2, SA-3, SA-5, SA-6

**Outcome:** Financial/credential safety holds under real concurrency and retries, no sensitive persistence leaks exist, both UIs are verified, and production enablement has a reversible additive rollout.

**Files:**

- Add PostgreSQL process-concurrency tests for intake/intake and intake/checkout races
- Modify `scripts/concurrency-deploy-gate.sh` only if discovery needs extension
- Modify: `docs/agents/codebase.md`
- Create: a concise structured-stock rollout/backfill runbook if command help is insufficient
- Correct design/plan only for discoveries that change the agreed contract

### Concurrency proof

- [ ] Same idempotency key racing in two processes commits one result.
- [ ] Different keys with duplicate explicit Auth Token commit at most one intake; duplicate email may commit independently; the Auth Token loser has zero partial side effects.
- [ ] Intake racing checkout obeys Product/stock-item locks and ends with matching numeric/credential availability.
- [ ] Structured Available edit racing checkout either commits before allocation or fails after allocation; no reserved item changes.
- [ ] Delete racing checkout preserves stock/reservation invariants and identity registry history.

### Security proof

- [ ] Search database audit/movement/effect/job tables, logs, rendered error pages, session payloads, and artifacts for seeded canary credentials; find none outside encrypted credential columns and intended customer response memory.
- [ ] Prove OTP URL is never fetched server-side and unsafe schemes/userinfo fail closed.
- [ ] Prove malformed encrypted payload/fingerprint-key misconfiguration disables structured intake/delivery safely without exposing ciphertext/plaintext.
- [ ] Prove Ambiguous Telegram Delivery remains operator-reviewed and potentially exposed credentials never return to stock.

### Full verification

- [ ] Run focused suites from every ticket.
- [ ] Run `cd src && composer test`.
- [ ] Run `cd src && composer test:foundation`.
- [ ] Run `cd src && composer test:concurrency-gate` with writable artifacts and PostgreSQL driver available.
- [ ] Run `cd src && npm run build`.
- [ ] Run responsive Playwright admin/storefront suites.
- [ ] Run two-axis Standards + Spec code review against the implementation base.

### Rollout

- [ ] Deploy additive schema/code with Account Input disabled.
- [ ] Run backfill dry-run then commit mode; retain only counts/row IDs in evidence.
- [ ] Resolve every retained duplicate conflict before enablement.
- [ ] Exercise controlled non-production add/edit/reserve/release/paid Telegram/Web flows.
- [ ] Enable Account Input while preserving Input Cepat.
- [ ] Record safe counts, commands, test results, and rollback switch only—no credentials/screenshots of credential content.

### Documentation closure

- [ ] Add `ProductStockIntake`, identity registry, `CredentialDeliveryPresentation`, admin routes, delivery ownership, and test entry points to `docs/agents/codebase.md`.
- [ ] Confirm `CONTEXT.md` remains implementation-free.
- [ ] Update this design before code if any rollout limitation changes the approved domain contract.

**Suggested commit:** `docs: close structured stock intake rollout`

## Final Done Criteria

- All seven tickets are complete in dependency order.
- Input Cepat remains compatible and available.
- Account Input is atomic, idempotent, duplicate-safe, responsive, and authorized.
- Store Bot and Web present the same paid credential semantics.
- No credential plaintext leaks outside encrypted persistence and intended customer rendering.
- Concurrency, ambiguity, retirement, and rollback evidence satisfy the design acceptance criteria.
