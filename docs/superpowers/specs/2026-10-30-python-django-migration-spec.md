# Spec induk: eksperimen dan migrasi kondisional Laravel ke Django

Tanggal: 2026-10-30. Status: **disepakati untuk perencanaan; implementasi dan perpindahan production belum dijalankan**.

Canonical spec berada di issue induk ini; ticket map dan body child issues di bawah adalah acuan eksekusi.

## 1. Masalah dan hasil yang dituju

Pemilik ingin mencoba pengganti seluruh runtime PHP/Laravel dengan Python/Django dan frontend HTML/CSS/JavaScript murni. Motivasi utamanya adalah respons tombol, checkout, deteksi pembayaran, dan penerimaan kredensial yang lebih cepat. Python atau Django bukan bukti peningkatan dengan sendirinya.

Hasil pertama adalah keputusan berbasis bukti: **lanjut, perbaiki dan ukur ulang, atau hentikan eksperimen**. Jika manfaatnya tidak cukup atau aplikasi kurang cocok, Laravel tetap dipakai. Hasil akhir kondisional adalah seluruh fitur aktif dan kewajiban historis berlanjut tanpa runtime PHP.

## 2. Keputusan pemilik

1. Branch integrasi: `migration/python-django-experiment`; seluruh pekerjaan migrasi berada di branch ini atau child branch yang kembali ke branch ini. Tidak ada implementasi langsung di `main`.
2. Kandidat pertama Django; versi Python/Django supported dipilih dan dikunci setelah pemeriksaan dokumentasi/status dukungan saat bootstrap. Prefer stable/LTS yang masih didukung, bukan prerelease.
3. Frontend memakai HTML, CSS biasa, vanilla JavaScript; tanpa React dan Alpine.js. Template server diperbolehkan. CSS hasil build, animasi dan aset lama dapat dipertahankan dan diadaptasi untuk menjaga kesamaan tampilan. Authoring baru tidak menambah framework CSS/JS; tool build pengembangan tidak menjadi runtime PHP.
4. Eksperimen dan perbandingan di lokal; production tetap berjalan seperti biasa.
5. Eksperimen awal memakai schema baseline repository, data sintetis/representatif dan key uji. Tidak perlu mengunduh full snapshot production atau membawa key asli ke laptop. PHP dan Django memakai dataset identik dengan hubungan, variasi status, volume/distribusi dan pekerjaan latar yang dicatat; asumsi skala yang belum diverifikasi bukan fakta production.
6. Setelah H1 memutuskan lanjut, HD (#684) wajib membuktikan kompatibilitas data aktual serta pemulihan backup sebelum P28/H2. Operator memilih pemeriksaan terkendali dekat data atau restore terisolasi; full snapshot baru hanya bila diperlukan, tidak wajib di laptop. Rahasia/data pelanggan tidak masuk Git, issue, log, screenshot atau laporan.
7. Gunakan **Lhiestore Testing Bot** dengan token terpisah. Jangan mengganti webhook, token, atau identitas bot production.
8. Pembayaran dimulai dari sandbox. Irisan pertama: QRIS sandbox dan Store Balance IDR. Sandbox lulus bukan kualifikasi production; real-payment qualification kelak memerlukan keputusan operator tersendiri.
9. Seluruh fitur aktif wajib tersedia pada perpindahan production. Bertahap adalah cara pengembangan, bukan izin memotong fitur saat rilis.
10. Tampilan pelanggan tetap: copy, locale, premium emoji, Unicode fallback, warna tombol, urutan menu, gambar, media, alur; dashboard/storefront tetap responsif dan mempertahankan animasi. Perubahan terlihat memerlukan persetujuan.
11. Target navigasi: median dan p95 **masing-masing minimal 30% lebih cepat** pada skenario wajib yang disepakati, termasuk 10 pengguna aktif bersamaan. Pengujian bertahap 1, 5, 10 pengguna.
12. Checkout, pengamatan paid, dan delivery diukur tersendiri; tidak diklaim lulus target numerik yang belum ditetapkan. Setelah baseline, proposal batas tahap tersebut diputuskan H1, sebelum perluasan.
13. Ketepatan transaksi, keamanan, race condition, dan pemulihan adalah hard gates. Kecepatan tidak mengompensasi pelanggaran gate.
14. Tidak ada tenggat kalender tetap. Gunakan resource lokal dan VPS 2 CPU/2 GB yang ada dahulu. Tambahan biaya harus dibahas bila bukti menunjukkan perlu.
15. Jika kelak rilis disetujui: sekitar **00.00 WITA (UTC+08:00)** pada tanggal terpisah, penghentian pembelian baru 15–30 menit, total maksimum 30 menit termasuk rollback. Belum ada tanggal rilis.

## 3. Bukti awal dan batas keyakinan

Audit repository baseline `d830709bb98cffacc6369847383c850ff89088f2`; working tree bersih sebelum dokumen ini. Perubahan main berikutnya harus diinventaris ulang dan direkonsiliasi sebelum kelulusan.

Arsitektur terbukti dari kode: Laravel/PHP-FPM, Nginx, PostgreSQL, Redis, queue workers, scheduler, outbound recovery, Blade/Tailwind/Alpine. Queue Redis pada konfigurasi repo tidak persisten; PostgreSQL menyimpan authority bisnis dan recovery. Konfigurasi memisahkan interaksi, pembayaran, receipt, notifikasi, outbound, broadcast, stok, Binance, supplier. Sembilan worker terkonfigurasi dan FPM sampai enam child adalah batas konfigurasi, bukan jumlah proses sibuk yang terukur.

SSH read-only berhasil pada 2026-10-30 sekitar 17.32–17.34 WITA: 2 CPU; RAM 1.963 MiB; available sekitar 752 MiB; swap terpakai sekitar 694 MiB; disk 78%, tersisa 8,5 GiB; enam container healthy. Snapshot Docker: app 261 MiB, DB 543 MiB, scheduler 138 MiB. Sampel vmstat menunjukkan swap dan I/O wait. Ini bukan peak/capacity guarantee atau penyebab latency yang sudah dibuktikan. App/DB saat itu OOMKilled false dan restart count 0; tidak membuktikan seluruh sejarah host bebas OOM.

Sampel log DB mengandung error check constraint; makna dan pemicunya belum dibuktikan. Tidak boleh disimpulkan data rusak atau guard salah hanya dari hitungan error.

Checkout repository production sama dengan baseline lokal; isi image runtime belum diverifikasi seluruhnya. Belum diverifikasi: jumlah transaksi aktif, ukuran/live schema dan privileges aktual, feature activation/provider aktif, key coverage data historis, backup terbaru dan restore, traffic puncak, real Telegram end-to-end p95. Snapshot/key acquisition tidak dilakukan pada tahap planning ini.

Bukti latency lokal yang ada memakai HTTP fakes dan hardware berbeda; skenario pin historis sudah retired. Instrumentasi ACK, queued action, paid observation dan credential ACK berguna sebagai prior art, bukan klaim production.

## 4. Architecture kandidat dan ownership

Satu aplikasi modular Django; tidak menambah microservices. Modul domain memiliki interface kecil dan adapter Telegram/HTTP/template/job memanggil aturan yang sama:

- Catalog dan presentation: Family/Product/Plan, pricing, locale, icon inheritance, snapshot.
- Checkout dan Order Lifecycle: checkout intent, idempotency, status, expiry, cancel, refunds, manual review.
- Inventory: intake, reservation, allocation, sale, release, retirement, duplicate credential identity.
- IDR/USDT ledgers dan External Money Receipt Claims: atomic postings, immutable history, projection checks, receiver ownership, holds/reorg/restore barriers.
- Payment adapters: preflight sebelum lock; prepare transaction-local tanpa external I/O; authoritative observation di luar money transaction lalu lock/revalidation.
- Telegram ingress/navigation/outbound: durable update dedupe dan canonical root; existing effect-ledger authority untuk delivery.
- Customer auth/API: identitas dan ownership existing, terpisah dari admin; tidak membuat customer baru untuk mengganti pemilik lama.
- Supplier/warranty: purchase uncertainty dan entitlement historis tetap terjaga.
- Operations: scheduler, recovery, alert, audit, retention, safe health/readiness.

PostgreSQL tetap menjadi authority uang, stok, idempotency, dan delivery state. Redis adalah wakeup/cache/lock, bukan satu-satunya bukti uang atau delivery. Jangan membuat ledger/outbox kedua yang bersaing. Wakeup Python memakai format Python sendiri; serialized PHP jobs tidak dieksekusi atau di-unserialize oleh Python.

Web, interaksi, payment verification, credential delivery, notifikasi, supplier dan recovery punya budget/concurrency eksplisit. Tidak harus satu OS process per nama queue; strategi dipilih dari pengukuran, dengan isolasi kapasitas agar pekerjaan lambat tidak menahan interaksi atau receipt. Jangan menyatukan semua pekerjaan ke satu loop yang blocking.

Money/stock transaction menjadi satu fungsi sinkron atomik bila memakai ORM Django; external I/O tidak diletakkan di dalam retried transaction. Pemakaian async mengikuti dukungan aktual library, bukan label performa. Worker retries memakai lease/fencing dan stable operation key.

## 5. Kontrak data dan kompatibilitas

### 5.1 Dataset eksperimen, data aktual dan pemulihan

**Sebelum H1:** gunakan schema baseline repository dan dataset sintetis/representatif yang deterministik, dengan test keys. P03 membuktikan synthetic PHP↔Python compatibility, bukan seluruh historical key coverage. P04/P11 membandingkan dataset/checksum identik, constraints, relasi, status termasuk pending/unknown/late, volume/distribusi dan background work. Catat provenance/seed, asumsi skala dan batas keyakinan. Tidak perlu production acquisition atau menunggu #684. Jangan membuat fixture dengan men-disable trigger/constraint atau mengedit immutable ledger.

Testing-bot/sandbox readiness menjadi acceptance P01/P08: identitas Lhiestore Testing Bot berbeda dari production, secret melalui kanal terlindungi, safe chat/mail targets, egress allowlist dan callback HTTPS terbatas. Ketiadaan credential tidak menahan pekerjaan lokal yang aman, tetapi real sandbox/Testing Bot smoke tetap wajib sebelum H1 lulus; mocks bukan penggantinya.

**Setelah H1 lanjut:** HD (#684, sebelumnya H0) menjadi gate data aktual sebelum P28 (#710). Operator menginventaris actual schema/version, triggers/functions/indexes/constraints, grants/roles/extensions/sequences, timezone/collation, queue variants dan file/key dependencies. Cocokkan baseline P03 dan perbaiki drift. Audit seluruh data relevan untuk crypto/key/hash/identity dan financial/history/inventory invariants, dengan safe counts/unknowns. Sampel berelasi utuh boleh membantu diagnosis, tetapi tidak menggantikan cakupan audit aktual.

Pisahkan dataset pengembangan dari backup pemulihan. Bukti restore backup aktual beserta files/grants/keys dan reconciliation tetap wajib sebelum H2. Operator boleh memakai backup yang sudah tersedia jika provenance/consistency dan restore terbukti, atau lingkungan terisolasi dekat data. Snapshot baru hanya bila bukti memerlukannya; tidak wajib menyimpan full snapshot di laptop. Catat resource, ruang quarantine/restore, jaringan, biaya, retention dan owner sebelum akuisisi. Jangan membebani VPS production dengan stack eksperimen.

Semua akses/akuisisi data/files/secrets asli memerlukan instruksi operator tersendiri; revisi planning bukan izin SSH/read ENV/export DB. Read-only scan memerlukan batas beban. Raw source tetap immutable/private; jika restore diperlukan, quarantine tanpa egress/worker/scheduler dan sanitasi offline ke dataset turunan. Pertahankan IDs/ownership/amounts/currencies/states/chronology dan invariant, tanpa mengubah source/production atau menyalin ENV mentah sebagai config. Privileged-role/grant/extension restore juga dibuktikan; backup no-owner/no-privileges tidak otomatis membawa role/grant.

File inventory mencakup storage/public, uploads, welcome banner, cover, QR template, tutorial, receipt/proof artifacts dan refs Telegram; gunakan aset uji aman sebelum H1, audit kewajiban file aktual pada HD. File_id production tidak dijamin reusable oleh testing bot: mapping dan upload ulang aset test tidak menimpa referensi production.

### 5.2 Crypto dan auth

Kode mengonfigurasi AES-256-CBC Laravel: envelope base64 JSON dengan iv/value/mac/tag; integrity diverifikasi sebelum dekripsi. Eloquent encrypted string/array berbeda dari general Crypt dengan PHP serialization. Petakan semua call site dan historical formats; tidak memakai arbitrary PHP object deserialization.

Uji bidirectional PHP↔Python dengan synthetic vectors: string/array/JSON, null/Unicode, panjang/format credential, tampering, wrong key, previous-key rotation dan error handling. Pemeriksaan actual historical coverage dilakukan pada HD setelah H1 lanjut, hanya menghasilkan safe counts/key coverage tanpa plaintext; bukan syarat penutupan P03. Key/fingerprint/HMAC input normalization harus identik, termasuk domain separation dan app-key bytes versus encoded config representation.

Customer password sha384-v1 memakai base64 SHA-384 atas domain prefix dan password sebelum hasher; raw legacy scheme, admin hashes, recovery-key hashes, API-key digests, signed URL/cursor, OAuth state, OTP/challenge digests juga harus diperiksa. Tidak melakukan force reset atau regenerasi Recovery Key untuk menutup kegagalan kompatibilitas. Session/cookie format bisa berbeda; tetapkan relogin policy secara eksplisit dan jangan memperluas authority/merge Customers.

### 5.3 Schema ownership

Existing schema dipertahankan terlebih dahulu. Introspection/inspectdb hanya starting point, wajib review defaults, enums/checks, partial unique, FK deletion behavior, triggers dan ORM writes. Django tidak otomatis menjadi pengelola legacy table. Framework auth/session/migration metadata boleh additive dan dipisahkan dari tabel Customers/admin yang canonical.

Harus ada satu pemilik schema dan procedure upgrade yang dapat dijalankan tanpa PHP pada hasil akhir. Schema-only bootstrap yang direview, baseline version dan Python migration history menggantikan kebutuhan menjalankan migrasi PHP; bukan rewrite history bisnis. Tidak menjalankan destructive reset/drop/rename massal. Perubahan sebelum cutover additive dan kompatibel dengan old app.

## 6. Invariant wajib

1. Stable Customer/Product/Order/Payment IDs dan financial ownership; tidak ada auto-merge berdasarkan email/username.
2. Exact integer IDR/micro-USDT dan exact decimal representations; tidak memakai binary float untuk uang atau implicit FX.
3. Debit/credit/reservation/settlement atomik dan idempotent, unique economic source; projection bukan saldo yang boleh diedit bebas.
4. Canonical lock order per flow, bounded deadlock retries, stable idempotency keys; API dan Telegram namespaces tidak bertabrakan.
5. Available/reserved/allocated/retired credential tetap konsisten; credential yang pernah terekspos tidak kembali available sesudah refund.
6. Callback provider bukan authority paid tanpa verifikasi yang sesuai; payment lama mempertahankan provider, receiver, amount, deadline, reference dan policy snapshot.
7. Late money, uncertain supplier purchase, chain reorg, drift, manual fulfillment dan ambiguous Telegram delivery tetap reviewable, tidak dianggap sukses/gagal sembarangan.
8. Telegram effect sent/unknown, claim token, ordering, group/sequence dan stable key dipertahankan. Unknown tidak blind resend. Automatic credential pins tetap retired.
9. Canonical navigation generation/lease, stale/foreign callback rejection, message/media changes, caption limits dan UTF-16 entities tetap benar.
10. Menu/UI icons DB-backed, premium emoji plus Unicode fallback, primary default, danger unavailable catalog, footer icons. Catalog presentation tidak pindah ke ENV.
11. Customer/admin/API guard dan per-object permission terpisah; linking bukan financial/order merge.
12. Disabled/unqualified features tetap disabled/unqualified; port kode bukan izin aktivasi funding/provider/custody.

## 7. Cakupan kesetaraan lengkap

Inventory P00 wajib memberi setiap fitur owner/ticket, route/callback, schema, prior tests, visibility, current activation status, historical obligations dan parity evidence. Status unknown tidak boleh ditandai inactive. Coverage meliputi:

- Bot private/group commands, catalog/category/Family/Plan/search, quantity/custom input/cart, home/balance/history/support/language/API management, maintenance, stock/waitlist, admin icon capture, banners/copy, all transactional messages.
- Product account intake legacy/structured, OTP mail link/Auth Token/tutorial, bulk operations, pricing overrides/tiers, supplier catalog/mapping/capacity/purchase/recovery, warranty/partial and remainder refunds.
- QRIS manual/KlikQRIS/Sakurupiah, Pakasir historical obligations, Binance Pay and BEP20 checkout, IDR top-ups/adjustments/reversals, USDT ledger/funding/receivers/reorg/treasury/controls, original-currency refunds.
- Customer accounts/password/OTP/magic link/Google/Telegram linking, guest checkout/Recovery Key/order claims, Customer API keys/admission/preferences/catalog/orders/delivery pagination, public API docs.
- Storefront/cart/review/invoice/status/access, policy consent, support, emails, responsive dashboard/product workspace/operational review queues/reporting, permissions/audit.
- Broadcast/stock/price notifications/Sales Proof, waitlist/mail, scheduler/recovery/backfill/retention, metrics/alerts, safe health, backup/restore and asset pipeline.
- SEO/redirects/canonical URLs/sitemap/robots/structured metadata, security headers/signed links and browser accessibility.

Laravel Blade directive conversion and Alpine behavior rewrite memerlukan regression tests. Snapshot HTML saja tidak membuktikan modal, animation, focus, keyboard, reduced motion, zoom, no-JS fallback, locale atau real mobile Telegram behavior.

## 8. Kontrak benchmark dan penerimaan

### 8.1 Lingkungan adil

Freeze dan catat PHP/Python revisions, image/build/dependency versions, schema/dataset manifest beserta synthetic atau actual provenance, assets, worker counts/policy, CPU/memory limits, PostgreSQL settings/connection pool, host conditions dan driver script. Untuk speed comparison gunakan limits setara, seluruh stack maksimum 2 CPU dan 2 GiB (bukan masing-masing container mendapat 2 GiB); jelaskan beda host RAM aktual sekitar 1,9 GiB dan OS headroom. Jalankan stack bergantian, bukan dua stack saling berebut resource. Tidak menurunkan correctness/durability untuk mendapatkan skor.

Baseline punya dua profil: current PHP behavior apa adanya dan resource-normalized comparison bila berbeda; laporkan keduanya. Optimasi PHP yang menjadi bagian comparator harus tercatat, bukan digabung diam-diam. Python slice juga membawa pekerjaan latar representatif; ablation tanpa background hanya diagnostik.

### 8.2 Skenario dan statistik

Wajib per transition: Home→catalog, catalog→Family/Plan, Plan→detail, Back/Home, quantity changes, Orders/Balance reads; scenario availability mengikuti scope implementasi. Dataset kecil/besar, cold/warm, idle dan concurrent background, 1/5/10 users, same-chat bursts dipisahkan. Test driver tidak membanjiri Telegram sungguhan atau menganggap flood-control sebagai biaya framework.

Minimum protocol proposal: >=200 measured successful observations per mandatory scenario/load/implementation, >=3 independent batches, warmup excluded dan cold reported; tambah sample bila variasi tinggi. Laporkan seluruh attempts/errors/timeouts/unknown/retries, p50/p95/max, queue waits, DB times, cgroup memory/peak/CPU, swap, OOM/restarts, DB connections dan backlog recovery. Tidak menghapus outlier atau failed runs untuk meluluskan gate. Report confidence/uncertainty dan raw sanitized samples; hasil dekat threshold yang tidak stabil = inconclusive, bukan pass.

Pass navigasi per mandatory scenario/load membutuhkan p50_python <=0,70×p50_php dan p95_python <=0,70×p95_php, tanpa correctness/security failure. Tidak merata-ratakan scenario lambat agar tersembunyi oleh yang cepat. Tentukan floor/resolution sebelum measurement; near-zero baseline = inconclusive dan butuh keputusan pemilik, tidak mengganti target sendiri.

### 8.3 Batas waktu yang berbeda

- Ingress ACK/answerCallbackQuery bukan screen completion.
- Automated backend navigation: input diterima runner/webhook sampai Telegram edit/send ACK yang berhasil. Ini belum bukti tampilan sudah terlihat di perangkat.
- Visible latency: pengamatan manual testing bot/video/timestamps dengan metode dan keterbatasan terpisah. Pemilik memeriksa tombol/copy/media sendiri. Backend ACK tidak disebut customer-visible p95.
- Checkout: submit sampai instruksi pembayaran ready/Telegram ACK.
- Payment: provider authoritative paid timestamp (jika tersedia dan clock trustworthy), application observed, commit, receipt queued/pickup, first credential ACK dan complete group.
- Fake transport, provider sandbox, live Testing Bot, dan eventual real money qualification punya report terpisah. Timestamp unknown tetap unknown, tidak dibuat nol. Poll interval/provider cadence dicatat; comparison tidak boleh memakai callback Python versus polling PHP tanpa mengungkap perubahan.

H1 menetapkan apakah manfaat cukup, performance budgets checkout/detection/delivery yang belum numerik, dan penerimaan tampilan nyata. Bila target gagal: diagnosis berbukti, bounded perubahan dan ukur ulang; bila tetap gagal atau pemilik tidak cocok, berhenti. Tidak melanjutkan full rewrite otomatis.

## 9. Testing dan security gates

Unit tests untuk pure domain rules dan boundary validation, bukan mirror implementation. Integration memakai real PostgreSQL/Redis isolated; SQLite tidak membuktikan row locks. Reuse assertions/domain scenarios existing tests, bukan menyalin framework internals.

Race matrix: last stock dua Customer, same-wallet concurrent spending, duplicated Telegram update/checkout/API key, paid versus cancel/expire/refund, duplicate/out-of-order callback, two workers claim same effect, supplier timeout/unknown, account linking and challenge consumption. Gunakan barrier/multiprocess dengan ledger/stock/outbound assertions sesudah race; arbitrary sleep saja tidak cukup.

Fault matrix: process kill before/after commit, lost Redis wakeup, stale lease, partial receipt, definite/ambiguous Telegram failure, provider timeout/429/5xx, DB contention/deadlock, disk/connection pressure, restart recovery, replay external evidence. Uji faults hanya disposable local services.

Security: threat model/abuse cases, object-level authorization across channels, separate admin/customer/API sessions, CSRF/XSS/HTML/entity escape, webhook authenticity and replay, rate limits shared/fail-closed, SSRF/media/url allowlists, secret-free error/log/fixtures, encrypted/authenticated fields, keyed hashes and constant-time checks, least privilege. No unresolved critical/high findings at gate; lower severity findings need owner disposition, not hidden waiver.

Browser/visual: both themes, supported locales, 320/390/768/1023/1024/1280+ widths, zoom/reflow, keyboard/focus/modal/scroll, no-JS fallbacks and reduced motion; preserve animation timing/interaction with original reference. Real Android/iOS/Telegram evidence stays human, not inferred from viewport emulation.

## 10. Cutover dan rollback kondisional

Eksperimen tidak mengubah production. Future production execution hanya setelah H2/H3 dan instruksi admin eksplisit untuk deployment/CI/CD; label/ticket bukan izin otomatis. Platform tetap GitHub Actions→VPS dari main setelah approval, bukan Railway. Jangan membuat/mengubah/menjalankan/menyelidiki workflow hanya untuk memperoleh CI.

Branch utama migrasi menerima evidence dan child changes. Main dapat bergerak selama eksperimen: catat drift dan refresh parity contract/dataset/comparator sebelum release. Branch diintegrasikan ke main hanya pada tahap yang terpisah dan disetujui; PHP tetap berjalan sampai cutover.

Rehearsal offline harus membuktikan: fresh backup plus files/key custody and restoration; maintenance/new-purchase gate; drain ready/delayed/reserved PHP jobs dan active claims; handling payments/callbacks tetap durable; one writer authority; receiver continuity; canonical navigation identity; restart/health/rollback and all work recovered once. Tidak mereplay bot update ke production sebagai test.

Logical DB tetap berlanjut pada VPS; jangan mengganti live DB dengan snapshot lokal yang stale. Strategi schema additive dan fresh data verification. Jika perlu pindah storage/DB fisik, buat keputusan/runbook baru beserta final delta/no-loss proof. Historical serialized PHP queue/failed payloads didrain atau dikonversi secara targeted dari durable identities, bukan di-unserialize di Python atau purge Redis global.

Target 00.00 WITA, max total 30 menit termasuk rollback. Runbook berisi timestamped checkpoints, rollback deadline berdasarkan rehearsal duration plus margin, dan latest safe proceed time; jangan memakai 30 menit penuh untuk mencoba deploy. Kalau rehearsal tidak bisa memenuhi budget, jangan rilis; bawa bukti dan pilihan ke pemilik.

Rollback aplikasi kembali ke old image pada schema kompatibel sambil mempertahankan transaksi baru. Untuk mencegah double charge/delivery setelah rollback, old app harus memahami rows/status/crypto yang ditulis Python selama rollback window. Jika belum kompatibel, new writes dibatasi atau cutover tidak boleh dilakukan. DB restore emergency adalah prosedur berbeda: external payments/chain/messages tidak ikut mundur, wajib replay/reconciliation/ambiguous delivery review. Tidak menjanjikan zero loss tanpa backup/restore/replay proof.

Retirement PHP baru setelah approved observation window dan tidak ada pending obligation yang memerlukan runtime lama. H3 harus menetapkan observation duration, owner/operator, exact release identities, rollback retention dan kapan rollback window ditutup. Final fresh install, upgrades, jobs, backup/restore, docs dan dependencies production harus bisa berjalan tanpa PHP/Composer/Artisan; legacy data/history tidak dihapus demi klaim PHP-free.

## 11. Gerbang manusia dan izin

- **HD (#684, sebelumnya H0):** setelah H1 lanjut, operator membuktikan actual data compatibility dan backup restoration sebelum P28/H2. Tidak wajib full snapshot di laptop. Testing-bot/sandbox readiness ada di P01/P08, bukan gate data.
- **H1:** hasil eksperimen, human bot QA, keputusan lanjut/stop, checkout/detection/delivery budgets. Closing ticket membutuhkan keputusan eksplisit, bukan sekadar report tersedia.
- **H2:** full parity/security/performance/rehearsal acceptance; real provider qualification plan bila diperlukan; biaya tambahan (default belum), session policy, rollback feasibility.
- **H3:** explicit production go/no-go dan operator execution, tanggal 00.00 WITA, 30-minute envelope, separately authorized CI/deployment; observation-window completion/retirement approval sebelum PHP removal.

Seluruh tiket implementasi ready-for-agent berarti scope jelas **setelah blockers closed**. Gate manusia diberi ready-for-human. Tidak ada agent boleh menutup human gate atas nama pemilik, atau menganggap main push sebagai izin memantau CI.

## 12. Risiko, usaha, dan penyelesaian

Risiko tertinggi: crypto/key historical coverage; inconsistent anonymization; lost DB triggers/privileges; dual writers during cutover; external side effects beyond DB rollback; old callbacks/signed links/session; resource limits/swap; incomplete active feature inventory; background workload omitted from performance comparison. Mitigasi dan buktinya mempunyai tiket sendiri.

Usaha belum menjadi komitmen kalender. Eksperimen melibatkan 12 tiket teknis plus H1; HD hanya berlaku setelah keputusan lanjut; full parity dan retirement jauh lebih besar. Estimasi order-of-magnitude satu engineer berpengalaman: eksperimen beberapa person-weeks, parity/rehearsal keseluruhan beberapa person-months; confidence rendah sampai inventory/crypto/baseline selesai. Re-estimate sesudah P03/P04 dan H1; laporkan effort aktual per milestone, bukan menambah test tanpa risiko yang ingin dibuktikan.

Parent hanya selesai melalui salah satu outcome: (a) eksperimen dihentikan secara eksplisit dengan report dan cleanup secrets/local resources, production Laravel tetap; atau (b) full acceptance, authorized cutover, observation, PHP retirement dan final no-PHP operations proof. Jangan menutup parent karena scaffolding atau sandbox payment berhasil.

## 13. Referensi

- Domain glossary, implementation owners/test map, ADRs.
- Latency gate evidence, queue topology, balance restore.
- [Django legacy database integration](https://docs.djangoproject.com/en/stable/howto/legacy-databases/): introspection bukan definitive schema migration; unmanaged models memerlukan review.
- [Django async](https://docs.djangoproject.com/en/stable/topics/async/), [performance](https://docs.djangoproject.com/en/stable/topics/performance/): transaction/async choices dan resource trade-offs harus diukur. Dokumentasi dicek melalui Context7; versi final diverifikasi saat bootstrap.


## Baseline visual untuk validasi

Baseline visual bukan backup database. Catat screenshot/rekaman aman, peta navigasi, urutan tombol, copy/locale, Unicode/custom emoji ID, style tombol dan aset yang direview. Jangan menyertakan customer, credential, payment proof atau QR payable asli. Data presentasi tetap DB-backed, bukan ENV baru. Media file_id production tidak otomatis reusable oleh Testing Bot. Pengumpulan aktual tidak dijalankan oleh revisi dokumen ini.
