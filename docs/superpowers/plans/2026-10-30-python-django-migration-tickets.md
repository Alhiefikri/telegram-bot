# Python migration ticket reference

Salinan kontrak GitHub pada revisi planning 2026-10-30. Issue live adalah acuan kanonis; lihat [rencana](2026-10-30-python-django-migration-plan.md). Tidak ada izin runtime/production/CI dari dokumen ini.

## #680: [Python Migration][P00] Inventaris kontrak fitur, kewajiban historis, dan parity matrix

Recorded tracker state: CLOSED.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: Tidak ada; frontier pertama.

Status eksekusi: **ready-for-agent setelah blockers selesai**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Mengubah audit awal menjadi daftar perilaku yang lengkap dan dapat dibuktikan sebelum porting.

## Solution

- Petakan fitur bot, storefront, admin, API, uang, stok, supplier, auth, delivery, operasi dan file ke owner canonical dan prior tests.
- Pisahkan implemented, active, disabled/unqualified dan unknown; activation production yang belum dibuktikan tetap unknown.
- Bekukan baseline revision, dokumentasi dan asumsi; buat prosedur refresh main drift serta skenario benchmark wajib.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Bekukan evidence dan source inventory.
2. Tambahkan parity matrix dan mapping seluruh fitur ke tiket.
3. Tetapkan scenario/acceptance contract dan daftar gap.

## Decision Document

Baca seluruh blocker dan spec induk. Jangan mulai selama blocker open; label ready-for-agent berarti fully specified, bukan mengabaikan blockers/human gates.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] Setiap fitur mempunyai input/output, transaksi/data owner, route/callback atau entry point, prior tests, ticket penerus dan evidence requirement.
- [ ] Payments pending/late, old buttons, API clients, signed links, sessions, file refs dan retired supplier/pin history tercakup.
- [ ] Tidak ada perubahan tampilan atau aktivasi provider/funding yang tersirat dalam migrasi.
- [ ] Daftar gap dan feature unknown eksplisit; tidak ada row diam-diam dikeluarkan dari full parity.

## Testing Decisions

- Cross-check inventory terhadap entry points, model/migration, scheduler jobs dan test map; jangan membuat test yang hanya menghitung daftar.
- Tinjau coverage terhadap domain glossary dan ADR yang superseded/current.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Tidak membuat runtime Python, membaca secret production, atau mengubah scope bisnis.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.


## #681: [Python Migration][P01] Bangun harness lokal terisolasi dan anggaran seluruh stack

Recorded tracker state: CLOSED.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #680

Status eksekusi: **ready-for-agent setelah blockers selesai**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Menyediakan tempat aman dan reproducible untuk Laravel comparator dan Django candidate tanpa side effect ke pelanggan.

## Solution

- Pisahkan local PostgreSQL/Redis/storage/network/ports untuk kedua stack; default deny outbound, explicit modes fake/sandbox/testing bot.
- Validasi configuration admission: local DB/Redis only, no production bot/provider targets, no scheduler startup pada raw restore, no log mail secrets.
- Berikan command local start/stop/reset untuk disposable data, aggregate CPU/memory budget dan pencatatan manifest.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Tambahkan manifest/config validation contract.
2. Tambahkan disposable local runtime dan egress controls.
3. Buktikan negative startup/reset/isolation cases dan runbook.

## Decision Document

Baca seluruh blocker dan spec induk. Jangan mulai selama blocker open; label ready-for-agent berarti fully specified, bukan mengabaikan blockers/human gates.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] Larangan koneksi production DB/Redis dan production Telegram token diuji sebelum startup; token tidak dicetak.
- [ ] Default blocked outbound, mail sink, safe admin/chat routing; explicit sandbox/test-bot allowlist tidak membuka supplier purchase atau live payment.
- [ ] Seluruh stack dibatasi total maksimum 2 CPU/2 GiB dengan OS/headroom caveat; comparator/candidate berjalan bergantian.
- [ ] Reset menolak target non-disposable dan tidak menghapus raw snapshot atau backup asal.

## Testing Decisions

- Negative startup tests untuk config production/ambiguous endpoint, forbidden egress dan leaked secret errors.
- Local smoke memverifikasi batas cgroup dan transport isolation, bukan benchmark throughput.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Tidak mengubah compose production atau CI/CD; tidak mengunduh snapshot/ENV asli.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.


## #682: [Python Migration][P02] Rancang acquisition, quarantine restore, sanitasi dan file manifest

Recorded tracker state: CLOSED.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #681

Status eksekusi: **ready-for-agent setelah blockers selesai**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Membuat prosedur data lokal yang menjaga compatibility dan confidentiality tanpa menulis production.

## Solution

- Inventaris encrypted fields/call sites, HMAC/fingerprint keys, hashes, schema objects/grants/roles/extensions/sequences dan storage references.
- Siapkan encrypted backup import/quarantine dan secret transfer terpisah; existing no-owner/no-privileges dump tidak cukup untuk grants.
- Bangun sanitasi dataset turunan memakai synthetic source terlebih dahulu, preserve ledger/history/invariants, mengganti kontak/credential/provider targets dengan aman.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Tulis acquisition/field/schema/file contract.
2. Implement quarantine/derived sanitization untuk synthetic fixture.
3. Uji invariant preservation dan tulis operator handoff HD (#684), dijalankan hanya setelah H1 lanjut.

## Decision Document

Baca seluruh blocker dan spec induk. Jangan mulai selama blocker open; label ready-for-agent berarti fully specified, bukan mengabaikan blockers/human gates.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] Raw source immutable/private, no network/no workers saat restore, derived dataset mempunyai checksum/provenance/retention plan.
- [ ] Constraints/triggers tidak di-disable demi anonymization; fingerprint/encrypted identities tetap konsisten dan diuji.
- [ ] Sanitized dataset mempertahankan cardinality, ownership relations, amounts, currencies, status/chronology; redaction tidak menghapus kasus pending/unknown/late.
- [ ] File/asset manifest dan per-bot Telegram file-id remapping tercakup; belum ada actual production acquisition.

## Testing Decisions

