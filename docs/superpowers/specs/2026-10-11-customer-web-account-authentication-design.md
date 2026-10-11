# Spec besar: Customer Web Storefront Authentication

Status: **Draft untuk review dan pemecahan implementasi; belum izin deploy production**.

Tanggal: 2026-10-11.

Issue canonical: [#607](https://github.com/Alhiefikri/telegram-bot/issues/607).

Implementation plan and ticket map: [plan](../plans/2026-10-11-customer-web-account-authentication-plan.md).

T8 implementation and sanitized local evidence: [release controls](../../customer-web-account-operations.md#authentication-release-controls-615). Local checks do not close the live Resend/Google/Store Bot release gates or authorize deployment.

Related decisions:

- [ADR-0001: Web storefront order access](../../adr/0001-web-storefront-order-access.md)
- [Customer Web Account implementation plan](../../customer-web-account-implementation-plan.md)
- [Customer Web Account operations](../../customer-web-account-operations.md)
- [CONTEXT.md](../../../CONTEXT.md)
- [Codebase map](../../agents/codebase.md)

## 1. Problem and outcome

The Web Storefront already has a Customer session and passwordless magic-link login, owned-order views, balance read views, guest checkout, Recovery Key access, and guest-order claim. The current login surface is intentionally narrow: it does not provide a conventional registration form, password login, Google OAuth, or secure Telegram account linking.

The target is a serious but incremental Customer account surface:

- a Customer can register and sign in with email and password;
- a Customer can request a one-time email code as an alternative sign-in path;
- a Customer can continue using the existing magic-link capability during migration and until the replacement is proven;
- a Customer can optionally sign in with Google through OAuth/OIDC;
- a Customer can optionally link and use Telegram through a two-sided proof flow, not a username match;
- email, Google, and Telegram identities resolve to one canonical `Customer` row;
- guest checkout remains available and never requires an account;
- Recovery Key remains available and is not weakened or replaced;
- owned Order, credential, Store Balance, and Customer USDT Balance reads remain scoped to the authenticated Customer;
- Resend sends transactional mail through the already verified `notify.lhiestore.web.id` domain.

The result is an account layer around the existing Customer aggregate, not a second user database and not a replacement for Order ownership or Recovery Key authorization.

## 2. Explicit conflict with existing decisions

ADR-0001 accepted the Web Storefront as guest-first and explicitly deferred OAuth. The current code has since implemented the planning-only passwordless magic-link account flow. This spec proposes reopening the deferred part of ADR-0001 and extending the current account contract.

No implementation ticket may silently delete the guest or Recovery Key guarantees from ADR-0001. The parent issue must remain open until the acceptance evidence below exists and the maintainer accepts the ADR update or a superseding ADR.

The existing `docs/customer-web-account-implementation-plan.md` remains historical for the first passwordless phase. This spec supersedes only its authentication expansion decisions:

- passwords are now in scope;
- email OTP is now in scope;
- Google OAuth is now in scope;
- Telegram linking is now in scope only through explicit two-sided proof;
- automatic identity or Order merges remain forbidden.

## 3. Decisions

### 3.1 Canonical identity

`App\\Models\\Customer` remains the canonical account aggregate. There is no `users` table reuse, no Auth0/Clerk/Supabase customer authority, and no second customer table.

Email remains a nullable Customer attribute because existing Telegram-only and anonymous web-checkout Customers are valid. A normalized non-null email is unique. Existing rows are not automatically merged or backfilled.

External identities are additive records in a new `customer_identities` table:

- `customer_id`;
- `provider` from a closed allowlist (`google`, `telegram`);
- immutable provider subject identifier;
- provider email only when supplied and verified, stored as non-authoritative profile evidence;
- timestamps and optional last-used timestamp;
- unique `(provider, provider_subject)`;
- unique `(customer_id, provider)` for one identity per provider in the first release.

OAuth access tokens and refresh tokens are not stored. The application needs an identity proof, not provider API access.

### 3.2 Authentication methods

The first complete release supports:

1. **Email and password**: the primary conventional login path.
2. **Email one-time code**: an alternative for login and recovery; single-use, short-lived, rate-limited.
3. **Existing email magic link**: retained during the migration and removed only after the replacement acceptance gate explicitly permits it.
4. **Google OAuth/OIDC**: optional identity provider through Laravel Socialite or a narrowly equivalent adapter. Only a verified provider subject is authoritative.
5. **Telegram**: optional identity provider through a two-sided proof flow initiated in the browser and confirmed in the Customer's private chat with the Store Bot. Telegram username, display name, phone, or submitted Telegram ID is never sufficient.

The account page may present these methods as separate actions. A method that is not configured must be hidden or show an honest unavailable state, never a dead button.

### 3.3 Registration fields

Required:

- email;
- password;
- password confirmation.

Optional:

- full name, stored in the existing `first_name` profile field or a narrowly justified profile field;
- WhatsApp number as an Optional Support Contact, encrypted or protected according to existing contact handling.

Username is not part of this release. The screenshot is a visual reference, not a requirement to create a public username identity. Adding a username later requires a separate domain decision for uniqueness, changes, abuse, and support.

Registration must verify the email before treating the account as fully verified. A Customer may be created in a pending state, but cannot use email/password or claim an Order until the verification contract is satisfied.

### 3.4 Password contract

- Passwords are stored only through Laravel's configured password hasher.
- Plaintext passwords never enter logs, audit metadata, queue payloads, browser storage, or database fields.
- Password confirmation is validated at the registration boundary and discarded.
- Login errors are generic and do not disclose whether an email exists.
- Password reset uses a single-use, hashed, expiring token owned by the Customer auth module.
- Password reset does not alter Order ownership, Recovery Key state, or existing guest access.
- Password login regenerates the session ID after success.
- A Customer can revoke all Customer sessions through the account security surface in a later slice; the first release must at least support normal logout and reset-token invalidation.

### 3.5 Email OTP and verification

Email OTP is a login/recovery method, not a replacement for the Customer database. A code:

- is generated with cryptographically secure randomness;
- is stored as a one-way hash;
- expires within a bounded short window, initially 10 minutes;
- is single-use and consumed under a row lock;
- has bounded request, resend, and attempt limits;
- is never placed in a query string, application log, audit metadata, or queue payload in plaintext;
- returns a generic request response whether or not an account exists.

Registration email verification and password reset use distinct token purposes. A token for one purpose cannot authenticate another purpose.

### 3.6 Resend delivery

The application uses Resend through Laravel's existing mail/notification seam, not a new mail subsystem.

Production sender:

```text
LhieStore <no-reply@notify.lhiestore.web.id>
```

Production SMTP configuration is secret-backed. The Resend API key is a sending-only credential restricted to the verified domain. It never enters source control, logs, database rows, notifications, or browser responses.

The existing `notifications` queue is used. Email notifications remain queued and retryable. A failed email send is observable without exposing recipient email, token, password, or secret link.

### 3.7 Google OAuth

Google is an optional login/link method, not the canonical Customer database.

Rules:

- callback state and nonce are validated;
- the provider subject is stored as the stable identity key;
- the provider email is accepted only when Google marks it verified;
- an OAuth callback that matches an existing email does not silently merge or take over the account;
- an existing authenticated Customer can explicitly link Google after reauthentication or a current verified session;
- an unlinked Google identity with a matching email enters an explicit account-linking path;
- OAuth tokens are not stored;
- redirect URIs are HTTPS and exact for each environment;
- missing credentials or unconfigured provider state is a safe unavailable outcome.

### 3.8 Telegram linking

Telegram linking is intentionally not implemented as an email or username heuristic.

The contract is:

1. An authenticated Customer starts linking Telegram from the Web Storefront.
2. The server creates a short-lived, single-use, opaque link claim bound to the Customer, browser/session context, and intended operation.
3. The browser opens a private Store Bot deep link.
4. The Bot confirms the Telegram actor in private chat and records the claim result through the existing Telegram identity authority.
5. The browser polls a bounded status endpoint or returns through a signed one-time callback.
6. The server consumes the claim atomically and attaches the Telegram identity if it is not already attached elsewhere.

A Telegram identity already attached to another Customer is a conflict requiring explicit recovery policy. The system never reassigns it automatically. Group messages, channel membership, username, display name, and client-submitted numeric IDs do not prove ownership.

### 3.9 Guest checkout and Recovery Key

Guest checkout remains available. Login is never required to browse, create a Cart, create an Order, or create a Payment.

Recovery Key remains a one-time-displayed, hashed Order access secret. It continues to unlock eligible guest Order credential access through `/akses-pesanan`. An account session does not make a non-owned Order visible.

Guest Order claim requires the authenticated Customer plus invoice number and valid Recovery Key. Email equality alone, OAuth email equality, Telegram username, WhatsApp number, or invoice number alone cannot claim an Order.

### 3.10 Session and authorization

Customer auth uses the existing dedicated `customer` guard and current Laravel session mechanism. It never shares the admin `web` guard or `App\\Models\\User` table.

Every Customer account read is Customer-scoped in the query or policy. Route identifiers are not authorization. Credential pages continue to emit `Cache-Control: no-store, private` and `Referrer-Policy: no-referrer`.

Redis remains the existing session/cache/queue runtime. A Redis restart may invalidate active sessions as documented by ADR-0014; it must not delete Customer, Order, identity, token, or Recovery Key authority. Durable account state stays in PostgreSQL.

## 4. Domain model and state

### Customer additions

Add only fields needed for this release:

- `email` remains nullable and unique when present;
- `email_verified_at`;
- password hash field with Laravel-compatible hidden serialization;
- `remember_token` if required by the guard;
- profile fields only where the current model has an existing semantic owner.

Do not add a username identity or catalog/presentation ENV.

### New tables

`customer_identities`:

- UUID or existing project primary-key convention;
- Customer foreign key with restrictive deletion;
- provider allowlist;
- immutable provider subject;
- verified provider email evidence nullable;
- unique provider/subject and Customer/provider constraints;
- last-used timestamp.

`customer_auth_tokens` or purpose-specific equivalent:

- Customer or normalized email binding;
- purpose (`email_verification`, `email_otp`, `password_reset`, `telegram_link`);
- token hash;
- expiry;
- consumed timestamp;
- bounded attempt counter where applicable;
- requested IP metadata only when privacy policy permits;
- unique token hash and indexes for expiry/active lookup.

The existing `customer_login_links` table remains compatible while magic-link migration is active. It may be retired only after a release gate proves no active link or rollback path needs it.

## 5. Routes and UI contract

The exact route names may follow existing route conventions, but the behavior must cover:

- `GET /akun` account landing;
- `POST /akun/register` registration;
- `GET /akun/verifikasi-email` verification status;
- `POST /akun/login` password login;
- `POST /akun/login-code` request OTP;
- `POST /akun/login-code/consume` consume OTP;
- existing magic-link request/consume routes during migration;
- `POST /akun/password-reset` request reset;
- `GET/POST /akun/password-reset/{token}` consume reset;
- `GET /auth/google/redirect` and callback;
- authenticated Google link/unlink routes;
- authenticated Telegram link start/status/consume routes;
- `POST /akun/logout`;
- authenticated Order, balance, and claim routes already present.

UI requirements:

- email-first login remains usable with JavaScript disabled where existing forms support it;
- password visibility toggle is progressive enhancement, not required for submission;
- loading, success, expired-token, invalid-code, rate-limited, unavailable-provider, and generic-error states are explicit;
- keyboard focus and mobile layout remain usable;
- no field reveals account existence;
- WhatsApp copy states that it is optional support contact, not a login method;
- OAuth buttons are rendered only when configured;
- all security and legal links point to real routes/documents.

## 6. Security invariants

1. Admin authentication is unchanged and isolated.
2. A Customer identity provider subject maps to at most one Customer.
3. A Customer can have at most one identity for each provider in the first release.
4. No automatic merge occurs on matching email, name, username, phone, or Telegram ID.
5. Tokens are high-entropy, hashed at rest, purpose-bound, expiring, single-use, and atomically consumed.
6. Passwords are hashed and never serialized or logged.
7. Authentication responses are generic where account enumeration is possible.
8. Login, OTP, registration, OAuth callbacks, linking, reset, and claim operations are rate-limited.
9. Session ID regenerates after authentication and privilege changes.
10. Customer data and Order data are scoped by authenticated Customer ID.
11. Guest checkout and Recovery Key access keep their existing behavior.
12. Credentials are never exposed on non-paid, foreign, or unauthorized Orders.
13. Resend credentials are secret-backed and sending-only.
14. Email content never contains Recovery Keys, raw credentials, provider tokens, or unnecessary payment secrets.
15. Audit/log metadata contains action, safe IDs, outcome, and redacted error categories only.
16. OAuth provider downtime cannot prevent guest checkout or passwordless Recovery Key access.
17. Telegram linking never trusts client-supplied Telegram identity claims.

## 7. Queue, mail, and operational contract

- Use the existing `notifications` queue and supervised worker.
- Queue payloads that contain transient plaintext token material must use the repository's encrypted queued-notification convention.
- Mail retry must not mint a second valid token for one user action.
- Expired/consumed auth token cleanup is bounded and scheduled.
- Metrics and logs report counts, purpose, safe outcome, provider status class, and correlation ID without raw email or token.
- Resend delivery failure is visible through failed jobs and an operator-safe health summary.
- Production configuration uses GitHub Actions deployment secrets and existing `.env` policy.
- No provider API key is committed or printed by diagnostics.

## 8. Rollout and rollback

### Expand

1. Add additive schema and model support.
2. Keep existing magic-link login and guest access operational.
3. Add Resend configuration and a non-production send smoke test.
4. Add password/OTP routes behind feature availability checks, not a global ENV presentation switch.
5. Add Google and Telegram identity records and linking endpoints without exposing buttons until provider readiness is proven.

### Migrate

1. Existing magic-link customers continue to authenticate.
2. A verified magic-link session may set a password and complete profile enrollment.
3. No automatic merge of Customers occurs.
4. Existing Orders remain attached to their current Customer unless explicitly claimed through invoice plus Recovery Key.
5. Customer may explicitly link Google or Telegram from an authenticated account.

### Cut over

1. Show password and OTP methods on the account page after mail and tests pass.
2. Show Google only when credentials and redirect configuration are ready.
3. Show Telegram only when the Bot proof callback and replay tests pass.
4. Keep magic link as a visible fallback until the release gate confirms replacement coverage.

### Rollback

- Hide new auth methods and restore the magic-link CTA without changing Customer, Order, identity, or Recovery Key data.
- Revoke a compromised Resend key and issue a new sending-only key.
- Do not drop additive tables or delete identities during rollback.
- Guest checkout, signed invoice, Recovery Key, payment, inventory, and fulfillment remain unchanged.

## 9. Verification plan

Permanent tests must prove consumer-visible behavior:

- registration with valid and invalid input;
- duplicate email and generic login responses;
- password login success/failure and session regeneration;
- email verification and reset token expiry/replay/purpose isolation;
- OTP attempt, expiry, replay, resend, and rate limits;
- magic-link compatibility during migration;
- Google subject creation, explicit linking, email collision, callback state, and provider failure;
- Telegram proof creation, private-chat confirmation, expiry, replay, identity conflict, and cross-Customer denial;
- Customer-scoped Order/balance/credential reads;
- guest checkout and Recovery Key regression;
- concurrent claim and identity-link races in PostgreSQL;
- no secret leakage in response, serialization, audit, logs, queue payloads, or notifications;
- Resend mail delivery smoke test with a real non-production inbox.

Use existing focused suites such as `CustomerAccountTest`, `ApplicationRateLimiterRegistrarTest`, Storefront access tests, Customer balance tests, and the PostgreSQL concurrency harness. Browser tests must exercise the actual account UI at mobile and desktop widths.

## 10. Non-goals

- Replacing guest checkout.
- Replacing Recovery Key access.
- Creating a public username system.
- Making WhatsApp a login or ownership proof.
- Automatically merging existing Customer rows.
- Sharing Orders between accounts.
- Storing Google/Telegram OAuth tokens.
- Adding Auth0, Clerk, Supabase Auth, Firebase Auth, or a second customer database.
- Adding customer mobile API or native app auth.
- Adding MFA, passkeys, device management, or enterprise SSO in this release.
- Changing payment, inventory, supplier, fulfillment, Store Balance, or Customer USDT Balance authority.
- Moving catalog content or presentation settings into ENV.

## 11. Release gate

The release is not complete until:

- all child tickets are closed with focused evidence;
- the ADR-0001 decision is updated or superseded explicitly;
- Resend delivery succeeds from the deployed application without exposing secrets;
- existing magic-link, guest checkout, Recovery Key, payment, inventory, Telegram, and admin regressions pass;
- PostgreSQL race tests pass for claims and identity links;
- browser evidence covers registration, password login, OTP, OAuth unavailable/configured states, linking, logout, and mobile layout;
- rollback has been exercised in a non-production environment;
- deployment remains through the existing GitHub Actions workflow from `main`.

## 12. Agent implementation clarifications

These contracts resolve implementation choices; they are not claims about implemented behavior.

### Registration and account recovery

- Password minimum 12 characters, maximum 128 characters; allow paste, spaces, and password managers; do not require arbitrary character classes. Never trim or lowercase a password.
- Registration cannot overwrite a password, profile, or verified email on an existing Customer. Duplicate registration returns generic guidance to sign in or recover access.
- Pending registration credentials expire after 30 minutes. Email verification binds the registration version and proposed password hash. An older verification cannot install credentials after a later enrollment or reset.
- Verification and reset tokens use at least 32 random bytes and expire after 30 minutes. Store hashes; deliver through encrypted work. A GET may show a confirmation form but must not consume a token. Consumption is CSRF-protected POST to avoid email scanner side effects.
- Reset invalidates all outstanding reset/login/verification challenges and increments a Customer authentication version checked by authenticated routes. Other Customer sessions become invalid on their next request; the current browser must authenticate again. Admin sessions are not revoked.
- Existing verified magic-link Customers set their first password only from a fresh authenticated account-security flow, not anonymous duplicate registration.
- First-release profile edits cover name and password. Email changes, account deletion, and manual account merging need separate policies and are not silently implemented.

### OTP and rate limits

- Use a six-digit numeric code with leading zeros supported, bound to an opaque browser challenge and normalized email/purpose. Hash low-entropy codes with a server-secret keyed digest; a plain SHA-256 code hash is insufficient against offline enumeration.
- Five incorrect attempts exhaust the challenge, including concurrent requests. Expiry is checked with `expires_at <= now()`. A resend invalidates the preceding challenge atomically.
- Resend cooldown is 60 seconds. Request limit: three per email per 15 minutes across IPs and ten per IP per hour. Registration/reset share those email-delivery limits.
- Password login: five failures per email/IP pair per 15 minutes and 30 per IP per 15 minutes. OTP consume: five per challenge and 30 per IP per 15 minutes. OAuth/link starts: ten per session/IP per 15 minutes. Existing Order-claim limit remains unchanged.
- Use keyed identifiers rather than plaintext email in throttle keys. Redis loss must not reset the durable OTP attempt counter. Missing rate-limit storage fails closed for new mail/auth challenges with a safe retry message.
- Unknown-email OTP requests are generic and do not create an account; registration remains explicit.

### Google linking and unlinking

- New provider subject plus unused verified email can create one verified Customer through the registration policy. Existing email collision requires fresh authentication to that existing Customer before explicit linking. Database uniqueness resolves concurrent callbacks without a forced merge.
- Identity linking requires fresh proof within five minutes, not possession of any old authenticated cookie alone.
- Unlink requires fresh proof and at least one other working verified login method. Never unlink the last login method.
- Browser callback state is one-use and session-bound. Use OAuth authorization-code state checks; require OIDC nonce/ID-token validation only if that adapter actually uses OIDC. Do not claim Socialite automatically verifies an unused OIDC nonce.

### Telegram canonical ownership

- Existing `customers.telegram_id` remains the Store Bot Customer authority. A new identity record cannot point to a different Customer than the row already owning that exact numeric Telegram ID.
- Telegram web sign-in starts an anonymous browser-bound challenge, gets explicit confirmation from the private Bot actor, then authenticates the existing Telegram Customer. It must not create a second Customer for an already registered actor.
- For explicit linking from an email Customer, an existing different Telegram Customer is a conflict. Do not merge Customers, move balances, reassign receivers, or move Orders. Explain the conflict without exposing the other Customer.
- A Telegram Customer may enroll a new, unused email after fresh Telegram proof plus email verification on that same Customer. An email owned elsewhere is a conflict.
- Link/login claims expire after five minutes and carry a random opaque capability in the Bot deep link, not raw IDs or credentials. Treat that capability as a secret: redact it from logs. The earlier phrase about a payload containing no token means no embedded identity or reusable credential, not no random proof capability.
- Browser polls are bound to the originating session; confirmation and browser consumption are separate one-use transitions. Bot confirmation alone does not authenticate an unrelated browser.
- Do not allow Telegram unlink while Store Bot identity and financial ownership rely on `telegram_id`; first release provides connect/status/sign-in only.

### Mail delivery and operational readiness

- Encrypted queue bodies may transiently contain the code/link and delivery address; the requirement is no plaintext at rest or in logs, not absence from the email delivery process itself.
- Persist bounded encrypted auth-mail work with token creation in PostgreSQL. Redis carries work IDs as wakeups; a scheduled recovery redispatches unexpired work after queue loss. Reuse existing mail work conventions where available; do not reuse Telegram delivery state as email authority.
- Sending retries reuse the same challenge. Expired challenges are never newly sent. Provider timeout may cause duplicate copies of the same message, never multiple auth grants.
- Resend SMTP: `smtp.resend.com`, STARTTLS port 587, username `resend`, password the sending-only API key. Translate TLS to the installed Laravel mail configuration correctly; do not assume `MAIL_SCHEME=tls` is a valid transport scheme.
- Existing sender branding stays under its current configuration owner; no new presentation ENV. Disable open/click tracking for authentication messages.
- Verified DNS is proven in this session, not application delivery. Resend API key, approved test inbox, and Google OAuth client credentials are operator prerequisites still unproven. Document how to provision them without printing secrets.
- Technical tickets may implement and exercise disposable provider fixtures. Real-mail and live OAuth readiness remain blocked until credentials are provisioned; mark the gate honestly, not a fake passing result.
- This plan does not authorize GitHub Actions dispatch/polling, production deploy, or production mutations. The admin must request those actions separately.
