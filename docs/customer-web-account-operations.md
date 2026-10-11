# Customer Web Account Operations

Production must set `APP_URL` to the canonical HTTPS storefront URL and configure transactional SMTP through the existing VPS-local `.env` secret owner. Resend uses `MAIL_MAILER=smtp`, `MAIL_SCHEME=smtp`, `MAIL_HOST=smtp.resend.com`, `MAIL_PORT=587`, `MAIL_USERNAME=resend`, and a sending-only API key restricted to `notify.lhiestore.web.id` as `MAIL_PASSWORD`. The installed Laravel/Symfony transport enables automatic STARTTLS and requires TLS for this host; `tls` is not a transport scheme. The sender address is `no-reply@notify.lhiestore.web.id`; branding remains with the existing `MAIL_FROM_NAME` owner. Compose forwards these existing settings to app, scheduler and workers. Keep secrets out of source, command arguments, resolved Compose output and diagnostics. See [Resend SMTP settings](https://resend.com/docs/send-with-smtp).

Guest checkout email is optional and remains order-scoped; it never populates or merges the anonymous `Customer`. The `notifications` queue worker delivers order-created, payment-confirmed, fulfillment-completed, expired, and cancelled milestone messages. Confirm that the `notifications` pool is present in `supervisord.conf` and that `ops:health --db --queue-schema --queue-depth` reports the `notifications` queue.

Guest-order mail contains only the invoice number, current status, non-secret item summary, relevant payment deadline, a signed invoice/status URL, and the `/akses-pesanan` CTA. The invoice/status URL does not grant credential access; the existing order access proof remains required.

`customer-auth:cleanup --limit=500` runs hourly and removes one bounded page per table: consumed/expired shared auth tokens, used/expired legacy links, consumed/failed/expired Telegram claims, and consumed/expired Google claims, including exact expiry (`expires_at <= now()`). Limits must be between 1 and 1000. It preserves live challenges and every token/link still referenced by mail work. Mail recovery owns ciphertext cleanup and one-day terminal-work retention; parent cleanup follows after recovery removes work. Restrictive FKs and row locks protect concurrent work. `customers:cleanup-login-links --chunk=500` remains a bounded legacy-only delegate and does not remove shared tokens or provider claims. Queue failures remain observable through existing failed-job checks and safe mail-health counts. Audit metadata must not contain email, authentication capabilities, Recovery Keys or credentials.

Support must not reveal Recovery Keys or attach an order manually. Customers recover a guest order with the invoice and Recovery Key at `/akun/klaim-pesanan`.

## Password authentication (#609)

`POST /akun/register` creates a pending Customer only for an unused normalized email. Repeated registration never changes an existing Customer's password, profile or ownership. Pending credentials live in a version-bound verification challenge for 30 minutes; email verification installs them only after an explicit `POST /akun/verifikasi-email`. The email link uses a fragment capability. Its GET displays a confirmation form and consumes nothing. Without JavaScript, the Customer can copy the 64 characters after `#token=` into that form.

`POST /akun/login` accepts verified email/password accounts. Passwords are 12–128 characters at enrollment/reset, retain spaces and case, and permit password managers. `CustomerPasswordHasher` stores a domain-separated SHA-384/base64 prehash through Laravel's configured slow hasher, with hidden `password_scheme = sha384-v1`; this preserves the full password beyond bcrypt's 72-byte limit. Existing raw hashes remain compatible for safe-length passwords and migrate on successful login. Admin password hashing is unchanged.

Existing verified magic-link Customers can set their first password at `/akun/keamanan` within five minutes of fresh authentication. Anonymous duplicate registration cannot complete or overwrite that account. Password changes use the reset flow, not this first-password enrollment form.

`POST /akun/password-reset` returns generic guidance. Its email opens `/akun/password-reset/confirm`; only the CSRF-protected POST changes the password. Success increments the Customer authentication/enrollment versions, invalidates outstanding verification/reset/OTP/Telegram challenges and legacy login links for that Customer/email, and logs the current Customer browser out. Other Customer sessions fail on their next account request. Customer login, verification, enrollment, reset and logout rotate the session and destroy the previous session ID while preserving unrelated admin session data. Orders, Recovery Keys, balances and Telegram identity are not moved or edited.

Registration/reset share a 60-second email cooldown, three requests per email per 15 minutes and ten per IP per hour. Password login allows five failures per email/IP pair and 30 per IP per 15 minutes. Cache keys use HMAC identifiers; unavailable rate storage fails closed with generic outcomes.

## Durable authentication mail work

The additive `customer_auth_mail_works` table stores only encrypted transient recipient/capability payloads. Shared verification/reset/OTP challenges use `auth_token_id`; retained magic links use `login_link_id`, with exactly one binding per work item and restrictive FKs. Tokens and mail work commit together. The existing `notifications` queue carries `SendCustomerAuthMail` work IDs only. Sending uses Laravel's notification/mail seam outside database locks; a 120-second attempt-fenced lease survives a worker crash. Delivery retries reuse the same challenge, never mint another grant.

`customer-auth:recover-mail --limit=100` is scheduled every minute. It redispatches due unexpired work after lost Redis wakeups, cancels expired work, clears terminal ciphertext and removes terminal mail work older than one day in bounded pages. Failed delivery logs only a work ID and safe outcome category; the existing failed-job record has an ID-only job and a sanitized exception. Pending/failed status and `last_error_code` are available in the database without decrypting recipient/capability payloads.

Before live qualification, provision the Resend sending-only domain-restricted key and approve a real non-production inbox privately. Disable both open and click tracking for `notify.lhiestore.web.id` in the Resend domain settings; authentication links must not be rewritten or receive tracking pixels. This is a provider setting, not a new presentation ENV. See [Resend verified-domain tracking](https://resend.com/docs/dashboard/domains/introduction). A local array/fake transport, verified DNS, or successful queue recovery is not evidence of inbox delivery. The key and approved inbox have not been supplied for #610; real Resend delivery and deployment readiness remain **unverified**, gated by #610/#615 and operator approval. No deployment or GitHub Actions operation is authorized by implementation.

Local evidence: `CustomerPasswordAuthenticationTest` covers enrollment, generic failures, real-cookie session rotation/destruction, version revocation, token expiry/replay/purpose, guard isolation, long/Unicode passwords and durable wakeup recovery. `CustomerPasswordAuthenticationPostgresTest` covers competing registrations and three-process verification/reset consumption on PostgreSQL 16. Disposable browser checks exercise registration, inert GETs, explicit confirmation, password login/reset/replay/logout, legacy first-password enrollment, no-JavaScript submission, keyboard focus, mobile/tablet/desktop and light/dark layouts.

## Email code contract (#610)

`POST /akun/login-code` requests a six-digit code (leading zeros allowed) for an existing verified Customer, returns generic guidance, and stores the normalized `customer_otp_email` and opaque 64-hex `customer_otp_challenge` in the browser session. A cooldown response preserves both session values. `POST /akun/login-code/consume` accepts `email`, `challenge` and `code`, is CSRF-protected, and checks the originating browser binding. The #612 account code form submits those session bindings without placing them in URLs.

Codes expire at ten minutes, are single-use and purpose-bound, and use an application-keyed digest rather than an enumerable plain code hash. Five incorrect attempts exhaust the durable challenge, including competing requests. Resend invalidates the preceding challenge atomically. Shared email delivery limits are a 60-second cooldown, three requests per normalized email per 15 minutes across IPs, and ten per IP per hour. OTP consume also limits IP traffic to 30 per 15 minutes. Cache identifiers are keyed, not plaintext email; unavailable rate storage fails closed. Customer-first row locks enforce the durable cooldown and attempt count even after Redis loss.

Successful consumption clears OTP session state and grants a fresh verified Customer session for account recovery. It does not change an existing password. Changing an existing password still requires the separate `password_reset` purpose and reset-version revocation contract. Unknown-email requests do not create Customers. Admin authentication, magic-link fallback, guest checkout and Recovery Keys are unchanged.

## Safe mail diagnostics and deployment smoke

`php artisan customer-auth:mail-health --limit=1000` emits JSON counts for due/deferred/expired-pending work, transport-accepted (`sent`), cancelled/failed work and all `notifications` failed jobs. Each count includes `capped`; it is a lower bound when capped, not a full backlog estimate. The command never decrypts payloads or contacts Resend and reports provider delivery as unverified. Failed/expired-pending work or notifications failed jobs yield a nonzero exit for operator attention. Use `ops:health --db --queue-depth --supervisor` alongside it to check worker runtime. Do not dump failed-job payloads or provider exception bodies.

After explicit approval, use a **non-production** deployed app with `APP_ENV=staging` (or an isolated local/testing environment), the real SMTP configuration above, TLS and the approved sender:

```bash
php artisan customer-auth:smoke-mail --confirm-real-send --confirm-tracking-disabled
```

The command asks for the approved inbox in a hidden prompt. It refuses production/unknown environments, incomplete configuration, log/array/failover transports, missing consent and unconfirmed tracking settings. It sends a token-free deployment probe directly through Laravel SMTP with a safe correlation UUID. SMTP acceptance is reported only as acceptance, never delivered mail. It prints neither inbox nor credentials and suppresses raw provider errors.

For noninteractive execution, add `--inbox-stdin` and supply the approved inbox through a private stdin stream from the existing secret manager; do not put the address in command arguments, shell history or deployment logs. There is deliberately no recipient ENV or `--to` argument. Before recording qualification, the operator must privately confirm inbox receipt, verified sender, DKIM/SPF results and absence of tracking/re-written links, then exercise the queued OTP/verification/reset and legacy flows using consented test data. Record only correlation, time and sanitized outcomes, never codes or capabilities. A probe alone does not prove queue recovery or successful authentication.

## Local verification for #610

The focused OTP/mail/maintenance/operations suites and existing password/account/balance/guest-checkout/Recovery Key/admin regressions passed: 162 tests, 1872 assertions. The isolated PostgreSQL 16 auth lane passed: 13 tests, 91 assertions, including competing OTP issuance, single-winner consumption and six concurrent incorrect attempts capped at five.

A disposable application run exercised the actual sync notification job and rendered array email, erased the encrypted delivery payload, authenticated one Customer with session rotation, rejected replay, and removed the consumed token after terminal mail retention. PHP formatting, OTP language-server diagnostics and secret-free Compose configuration validation passed. No browser surface changed in this slice; #612 owns OTP forms. None of this proves Resend inbox delivery. The real-mail gate remains open until the operator provisions the sending-only key and approved inbox and confirms receipt.

## Telegram two-sided proof (#611)

`CustomerTelegramAuthenticationService` owns five-minute browser claims in `customer_telegram_auth_claims`. `POST /akun/telegram/login` starts an anonymous claim; `POST /akun/telegram/link` requires a fresh authenticated Customer session within five minutes. Both return a private Store Bot deep link and `expires_in: 300`. Readiness uses the existing Telegram token and the strict HTTPS `t.me` username in `storefront.links.telegram_bot_url`; no new presentation configuration is introduced.

The `/start wa_<opaque capability>` message only prepares the private actor and sends an explicit confirmation button. `wa_confirm:<independent capability>` confirms once, but changes neither Customer identity nor browser authentication. `GET /akun/telegram/status` returns only `pending`, `confirmed`, `conflict`, `expired`, `invalid` or `unavailable`. `POST /akun/telegram/consume` atomically links or signs in once. Web routes use session state, not submitted Telegram IDs or capabilities; mutations require CSRF. Responses use `no-store, private` and `no-referrer`.

Claims bind the browser session ID, random browser nonce, operation, Customer and authentication version. Link freshness is checked again at confirmation and consumption. Customer-first row locks, a uniqueness savepoint and provider/canonical constraints allow one winner without moving Orders, balances or receivers. Linking requires an unused actor and an empty target Telegram slot. A legacy registered Telegram Customer can sign in and materialize its matching provider identity; an unknown actor cannot create an account through anonymous sign-in. A foreign canonical owner or occupied identity returns a generic conflict, never automatic reassignment. Telegram unlink is unavailable.

`TelegramUpdateProcessor` intercepts these reserved messages/callbacks before `TelegramCustomerResolver`, so an unused linking actor cannot be auto-registered into a competing Customer. Only a validated webhook actor with `is_bot: false`, explicit private chat and equal positive actor/chat IDs supplies proof. Groups, usernames, names, phone numbers and channel membership do not supply authority. Authentication update envelopes are encrypted in the existing update payload column; jobs retain update IDs. Claim capabilities are hashed, and provider error descriptions/logging redact both payload forms. Do not capture deep links or confirmation buttons in logs, screenshots or support tickets.

Start allows ten operations per session/IP pair per 15 minutes; status allows 120, browser consumption 30, and private preparation/confirmation share 30 per actor in that window. Keys use application-key HMAC identifiers. Missing rate storage or Bot configuration fails closed. Bot delivery failure leaves the browser unauthenticated; reopening the original deep link rotates the pending confirmation button without extending expiry.

A fresh Telegram-authenticated Customer without verified email can submit an unused email to `POST /akun/telegram/email`. The existing encrypted durable verification mail binds that same Customer, email, enrollment/authentication versions and originating browser. `POST /akun/verifikasi-email` verifies the email without installing a password or creating another Customer. Password enrollment can then use the existing account-security flow. Existing-email collision never merges accounts.

Local verification uses `CustomerTelegramAuthenticationTest`, `Telegram/WebAuthenticationWebhookTest` and `CustomerTelegramAuthenticationPostgresTest`. PostgreSQL 16 races prove one-winner consume, competing Customers for one actor and competing actors for one Customer. A disposable in-memory runtime exercised actual ingress, sync processor and Bot API adapter with isolated HTTP responses: explicit button confirmation, separate browser consume, canonical linking, replay rejection, encrypted update retention, sign-in and bounded cleanup. Account form integration remains #612; live private Store Bot/browser qualification remains an operator-gated rollout check. No production deployment or GitHub Actions operation is authorized.

Verification on 2026-10-11: the focused Telegram/authentication/maintenance and account, balance, guest-checkout, Recovery Key and admin compatibility run passed 232 tests with 2,656 assertions. The isolated PostgreSQL 16 lane passed three process-level races. Bot HTTP responses were disposable fixtures, not live Telegram evidence. An initial runtime attempt used the configured local SQLite connection during migration and failed at an existing-email collision; the corrected runtime purged that connection and asserted an in-memory database before migrating.

## Customer account surface (#612)

`/akun` defaults to password login. Native GET links select registration, email code, reset request, retained magic link and verification guidance through the allowlisted `view` query. These forms use the existing CSRF-protected server routes; JavaScript is only progressive enhancement for password visibility, submission feedback and Telegram proof. Registration failures return to the registration form, while generic OTP request/failure responses retain the code-entry state for both known and unknown email addresses.

Name is optional and belongs to `Customer.first_name`. Optional Support Contact (WhatsApp) remains checkout-scoped under the existing Order contact owner. The registration guidance explicitly explains that it is optional, is not a login method and cannot prove Customer or Order ownership. No public username or second contact authority is introduced.

Verification, reset and magic-link GETs show confirmation forms and consume nothing. With JavaScript, fragment capabilities are copied into hidden POST inputs and removed from the current URL. Without JavaScript, Customers can paste the 64 characters after `#token=` into the labeled confirmation input. Magic links no longer auto-submit on page load. Expired/invalid links and codes return generic recovery guidance. Account throttle responses retain HTTP 429 and `Retry-After`, provide a usable return link and disclose no account existence. Customer account responses emit `no-store, private` and `no-referrer`.

Orders, balance, guest-order claim and account security share native account navigation. The Storefront header distinguishes guest access from an authenticated Customer account. Existing guest checkout and `/akses-pesanan` remain independent of login.

Google controls appear only when the #613 adapter has credentials and an exact HTTPS callback origin. Telegram is rendered only when the existing Bot readiness contract passes. Its browser enhancement starts the real CSRF-protected claim, opens the private Store Bot and checks confirmation only on Customer request, then consumes the proof separately. Without JavaScript, the Telegram action is hidden and the email methods remain usable. Pending, expired, conflict and unavailable outcomes never merge accounts.

Account forms retain Storefront fonts and paper/blue identity, use one bounded reading column and reserve blue for submission. Native state links make every email form reachable without JavaScript. Account-scoped input borders, focus outlines and support text are legible in both themes; controls have at least 44px targets. Dials: ENERGY 1 / RHYTHM 1 / MOTION 1. No new assets, presentation ENV or frontend framework are introduced.

Verification entry points: `CustomerAccountTest`, the existing password/OTP/account/balance/guest/Recovery Key/admin suites and `npx playwright test tests/Browser/customer-account.spec.js --workers=1`. Browser fixtures refuse non-testing or non-disposable SQLite databases; decrypted mail grants are read into process memory, never emitted by the test reporter. Local browser evidence does not qualify live Resend delivery, Google OAuth or private Store Bot rollout. Those operator prerequisites and production/Actions authorization remain unchanged.

Local #612 verification on 2026-10-11: the focused account/password/OTP/Telegram/balance/guest/Recovery Key/admin run passed 143 tests with 1,966 assertions. The production asset build passed. Four disposable browser scenarios passed registration and explicit verification, password failure/success, OTP failure/success, reset, retained magic-link confirmation, logout, account navigation, 320/768/1280px layouts, dark mode, keyboard focus, 200% text sizing and no-JavaScript forms/expired verification. Separate interactive inspection confirmed visible loading with duplicate submission disabled, configured Telegram claim creation/pending checks and its no-JavaScript email fallback. Telegram configuration was disposable; no live Bot or Resend delivery was claimed.

## Google authentication and explicit linking (#613)

`GoogleOAuthProvider` is the narrow equivalent adapter: authorization-code exchange at Google's fixed token endpoint, then HTTPS v3 userinfo with a nonempty subject, valid normalized email and strict boolean `email_verified: true`. It does not consume an ID token or use OIDC nonce validation. OAuth access, refresh and ID tokens stay transient; no provider token enters identity records, queues or audit metadata. Responses are memory-bounded to 16 KiB, redirects are refused and provider exceptions are sanitized. See [Google web-server OAuth](https://developers.google.com/identity/protocols/oauth2/web-server) and [userinfo](https://developers.google.com/identity/openid-connect/openid-connect#obtaininguserprofileinformation).

`GET /auth/google/redirect` starts subject-based login. Only the registration screen's explicit `operation=register` can create a verified Customer for an unused email. An existing subject signs in its bound Customer even if Google's verified email changed; the canonical Customer email is not overwritten. A matching email without that subject returns guidance to authenticate with the existing account and explicitly link. It never authenticates, merges accounts or transfers Orders, balances or receivers.

`customer_google_auth_claims` holds hashed, five-minute, browser-bound one-use state plus operation, Customer/version/fresh-proof and keyed configuration bindings. Callback consumption commits before network IO, including failed exchanges. Reset-version changes, browser changes, expired proof, configuration revocation and replay fail closed. Customer and identity uniqueness are resolved through Customer-first locks and a savepoint. Guard/session mutation happens only after the ownership transaction commits.

`POST /akun/google/link` and `POST /akun/google/unlink` require CSRF, the current Customer/version and authentication less than five minutes old. Linking rejects a foreign subject or occupied provider slot. Unlinking requires another verified login method: verified canonical email for the existing email methods, or the exact canonical Telegram identity. Both rotate the session without extending the original proof timestamp. Nullable-email Google sessions carry the exact persisted identity ID; generic authentication/logout clears it, and removing that identity invalidates its authority. Unlink to a canonical Telegram alternative retains usable Customer access.

Starts, callbacks and unlink each allow ten operations per session/IP pair per 15 minutes through the existing HMAC-keyed limiter; missing storage fails closed. Callback responses are private/no-store/no-referrer. The controller removes callback query capabilities before Laravel records previous-URL session history. The exact Nginx callback location disables access/error request logging while preserving the query only for PHP dispatch. External reverse proxies must likewise redact callback query strings; do not capture OAuth URLs in support tickets or diagnostics.

Provision `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` privately through the existing secret owner. Compose forwards these optional credentials. `APP_URL` must be an HTTPS origin without credentials, query, fragment or non-root path; the sole callback is `APP_URL/auth/google/callback`, including an explicit origin port if configured. Register that exact URI in the Google console separately for each environment. There is no arbitrary redirect ENV. Missing credentials hide sign-in/register/link/unlink actions while retaining honest availability/status guidance. Rotation changes the configuration fingerprint and invalidates pending callbacks.

Local verification: 21 Google feature tests passed (463 assertions), 151 existing authentication/account/balance/guest/Recovery Key/admin compatibility tests passed (2,018 assertions), and three PostgreSQL 16 process races passed (31 assertions): competing Customers for one subject, competing subjects for one Customer slot, and three callbacks consuming one state. The production asset build and four retained browser scenarios passed. A disposable interactive application exercised Google registration, unknown-subject login refusal, explicit link/unlink, unavailable controls and bounded cleanup; mobile/tablet/desktop, light/dark, 200% text and visible focus checks passed. New control text contrast ratios range from 6.36:1 to 15.70:1, with 44px targets. Provider responses were fixtures, not live Google evidence.

Live OAuth remains unverified until the operator provisions credentials, consent-screen/domain configuration and exact staging/production redirects, then approves the live browser smoke. Implementation does not authorize production deployment, GitHub Actions operations or closing the #607 release gate.

## Legacy rollout and ownership compatibility (#614)

This slice requires no backfill or destructive migration. Retain the additive authentication schema and all existing Customer IDs. Deploying the code does not authorize production rollout or qualify live providers.

| Existing Customer shape | Supported transition | Ownership retained |
| --- | --- | --- |
| Verified email, no password (magic link) | Continue using the magic link or email code. Fresh login permits first-password enrollment at `/akun/keamanan`. Duplicate registration cannot enroll this Customer. | Same Customer, Orders, IDR/USDT accounts and Recovery Keys. |
| Telegram-only, nullable email | Private Store Bot confirmation followed by browser consumption signs in the exact canonical Telegram Customer. Fresh Telegram proof can start unused-email verification on that row. | No second Customer, identity reassignment, Order or balance transfer. |
| Telegram with unverified email | Telegram proof remains usable. Verify an unused email through the same-Customer enrollment flow before using email methods. | The email attribute alone supplies no account proof. |
| Anonymous web Customer, no account identity | Continue guest checkout and invoice/Recovery Key access. Sign in separately and claim an eligible paid guest Order with both proofs. | Claim moves only that Order; it does not merge Customers or transfer their balances. |
| Nullable-email Customer with a persisted Google identity | Sign in through that exact provider subject. Provider email may differ from the canonical email and is not linking authority. | Existing Orders remain account-owned and cannot be claimed by someone else holding their Recovery Key. |
| Historical nullable-email row with a password or email verification timestamp | Preserve the row and its Orders. Null email is not sufficient to classify the owner as a guest. | No speculative email backfill or automatic merge. Escalate unusable account access without rewriting ownership. |

`CustomerOrderClaimService` requires invoice plus Recovery Key and paid status, even for a repeated claim by the same Customer. An account-owned Order cannot be transferred. A guest owner has no email, canonical Telegram ID, password, email verification timestamp or persisted provider identity. Both Customer rows lock in ascending ID order before the Order; enrollment and linking use the same Customer-first boundary. A changed owner is rechecked under the Order lock. Concurrent competing claims produce one owner and one audit event; repeated same-owner claims are idempotent. The original Recovery Key hash and session access remain valid after claim.

Order history/detail/status and both balance projections/activity cursors use the canonical `customer` session. Submitted Customer IDs, provider subjects, Telegram IDs, emails and support contacts cannot select another account. `AuditLogger` resolves implicit admin actors through `web`, never the active Customer guard; Customer login must not break signed invoice/status or checkout audit writes.

### Support handling and account security copy

Use these responses without requesting private proof material:

- Account methods: “Metode masuk membuka akun pelanggan yang sama. Email Google, nama, Telegram, atau WhatsApp tidak otomatis menggabungkan akun, pesanan, atau saldo.”
- Guest claim: “Untuk menghubungkan pesanan guest yang sudah dibayar, masuk ke akun tujuan lalu masukkan invoice dan Recovery Key di halaman Klaim pesanan. Invoice atau kontak saja tidak cukup.”
- Secret handling: “Jangan kirim Recovery Key, password, kode email, link masuk, atau data akun produk ke support. Support tidak dapat menampilkan ulang Recovery Key atau memindahkan pesanan ke akun lain.”
- Identity conflict: “Identitas tersebut tidak dapat dihubungkan ke akun ini. Gunakan metode masuk akun yang sebelumnya Anda gunakan. Kami tidak menggabungkan akun atau memindahkan saldo.”
- Lost key: “Recovery Key hanya ditampilkan sekali. Periksa penyimpanan pribadi Anda. Kami tidak dapat mengirim ulang atau membuat penggantinya.”

For an account or claim report:

1. Ask only for the invoice, affected route, approximate time and generic outcome. Do not ask for codes, keys, provider subjects, callback/deep-link URLs or credential screenshots.
2. Direct account recovery to the existing email methods or private Telegram proof. Direct guest recovery to `/akses-pesanan`; a Customer who wants account ownership uses `/akun/klaim-pesanan` themselves.
3. A signed invoice/status URL, matching email, OAuth email, name, Telegram profile or Optional Support Contact does not authorize attachment. Do not change `orders.customer_id`, Customer identity bindings, receivers or balance rows manually.
4. Review sanitized outcome categories and safe internal row IDs only. A conflict or invalid proof must not disclose the other Customer or their Order/balance.
5. Escalate a missing key or conflicting identity as an unresolved access case. There is no support override, Recovery Key regeneration or account merge in this release.

### Rollout and rollback checks

Before exposing a newly configured provider, exercise an existing magic-link Customer without enrolling a password, first-password enrollment on the same Customer, Telegram-only and nullable-email Customers, anonymous guest purchase, signed invoice/status, invoice/Recovery Key access before and after claim, foreign Order denial and both balance reads. Compare Customer/Order/balance ownership, not provider payload IDs. Use disposable fixtures; never submit a real Customer's secrets to diagnostics.

Rollback restores the retained magic-link surface while keeping additive tables and authentication versions. Do not drop provider identities, token/mail history or Recovery Key hashes. No existing Customer must enroll a password to retain guest or magic-link access. Resend inbox delivery, live Google OAuth and private Store Bot/browser qualification remain operator-gated under #615; local fixtures are not release evidence for those providers.

Focused commands:

```bash
cd src
php artisan test --compact tests/Feature/CustomerAccountTest.php tests/Feature/CustomerAuthenticationCompatibilityTest.php tests/Feature/CustomerOrderClaimCompatibilityTest.php tests/Feature/CustomerBalanceWebTest.php tests/Feature/StorefrontOrderAccessTest.php tests/Feature/StorefrontShopCheckoutTest.php tests/Feature/StorefrontShopOrderInvoiceTest.php tests/Feature/StorefrontShopSignedInvoiceModuleTest.php
RUN_CONCURRENCY_TESTS=1 php vendor/bin/phpunit -c phpunit.postgres.xml tests/Feature/CustomerOrderClaimPostgresTest.php
```

The PostgreSQL command requires `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` supplied privately for an isolated testing database with an empty `DB_URL`. The harness refuses production/non-test database names. The claim suite is included in the existing PostgreSQL release integration list; running it locally is not authorization to inspect or dispatch GitHub Actions.

Local verification: 341 focused authentication, Customer account/balance, guest checkout, signed invoice, Recovery Key, ledger, warranty, inventory audit and admin tests passed with 4,460 assertions. Fifteen PostgreSQL 16 authentication/claim races passed with 147 assertions, including mixed email/Telegram/nullable-Google competing claims, same-owner replay and enrollment committing before claim. A disposable HTTP application and Chromium session proved password login, canonical IDR balance despite a foreign Customer-ID query, nullable-Google Order claim refusal, valid paid guest claim, credential display, logout and retained Recovery Key access with HTTP 200, `no-store` and `no-referrer`. Existing account layout and security copy remain unchanged.

