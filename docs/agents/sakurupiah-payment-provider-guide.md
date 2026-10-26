# Panduan Agent: Payment Provider Sakurupiah

Status: **implemented**. This guide documents the live Sakurupiah provider
boundaries, credential-driven production enablement, and operational checks.

## Tujuan dan aturan baca

Target awal adalah menambah Sakurupiah sebagai provider payment baru tanpa merusak Payment historis, checkout Web Storefront, Store Bot, Inventory Ledger, `OrderLifecycle`, atau Telegram Outbound Effect Ledger.

Baca sebelum menyentuh source:

1. [`CONTEXT.md`](../../CONTEXT.md) untuk istilah domain.
2. [`docs/agents/codebase.md`](codebase.md) untuk ownership, lock order, queue, dan test map.
3. [`docs/adr/0006-klikqris-native-qris-and-pakasir-retirement.md`](../adr/0006-klikqris-native-qris-and-pakasir-retirement.md) untuk aturan provider switch, same-Payment recovery, dan larangan automatic fallback.
4. [`docs/superpowers/specs/2026-08-25-checkout-payment-assembly-architecture.md`](../superpowers/specs/2026-08-25-checkout-payment-assembly-architecture.md) untuk seam `CheckoutPaymentPreparation` dua fase.
5. [`sakurupiah-api-research.md`](sakurupiah-api-research.md) untuk fakta API dan unknowns yang masih harus dikonfirmasi.

Prioritas sumber:

1. Response mentah dari sandbox merchant target.
2. Klarifikasi tertulis dari Sakurupiah support.
3. Dokumentasi resmi dan Postman collection resmi.
4. Inferensi implementasi di dokumen ini.

If source only says “not documented”, preserve safe parsing and recovery
behavior in code; do not expose raw provider values or add unnecessary ENV
switches.

## Definition of done untuk agent

Implementasi baru boleh dianggap siap hanya jika semua kondisi ini terbukti:

- `sakurupiah` adalah identity provider yang hanya berlaku untuk checkout baru.
- Payment historis tidak pernah ditafsirkan berdasarkan provider aktif saat ini.
- Satu checkout menghasilkan paling banyak satu Order, satu Payment, satu reservation, dan satu checkout effect.
- `CheckoutPaymentPreparation::prepare()` tidak melakukan HTTP provider, callback, queue, atau Telegram.
- Provider invoice dibuat setelah Payment lokal commit, dengan single-flight lock per Payment.
- Timeout/connection error saat create masuk ke recovery pada Payment yang sama; tidak ada blind create kedua dan tidak ada fallback ke provider lain.
- Callback diperlakukan sebagai signal. Paid hanya melalui `OrderLifecycle` setelah signature, correlation, status authoritative, dan amount/integrity gate lulus.
- Callback duplicate, unknown, out-of-order, dan provider outage aman serta terlihat di `PaymentEvent`/operational alert.
- QR atau hosted URL hanya dipresentasikan melalui DTO safe yang sudah divalidasi; raw payload, API key, signature, dan token URL tidak bocor.
- SQLite behavior suite, test provider terfokus, PostgreSQL concurrency gate, sandbox qualification, dan release checks memiliki bukti.

## Keputusan scope yang direkomendasikan

### Owner-selected QRIS channel

Channel selection belongs to the Owner Dashboard, not the customer:

- `Payment.provider = sakurupiah`.
- `Payment.method = QRIS` sebagai identitas rail customer lokal.
- Request provider memakai channel yang dipilih Owner dan disnapshot pada
  `Payment.sakurupiah_channel` atau `StoreBalanceTopUp.sakurupiah_channel`.
- `Payment.currency = IDR` hanya setelah merchant mengonfirmasi bahwa `amount`
  memang rupiah integer.
- Checkout customer tetap memilih rail QRIS yang sama; hanya Owner yang
  memilih channel `QRIS`, `QRISC`, `QRISMU`, atau `QRIS2` di Dashboard.
- `merchant_fee = 2` (buyer bears the fee) adalah kebijakan release saat ini:
  fee channel QRIS dibebankan ke pembeli, sehingga provider menambahkan fee di
  atas nominal Order (`total = amount + fee`) dan merchant menerima penuh
  nominal Order. Kebijakannya dipin di
  `services.sakurupiah.qris_contract` (`fee_payer = customer`), bukan ENV.
  Provider meng-echo payer sebagai kata (`Merchant` atau `Pelanggan`), dan
  response `total`, `fee`, `amount_merchant` harus tetap divalidasi per sandbox.
- Channel disimpan pada `manual_payment_settings.sakurupiah_channel`, default
  `QRIS`; bukan ENV. Metadata fee, limit, dan settlement berasal dari API.