- Synthetic mixed legacy/structured secrets dan identity relations; tampering/sanitization failure harus fail closed.
- Restore schema/grants lokal dan invariant audit sebelum/sesudah sanitasi, termasuk immutable ledger.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Agent tidak SSH membaca ENV/key/backup asli atau mengekspor DB production; itu HD (#684) setelah H1 lanjut dengan instruksi operator terpisah.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.

## Revisi handoff planning

Issue ini tetap selesai sebagai bukti prosedur/synthetic rehearsal, bukan bukti akuisisi production. Untuk eksperimen gunakan jalur synthetic/representative tanpa full dump; prosedur acquisition/quarantine tetap tersedia bila HD (#684) setelah H1 membutuhkan data aktual. Referensi H0 dalam artefak implementasi sebelumnya adalah prosedur akuisisi opsional yang kini berlaku pada HD, bukan prasyarat eksperimen. Kontrak eksekusi yang berlaku adalah revisi issue induk dan HD ini; instruksi akuisisi lama tidak memberi izin akses production.


## #683: [Python Migration][P05] Bootstrap Django, module ownership dan Python test environment

Recorded tracker state: OPEN.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #681, #680

Status eksekusi: **ready-for-agent setelah blockers selesai**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Membuat fondasi Django side-by-side yang hanya dapat berjalan dengan runtime config lokal aman.

## Solution

- Pilih supported Python/Django stable/LTS dari current official docs; lock dependencies dan document support horizon.
- Susun domain modules dengan adapters transport, dependency injection dan health safe; plain HTML/CSS/JS template pipeline.
- Siapkan unit/integration/concurrency harness, ID-only jobs contract, PostgreSQL connection budget dan secret-safe settings validation.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Tambahkan supported-version/dependency and module contract.
2. Bootstrap local Django/adapters/template/static setup.
3. Tambahkan test entry points, validation and contributor runbook.

## Decision Document

Baca seluruh blocker dan spec induk. Jangan mulai selama blocker open; label ready-for-agent berarti fully specified, bukan mengabaikan blockers/human gates.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] Default Laravel production paths/workflows tetap utuh; new app isolated and no worker auto-runs on quarantine DB.
- [ ] Startup memverifikasi local identity/config, exact money types dan request/log redaction; no production URL/secrets committed.
- [ ] Domain interfaces mengembalikan outcomes; controllers/jobs tidak memegang business transactions sendiri.
- [ ] Auth/session metadata additive dan separate; framework default admin tidak menggantikan dashboard pelanggan/owner.

## Testing Decisions

- Local bootstrap/health/validation smoke, domain unit example dengan meaningful outcome dan real DB integration harness.
- Dependency/support information sourced and version-locked; no performance guarantee dari framework selection.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Tidak menjalankan legacy model migrations otomatis, mengganti main runtime, atau menambah frontend framework.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.


## #684: [Python Migration][HD] Gate manusia setelah H1: kompatibilitas data production dan bukti pemulihan

Recorded tracker state: OPEN.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #682, #693

Status eksekusi: **ready-for-human setelah H1 memutuskan lanjut**. Gate ini sebelumnya H0. Revisi atas permintaan pemilik: unduhan snapshot sekitar 8 GB menghambat eksperimen karena jaringan, waktu dan biaya. HD tidak lagi memblokir P03 (#685), P04 (#686), atau P08 (#689); HD wajib selesai sebelum full acceptance P28 (#710). Branch integrasi tetap migration/python-django-experiment, bukan main.

## Problem Statement

Membuktikan kesiapan data aktual sebelum migrasi production tanpa mewajibkan full snapshot di laptop untuk memulai eksperimen.

## Solution

- Eksperimen sebelum H1 memakai schema baseline repo, synthetic compatibility vectors, data sintetis/representatif dan key uji. Testing-bot/sandbox readiness diperiksa di P01/P08, bukan gate ini.
- Setelah H1 lanjut, operator memilih cara pemeriksaan paling hemat yang tetap memberi cakupan lengkap: pemeriksaan read-only terkontrol dekat data, restore backup yang sudah tersedia ke lingkungan terisolasi, atau snapshot baru bila diperlukan. Tidak wajib mengunduh seluruh data ke laptop; tidak mengasumsikan backup yang ada valid tanpa bukti restore.
- Sampel historis tersanitasi dengan relasi utuh dapat dipakai untuk diagnosis/fixture. Sampel bukan pengganti audit seluruh data relevan untuk schema, crypto/key coverage, hashes/identity, saldo/ledger, stock/allocation, history dan file obligations.
- Pisahkan dataset pengembangan dari backup pemulihan. Tetapkan consistent checkpoint/provenance, role/grant/extension/file/key coverage, lokasi bukti, retention dan owner. Sebelum H2, wajib ada bukti pemulihan backup aktual serta reconciliation, bukan sekadar backup file tersedia.

## Commits

1. Setelah H1 lanjut, operator mencatat metode, lokasi terisolasi, scope, checkpoint, resource budget dan izin pemeriksaan yang dipilih, tanpa secrets.
2. Cocokkan schema/grants aktual dengan baseline P03; audit seluruh format/rows relevan dan historical key coverage. Perbaikan codec/schema akibat temuan harus selesai dan diuji sebelum #710 lulus.
3. Buktikan backup/files/roles/key restore dan invariant reconciliation pada lingkungan disposable yang disetujui; terbitkan safe counts/checksums/unknowns dan handoff P28/P29/H2.

## Decision Document

Hanya pemilik/operator dapat menyetujui dan menutup HD. Persetujuan revisi planning bukan izin SSH, membaca ENV/key/data asli, membuat snapshot, mengubah production, menambah biaya atau deploy. Masing-masing operasi memerlukan instruksi eksplisit saat pelaksanaan. Jangan memasang stack eksperimen pada VPS production yang terbatas kapasitasnya; pilih lokasi dan budget sebelum operasi. Read-only scan juga perlu batas beban dan kondisi penghentian.

Raw source immutable/private. Bila restore diperlukan, lakukan di quarantine tanpa egress, worker atau scheduler. Sanitasi ke dataset turunan, bukan mengubah source/production. Pertahankan IDs/ownership/amounts/currencies/states/chronology dan invariant; jangan disable constraint/trigger atau mengedit ledger demi sanitasi. Key historis hanya di kanal terlindungi; jangan salin ENV production menjadi runtime config. Laporan hanya metadata aman/counts, tanpa plaintext/secret/customer data.

Full snapshot baru hanya dipilih bila diperlukan untuk bukti yang belum tersedia; alasan, ukuran, ruang restore/quarantine, jaringan, biaya dan cleanup dicatat sebelum akuisisi. Lokasi restore tidak wajib komputer lokal. Backup pemulihan tetap wajib dan tidak boleh diganti oleh sampled development dataset. Logical DB production tetap berlanjut; jangan cutover dengan mengganti DB live memakai clone lama.

## Acceptance Criteria

- [ ] H1 secara eksplisit memutuskan lanjut; operator menyetujui scope/metode/lokasi/anggaran dan mencatat provenance serta batas akses tanpa secrets.
- [ ] Actual schema, grants/roles/extensions, versi, sequence/trigger/constraint dan file-reference inventory dibandingkan baseline P03; drift diperbaiki atau gate tetap blocked.
- [ ] Seluruh data historis relevan tercakup audit crypto/key/hash/identity dan financial/history/inventory invariants. Coverage counts/unknowns dilaporkan; unreadable rows atau mismatch tidak dilewati. Sampling/synthetic saja tidak meluluskan gate.
- [ ] Restore backup aktual beserta file/grant/key dependencies diuji di lingkungan terisolasi dan reconciliation lulus. Bukti mencantumkan checkpoint, revision, commands, safe artifact identity dan elapsed time; tidak wajib full dump di laptop.
- [ ] Human approval dan handoff #710/#711/#712 tercatat. Missing evidence tetap no-go production, tetapi tidak mengunci eksperimen awal.

## Testing Decisions

- Gunakan hasil synthetic PHP↔Python P03 sebagai pembanding, lalu verifikasi actual historical coverage secara terkendali.
- Rehearse restore/reconciliation dan penolakan wrong/missing key, schema drift atau invariant mismatch pada lingkungan disposable. Jangan fault-inject production.
- Bedakan hasil pemeriksaan read-only aktual, sampel tersanitasi dan data sintetis. Jangan menyimpulkan seluruh production kompatibel dari sampel.

## Out of Scope

Tidak ada kewajiban unduhan snapshot penuh untuk memulai eksperimen. Tidak ada perubahan fitur, pelonggaran money/stock/security invariants, force reset password/Recovery Key, publikasi ENV/key/dump atau live database replacement. Tidak deploy/restart/change webhook, real-money test, tambahan biaya atau operasi CI/CD tanpa instruksi admin tersendiri. Jangan dispatch/re-run/cancel/poll/watch/investigate CI.

## Further Notes

P02 (#682) tetap catatan prosedur yang telah selesai, bukan bukti production acquisition atau actual data compatibility. P03/P04/P08 berjalan dengan data uji; #710 menunggu HD. H2/H3 tetap memisahkan readiness, izin execution, rollback window dan retirement.


## #685: [Python Migration][P03] Buktikan schema, crypto, hash dan identity compatibility PHP↔Python

Recorded tracker state: OPEN.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #682, #683

Status eksekusi: **ready-for-agent setelah blockers selesai**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Memastikan Python memahami data lama dan dapat menulis format yang masih dibaca Laravel selama rollback window.

## Solution

- Review unmanaged legacy Django models, DB types/defaults/enums/constraints/triggers, integer/decimal/timezone mapping dan least-privilege roles.
- Implement tested Laravel envelope decoder/encoder sesuai authenticated AES settings, string/array versus serialized variants; no arbitrary object deserialization.
- Petakan Customer sha384-v1/raw/admin/recovery hashes, key rotation, HMAC normalization, API digests/signed URLs/cursors/challenges; audit actual historical key coverage dipindahkan ke HD (#684), bukan syarat menyelesaikan P03.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Tambahkan reviewed model/crypto contracts dan synthetic vectors.
2. Implement compatibility codecs/hash verification serta least-privilege mappings.
3. Buktikan synthetic compatibility dan rollback write contract; catat perbedaan schema aktual/key coverage yang belum diverifikasi sebagai handoff wajib HD (#684).

## Decision Document

Baca seluruh blocker dan spec induk. Jangan mulai selama blocker open; label ready-for-agent berarti fully specified, bukan mengabaikan blockers/human gates.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] Bidirectional synthetic vectors lulus untuk Unicode/null/JSON/legacy credentials, wrong key, tamper, rotation, malformed payload; plaintext tidak masuk evidence.
- [ ] Manifest synthetic vectors, schema baseline repo dan batas cakupan dilaporkan; actual production schema/key coverage belum diklaim lulus dan dialihkan eksplisit ke HD (#684). Key uji bukan key production.
- [ ] Money/ID precision, constraints/trigger/FK/default/sequence behavior sesuai baseline; ORM tidak mengambil schema lifecycle legacy otomatis.
- [ ] Secret fingerprints dan hashes tidak diregenerasi untuk menyamarkan incompatibility; additive schema strategy dan Python-only bootstrap design terdokumentasi.

## Testing Decisions

- PHP-generated vectors dibaca/ditulis Python dan kembali diverifikasi PHP; rollback writer contract diuji.
- Real PostgreSQL schema-object/role behavior tests; no float money and no automatic destructive migration.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Tidak force password reset/Recovery Key regeneration, decrypt customer data ke laporan, atau migrate production.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.


## #686: [Python Migration][P04] Ukur baseline Laravel untuk navigasi, checkout, paid observation dan delivery

Recorded tracker state: OPEN.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #681, #680

Status eksekusi: **ready-for-agent setelah blockers selesai**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Mendapatkan comparator yang adil sebelum mengklaim manfaat Django.

## Solution

- Jalankan existing Laravel di isolated synthetic/representative dataset yang sama dengan Django; freeze versions/revision/assets/worker config dan background load.
- Ukur 1/5/10 user, same-chat bursts, cold/warm dan idle/background; pisahkan ACK, screen update ACK, checkout, paid observe/commit/receipt ACK.
- Implement reusable runner/report contract dengan raw sanitized timestamps/unknown/error/retry counts dan resource/cgroup metrics.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Bekukan benchmark protocol dan dataset manifests.
2. Tambahkan measurement/runner/report dengan failure accounting.
3. Jalankan baseline lokal dan simpan sanitized evidence beserta limits.

## Decision Document

Baca seluruh blocker dan spec induk. Jangan mulai selama blocker open; label ready-for-agent berarti fully specified, bukan mengabaikan blockers/human gates.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] Dataset deterministik mencakup relasi utuh, variasi status/riwayat, volume/distribusi dan pekerjaan latar; seed/checksum/provenance serta asumsi skala dicatat. Tanpa statistik production terverifikasi, hasil hanya berlaku pada profil uji, bukan klaim skala production. Tidak menunggu snapshot atau HD (#684).

- [ ] Current behavior dan resource-normalized profile dilaporkan terpisah; tidak mengganti baseline diam-diam.
- [ ] Minimal 200 measured observations per mandatory navigation scenario/load per implementation dan >=3 batches; warmup excluded, cold separate, tambah jika tidak stabil.
- [ ] Semua attempts dan kegagalan tercatat; p50/p95 tidak dicampur across scenarios; backend Telegram ACK bukan visible-device latency.
- [ ] Synthetic Telegram/provider fixture, sandbox provider dan live Testing Bot evidence dibedakan; no production requests atau meaningful data leak.

## Testing Decisions

- Report-parser tests missing boundaries/failure inclusion/outlier preservation dan deterministic calculations.
- Runner smoke pada disposable services; sensor resources dan connection/backlog accounting diverifikasi.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Tidak mengukur production baru, mengubah PHP bisnis untuk menguntungkan comparator, atau menyelidiki CI.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.


## #687: [Python Migration][P06] Port navigasi katalog pertama beserta premium emoji dan canonical root

Recorded tracker state: OPEN.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #685, #683

Status eksekusi: **ready-for-agent setelah blockers selesai**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Membuat navigasi Django yang dapat dibandingkan langsung tanpa perubahan UI yang tidak disetujui.

## Solution

- Port minimized ingress/update dedupe, per-chat sequencing, canonical root generation/lease, Home/catalog/Family/Plan/detail/back/quantity.
- Preserve icon inheritance/snapshots, message-copy/UI slots, Unicode fallbacks, inline styles, locale/entities/media/caption handling.
- Reject stale/foreign callbacks and handle definite versus ambiguous Telegram edits sesuai existing contracts.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Tambahkan ingress/root/presentation contracts.
2. Port initial navigation and DB-backed icon/copy paths.
3. Uji stale/duplicate/ambiguous/media cases dan human QA checklist.

## Decision Document

Baca seluruh blocker dan spec induk. Jangan mulai selama blocker open; label ready-for-agent berarti fully specified, bukan mengabaikan blockers/human gates.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] Menu/text/order/media/button callbacks setara untuk required initial screens; unavailable catalog danger, default/navigation primary, footer icons preserved.
- [ ] Testing bot asset/file references terpisah; no production bot calls or pinned credential restoration.
- [ ] Duplicate updates tidak mengulang business effects; same-chat bursts tidak menimpa screen dengan stale generation.
- [ ] No external provider request di routine navigation atau lock held solely for payment lookup.

## Testing Decisions

- Golden sanitized outbound payload contracts plus real PostgreSQL root/update races.
- Testing Bot QA dengan explicit allowlist; screenshot/manual evidence tidak dianggap automated p95 proof.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Tidak rework tampilan/copy, activate production webhook, atau full bot feature parity pada tiket ini.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.


## #688: [Python Migration][P07] Port atomic checkout, inventory dan Store Balance IDR untuk eksperimen

Recorded tracker state: OPEN.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #685, #683

Status eksekusi: **ready-for-agent setelah blockers selesai**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Membuktikan money/stock correctness sebelum sandbox checkout dan delivery.

## Solution

- Port shared checkout intent/pricing snapshot, customer/cart/product lock graph, maintenance/restore spending guards dan stable checkout key.
- Port inventory reservation/allocation/sale/release/retirement serta canonical IDR ledger debit/refund prerequisites.
- Pisahkan preflight outside locks dan transaction-local prepare; external I/O/Telegram tidak di retried money closure.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Tetapkan transaction/lock/idempotency contract.
2. Implement checkout, inventory and IDR postings.
3. Buktikan races, stale inputs dan atomic rollback.

## Decision Document

Baca seluruh blocker dan spec induk. Jangan mulai selama blocker open; label ready-for-agent berarti fully specified, bukan mengabaikan blockers/human gates.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] Exact IDR integers, non-negative projection dan immutable source/idempotency constraints preserved.
- [ ] Concurrent same-wallet checkouts tidak overspend; last-stock dua Customer hanya satu menang.
- [ ] Duplicate checkout/update replay mengembalikan existing result tanpa second debit/reservation; stale prices/maintenance fail atomically.
- [ ] Tidak membuat parallel balance ledger atau relabel historical source/currency; DB guards remain authority.

## Testing Decisions

- Multiprocess PostgreSQL barrier tests same-wallet/last-stock/replay, rollback after failure and deadlock retry bounds.
- Compare sanitized lifecycle/ledger/stock outcomes dengan PHP fixtures.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Belum full funding/adjustment/warranty atau seluruh payment rails; no production writes.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.


## #689: [Python Migration][P08] Integrasikan QRIS sandbox tanpa mengubah payment identity saat retry

Recorded tracker state: OPEN.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #688, #681

Status eksekusi: **ready-for-agent setelah blockers selesai**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Menguji checkout-to-paid memakai provider sandbox yang tersedia dan terverifikasi.

## Solution

- Implement provider adapter pada existing reference/snapshot contracts; pilih sandbox tersedia yang sesuai active-provider inventory, catat capability gaps.
- Port create/recovery/status/callback authenticity, authoritative observation, timeout/429/5xx handling dan callback duplicate coalescing.
- Expose local HTTPS callback secara terbatas dengan validasi secret; no production provider credentials di sandbox runtime.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Tambahkan sandbox adapter/authentication contract.
2. Implement create/status/recovery/callback handling.
3. Buktikan failure/race cases dan record sandbox qualification limits.

## Decision Document

Baca seluruh blocker dan spec induk. Jangan mulai selama blocker open; label ready-for-agent berarti fully specified, bukan mengabaikan blockers/human gates.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] Sebelum external smoke, verifikasi testing-bot berbeda dari production, sandbox credentials/targets dan callback HTTPS terbatas. Secret disediakan lewat kanal terlindungi, bukan issue/Git/log. Jika belum tersedia, lanjutkan pekerjaan lokal yang aman; external smoke tetap blocker P08/H1 dan tidak diganti fabricated pass.

- [ ] Retry/recovery tidak membuat Payment/reference/customer top-up baru atau mengganti total/deadline/provider snapshot.
- [ ] Paid transition hanya setelah authoritative verification; duplicate/out-of-order callback serta mismatch ditolak atau review sesuai domain.
- [ ] Provider observations di luar transaction/chat lock; settled/cancel/expired race tidak menyalurkan fulfillment tanpa authority.
- [ ] Sandbox evidence mencatat actual provider capability dan unknown; unavailable sandbox bukan fabricated pass.

## Testing Decisions

- Contract fixtures sukses/pending/failed/expired/mismatch/late; real sandbox smoke setelah token/identitas Lhiestore Testing Bot, sandbox creds, safe targets, egress dan endpoint callback diverifikasi di P08/P01, tanpa menunggu HD (#684).
- PG callback races dan provider-failure recovery dengan stable operation keys.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Tidak real-money test atau production credentials/callback/webhook mutation; Binance/USDT belum scope.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.


## #690: [Python Migration][P09] Port receipt credentials dan outbound effect ledger protocol

Recorded tracker state: OPEN.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #688, #687

Status eksekusi: **ready-for-agent setelah blockers selesai**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Mengirim kredensial sekali dengan urutan dan ambiguity semantics yang sama seperti sistem lama.

## Solution

- Port effect identity/group/sequence, claim leases/fencing, after-commit materialization, definite failure fallback dan manual-review unknown.
- Render credential photo/text/TXT/action panels dari authorized immutable allocation; tidak memasukkan plaintext ke queue/cache payload.
- Recover paid orders with missing wakeups/materialization dan repair sent projections tanpa resend.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Port effect protocol/eligibility and ID-only dispatch contract.
2. Implement credential render/delivery with existing semantics.
3. Buktikan crash/unknown/projection-repair behavior.

## Decision Document

Baca seluruh blocker dan spec induk. Jangan mulai selama blocker open; label ready-for-agent berarti fully specified, bukan mengabaikan blockers/human gates.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] Exactly-one economic allocation dan one successful claim per ordered unit, no blind resend sesudah lost ACK.
- [ ] Receipt/user ownership, retired/partial/manual review eligibility dan locale/icon snapshots preserved.
- [ ] Slow admin/email work tidak menahan credential critical path; automatic pins tetap absent.
- [ ] Crash setelah sent tetapi sebelum projection update dapat dipulihkan tanpa duplicate transport calls.

## Testing Decisions

- Multiprocess effect claim race, multi-chunk ordering, partial send then ambiguous ACK, Redis wakeup loss and replay.
- Sanitized payload assertions tanpa menyimpan secret values dalam artifact.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Tidak membangun outbox kedua, blind resend, mengubah automatic-pin decision atau expose credential fixtures publik.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.


## #691: [Python Migration][P10] Isolasi worker interaksi, payment, delivery dan durable recovery

Recorded tracker state: OPEN.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #689, #690

Status eksekusi: **ready-for-agent setelah blockers selesai**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Membuktikan antrean dan scheduler kandidat tidak kehilangan kewajiban atau memblokir navigasi saat provider lambat.

## Solution

- Implement bounded queue consumers and recovery schedule untuk initial slice; pilih worker library melalui docs/ADR setelah P05 tanpa menambah layanan di luar budget.
- Preserve per-chat serial behavior, claim leases/retry limits/fencing, delayed/reserved wakeups dan durable discovery.
- Instrument enqueue/pickup/finish/paid/first credential ACK, resources/connection budget; no secret-bearing jobs.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Tetapkan worker scheduling/admission/timeout contract.
2. Implement bounded consumers and initial durable sweeps.
3. Tambahkan timing/resource evidence dan failure-recovery tests.

## Decision Document

Baca seluruh blocker dan spec induk. Jangan mulai selama blocker open; label ready-for-agent berarti fully specified, bukan mengabaikan blockers/human gates.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] Slow payment dan slow notification tidak menahan required navigation/receipt work.
- [ ] Worker restart/Redis loss dipulihkan dari PostgreSQL tanpa new payment/debit/send untuk unknown delivery.
- [ ] Timeout/lease/retry_after compatible dan shutdown/restart/drain bounded; serialized PHP job tidak di-unserialize Python.
- [ ] Total stack resource limits terukur; cgroup memory bukan sum RSS dan container limits bukan actual usage.

## Testing Decisions

- Disposable process-kill before/after commit, stale worker fence, delayed retry and lost wakeup tests.
- Mixed-load local run memverifikasi bounded queue/connection use dan recoverable backlog.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Tidak consolidate semua queues ke satu blocking worker atau mengubah runtime production.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.


## #692: [Python Migration][P11] Jalankan experiment gate: performance, security, race dan parity report

Recorded tracker state: OPEN.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #686, #687, #689, #691

Status eksekusi: **ready-for-agent setelah blockers selesai**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Menyediakan bukti terukur untuk keputusan H1 tanpa meneruskan full rewrite otomatis.

## Solution

- Jalankan PHP↔Django comparison protocol dengan manifest synthetic dataset/limits/background comparable, 1/5/10 users.
- Run threat/authorization/replay/SSRF/secret-log checks dan transaction/outbound failure/race suite untuk initial slice.
- Buat safe report per navigation scenario dan payment stage, raw samples/error counts, manual Testing Bot QA dan unresolved findings.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Lengkapi initial integrated test/measurement contracts.
2. Jalankan isolated comparison and security/race evidence.
3. Terbitkan report lanjut/perbaiki/stop dengan limits dan proposal payment timing budgets.

## Decision Document

Baca seluruh blocker dan spec induk. Jangan mulai selama blocker open; label ready-for-agent berarti fully specified, bukan mengabaikan blockers/human gates.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] Bukti memisahkan hasil synthetic dari actual production compatibility. Tidak perlu full dump production untuk gate eksperimen; unknown historical/schema coverage tetap kewajiban HD (#684) sebelum #710.

- [ ] Setiap mandatory navigation scenario/load memenuhi p50 dan p95 <=70% baseline atau verdict fail/inconclusive; tidak dirata-rata across scenarios.
- [ ] >=200 observations dan >=3 batches per mandatory scenario/load/implementation; uncertainty/high variance ditunjukkan, bukan dipaksa pass.
- [ ] Zero money/stock/duplicate-delivery authority violations; tidak ada unresolved critical/high security finding.
- [ ] Backend ACK, visible human impression, fakes, sandbox dan actual Testing Bot evidence terpisah; checkout/detection/delivery timing tidak dibuat target sesudah melihat hasil tanpa keputusan.

## Testing Decisions

- Full initial PG races/fault suite, authorized live testing-bot QA and sandbox-only payments.
- Reproduce report dari sanitized raw data dan verifikasi final ledger/allocation/outbound audits.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Tidak memilih lanjut atas nama pemilik atau mengklaim production performance dari local fakes.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.


## #693: [Python Migration][H1] Gate manusia: nilai eksperimen dan putuskan lanjut, revisi atau stop

Recorded tracker state: OPEN.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #692

Status eksekusi: **ready-for-human**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Pemilik menilai bukti dan pengalaman bot sebelum full parity dikerjakan.

## Solution

- Review p50/p95 30% per mandatory scenario/load, resource headroom, race/security/failure evidence dan manual Testing Bot usability.
- Tetapkan acceptance budgets checkout/instructions/paid detection/first credentials/completion dari baseline dan actual provider limits.
- Jika lanjut, konfirmasi feature matrix scope/activation gaps dan next-phase priorities; jika stop, tentukan safe cleanup snapshot/secrets/tunnels dan retained report.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Catat keputusan H1 dan numerical payment-stage budgets.
2. Update accepted scope/targets atau stop/cleanup record.

## Decision Document

Hanya manusia/operator yang dapat mencatat keputusan dan menutup gate ini. Agent boleh menyiapkan evidence; tidak boleh menyetujui atas nama pemilik.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] Keputusan H1 memakai bukti eksperimen sintetis/representatif dan batasnya, bukan mengklaim seluruh data production kompatibel. Jika lanjut, HD (#684) menjadi kewajiban sebelum #710; jika stop, tidak perlu akuisisi snapshot untuk eksperimen ini.

- [ ] Keputusan eksplisit lanjut/revise/stop dicatat oleh pemilik, dengan alasan dan unresolved limitations.
- [ ] Tidak menutup gate sebagai pass hanya karena report tersedia atau median aggregate terlihat baik.
- [ ] Missing sample/unstable threshold/security/correctness failure remains fail or inconclusive; exception mengubah scope/target membutuhkan keputusan baru.
- [ ] Downstream full rewrite tetap blocked sampai keputusan lanjut; stop mempertahankan Laravel production.

## Testing Decisions

- Pemilik mengetes button→screen, checkout sandbox dan receipt di testing bot.
- Review evidence manifest dan replayable report tanpa data sensitif.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Bukan izin production deployment, real money atau CI/CD.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.


## #694: [Python Migration][P12] Lengkapi Order Lifecycle, expiry, cancel, refunds dan review

Recorded tracker state: OPEN.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #693, #688

Status eksekusi: **ready-for-agent setelah blockers selesai**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Mempertahankan semua lifecycle dan kewajiban historis lintas kanal.

## Solution

- Port paid/cancel/expire/reject/manual complete, terminal semantics dan historical provider projections.
- Port partial/remainder/original-currency refunds, retired credentials, manual fulfillment dan late payment review.
- Pelihara policy/price/currency/reference snapshots serta common customer/account/order/payment/inventory lock graph.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Port lifecycle transition/lock contract.
2. Lengkapi cancel/expiry/refund/review behavior.
3. Buktikan race/replay/retirement dan historical parity.

## Decision Document

Baca seluruh blocker dan spec induk. Jangan mulai selama blocker open; label ready-for-agent berarti fully specified, bukan mengabaikan blockers/human gates.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] Paid versus expiry/cancel/refund race punya satu hasil sah; no duplicate sale/credit/release.
- [ ] External partial/remainder evidence dan original debit bounds preserved; exposed credential tidak restock.
- [ ] Legacy Pakasir status projection dan UI mapping tidak disamakan sembarangan dengan rails baru.
- [ ] Ownership/audit/authorizations dan maintenance/restore barrier scope sesuai canonical rules.

