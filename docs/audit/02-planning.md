# Planning remediasi Logic App Bot + Web

Tanggal: 2026-08-17  
Status: **Stage 2 selesai dan dilanjutkan berdasarkan persetujuan eksplisit pengguna**  
Scope: logic aplikasi, business logic, race condition, idempotency, dan flow Telegram/Web checkout, Order, Payment, serta efek outbound Telegram. Tuning resource, tuning PostgreSQL, cleanup disk, image Docker, dan resource limit tetap di luar scope.

## 1. Keputusan yang disepakati

### 1.1 Satu boundary untuk temuan outbound

Temuan #1, #2, #3, dan #6a memakai satu shared **Telegram Outbound Effect Ledger**. Ledger ini adalah transactional outbox yang menyimpan efek bisnis Telegram dalam database yang sama dengan perubahan Order, Payment, inventory, Sales Proof, atau aggregate sumber lainnya.

`orders.checkout_key` tetap menjadi mekanisme idempotensi mutasi checkout. Ia tidak diganti dan tidak diperluas menjadi idempotensi pengiriman Telegram. Laravel `ShouldBeUnique`, `afterCommit()`, queue retry, dan cache lock tetap boleh dipakai sebagai lapisan pendukung, tetapi bukan bukti bahwa Telegram menerima tepat satu pesan.

### 1.2 Boundary domain

Ledger hanya mencakup efek bisnis yang harus durable:

- instruksi pembayaran checkout melalui `sendMessage`, `sendPhoto`, atau media yang setara;
- receipt dan delivery credential customer;
- notifikasi Order/Payment untuk admin;
- forwarding payment proof;
- Sales Proof Post ke Store Channel;
- Admin Broadcast per recipient;
- stock dan product-price notification.

Ledger tidak mencakup operasi ephemeral atau cleanup pada pass awal:

- `answerCallbackQuery`;
- `sendChatAction`;
- `deleteMessage` untuk pembersihan pesan lama;
- operasi edit yang belum memiliki kebutuhan retry durable yang jelas.

Ledger merepresentasikan logical delivery unit. Efek tingkat tinggi yang terdiri dari beberapa panggilan Telegram, misalnya receipt yang terpecah menjadi beberapa chunk, memiliki `effect_group_key` dan urutan unit. Setiap panggilan durable menjadi unit terpisah agar chunk yang sudah berhasil tidak dikirim ulang ketika chunk berikutnya ambigu.

### 1.3 Mengapa transactional outbox

Checkout saat ini commit lebih dahulu, lalu callback baru dispatch job. Transactional outbox menulis row pending dalam transaction yang sama dengan Order, Payment, reservation, dan credential allocation. Dispatcher dapat berjalan setelah commit, dan scheduler dapat menemukan row yang tidak pernah mendapat queue dispatch.

Outbox tidak menjadikan Telegram exactly-once. Dokumentasi Telegram Bot API tidak mendokumentasikan idempotency key untuk `sendMessage` atau `sendPhoto`. Karena itu efek ambigu tidak boleh diperlakukan sebagai failure biasa.

### 1.4 State effect

State canonical yang direncanakan:

```text
pending -> sending -> sent
                    -> failed
                    -> unknown -> manual_review

sending dengan lease kedaluwarsa -> unknown
failed yang retryable -> pending
manual_review -> sent setelah operator memverifikasi delivery
manual_review -> pending hanya melalui explicit resend baru yang tercatat sebagai versi effect baru
```

Makna state:

- `pending`: effect sudah tercatat dalam transaction sumber dan belum dicoba;
- `sending`: satu worker memiliki lease dan boleh melakukan Telegram I/O;
- `sent`: Telegram memberi response sukses yang memuat message identifier yang dibutuhkan;
- `failed`: response definitif menyatakan request tidak diterima atau validasi lokal gagal sebelum side effect;
- `unknown`: koneksi/worker mati setelah atau ketika Telegram mungkin sudah menerima request, sehingga outcome remote tidak dapat dibuktikan;
- `manual_review`: operator perlu memverifikasi Telegram atau memilih explicit resend. State ini bukan retry otomatis.

