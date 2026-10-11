# Plan implementasi Customer Web Storefront Authentication

Tanggal: 2026-10-11. Deliverable sesi ini: planning, spec, dan tiket; bukan implementasi auth atau deploy.

Canonical epic: [#607](https://github.com/Alhiefikri/telegram-bot/issues/607).

Spec: [Customer Web Authentication](../specs/2026-10-11-customer-web-account-authentication-design.md), termasuk section 12 yang menetapkan batas token, linking, mail durability, dan prerequisites.

## Baseline dan keputusan

Source saat planning sudah memiliki dedicated `customer` guard, Customer magic-link auth, Order claim via invoice/Recovery Key, owned-order history, dan read-only balance. Dokumen first-phase masih bertuliskan planning-only; source lebih authoritative. Jangan membangun ulang fitur existing.

Resend domain `notify.lhiestore.web.id` sudah verified dari dashboard pengguna dan empat record DNS telah resolve. API key dan aplikasi email delivery belum diuji. Google credentials belum diprovision. Cloudflare login bukan Resend authentication.

Target: register email/password, verifikasi email, password reset, kode email, Google login/link, Telegram proof/login/link, UI account dan kompatibilitas guest. Tetap Laravel/Blade dan Customer canonical. Screenshot memberi arah form, bukan izin menyalin brand marketku. Name opsional; WhatsApp optional support contact; public username tidak dibangun. Full storefront/catalog redesign di luar scope, surface account dan header integration masuk scope.

ADR-0001 OAuth deferral harus secara eksplisit diperluas tanpa membatalkan guest/Recovery Key. Redis session driver tetap existing sesuai ADR-0014; jangan mengganti session semua channel sebagai efek samping.

## Tiket dan urutan

Semua delapan technical tickets berlabel `ready-for-agent`. Artinya kontrak teknis dapat dikerjakan; native dependency yang open tetap memblokir eksekusi. Label bukan izin production atau bukti credentials sudah tersedia.

| Key | Issue | Deliverable | Blocked by |
| --- | --- | --- | --- |
| T1 | [#608](https://github.com/Alhiefikri/telegram-bot/issues/608) | Customer data, token/identity constraints, guard dan ADR contract | none |
| T2 | [#609](https://github.com/Alhiefikri/telegram-bot/issues/609) | Register/password/login, verification, reset dan session revocation | #608 |
| T3 | [#610](https://github.com/Alhiefikri/telegram-bot/issues/610) | OTP, Resend delivery, encrypted durable work, cleanup | #608 |
| T4 | [#612](https://github.com/Alhiefikri/telegram-bot/issues/612) | Account UI, mobile/accessibility, method states | #609, #610 |
| T5 | [#613](https://github.com/Alhiefikri/telegram-bot/issues/613) | Google OAuth, explicit link/unlink | #608, #612 |
| T6 | [#611](https://github.com/Alhiefikri/telegram-bot/issues/611) | Telegram private proof, canonical identity, login/link | #608 |
| T7 | [#614](https://github.com/Alhiefikri/telegram-bot/issues/614) | Guest/Recovery Key/Order/balance/legacy compatibility | #609, #610, #612, #613, #611 |
| T8 | [#615](https://github.com/Alhiefikri/telegram-bot/issues/615) | Integration/security/race/browser/mail/rollback evidence | #608, #609, #610, #612, #613, #611, #614 |

```text
T1 -> T2 + T3 + T6
T2 + T3 -> T4
T1 + T4 -> T5
T2 + T3 + T4 + T5 + T6 -> T7
T1..T7 -> T8
```

First runnable ticket: #608. T2/T3/T6 may proceed independently afterward, but shared routes, Customer model, controller and token primitives need one integration owner. T1 owns shared schema/interface, T2 owns password controller routes, T3 owns mail/token worker and OTP routes, T6 owns Telegram adapter. T4 integrates UI; T8 owns broad integration. No agent runs CI on its own initiative.

## Shared contract every ticket must read

1. Read the full spec, especially section 12. Its clarifications take precedence over ambiguous earlier prose.
2. Preserve admin `web` guard and nullable-email legacy Customers.
3. Never merge or migrate Customer identities, financial rows, deposit receivers or Orders by matching email/contact.
4. Telegram identity must agree with existing `customers.telegram_id`; linking a different existing Customer is rejected.
5. Order claim remains invoice plus Recovery Key, not a login identity claim.
6. Token consumption is POST, one-use, purpose-bound, timestamp-checked and atomic. OTP is six digits with keyed digest and five durable attempts.
7. Password reset invalidates other Customer sessions via auth version. Keep admin sessions untouched.
8. Secret-bearing mail work is encrypted and durable; Redis loss cannot silently lose all pending auth mail.
9. Only secret credentials use new ENV. Presentation values use current owners or DB/Admin.
10. UI state buttons are real actions. No configured provider means no misleading working button.
11. No raw credentials, API keys, auth codes/capabilities, or email PII in logs/audits/screenshots. Encrypted transient delivery payload is permitted where necessary.
12. No feature implementation, production deploy, Actions dispatch, or push is authorized by creating these tickets.

## Tiny-commit sequence

### T1: foundation

1. Confirm source and schema baseline; update the historical planning status with source-backed notes.
2. Define token purposes/identity result contracts and add additive constraints; no auth routes change yet.
3. Add Customer hidden password/auth-version fields and guard compatibility.
4. Add behavior/constraint tests and ADR/domain documentation for the new contract.

### T2: password and registration

1. Implement pending enrollment and purpose/version-bound verification without overwriting existing Customers.
2. Implement password login and session regeneration; unverified accounts cannot access verified account actions.
3. Implement reset flow, auth-version revocation and normal logout.
4. Update focused tests and run a disposable end-to-end registration/login/reset scenario.

### T3: OTP and email

1. Implement challenge issuance, keyed code digest, attempt count, cooldown and atomic consume.
2. Implement encrypted durable mail work and scheduler recovery using existing notifications worker.
3. Configure Resend transport through current Laravel mail seams; add verification/OTP/reset templates.
4. Add cleanup and safe delivery outcomes; prove provider failure/Redis loss with fixtures.
5. Perform real-inbox send only after secret/test recipient provision; otherwise keep explicit release gate open.

### T4: account UX

1. Reuse Storefront visual tokens/brand and Blade form conventions; existing logo is not replaced by reference brand.
2. Build register, login, verification, reset, OTP and fallback states around the real route contracts.
3. Add account/security/profile navigation, accessible focus, mobile layout and provider availability slots.
4. Exercise actual UI with browser and no-JS form paths. Remove incidental wording/source tests instead of re-pinning them.

### T5: Google

1. Add minimal Socialite adapter and provider configuration with exact redirect allowlist.
2. Implement subject-based sign-in/new-account creation and collision-to-explicit-link flow.
3. Add fresh-auth link/unlink policy and last-login-method guard.
4. Prove callback replay/state/races via provider fixtures; live OAuth smoke waits for operator credentials.

### T6: Telegram

1. Build five-minute browser-bound claim and private Bot explicit confirm states.
2. Authenticate existing Telegram Customer, or link an unused actor to the current Customer without conflicting `telegram_id` ownership.
3. Add conflict state and same-Customer verified email enrollment; do not merge identities.
4. Prove private actor/session binding, expiry, replay, race and wrong actor outcomes.

### T7: compatibility

1. Audit every customer-account authorization surface for canonical Customer guard usage.
2. Prove no auth identity grants arbitrary Order/credential/balance access.
3. Verify legacy Customers retain Orders and magic-link fallback; no forced password.
4. Update support/claim runbook and prove guest checkout, Recovery Key and financial reads remain unchanged.

### T8: final integration

1. Integrate affected contracts/callsites and remove dead branches made obsolete by completed cutover, not active fallback contracts.
2. Run focused suites, PostgreSQL concurrency, production frontend build and actual browser flows.
3. Prove Resend real inbox, safe error output, queue recovery and token replay through staging/disposable environments.
4. Exercise rollback to magic-link surface with additive schema retained.
5. Record evidence per acceptance gate and leave missing human prerequisites explicitly blocked. Never claim deployment readiness from fakes alone.

Each implementation commit leaves existing guest and magic-link paths usable. Avoid one cross-channel identity migration commit or destructive rollback migrations.

## Verification ownership

- T1: uniqueness/nullability/serialization/guard behavior and migration compatibility.
- T2: enrollment verification, password success/failure, reset/revoke, generic responses and CSRF.
- T3: OTP expiry/attempt/replay/resend/purpose, encrypted work, Redis loss recovery, mail status.
- T4: actual mobile/desktop surface, keyboard, error/loading states and no-JS forms.
- T5/T6: subject/actor proof and identity races under PostgreSQL, callback replay/cross-session refusal.
- T7: existing CustomerAccount/Storefront access/balance suites, legacy shape and claim races.
- T8: integrated suite/build/browser/race/mail/rollback evidence, sanitized artifacts.

Existing source evidence: `CustomerAuthenticationService`, `CustomerAccountController`, `CustomerOrderClaimService`, `CustomerOwnedOrderQuery`, `CustomerAccountTest`, `ApplicationRateLimiterRegistrar`, and the existing Customer account migration. Test exact consumer-visible boundaries, not helper names, copied labels or forwarding mocks.

## Operator prerequisites, not fake agent readiness

- Resend sending-only API key restricted to verified sender and a consented test inbox.
- Google OAuth client ID/secret, consent screen/domain setup, exact staging/production redirects and publishing status.
- Actual Store Bot confirmation and web browser proof for Telegram staged rollout.
- Accepted privacy/terms content if account UI displays legal consent; use existing content, never invent legal approval.
- Explicit admin request for any production deployment/CI operation.

Agents can implement and verify local behavior with isolated fixtures while those prerequisites are unresolved. T8 cannot close the release gate without real delivery/provider evidence. Parent #607 stays open until required technical evidence and operator readiness are established.

## Epic checklist

- [ ] #608 T1: Canonical data/ADR contract
- [ ] #609 T2: Register/password/verification/reset/login
- [ ] #610 T3: OTP/Resend/durable delivery
- [ ] #612 T4: Account UI
- [ ] #613 T5: Google OAuth
- [ ] #611 T6: Telegram proof
- [ ] #614 T7: Compatibility
- [ ] #615 T8: Security and release evidence

GitHub native sub-issues and blocked-by edges are the live ordering gate; this document is a versionable snapshot. Existing historical spec/plan remains preserved rather than rewritten as already shipped. No code, secrets, runtime or production config is changed by this planning document.