## Testing Decisions

- PG lifecycle multiprocess matrix serta cross-channel refund/replay fixtures.
- Late payment and uncertain proof/review paths diverifikasi tanpa automatic fulfillment/refund.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Tidak mengubah kebijakan refund atau menyederhanakan status untuk ORM.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.


## #695: [Python Migration][P19] Port customer/admin authentication, linking dan ownership compatibility

Recorded tracker state: OPEN.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #693, #685, #683

Status eksekusi: **ready-for-agent setelah blockers selesai**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Melanjutkan akses akun existing tanpa merge Customer atau memperluas authority.

## Solution

- Port admin/customer separate guards, passwords/hash schemes, email verification/OTP/reset/magic links, versioned session revocation.
- Port Google and private Telegram two-sided proof/link/unlink, expiring hashed claims dan durable encrypted auth mail.
- Preserve guest Recovery Key/order claiming, existing signed links and explicit session/relogin policy for cutover.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Port identity/auth/token/session compatibility contracts.
2. Implement auth/claim/mail paths with separate adapters.
3. Buktikan account races, replay, revocation and ownership isolation.

## Decision Document

Baca seluruh blocker dan spec induk. Jangan mulai selama blocker open; label ready-for-agent berarti fully specified, bukan mengabaikan blockers/human gates.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] Same provider subject/canonical Telegram ID tetap exact same Customer; email collision/username tidak auto-link atau merge uang/order.
- [ ] Purpose-bound challenges single-use/attempt-limited/browser/version-bound; reset revokes sessions appropriately.
- [ ] Password/Recovery Key compatibility lulus tanpa force reset; admin/customers auth metadata tidak disatukan.
- [ ] Rate limiting fails closed, CSRF/cookie/session fixation/logging rules teruji; old session policy eksplisit untuk H2.