Timeout, connection exception, HTTP failure setelah request mungkin terkirim, dan worker crash ketika state `sending` tidak boleh kembali ke `pending`. Keputusan konservatif ini menerima kemungkinan pesan tidak terkirim untuk menghindari duplicate credential, duplicate payment instruction, atau duplicate public Sales Proof.

### 1.5 Ownership dan lease

Business service pemilik aggregate membuat effect melalui recorder dalam transaction yang sama. Dispatcher/job tidak membuat Order, Payment, inventory sale, atau transition baru.

Worker mengambil effect dengan `lockForUpdate()` dan claim token yang memiliki lease. Finalisasi hanya boleh dilakukan jika token masih cocok. Worker mengirim Telegram di luar transaction database, lalu menulis `sent`, `failed`, atau `unknown`. Recovery command mengubah `sending` yang lease-nya kedaluwarsa menjadi `unknown`, bukan langsung mengirim ulang.

Queue existing tetap dipakai:

- `telegram-outbound` untuk checkout instruction;
- `notifications` untuk receipt, lifecycle notification, dan Sales Proof;
- `telegram-broadcast` untuk recipient broadcast, stock, dan price notification.

### 1.6 Payload dan data sensitif

Effect menyimpan referensi aggregate, target Telegram, locale/template version, group/sequence, dan metadata retry. Customer-visible content harus stabil terhadap perubahan konfigurasi setelah checkout. Bila payload atau render context disimpan, field yang mungkin memuat credential harus encrypted at rest dan tidak pernah masuk log, exception body, fixture, atau output command.

QR image bytes dan Telegram bot token tidak disimpan di ledger. QR dipresentasikan melalui state provider yang sudah durable dan trusted. Untuk KlikQRIS, worker tetap memakai `KlikQrisNativeQrisService` dan Payment yang sama. Tidak boleh membuat Payment kedua atau fallback otomatis ke Manual.

### 1.7 Legacy claim sebagai expand-contract

Kolom claim lama tidak langsung dihapus. Rollout dilakukan bertahap:

1. tambah ledger dan adapter tanpa memutus projection lama;
2. migrasikan satu flow end-to-end pada satu waktu;
3. selama transisi, update projection lama bila dashboard atau cleanup masih membutuhkannya, tetapi ledger menjadi source of truth untuk flow yang sudah migrated;
4. backfill hanya row yang memiliki bukti belum pernah dicoba, yaitu state business eligible, sent marker NULL, dan tidak memiliki claim/attempt evidence. Row dengan claim lama yang stale dipetakan ke `unknown` atau manual review, bukan blind resend;
5. jalankan laporan bahwa tidak ada caller/row aktif yang memakai claim lama;
6. hapus kolom dan query legacy dalam ticket contract terpisah.

`afterCommit()` boleh tetap dipakai untuk wake-up dispatch setelah row outbox tercatat, tetapi correctness tidak bergantung pada callback process yang hidup setelah commit. Scheduler recovery harus dapat menemukan row pending tanpa claim.

## 2. Temuan dan keputusan remediasi

| Temuan | Status validasi | Keputusan planning | Ticket family |
|---|---|---|---|
| #1 Claim reset pada exception ambigu | Confirmed | Shared effect ledger, typed outcome, `unknown` dan manual review | Outbound effect migrations |
| #2 Checkout dispatch gap | Confirmed | Recorder dipanggil di transaction checkout, dispatcher/recovery membaca `pending` termasuk claim NULL | Checkout effect |
| #3 Sales Proof `sending` stranded | Confirmed | Ledger menjadi source of truth, `sending` lease expired menjadi `unknown`, dashboard operator menampilkan review | Sales Proof effect |
| #4 `CustomerLoginLink` guarded kosong | Partially true | Explicit allowlist, `used_at` hanya melalui assignment domain/internal | Security hardening |
| #5 Admin limiter per login+IP | Confirmed | Pertahankan bucket identity dan tambah bucket global IP dengan increment atomik | Security hardening |
| #6a Claim-reset coverage tambahan | Confirmed sebagai perluasan #1 | Semua durable Telegram send masuk policy yang sama, bukan patch per job | Outbound effect migrations |
| #6b check/hit limiter non-atomik | Observation | Ditangani bersama #5, tidak dibuat finding terpisah | Security hardening |