- `SakurupiahChannelSettings` mengotorisasi Owner, memvalidasi pilihan aktif,
  memeriksa optimistic version di bawah lock, lalu menyimpan perubahan dan audit
  secara atomik. Provider aktif tidak ikut diubah.
- Readiness memilih tepat channel tersimpan dan memasukkannya dalam cache
  fingerprint; preflight yang sudah basi ditolak sebelum persist checkout.
- Create, retry, recovery, callback, dan history harus cocok dengan snapshot
  transaksi. Payment lama mempertahankan observed channel; transaksi lama tanpa
  observed channel dan top-up lama tetap QRISC. Tidak ada fallback otomatis.
- `QRISMU` memakai expiry 12 jam; channel lain memakai policy 24 jam yang
  dikualifikasi. Metadata expiry yang tidak sesuai membuat channel tidak siap.

### Presentation contract

The official create response documents `qr` as a provider-issued QRIS string
and `checkout_url` as the buyer redirect. For the approved `QRISC` channel the
merchant sandbox returns `qr` as an HTTPS **provider-hosted QR image URL**
(verified `image/png`), not as an EMVCo payload. The application resolves the
presentation in this order after the invoice identity, amount/fee, status, and
expiry gates pass:

1. **Direct QR** — `qr` is a valid EMV payload (starts `000201`, terminal CRC
   verified, tag 54 amount equals the provider total). The QR image is rendered
   locally; the hosted URL is not used.
2. **QR image** — `qr` is an HTTPS URL on the exact environment host allowlist.
   The complete URL is stored only in the encrypted `provider_presentation`
   snapshot. Telegram downloads it server-side through `SakurupiahQrisImage`
   (no redirects, bounded size, PNG/JPEG signature required) and sends it as a
   photo with the scan copy; the storefront serves it through the signed
   `…/qris` route. A provider `checkout_url` that also passes the URL policy is
   kept alongside the image and exposed as the website button; if it is absent
   or fails the policy, the image presentation stays payable.
3. **Hosted** — no usable QR; `checkout_url` is accepted only with HTTPS and an
   exact environment host allowlisted host. Provider-documented query
   parameters are allowed, but userinfo, custom ports, fragments, and
   non-allowlisted hosts remain rejected. The complete URL is kept only in the
   encrypted `provider_presentation` snapshot and must never be copied to
   `provider_payload`, logs, events, or other plaintext diagnostics.

When none of the three yields a usable presentation, the Payment stays pending
with the safe `provider_presentation_pending` outcome and the customer receives
retry copy without QR, total, or provider data.

The application never renders an unvalidated `qr` string as a scannable QR
image: arbitrary opaque content is not payable because a QR that does not
encode a valid payload cannot be scanned.

## Peta ownership repository

| Concern | Owner yang harus dipakai | Agent tidak boleh memindahkan aturan ke |
| --- | --- | --- |
| Provider identity dan label | `src/app/Support/PaymentProvider.php` | controller/view |
| Provider selection checkout baru | `PaymentProviderResolver`, `PaymentProviderSwitcher` | channel adapter |
| Rail preparation | `src/app/Services/Checkout/CheckoutPaymentPreparation.php` + resolver | `OrderCheckoutService` |
| Common Payment persistence | `CheckoutPaymentPlanWriter` | provider client |
| Order/payment transitions | `OrderLifecycle` / `OrderStateMachine` | webhook controller/client |
| Reservation/sale/release | `InventoryLedger` | payment adapter |
| Provider create/status/recovery | module `src/app/Services/Sakurupiah/` sendiri | `OrderCheckoutService` |
| Callback observation | `SakurupiahCallbackProcessor` + event logger | raw callback body |
| Customer presentation | Telegram/Storefront presentation adapter | lifecycle service |
| Durable Telegram delivery | `TelegramOutboundEffectLedgerProtocol` dan `CheckoutInstructionDeliveryPipeline` | job state table baru |
| Reconciliation | provider command + adapter, lalu `OrderLifecycle` | direct status update |
| Admin audit/settings | existing Admin services dan `AuditLogger` | provider HTTP client |

## Kontrak API Sakurupiah yang terverifikasi

Rujukan detail dan caveat: [`sakurupiah-api-research.md`](sakurupiah-api-research.md). URL dan nama field berikut sengaja memakai spelling provider, termasuk `api-sanbox` dan `mechant`.

### Environment

| Environment | Base URL |
| --- | --- |
| Production | `https://sakurupiah.id/api/` |
| Sandbox | `https://sakurupiah.id/api-sanbox/` |

