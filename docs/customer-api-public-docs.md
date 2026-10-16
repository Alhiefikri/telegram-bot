# Customer API public documentation (S10 #626)

Contract: [#617 sections 2, 5, 5.1, 7.1, 9 and 10](https://github.com/Alhiefikri/telegram-bot/issues/617),
implemented by [#626](https://github.com/Alhiefikri/telegram-bot/issues/626). Credentials,
admission and the read/order contracts stay owned by
[Customer API key operations](customer-api-key-operations.md),
[Customer API reads](customer-api-reads.md) and [Customer API orders](customer-api-orders.md);
this document covers the published documentation surface and its evidence only. No production
exposure, activation, deployment or CI operation was performed.

## Published surface

| Path                     | Owner                                    | Notes                                                                    |
| ------------------------ | ---------------------------------------- | ------------------------------------------------------------------------ |
| `/api-docs`              | `StorefrontApiDocsController::index`     | Readable page, Indonesian and English.                                   |
| `/api-docs/openapi.json` | `StorefrontApiDocsController::openapi`   | OpenAPI 3.1, `application/json; charset=UTF-8`, `public, max-age=3600`.  |
| `/api-docs/reference.md` | `StorefrontApiDocsController::reference` | Plain Markdown, `text/markdown; charset=UTF-8`, `public, max-age=3600`.  |
| `/llms.txt`              | `StorefrontLlmsController`               | Concise root index, `text/plain; charset=UTF-8`, `public, max-age=3600`. |

The origin comes from the existing application URL (`url('/')`), never from the reference
site and never from a new ENV. `route('storefront.api-docs.index')` is in
`StorefrontSitemap::entries()`, `StorefrontSeo::apiDocs()` supplies the metadata, and
`robots.txt` keeps `/api-docs` and `/llms.txt` crawlable while `/api/` stays disallowed.
`/api-docs/` with a trailing slash reaches the same route without a redirect (Laravel
matches it), so no duplicate route is registered.

## One canonical description

`App\ApiDocs\DocumentationSet` is the composition root; every artifact renders from the same
classes, which is what keeps operation, schema, enum, limit and error descriptions aligned
instead of hand-maintained three times:

| Class                                               | Ownership                                                                                                                                                                                                                                                                                                     |
| --------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `CustomerApiDocumentation`                          | Document version, `/api/v1` prefix, body/page/quantity/idempotency bounds, money patterns, security scheme, admission budgets, machine error codes, the seven operations (bilingual summary and description, parameters, request body, responses, error codes, notes) and every response/request JSON Schema. |
| `CustomerApiGuide`                                  | The bilingual long-form explanations as Markdown bodies (overview, quickstart, authentication, money, pricing, balances, consent, idempotency, fulfilment, refunds, limits, errors, scope, machine files).                                                                                                    |
| `CustomerApiExamples`                               | The runnable cURL, Python, JavaScript/TypeScript and PHP example for each operation.                                                                                                                                                                                                                          |
| `CustomerApiSamples`                                | One synthetic JSON example per documented schema and request body.                                                                                                                                                                                                                                            |
| `DocumentationTables`                               | Parameter, schema-field, error and budget rows, plus the page filter haystack, shared by the page and the Markdown reference.                                                                                                                                                                                 |
| `OpenApiDocument`, `ReferenceMarkdown`, `LlmsIndex` | The three machine renderers.                                                                                                                                                                                                                                                                                  |

Iterating `operations()` merges the inherited boundary codes (`method_not_allowed`,
`payload_too_large`) that the shared `/api/v1` admission middleware applies to every
operation, so the error tables on the page, in `reference.md` and in the OpenAPI document
list the same set.

## What the page publishes

- All seven operations with method, path, bilingual title, summary, description, parameter
  tables, request-body tables with a JSON example, response tables, an error table and
  notes.
- Money, pricing precedence, the four preference modes, expected-price consent, durable
  idempotency across rotation, timeout and partial-delivery recovery, refunds, key
  security, admission budgets, safe error handling, and the honest scope/status limits.
- Field tables for every published schema with enum values, patterns, bounds and a JSON
  example, all of which appear in the initial HTML.

## Accessibility and progressive enhancement

`storefront/api-docs.blade.php` is a standalone content page (`storefront.partials.head`,
`header`, `footer`) like the policy pages, so it inherits the storefront design system,
theme toggle, focus outline and reduced-motion rules.

- Every explanation, table and code sample is in the initial HTML. Both languages and all
  four sample languages render before JavaScript runs; the module only reveals the optional
  controls and switches the `data-api-docs-*` attributes the scoped CSS uses, so no control
  is ever present-but-dead.
- The section list is a native `<details>` disclosure below `lg` and a sticky, scrollable
  column above it; operation links are 44px targets and the operation links carry the same
  filter haystack as their article.
- `StorefrontApiDocsController`'s page shell adds `min-width: 0` to documentation sections,
  articles and disclosures. Without it the widest field table imposed an `auto` minimum
  width on the grid item and widened the whole page (clipped by the shell) instead of
  scrolling inside its `role="region"` container.
- Code blocks and wide tables are their own keyboard-focusable scroll containers
  (`tabindex="0"`, labelled), which is why the mobile page has no horizontal overflow.
- Copy actions reuse the existing `[data-copy-text]` contract from `storefront.js`, so the
  feedback markup, `aria-live` status and clipboard fallback behaviour stay shared.

Purpose notes for the choices that are not self-evident:

- Code samples keep one fixed dark console surface (`--sf-trend-*`) in both themes because
  code is data and must hold one predictable contrast on light paper and in dark mode.
- The pressed language/sample state uses `--sf-accent-soft` with `--sf-ink` text and an
  accent border. White on the dark accent measures 2.54:1, which would fail, while the
  soft pair measures 12.87:1 light and 10.72:1 dark.
- Lead paragraphs and the section list use `--sf-ink` rather than `--sf-muted`, because the
  muted token measures 4.39:1 on the page paper (it passes at 4.68:1 only on surfaces).

## Verification (2026-10-16)

### Behaviour tests

```sh
cd src
php artisan test tests/Feature/StorefrontApiDocsTest.php
```

10 tests, 690 assertions, no failures or skips. The suite proves the page renders both
languages, every documented operation and the machine links; that every documented route
exists in the router with the documented tag and error codes; that every `$ref` resolves;
that published samples cover exactly the declared fields of their schema; that the money
patterns reject `0` and accept `0.000001`; that the documented enums match
`BalancePaymentPreference`, `OrderStatus` and `PaymentStatus`; that the documented
body/page/quantity/idempotency/permit bounds match the implementation constants; that
`reference.md` stays plain Markdown with all four language fences; that `llms.txt` links only
public surfaces; and that the SQLite lane (no Redis) fails closed with the documented 503
`temporarily_unavailable` envelope.

### OpenAPI validation

```sh
curl -s "$STORE_ORIGIN/api-docs/openapi.json" -o /tmp/openapi-published.json
npx --yes @redocly/cli@2 lint /tmp/openapi-published.json --format=stylish
```

`Woohoo! Your API description is valid.` with one warning: `info-license` (no licence is
claimed for the store API, so no licence field was invented). Validation findings were fixed
rather than suppressed: order identifiers are documented as UUIDs (they were wrongly
documented as numeric strings), unused money components were removed, and
`expected_totals.USD` now uses a positive-only pattern.

### Browser walkthrough

```sh
cd src
npm run build
npx playwright test tests/Browser/api-docs.spec.js
```

7 tests passed (Chromium, disposable `php -S` server and SQLite database, self-hosted like
the other storefront specs). Observed:

| Scenario                     | Observed                                                                                                                                                                                                                              |
| ---------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Desktop 1280px, initial HTML | Every operation heading, path and method visible; both languages visible; 28 sample blocks visible; cURL sample contains the bounded-timeout and bearer flags                                                                         |
| Mobile 375px                 | Desktop section list hidden, the `<details>` list opens and its anchor scrolls the operation into view; every button, list link and disclosure is at least 44×44px; no element outside its scroll container crosses the viewport edge |
| Keyboard                     | Focused language button exposes a visible outline of at least 2px, `Enter` switches `aria-pressed` and hides the other language, `Enter` on a sample language hides the other samples                                                 |
| Copy                         | Base URL and the create-order Python sample are written to the clipboard through the shared copy action                                                                                                                               |
| Filter                       | Typing `delivery` narrows the list to the delivery operation; an unmatched query shows the empty-result message and restores on clear                                                                                                 |
| Machine files                | `openapi.json`, `reference.md`, `llms.txt` and `/api-docs/` answer 200 with the documented content types and cache header                                                                                                             |
| No JavaScript                | Both languages and all 28 samples stay visible; language, sample, filter and copy controls stay hidden                                                                                                                                |
| Rendered contrast            | In light and dark themes: prose, prose paragraphs, section links, table cells, pressed language button and code text all measure at least 4.5:1                                                                                       |

### Disposable HTTP example smoke

Throwaway runtime: `telegram-bot:local` PHP 8.4 with the repository mounted, PostgreSQL 16
and Redis 7 on loopback ports, synthetic Customer, credential, IDR/USDT funding and one
local Product. The four published examples were extracted from the published
`/api-docs/reference.md` fenced blocks and executed with `sh`, `python3`, `node` and `php`
against the real `/api/v1` routes, using a 15-second timeout and status checks.

```sh
bash /tmp/api-docs-src/run.sh   # throwaway wrapper; removed after the run
```

| Observation                         | Result                                                                                                                                                                |
| ----------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Example runs                        | 28 of 28 exited 0 (seven operations × four languages)                                                                                                                 |
| Initial HTML                        | 53 English blocks and 28 sample blocks present before JavaScript                                                                                                      |
| Create order                        | First run `201` `idempotent_replay: false`; the other three languages printed `replay: true` for the same order id                                                    |
| Three concurrent identical requests | `200 200 200`, all `idempotent_replay: true`, one order id                                                                                                            |
| Order/status/delivery reads         | Same order id, `processing`/`paid`/`USDT`, `fulfillment.state: complete`, `delivery.state: ready`, one released unit with its tutorial                                |
| Missing key / invalid key           | `401 unauthenticated`                                                                                                                                                 |
| Browser `Origin` header             | `403 backend_only`                                                                                                                                                    |
| Unknown Product / foreign order id  | `404 not_found`                                                                                                                                                       |
| `quantity=0`                        | `422 invalid_input`                                                                                                                                                   |
| Changed payload on the used key     | `409 idempotency_conflict`                                                                                                                                            |
| Stale expected totals on a new key  | `409 price_changed`                                                                                                                                                   |
| Response headers                    | `Cache-Control: no-store, private`, `Referrer-Policy: no-referrer`, `X-Request-ID`, `X-RateLimit-Limit/Remaining/Reset`                                               |
| Durable state                       | `orders=1`, `payments=1`, `usd_purchase_debits=1`, `allocated_units=1`, `idr_purchase_debits=0`, IDR balance unchanged at `50000`, USDT balance `0` after funding `2` |
| Claims                              | `accepted docs-smoke-checkout-0001` bound to the order; the rejected `price_changed` attempt stayed `in_progress` with no order                                       |

The disposable containers, database, synthetic credential and wrapper scripts were removed
after the run. Fixture delivery is not live Telegram or provider qualification; no supplier
purchase, real payment, production host or CI run participated.

### Limits

- The browser walkthrough is Chromium-only, self-hosted and single-worker. It asserts
  visibility, geometry, rendered contrast, keyboard state and clipboard content
  programmatically; screenshot capture is unavailable in this sandbox, so the evidence is
  assertions rather than images, and cross-browser parity or throughput is not claimed.
- The HTTP smoke used a development server with four PHP workers and one synthetic Customer;
  it proves the published examples execute and that the documented statuses and money state
  hold, not production capacity, TLS/CDN behaviour or concurrent financial load. Those
  remain owned by [#628](https://github.com/Alhiefikri/telegram-bot/issues/628) and the
  existing qualification gates.
- `info-license` is intentionally absent: the store API has no published licence, and
  inventing one would be a fabricated claim.
- An unrelated pre-existing failure remains in
  `ProductionReleaseHardeningTest::test_postgresql_release_manifest_covers_every_tagged_feature_file`:
  `CustomerApiAdmissionPostgresTest` and `CustomerApiCredentialsPostgresTest` carry the
  `postgres-release` group but are absent from `phpunit.postgres.xml` at `4c5efe0a`. It is
  untouched by this ticket and was not fixed here.
