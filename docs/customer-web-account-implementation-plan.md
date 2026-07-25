# Customer Web Account Implementation Plan

Status: planning only. The current guest checkout and Recovery Key flow remain live until every phase below is deployed and verified.

## Goal

Make the Web Storefront an independent customer surface. A customer can sign in from email, view only their own paid orders, and recover access without depending on Telegram. Telegram remains a catalog, notification, and support channel; it is not the customer account authority.

The first version uses passwordless email magic links. It deliberately does not add passwords or OAuth.

## Locked Decisions

- Web checkout remains guest checkout. Login is never required to start a purchase or create a QRIS payment.
- Customer authentication uses a dedicated `customer` session guard and provider. It must never share the admin `web` guard or `App\Models\User` table.
- A magic link is emailed to authenticate the owner of an email address. It expires after 15 minutes and is single-use.
- An email address becomes verified only after its magic link is consumed.
- A customer account may see only paid orders whose `customer_id` is that authenticated customer.
- A guest order may be claimed only by an authenticated customer who supplies the order's invoice number and valid Recovery Key.
- Claiming an order moves its `customer_id` to the authenticated customer in one database transaction. It is idempotent for the current customer and rejects another customer.
- The Recovery Key flow remains available for every eligible order. A customer account supplements it; it does not replace it.
- OAuth, passwords, Telegram account linking, order sharing, and a mobile API are out of scope for this phase.
- All credential responses continue to send `Cache-Control: no-store, private` and `Referrer-Policy: no-referrer`.

## Current Repo Map

- `App\Models\User` and the `web` guard are admin-only: `src/app/Models/User.php`, `src/config/auth.php`.
- `App\Models\Customer` already owns orders and carts, but is a plain Eloquent model without an email identity: `src/app/Models/Customer.php`.
- Every order already has `customer_id`; web guest checkout creates a customer with `telegram_id = null`: `src/app/Storefront/StorefrontShopCheckout.php`.
- Recovery Key issuance, validation, and temporary per-order grants live in `src/app/Storefront/StorefrontOrderAccess.php`.
- `/akses-pesanan` validates invoice plus Recovery Key and shows credentials only for paid orders: `src/app/Http/Controllers/StorefrontOrderAccessController.php`.
- Public storefront routes are in `src/routes/web.php`.
- Database-backed queues, sessions, and cache are already the production defaults in `docker-compose.yml`; do not add Redis for this feature.

## Target Customer Flow

### Sign in

1. Customer opens `/akun` and enters an email address.
2. The app accepts the request without revealing whether that email has an existing account.
3. The app creates one hashed, single-use login token and queues a transactional email.
4. The customer opens the link within 15 minutes.
5. The app consumes the token atomically, finds or creates the customer by normalized email, sets `email_verified_at`, regenerates the session, and redirects to `/akun/pesanan`.

### Claim a guest order

1. Authenticated customer opens `/akun/klaim-pesanan`.
2. Customer submits invoice number and Recovery Key.
3. The app locks the order and authenticated customer row in one transaction.
4. It requires a paid order and validates the Recovery Key hash.
5. If the order is already owned by this customer, the result is successful without another mutation.
6. If another customer owns the order, the app returns the same generic invalid-access message used for an invalid key.
7. Otherwise, it updates `orders.customer_id` to the authenticated customer and records an audit event.
8. The customer is redirected to the order inside `/akun/pesanan`.

### Access an owned order

1. `auth:customer` protects the account routes.
2. The order query is always scoped to `customer_id = authenticated customer ID` and paid status.
3. The credential view uses the existing order presenter and security headers.
4. Unauthenticated users continue through `/akses-pesanan` with invoice plus Recovery Key.

## Data Model

### Customers

Extend `customers` rather than creating a second customer identity table.

- `email`: nullable string, normalized to lowercase and unique when non-null.
- `email_verified_at`: nullable timestamp.
- `remember_token`: nullable string, required by Laravel's session-auth user contract.

Change `Customer` to extend Laravel's `Authenticatable` base and use `Notifiable`. Preserve its existing `orders`, `carts`, and Telegram relationships. Do not add a password field in this phase.

Existing Telegram customers and anonymous web-checkout customers may have a null email. No backfill or forced merge is permitted.

### Customer login links

Create `customer_login_links` with:

- `id`;
- `email` (normalized lowercase, indexed);
- `token_hash` (SHA-256, unique; plaintext is never stored);
- `expires_at` (indexed);
- `used_at` (nullable);
- `requested_ip` (nullable, privacy-minimized string);
- timestamps.

Store only a SHA-256 hash of the token. The email contains the plaintext token. A consumed or expired token cannot create a session.