`api-sanbox` adalah spelling resmi yang dipublikasikan provider. Jangan menggantinya menjadi `api-sandbox` tanpa bukti live.

### Authentication

Semua operasi API terdokumentasi memakai:

- `Authorization: Bearer <API_KEY>`.
- `api_id` pada body request.
- HTTPS.
- API ID dan API KEY merchant target, bukan credential contoh dari docs/Postman.

Token lifetime, scope, rotation, revocation, HTTP 401/403 mapping, dan apakah IP allowlist production wajib atau opsional belum konsisten. Simpan credential hanya di konfigurasi rahasia server; jangan masukkan ke Payment, audit metadata, fixture, screenshot, atau log.

### Signature invoice

Signature create invoice adalah HMAC-SHA256 dengan API key sebagai key dan concatenation tanpa delimiter:

```text
message = api_id + method + merchant_ref + amount_as_submitted
signature = HMAC-SHA256(key = api_key, message = message)
```

Implementasi harus:

- memakai method dan amount string yang benar-benar dikirim;
- tidak menambah separator, whitespace, JSON encoding, atau normalisasi yang tidak didukung source;
- menghasilkan lowercase hexadecimal sesuai implementasi PHP resmi, lalu membuktikannya dengan test vector tanpa menyimpan credential nyata;
- melakukan signature calculation hanya di client server-side.

Canonicalization amount, case method, Unicode/whitespace, dan accepted signature casing belum ditulis provider. Jangan mengubah representasi setelah sandbox qualification.

### Endpoint matrix

Semua endpoint provider yang ditemukan menggunakan `POST`.

| Operasi | Production | Sandbox | Body wajib | Catatan |
| --- | --- | --- | --- | --- |
| List channel | `/list-payment.php` | `/list-payment.php` | `api_id`, `method=list` | Runtime source untuk channel, fee, limit, type, default expiry |
| Check balance | `/check_balance.php` | `/check_balance.php` | `api_id`, `method=balance` | Admin observability; bukan bukti payment paid |
| Create invoice | `/create.php` | `/create.php` | `api_id`, channel `method`, `phone`, `amount`, `merchant_fee`, `merchant_ref`, `callback_url`, `return_url`, `signature` | `name`, `email`, `expired`, dan product arrays opsional/conditional |
| Transaction history | `/transaction.php` | `/transaction.php` | `api_id`, `method=transaction` | Field filter `mechant` memang salah eja dan harus dipertahankan |
| Transaction status | `/status-transaction.php` | `/status-transaction.php` | `api_id`, `method=status`, `trx_id` | Docs hanya menjamin request dengan provider `trx_id` |
| Callback | URL merchant | URL merchant | JSON body + callback headers | Bukan endpoint yang dipanggil aplikasi |

Semua response harus melalui parser typed dan sanitizer. `status = "200"` di JSON tidak otomatis berarti HTTP 200 atau transaction accepted; bedakan transport status, application status, `message`, schema, dan business status.

### Create invoice request

Field yang didokumentasikan:

| Field | Rule provider | Kebijakan adapter |
| --- | --- | --- |
| `api_id` | Required | Ambil dari environment target |
| `method` | Required channel code | Channel snapshot transaksi; harus cocok dengan channel hasil provider |
| `name` | Optional | Kirim buyer name bila contract/privacy mengizinkan |
| `email` | Optional | Kirim hanya bila diperlukan; jangan log |
| `phone` | Required; contoh `08`, `62`, `60` | Perlakukan sebagai string, normalisasi hanya setelah dikonfirmasi |
| `amount` | Required integer; tidak ada currency field | Local integer amount; block bila unit/currency belum terkonfirmasi |
| `merchant_fee` | `1` merchant, `2` customer; kosong/false disebut default merchant | Release ini memakai `2` (buyer) dan memvalidasi echo `Merchant`/`Pelanggan` terhadap `fee_payer` terkonfigurasi |
| `merchant_ref` | Required unique string | Pakai `Payment.merchant_ref`; jangan membuat reference kedua |
| `expired` | Optional integer jam; default 24 jam/channel default; timezone `Asia/Jakarta` | Policy wajib ditutup karena local TTL saat ini berbasis menit |
| `produk[]`, `qty[]`, `harga[]`, `size[]`, `note[]` | Optional conditional arrays | V1 jangan kirim sebelum length/reconciliation contract jelas |
| `callback_url` | Required | Route callback HTTPS environment yang benar |
| `return_url` | Required | URL aplikasi yang signed/allowlisted; bukan arbitrary customer URL |
| `signature` | Required | HMAC exact concatenation |