## Testing Decisions

- PG concurrent registration/link/OTP/reset/order-claim races dan cross-guard/object authorization.
- Secret-free local mail sink plus OAuth/Telegram fixtures; live login qualification hanya approved test identities.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Tidak redesign auth, merge customers atau use production provider accounts without approval.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.


## #696: [Python Migration][P13] Lengkapi semua QRIS rails dan kompatibilitas Pakasir historis

Recorded tracker state: OPEN.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #694, #689

Status eksekusi: **ready-for-agent setelah blockers selesai**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Menjaga existing/new payment rail behavior dan riwayat provider tanpa implicit switch.

## Solution

- Port QR Manual, KlikQRIS dan Sakurupiah serta legacy Pakasir settlement/history yang masih wajib didukung.
- Preserve provider switching/versioned settings, QR template/presentation files, statuses, create/recovery throttling/authoritative reconciliation.
- Inventory provider activation/readiness dan per-environment callback/allowlists; default disabled state preserved.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Port rail selection/readiness/snapshot contracts.
2. Implement remaining QR rails/legacy handlers and QR presentation.
3. Record per-rail parity/fault/qualification evidence.

## Decision Document

Baca seluruh blocker dan spec induk. Jangan mulai selama blocker open; label ready-for-agent berarti fully specified, bukan mengabaikan blockers/human gates.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] Provider switch hanya untuk new payments; old references/amount/deadline/receiver immutable.
- [ ] Create uncertainty, status failure dan late money tidak membuat fallback payment atau lost obligation.
- [ ] Callback authenticity, sanitized evidence, trusted QR URLs dan provider cancellation attempt semantics preserved.
- [ ] Every rail has contract tests; external qualification unavailable dicatat sebagai blocker, bukan diganti fixture pass.

