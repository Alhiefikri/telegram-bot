# Customer Web Account Operations

Production must set `APP_URL` to the canonical HTTPS storefront URL and configure a transactional SMTP provider through deployment secrets. Resend uses `MAIL_MAILER=smtp`, `MAIL_SCHEME=smtp`, `MAIL_HOST=smtp.resend.com`, port `587`, username `resend`, and a sending-only API key as `MAIL_PASSWORD`; Laravel/Symfony negotiates STARTTLS on that SMTP transport. `tls` is not a transport scheme. Verify the existing `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME` owners before enabling customer-facing email. Do not put SMTP credentials in the repository or diagnostics.

Guest checkout email is optional and remains order-scoped; it never populates or merges the anonymous `Customer`. The `notifications` queue worker delivers order-created, payment-confirmed, fulfillment-completed, expired, and cancelled milestone messages. Confirm that the `notifications` pool is present in `supervisord.conf` and that `ops:health --db --queue-schema --queue-depth` reports the `notifications` queue.

Guest-order mail contains only the invoice number, current status, non-secret item summary, relevant payment deadline, a signed invoice/status URL, and the `/akses-pesanan` CTA. The invoice/status URL does not grant credential access; the existing order access proof remains required.

The scheduled command `customers:cleanup-login-links --chunk=500` removes only consumed or expired links. Queue workers should be monitored using the existing queue health check. Login-link and guest-order notification failures are observable through failed jobs and the `customer.login_link_requested` / `customer.login_link_consumed` audit actions; tokens, Recovery Keys, credentials, and email addresses are not logged.

Support must not reveal Recovery Keys or attach an order manually. Customers recover a guest order with the invoice and Recovery Key at `/akun/klaim-pesanan`.

## Password authentication (#609)

`POST /akun/register` creates a pending Customer only for an unused normalized email. Repeated registration never changes an existing Customer's password, profile or ownership. Pending credentials live in a version-bound verification challenge for 30 minutes; email verification installs them only after an explicit `POST /akun/verifikasi-email`. The email link uses a fragment capability. Its GET displays a confirmation form and consumes nothing. Without JavaScript, the Customer can copy the 64 characters after `#token=` into that form.

`POST /akun/login` accepts verified email/password accounts. Passwords are 12–128 characters at enrollment/reset, retain spaces and case, and permit password managers. `CustomerPasswordHasher` stores a domain-separated SHA-384/base64 prehash through Laravel's configured slow hasher, with hidden `password_scheme = sha384-v1`; this preserves the full password beyond bcrypt's 72-byte limit. Existing raw hashes remain compatible for safe-length passwords and migrate on successful login. Admin password hashing is unchanged.

Existing verified magic-link Customers can set their first password at `/akun/keamanan` within five minutes of fresh authentication. Anonymous duplicate registration cannot complete or overwrite that account. Password changes use the reset flow, not this first-password enrollment form.

`POST /akun/password-reset` returns generic guidance. Its email opens `/akun/password-reset/confirm`; only the CSRF-protected POST changes the password. Success increments the Customer authentication/enrollment versions, invalidates outstanding verification/reset/OTP/Telegram challenges and legacy login links for that Customer/email, and logs the current Customer browser out. Other Customer sessions fail on their next account request. Customer login, verification, enrollment, reset and logout rotate the session and destroy the previous session ID while preserving unrelated admin session data. Orders, Recovery Keys, balances and Telegram identity are not moved or edited.

Registration/reset share a 60-second email cooldown, three requests per email per 15 minutes and ten per IP per hour. Password login allows five failures per email/IP pair and 30 per IP per 15 minutes. Cache keys use HMAC identifiers; unavailable rate storage fails closed with generic outcomes.

## Verification/reset mail work

The additive `customer_auth_mail_works` table stores only encrypted transient recipient/capability payloads. Tokens and mail work commit together. The existing `notifications` queue carries `SendCustomerAuthMail` work IDs only. Sending uses Laravel's notification/mail seam outside database locks; a 120-second attempt-fenced lease survives a worker crash. Delivery retries reuse the same challenge, never mint another grant.

`customer-auth:recover-mail --limit=100` is scheduled every minute. It redispatches due unexpired work after lost Redis wakeups, cancels expired work, clears terminal ciphertext and removes terminal mail work older than one day in bounded pages. Failed delivery logs only a work ID and safe outcome category; the existing failed-job record has an ID-only job and a sanitized exception. Pending/failed status and `last_error_code` are available in the database without decrypting recipient/capability payloads.

Before live mail qualification, provision the Resend sending-only key and an approved non-production inbox through existing secret owners. Disable open/click tracking on the Resend sender domain. A local array/fake transport, verified DNS, or successful queue recovery is not evidence of actual inbox delivery. Real Resend delivery and deployment readiness remain gated by #610/#615 and operator approval; #609 authorizes no deployment or GitHub Actions inspection.

Local evidence: `CustomerPasswordAuthenticationTest` covers enrollment, generic failures, real-cookie session rotation/destruction, version revocation, token expiry/replay/purpose, guard isolation, long/Unicode passwords and durable wakeup recovery. `CustomerPasswordAuthenticationPostgresTest` covers competing registrations and three-process verification/reset consumption on PostgreSQL 16. Disposable browser checks exercise registration, inert GETs, explicit confirmation, password login/reset/replay/logout, legacy first-password enrollment, no-JavaScript submission, keyboard focus, mobile/tablet/desktop and light/dark layouts.