Dokumen menyebut `form-data`; snippet create memakai URL-encoded `http_build_query`; Postman menyatakan `formdata`. Uji dan tetapkan encoding sandbox sebelum production. Jika V1 tidak mengirim product arrays, `application/x-www-form-urlencoded` paling sederhana untuk diuji; jangan menganggapnya terbukti sebelum raw response.

### Create response

Contoh sukses mendokumentasikan root `status`, `message`, `data[]`, dan optional `produk[]`. Item `data[]` dapat memiliki:

- `via`, `payment_kode`, `trx_id`, `merchant_ref`;
- `nama`, `email`, `phone`;
- `total`, `merchant_fee`, `fee`, `amount_merchant`;
- `date`, `time`, `expired`, `payment_status`;
- `qr` (disebut khusus QRIS);
- `payment_no`, `checkout_url`.

Types tidak konsisten antara string dan number; nullability/missing fields per channel tidak didefinisikan. Parser harus menerima hanya variasi yang dibuktikan dan menolak overflow, negative amount, invalid date, missing `trx_id`, missing reference, channel mismatch, atau status yang tidak dikenal.

`trx_id` adalah provider reference untuk local Payment. `merchant_ref` harus cocok dengan `Payment.merchant_ref`. `payment_no`/`checkout_url` hanya disimpan atau dipresentasikan setelah field safety policy diputuskan.

### Channel catalogue

`list-payment.php` mendokumentasikan `data[]` dengan:

- `kode`, `nama`;
- `minimal`, `maksimal`, `biaya`, `percent`;
- `tipe` seperti `DIRECT` atau `REDIRECT`;
- `logo`, `status`;
- `addition.tambahan_biaya`, `addition.jenis`, `addition.default_expired`, `addition.settlement`;
- `guide.title`, `guide.payment_guide`.

Static table dan contoh response bertentangan untuk `BCAVA` (limit dan fee). Runtime response merchant harus menjadi source of truth. Jangan hard-code seluruh channel/fee/limit ke source atau ENV.

### Status dan expiry

Status string yang didokumentasikan:

- `pending` — belum confirmed paid;
- `berhasil` — provider menyatakan berhasil;
- `expired` — invoice kadaluarsa.

Callback code yang ditunjukkan docs: `pending=0`, `berhasil=1`, `expired=2`. Postman description menyebut `expired=-2`; konflik ini harus diselesaikan sebelum menerima expired berdasarkan integer saja.

Tidak ada status `cancelled`, `failed`, `refunded`, chargeback, atau reversal yang terdokumentasi. Production payment disebut tidak dapat dibatalkan. Local cancellation harus tetap memakai `Provider Cancellation Attempt` policy yang ada; jangan menjanjikan refund/provider cancel.

`expired` request berupa jam integer dan default/provider channel expiry berada di `Asia/Jakarta`. Local Order deadline adalah safety boundary fulfillment. Karena provider tidak mendukung expiry menit secara terdokumentasi, agent harus memilih salah satu policy berikut dan merekam keputusan:

- samakan local deadline ke jam provider yang dibuktikan;
- gunakan provider expiry minimal yang dibuktikan dan local deadline lebih awal, dengan Late Payment handling;
- blok production sampai provider menerima precision/policy yang cukup.

Rounding tanpa decision tertulis dilarang.

## Callback/webhook contract

Provider mendokumentasikan:

- method `POST`;
- `Content-Type: application/json`;
- `X-Callback-Event: payment_status`;
- `X-Callback-Signature = HMAC-SHA256(raw_body, API_KEY)`;
- body `trx_id`, `merchant_ref`, `status`, `status_kode`.

### Receiver algorithm

Urutan wajib:

1. Batasi request size dan JSON depth sebelum parsing.
2. Simpan raw bytes hanya pada boundary sementara; jangan re-encode untuk signature.
3. Hitung HMAC raw body dengan API key environment yang benar.
4. Bandingkan menggunakan constant-time comparison.
5. Validasi event exact `payment_status`.
6. Decode JSON dan validasi field types/length/status-code pair.
7. Correlate `merchant_ref` ke `Payment` provider `sakurupiah`.
8. Pastikan `trx_id` sama dengan provider reference yang tersimpan jika sudah ada.
9. Log callback melalui `PaymentEvent` dengan idempotency key yang deterministic dan payload sanitized.
10. Untuk trigger paid/expired, panggil status/history authoritative provider lookup sesuai policy; jangan mengubah Payment dari callback body langsung.
11. Hanya setelah semua integrity gate lulus, delegasikan paid/expired ke `OrderLifecycle`.
12. Duplicate callback harus menjadi no-op lifecycle dengan `received_count` bertambah.

Body callback tidak mengandung amount/currency dan status endpoint yang didokumentasikan hanya mengembalikan status. Karena itu automatic paid harus diblok sampai salah satu bukti berikut tersedia:

