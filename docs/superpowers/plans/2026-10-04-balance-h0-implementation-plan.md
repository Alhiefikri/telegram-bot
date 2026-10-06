# Implementasi saldo berdasarkan keputusan H0-v1

Tanggal: 2026-10-04. Status: **Planning teknis diotorisasi Owner; implementasi mengikuti dependency gates**. Sumber keputusan: [H0-v1 #538](../../balance-h0-operational-decisions.md), [ADR-0020](../../adr/0020-single-owner-balance-adjustments.md), [A0 audit](../../balance-a0-baseline.md) dan [lock sites](../../balance-a0-lock-sites.md). Scope epic tetap [#536](https://github.com/Alhiefikri/telegram-bot/issues/536); H0 tidak menggantikan semua ticket dengan satu fitur manual yang mengabaikan receipt/provider/accounting.

Owner meminta tanpa interview tambahan: agent menentukan planning agar flow sederhana, aman dari race, kebocoran/security issue dan beban berlebih. Plan ini mengubah kontrak draft dual-approval/cap/beta sesuai keputusan tertulis; semua financial/ownership/durability invariants tetap berlaku. Tidak ada runtime change atau deploy dalam patch H0.

## Flow produk

Admin memiliki satu area Saldo: daftar customer bersaldo positif dan pencarian exact Telegram ID. Pengiriman IDR hanya memerlukan nominal, alasan dan tombol lanjut. Konfirmasi memperlihatkan penerima (username jika tersedia + ID), amount, lalu meminta password. Jangan memakai form set-balance, mandatory bukti upload atau dropdown chart accounting. Jika kasus terkait record existing, tampilkan tindakan penyelesaian dari detail Top-up/refund atau satu pilihan kaitkan transaksi; wajib menggunakan source tersebut dan tidak meninggalkan workflow original creditable.

History paginated menampilkan kirim/full reversal dan detail yang diperlukan. Tombol Batalkan hanya untuk pengiriman eligible; preview, password, lalu seluruh nominal sekali. Jika available funds tidak cukup, informasikan review tanpa partial debit/negative balance. Customer dan Owner mendapatkan pesan sesuai keputusan. Jangan menahan layar untuk menunggu Telegram.

USDT: input manual → preview exact amount/terms → satu invoice pada rail qualified Binance Pay ID/BSC → instruksi/token/network/received amount/deadline → verifikasi → credit dan receipt. Satu active funding target lintas rail per Customer; top-up berikutnya kembali ke target existing. Salah format diberi contoh perbaikan singkat. Salah token/amount/network tidak auto-credit; support Owner menyelesaikan review berdasarkan verified funds/source. UI Customer history mengikuti IDR, tanpa menu/halaman tambahan yang tidak diperlukan.

## Baseline yang menentukan implementasi

| Evidence sekarang | Implikasi pekerjaan |
| --- | --- |
| StoreBalanceLedger memiliki source/key uniqueness, account locks dan overflow guards; IDR history canonical | Tambah operasi typed adjustment/reversal ke owner ini, bukan writer lain atau direct controller balance update |
| StoreBalanceEntryKind::direction dan signed SUM ledger/reconciliation hanya mengenali PurchaseDebit sebagai debit | Sebelum reversal dipakai, seluruh consumer signed amounts harus memahami debit reversal; uji history/reconciliation/refund/reporting, bukan enum saja |
| Ledger sebagian memakai full-history SUM, sedangkan creditTopUp memakai latest cursor | Hot mutation memakai projection/cursor yang dijaga DB immutability; historical verification melalui bounded reconciliation di luar critical locks, tanpa menurunkan drift detection |
| StoreBalanceOwnerCompensationService dan settlement memakai Customer → account → Top-up, resolution_type dan status Compensated | Manual recovery wajib satu resolution dengan settlement existing; jangan independent manual credit yang bisa dibayar callback lagi |
| AdminPasswordConfirmationController menyetel passwordConfirmed session; existing middleware memungkinkan periode fresh auth | Per-action money confirmation tidak cukup memakai global password.confirm; password proof harus terikat satu immutable request/action dan tidak execute pending BSC action |
| Customer.telegram_id cast integer; username/telegram_id hidden pada serialization | Exact lookup parse bounded string tanpa JS Number/float/truncation; projection response eksplisit, jangan serialize Customer model penuh |
| StoreBalanceActivityService PAGE_SIZE=5, cursor, limited columns; owner compensation list paginated | Reuse pola keyset/bounded query, active-tab isolation dan privacy |
| TelegramOutboundEffectService/protocol/recovery ialah sole ledger seam | Tambah effect families/materialization di seam ini; tidak membangun Telegram outbox kedua |
| composer.lock brick/math 0.18.0, Laravel ^13.8; PostgreSQL 16 | Context7 Laravel 13/PostgreSQL 16; arithmetic per installed 0.18 signatures, tidak upgrade library untuk plan ini |

## Tahap kecil dan completion evidence

Setiap tahap merupakan commit atau beberapa commit kecil yang menjaga existing behavior tetap berjalan. Migrations additive/backward-compatible; UI baru tetap tidak aktif sampai acceptance yang relevan lulus. Nama PHP/tabel baru di bawah adalah rancangan seam, bukan symbol yang sudah ada.

### 1. Policy handoff dan source authority (#538 → #541/#543/#548)

Simpan keputusan H0-v1 dan ADR-0020; policy runtime baru di DB/Admin. Mode single_owner eksplisit hanya untuk adjustment/reversal Owner, bukan Gate::before universal. Business cap nullable berarti no business cap, tidak mematikan representational/overflow checks. Audience semua Customer existing; active funding quota satu. Terms/policy version dibinding pada intent. Amend kontrak ticket draft yang masih menyebut distinct actor/beta cap; technical/provider/production gates tetap mandatory.

R1 #541 masih OPEN saat perencanaan: global receipt registry/aliases/parent claims harus selesai sebelum I1 existing dependency dimulai. R2 #542 mengintegrasikan/backfill setiap writer sebelum activation funding. Jangan bypass dependency untuk mengejar form yang terlihat selesai. H0 accepted operational policy bukan receipt qualification.

Evidence: schema/policy version behavior, exact contract sources dan gate states; tidak ada ENV baru untuk fees/limits/presentation, fake receipt atau aktor fiktif.

### 2. Per-action confirmation dan lockout (bagian #543/#544)

Bangun owner-only confirmation boundary untuk IDR request/reversal. CSRF/auth/session tetap enforced; parse input/lookup sebelum preview. Preview 5 menit, nonce opaque/server-generated, immutable actor/Customer/IDR/amount/reason/source/action/version. Server verifies Owner password setiap action; tidak menyimpan plaintext/hash credential di preview/queue/audit dan tidak menerima proof lintas session/request. Jangan mengubah password timeout global atau menjalankan pending BSC mutation sebagai efek samping.

Lockout per Owner: 5 gagal pada sliding window 15 menit, lockout 15 menit. Durable per-actor state melindungi reset akibat Redis loss; atomic transitions mencegah concurrent attempts melewati threshold. Atomic bounded verification-slot/lease sebelum KDF untuk membatasi expensive password checks, tanpa menahan money/account transaction selama hashing. Proof successful verification hanya in-process/one-action, terikat immutable request/session/credential version; final transaction rechecks authorization/current credential version/state. Lockout/role revoked/expired preview gagal closed; tidak retry business auth failure.

Alert lockout dibuat satu kali pada transition dengan unique actor/episode identity, disimpan durable. Failure accounting tidak rollback bersama money transaction. Timestamp expiry dicek pada request; no per-actor scheduler. Lease token/expiry menghindari stuck verification saat process crash; no external calls inside locks.

Evidence: Owner-only request, permission removal/password change, missing session/CSRF, expired/tampered preview, forged/reused token, 5th concurrent failure, no alert storm, no reset on Redis restart, no password serialization, bounded verification work.

### 3. Immutable adjustment source dan IDR posting (#543)

Additive request menyimpan Customer/actor/currency/amount/reason/category/source binding/nonce/fingerprint/version dan state; linked original source bila record exists. Simple UI reason dipetakan ke typed domain authority server; client tidak boleh memilih arbitrary source string untuk menulis ledger. Untuk unrecorded case, Owner attestation dicatat sebagai manual liability adjustment dengan counterpart yang jujur; bukan VerifiedFunding/settled provider asset. Tidak mengarang external receipt untuk memenuhi category funded_transfer. Promotional grant tidak menjadi pilihan default.

Extend canonical StoreBalanceLedger dengan typed approved adjustment/full reversal operations. DB uniqueness melindungi one request/source → one posting; compare complete fingerprint (Customer, amount, reason/source, action/original) sebelum replay. Empty-account create memakai conflict-safe insert/savepoint lalu row lock. Update direction semantics untuk seluruh negative kinds pada ledger SUM, reconciliation, activity dan reporting; preserve old entry values/history.

Transaction: canonical Customer/account locks jika flow memakai Customer → request/source/refund resolution → receipt claim bila applicable → entry/accounting/audit/durable notification. Ikuti per-flow A0, jangan menambahkan Customer lock setelah account/Order; linked refund flow memakai existing lifecycle graph. Bounded retries pada outer boundary saja, maximal lima; no HTTP/Telegram/RPC/custody/KDF in retried closure. Unique conflicts memakai savepoint atau fresh transaction, bukan catch lalu melanjutkan aborted PostgreSQL transaction. Entry/projection/request/accounting/audit/outbound obligation commit bersama.

Evidence: no pending credit, matching replay same entry, different payload conflicts, competing first-account creation, drift/overflow fail-closed, inject failure pada setiap write menghasilkan rollback total, DB append-only/financial FK protection. Tidak memakai SQLite untuk membuktikan locking.

### 4. Link recovery dengan existing Top-up/refund (#541/#542/#543)

Manual + callback + polling + legacy compensation + partial/full refund bersaing pada economic resolution yang sama. Saat record exists, recovery action tidak boleh terlepas dari source dan receiver Customer original. Settled/compensated/resolved target tidak credit lagi; late observed money sesudah compensation/reversal masuk treasury/review, bukan membuka source. Reversal manual recovery membalik available credit tanpa membuat invoice original creditable.

Refund-to-balance untuk Payment external harus mendaftarkan entitlement original currency/amount dan settlement choice; tidak credit IDR untuk USDT secara implicit atau menandai seluruh Payment refunded setelah hanya partial amount. Unrecorded Owner request tidak mengklaim membuktikan economic receipt uniqueness antar-request; residual risk audit tetap eksplisit.

Evidence: PostgreSQL barriers benar-benar overlap manual vs callback/poll, compensation vs callback, full/partial refunds dan reversed recovery vs late receipt. Assert satu consumed receipt/resolution + satu authorized credit/counterpart, proper loser/replay behavior, original entitlement caps, no reopened economic identity.

### 5. Full reversal (#545)

Server-load original eligible adjustment, immutable Customer/IDR/amount/source. Owner password per-action dan preview. Account/source/original reversal locks sesuai graph; DB unique original reversal claim. Debit penuh hanya jika available cukup; debit condition checks affected row/row locked version, no negative balance. Original entry/audit tetap append-only; no partial reversal. Recovery linked original remains resolved. Insufficient goes review dengan safe message, tanpa hidden partial write.

Evidence: reverse/reverse, reverse/purchase, reverse/refund races; foreign/non-adjustment/original-currency mismatch rejected; replay same effect; spent funds no debit; combined reconciliation recognizes reversal direction.

### 6. Dashboard sederhana dan read models (#544/#557)

Owner-only Saldo area dengan positive balance list (IDR>0 OR USDT>0), exact ID lookup, identity/amount preview dan history. Unknown ID does not create Customer. Username optional/escaped/display-only. Server validate bounded decimal string ID terhadap DB supported range; do not round numeric JSON/JavaScript identifier. UI kirim/resend feedback distinguishes applied/replayed/conflict/expired; disable double-click for UX but DB guards remain authority. Password cleared after action/error and not persisted in browser storage.

Backend pagination/keyset + proper indexes; select required columns, eager-load bounded identities, active-tab query isolation. Zero accounts remain in storage/history. Read balance from consistent projection/cursor snapshot, no SUM per list row or network refresh. Detail lists original + reversal/source refs tanpa raw provider proof/password/private metadata. Mobile flow keyboard/focus/accessibility uses established Admin patterns; no unsolicited extra form choices.

Evidence: registered/missing/changed username, malformed/large ID, ownership/auth/CSRF, HTML/XSS in username/reason, stale tab/session, password reuse between two distinct requests, duplicate submit, responsive browser flow, bounded query count across page sizes.

### 7. Durable notifications dan ringan saat recovery (#543/#544/#545/#552/#558)

Materialize one durable notification obligation per money effect/recipient untuk Customer dan Owner, unique entry/action/recipient identity. Customer receipt uses currency/amount/ref, no internal reason/proof echo by default. Owner alert has operational detail yang disanitasi. Telegram delivery terjadi setelah commit melalui sole effect ledger; after-commit dispatch hanya wakeup. Recovery from indexed DB rows survives Redis loss/process death. Ambiguous delivery memakai existing manual review, bukan blind resend.

Reuse scheduler/worker recovery sweep existing, bounded batches with next_attempt/lease-token/backoff and caps; do not scan all Customers or all historical ledger. Tidak menambah cron per customer/top-up/password attempt. Expired lockout/preview tidak memerlukan job. Successful notification failure tidak repeat money posting, dan every password failure tidak emit notification.

Evidence: crash before/after commit/dispatch/send, notification insert failure rollbacks money where required, Redis restart, lease expiry fencing, one effect per event, bounded backlog recovery and no sensitive payload leak.

### 8. Exact USDT accounts/policy/funding (#546/#548/#551)

USDT separate account/ledger, integer micro-USDT, no IDR migration/dual-write/implicit FX. Checked addition/subtraction into supported BIGINT; no business maximum does not mean native int unlimited. Parser bounds raw input length (technical initial budget 64 characters), ASCII positive whole/fraction, at most one comma/dot separator, max 6 fractional digits; rejects mixed/grouped/scientific/sign/zero/overflow. Normalize string before Brick parsing. Installed 0.18 BigDecimal::of + multipliedBy exact integer or existing pure exact parser; toInt overflow/rounding exceptions handled without leaking input/secrets. BinanceUsdtAmount accepts signed history values and permissive trailing fractional zeroes, so do not expose it unchanged as customer input validator.

Policy version/terms/fee 0/input-only audience/quota snapshots from DB. Confirmation TTL 10 minutes. One active Customer funding slot across rails claimed durably with Customer/account/source lock + uniqueness; expired but unresolved funds keep slot held. Same confirmation/source returns original target. Deadline snapshotted from existing rail-specific dashboard setting; changing setting cannot retarget existing invoice.

Evidence: comma/dot normalization, tiny/boundary/overflow/very-long/mixed input, no rounding, foreign customer/chat/root/nonce, concurrent intents/confirmations/switch-rail, deadline change and expired-confirmation replay; no provider effect before confirm.

### 9. Provider/receipt qualification dan settlement (#539/#540/#541/#542/#549/#550/#552/#553)

Owner wants Binance Pay ID and USDT BEP20 BSC familiar checkout UX; this does not qualify funding attribution. H1/A1 verify actual account contract/permissions/receiver ownership/finality. BSC permanent exclusive Customer receiver globally disjoint checkout pool; no private key in app. Network/contract/decimals/successful Transfer/receiver/canonical finality and exact amount must match; amount-unique discovery/shared receiver is not Customer attribution. Binance authenticated binding must tie receipt/reference/payer to intent/Customer; C2C/history matching amount alone cannot enable funding. If absent, rail stays unavailable and blocker evidence is recorded; don't fake a usable provider.

Physical receipt aliases + BSC parent tx/log uniqueness global across all targets; qualified consumption one target only. Adapter-observed create timeout stays creation_unknown with same reference, no fallback invoice/receiver. Under/over/wrong-token/multi-transfer/nonrepresentable/late/reorg cases reviewed without automatic credit/FX/refund. Manual USDT resolution must use verified attributable received funds and same claim/resolution, never arbitrary grant. Preserve existing legacy provider snapshots/receipts.

Evidence: wrong/fake USDT symbol contract, wrong chain/receiver/Customer, token decimal remainder, alias vs log consumer, multiple logs targets, checkout vs funding receipt races, network outage/create timeout/reorg/late compensation and finalized deposit attribution. Production receipts/funds are outside testing authorization.

### 10. Reconciliation, performance/security gate dan release preparation (#547/#554–#562)

Reconcile projection vs signed ledger, receipt/resolution vs postings, and currency-specific liability/unresolved funds vs verified asset evidence. Bounded cursor scans with persistent progress; no silent fix. Per-account drift freezes that account; aggregate drift pauses new funding and emits transition alert. Owner-only resume needs consistent evidence and audit. Checkout/refunds consume original currency price/entitlement; no repricing/FX or aggregate mixed-currency reports.

Use existing verification entry points: composer test (focused behavior first), composer test:foundation, composer test:concurrency-gate against disposable PostgreSQL, npm run test:js, npm run build and npm run test:browser:admin for changed UI. V1 adds real process-barrier race/crash fixtures to existing concurrency infrastructure; SQLite/sync is insufficient. V2 uses reproducible dataset/load phases and measured query counts, p50/p95 latency, DB lock wait/retries, process RSS, queue depth, sweep duration/rows and outbound calls. Compare small vs growing historical data and warm/long-lived workers; strict budget regression decided from measured baseline, never invented benchmark success.

Security includes Owner session/password compromise boundaries, authorization revocation, CSRF/XSS, stale-version/nonce/credential proof replay, enum/source spoofing, serialized secrets, proof URL SSRF prevention, BSC/provider input budgets and full claim isolation. Memory/performance checks ensure bounded materialization (no all rows in memory), no N+1/full SUM per mutation/display, no busy retry loops, bounded leases and backoff, no leaked media/temp resource lifecycle or alert storms. Leak diagnosis fixes measured issue rather than adding perpetual monitoring jobs.

O2 runbooks cover backup/restore/zero-trust reconciliation after restore, role/credential incident revoke, receiver rotation snapshot, pause/resume and rollback without rewriting ledger. O3 readiness checks exact release/schema/contracts and outstanding operator gates. H2 alone authorizes production canary via GitHub Actions main/VPS. Do not close parent/downstream because docs/fakes are green.

## Completion mapping

| Concern | Proof needed before relevant feature activation |
| --- | --- |
| Simple UX | Tested exact-ID → amount/reason → preview/password → receipt, and full reversal/history; mobile browser + JS behavior |
| Race/double credit | Actual overlapping PostgreSQL process tests across manual/provider/refund/reversal; assert all money/accounting/receipt states |
| Security | Per-action reauth, immutable binding, Owner-only state recheck, atomic lockout and verified token/receipt attribution negative tests |
| Performance/leaks | Query/batch bounds plus growing-data/long-lived-worker measurements; resource cleanup, queue/scheduler/backoff evidence |
| Durable notifications | Same transaction obligation, crash/Redis loss recovery and ambiguity tests; no repeat credit |
| Honest financial records | Original history preserved, manual attestation distinct from provider funding, matched currency/entitlements, reconciliation and counterpart evidence |

H0 itself is complete when signed field decisions, exception/control acceptance specification, consistent docs/plan and PR evidence exist. Runtime/concurrency/performance above are mandatory downstream deliverables; this plan does not claim they have passed.

## Documentation provenance

Context7 /laravel/docs/__branch__13.x: [transactions/retries](https://github.com/laravel/docs/blob/13.x/database.md), [locking](https://github.com/laravel/docs/blob/13.x/queries.md), [Hash::check](https://github.com/laravel/docs/blob/13.x/hashing.md), [password-confirmation session timeout](https://github.com/laravel/docs/blob/13.x/authentication.md), [policy override behavior](https://github.com/laravel/docs/blob/13.x/authorization.md). PostgreSQL /websites/postgresql_16: [ON CONFLICT](https://www.postgresql.org/docs/16/sql-insert.html), [Read Committed](https://www.postgresql.org/docs/16/transaction-iso.html), [savepoint recovery](https://www.postgresql.org/docs/16/sql-update.html).

Context7 /brick/math returned main-version docs and changelog that differs from 0.18.0; [installed-version source](https://github.com/brick/math/tree/0.18.0/src) and composer.lock/vendor signatures were checked. In installed 0.18, BigNumber::of and BigDecimal::multipliedBy accept BigNumber/int/string; conversion/scale APIs are exact or throw. Normalize customer grammar before arithmetic and verify implementation against installed version. No new dependency or library migration in H0.
