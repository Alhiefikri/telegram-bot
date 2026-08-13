# KlikQRIS Official Webhook Contract Alignment

**Date:** 2026-08-12
**Status:** Proposed
**Scope:** Align KlikQRIS production configuration, transaction creation, and webhook verification with the provider's published API documentation.
**Primary source:** https://klikqris.com/dokumentasi-api, read on 2026-08-12.

## Problem

The production integration currently assumes a shared webhook secret and an HMAC-SHA256 signature delivered through `X-KLIKQRIS-SIGNATURE` or `X-Signature`. The published KlikQRIS API documentation does not define that contract.

KlikQRIS documents only these merchant credentials:

- `x-api-key` request header;
- `id_merchant` request header and create body field;
- production API base URL `https://klikqris.com/api`.

For webhook verification, KlikQRIS documents a transaction `signature` in the create response and a `signature` field in the callback JSON body. The receiver must compare the callback signature with the signature returned by the original create request. No webhook shared secret or HMAC header is documented.

The current implementation therefore cannot pass production readiness using only the documented provider credentials, and a real documented callback would be rejected before authoritative status verification.

## Provider Contract: Documented Facts

The implementation MUST treat only the following statements as provider facts unless the official documentation changes:

1. `POST /qris/create` is authenticated with `x-api-key` and `id_merchant` headers.
2. The create JSON body requires `order_id`, `amount`, and `id_merchant`; `keterangan` and `callback_url` are optional.
3. If `callback_url` is omitted, KlikQRIS uses the global webhook URL configured in its Settings page.
4. A successful create response includes a transaction `signature` inside `data`.
5. A callback is JSON and includes `order_id`, `status`, `amount`, `total_amount`, and `signature`.
6. The documented callback statuses include paid and expired outcomes; the published example uses `PAID`, while the status endpoint example uses `SUCCESS` for a paid transaction.
7. KlikQRIS instructs the merchant to compare the callback body signature with the signature returned by create.
8. A callback endpoint must return HTTP 200 for an accepted callback; otherwise KlikQRIS may retry delivery.
9. `GET /qris/status/{order_id}` is authenticated with `x-api-key` and `id_merchant` and returns the authoritative transaction state.
10. `total_amount`, not the requested subtotal alone, is the final amount presented to the customer.

The implementation MUST NOT invent a provider webhook secret, HMAC algorithm, signature header, alternate endpoint, or undocumented credential.

## Project Decisions

These are project security and lifecycle decisions, not claims about KlikQRIS behavior:

1. **Authoritative status remains the paid authority.** A matching callback signature only authorizes the callback as a trigger. `KlikQrisCallbackProcessor` must still fetch `GET /qris/status/{order_id}`, match provider identity and amounts, and use `OrderLifecycle` before a Payment can become paid.
2. **Persist the create signature in a dedicated encrypted Payment field.** It must never be placed in `provider_payload`, `PaymentEvent.raw_payload`, audit metadata, application logs, exceptions, admin output, customer output, test snapshots, or issue evidence. A one-way hash is insufficient because the provider contract requires equality with a later opaque value, but encrypted-at-rest storage permits the exact constant-time comparison and existing recovery behavior.
3. **Send the configured callback URL per create request.** Production readiness already requires an exact HTTPS callback URL ending in `/api/webhook/klikqris`. Supplying it in each create request avoids dependence on mutable provider-dashboard state. Sandbox sends its configured callback URL only when nonblank.
4. **Validate before outbound status traffic.** A production callback must first resolve a KlikQRIS Payment by `provider + merchant_ref`, then require a nonblank stored create signature and a constant-time match with the nonblank callback body signature. Missing or mismatched signatures return HTTP 401, create no PaymentEvent, perform no status API call, and cause no lifecycle transition. This is the owner's selected behavior; the provider documentation does not prescribe the invalid-signature status code.
5. **Legacy/in-flight Payments without a stored signature are status-only.** Their callbacks fail authentication and cannot trigger status traffic. Scheduled reconciliation and explicit admin reconciliation remain able to fetch authoritative status and finish the existing Payment. They are never replaced, migrated to another provider, or automatically fulfilled from callback data.
6. **Recovery may persist a signature only from an authoritative provider response.** When status recovery supplies a signature for an existing KlikQRIS Payment, the same encrypted field may be populated if blank. It must never be overwritten with a different value. A mismatch is an invalid provider result requiring safe observability, not silent rotation.
7. **Remove the undocumented configuration.** `KLIKQRIS_PRODUCTION_WEBHOOK_SECRET` and its sandbox equivalent are removed from runtime config, example env, Docker environment forwarding, readiness requirements, fixtures, tests, and operator guidance. No compatibility alias remains.
8. **Keep environment isolation.** Production still requires `APP_ENV=production`, `KLIKQRIS_PRODUCTION_ENABLED=true`, production base URL, API key, merchant ID, exact HTTPS callback URL, API-host allowlist, callback-host allowlist, asynchronous queue, schema, and reconciliation schedule. Sandbox and production endpoint and credential separation remains fail-closed.
9. **Do not log signatures.** Existing recursive sanitization of `signature` keys remains. Authentication comparisons occur before sanitized event logging.
10. **Payment Provider Switch is unchanged.** The owner still activates KlikQRIS only after readiness passes. Existing Payments remain bound to their stored provider, and KlikQRIS never falls back automatically to Manual or Pakasir.