- status/history sandbox benar-benar mengembalikan amount/reference yang dapat dicocokkan;
- provider memberi kontrak tertulis bahwa `trx_id` dan signed status authoritative mengikat invoice amount lokal;
- ada endpoint/detail response resmi yang memuat amount.

Signature valid tanpa amount/correlation bukan otorisasi fulfillment.

### HTTP acknowledgement

Docs menampilkan JSON `{success, message}` tetapi tidak menetapkan HTTP acknowledgement, retry schedule, callback timeout, atau event ordering. Sandbox harus mengukur ini. Default receiver yang aman: acknowledge duplicate/unknown event dengan response aman agar retry provider tidak menjadi storm, tetapi jangan menyamakan acknowledgement dengan successful payment processing. Bila verification belum selesai, enqueue bounded verification dan tetap expose `Payment Verification Pending`.

## Target module shape

Nama konkret boleh berubah setelah implementasi, tetapi ownership dan typed boundary harus tetap:

```text
PaymentProviderResolver
  → SakurupiahCheckoutPaymentPreparation
  → CheckoutPaymentPlan (typed Sakurupiah snapshot)
  → OrderCheckoutService

post-commit instruction/status path
  → SakurupiahInvoiceLifecycleService
  → SakurupiahProviderAdapter / SakurupiahClient
  → SakurupiahInvoice DTO
  → sanitized Payment + PaymentEvent

POST /api/webhook/sakurupiah
  → SakurupiahWebhookController
  → SakurupiahCallbackProcessor
  → authoritative status/history
  → OrderLifecycle
```

Komponen yang disarankan:

- `App\Enums\SakurupiahEnvironment` — sandbox/production selection, terpisah dari `KlikQrisEnvironment`.
- `SakurupiahProviderReadiness` + report — credentials pair, HTTPS URL, runtime env, required route, channel availability, schema/config readiness.
- `SakurupiahProviderAdapter` — `listChannels`, `createInvoice`, `transactionStatus`, `transactionHistory`, `checkBalance`; return DTO, bukan array bebas.
- `SakurupiahClient` — HTTP headers, form encoding, timeout, bounded retry hanya untuk safe lookup, HMAC create, HTTP/application error translation.
- `SakurupiahInvoice`, `SakurupiahStatus`, `SakurupiahChannel`, `SakurupiahCallback` — immutable typed DTOs.
- `SakurupiahPayloadSanitizer` — whitelist/secret redaction sebelum `PaymentEvent.raw_payload` atau safe projection.
- `SakurupiahInvoiceLifecycleService` — create, same-Payment recovery, persistence, safe outcome, payment lock, owner alert. Jangan copy KlikQRIS state names jika semantics berbeda; gunakan outcome yang eksplisit.
- `SakurupiahPaymentEventLogger` — callback/create/reconciliation idempotency dan sanitized evidence.
- `SakurupiahCallbackProcessor` — callback signal, lookup, integrity gates, `OrderLifecycle` delegation.
- `SakurupiahCheckoutPaymentPreparation` + typed `SakurupiahCheckoutPaymentSnapshot` — local amount/currency/method/expiry snapshot only.

Jangan membuat `ProviderPaymentAttributes` associative field bag, provider-neutral Native QRIS module, atau helper generik yang menyamarkan perbedaan QRIS/redirect/VA.

## Local Payment mapping

Gunakan existing fields terlebih dahulu; migration baru hanya jika evidence membuktikan snapshot tidak dapat direpresentasikan aman.

| Local field | Sakurupiah value | Rule |
| --- | --- | --- |
| `provider` | `sakurupiah` | Immutable provenance |
| `merchant_ref` | local generated reference | Idempotency/correlation key |
| `provider_reference` | `trx_id` | Required before status lookup |
| `method` | `QRIS` | Customer rail identity |
| `provider_payment_method` | Observed `payment_kode` | Must match the immutable `sakurupiah_channel` snapshot |
| `sakurupiah_channel` | Selected channel before create | Settings changes never re-target this transaction |
| `amount` | local Order payable amount | Integer only after IDR confirmation |
| `currency` | `IDR` v1 | No provider currency field; gate required |
| `provider_total_payment` | validated response `total` | Never blindly cast untrusted value |
| `provider_fee` | validated response `fee` | Distinguish customer/merchant payer |
| `provider_created_at` | response date/time | Parse timezone only after confirmation |
| `provider_expires_at` | response `expired` | Earliest effective deadline wins |
| `provider_payload` | sanitized non-secret projection | Never raw response or credentials |
| `provider_payment_number` | `payment_no` only if needed | Encrypted cast; do not log |
| `provider_create_state` | provider-specific internal state | Keep inside lifecycle policy; do not let UI infer directly |

