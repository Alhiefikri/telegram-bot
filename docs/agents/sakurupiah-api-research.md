# Sakurupiah API research findings

> Research note for a future Sakurupiah payment-gateway adapter. This is documentation research only; it is not an implementation specification and does not replace a merchant-account test.

## Scope and sources

The primary source is Sakurupiah's public API documentation. Its linked Postman collection was inspected as a second first-party representation of the request definitions, and the signature example linked by that documentation was inspected for the exact algorithm.

- **[D1 — Sakurupiah API Documentation](https://sakurupiah.id/developers/api-dokumentasi)** — endpoint descriptions, examples, field tables, response examples, callback handler, channel table, and general notes.
- **[P1 — SAKURUPIAH DOKUMENTASI API Postman collection](https://sakurupiah.id/SAKURUPIAH-DOKUMENTASI-API-v2.1Postman.json)** — machine-readable request methods, form fields, URLs, and collection descriptions.
- **[S1 — official-linked PHP signature example](https://raw.githubusercontent.com/SAKURUPIAH/SIGNATURE_INVOICE/main/PHP_version.php)** — the PHP implementation linked from D1/P1.

D1 and P1 were directly retrievable as static HTML/JSON. No dynamically rendered API schema, OpenAPI document, downloadable error catalogue, or rate-limit policy was exposed by the inspected sources. The documentation uses the spelling `api-sanbox` (not `api-sandbox`) in its URLs; preserve that spelling unless a live merchant test proves otherwise.

## Executive summary

Sakurupiah exposes a bearer-authenticated REST-like API with PHP-script endpoints. All documented API requests use `POST`. The API ID is sent in the request body and the API key is sent as `Authorization: Bearer <API_KEY>`. Creating an invoice additionally requires a body `signature`: HMAC-SHA256 keyed by the API key over the concatenation `api_id + method + merchant_ref + amount`, with no separators shown in the official code. Callbacks are `POST` JSON requests signed by HMAC-SHA256 over the raw JSON body and carry `X-Callback-Signature` plus `X-Callback-Event: payment_status`.

The documented payment state vocabulary is `pending`, `berhasil` (successful), and `expired`. A merchant reference is required and must be different for each new transaction, but duplicate-reference behavior is not documented. The API presents Indonesian-rupiah-looking integer amounts and channel minimum/maximum values, but does not document a currency field or an explicit currency contract. Production is live and the documentation warns that payments cannot be cancelled; sandbox is intended for testing.

## Environment and authentication

| Environment | Base URL | Evidence |
| --- | --- | --- |
| Production/live | `https://sakurupiah.id/api/` | D1 “Url Live/Production”; P1 collection descriptions |
| Development/sandbox | `https://sakurupiah.id/api-sanbox/` | D1 “Url Development/Sandbox”; P1 variables/descriptions |

Observed authentication requirements:

1. Register and have an authenticated Sakurupiah account, then obtain a merchant-specific **API ID** and **API KEY** from the merchant settings area (D1).
2. Send the API key as an `Authorization` bearer token. P1 models this as Postman bearer auth with the `api_key` variable; D1's examples show `Authorization: Bearer ...`.
3. Send `api_id` in the request body for every documented API operation. The API ID/API key pair identifies a merchant; D1 says one account can have multiple merchants with different credentials.
4. Invoice creation requires a body `signature` in addition to bearer authentication (D1/P1).

The sources do not state token lifetime, rotation/revocation, scopes, required authorization header casing, whether API ID is accepted in headers, or whether authentication failures use HTTP 401/403 versus the documented JSON status convention. Do not copy the credential-looking values published in examples or P1 into an application.

## Signature rules

### Invoice request signature

D1 states that a new/open-payment transaction must be signed with HMAC-SHA256 using the API key. The documented PHP expression is equivalent to:

```text
HMAC-SHA256(key = API_KEY, message = API_ID + METHOD + MERCHANT_REF + AMOUNT)
```

The official-linked PHP source [S1](https://raw.githubusercontent.com/SAKURUPIAH/SIGNATURE_INVOICE/main/PHP_version.php) and the inline D1 example both show direct concatenation of `api_id`, `data_method`/`method`, `merchant_ref`, and `amount`, with no delimiters or normalization step. The signature is sent as the `signature` form field, not as a documented request header. The examples show a hexadecimal digest, but do not separately specify encoding, case normalization, whitespace handling, or canonical numeric formatting. Implementations should use the exact string representation submitted in `amount` and should obtain confirmation with a merchant test before changing representation.

### Callback signature

D1's callback handler computes:

```text
HMAC-SHA256(key = API_KEY, message = raw HTTP request body)
```

and compares it with `X-Callback-Signature`. This means verification must use the untouched bytes/string read from `php://input`, before JSON re-encoding or field reordering. No callback signature encoding/case, timestamp, nonce, or replay-window rule is documented.

## Endpoint inventory

All rows below are documented as `POST`. The endpoint paths are relative to the environment base URL above.

| Operation | Production path | Sandbox path | Required body | Optional body | Response shape |
| --- | --- | --- | --- | --- | --- |
| List channels | `/list-payment.php` | `/list-payment.php` | `api_id`, `method=list` | None documented | `status`, `message`, `data[]` |
| Check balance | `/check_balance.php` | `/check_balance.php` | `api_id`, `method=balance` | None documented | `status`, `message`, `data` |
| Create invoice | `/create.php` | `/create.php` | `api_id`, channel `method`, `phone`, `amount`, `merchant_fee`, `merchant_ref`, `callback_url`, `return_url`, `signature` | `name`, `email`, `expired`, and conditional product arrays | `status`, `message`, `data[]`, optional/root `produk[]` in example |
| Transaction history | `/transaction.php` | `/transaction.php` | `api_id`, `method=transaction` | `mechant`, `payment_kode`, `trx_id`, `merchant_ref`, `status`, `tanggal_awal`, `tanggal_akhir` | `status`, `message`, `data[]` |
| Transaction status | `/status-transaction.php` | `/status-transaction.php` | `api_id`, `method=status`, `trx_id` | None documented | `status`, `message`, `data[]` |
| Merchant callback URL | merchant-configured/request `callback_url` | merchant-configured/request `callback_url` | JSON callback body; callback headers | None documented | Merchant acknowledgement; no provider-required schema documented |

Endpoint URLs and methods: [D1](https://sakurupiah.id/developers/api-dokumentasi) and [P1](https://sakurupiah.id/SAKURUPIAH-DOKUMENTASI-API-v2.1Postman.json).

### Request encoding ambiguity

D1 labels the bodies as `form-data`. The list, balance, history, and status cURL snippets pass a PHP array to `CURLOPT_POSTFIELDS`, which normally produces multipart form data. The create-invoice snippet instead calls `http_build_query($data)` and passes the resulting string, while still labeling the table `form-data`; absent an explicit content-type header, that is normally URL-encoded form submission. P1 also declares `mode: formdata` for create. The API's accepted content types and whether arrays must be multipart are therefore unresolved; test both only in sandbox if required, and do not silently switch encoding in production.

## Channel catalogue and amount rules

### Channel listing response

The `list-payment.php` example returns each channel with:

- `kode`, `nama` — channel identifier/display name;
- `minimal`, `maksimal`, `biaya`, `percent` — limits and fee metadata represented as strings in the example;
- `tipe` — example values `DIRECT` and `REDIRECT`;
- `logo`, `status` — image URL and availability (example `Aktif`);
- `addition.tambahan_biaya`, `addition.jenis`, `addition.default_expired`, `addition.settlement`;
- `guide.title`, `guide.payment_guide`.

D1 says the operation includes active and inactive/offline channels. A future adapter should treat this response as the runtime source of available channel metadata rather than hard-coding the marketing table, but the provider's exact availability/field schema should be confirmed against the target merchant.

### Published channel table

D1 publishes this fee/limit table (Indonesian rupiah notation):

| Code | Name | Minimum | Maximum | Fee |
| --- | --- | ---: | ---: | ---: |
| `QRISMU` | QRISMU | Rp 500 | Rp 5,000,000 | 0.8% |
| `QRIS2` | QRIS2 | Rp 100 | Rp 10,000,000 | 0.9% |
| `QRIS` | QRIS | Rp 500 | Rp 2,000,000 | 0.7% |
| `QRISC` | QRISC | Rp 200 | Rp 20,000,000 | 0.7% |
| `ShopeePay` | ShopeePay | Rp 1,000 | Rp 2,000,000 | 3% |
| `GOPAY` | GOPAY E-Wallet | Rp 500 | Rp 5,000,000 | 3% |
| `BNCVA` | BNC Virtual-Account | Rp 10,000 | Rp 10,000,000 | Rp 3,500 |
| `SINARMAS` | SINARMAS Virtual-Account | Rp 10,000 | Rp 10,000,000 | Rp 3,500 |
| `BRIVA` | BRI Virtual-Account | Rp 10,000 | Rp 10,000,000 | Rp 3,500 |
| `BCAVA` | BCA Virtual-Account | Rp 10,000 | Rp 15,000,000 | Rp 4,900 |
| `DANAMON` | DANAMON Virtual-Account | Rp 10,000 | Rp 15,000,000 | Rp 3,500 |
| `MUAMALAT` | MUAMALAT Virtual-Account | Rp 10,000 | Rp 15,000,000 | Rp 3,500 |
| `BSIVA` | BSI Virtual-Account | Rp 10,000 | Rp 20,000,000 | Rp 3,500 |
| `OCBC` | OCBC Virtual-Account | Rp 10,000 | Rp 10,000,000 | Rp 3,500 |
| `BAGVA` | BAG Virtual-Account | Rp 10,000 | Rp 15,000,000 | Rp 4,200 |
| `ALFAMART` | ALFAMART | Rp 10,000 | Rp 5,000,000 | Rp 3,000 |
| `INDOMARET` | INDOMARET | Rp 10,000 | Rp 2,500,000 | Rp 3,000 |

The same D1 page's example `list-payment.php` response reports a `BCAVA` maximum of `20000000` and fee `5500`, while the table reports Rp 15,000,000 and Rp 4,900. That is a material documentation inconsistency; runtime channel metadata and an actual merchant response must win over either static value. The example shows only some channels and must not be treated as a complete catalogue.

### Invoice fields

The create-invoice field table in D1/P1 says:

| Field | Requiredness and documented meaning |
| --- | --- |
| `api_id` | Required merchant API ID. |
| `method` | Required payment-channel code, such as a code returned by channel listing. |
| `name` | Optional buyer name. |
| `email` | Optional buyer email. |
| `phone` | Required active buyer phone; D1 says Indonesia and Malaysia country codes are allowed and gives `08xxx`, `62xxx`, and `60xxx` forms. P1's description also mentions `+62xxx`; normalization rules are not specified. |
| `amount` | Required total purchase price; documented type `int`. No currency field is present. |
| `merchant_fee` | Required integer: `1` means merchant bears fee; `2` means buyer bears fee. Empty/false is documented to default to merchant-bearing. |
| `merchant_ref` | Required merchant invoice/unique code string; must differ for every new transaction. |
| `expired` | Optional integer payment lifetime. D1 says default is 24 hours or the channel default, in `Asia/Jakarta`; examples state `1` = 1 hour, `12` = 12 hours, `24` = 24 hours. No allowed range or behavior for zero/negative/fractional values is documented. |
| `produk[]` | Optional-but-conditional product names. |
| `qty[]` | Optional-but-conditional quantities corresponding to product entries. |
| `harga[]` | Optional-but-conditional unit prices corresponding to product entries. |
| `size[]` | Optional-but-conditional custom sizes corresponding to product entries. |
| `note[]` | Optional-but-conditional per-item notes corresponding to product entries. |
| `callback_url` | Required callback destination for invoice status changes. |
| `return_url` | Required URL to which the buyer is redirected. |
| `signature` | Required HMAC-SHA256 signature keyed by the API key. |

The docs do not define array length matching, whether product arrays are required as a complete set, whether `amount` must equal the sum of product lines, decimal/rounding behavior, or a currency parameter. The create example itself has `amount` 20,000 while its displayed product line values would not obviously reconcile to that amount; do not derive validation rules from that example.

The phone field is described as `int` in D1 but examples contain country-prefixed values and a leading-zero form; an implementation should preserve it as a string until the provider contract is confirmed.

### Create response

The successful example contains a one-element `data` array with these fields:

`via`, `payment_kode`, `trx_id`, `merchant_ref`, `nama`, `email`, `phone`, `total`, `merchant_fee`, `fee`, `amount_merchant`, `date`, `time`, `expired`, `payment_status`, `qr` (noted as QRIS-only), `payment_no`, and `checkout_url`.

The root example additionally contains `produk[]` objects with `nama_produk`, `qty`, `harga`, `size`, and `note`. Types are inconsistent: for example, some amounts are JSON numbers, some are strings, and `merchant_fee` is shown as the string `Merchant`. The response schema does not specify nullability, missing fields by channel, or whether `payment_no` is numeric for all methods. `checkout_url` is the buyer-facing redirect target; `qr` is specifically noted for QRIS. The initial `payment_status` example is `pending`.

## Status, history, and expiry

### Status vocabulary

D1 documents transaction status values `pending`, `berhasil`, and `expired` for history/status responses and callbacks. The example history response uses those exact lowercase strings. The callback sample pairs them with integer status codes:

- `pending` → `0`;
- `berhasil` → `1`;
- `expired` → `2` in the D1 PHP handler and D1 callback table.

P1's callback description instead says `expired` → `-2`. This conflict must be resolved with a real callback or provider support before accepting `expired` solely by code. A safe consumer should validate both the string and the provider-confirmed code according to the final observed contract, rather than trusting an isolated integer.

No cancelled, refunded, failed, chargeback, or reversed state is documented. D1 warns that production payments cannot be cancelled; the absence of a cancellation API is therefore significant.

### History lookup

`POST /transaction.php` accepts `method=transaction` and optional filters. The field is spelled **`mechant`** (missing `r`) in both D1 and P1; its documented value `1` limits results to the API ID/API-key merchant, while empty/`0` returns all merchants associated with the Sakurupiah account. This is a provider spelling, not a typo to fix in an adapter request.

Other filters are `payment_kode`, provider `trx_id`, merchant `merchant_ref`, `status`, `tanggal_awal`, and `tanggal_akhir`. Dates use `Y-m-d`. The example `data[]` records contain `trx_id`, `merchant_ref`, `payment_kode`, `tanggal`, `waktu`, `amount`, `expired`, and `status`. D1 describes newest-first history but does not define pagination, page size, timezone for date filtering, maximum date range, or a stable ordering guarantee.

### Status lookup

`POST /status-transaction.php` requires `api_id`, `method=status`, and provider `trx_id`; its successful response example is `{status: "200", message: "success", data: [{status: "berhasil"}]}`. P1's description says lookup can use a “TRX ID or reference,” but the field table only documents required `trx_id` and no reference field. Treat provider `trx_id` as the only documented request key until confirmed otherwise.

## Callback/webhook contract

### Documented delivery

The callback URL can be configured in the merchant page or passed as `callback_url` when creating the invoice. D1 says the gateway sends callbacks when payment status changes; its prose specifically mentions successful payment and expiry, while the sample handler includes a pending branch. P1 says callbacks are sent automatically on payment or expiry and advises handling duplicate deliveries. The exact event trigger set, retry policy, and delivery timing are not documented.

The documented HTTP contract is:

- method: `POST`;
- `Content-Type: application/json`;
- `X-Callback-Event: payment_status`;
- `X-Callback-Signature`: HMAC-SHA256 of the raw JSON request body keyed by the merchant API key;
- JSON body: `trx_id` (provider ID), `merchant_ref` (merchant invoice/reference), `status`, and `status_kode`.

The event value `payment_status` is the only documented event. The body does not include API ID, amount, currency, callback ID, timestamp, or attempt number. Use `trx_id` and/or the merchant reference to correlate, but verify whether either can be absent in a real callback.

### Receiver requirements implied by the official sample

D1's PHP handler demonstrates this processing order: read raw body; calculate and compare `X-Callback-Signature`; JSON-decode and reject invalid JSON; require `X-Callback-Event == payment_status`; read the four callback fields; accept only matching status/string-code pairs; return JSON success/failure messages. This is sample code, not a stated provider acknowledgement protocol. It does not set explicit HTTP status codes, authenticate source IP, enforce replay protection, or make updates idempotent.

P1 explicitly warns that a callback may be received more than once and the merchant must validate duplicates so a transaction is not updated repeatedly. Persist a deduplication/idempotency decision in the local payment lifecycle; do not mark a payment paid from an unverified callback body or from the callback status string alone.

## Errors, HTTP semantics, and limits

The general D1/P1 notes list response code `200` as successful and `400` as failed with the reason in `message`. Examples put `status` inside the JSON response as a string, so “200/400” may be an application-level status rather than the HTTP status; the sources do not clearly distinguish the two. No complete error-body schema is supplied beyond `status`, `message`, and occasional `data`.

No official source inspected documents:

- HTTP status mapping for validation, authentication, authorization, duplicate `merchant_ref`, unknown channel, expired invoice, or provider outage;
- machine-readable error codes or stable error messages;
- rate limits, quotas, concurrency limits, pagination limits, or `Retry-After`;
- timeout, retry, backoff, or idempotency semantics for API requests;
- whether a request accepted by the HTTP layer but returning JSON `status=400` has created a transaction.

Treat non-2xx transport responses, invalid JSON, missing required fields, and an unrecognized provider status as non-successful observations. Do not retry invoice creation blindly: the API requires a unique `merchant_ref`, but does not document whether a timed-out create was committed or how to recover it. A status/history lookup by a persisted provider `trx_id` or merchant reference is the safer recovery question, subject to the lookup ambiguities above.

## Security and operational notes

Observed protections and deployment differences:

- Use HTTPS URLs shown by the docs for both environments.
- Keep API ID/API key server-side; the bearer token is an API credential.
- Verify the invoice signature before sending create requests and verify callback signatures over the raw body.
- Production is described as live, with real active payment codes; D1 warns that payments entering production cannot be cancelled. Sandbox is explicitly provided for API testing.
- D1 says production supports IP validation that can be configured for the hosting server or proxy and describes IPv4/IPv6 allowlisting as **optional**. P1's collection description says the same validation is **required**. The provider's actual production policy and the effective source IP behind proxies are unresolved; obtain merchant confirmation before relying on either reading.
- The callback sample does not authenticate by source IP, so a callback receiver must rely on signature verification plus local correlation and replay/deduplication controls unless the provider supplies an additional network contract.

The docs do not specify TLS versions, certificate pinning, webhook IP ranges, secret rotation, key scopes, request timestamps/nonces, replay windows, or data-retention obligations. These are unknowns, not evidence that the provider lacks such controls.

## Implementation-facing observations (not provider guarantees)

These are conservative integration consequences of the observed contract, not additional Sakurupiah promises:

1. Model `merchant_ref` as the local idempotency/correlation key and enforce local uniqueness before create. On ambiguous create transport, reconcile before any second create.
2. Persist both local reference and provider `trx_id`; status lookup is documented by `trx_id`, while history can filter by either ID or merchant reference.
3. Store provider expiry as an observed timestamp when returned. The requested `expired` value is an integer hour count in the `Asia/Jakarta` rule, but no precision or range is guaranteed.
4. Mark a payment paid only after authenticating a callback or a status response and checking local amount/reference/provider-ID invariants. The callback body itself carries no amount or currency, so correlation to the original invoice is essential.
5. Make callback processing idempotent. P1 explicitly warns about duplicate callback deliveries.
6. Treat channel limits, fee, type, status, and default expiry as dynamic metadata from `list-payment.php`; the static table and response example already disagree for `BCAVA`.
7. Keep amounts as integer minor-unit-like values only if the merchant confirms the account is IDR. The API has no `currency` field and the docs do not define another unit.
8. Preserve the provider's `mechant` spelling when calling history. Do not “correct” it to `merchant`.

## Source/unknowns checklist

### Confirmed by inspected official material

- [x] Production and sandbox base URLs and the provider's `api-sanbox` spelling.
- [x] `POST` endpoint paths for channel list, balance, create, history, and status.
- [x] Bearer API-key authentication and body `api_id`.
- [x] Invoice HMAC-SHA256 construction and callback raw-body HMAC-SHA256 construction.
- [x] Invoice required/optional fields as documented, including callback/return URLs.
- [x] Channel list shape, published channel codes, fee/limit metadata, and direct/redirect examples.
- [x] Provider/merchant identifiers: `trx_id`, `merchant_ref`, and `payment_no` in the create response.
- [x] Documented status strings and callback headers/body fields.
- [x] Production-live versus sandbox-testing warning and production no-cancel warning.
- [x] Duplicate callback warning.

### Must be confirmed before production implementation

- [ ] Whether `200`/`400` are HTTP statuses, JSON `status` values, or both; obtain raw responses for success and failure.
- [ ] Full error schema/catalog and authentication/signature/duplicate-reference failure behavior.
- [ ] Whether all requests accept multipart form data, URL-encoded form data, or both; especially create with product arrays.
- [ ] Exact signature encoding/case and canonicalization of amount, method, references, and Unicode/whitespace.
- [ ] Whether duplicate `merchant_ref` is safely queryable and whether create is idempotent.
- [ ] Invoice amount currency/unit, integer range, fee inclusion, rounding, and whether amount must reconcile with product arrays.
- [ ] Channel availability and current per-channel limits/fees for the target merchant; resolve `BCAVA` contradiction.
- [ ] Expiry allowed range, exact timezone interpretation, boundary behavior, and whether `addition.default_expired` is always hours.
- [ ] Callback status-code mapping (`expired` is documented as both `2` and `-2`), event triggers (including pending), retry schedule, timeout, and acknowledgement requirements.
- [ ] Callback field presence/types, callback ordering, replay protection, and whether provider sends callbacks after status polling.
- [ ] Status lookup support for merchant reference (P1 prose says yes; field table says only `trx_id`).
- [ ] History pagination, ordering, maximum date range, date-filter timezone, and `mechant` behavior for multiple merchants.
- [ ] Rate limits, quotas, concurrency, timeout, retry, and `Retry-After` behavior.
- [ ] Production IP allowlist policy (D1 says optional; P1 says required), proxy handling, and provider webhook source IPs.
- [ ] Credential rotation/revocation, scopes, TLS/network requirements, and data-retention/security obligations.

## Evidence table

| Claim | Source evidence | Confidence / caveat |
| --- | --- | --- |
| Environment bases are `/api/` and `/api-sanbox/` | D1 “Url Live/Production” and “Url Development/Sandbox”; P1 collection description and variables | **High** for documented URLs; the `sanbox` spelling is provider-published. |
| API key is bearer auth; `api_id` is in body | D1 Authorization section and every cURL example; P1 bearer auth and form fields | **High**. Token lifecycle/scopes are unknown. |
| Invoice signature is HMAC-SHA256 over direct concatenation of API ID, method, merchant reference, and amount | D1 signature code/explanation; S1 linked PHP source | **High** for algorithm; encoding/canonicalization details are unspecified. |
| Callback signature is HMAC-SHA256 over raw JSON body | D1 callback handler (`php://input`, `hash_hmac`) and callback header table; P1 callback headers | **High**. Replay and encoding rules are unspecified. |
| API requests are `POST` | D1 cURL snippets and P1 request method values for all five operations | **High**. Callback is also documented as `POST`. |
| Create fields and expiry are as tabulated | D1 create field table; P1 create form-data descriptions | **High** for names/requiredness; array validation and expiry range are unknown. |
| Amounts are rupiah-looking integer values, with no currency field | D1 channel table uses `Rp`; create amount is `int`; no currency field appears in D1/P1 create request/response | **Medium** for IDR interpretation; explicit provider currency contract is absent. |
| Statuses are `pending`, `berhasil`, `expired` | D1 status/history/callback text and examples; P1 callback description | **High** for strings; other terminal states are not documented. |
| Callback status code for expiry is `2` | D1 handler/table compares `expired` with `2` | **Medium** because P1 says `-2`. |
| Callbacks can be duplicated | P1 callback description explicitly instructs duplicate validation | **High**; retry schedule is unknown. |
| Production IP validation is available/optional | D1 general notes say configurable and optional | **Medium** because P1 says required. |
| `BCAVA` has the published table limits/fee | D1 channel table | **Low-to-medium** because D1's own runtime response example says max `20000000` and fee `5500`. |
| Error response is status `400` with a message | D1/P1 general “KODE RESPONSE” note | **Medium**; HTTP versus JSON status is not distinguished and no error catalogue exists. |
| No documented rate limits | Full inspected D1 API page and P1 collection contain no rate-limit section/field | **Medium** as a documentation absence, not proof no limits exist. |
## Recommended verification sequence

Before enabling a production rail, use a merchant-owned sandbox credential and test only documented operations: list channels; create the smallest permitted invoice for a confirmed channel; capture raw request/response headers and body; follow the returned checkout URL where appropriate; observe callback signature and status transitions including expiry; perform status and history lookups; then repeat a callback payload to verify local deduplication. Record actual HTTP statuses, content type, field types, expiry timezone, duplicate-reference response, and retry behavior. Obtain written provider clarification for the checklist conflicts before moving to production.

A redacted merchant-sandbox smoke run on 2026-09-04 observed `HTTP 200` with
JSON `status: "200"` for channel listing, 17 channel rows, and an active
`QRIS` row with limits `500`–`2000000`, `biaya: "0.7"`,
`percent: "Percent"`, and `addition.default_expired: "12"`. The adapter now
normalizes that provider fee-unit representation to `0.7%`. Balance parsing
also succeeded without recording the balance amount. One smallest-amount
(`500`) QRIS invoice was created with a unique redacted merchant reference;
same-day history recovered one pending row with an amount and expiry, while
status lookup returned `pending` without amount fields. A non-mutating
unsupported-method probe returned HTTP `400` with JSON `status: "400"` and a
message. Repeating the same merchant reference was rejected with HTTP `400`
and did not accept a second invoice. After its configured expiry, the same
redacted invoice was observed as `expired` through both status and
amount-bearing history. No payment or callback was observed; rate-limit,
timeout, expiry-code mapping, callback, and production-network behavior remain
unqualified. No production credential or endpoint was used. This observation
is merchant-specific evidence, not production qualification.

After the first invoice expired, one Owner-authorized fresh sandbox invoice
was created at approximately 21:28 WIB for amount `500`. Its typed create
response was `pending` with total `500`, merchant-fee mode `1`, and both QR and
hosted-presentation fields present; the provider omitted a separate `amount`
field. The Owner later changed the sandbox invoice to successful. Status
lookup and amount-bearing history both reported `berhasil` for QRIS and amount
`500`, while status lookup still omitted amount fields. No provider callback was
observed by the application and the invoice was not linked to a local Payment.
No presentation value or full identifier is recorded.

A later app-linked sandbox checkout was exercised with the dedicated testing
bot. The provider status lookup returned `berhasil` without an amount, so the
application reconciliation path fetched same-day history and accepted the
transition only after amount `500` matched the local IDR Payment. The Payment
became `paid` with `paid_at`, the Order payment status became `paid`, and the
Order remained `processing` for manual fulfillment. The paid Telegram admin
outbound effect was recorded `sent`, and the signed Web invoice rendered the
paid surface. Three identical signed pending callbacks remained deduplicated
and acknowledged; no provider paid callback was observed. The app-linked
create response omitted QR/hosted presentation, so the application held the
initial Payment pending and non-payable. A separate app-linked expiry path and
the unresolved provider/Owner production gates remain outstanding.

## 2026-09-07 — QRIS presentation and post-create status re-check

This dated section re-checks the create-invoice presentation and status/history contract against the three first-party sources already listed above: [D1 — Sakurupiah API Documentation](https://sakurupiah.id/developers/api-dokumentasi), [P1 — official Postman collection](https://sakurupiah.id/SAKURUPIAH-DOKUMENTASI-API-v2.1Postman.json), and [S1 — official-linked PHP signature source](https://raw.githubusercontent.com/SAKURUPIAH/SIGNATURE_INVOICE/main/PHP_version.php). The review and the existing redacted sandbox observations used no production credential or request.

### Source-backed conclusion

- **Sandbox versus production:** D1 names `https://sakurupiah.id/api-sanbox/` as development/sandbox and `https://sakurupiah.id/api/` as live/production, and says sandbox is for testing while production carries real payments that cannot be cancelled ([D1](https://sakurupiah.id/developers/api-dokumentasi), environment note and general notes). P1 repeats those two bases and the same testing/live distinction ([P1](https://sakurupiah.id/SAKURUPIAH-DOKUMENTASI-API-v2.1Postman.json), collection description). Neither source documents a sandbox-only omission, placeholder, alternate QR format, alternate hosted presentation, or status-dependent presentation schema. An absent or unusable presentation therefore cannot be called an official sandbox quirk.
- **Pending create presentation:** D1's successful create example has a `data[]` row whose `payment_status` is `pending` and which names `qr` (annotated as QRIS-only), `payment_no`, and `checkout_url`; the surrounding create text identifies `checkout_url` as the buyer-facing redirect field ([D1](https://sakurupiah.id/developers/api-dokumentasi), create response). P1's create request explicitly selects `method` `QRIS`, but publishes no response schema beyond the D1 example ([P1](https://sakurupiah.id/SAKURUPIAH-DOKUMENTASI-API-v2.1Postman.json), Create Invoice). Thus a successful pending QRIS create is documented as carrying provider presentation data (QRIS `qr` and/or the hosted `checkout_url` route as applicable), but the docs do **not** promise that both are non-null, define EMV/CRC/exact-amount validity, or define an allowed hosted hostname/path/query shape. “Usable” must remain an application validation result, not an assumption from field presence.
- **Making an invoice successful:** The documented status operation only returns a status row in its example; the documented history rows contain transaction/reference/channel/date/time/amount/expiry/status fields and no `qr` or `checkout_url` ([D1](https://sakurupiah.id/developers/api-dokumentasi), status and history examples). P1 likewise defines status lookup by `trx_id` and lists no presentation fields in the status/history request or response contract ([P1](https://sakurupiah.id/SAKURUPIAH-DOKUMENTASI-API-v2.1Postman.json), History Transaction and Status Transaction). No first-party source documents a state-mutation operation or says that changing a sandbox invoice to `berhasil` generates or reveals QR. A successful-status observation therefore cannot justify expecting QR to appear after the transition.
- **Interpretation of the observed sandbox result:** The existing redacted observations (one direct sandbox response with unusable QR/hosted values and one app-linked create response without presentation) are not explained by any documented environment difference. Treat them as an external provider presentation-contract mismatch/blocker for the observed merchant response, not as permission to relax validation. Whether this is an account-specific sandbox behavior, a provider defect, or an undocumented fixture remains unresolved until Sakurupiah supplies a valid sandbox response or written clarification.

### Exact request contract and adapter comparison

- **Create method and encoding:** D1's create request is `POST` to `/create.php`; its PHP example passes the request through `http_build_query($data)` while the field table labels the body `form-data` ([D1](https://sakurupiah.id/developers/api-dokumentasi), create request). P1 declares `POST` with Postman `body.mode: formdata` ([P1](https://sakurupiah.id/SAKURUPIAH-DOKUMENTASI-API-v2.1Postman.json), Create Invoice). This is an official encoding inconsistency: the current `SakurupiahClient` uses `Http::asForm()`, which matches the D1 create snippet's URL-encoded approach, while the Postman representation is multipart form-data. No official source resolves whether both encodings are accepted; do not change encoding based on the presentation symptom alone.
- **Create field names:** The documented body names are `api_id`, `method`, `name`, `email`, `phone`, `amount`, `merchant_fee`, `merchant_ref`, optional `expired`, conditional product arrays `produk[]`, `qty[]`, `harga[]`, `size[]`, `note[]`, required `callback_url`, required `return_url`, and required `signature` ([D1](https://sakurupiah.id/developers/api-dokumentasi), create field table; [P1](https://sakurupiah.id/SAKURUPIAH-DOKUMENTASI-API-v2.1Postman.json), Create Invoice formdata). For QRIS, the channel value is `method=QRIS` in P1. The adapter's `SakurupiahCreateInvoice::toProviderPayload()` sends the required scalar names and does not invent or rename them; product arrays are not part of this QRIS adapter request shape.
- **Authentication and signature:** P1 models bearer authentication, and D1 documents the bearer `Authorization` header. S1's provider-linked PHP source computes HMAC-SHA256 over the direct concatenation of API ID, method, merchant reference, and amount, keyed by the API key ([S1](https://raw.githubusercontent.com/SAKURUPIAH/SIGNATURE_INVOICE/main/PHP_version.php)). The adapter's signature/request path follows those names and method ordering; no presentation evidence justifies changing it.
- **Fee and expiry:** D1 and P1 define `merchant_fee=1` as merchant-borne and `merchant_fee=2` as buyer-borne; empty/false defaults to merchant-borne ([D1](https://sakurupiah.id/developers/api-dokumentasi), create field table; [P1](https://sakurupiah.id/SAKURUPIAH-DOKUMENTASI-API-v2.1Postman.json), `merchant_fee` description). `expired` is optional and documented as an integer hour count: omitted/false uses 24 hours or the channel default in `Asia/Jakarta`, with examples of 1, 12, and 24 hours ([D1](https://sakurupiah.id/developers/api-dokumentasi), `expired` field; [P1](https://sakurupiah.id/SAKURUPIAH-DOKUMENTASI-API-v2.1Postman.json), `expired` description). Neither source specifies a complete range or zero/negative behavior. The current adapter enforces the documented positive-hour interpretation and parses the provider's returned absolute expiry without using a success status to infer presentation.

### Status/history response comparison

- **Status lookup:** `POST /status-transaction.php` takes `api_id`, `method=status`, and required provider `trx_id`; D1's successful example is an envelope with `status`, `message`, and `data[0].status` only ([D1](https://sakurupiah.id/developers/api-dokumentasi), status request/table/response). P1 repeats those request fields and endpoint bases, although its prose says “TRX ID or reference” while its field table names only `trx_id` ([P1](https://sakurupiah.id/SAKURUPIAH-DOKUMENTASI-API-v2.1Postman.json), Status Transaction). The current status parser therefore treats the requested `trx_id` as authoritative when the response omits it, accepts the documented status field, and treats amount, QR, hosted URL, and other enriched fields as optional rather than requiring them.
- **History lookup:** `POST /transaction.php` takes `api_id`, `method=transaction`, and optional filters including the provider-spelled `mechant`, `payment_kode`, `trx_id`, `merchant_ref`, `status`, `tanggal_awal`, and `tanggal_akhir` ([D1](https://sakurupiah.id/developers/api-dokumentasi), history field table; [P1](https://sakurupiah.id/SAKURUPIAH-DOKUMENTASI-API-v2.1Postman.json), History Transaction). The example `data[]` row has `trx_id`, `merchant_ref`, `payment_kode`, `tanggal`, `waktu`, `amount`, `expired`, and `status`; there is no presentation field ([D1](https://sakurupiah.id/developers/api-dokumentasi), history response). The current history parser requires those documented identity/amount/status fields and does not treat history as a QR/hosted-presentation source.
- **Status vocabulary:** D1 and P1 document `pending`, `berhasil`, and `expired` for status/history/callback flows ([D1](https://sakurupiah.id/developers/api-dokumentasi), status/history and callback sections; [P1](https://sakurupiah.id/SAKURUPIAH-DOKUMENTASI-API-v2.1Postman.json), callback/status descriptions). Presentation is documented on create, not on status/history. A status transition to `berhasil` must not be used as a presentation backfill.

### Unresolved ambiguity

1. The docs do not state whether a QRIS create response must contain `qr`, `checkout_url`, or both, nor whether either value is guaranteed to be non-empty and usable in sandbox or production. They also provide no QR payload validity rules or hosted URL allowlist/query policy.
2. D1's create cURL uses `http_build_query` while D1's table and P1 call the body `form-data`; accepted content types and array encoding remain unverified.
3. The create response example uses a non-QRIS channel while annotating `qr` as QRIS-only, so it cannot prove the exact QRIS response shape. No official post-success response example contains presentation fields.
4. P1's status prose permits a reference lookup, but its request table requires `trx_id`; no source resolves that conflict. The docs also do not specify whether status/history rows ever include create-only presentation fields.
5. The provider publishes no state-mutation endpoint or sandbox-success simulation contract. The observed owner-authorized status change is merchant-specific evidence, not official documentation of presentation behavior.

### Implementation recommendation

No application-source change is justified by the inspected first-party material. Preserve the current fail-closed flow: require a pending create result; expose a direct QR only after EMV/CRC and exact-total validation; otherwise accept a hosted route only after HTTPS and configured-host validation; and keep a missing/invalid presentation as non-payable `provider_presentation_pending`. Do not synthesize QR, strip provider URL tokens/query components, or mark an invoice successful to obtain presentation. Use the status endpoint for status, history for amount-bearing reconciliation, and do not expect either to provide QR/hosted instructions. Revisit only after Sakurupiah provides a valid redacted sandbox fixture or a written clarification that defines the presentation contract; this note does not qualify production or add an environment/policy gate.

**Official sources rechecked on 2026-09-07:** [D1](https://sakurupiah.id/developers/api-dokumentasi), [P1](https://sakurupiah.id/SAKURUPIAH-DOKUMENTASI-API-v2.1Postman.json), [S1](https://raw.githubusercontent.com/SAKURUPIAH/SIGNATURE_INVOICE/main/PHP_version.php).

## 2026-09-07 — Approved merchant channel correction

The merchant-provided channel catalog identifies several QRIS variants. The
approved target channel is provider code `QRISC` (`QRIS Custom Merchant`):
minimum `Rp200`, maximum `Rp20,000,000`, fee `0.7%`, additional fee `Rp100`,
settlement `H+1`, type `QRIS`, status `Online`. This is merchant-specific
qualification evidence, not a claim that every Sakurupiah account has the same
channel set.

The earlier app and direct observations in this note used provider code
`QRIS`, not `QRISC`. They therefore cannot establish a presentation failure for
the approved channel. The application now keeps local customer rail
`Payment.method=QRIS` separate from provider API `method=QRISC`, and readiness
selects exactly `QRISC` while rejecting an ambiguous QRIS channel set.

## 2026-09-07 — QRISC local presentation rerun

The rebuilt local app observed the merchant sandbox `QRISC` channel as active
with minimum `200`, maximum `20,000,000`, `default_expired=24`, and settlement
`H+1`. The local contract was aligned to the observed 24-hour expiry before
the checkout.

One fresh amount-`200` app-linked checkout used provider method `QRISC` and
returned `pending`, fee `0`, and total `200`; the amount equation matched. The
persisted invoice row was sanitized, so it contained neither `qr` nor
`checkout_url`; the application persisted no presentation and classified the
Payment as `provider_qris_unusable` / `provider_presentation_pending`.
The Payment stayed pending.

A separate raw sandbox create probe at amount `200` returned HTTP/application
status `200`. Its `data[0]` contained `qr` (string length `63`) and
`checkout_url` (HTTPS `sakurupiah.id` with a query), and
`SakurupiahInvoice` parsed the QR. `QrisEmvPayload` rejected that QR because it
was missing the terminal CRC. The hosted URL was not eligible under the
non-tokenized safety policy because it contained a query. The portal Detail
page can render a QR while the API presentation remains unusable; the
application guardrail is therefore detecting an invalid provider payload, not
dropping a valid QR.

This closes neither the QRISC presentation gate nor production qualification;
request a complete EMV QR payload or an approved non-tokenized hosted
presentation from Sakurupiah.

## 2026-09-17 — Create `qr` field is a provider QR image URL

A live sandbox probe of the approved `QRISC` channel (same merchant sandbox
credential, amount `200`, unique merchant reference) resolved the presentation
ambiguity recorded above. The create `data[0]` carried both documented fields:

- `qr` — an HTTPS URL on `sakurupiah.id` whose path ends in a file-like
  segment. Fetched directly, it returned HTTP `200`, `Content-Type:
  image/png`, a valid PNG signature, and roughly 4.6 kB of image data. It is a
  provider-hosted QR image asset, not an EMVCo payload: it does not start with
  `000201` and has no terminal CRC.
- `checkout_url` — an HTTPS `sakurupiah.id` URL with a query string. Fetched
  directly, it returned HTTP `200`, `Content-Type: text/html`, i.e. the hosted
  payment page.

No presentation values are recorded here. This narrows the earlier open
question: for `QRISC` the API hands the merchant a hosted QR image plus a
hosted payment page. The application now consumes the image URL through the
environment URL allowlist (downloaded server-side with redirects refused, a
2 MB cap, and an image-signature check) instead of requiring an EMV payload,
and keeps the hosted URL as a secondary link when it also passes the policy.

EMV support remains only an opportunistic path for a provider payload that
happens to validate; the rejection of arbitrary opaque `qr` content as a
scannable QR is intentional and unchanged.