Do not store raw email-provider access tokens, refresh tokens, or OAuth data in this phase.

### Audit events

Use the existing audit-log mechanism to record:

- `customer.login_link_requested` without a raw token;
- `customer.login_link_consumed`;
- `order.customer_claimed`, including order and resulting customer IDs.

Never put Recovery Keys, magic-link tokens, credentials, or full email addresses into audit metadata or application logs.

## Architecture Boundaries

- **Customer authentication service**: issues and consumes magic links; owns token hashing, expiry, single-use semantics, and customer session login.
- **Customer order claim service**: owns invoice normalization, Recovery Key verification, locking, owner checks, reassignment, and audit logging.
- **Customer order access policy/query**: scopes account order lists and detail pages to the authenticated customer and paid orders.
- **Mail notification**: a queued Laravel notification/mailable that only renders and sends the magic link. It must not contain account-claim logic.
- **Account controllers**: thin request/response adapters. No token or ownership logic in controllers or Blade templates.

Keep the existing `StorefrontOrderAccess` service as the Recovery Key authority. Extract a narrowly named key-verification method from it only if both recovery access and order claim need the same operation; do not duplicate the hash check.

## Security and Anti-Spam Requirements

### Magic links

- Generate at least 32 bytes of cryptographically secure random token material.
- Store a SHA-256 one-way hash; look it up by the hash and use constant-time comparison before consuming it.
- Link validity is 15 minutes.
- Consume a token inside a DB transaction using `lockForUpdate`; set `used_at` before login.
- Regenerate the Laravel session ID after successful customer login.
- Use a route that receives only the token. Do not put email address, invoice number, or Recovery Key in the URL.
- Return generic success for link-request submission whether or not an account exists.
- Apply `Cache-Control: no-store, private` to login consumption and all account pages.

### Rate limits

Define named rate limiters in the application service provider. Initial limits:

| Action | Limit | Key |
| --- | --- | --- |
| Request magic link | 3 per 15 minutes | normalized email plus IP |
| Request magic link | 10 per hour | IP |
| Consume magic link | 10 per 15 minutes | IP |
| Claim order | 5 per 15 minutes | authenticated customer plus IP |

Limits must use the existing cache store. A CAPTCHA is not part of the first release; add it only if rate-limit metrics show abuse.

### Order claim and credentials

- Claim requires both a current authenticated customer session and invoice plus Recovery Key.
- Never allow claim by matching an email, Telegram username, buyer handle, or invoice number alone.
- Lock the `orders` row before checking owner or updating `customer_id`; this prevents two accounts from winning the same claim.
- Do not alter the original cart, payment, inventory movements, or stock-item ownership when claiming an order.
- Account access must recheck paid state on every credential request. A pending, cancelled, expired, or refunded order never reveals credentials.
- Use the same generic message for invalid key, unpaid order, and order owned by another customer to reduce information disclosure.

## Routes and UI

Suggested names and paths:

| Route | Method | Middleware | Purpose |
| --- | --- | --- | --- |
| `/akun` | GET | guest/customer | account landing and magic-link request form |
| `/akun/login-link` | POST | throttle:customer-login-link | request magic link |
| `/akun/login-link/{token}` | GET | throttle:customer-login-consume | consume magic link and sign in |
| `/akun/logout` | POST | auth:customer | sign out |
| `/akun/pesanan` | GET | auth:customer | list owned orders |
| `/akun/pesanan/{order}` | GET | auth:customer | show one owned paid order and credentials |
| `/akun/klaim-pesanan` | GET/POST | auth:customer, throttle:customer-order-claim | claim a guest order |

Account navigation should appear in the public storefront header. Checkout success and Recovery Key access success should show a non-blocking CTA to sign in or claim the order.

Do not use signed invoice URLs for the account order-detail route. Authorization comes from the customer session and order ownership. The existing signed invoice routes remain unchanged for guest checkout status pages.

## Implementation Slices

### 1. Customer auth foundation

- Add customer auth columns and `customer_login_links` migration.
- Convert `Customer` to an authenticatable, notifiable model.
- Add `customers` provider and `customer` session guard without changing the admin `web` guard.
- Add rate limiter definitions and tests.
- Add customer login/logout routes and an account landing page.

Acceptance criteria:

- Admin login and `/admin` authorization behave exactly as before.
- A valid magic link signs in a customer and sets `email_verified_at`.
- Expired, consumed, altered, or replayed links cannot authenticate.
- Link-request response does not disclose customer-account existence.

### 2. Magic-link delivery and operations