`checkout_key`, provider setting version, Order snapshot, inventory reservation, and outbound effect remain owned by existing modules.

## Provider lifecycle rules

### Create

1. Local checkout completes transaction and writes pending Order/Payment/effect.
2. Post-commit caller acquires a cache/database single-flight lock keyed by Payment.
3. Read Payment fresh and ensure Order/payment are still pending and within local policy.
4. Ensure readiness; readiness failure means request was not sent and may be retried.
5. Build request from persisted `merchant_ref`, local amount, fixed method, configured callback/return URL, and exact signature.
6. Call create once.
7. Parse response and assert provider, method, reference, amount/unit, status, and expiry.
8. Persist sanitized projection and `trx_id` atomically under Order/Payment lock.
9. Return only a safe Payable/Creating/RecoveryPending/ProviderPresentationPending/Invalid/Expired-style outcome.

### Ambiguous create

Connection reset, timeout, worker interruption after request dispatch, HTTP 5xx with unknown server commit, invalid JSON after a possible commit, or response schema failure must be classified as **outcome unknown**, not definitive “not created”.

Recovery order:

1. use persisted `provider_reference` if available;
2. query documented status by `trx_id`;
3. query history by both `trx_id` and `merchant_ref` where supported;
4. validate amount/reference/status/expiry from the strongest response;
5. persist the same Payment or keep `Payment Verification Pending`;
6. never issue a second `merchant_ref` or second Payment automatically.

If no provider reference is available and duplicate-reference lookup is undocumented, escalate to owner/manual review instead of blind create retry.

### Paid and expired

- `berhasil` is an observation, not a direct local transition.
- `expired` may release local reservation only when the provider result and local Payment still match and the effective deadline policy permits it.
- A later valid paid observation on a terminal local Order is a **Late Payment**, not automatic fulfillment.
- All paid/expired/refund/manual-review transitions go through `OrderLifecycle`.
- `PaymentEvent.processed_at` is a processing marker, not a replacement for local status idempotency.

## Checkout integration steps

1. Add provider identity/label to `PaymentProvider`; keep `known()` broad enough for history and `allowed()` limited to explicitly enabled checkout modes.
2. Add a Sakurupiah QRIS intent or provider-bound intent with normalized provider/method identity.
3. Add typed snapshot and register it in `CheckoutPaymentPlan` validation.
4. Add `SakurupiahCheckoutPaymentPreparation` to the preparation resolver and AppServiceProvider. `preflight()` may perform bounded readiness/channel lookup; `prepare()` may only use locked local data and preflight fingerprint.
5. For non-Store Balance checkout, preserve the lock order: `cart → checkout_maintenance_settings → cart items/products → ManualPaymentSetting`.
6. Do not add Sakurupiah HTTP to `OrderCheckoutService` or `CheckoutPaymentPlanWriter`.
7. Make provider setting version/ID part of the existing snapshot validation. Provider switches affect only new checkout.
8. Ensure preparation failure creates no Order, Payment, reservation, credential allocation, or outbound effect.
9. Add characterization assertions for one Order, one Payment, exact IDR amount, method, currency, expiry, and checkout key reuse.

## Telegram and Web Storefront integration

Provider-specific behavior must be an adapter, not duplicated business logic.

Expected callsites to audit and update:

- `src/app/Enums/TelegramOutboundEffectType.php` — add a dedicated Sakurupiah checkout effect if its rendering/recovery differs.
- `TelegramOutboundEffectService::typeForPayment()` — map `provider=sakurupiah` explicitly.
- `CheckoutInstructionDeliveryPipeline` — payment lookup, safe delivery method, timing labels, effect allowlist.
- `TelegramOutboundEffectDispatcher` — map effect to dedicated job.
- `TelegramCheckoutCallbackHandler` — compatibility fallback and supported post-creation behavior.
- `TelegramCheckoutIntents` / `TelegramPaymentInstructionRenderer` — render only safe provider DTO/outcome; never inspect raw provider payload.
- `StorefrontShopCheckout` — post-commit creation/recovery path and idempotent reuse.
- `StorefrontShopPaymentStatusRefresh` and `StorefrontShopSignedInvoice` — provider-specific status/presentation through lifecycle service.
- `StorefrontShopOrderQrisController` or a dedicated signed provider URL route — safe direct QR/hosted presentation.
- `Payment::paymentMethodLabel()` and provider-specific accessors only when a real presentation contract requires them.

For direct QRIS, the presentation DTO must contain only validated image/payload, final payable total, and effective deadline. For hosted invoice, use an allowlisted HTTPS redirect and never return arbitrary provider URL from a customer-controlled field.