## 3. Coverage effect yang direncanakan

| Effect | Aggregate source | Queue | Kebijakan unknown |
|---|---|---|---|
| Checkout instruction | Order + Payment | `telegram-outbound` | Tidak blind retry; customer/admin review sebelum resend |
| Receipt/credential | Paid Order + stock allocation | `notifications` | Paling konservatif; verifikasi delivery sebelum explicit resend |
| Admin pending/paid/expired | Order lifecycle | `notifications` | Manual review atau explicit retry, tanpa reset claim |
| Manual payment proof forwarding | Payment proof | `notifications` | Manual review karena attachment bisa sudah diterima |
| Sales Proof Post | Paid Order Proof Event + Store Channel | `notifications` | Tidak blind retry public post; operator mark sent atau explicit new version |
| Admin Broadcast recipient | Broadcast recipient | `telegram-broadcast` | Recipient-level unknown, batch lain tetap berjalan |
| Stock notification | InventoryMovement | `telegram-broadcast` | Recipient/effect-level review, pertahankan rate limit |
| Price notification | PriceChangeEvent + recipient | `telegram-broadcast` | Recipient/effect-level review, pertahankan locale dan batching |

## 4. Skema database garis besar

Nama tabel yang direncanakan: `telegram_outbound_effects`.

Kolom konseptual minimum:

- identity: `id`, unique `effect_key`, `effect_group_key`, `sequence`, `effect_type`;
- source: `source_type`, `source_id`, optional `parent_effect_id` atau group reference;
- target: `target_type`, `telegram_chat_id`, optional recipient/customer reference;
- delivery: `state`, `attempts`, `next_attempt_at`, `lease_until`, `claim_token`, `telegram_method`, `telegram_message_id`;
- rendering: locale/template version, sanitized immutable context or encrypted payload reference;
- outcome: `sent_at`, `unknown_at`, `failed_at`, `manual_review_at`, redacted `last_error_code`/`last_error`;
- audit: created/updated timestamps and optional operator resolution metadata.

Index/constraint minimum:

- unique logical effect key;
- lookup pending/retryable by state and next attempt;
- lookup expired leases by state and lease time;
- lookup source/group and sequence;
- no index or column should expose plaintext credential, Telegram token, or QR secret.

Exact column types, enum representation, and whether encrypted context is split from source references must be decided in the implementation ticket against PostgreSQL and SQLite test compatibility. The semantic invariant is more important than the ORM shape: one logical delivery unit has one authoritative state and one ownership token.

## 5. Urutan dependency dan vertical slices

1. **Foundation plus manual checkout effect**. Introduce the ledger state machine, claim/finalize service, dispatcher/recovery, and migrate QR Manual checkout instructions end-to-end. This proves the commit-to-dispatch gap and ambiguous send behavior on the simplest customer payment path.
2. **Operator outbound delivery review**. Add a safe admin surface or command-backed admin flow for `unknown`/`manual_review`, with no credential or raw payload leakage. This is required before migrating sensitive receipts and public Sales Proof.
3. **Provider-specific checkout instructions**. Migrate KlikQRIS, Pakasir, and Binance instruction adapters while preserving provider-specific lifecycle, TTL, safe outcomes, and same-Payment recovery.
4. **Receipt and credential delivery**. Migrate multi-unit receipt delivery with ordered units, secret redaction/encryption, and no duplicate credential resend after ambiguity.
5. **Order/admin/payment-proof notifications**. Migrate lifecycle notifications and manual payment-proof forwarding into the same ledger while preserving queue ownership and payment transition ownership.
6. **Sales Proof Post**. Migrate public channel publication, projection/status, stale recovery, operator review, and explicit resend semantics.
7. **Broadcast recipient delivery**. Migrate Admin Broadcast recipient units while preserving materialization, literal content, per-recipient locale, batching, and flood control.
8. **Stock and price notifications**. Migrate movement/event recipient units while preserving rate limits, snapshot semantics, and existing notification queues.
9. **Legacy contract**. Remove old claim columns/queries only after every migrated flow and the operational report prove that the ledger is authoritative.
10. **Independent hardening**. Explicit mass-assignment allowlist and global per-IP login limiter can be implemented independently of the outbox sequence.