## Data Model

Add one nullable encrypted-at-rest field owned by `Payment` for the opaque KlikQRIS create signature.

Properties:

- nullable for non-KlikQRIS and pre-migration Payments;
- mass-assignable only through the existing Payment model boundary;
- encrypted cast at the model boundary;
- excluded from serialization and presentation surfaces;
- never copied into provider payload or payment events;
- written after a validated create/status response under the existing Payment lock;
- write-once: the same value is idempotent; a different later value is rejected and reported safely.

The migration must be additive and deployable before any production callback contract is enabled.

## Create Flow

`KlikQrisClient::createTransaction()` must continue to:

- authenticate using the documented headers;
- send `order_id`, `id_merchant`, integer `amount`, and `keterangan`;
- validate response identity, amount, `total_amount`, status, media, and expiry through the existing transaction parser;
- avoid retrying an uncertain create;
- sanitize provider payload persistence.

It must additionally send the environment-specific `callback_url` when configured. A production request cannot be considered ready without that configured URL.

`KlikQrisNativeQrisService` must persist the validated signature and transaction state atomically under its existing Payment lock. A response without a nonblank signature cannot produce a production `Payable` outcome because the documented callback-authentication evidence would be absent. Sandbox behavior should use the same transaction invariant when a signature is present; tests must not introduce a second production-only transaction model.

## Callback Flow

The production callback flow is:

1. Apply existing content type, payload size, duplicate-critical-key, JSON-depth, and shape validation.
2. Parse `order_id`, `amount`, `total_amount`, `status`, and `signature` without persisting raw secrets.
3. Resolve the Payment using both provider `klikqris` and `merchant_ref`.
4. Require the Payment's encrypted stored signature.
5. Compare callback and stored signatures with `hash_equals`.
6. On missing/mismatch, return HTTP 401 and stop before event logging or provider traffic.
7. On match, treat the callback as an untrusted trigger and invoke the existing bounded authoritative status lookup.
8. Apply a paid transition only when the authoritative transaction matches the Payment and reports success.
9. Preserve existing idempotent PaymentEvent, receipt, inventory, and Sales Proof Post behavior for duplicate valid callbacks.
10. On a temporary authoritative status outage after callback authentication, acknowledge according to the existing bounded webhook/queue behavior and let durable reconciliation continue.

The callback signature is authentication evidence, not payment evidence.

## Readiness and Configuration

The production environment contract becomes:

- `KLIKQRIS_PRODUCTION_ENABLED`;
- `KLIKQRIS_PRODUCTION_BASE_URL=https://klikqris.com/api` unless KlikQRIS publishes a different merchant-specific production URL;
- `KLIKQRIS_PRODUCTION_API_KEY`;
- `KLIKQRIS_PRODUCTION_MERCHANT_ID`;
- `KLIKQRIS_PRODUCTION_CALLBACK_URL=https://<app-host>/api/webhook/klikqris`;
- `KLIKQRIS_PRODUCTION_ALLOWED_HOSTS=klikqris.com` for the documented base URL;
- `KLIKQRIS_PRODUCTION_CALLBACK_ALLOWED_HOSTS=<app-host>`;
- existing timeout, queue, schema, and scheduler configuration.