A provider error while preparing instructions must not send a misleading “paid” or wrong amount message. The Telegram effect ledger must record `unknown`/`failed` according to transport outcome; provider recovery and Telegram delivery recovery remain separate state machines.

## Error policy

| Observation | Local outcome | Side effects |
| --- | --- | --- |
| Invalid provider config/readiness before request | `InvalidCheckout` or provider unavailable | No new local checkout side effects when preflight fails |
| HTTP 400 / JSON application error before request acceptance is known | Definitive failure only if provider confirms no transaction | No blind retry; record safe error |
| Timeout/connection/5xx after create may have reached provider | `RecoveryPending` / `Payment Verification Pending` | Same Payment recovery, no second create |
| Invalid/missing create schema | Outcome unknown if request may have reached provider | Persist sanitized error/state, reconcile |
| Unknown channel/status/code | Invalid/unverified | No paid/expired transition |
| Callback bad signature/event/body | Reject/record safe event | No lifecycle mutation |
| Valid callback but Payment not found | Safe unlinked event | No new Payment, no fulfillment |
| Valid callback, status lookup unavailable | Verification pending | Queue bounded retry, retain reservation |
| Authoritative paid with exact identity/amount | `OrderLifecycle::applyPaymentPaid()` | One paid transition and normal downstream effects |
| Authoritative expired while pending | `OrderLifecycle::applyPaymentExpired()` or pending expiry owner | Release only through existing lifecycle/inventory rules |
| Paid evidence after terminal local Order | Late Payment | Manual Fulfillment Review; no automatic fulfillment |

Do not retry create based only on HTTP 400/JSON `status=400`. The docs do not define whether a failed response can still create a transaction.

## Configuration policy

Add only the separate sandbox/production API ID and API KEY pairs to the
server-side environment. Stable endpoints, callback/return routes, host
policy, QRIS settlement defaults, and operational budgets stay pinned in
application configuration. Production is enabled automatically when both
production credentials are present; there is no separate enabled flag.

Do not add ENV for catalog labels, product prices, customer-facing channel
metadata, or editable presentation content. Runtime channel data comes from
`list-payment.php`; owner-editable policy belongs in database/Admin.

Readiness must fail closed when:

- the API ID/API KEY pair is incomplete;
- the base URL is not HTTPS in production;
- sandbox credentials are used with the production base URL or vice versa;
- the callback route is missing or not HTTPS where required;
- the QRIS channel is not returned active for the target merchant;
- amount/currency/expiry data cannot be validated;
- queue, scheduler, or payment schema prerequisites are unavailable.

Do not put provider credentials in root or app `.env.example` with sample
values. Blank variable names and comments are acceptable; never copy official
dummy tokens.

## Testing playbook


### Unit

- HMAC signature exact concatenation, lowercase hex, amount string preservation.
- Raw callback signature verification before JSON re-encoding.
- Header event matching and status/status-code pair validation, including expired conflict.
- Typed parser accepts proven string/number variants and rejects overflow/negative/missing identity.
- Channel metadata normalization, active filter, min/max/fee bounds, and runtime-vs-static precedence.
- Expiry timezone/rounding policy and earliest effective deadline.
- Amount/fee/merchant-fee semantics.
- Opaque provider QR persistence/rendering and hosted URL HTTPS/exact-host validation, including documented query parameters.
- Sanitizer removes API keys, signatures, raw tokens, sensitive URL query values, and unnecessary buyer data.
- Plan rejects incomplete/mismatched Sakurupiah snapshot.
- `prepare()` has no transport invocation.
- Outcome mapping does not expose raw field bags.

### HTTP client feature tests

Use Laravel HTTP fakes with no real credentials:

- every environment builds the exact documented URL;
- bearer header and `api_id` are present;
- create uses the qualified form encoding and exact fields;
- signature matches the request body representation;
- status uses `trx_id` and does not invent undocumented reference field;
- history preserves `mechant` spelling;
- response HTTP failures, JSON `status` failures, invalid JSON, missing data, and schema mismatch are distinct;
- lookup retries only bounded transport/408/429/5xx cases after policy confirmation;
- create timeout never automatically calls create twice;
- sensitive headers/body values do not enter logs or test artifacts.

### Callback/lifecycle feature tests