## Testing Decisions

- Fixture+PG race create/recovery/callback/cancel coverage per rail.
- Sandbox verification hanya untuk provider yang available dan authorized; no live payments.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Tidak mengaktifkan retired provider untuk new checkout atau mengubah provider policy.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.


## #697: [Python Migration][P14] Port Binance Pay dan BSC USDT checkout verification

Recorded tracker state: OPEN.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #694

Status eksekusi: **ready-for-agent setelah blockers selesai**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Melanjutkan existing external-USD checkout tanpa memperlemah bukti pembayaran.

## Solution

- Port Binance amount/Order-ID verification dan selected rail instructions/status/reconciliation.
- Port BSC transaction observation, receiver/token/network/amount/deadline/confirmations snapshots dan replay protection.
- Pisahkan public receiving config dari external signing/custody; no private-key wallet service baru.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Port rail instructions and immutable verification contract.
2. Implement Binance/BSC observation and lifecycle handoff.
3. Buktikan duplicate/mismatch/outage/late cases.

## Decision Document

Baca seluruh blocker dan spec induk. Jangan mulai selama blocker open; label ready-for-agent berarti fully specified, bukan mengabaikan blockers/human gates.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] Tx hash/order ID bukan proof final tanpa authoritative verification dan exact receiver/currency/amount/time checks.
- [ ] Duplicate transfer/reference atau late funds tidak double-consume/credit atau fulfill terminal order.
- [ ] Provider outage dan chain uncertainty tetap pending/review sesuai contract, tidak diubah cancelled secara asumsi.
- [ ] Checkout existing receiver assignments/historical payments berlanjut dan tidak mengaktifkan USDT funding yang belum qualified.

## Testing Decisions

- Authoritative fixture tests wrong receiver/token/amount/finality, duplicate observation dan settlement race.
- Watch-only local/fake RPC failure tests; real rail qualification terpisah melalui H2.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Tidak melakukan transaksi chain/live Binance, custody activation, atau menambah money float.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.


## #698: [Python Migration][P18] Port supplier acquisition, historical entitlement dan warranty

Recorded tracker state: OPEN.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #694

Status eksekusi: **ready-for-agent setelah blockers selesai**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Menjaga supplier cost/capacity/purchase uncertainty dan kewajiban akun historis.

## Solution

- Port supplier catalog/private import/mapping/retail overrides/capacity, deposits dan acquisition claims.
- Port purchase lease/recovery/ambiguous outcomes, account units/structured credentials dan partial delivery review.
- Port warranty/replacement/refund entitlements, retired suppliers/history dan account credential retirement.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Port supplier acquisition/capacity contracts.
2. Port account-unit fulfillment/warranty entitlement owners.
3. Buktikan uncertainty, retirement dan cost/refund invariants.

## Decision Document

Baca seluruh blocker dan spec induk. Jangan mulai selama blocker open; label ready-for-agent berarti fully specified, bukan mengabaikan blockers/human gates.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] Uncertain supplier purchase tidak blind retry menjadi purchase ganda; costs/source snapshots retained.
- [ ] Retiring supplier menghentikan new purchases tetapi tidak menghapus obligation/holds/history/refund caps.
- [ ] Partial/missing units tidak diklaim fulfilled; API/Telegram delivery authorization konsisten.
- [ ] No local test menggunakan live supplier purchase credentials/targets; SSRF/webhook/secret handling diuji.

## Testing Decisions

- PG capacity/purchase claim/import/pricing races; partial/timeout/unknown recovery.
- Credential identity duplicate/intake/retirement and historical warranty/refund fixtures.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Tidak mengubah supplier economics atau membeli barang nyata selama local tests.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.


## #699: [Python Migration][P15] Lengkapi IDR top-up, adjustments, reversals dan reconciliation

Recorded tracker state: OPEN.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #694, #696

Status eksekusi: **ready-for-agent setelah blockers selesai**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Menjaga seluruh pergerakan saldo IDR dan operational controls existing.

## Solution

- Port funding intent/quote/confirmation, manual/QRIS top-ups, deadline/late settlement dan stable economic claims.
- Port owner adjustment/reversal controls, audit/password rules yang existing, notifications dan projection checks.
- Preserve shared Customer/account/order lock graph, maintenance/spending/restore barriers dan original-source refund cap.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Port IDR funding/adjustment contracts.
2. Implement remaining top-up/reversal/reconciliation behavior.
3. Buktikan replay, drift, late money dan permission cases.

## Decision Document

Baca seluruh blocker dan spec induk. Jangan mulai selama blocker open; label ready-for-agent berarti fully specified, bukan mengabaikan blockers/human gates.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] Same source/retry tidak double-credit; expired compensated target tidak membuka second credit.
- [ ] Adjustment/reversal require correct authority/evidence dan insufficient spent funds masuk review.
- [ ] Projection drift fail closed dan tidak auto-repair money history; immutable source IDs remain.
- [ ] Chooser/preset/admin policy DB-backed dan current snapshots tidak diubah setting baru.

## Testing Decisions

- PG competing settlement/adjustment/reversal/spending races.
- Compare ledger sums/projections/outbound receipts setelah retries/Redis loss.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Tidak maker-checker policy baru atau reset balance projection manual.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.


## #700: [Python Migration][P16] Port USDT ledger, external receipt claims dan receiver ownership

Recorded tracker state: OPEN.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #694, #699

Status eksekusi: **ready-for-agent setelah blockers selesai**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Menjaga micro-USDT accounting, dedupe sumber uang dan exclusive receiver history.

## Solution

- Port immutable USDT postings/projections/accounting aggregates, freeze and original-currency refunds.
- Port External Money Receipt Claims alias/parent/global consumption dan receiver ownership registry.
- Port automatic/explicit dual-wallet spending policy dengan canonical IDR-before-USDT locking dan no implicit FX.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Port USDT/receipt/receiver invariants.
2. Implement postings, selection and reconciliation interfaces.
3. Buktikan PG races dan original-currency replay rules.

## Decision Document

Baca seluruh blocker dan spec induk. Jangan mulai selama blocker open; label ready-for-agent berarti fully specified, bukan mengabaikan blockers/human gates.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] Exact integer micro-USDT and representational overflow checks; no float or dual-currency debit.
- [ ] Same external receipt tidak dikonsumsi lintas rail/order/top-up dua kali; claims/aliases/history immutable.
- [ ] Automatic selection mematuhi wallet health/freeze/restore gates dan tidak fallback ketika stale atau unsafe.
- [ ] Receiver reuse/ownership changes ditolak sesuai existing schema; code availability bukan activation permission.

## Testing Decisions

- PG global receipt claim/receiver races, dual-wallet concurrent spend, refund/reversal replay.
- Property/invariant tests sums, projection drift and exact currency/source ownership.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Tidak menggabungkan IDR/USDT history ke ledger baru atau mengaktifkan funding.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.


## #701: [Python Migration][P17] Port USDT funding, finality/reorg dan balance operational controls

Recorded tracker state: OPEN.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #700, #697

Status eksekusi: **ready-for-agent setelah blockers selesai**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Mempertahankan durable USD funding/recovery/control behavior tanpa membuka rails yang belum qualified.

## Solution

- Port targets/reservations/preparation/expiry/cancel, Binance/BSC observation/settlement dan late money review.
- Port reorg/spent recovery, drift freeze, treasury coverage, pauses, restore/replay/reconciliation barriers dan scoped alerts.
- Port USD presets/chooser/history dan rail readiness; external signing/custody assumptions tetap unchanged.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Port funding reservation and settlement state machines.
2. Port reorg/treasury/control/restore owners.
3. Buktikan finality, late money, barriers dan recovery scenarios.

## Decision Document

Baca seluruh blocker dan spec induk. Jangan mulai selama blocker open; label ready-for-agent berarti fully specified, bukan mengabaikan blockers/human gates.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] Expiry/cancel releases active slot without credit; late funds tetap attributable/reviewable.
- [ ] Reorg tidak menghapus postings atau membuat negative spendable balance; freeze/recovery requires authority.
- [ ] Pause new exposure tidak menghentikan authoritative settlement/refund/reconciliation/required alerts.
- [ ] Unqualified Binance/BSC funding stays disabled; restoration requires complete evidence and zero unexplained drift.