Overlap is intentional. Tickets 1 through 8 use one table, state machine, claim protocol, recovery semantics, and outcome classifier. No feature may introduce a second outbound state table or reset a legacy claim as a substitute for finalizing the shared effect.

## 6. Rollout strategy

### Expand

- Deploy schema and domain code that can coexist with all existing jobs.
- Add ledger health/reporting and feature flags per effect family.
- Keep old columns and projections readable.
- Create new effects only for a migrated family. Do not backfill ambiguous old attempts automatically.

### Migrate

- Enable one family at a time, starting with QR Manual checkout.
- Run SQLite feature tests and PostgreSQL `composer test:concurrency-gate` for checkout, row locks, recovery, and effect ownership.
- Observe counts of pending, sending, sent, failed, unknown, and manual review. Compare ledger success with old projection fields.
- Keep an operator rollback switch that stops new ledger dispatch for the family while leaving existing sent records immutable.

### Contract

- After all effect families are migrated and stale-row reports are clean, stop writing legacy claim fields.
- Remove legacy recovery branches and old claim columns/indexes in a separate deploy.
- Keep historical outcome/audit records in the ledger. Do not delete unknown effects as cleanup.

No downtime is required by design, but each schema migration must be backward compatible with the currently running worker version. Database migration ordering and worker restart sequencing belong in the implementation/runbook ticket.

## 7. Testing strategy

### Shared effect boundary

Use public or highest available seams:

- Telegram webhook callback through `POST /api/webhook/telegram`, callback processing, checkout transaction, and queue dispatch;
- real local HTTP boundary for the Telegram adapter that accepts the request and disconnects before the response;
- `telegram:recover-outbound` or its replacement command against database state;
- admin HTTP flow for manual review and explicit resend.

Assertions must cover both customer-visible behavior and durable state. A fake client-only unit test is useful for classification but cannot replace the public boundary test.

### PostgreSQL concurrency

Run `composer test:concurrency-gate` for:

- two checkout callbacks with the same `checkout_key`;
- two workers claiming the same effect;
- lease expiration versus finalization with mismatched token;
- transaction commit followed by simulated process death before queue dispatch;
- Sales Proof publication and payment lifecycle locks;
- global login limiter behavior with concurrent attempts when the production cache backend is available.

### Prior art to extend

- `Feature/Telegram/WebhookIngressTest` and `Feature/CartCheckoutTest` for webhook and checkout public paths;
- `Feature/Telegram/KlikQrisCheckoutTest`, `Feature/Telegram/PaymentProofAdminCallbackTest`, and `Feature/AdminTelegramBroadcastQueueTest` for provider, proof, queue, and recovery behavior;
- `Feature/CustomerAccountTest` for customer login-link behavior;
- existing PostgreSQL checkout and KlikQRIS concurrency tests for row-lock boundary;
- broad non-draft suite as regression gate.

### Security and redaction

Every migrated effect test must assert that credential text, QR content, bot token, recipient Telegram IDs where policy forbids them, and raw provider payloads do not appear in logs, exception output, command output, or audit metadata.

## 8. Risiko dan rollback

| Risiko | Mitigasi |
|---|---|
| Telegram menerima request lalu response hilang | State `unknown`, no automatic resend, operator verification |
| Worker mati pada `sending` | Lease recovery maps to `unknown`, never to blind `pending` |
| Outbox row tidak tercatat | Row dibuat di transaction sumber; failed transaction membuat Order/Payment juga rollback |
| Effect tercatat tetapi queue dispatch gagal | Scheduler scans `pending` rows including NULL claim and dispatches them |
| Payload customer berubah sebelum retry | Immutable render context/version, encrypted sensitive context, or explicit source snapshot |
| KlikQRIS lifecycle dipanggil ulang | Reuse same Payment and existing single-flight/recovery service |
| Duplicate effects during dual-write rollout | One feature flag/source of truth per family, effect key uniqueness, no simultaneous old/new senders |
| Operator salah resend | Resend creates new version and requires explicit confirmation; old unknown remains audit record |
| Migration/worker version mismatch | Expand-contract schema, deploy additive migration first, contract only after report |
| Login limiter terlalu agresif | Separate identity and IP thresholds, configurable decay, clear identity bucket on success, monitor false positives |