- valid pending callback is idempotent and does not fulfill;
- valid paid callback performs status/history verification before lifecycle;
- callback amount/reference mismatch does not mark paid;
- bad signature/event/status code/JSON is rejected with no Payment transition;
- duplicate callback increments event observation and performs one paid transition;
- unknown provider/payment is safely recorded without creating local rows;
- status lookup outage leaves `Payment Verification Pending` and does not release inventory;
- expired callback releases only through lifecycle and respects local deadline;
- late paid evidence creates `Manual Fulfillment Review` and does not fulfill a terminal Order;
- one checkout produces one Payment and one outbound effect;
- provider switch race preserves one setting snapshot;
- historical provider rows remain on their original compatibility path.

### PostgreSQL concurrency

Run the repository PostgreSQL gate for:

- duplicate customer checkout with same `checkout_key`;
- two provider instruction workers racing the same Payment;
- create outcome unknown racing callback and reconciliation;
- duplicate callbacks racing paid transition;
- expiry job racing paid callback/status refresh;
- provider switch racing checkout;
- stock reservation racing provider preparation failure.

The SQLite suite proves behavior; PostgreSQL is required for row-lock and unique-index claims.

### Sandbox qualification

Use a merchant-owned sandbox credential and the smallest permitted QRIS amount.
Capture only redacted evidence:

1. `list-payment.php` and active QRIS metadata.
2. Raw HTTP status/content type and create response shape.
3. Signature acceptance with the qualified form encoding.
4. Returned `trx_id`, `merchant_ref`, `payment_status`, `expired`, `qr`, and/or `checkout_url`—store only hashes/redacted metadata in evidence.
5. QR scan/hosted redirect behavior and displayed amount.
6. Callback headers/body shape, valid signature, pending/paid/expired transitions.
7. Duplicate callback delivery.
8. `status-transaction.php` and history correlation/amount evidence.
9. Duplicate `merchant_ref` behavior.
10. timeout/5xx/recovery behavior using a controlled fake or provider-approved test.
11. configured production host behavior; never use production payment for exploratory testing.

Sandbox success does not replace production credentials or the production
readiness check.

## Operational unknowns

These provider behaviors remain useful to monitor with redacted sandbox or
provider-approved evidence:

- HTTP status versus JSON `status` semantics and complete error schema.
- Duplicate `merchant_ref` behavior and safe lookup after ambiguous create.
- Form-data versus URL-encoded acceptance, especially arrays.
- Signature canonicalization and amount representation.
- Explicit currency/unit, integer range, fee inclusion, and rounding.
- Current target-merchant channel limits/fees; `BCAVA` docs conflict is a warning that static values are not enough.
- Expiry range, timezone, precision, and boundary behavior.
- `expired` status code `2` versus `-2`.
- Callback event set, ordering, retries, acknowledgement, and replay handling.
- Callback field presence/types and whether `trx_id` is always supplied.
- Amount-bearing authoritative status/history response.
- History pagination/order/date timezone and multi-merchant `mechant` behavior.
- Rate limits, `Retry-After`, timeouts, and provider concurrency limits.
- Production host/network behavior and reverse-proxy source IP.
- Credential rotation/revocation, TLS/network, and retention obligations.

These observations do not add a separate production enablement gate or ENV
switch. Credentials, readiness, and the existing safe outcome rules remain the
activation and integrity boundaries.

## Agent execution checklist

- [ ] Record the v1 channel/presentation/fee/expiry decisions in the implementation issue.
- [ ] Document how each operational unknown is handled before production traffic.
- [ ] Build typed DTOs and provider-specific sanitizer first.
- [ ] Add readiness and config without adding sample secrets.
- [ ] Add preparation adapter and plan snapshot; keep `prepare()` side-effect-free externally.
- [ ] Add post-commit lifecycle, same-Payment recovery, event idempotency, and safe outcomes.
- [ ] Add callback ingress and authoritative verification.
- [ ] Add Telegram/Web Storefront presentation through existing effect/lifecycle seams.
- [ ] Add scheduler, queue, admin readiness, and runbook evidence.
- [ ] Run focused tests, full SQLite suite, PostgreSQL concurrency gate, sandbox qualification, and deployment health checks.
- [ ] Update `docs/agents/codebase.md`, runbook, and ADR only when final ownership/operational decisions are actually accepted.

Never mark a provider response “paid” because a callback contains `status=berhasil` alone. Never send a second create request to compensate for uncertainty. Never expose provider raw payload as a customer or admin DTO.

## Sources

- [Sakurupiah API documentation](https://sakurupiah.id/developers/api-dokumentasi)
- [Official Sakurupiah Postman collection](https://sakurupiah.id/SAKURUPIAH-DOKUMENTASI-API-v2.1Postman.json)
- [Official-linked HMAC PHP example](https://raw.githubusercontent.com/SAKURUPIAH/SIGNATURE_INVOICE/main/PHP_version.php)
- [Repository API research and evidence table](sakurupiah-api-research.md)