## Testing Decisions

- PG settlement/cancel/expiry/receiver/reorg races dan multi-page reconcile completeness.
- Disposable lost-observation/Redis-loss/restart/restore replay drill dengan no duplicate money/delivery.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Tidak real custody transfer, receiver rotation atau qualification baru tanpa human approval.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.


## #702: [Python Migration][P20] Port Customer API v1, admission, idempotency dan paginated delivery

Recorded tracker state: OPEN.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #695, #700, #698

Status eksekusi: **ready-for-agent setelah blockers selesai**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Mempertahankan existing API clients dan financial/order/delivery semantics.

## Solution

- Port API key digest/lifecycle, shared Redis admission/permit leases, availability switch, exact API contracts/errors/preferences.
- Port catalog/balance/history/keyset cursors dan create-order durable claims/stable payload fingerprints.
- Port delivery pagination/owned read state, supplier pending/manual review and immutable allocations.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Port admission/key/availability contracts.
2. Implement reads/orders/delivery adapters over shared domain.
3. Buktikan API compatibility, key race and external-client fixtures.

## Decision Document

Baca seluruh blocker dan spec induk. Jangan mulai selama blocker open; label ready-for-agent berarti fully specified, bukan mengabaikan blockers/human gates.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] Key rotate/revoke versus mutation fence safe; disabled API fail-closed without mutating claims/history.
- [ ] Customer+operation+Idempotency-Key stable across replay; payload mismatch conflicts and no second debit/order.
- [ ] Signed cursors/filters/response allowlists, currency mapping and old client routes compatible.
- [ ] Delivery pages continue same allocation; no unauthorized credential leak or synthetic partial-fulfillment state.

## Testing Decisions

- API contract comparisons and PG key/admission/order idempotency races across Telegram/web/API callers.
- Delivery pagination ownership/tamper/replay and supplier-review eligibility cases.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Tidak public API v2 redesign, change currency labels atau force clients rotate keys.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.


## #703: [Python Migration][P21] Port storefront, guest checkout, invoices dan customer pages

Recorded tracker state: OPEN.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #695, #696, #697, #701, #698

Status eksekusi: **ready-for-agent setelah blockers selesai**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Mempertahankan storefront/customer UX dalam server-rendered HTML dan vanilla JS.

## Solution

- Port home/catalog/Family/Plan/search/cart/direct review, policy consent, checkout recovery dan signed invoices/status/access.
- Port guest Recovery Key/owned orders/credentials/balance/activity/support/auth pages dan safe resend behavior.
- Convert Blade to Django templates dan Alpine interactions ke vanilla JS; preserve themes/assets/animations/locales/URLs.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Port template/static presentation contract.
2. Implement storefront/checkout/invoice/account adapters.
3. Buktikan browser flows and cross-channel domain parity.

## Decision Document

Baca seluruh blocker dan spec induk. Jangan mulai selama blocker open; label ready-for-agent berarti fully specified, bukan mengabaikan blockers/human gates.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] Pricing/stock/payment authority tetap shared domain, GET review tidak menciptakan side effects.
- [ ] Failed validation/stale price/stock preserve proper review context dan focus tanpa leaking secrets in URL/history.
- [ ] Guest checkout/recovery/order claim bekerja tanpa memaksa Telegram login; paid credential ownership intact.
- [ ] Responsive/no-JS/keyboard/reduced-motion/zoom behavior setara; scope changes need owner acceptance.

## Testing Decisions

- Real HTTP domain integration plus browser flows at phone/tablet/desktop, both themes/locales.
- Signed URL/CSRF/cart/session/credential access regression dan no-JS checkout cases.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Tidak rebrand/redesign, add React/Alpine atau mengubah checkout policy.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.


## #704: [Python Migration][P22] Port admin shell, katalog, pricing dan stock workspace

Recorded tracker state: OPEN.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #703, #698

Status eksekusi: **ready-for-agent setelah blockers selesai**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Mempertahankan dashboard custom dan pengelolaan katalog/akun di Django.

## Solution

- Port permission-filtered sidebar/topbar/direct tabs/linked shells, theme/mobile drawer/focus/state/navigation.
- Port categories/Families/Products/icons/banners/content/pricing tiers/reseller/stock intake/edit/delete/bulk/structured account tools.
- Replace Alpine with vanilla JS preserving modal/keyboard/focus/scroll/animation and no-JS fallback.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Port shell and vanilla JS lifecycle.
2. Port catalog/pricing/account-stock management adapters.
3. Buktikan browser/permission and financial mutation regressions.

## Decision Document

Baca seluruh blocker dan spec induk. Jangan mulai selama blocker open; label ready-for-agent berarti fully specified, bukan mengabaikan blockers/human gates.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] Dashboard tetap custom; default Django admin tidak menggantikan existing UI atau bypass domain mutations.
- [ ] Product/family/UI icon edits DB-backed dan invalidation/snapshot rules preserved; no new presentation ENV.
- [ ] Stock/pricing mutations memakai canonical transaction owners; exposed/duplicate credentials not accepted.
- [ ] Owner/Admin permissions, mobile drawer/modal races, safe error handling dan responsive layout setara.

## Testing Decisions

- Browser matrix direct tabs/back/forward/filter/hash/drawer/modal/no-JS/zoom plus permission checks.
- PG simultaneous pricing/intake/checkout/edit/delete races dan sanitized screenshot references.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Tidak melakukan cosmetic redesign atau mengedit projection/stock langsung lewat generic admin.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.


## #705: [Python Migration][P23] Port admin operasi order, pembayaran, saldo, supplier dan reporting

Recorded tracker state: OPEN.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #704, #699, #701, #698, #702

Status eksekusi: **ready-for-agent setelah blockers selesai**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Menjaga semua operational decision surfaces dan laporan dalam dashboard yang sama.

## Solution

- Port order/payment/refund/manual review/outbound ambiguity screens, supplier recovery/warranty controls.
- Port provider/maintenance/funding/spending/receiver/API availability/settings, IDR adjustments/reversals, USDT controls/restore review.
- Port period metrics/earnings/history/filters/export/audit dengan existing definitions dan role restrictions.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Port review/financial control interfaces.
2. Port operational dashboard settings/reports.
3. Buktikan permission/version/financial/browser parity.

## Decision Document

Baca seluruh blocker dan spec induk. Jangan mulai selama blocker open; label ready-for-agent berarti fully specified, bukan mengabaikan blockers/human gates.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] Dangerous confirmations tetap purpose/version/actor bound; owner exceptions tidak menjadi global authorization bypass.
- [ ] Reports berasal dari authority/snapshots existing, no cached fabricated balance atau implicit FX.
- [ ] Settings editable DB-backed dan old Payments unaffected; hidden inactive controls tidak mengaktifkan rails.
- [ ] Secret fields masked in UI/errors/exports dan manual decisions audited exactly once.

## Testing Decisions

- Permission/object-bound action tests dan PG version/conflicting review/financial action races.
- Browser real operational forms invalid/stale/success/back/no-JS, both themes/mobile; reporting outcome fixtures.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Tidak redesign financial metrics, bypass review atau self-authorize production actions.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.


## #706: [Python Migration][P24] Lengkapi seluruh bot commands, balance/history/API/admin presentation

Recorded tracker state: OPEN.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #687, #701, #698, #702, #705

Status eksekusi: **ready-for-agent setelah blockers selesai**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Menutup seluruh bot parity rows setelah initial navigation slice.

## Solution

- Port remaining commands/callbacks/cart/orders/history/stock/support/languages/maintenance/waitlist/balance chooser/presets/API management/admin capture.
- Preserve command exceptions/canonical root/quantity tokens/revision-bound confirmations, group public-safe surfaces dan auth interception.
- Preserve copy/icons/entities/media/delete-edit-send order serta delivered artifact navigation tanpa overwrite credentials.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Port remaining bot entry points/intents.
2. Lengkapi presentation/configuration/editing paths.
3. Buktikan all bot parity rows and old-button recovery.

## Decision Document

Baca seluruh blocker dan spec induk. Jangan mulai selama blocker open; label ready-for-agent berarti fully specified, bukan mengabaikan blockers/human gates.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] Every bot parity row P00 mapped and passed; old/stale/foreign callbacks recovery sesuai existing behavior.
- [ ] Premium emoji, per-product override, footer icons/colors, Unicode rejection fallback dan localized text preserved.
- [ ] Secrets first-display/API rotation tidak tersimpan di root/queue/cache/log; financial buttons private-owner scoped.
- [ ] No automatic credential pins, new forced membership gate atau unsanctioned presentation change.

## Testing Decisions

- Webhook-to-domain-to-transport golden cases semua locales/private/group contexts.
- PG navigation/intents/destructive confirmation races dan manual testing-bot QA screenshots/videos.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Tidak menambah fitur bot atau mengubah customer flow untuk memudahkan migration.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.


## #707: [Python Migration][P25] Port notifications, mail, broadcast, waitlist dan Sales Proof

Recorded tracker state: OPEN.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #706, #695, #696, #698

Status eksekusi: **ready-for-agent setelah blockers selesai**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Melanjutkan komunikasi bisnis dan pemulihan tanpa duplicate send atau data leakage.

## Solution

