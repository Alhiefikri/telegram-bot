# Spec: Customer Login Link and Admin Login Throttling Hardening

## Problem Statement

The CustomerLoginLink model disables mass-assignment protection globally. Current application call sites pass curated internal arrays, so no raw request propagation was found, but a future call site could write `used_at` or another security-sensitive field through `create()` without an explicit allowlist.

The admin login limiter currently keys attempts by normalized login plus IP. An attacker can rotate identifiers from one IP and receive a fresh five-attempt bucket for each identifier. The controller also checks and increments in separate operations, which is weaker under concurrent requests on the production cache backend.

## Solution

Replace the unrestricted CustomerLoginLink model with an explicit fillable allowlist containing only fields needed when issuing a link. Keep `used_at` outside the mass-assignment allowlist so it can only be assigned by the locked consume flow.

Retain the per-identity/IP login bucket as defense-in-depth and add a global per-IP bucket. Use atomic limiter increment behavior so one IP cannot bypass throttling by changing identifiers or winning a check-then-hit race. Use separate policy values for identity and IP thresholds, preserve the existing validation response, clear the identity bucket after successful login, and do not clear the global IP bucket after one successful login.

## User Stories

1. As a customer authentication owner, I want a login-link request to populate only approved link fields, so that a future mass-assignment call cannot mark a link used or alter its lifecycle fields accidentally.
2. As a customer, I want a valid login link to remain consumable exactly once, so that hardening the model does not break the normal request and consume flow.
3. As an administrator, I want failed login attempts from one IP to share a global limit even when the attacker changes email or name, so that identifier rotation cannot bypass brute-force protection.
4. As an administrator sharing a network with another administrator, I want the per-identity and per-IP policies to be explicit and observable, so that the global defense does not silently erase the account-specific defense.
5. As a legitimate admin, I want a successful login to clear the identity bucket but not reset the global IP bucket, so that a single successful credential check does not immediately permit an IP-wide attack burst.
6. As an operator, I want the existing localized validation failure behavior to remain stable when either limiter blocks a request, so that the login UX and monitoring contract do not change unexpectedly.
7. As a security reviewer, I want concurrent failed requests to increment the limiter atomically, so that the observed attempt count cannot undercount simultaneous brute-force traffic.
8. As a developer, I want the limiter thresholds and decay windows to be policy/config values, so that operations can tune false-positive risk without rewriting controller logic.

## Implementation Decisions

- Use an explicit mass-assignment allowlist for CustomerLoginLink: `email`, `token_hash`, `expires_at`, and `requested_ip`.
- Do not include `used_at` in the allowlist. The consume service may still assign `used_at` directly after it obtains the existing row lock and validates token, expiry, and one-time-use conditions.
- Preserve the current token hashing, expiry, email normalization, transactional consume, audit events, and notification behavior.
- Use two limiter identities: normalized login plus IP, and normalized IP alone. Keep the identity bucket as defense-in-depth.
- Use atomic increment semantics for both limiter buckets. The decision to reject must be based on the incremented count, not a separate check followed by a later hit that can race.
- Start with a 60-second decay, a maximum of 5 failed attempts per identity/IP, and a maximum of 10 failed attempts per IP across identifiers. Isolate these values as policy/config so they can be tuned.
- Count failed credentials and forbidden admin accounts consistently with the current behavior. A successful login clears only the identity bucket after authentication succeeds.
- Keep validation messages and HTTP redirect behavior compatible with the current admin login flow. Do not add password reset, CAPTCHA, account lockout persistence, or a new authentication provider.
- If the cache backend cannot provide the same atomic behavior in the unit test environment, use a focused policy seam plus a production-backend integration test rather than claiming SQLite/array tests prove cross-request atomicity.

## Testing Decisions

- Verify normal customer login-link request and consume behavior through the existing customer HTTP feature seam. Attempt mass assignment of `used_at` and assert it is not persisted, then verify the domain consume flow still sets it exactly once.
- Verify all approved fields are still written by the authentication service and that raw token material remains absent from persisted or logged values.
- Exercise the public admin login HTTP route with five failed attempts for one identifier and then attempts for several different identifiers from the same IP. Assert that the global IP threshold blocks rotation before each identifier can obtain an independent unlimited sequence.
- Assert that a successful login clears the identity bucket but does not clear the IP-wide bucket.
- Test the limiter policy independently for threshold, decay, normalized identity, and atomic-increment result handling.
- Add a production-cache integration/concurrency test where the repository’s concurrency harness supports it. Do not treat the array cache unit test as proof of atomicity.
- Run the existing customer account, admin authentication/access, and broad non-draft PHP suites.

## Out of Scope

- Changing customer account ownership, recovery keys, email delivery, token hashing, or session behavior.
- Adding persistent account lockout, CAPTCHA, MFA, password reset, or a third-party authentication service.
- Changing admin roles, permissions, IP allowlists, proxy trust, or infrastructure rate limits.
- Changing the Telegram Outbound Effect Ledger, queue behavior, payment flow, or storefront checkout.

## Further Notes

This is deliberately a small hardening spec and does not require a separate ADR. It follows the existing domain ownership rules and the Laravel mass-assignment and rate-limiting guidance:

- https://laravel.com/docs/13.x/eloquent#mass-assignment
- https://laravel.com/docs/13.x/routing#rate-limiting

The hardening work is independent from the Telegram outbox dependency chain and may be implemented before or after the outbound-effect slices.
