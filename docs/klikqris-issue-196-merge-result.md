# Issue #196 — KlikQRIS integration merge result

Fixes #196.

This branch is an integration of the current `main` architecture. The legacy
`feature/klikqris-sandbox` branch was not merged. Production credentials,
production traffic, and provider cutover are outside this change.

## Legacy ten-commit audit

| Legacy commit | Decision | Result on this branch |
| --- | --- | --- |
| `68dcbbb` — record #197 sandbox evidence | Port/consolidate | The sanitized 12/12 evidence is retained by reference to [#197](https://github.com/Alhiefikri/telegram-bot/issues/197) and [the repository evidence](klikqris-sandbox-results-2026-08-08.md); no credential or raw payload was copied. |
| `c57f459` — accept shared sandbox sample QR | Port/consolidate | The sandbox qualification remains accepted as non-production evidence; exact QR media is not persisted in this branch. |
| `71e892c` — require explicit KlikQRIS selection | Superseded and drop the conflicting patch | `main` already owns `PaymentProvider`, `PaymentProviderResolver`, `PaymentProviderSwitcher`, owner authorization, and readiness. The legacy `KlikQrisSandboxCheckoutSelector` and its duplicated selection seam were deliberately not ported. |
| `074a4b1` — preserve provider amount display | Port behavior and tests | Persisted `total_amount` representation is validated against the stored integer total and rendered exactly; malformed or mismatched payloads fail closed. |
| `c0e827e` — record unknown callback statuses safely | Port/adapt | Unknown status tokens are bounded, sanitized to a status plus merchant-reference hash, idempotently recorded, acknowledged without payment transition, and exposed as a safe admin condition. |
| `cd6d3d6` — align exact amount expectations | Port tests | Sandbox validation, Telegram, and readiness expectations now cover exact provider amount representation. |
| `4dd3a93` — owner-only payment mode validation | Superseded/already implemented | Owner-only mutation and non-Owner readiness visibility are supplied by `main`'s centralized switch and were regression-tested; the legacy test-only patch was not copied. |
| `b71b988` — operational sandbox evidence | Port/consolidate | Operational evidence is referenced through #197 and summarized here without copying secrets or raw provider material. |
| `fa2daf7` — sandbox dashboard observation | Port/consolidate | The observation remains evidence for #197 only; no dashboard image or full provider reference was added to the repository. |
| `0293a04` — callback race evidence | Port/consolidate | The race result is referenced through #197; provider callback/reconciliation idempotency and exactly-once regression coverage runs on this branch. |

## Preserved invariants

- `PaymentProvider` and the centralized Payment Provider Switch are the only
  provider-selection seam for new checkouts across Telegram and Storefront.
- Only an Owner can mutate the active provider, with explicit confirmation and
  readiness validation. Non-Owners can inspect readiness.
- A provider change affects new checkouts only. Existing payments continue to
  use their persisted `Payment.provider`.
- KlikQRIS readiness failure is fail-closed. KlikQRIS does not fall back to
  Pakasir or Manual after selection or provider creation failure.
- Webhooks are signals. The authoritative KlikQRIS status response, identity,
  and exact amount checks are required before `paid`; callback, reconciliation,
  expiry, recovery, idempotency, and exactly-once fulfillment/receipt behavior
  remain intact.
- Provider payloads and admin projections exclude credentials, signatures,
  tokens, QR base64/raw payloads, and full unknown merchant references.
- Provider switching and checkout creation serialize on the same locked active
  setting row; a concurrent race creates at most one checkout/payment and
  never rewrites an existing payment's provider.
- Sandbox and production configuration/readiness remain separate. The
  production flag remains fail-closed and disabled (`KLIKQRIS_PRODUCTION_ENABLED=false`);
  Pakasir remains available and was not removed or disabled.

## Verification gates

| Gate | Result |
| --- | --- |
| Focused KlikQRIS/provider-switch regressions | PASS — 157 tests, 840 assertions |
| Issue #197 sandbox qualification evidence | PASS — all 12 scenarios recorded as PASS in the sanitized evidence |
| Full PHPUnit suite | PASS — 1,164 tests, 7,929 assertions, 21 expected skips |
| Pint formatter/linter check | PASS — `./vendor/bin/pint --test` |
| PostgreSQL fresh migration + concurrency release gate | PASS — isolated disposable PostgreSQL, 18 tests, 262 assertions, 0 skips |
| Frontend build | PASS — `npm run build` |
| Browser smoke | PASS — admin/customer suite 28 tests; customer filter 1 test |
| Dependency audit | PASS — `composer audit --locked --no-dev --abandoned=fail` |
| Whitespace check | PASS — `git diff --check` |
| GitHub Actions CI | PASS — run `31311280308`, all 6 required jobs green |

The repository has no `composer lint` script; Pint is the configured PHP
formatter/linter and was run directly. The host PHP CLI lacked `pdo_pgsql`, so
the PostgreSQL gate was rerun in the repository's PHP container against a
disposable isolated PostgreSQL database. The disposable database and network
were removed after the gate.

## Production-disabled handoff

This is an engineering merge handoff only. The disabled-production boundary is
verified by configuration/readiness tests and the CI image gate; no production
deployment was executed from this branch because production credentials,
traffic changes, and rollout authorization are explicitly out of scope. Do not
add production credentials, enable KlikQRIS production, change production
traffic, disable Pakasir, or execute [#201](https://github.com/Alhiefikri/telegram-bot/issues/201)
from this branch. #201 remains the separate human
authorization/secrets/readiness/rollout workflow and stays blocked until #196
is reviewed, merged, and deployed with the production flag still disabled.

Rollback is the previous `main` deployment (`b545afc`) with Pakasir left as the
active production rail. If this branch is merged, the deployment must preserve
the disabled KlikQRIS production flag and must not route production traffic to
KlikQRIS.