- Port admin/order/payment/balance notices, credential email snapshots, auth mail, broadcast/stock/price/waitlist notifications dan Sales Proof.
- Preserve outbound effect protocol/snapshot recipient/content, per-recipient locale/fallback/rate handling and queue isolation.
- Handle missing projection/materialization/Redis wakeups, privacy masking, disabled channel readiness dan recipient eligibility.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Port notification eligibility/content/snapshot contracts.
2. Implement families and durable recovery.
3. Buktikan ambiguity/privacy/queue-isolation and message parity.

## Decision Document

Baca seluruh blocker dan spec induk. Jangan mulai selama blocker open; label ready-for-agent berarti fully specified, bukan mengabaikan blockers/human gates.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] Credentials email retries selalu ke recipient snapshot; auth work expiry/single grant preserved and no log mail transport for secrets.
- [ ] Broadcast admin-authored content preserved; sales proof eligibility API fulfillment constraints and masked customer preserved.
- [ ] Unknown send reviewed without blind retry; sent projections repair without resend across families.
- [ ] Slow notification/mail tidak menahan interaksi/payment/receipt; local all recipients test-only.

## Testing Decisions

- Delivery/recovery/idempotency fixtures per family, effect claim races and partial/unknown send recovery.
- Mail sink/browser preview tests serta privilege/content-leak assertions without real customer messaging.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Tidak broadcast ke pelanggan, use live email recipients atau collapse receipt queue isolation.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.


## #708: [Python Migration][P26] Port scheduler, retention, metrics, health dan Python-only operations

Recorded tracker state: OPEN.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #701, #707, #705

Status eksekusi: **ready-for-agent setelah blockers selesai**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Memastikan pekerjaan rutin dan pemulihan tidak bergantung pada Artisan ketika PHP dilepas.

## Solution

- Map setiap scheduler/recovery/backfill/cleanup command ke Python equivalent dengan bounded scans/cursors/locks/leases.
- Port safe health/readiness, queue/worker topology, money/recovery alerts, retention/privacy dan operator evidence.
- Define fresh DB bootstrap/migration history/backup+files+roles+key restore tooling tanpa PHP; deployment design only, no workflows changed.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Port schedule/recovery/retention ownership matrix.
2. Implement bounded commands/health/alerts and schema bootstrap.
3. Buktikan no-PHP local operations and backup/restore evidence.

## Decision Document

Baca seluruh blocker dan spec induk. Jangan mulai selama blocker open; label ready-for-agent berarti fully specified, bukan mengabaikan blockers/human gates.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] Every operational parity row has Python owner/schedule/failure policy and tests; no silently dropped command.
- [ ] Cleanup tidak menghapus history/financial obligations/unknown delivery; metric/report failure tidak mengubah money.
- [ ] Health cheap/bounded dan tidak decrypt secrets atau claim capacity dari healthy status.
- [ ] Fresh install/upgrade/restore runs local without PHP CLI; grants/extensions/schema/functions remain preserved.

## Testing Decisions

- Fake-clock scheduler due/overlap/cursor tests plus real PG crash/recovery and retention safety.
- Disposable Python-only fresh DB and backup restore with files/key/grant verification; no production operation.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Tidak menjalankan scheduler baru di production atau mengubah CI/CD/deploy workflows.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.


## #709: [Python Migration][P27] Validasi full visual/browser parity dan public URL/API documentation

Recorded tracker state: OPEN.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #703, #704, #705, #706, #707

Status eksekusi: **ready-for-agent setelah blockers selesai**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Membuktikan UI/frontend dan dokumentasi publik setara sebelum full release gate.

## Solution

- Build controlled visual/interaction references untuk bot/admin/storefront dan critical animation states; compare sanitized datasets.
- Verify locales/themes/mobile/desktop/zoom/focus/no-JS/reduced-motion, URL redirects/canonical/SEO/structured data dan API docs/OpenAPI.
- Produce manual real Telegram/mobile acceptance checklist dengan exact source/artifact identities dan known rendering limits.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Freeze sanitized visual references and interaction matrix.
2. Fix parity gaps and port public docs/metadata.
3. Record full browser/bot acceptance evidence and remaining human cases.

## Decision Document

Baca seluruh blocker dan spec induk. Jangan mulai selama blocker open; label ready-for-agent berarti fully specified, bukan mengabaikan blockers/human gates.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] No unresolved unapproved text/menu/icon/style/media/animation/flow difference pada required rows.
- [ ] All documented public paths/API schemas align implemented behavior without legacy PHP-only endpoints.
- [ ] Screenshots alone tidak dianggap proof focus/scroll/modal/animation behavior atau real Android/iOS rendering.
- [ ] Public docs/examples bebas secrets/real customer data; owner review requirements explicit.

## Testing Decisions

- Browser interaction/visual matrix at 320/390/768/1023/1024/1280+ widths, both themes/locales, zoom/no-JS.
- Public HTTP route/SEO/OpenAPI contract tests plus recorded manual bot QA.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Tidak memperbaiki desain dengan perubahan terlihat tanpa persetujuan atau menganggap viewport emulation real-device proof.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.


## #710: [Python Migration][P28] Jalankan full correctness, security, recovery dan resource acceptance

Recorded tracker state: OPEN.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #702, #708, #709, #684

Status eksekusi: **ready-for-agent setelah blockers selesai**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Menguji aplikasi lengkap dengan workload latar representatif sebelum rehearsal cutover.

## Solution

- Run full parity matrix, cross-channel money/stock/receipt/auth/API/supplier races dan fault injection.
- Repeat performance protocol/30% navigation gates plus H1 payment-stage budgets on full stack under 2CPU/2GiB envelope.
- Perform threat-model review, dependency/secret exposure checks, DB role/permission audit and safe evidence bundle.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Complete cross-module acceptance fixtures and security cases.
2. Run integrated gates/resource measurements.
3. Publish readiness report, findings and residual qualification needs.

## Decision Document

Baca seluruh blocker dan spec induk. Jangan mulai selama blocker open; label ready-for-agent berarti fully specified, bukan mengabaikan blockers/human gates.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] HD (#684) telah disahkan operator: actual schema/grants, historical crypto/hash/identity coverage dan financial/history/file invariant audit lengkap, serta bukti restore backup dapat direproduksi. Sampel/synthetic saja tidak cukup untuk production readiness; mismatch/unknown belum selesai berarti no-go.

- [ ] All parity rows pass or explicitly human-gated qualification; no unresolved critical/high security or correctness violation.
- [ ] p50/p95 navigation goals per mandatory load/scenario remain met with full background work; no OOM/restart/unbounded backlog/connections.
- [ ] Fault/replay/restart preserves every economic source/allocation/unknown-delivery obligation and audit trail.
- [ ] Evidence includes failures/skips/unknowns and fresh source/data versions; no fake performance claim from initial slim slice.

## Testing Decisions

- Real PostgreSQL/Redis multiprocess races, disposable kills/Redis loss/provider fault cases and full browser contracts.
- Repeated controlled benchmark and full ledger/stock/outbound consistency audit after runs.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Tidak menutup human acceptance atau menguji kegagalan pada production.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.


## #711: [Python Migration][P29] Latih cutover/rollback lokal dalam batas 30 menit

Recorded tracker state: OPEN.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #710

Status eksekusi: **ready-for-agent setelah blockers selesai**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Menghasilkan runbook terukur yang menjaga transaksi, old buttons dan rollback tanpa stale database restore.

## Solution

- Rehearse new-purchase pause, provider callback continuity/durable buffering, drain PHP ready/delayed/reserved jobs/claims dan handoff single writer.
- Verify additive schema/crypto/status backward compatibility for Python writes if rollback to Laravel, signed links, sessions, files and production-bot identity continuity by fixtures.
- Design platform integration GitHub Actions→VPS from main without changing/running workflows; plan source drift reconciliation and no-PHP final operations.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Write checkpoint/rollback compatibility and ownership contract.
2. Implement local-only rehearsal controls/fixtures.
3. Run timed drills and publish production execution proposal with no-go triggers.

## Decision Document

Baca seluruh blocker dan spec induk. Jangan mulai selama blocker open; label ready-for-agent berarti fully specified, bukan mengabaikan blockers/human gates.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] Timed rehearsal covers fresh backup, checkpoint, drain, handoff, smoke and rollback with max total 30 minutes and explicit rollback deadline/margin.
- [ ] No live DB replacement by old local clone; no global queue purge, arbitrary PHP unserialization, double writer or lost callbacks.
- [ ] Old app can safely consume candidate new writes during rollback window; otherwise release verdict no-go.
- [ ] Runbook differentiates application rollback from emergency DB restore/replay and names owner/operator, health triggers, release IDs, pending human decisions.

## Testing Decisions

