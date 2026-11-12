# P27 visual/browser parity and public API documentation (#709)

**Status: API contract tests, disposable PostgreSQL harness, and local 390px Chromium checks pass. Real Android/iOS/Telegram owner review remains pending.**

All evidence is local and synthetic. No commit, push, deployment, CI/CD run, production access, customer data, provider call, purchase, or live Telegram message was used.

## Implementation

- Added a bilingual Django API reference, OpenAPI 3.1 JSON, Markdown reference, `llms.txt`, `robots.txt`, and sitemap entries. The named Django routes and `reverse()` calls keep public links tied to the URL configuration.
- The reference is generated from the Python Customer API operation and schema definitions. It documents the seven dispatched operations only: `GET /api/v1/balance`, `GET /api/v1/products`, `GET /api/v1/products/{id}`, `POST /api/v1/orders`, `GET /api/v1/orders`, `GET /api/v1/orders/{id}`, and `GET /api/v1/orders/{id}/delivery`. The OpenAPI server base includes `/api/v1`; examples use synthetic values.
- Kept API documentation public while `robots.txt` disallows `/api/`. The sitemap and `llms.txt` link to the documentation. No PHP-only API operation is exposed by the Python contract.
- Long inline error-envelope JSON wraps at narrow widths. The API documentation navigation and machine-file links retain 44px minimum targets.
- Admin navigation labels remain on one line in the mobile drawer. Keyboard focus scrolls the drawer rather than the document; Escape closes the dialog and restores focus to its trigger.
- No catalog presentation values or product icons were moved to environment variables. Product icon configuration remains DB-backed.

## Reused phase evidence

These artifacts are the P21-P26 inputs used for the P27 comparison. Hashes identify the exact local files.

| Phase | Artifact | SHA-256 | Evidence and limit |
| --- | --- | --- | --- |
| P21 | `.local/migration-p21/verification.json` | `e0d251d3f366731003b93671ed5d663c1514e2aa91a509bf89254f7f7a78b3c7` | Its `browser-finished` entry is stale. It is not completed visual-browser proof. |
| P22 | `.local/migration-p22/verification.json` | `846deb4d2d6e610edb1924f89948946c5d4b8ed9eadfe63823cfbd4584a45d1b` | Existing Admin evidence; no mobile-browser qualification. |
| P25 | `.local/migration-p25/verification.json` | `424972bedd87b767277c064e6d381a741029afa182a2e4882962a73f6967ccc2` | Notification and Admin flows; not a real-device run. |
| P25 | `.local/migration-p25/browser-evidence.json` | `2a9e7acc81be869f8db3324bcb70d7aad619ef4d139aff05de3f88105d80bf93` | 390px viewport and document. Admin navigation labels wrapped. Light theme only. |
| P26 | `.local/migration-p26/verification.json` | `fcc57f2ebf06fb06708368d8f30fae5e3ff1e10840412899e65a95f5362fc121` | Operations evidence; does not qualify browser or device presentation. |
| P27 | `.local/migration-p27/browser-evidence.json` | `4a76dc7fc1b22fe14d75a47e926dc7322724433eca75d161cef06d74fc6d4c18` | Local Chromium evidence below. Android, iOS, Telegram-client, dark-theme, and owner signoff remain open. |
| P27 | `.local/migration-p27/verification.json` | `23701588e5ef05593c3fb294955f7826497a3fbd9ea85a4c330ee7c1e134188d` | Disposable synthetic PostgreSQL harness passed 24 P23 checks on Python 3.13.18, Django 5.2.18, and PostgreSQL 16.15. |

P25 identified the mobile navigation wrapping that P27 addresses. P21 does not provide completed visual proof, so the P27 browser checks do not substitute for a source-quality visual comparison or the owner review below.

## P27 local verification

The API documentation suite ran under Django's `DiscoverRunner` with a synthetic runtime. All 7 tests passed; Django reported no system-check issues and skipped the unused default database. The suite checks named routes, OpenAPI operation and server-base parity, schema references, parser bounds, synthetic request parsing, public metadata, and absence of legacy operations.

The disposable P27 PostgreSQL integration harness passed all 24 recorded P23 checks on Python 3.13.18, Django 5.2.18, and PostgreSQL 16.15. Its Admin mode composes the P25 notification grants required by the Sales Proof detail read; production grants are unchanged. The audit assertion binds the `LIKE` pattern as a Psycopg 3 parameter.

The managed Chromium run used the local synthetic candidate app at `http://127.0.0.1:18802`, a 390x844 CSS-pixel viewport, and device scale factor 1.25:

- Admin: 390px document width, 16 one-line navigation labels, 315px drawer. Enter opened the dialog. Fifteen Tab presses moved focus from the first link to `Banner beranda`; the drawer scrolled 117px while document scroll remained 0. Escape closed the dialog and restored focus.
- API docs: 390px document width, seven operations, `/api/v1` base URL, 44px minimum navigation and machine-file targets, and `overflow-wrap: anywhere` for the error envelope. Enter opened the focused schema disclosure after the `#schemas` link.
- `/api-docs`, `/api-docs/openapi.json`, `/api-docs/reference.md`, `/llms.txt`, `/robots.txt`, and `/sitemap.xml` returned HTTP 200. The OpenAPI document declared seven operations and the same `/api/v1` server base as the page. The Markdown reference included every schema heading. `robots.txt` disallowed `/api/` without disallowing `/api-docs`; the sitemap included the API docs URL.

Browser artifacts:

| Artifact | SHA-256 |
| --- | --- |
| `.local/migration-p27/admin-mobile-390.png` | `7ebd1e3eb33dca6018ad837d4c853d45b052706db8719de10a8a188b7abe7ad1` |
| `.local/migration-p27/admin-mobile-390-scrolled.png` | `53a950689814d1de6df262d6a9983b9511ab37ee184bd40e7b2a27d2d2fa07c3` |
| `.local/migration-p27/api-docs-mobile-390.png` | `29ae532f9092553b95c357400aceeecde8884a0be786f1f8af8a5ac066123ce2` |
| `.local/migration-p27/api-docs-schemas-390.png` | `dd0224ca0bb67258d7dd93a93a435dc08e22ded50ffc802047573930ab296c6a` |

The screenshots and `.local/migration-p27/browser-evidence.json` contain synthetic local data only. Screenshots show rendered pixels, not Android/iOS layout, actual Telegram rendering, animation timing, or keyboard behavior by themselves. The browser evidence records focus, drawer scrolling, and disclosure state separately. This run used the light theme; dark-theme contrast and animation parity remain unqualified.

## Source identity

Base revision: `b1f20dcc3a05d43646a9cc5465e212719513495d`.

SHA-256 values for the P27 source and test files:

| File | SHA-256 |
| --- | --- |
| `candidate/lhie_candidate/customer_api/documentation.py` | `e8af5ca3de8f4086a40d96d710660f04b731a2ab55ab353dd52f41c30f8df794` |
| `candidate/lhie_candidate/storefront/api_docs.py` | `d0def3eae973b412cf4f4506a6a8e42e5ea003d49337ebf99169f95620a09d69` |
| `candidate/lhie_candidate/storefront/catalog_urls.py` | `f74e66f6d8235711a66e6512615575eeb769b98bb6a0fd5f1f62b068adeb91ce` |
| `candidate/lhie_candidate/storefront/catalog.py` | `8480806cf2ca19f596e33d1f2ac37342101794725659feb86878d0786b7c18c1` |
| `candidate/templates/storefront/api_docs.html` | `62fdb72dfd62ebc2f120827c389841a5aa2e7f91e58f2602390f5f452d98164e` |
| `candidate/tests/test_customer_api_docs.py` | `73ee8b69298dee53e5b963893bf11f993bd8cce83ca9dc20458d0476d849477c` |
| `candidate/static/storefront/catalog.css` | `04c5c50cf4d1cb33fab2e0ef30aa7a5b56e1e6017dcb2f73f7b2f6e88668e1cb` |
| `candidate/static/admin/admin.css` | `f190892d458433983a41d0450833225ba4d6270cfe9944a35744591baf2e71a6` |
| `candidate/tests/test_admin_postgres.py` | `e83ac83e19da21e10265a10322155352c8e8c0ef7217f7de62fb14e7d77c9428` |
| `candidate/tests/test_admin_operations_postgres.py` | `2140a03a27562ee4d13df16fa3e14ef88f84df5e97e9167c26ff58a90357fa5e` |
| `candidate/tests/test_storefront_postgres.py` | `af7c4c98d0d4236ebfbad60013ee7f0617f30c5e4d5e168dc298e4ff7f5baa84` |
| `tools/migration_harness/smoke_checkout.py` | `4eed2731e476f69ceec9da20c4a9a194440e9d0882289d3255439a4f54bd8bcc` |

## Owner review checklist

**Owner review is required and has not been completed.** Compare the P27 artifacts and source hashes above with the approved P21-P26 visual and interaction references. For each row, record the candidate source identity, actual device and OS, Telegram or browser client and build, theme, result, artifact path and SHA-256. Record any difference with the approving owner and issue reference. Do not accept an unapproved copy, menu, icon, style, media, animation, or flow difference.

| Surface | Manual check | Required evidence | Result |
| --- | --- | --- | --- |
| Public API docs and URLs | Open the page and machine files on the approved candidate origin. Compare operation paths, base URL, schemas, examples, robots, and sitemap with the approved source. Confirm no secret or customer data appears. | Sanitized response or capture for each URL, candidate source identity, origin, client/build, and result. | Pending |
| Admin mobile navigation | On real Android Chrome and iOS Safari, open the drawer, check every label, focus, keyboard scrolling, close behavior, and document overflow. | Device model, OS/browser versions, viewport, theme, recording of focus and scroll, screenshots, and per-check result. | Pending |
| Telegram client | On an owner-approved Testing Bot in real Telegram Android and iOS clients, compare the relevant menu, button copy, icons, stock states, navigation, and flows with the approved references. | Telegram client/build, device/OS, source identity, sanitized recording or screenshots, and result for each row. No customer data, live purchase, or production send. | Pending |
| Theme and motion | Check light and dark themes, focus contrast, dialogs, and reduced-motion behavior on the actual clients. | Theme and OS accessibility settings, device/client identity, recording where motion or focus matters, and result. | Pending |

A screenshot cannot establish keyboard focus, drawer scrolling, dialog dismissal, animation timing, or real Android/iOS/Telegram behavior. Use a recording or direct device observation for those checks. No device, production, or Telegram qualification is claimed by the local Chromium run.

## Django references

The implementation uses Django 5.2.18 URL reversing and its test client patterns:

- [URL resolver and `reverse()`](https://docs.djangoproject.com/en/5.2/ref/urls/)
- [Django test client](https://docs.djangoproject.com/en/5.2/topics/testing/tools/)