Rollback for an effect family disables new ledger dispatch and leaves immutable sent/unknown records. It does not reset unknown to pending and does not send from both legacy and ledger workers. A rollback that requires resending an unknown effect must be an explicit operator action with duplicate warning.

## 9. Hardening kecil tanpa ADR

### CustomerLoginLink

Temuan ini partially true dan tidak membutuhkan architectural decision. Ganti `$guarded = []` dengan explicit fillable allowlist untuk `email`, `token_hash`, `expires_at`, dan `requested_ip`. `used_at` tetap diubah melalui assignment domain setelah row terkunci, bukan melalui mass-assignment input.

### Admin login limiter

Pertahankan bucket per normalized login + IP sebagai defense-in-depth, tambahkan bucket global per IP, dan gunakan increment atomik pada backend cache production. Rekomendasi awal adalah 5 percobaan per identity/IP dalam 60 detik dan 10 percobaan total per IP dalam 60 detik. Nilai harus terisolasi sebagai policy/config agar dapat dituning tanpa mengubah aturan controller. Login sukses membersihkan bucket identity, sedangkan bucket IP tidak dibersihkan oleh satu login sukses.

## 10. Checklist perubahan untuk AI

- Istilah baru: `Telegram Outbound Effect`, `Ambiguous Telegram Delivery`, dan `Manual Delivery Review` ditambahkan ke `CONTEXT.md` tanpa detail implementasi.
- ADR baru mencatat keputusan transactional outbox, boundary durable business send, dan larangan blind retry pada outcome ambigu.
- Business rule tetap berada pada recorder/dispatcher/effect policy service, bukan controller atau queue adapter.
- Payment lama, provider selection, KlikQRIS same-Payment recovery, dan InventoryLedger tidak berubah.
- Retry, idempotency, lease, dan row lock diuji pada boundary SQLite untuk behavior serta PostgreSQL untuk concurrency.
- Secret, credential, QR payload, dan token tidak masuk logs/docs/fixtures.
- Scheduler/queue/runbook changes dicatat bersama ADR dan issue implementasi.
- Setiap ticket mewajibkan test/lint/build yang relevan dan hasilnya dicatat.

## 11. Sumber best practice yang dipakai

- Laravel Queues 13.x: unique jobs, retry/backoff, dan dispatch setelah transaction: https://laravel.com/docs/13.x/queues
- Laravel Queries 13.x: pessimistic locking dan `lockForUpdate`: https://laravel.com/docs/13.x/queries
- Laravel Rate Limiting 13.x: limiter keys, decay, dan increment behavior: https://laravel.com/docs/13.x/routing#rate-limiting
- Laravel Eloquent mass assignment: https://laravel.com/docs/13.x/eloquent#mass-assignment
- Telegram Bot API: `sendMessage`, `sendPhoto`, response contract, dan tidak adanya idempotency key outbound yang didokumentasikan: https://core.telegram.org/bots/api
- AWS Prescriptive Guidance, Transactional Outbox: https://docs.aws.amazon.com/prescriptive-guidance/latest/cloud-design-patterns/transactional-outbox.html
- AWS Builders' Library, Making retries safe with idempotent APIs: https://aws.amazon.com/builders-library/making-retries-safe-with-idempotent-APIs/
- AWS SQS, at-least-once delivery: https://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSDeveloperGuide/standard-queues-at-least-once-delivery.html

## 12. Gate result

Stage 2 planning telah disetujui untuk dilanjutkan secara autonomous oleh pengguna pada 2026-08-17. ADR dan glossary diperbarui sebagai keputusan accepted. Tahap berikutnya adalah menulis spec mirror, membuat spec issue, lalu memecahnya menjadi tracer-bullet ticket dengan dependency native GitHub bila API tersedia.