- Disposable bidirectional cutover+rollback with in-flight payment, effect claim, old callback, reserved job and lost ACK scenarios.
- Disposable backup/files/grants/key restore and reconciliation proof pada lingkungan terisolasi yang disetujui melalui HD (#684); tidak wajib di laptop atau mengunduh full snapshot. Ukur full elapsed time termasuk rollback. Fresh backup production pada hari cutover tetap memerlukan operator/izin H3.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Tidak deploy/restart/change webhook production, modify/run/watch CI, atau claim zero-loss tanpa proof.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.


## #712: [Python Migration][H2] Gate manusia: full parity, security, provider qualification dan rehearsal acceptance

Recorded tracker state: OPEN.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #711

Status eksekusi: **ready-for-human**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Pemilik menerima readiness evidence sebelum production execution dipertimbangkan.

## Solution

- Review complete parity, performance/resource, security/race/fault evidence, full UI/bot QA dan main drift.
- Resolve session/relogin policy, real-provider qualification scope/test identity/nominal/callback needs, backup/key custody and rollback compatibility.
- Keep resource plan existing VPS unless separate cost approval; set observation owner/window proposal dan release success criteria.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Record accepted readiness, qualified scopes and any residual no-go.
2. Prepare H3 operator handoff with explicit decisions.

## Decision Document

Hanya manusia/operator yang dapat mencatat keputusan dan menutup gate ini. Agent boleh menyiapkan evidence; tidak boleh menyetujui atas nama pemilik.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] HD (#684) dan full acceptance #710 membuktikan cakupan data aktual serta restore/reconciliation; laporan sintetis atau sampel saja tidak menjadi alasan meluluskan kompatibilitas production. Lokasi bukti boleh lingkungan terisolasi dekat data, bukan wajib laptop.

- [ ] Explicit owner acceptance atau no-go recorded; human checks/qualification unavailable stay blockers.
- [ ] Any real-money/live integration test needs explicit separate scope/authorization and safe accounts; sandbox pass alone insufficient.
- [ ] Rehearsal proves downtime/rollback envelope and complete financial/history/file preservation.
- [ ] Closing H2 does not authorize CI/deployment; H3 remains separate explicit gate.

## Testing Decisions

- Owner reviews testing bot/admin/storefront and safe evidence bundle.
- Operator verifies timed rehearsal, release manifests and remaining qualification obligations.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Tidak deploy, authorize new cost implicitly atau waive unresolved financial/security failures.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.


## #713: [Python Migration][H3] Gate manusia: authorized cutover, rollback window dan retirement approval

Recorded tracker state: OPEN.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #712

Status eksekusi: **ready-for-human**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Memisahkan production authorization/execution dari tiket agent serta memastikan periode observasi selesai sebelum PHP removal.

## Solution

- Pemilik memilih tanggal 00.00 WITA, operator, exact release/rollback identities, <=30-minute envelope dan instruksi CI/deployment terpisah.
- Operator melakukan authorized integration/main release dan cutover sesuai approved runbook; production checks hanya dalam scope baru yang diberikan.
- Tetapkan dan jalankan observation window, reconciliation/pending obligations, rollback retention serta keputusan explicit retirement.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Record approved execution/observation details.
2. Record outcome and owner retirement or rollback decision.

## Decision Document

Hanya manusia/operator yang dapat mencatat keputusan dan menutup gate ini. Agent boleh menyiapkan evidence; tidak boleh menyetujui atas nama pemilik.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] Execution requires explicit admin instructions at that time; ticket readiness atau closure H2 bukan izin operasi production.
- [ ] Actual release identity, cutover/downtime outcome, smoke/reconciliation evidence, any rollback dan operator decisions recorded without secrets.
- [ ] Observation duration is specified before execution; no auto-close on first healthy response.
- [ ] H3 closed only after successful observation and explicit PHP retirement approval; rollback/failed cutover keeps downstream blocked.

## Testing Decisions

- Only authorized production smoke/qualification; destructive races and unit/integration load tests stay isolated local.
- Reconcile obligations/new writes against approved evidence; no automatic CI investigation.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Tidak dapat dijalankan AFK atau dipakai sebagai standing authorization production/CI.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.


## #714: [Python Migration][P30] Retire runtime PHP setelah observasi dan approval eksplisit

Recorded tracker state: OPEN.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #713, #708

Status eksekusi: **ready-for-agent setelah blockers selesai**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Mencapai target no-PHP tanpa menghapus history/kewajiban atau membuang rollback sebelum waktunya.

## Solution

- Remove PHP/Composer/Artisan runtime images/processes/production scripts dan legacy frontend dependencies setelah approved cutover observation.
- Preserve immutable database history, legacy columns/identifiers/hashes dan historical fulfillment/financial obligations.
- Update deployment/runtime/tooling proposal dan local operations docs; actual external changes remain separately authorized, no workflow mutation under default no-CI rule.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Inventory final PHP dependencies and retirement compatibility.
2. Remove authorized local/runtime dependencies and update operations docs.
3. Verify PHP-free operation and retained history/rollback manifest.

## Decision Document

Baca seluruh blocker dan spec induk. Jangan mulai selama blocker open; label ready-for-agent berarti fully specified, bukan mengabaikan blockers/human gates.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] No operational path needs PHP for web/bot/jobs/scheduler/upgrade/backup/restore/asset delivery.
- [ ] Retained rollback artifacts have explicit expiry/ownership; no deletion before approved window closes.
- [ ] Schema/ledger/history not dropped for cosmetic cleanup; outstanding obligations remain recoverable Python-only.
- [ ] If workflow/platform edits are required, isolate documented patch proposal and request task-specific authorization before applying restricted CI/CD changes.

## Testing Decisions

- Fresh local install/run without PHP executable or Composer dependency; full smoke and critical restoration paths.
- Static dependency/runtime inventory plus unresolved-work recovery fixtures.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Tidak menghapus production image/data/history atau mengubah workflow tanpa authorization baru.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.


## #715: [Python Migration][P31] Audit hasil akhir dan dokumentasikan outcome migrasi

Recorded tracker state: OPEN.

Part of https://github.com/Alhiefikri/telegram-bot/issues/679

Blocked by: #714

Status eksekusi: **ready-for-agent setelah blockers selesai**. Integrasi: **migration/python-django-experiment**; child branch harus berasal dari dan kembali ke branch tersebut. Jangan implementasi atau merge migrasi langsung ke main.

## Problem Statement

Menutup induk hanya dengan evidence seluruh target tercapai dan operasi dapat dipelihara.

## Solution

- Audit feature matrix, supported dependencies, no-PHP runtime, public docs, backup/restore/upgrades dan operational ownership.
- Compare final accepted performance/payment budgets, actual resource use and rollout/observation evidence tanpa mengklaim dari synthetic results saja.
- Reconcile all tickets/ADRs/known issues, human acceptance, secret retention/cleanup and maintainer handoff.

## Commits

Urutan perubahan kecil yang runnable; tambahkan langkah pemisahan bila satu commit terlalu besar:

1. Complete final architecture/domain/operations documentation.
2. Audit acceptance records, secret cleanup and ownership.
3. Record final outcome and parent closure recommendation.

## Decision Document

Baca seluruh blocker dan spec induk. Jangan mulai selama blocker open; label ready-for-agent berarti fully specified, bukan mengabaikan blockers/human gates.

Spec induk adalah kontrak canonical. Django + PostgreSQL + bounded workers/Redis; frontend HTML/CSS/vanilla JS, no React/Alpine. Existing domain owners/ADRs dan Python compatibility seams dipertahankan. Gunakan data tersanitasi dengan financial/history invariants; no direct projection edits, auto customer merge, floats for money, or automatic activation of unqualified rails.

Lingkungan default lokal/disposable, Lhiestore Testing Bot, sandbox provider, egress allowlist dan mail sink. Eksperimen awal memakai data sintetis/representatif dengan invariant utuh; full snapshot production dan key asli bukan prasyarat. Akuisisi/pemeriksaan data production hanya oleh operator dengan izin terpisah melalui HD (#684), setelah H1 lanjut dan sebelum full acceptance (#710). HD tidak memblokir P03/P04/P08. H1 harus menyetujui lanjut sebelum full rewrite; H2/H3 memisahkan readiness, execution dan retirement.

## Acceptance Criteria

- [ ] All required features/obligations have final evidence; no hidden PHP runtime, legacy data drop atau unresolved high-risk security/correctness issue.
- [ ] Source/release identities, benchmark limitations, human approvals dan rollback-window closure recorded.
- [ ] Fresh-install/restore/upgrade/operator runbooks work with Python stack and existing VPS budget.
- [ ] Parent closure reflects successful full migration only; if experiment stopped, use H1 stop outcome instead of falsely completing P31.

## Testing Decisions

- Final local no-PHP operational audit and representative user/admin/API smoke.
- Review all acceptance evidence and unresolved findings; tests rerun only for changed/unresolved concerns.

Test observable behavior dan domain invariant. Money/stock/claim races memakai real PostgreSQL dan multiprocess synchronization; SQLite/mocks saja bukan proof. Fault injection hanya disposable local services. Evidence mencatat revision, versions, commands, dataset identity, outcomes/skips/unknowns, limits dan artifact locations tanpa secrets. Tambah tests berdasarkan risiko, bukan mengejar jumlah.

## Out of Scope

Tidak menutup parent otomatis, hide failed gates atau execute new production/CI work.

Pembuatan tiket ini tidak memberi izin production mutations, deployment/restart/webhook changes, real-money test, tambahan biaya, pengiriman ke pelanggan, atau operasi CI/CD. Jangan dispatch/re-run/cancel/poll/watch/investigate CI maupun mengubah workflow tanpa instruksi admin tersendiri. Untuk HD (#684)/H3 yang mendeskripsikan kerja operator production, pelaksanaan tetap membutuhkan instruksi eksplisit saat gate dijalankan; seluruh tiket agent tetap lokal.

## Further Notes

Referensi perilaku: domain glossary, implementation map dan ADR relevant di repo; prior tests ditemukan lewat inventory P00. Laporkan requirement yang belum tercakup/bertentangan dan jangan menyederhanakan authority. File spesifik sengaja tidak dikunci dalam tiket karena interface/owner adalah kontrak yang harus bertahan.