Readiness must not make live provider requests or print secrets. `klikqris:validate-production` remains a sanitized local dependency/configuration check.

## Failure Behavior

- Missing create signature: transaction cannot become newly payable in production; safe non-payable/recovery outcome, no raw provider response disclosure.
- Missing/mismatched callback signature: HTTP 401, no event, no status request, no Payment/Order/inventory side effect.
- Unknown Payment: HTTP 401 without revealing whether a merchant reference exists.
- Valid signature plus callback amount/status mismatch: callback cannot mark paid; authoritative verification and existing safe matching rules govern processing.
- Provider status outage: Payment remains unchanged as Payment Verification Pending and reconciliation retries.
- Signature mismatch between stored create evidence and a later authoritative status response: do not overwrite; report a sanitized invalid provider result for operator review.
- Pre-migration Payment without signature: callback rejected; reconciliation remains authoritative and available.

## Testing Decisions

Tests defend observable contracts, not source text:

1. Gateway request tests assert documented authentication headers and the exact configured callback URL are sent, with no HMAC secret/header.
2. Transaction/native lifecycle tests assert a validated signature is encrypted at rest, round-trips through the model, stays absent from sanitized payload/event/log output, and is write-once.
3. Production readiness tests pass with only documented provider credentials plus project-owned URL/allowlist/operational configuration; they no longer require a webhook secret.
4. Production webhook tests cover matching, missing, and mismatched body signatures. Invalid callbacks return 401 before `GET /qris/status`, PaymentEvent creation, receipt dispatch, or state transition.
5. A valid signed callback still cannot mark paid unless authoritative status returns a matching success.
6. Duplicate valid callbacks remain idempotent across Payment, inventory, receipt, and Sales Proof Post effects.
7. Legacy unsigned Payments remain reconcilable through status and cannot be fulfilled directly by callbacks.
8. PostgreSQL concurrency coverage proves duplicate valid callbacks and reconciliation cannot duplicate fulfillment or overwrite signature evidence.
9. Secret deny-list assertions include stored/create/callback signatures and confirm they do not appear in logs, serialized models, admin surfaces, or persisted sanitized payloads.
10. Existing Telegram and Web Storefront Native QRIS behavior, final `total_amount`, expiry, outcome-unknown recovery, provider switching, and Pakasir regressions remain green.

Prior art is the existing KlikQRIS gateway, webhook, production-readiness, native lifecycle, reconciliation, transaction, customer-surface, and PostgreSQL concurrency suites.

## Acceptance Criteria

- [ ] Runtime configuration contains no KlikQRIS webhook-secret setting or HMAC-header contract.
- [ ] Production readiness succeeds using exactly the documented provider credentials plus project-owned operational URL/allowlist settings.
- [ ] Create requests include the documented headers, merchant body field, and configured callback URL.
- [ ] A validated create signature is stored encrypted, write-once, and never appears in generic provider payloads or observable output.
- [ ] Production callback authentication compares JSON `signature` with the stored create signature using constant-time equality.
- [ ] Missing/mismatched signatures return 401 before event logging, status traffic, or state changes.
- [ ] Matching callbacks still require authoritative status success before `OrderLifecycle` marks the Payment paid.
- [ ] Pre-migration unsigned Payments remain recoverable by reconciliation without callback trust or replacement Payment.
- [ ] Duplicate and concurrent valid signals preserve exactly-once paid, inventory, receipt, and Sales Proof Post effects.
- [ ] Operator documentation and production rollout ticket no longer request a nonexistent KlikQRIS webhook secret.
- [ ] Targeted PHP, PostgreSQL concurrency, configuration, and production smoke gates pass before issue #201 resumes.

## Out of Scope

- Activating KlikQRIS in production.
- Provisioning or exposing real credentials.
- Changing the Payment Provider Switch.
- Automatic fallback to Manual or Pakasir.
- Replacing authoritative status verification with callback trust.
- Changing KlikQRIS fee, QR rendering, expiry, customer presentation, or settlement behavior beyond what contract alignment requires.
- Removing Pakasir.
- Adding provider-neutral payment abstractions.
- Assuming undocumented retry schedules, signing algorithms, header names, IP allowlists, or callback fields.
