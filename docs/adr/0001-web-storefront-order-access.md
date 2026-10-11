# Web storefront order access

## Status

Accepted: 2026-07-10. Authentication extension accepted for implementation: 2026-10-11 (#608); new login methods and production readiness remain gated by #607.

## Decision

The Web Storefront is a standalone customer purchase surface: customers check out as guests with QRIS and do not need a Lhie Store login or OAuth provider.

For every new web order, the application shows a recovery key once, stores only its bcrypt hash, and requires the invoice number plus that key to reopen paid account credentials. A successful check creates a 30-minute server-side session grant. Credential responses use `Cache-Control: no-store, private` and `Referrer-Policy: no-referrer`; recovery attempts are rate-limited.

Customers are instructed to save the invoice, recovery key, and credentials in a private password manager or secure private screenshot. The key cannot be resent because the plaintext is not stored and this flow deliberately does not depend on email or Telegram delivery.

### Customer authentication extension (#608)

This decision reopens the OAuth deferral below. `App\Models\Customer` is the canonical account for email/password, email OTP, retained magic links, Google, and private-chat Telegram proof. The [authentication specification](../superpowers/specs/2026-10-11-customer-web-account-authentication-design.md), especially section 12, supersedes the authentication exclusions in the [historical passwordless plan](../customer-web-account-implementation-plan.md). It does not supersede guest checkout, Recovery Key, Order ownership, or financial authority.

The additive foundation stores nullable `password` through Laravel's configured hasher, hidden `auth_version` and `enrollment_version`, `customer_identities`, and `customer_auth_tokens`. Existing `first_name` owns the optional account name; no public username or new contact/profile column is introduced. Email and password remain nullable. Existing Telegram-only and anonymous Customers are not backfilled, merged, or forced to enroll.

Identities allow only `google` and `telegram`. Database uniqueness covers `(provider, provider_subject)` and `(customer_id, provider)`; Customer deletion is restricted by the FK. Ownership, provider, and subject cannot be rewritten. Provider email is nullable, verified evidence supplied by a future trusted adapter, never linking authority. No OAuth access/refresh token is stored. PostgreSQL requires every Telegram subject to equal the same Customer's canonical `telegram_id`, locks that Customer during the check, and rejects changing the canonical ID while an identity references it.

The shared `CustomerIdentityLinkOutcome` contract distinguishes successful linking, same-Customer replay, subject owned elsewhere, a different subject already linked for this provider, and canonical Telegram ownership conflict. Future adapters resolve uniqueness races to these outcomes through a savepoint/transaction boundary; they never expose another Customer's data or merge accounts. Google linking/unlinking requires fresh proof within five minutes and another verified login method before unlink. Telegram first-release flows do not provide unlink. Proof acquisition and link mutation are downstream tickets, not implemented by this foundation.

Token purposes are closed: `email_verification`, `email_otp`, `password_reset`, `telegram_link`. Every row binds a Customer or normalized email, except an anonymous Telegram sign-in challenge that binds only its originating browser/session. All rows carry a unique 64-character lowercase digest, required issuance/expiry timestamps, and an optional consumed timestamp. Maximum lifetimes are respectively 30, 10, 30, and 5 minutes. OTP requires a separate opaque challenge digest, normalized email and at most five durable incorrect attempts; its code digest must be server-secret keyed, not plain SHA-256. Telegram claims require a hashed browser/session binding; downstream proof state binds the confirmed private actor without rewriting the original token binding. Hashes, bindings, proposed password hash, email, versions and loaded Customer relationships are hidden from serialization. Models guard all identity/token fields against mass assignment; trusted module owners explicitly assign them.

PostgreSQL checks enforce digest shape, normalized token email, binding, purpose-specific TTL and attempt bounds; triggers reject binding rewrites, decreasing attempt counts and reopening consumed tokens. SQLite covers model/guard/serialization and basic unique/FK constraints, not these production invariants. `isActiveFor()` checks exact purpose, unconsumed state, strict future expiry and remaining OTP attempts; it is not atomic consumption authority. Downstream owners must lock the row, check Customer/enrollment/browser versions and consume through CSRF-protected POST. Verification/reset capabilities use at least 32 random bytes. Pending enrollment expires in 30 minutes and binds the enrollment version/proposed password hash. Password reset increments `auth_version` and invalidates outstanding challenges; session enforcement belongs to #609.

The existing `customer_login_links` schema, issuance, 15-minute expiry and POST consumption remain active. The only legacy model change hides its email, digest and IP from serialization. `customer` continues using the `customers` provider; admin `web` continues using `users`. Auth-mail encryption, durable work/recovery, cleanup and delivery belong to #610, not a second mail or Telegram delivery subsystem.

Guest checkout still requires no authentication provider. Account access remains Customer-scoped; identity/email equality never grants Order, credentials, Store Balance, or Customer USDT Balance ownership. Claiming an eligible guest Order still requires authenticated Customer plus invoice and Recovery Key. No balances, receivers or Orders move as a result of authentication linking.

Rollback restores the magic-link surface with additive schema retained; production rollback must not drop identity/token history. #615 implements a database-backed new-method availability gate and scoped version/challenge revocation; [release controls and sanitized evidence](../customer-web-account-operations.md#authentication-release-controls-615) describe their distinct semantics. Guest/Recovery Key access and existing account reads are outside the availability gate. Resend real delivery, Google credentials and live Telegram browser/Bot proof remain operator-owned release gates. This decision authorizes neither production deployment nor GitHub Actions operations.


## Transition

Orders created before this migration have no recovery-key hash. Their existing signed invoice behavior remains temporarily intact because a replacement key cannot be generated and delivered safely without a verified customer channel. New web orders always use the recovery-key flow.

## Consequences

- OAuth was deferred for the original guest-only release. The optional Customer authentication extension above now permits it without making guest purchases provider-dependent.
- Losing both invoice and recovery key requires a future operator-approved recovery policy; the application cannot reconstruct the key.
- The Store Bot and its Community Gate remain legacy Telegram concerns and do not gate the Web Storefront.
