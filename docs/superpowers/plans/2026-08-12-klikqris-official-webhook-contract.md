# KlikQRIS Official Webhook Contract Alignment Implementation Plan

**Specification:** `docs/superpowers/specs/2026-08-12-klikqris-official-webhook-contract.md`
**Provider source:** https://klikqris.com/dokumentasi-api, read on 2026-08-12.
**Related rollout:** #201 remains blocked until this plan is merged, deployed with KlikQRIS disabled, and verified.

## Constraints

- Implement against the published KlikQRIS contract only: `x-api-key`, `id_merchant`, base URL, create/status endpoints, create-response signature, and callback-body signature.
- Do not invent a shared secret, HMAC header, signing algorithm, callback retry schedule, IP allowlist, or undocumented field.
- Callback signature match authenticates only the trigger. Authoritative status verification remains mandatory before paid.
- Keep signatures out of generic payloads, events, logs, UI, audit metadata, fixtures, reports, and issue evidence.
- Keep Payment Provider Switch ownership, existing-Payment provider binding, no-fallback behavior, and `OrderLifecycle` transitions unchanged.
- Each commit below must leave the application and its focused tests working.

## Commit 1 — Add encrypted create-signature storage

**Purpose:** Establish the smallest deployable data-model prerequisite without changing request or callback behavior.

Changes:

- Add one nullable column dedicated to the opaque KlikQRIS transaction signature.
- Add the field to the Payment model's controlled assignment list and encrypted cast.
- Ensure it is hidden from array/JSON serialization even when a Payment is serialized accidentally.
- Update Payment factory/support builders only where an explicit synthetic signature is needed.
- Add behavior tests proving plaintext is not stored, model round-trip works, null remains valid for existing Payments, and serialization omits it.

Verification:

- Payment model/migration tests pass.
- Existing KlikQRIS and non-KlikQRIS Payment creation remains compatible.

## Commit 2 — Persist validated provider signature as write-once evidence

**Purpose:** Capture the documented create-response signature under the existing Native QRIS locking boundary.

Changes:

- Extend the Native QRIS persistence path to write a nonblank signature from a validated KlikQRIS transaction.
- Make signature evidence write-once: an identical value is idempotent; a different later value cannot overwrite it and produces a sanitized invalid-provider outcome/report.
- Permit authoritative status recovery to populate the signature only when the field is blank.
- Keep the sanitizer behavior that removes `signature` recursively from provider payload and PaymentEvent data.
- Prevent a production create response without a usable signature from becoming a newly payable result.
- Preserve outcome-unknown recovery on the same Payment and prevent duplicate create.

Verification:

- Native lifecycle tests cover create persistence, idempotent repeat, mismatch rejection, status recovery, and absent production signature.
- Secret deny-list assertions prove no signature appears in payload/event/error output.

## Commit 3 — Send the documented callback URL during create

**Purpose:** Make every transaction explicitly point to the project-owned callback endpoint documented by KlikQRIS.

Changes:

- Add the active environment callback URL to the `POST /qris/create` body when configured.
- Keep `id_merchant` in both the documented header and body.
- Preserve `x-api-key`, integer amount, order ID, description, timeout, and uncertain-create behavior.
- Production readiness continues to require an exact HTTPS `/api/webhook/klikqris` URL; sandbox includes its callback URL only when configured.
- Update request contract tests to assert the exact body and headers and to assert no webhook-secret/HMAC header exists.

Verification:

- Gateway tests pass for sandbox and production request construction.
- Create failure and outcome-unknown tests remain green.

## Commit 4 — Authenticate production callbacks with stored body signature

**Purpose:** Replace the undocumented HMAC-header gate with the provider-documented equality check.

Changes:

- Parse and validate the callback JSON body before authentication without persisting or logging it.
- Resolve the Payment by `provider=klikqris` and `merchant_ref=order_id`.
- Require a nonblank callback `signature` and nonblank encrypted create signature.
- Compare them with constant-time equality.
- Return HTTP 401 for unknown Payment, absent stored signature, absent callback signature, or mismatch; do not disclose which condition failed.
- Ensure rejection occurs before PaymentEvent creation, provider status traffic, queue dispatch, lifecycle transition, receipt, inventory, or Sales Proof Post effects.
- After a match, preserve the existing callback processor flow: callback is a trigger, authoritative status is fetched, amounts and identity are checked, and only `OrderLifecycle` may apply paid.
- Keep bounded provider-outage acknowledgement and durable follow-up behavior after authentication succeeds.