- Add a queued notification/mailable and email template. The queued notification must implement Laravel's `ShouldBeEncrypted` because its transient payload contains the plaintext magic-link token.
- Add mail configuration documentation for an external SMTP transactional-email provider.
- Add cleanup of expired/used login links using a scheduled command with bounded deletion.
- Expose safe operational counters/logs for request, send failure, and consume success/failure.

Acceptance criteria:

- HTTP request returns without waiting for SMTP.
- Queue retry does not create multiple valid tokens beyond the intended rate limit.
- Sending failure is observable and does not log the token.
- Expired token cleanup never deletes active links.

### 3. Customer order dashboard

- Implement owned-order list and order detail query scoped by authenticated customer ID.
- Reuse `StorefrontShopOrderPresenter` only after verifying it does not expose data beyond the scoped order.
- Apply no-store and no-referrer headers to account credential pages.
- Add account CTA in the storefront header and post-checkout/access views.

Acceptance criteria:

- A customer cannot enumerate or load another customer's order by route ID.
- Paid owned orders reveal the same credential data as existing Recovery Key access.
- Non-paid owned orders never reveal credentials.
- Guest invoice/status URLs continue to work exactly as before.

### 4. Guest order claim

- Implement `CustomerOrderClaimService` and request validation.
- Reuse Recovery Key validation from `StorefrontOrderAccess` rather than copying it.
- Add order lock, idempotent same-owner behavior, audit log, and generic failures.
- Add a claim-order form and CTA.

Acceptance criteria:

- Correct invoice plus Recovery Key claims one paid guest order.
- Wrong key, unpaid order, and another customer's claimed order return the same safe failure response.
- Two parallel claim attempts result in one owner only.
- After claim, the order is listed only for its new customer owner; Recovery Key access still works.

### 5. Hardening and release

- Add feature tests for every route and authorization boundary.
- Add PostgreSQL concurrency coverage for competing claims.
- Run email delivery smoke test with a real non-production inbox.
- Document customer-support recovery procedure: support cannot reveal Recovery Keys or manually attach an order without an approved future policy.
- Add operational checks for queue health, scheduler heartbeat, mail-send failures, and database backup restore.

Acceptance criteria:

- Existing storefront, Telegram, payment webhook, inventory, and admin test suites pass.
- The PostgreSQL concurrency gate passes.
- A real staging magic-link email arrives, authenticates once, and cannot be replayed.
- Rollback disables account routes/CTAs without changing guest checkout or Recovery Key access.

## Test Plan

Add focused feature tests for:

- magic-link request validation, generic response, email normalization, expiry, reuse, and session regeneration;
- all named rate limits;
- account order list/detail ownership and credential headers;
- claim success, invalid key, unpaid order, already-owned order, and idempotent repeat claim;
- simultaneous claims using the existing PostgreSQL process-concurrency harness;
- regression coverage for `/akses-pesanan` and current guest checkout.

Do not assert plaintext tokens, Recovery Keys, or credentials in logs or snapshots.

## Configuration and Operations

Use an external SMTP transactional-email provider. The VPS should only enqueue and send through Laravel's existing database queue; no extra worker type, Redis, OAuth service, or background daemon is needed.

Required production configuration, with provider-specific values kept in `.env` and GitHub secrets only:

```env
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="LHIE STORE"
```

Set `APP_URL` to the canonical HTTPS storefront URL before testing magic links. The link host must be covered by `TRUSTED_HOSTS` when that setting is enabled.

After code, configuration, route, queue, scheduler, or mail changes, refresh the active stack and verify it:

```bash
docker compose -p telegram-bot-prod --env-file .env up -d --build --remove-orphans
docker compose -p telegram-bot-prod --env-file .env ps
docker compose -p telegram-bot-prod --env-file .env exec -T app php artisan ops:health --db --queue-schema --queue-depth --supervisor
```

Deploy production only through the `Deploy production` GitHub Actions workflow from `main` after CI and mail smoke validation pass.

## Deferred Decisions

- Which SMTP transactional-email provider and verified sender domain will be used.
- Whether customers may later set a password in addition to magic links.
- Whether Google OAuth is worth adding after customer-account usage is established.
- A verified, operator-approved recovery policy for customers who lose both invoice and Recovery Key.
- Secure Telegram-to-customer-account linking. This requires a two-sided proof flow and is not safe to infer from Telegram username.

## Non-Goals

- Replacing Recovery Keys.
- Making Telegram membership a web-account requirement.
- Migrating or merging existing customers automatically by display name, username, or Telegram ID.
- Sharing orders between multiple customer accounts.
- Storing payment-provider or OAuth tokens for customer authentication.
- Changing inventory, payment, checkout, or order-state-machine behavior.