Verification:

- Production webhook tests cover matching, missing, mismatched, unknown-Payment, duplicate, malformed, and provider-outage behavior.
- Tests explicitly assert invalid callbacks make zero status requests and create zero events.
- A matching callback plus non-success/mismatched authoritative status never marks paid.

## Commit 5 — Remove the undocumented webhook-secret configuration

**Purpose:** Make production readiness achievable with the credentials KlikQRIS actually publishes.

Changes:

- Remove sandbox/production webhook-secret config keys and accessors.
- Remove webhook-secret env examples and Docker environment forwarding.
- Remove shared-secret cross-environment validation.
- Change readiness to require documented provider credentials plus project-owned callback URL/allowlists and existing queue/schema/scheduler dependencies.
- Update sanitized readiness command tests and support fixtures.
- Preserve production-vs-sandbox endpoint, API key, merchant ID, callback URL, environment, HTTPS, host, timeout, queue, schema, and schedule gates.

Verification:

- Production readiness passes without a webhook secret and fails for each remaining required dependency.
- `klikqris:validate-production` output remains sanitized and makes no provider request.
- No old env key, config key, accessor, test fixture, or operator instruction remains.

## Commit 6 — Cover legacy unsigned Payments and concurrency

**Purpose:** Preserve existing-Payment safety during additive deployment and live traffic.

Changes:

- Add behavior coverage proving pre-migration KlikQRIS Payments without stored signatures reject callbacks with 401 and no status traffic.
- Prove scheduled and admin reconciliation can still fetch authoritative status and finish those Payments safely.
- Extend PostgreSQL concurrency coverage for duplicate matching callbacks racing reconciliation.
- Assert stored signature evidence cannot be overwritten and paid/inventory/receipt/Sales Proof Post effects occur at most once.
- Preserve late-payment and Payment Verification Pending semantics.

Verification:

- Focused SQLite tests pass.
- KlikQRIS PostgreSQL concurrency tests pass.
- Existing provider-switch concurrency and Pakasir payment regressions pass.

## Commit 7 — Update operator documentation and rollout gate

**Purpose:** Prevent operators from provisioning a nonexistent provider secret or enabling production on the obsolete contract.

Changes:

- Update production configuration guidance to list only documented KlikQRIS credentials and project-owned callback/allowlist settings.
- Explain that the create-response signature is stored by the application and compared with the callback-body signature; operators do not configure it.
- State that callback equality does not replace authoritative status verification.
- Document behavior for invalid callbacks, legacy unsigned Payments, reconciliation, config-cache refresh, readiness, and activation order.
- Update the implementation map's KlikQRIS production ingress description so it no longer claims a production secret.
- Link the specification and implementation issue from #201 and keep #201 blocked until deployment with KlikQRIS disabled is verified.

Verification:

- Documentation contains no instruction to set `KLIKQRIS_PRODUCTION_WEBHOOK_SECRET`.
- Commands, paths, and env names match the final implementation.

## Final Verification Gate

Run after all commits, once:

1. Focused KlikQRIS unit and feature suites: gateway, transaction, Native QRIS lifecycle, webhook, production readiness, reconciliation, customer surfaces, Telegram, and Storefront.
2. KlikQRIS PostgreSQL concurrency suites.
3. Payment Provider Switch and Pakasir regression suites affected by shared Payment lifecycle behavior.
4. Code formatter and static checks used by the repository.
5. Production configuration/readiness smoke with synthetic secrets and KlikQRIS disabled for traffic.
6. Production container smoke proving app, asynchronous queue workers, and scheduler are healthy.
7. Secret scan/evidence review proving no real credential or synthetic signature leaked into tracked docs, logs, artifacts, or issue comments.

## Deployment Sequence

1. Merge the additive migration and contract alignment.
2. Deploy with the Payment Provider Switch still on the existing provider and `KLIKQRIS_PRODUCTION_ENABLED=false`.
3. Run migrations and refresh Laravel runtime config through the normal container/process restart.
4. Provision only the documented production API key, merchant ID, base URL, callback URL, and allowlists through the production secret manager.
5. Enable the production readiness flag while keeping the Payment Provider Switch away from KlikQRIS.
6. Run the sanitized `klikqris:validate-production` and operational health checks.
7. Resume #201 only after readiness is green and an operator confirms the callback endpoint is publicly reachable over HTTPS.
8. The owner performs staged activation and rollback drills under #201; this implementation plan does not authorize traffic.
