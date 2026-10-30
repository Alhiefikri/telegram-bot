# Implementation plan: eksperimen Django dan migrasi kondisional

Canonical parent: https://github.com/Alhiefikri/telegram-bot/issues/679.
Spec lokal: [spec induk](../specs/2026-10-30-python-django-migration-spec.md).

## Aturan eksekusi

Branch integrasi: `migration/python-django-experiment`, bukan main. Setiap pekerjaan memakai child branch dari dan kembali ke integrasi. Tidak push, CI/CD, akses production, perubahan webhook atau biaya tambahan tanpa instruksi tersendiri. Label ready-for-agent tidak mengabaikan dependency atau human gate.

P00 #680, P01 #681 dan P02 #682 telah closed. Berikutnya P05 #683 dapat dimulai; P04 #686 juga bebas blocker. P03 #685 menunggu P05, bukan #684. Gunakan schema baseline repo, data sintetis/representatif dan key uji. Full snapshot production tidak diperlukan untuk mulai. Jangan menggunakan raw schema privat sebagai bootstrap tanpa review dan klasifikasi.

## Fase

1. P00–P05: inventory, isolasi, prosedur data, Django bootstrap, synthetic compatibility dan baseline PHP.
2. P06–P11: navigasi, checkout IDR, QRIS sandbox, delivery/recovery dan pengukuran pada dataset identik. P01/P08 memverifikasi Testing Bot/sandbox sebelum external smoke; fake bukan bukti live. H1 memutuskan lanjut/revisi/stop.
3. Setelah H1 lanjut: P12–P27 full parity dan HD #684 actual-data qualification. HD wajib selesai sebelum P28 #710; actual audit seluruh data relevan dan bukti restore backup tetap wajib, tetapi full dump baru/laptop restore tidak wajib jika metode terisolasi yang disetujui memberi bukti lengkap.
4. P28–P29: full acceptance dan rehearsal cutover/rollback, lalu H2 readiness.
5. H3 memberi izin execution/observation/retirement terpisah, lalu P30–P31. Tidak ada penutupan human gate otomatis.

## Ticket map

| ID | GitHub | Pekerjaan | Blocked by | Triage |
| --- | --- | --- | --- | --- |
| P00 | [#680](https://github.com/Alhiefikri/telegram-bot/issues/680) | Inventaris kontrak fitur, kewajiban historis, dan parity matrix | — | ready-for-agent |
| P01 | [#681](https://github.com/Alhiefikri/telegram-bot/issues/681) | Bangun harness lokal terisolasi dan anggaran seluruh stack | P00 (#680) | ready-for-agent |
| P02 | [#682](https://github.com/Alhiefikri/telegram-bot/issues/682) | Rancang acquisition, quarantine restore, sanitasi dan file manifest | P01 (#681) | ready-for-agent |
| P03 | [#685](https://github.com/Alhiefikri/telegram-bot/issues/685) | Buktikan schema, crypto, hash dan identity compatibility PHP↔Python | P02 (#682), P05 (#683) | ready-for-agent |
| P04 | [#686](https://github.com/Alhiefikri/telegram-bot/issues/686) | Ukur baseline Laravel untuk navigasi, checkout, paid observation dan delivery | P01 (#681), P00 (#680) | ready-for-agent |
| P05 | [#683](https://github.com/Alhiefikri/telegram-bot/issues/683) | Bootstrap Django, module ownership dan Python test environment | P01 (#681), P00 (#680) | ready-for-agent |
| P06 | [#687](https://github.com/Alhiefikri/telegram-bot/issues/687) | Port navigasi katalog pertama beserta premium emoji dan canonical root | P03 (#685), P05 (#683) | ready-for-agent |
| P07 | [#688](https://github.com/Alhiefikri/telegram-bot/issues/688) | Port atomic checkout, inventory dan Store Balance IDR untuk eksperimen | P03 (#685), P05 (#683) | ready-for-agent |
| P08 | [#689](https://github.com/Alhiefikri/telegram-bot/issues/689) | Integrasikan QRIS sandbox tanpa mengubah payment identity saat retry | P07 (#688), P01 (#681) | ready-for-agent |
| P09 | [#690](https://github.com/Alhiefikri/telegram-bot/issues/690) | Port receipt credentials dan outbound effect ledger protocol | P07 (#688), P06 (#687) | ready-for-agent |
| P10 | [#691](https://github.com/Alhiefikri/telegram-bot/issues/691) | Isolasi worker interaksi, payment, delivery dan durable recovery | P08 (#689), P09 (#690) | ready-for-agent |
| P11 | [#692](https://github.com/Alhiefikri/telegram-bot/issues/692) | Jalankan experiment gate: performance, security, race dan parity report | P04 (#686), P06 (#687), P08 (#689), P10 (#691) | ready-for-agent |
| H1 | [#693](https://github.com/Alhiefikri/telegram-bot/issues/693) | Gate manusia: nilai eksperimen dan putuskan lanjut, revisi atau stop | P11 (#692) | ready-for-human |
| HD | [#684](https://github.com/Alhiefikri/telegram-bot/issues/684) | Gate manusia: kompatibilitas data production dan bukti pemulihan, tanpa kewajiban full dump di laptop | P02 (#682), H1 (#693) | ready-for-human |
| P12 | [#694](https://github.com/Alhiefikri/telegram-bot/issues/694) | Lengkapi Order Lifecycle, expiry, cancel, refunds dan review | H1 (#693), P07 (#688) | ready-for-agent |
| P13 | [#696](https://github.com/Alhiefikri/telegram-bot/issues/696) | Lengkapi semua QRIS rails dan kompatibilitas Pakasir historis | P12 (#694), P08 (#689) | ready-for-agent |
| P14 | [#697](https://github.com/Alhiefikri/telegram-bot/issues/697) | Port Binance Pay dan BSC USDT checkout verification | P12 (#694) | ready-for-agent |
| P15 | [#699](https://github.com/Alhiefikri/telegram-bot/issues/699) | Lengkapi IDR top-up, adjustments, reversals dan reconciliation | P12 (#694), P13 (#696) | ready-for-agent |
| P16 | [#700](https://github.com/Alhiefikri/telegram-bot/issues/700) | Port USDT ledger, external receipt claims dan receiver ownership | P12 (#694), P15 (#699) | ready-for-agent |
| P17 | [#701](https://github.com/Alhiefikri/telegram-bot/issues/701) | Port USDT funding, finality/reorg dan balance operational controls | P16 (#700), P14 (#697) | ready-for-agent |
| P18 | [#698](https://github.com/Alhiefikri/telegram-bot/issues/698) | Port supplier acquisition, historical entitlement dan warranty | P12 (#694) | ready-for-agent |
| P19 | [#695](https://github.com/Alhiefikri/telegram-bot/issues/695) | Port customer/admin authentication, linking dan ownership compatibility | H1 (#693), P03 (#685), P05 (#683) | ready-for-agent |
| P20 | [#702](https://github.com/Alhiefikri/telegram-bot/issues/702) | Port Customer API v1, admission, idempotency dan paginated delivery | P19 (#695), P16 (#700), P18 (#698) | ready-for-agent |
| P21 | [#703](https://github.com/Alhiefikri/telegram-bot/issues/703) | Port storefront, guest checkout, invoices dan customer pages | P19 (#695), P13 (#696), P14 (#697), P17 (#701), P18 (#698) | ready-for-agent |
| P22 | [#704](https://github.com/Alhiefikri/telegram-bot/issues/704) | Port admin shell, katalog, pricing dan stock workspace | P21 (#703), P18 (#698) | ready-for-agent |
| P23 | [#705](https://github.com/Alhiefikri/telegram-bot/issues/705) | Port admin operasi order, pembayaran, saldo, supplier dan reporting | P22 (#704), P15 (#699), P17 (#701), P18 (#698), P20 (#702) | ready-for-agent |
| P24 | [#706](https://github.com/Alhiefikri/telegram-bot/issues/706) | Lengkapi seluruh bot commands, balance/history/API/admin presentation | P06 (#687), P17 (#701), P18 (#698), P20 (#702), P23 (#705) | ready-for-agent |
| P25 | [#707](https://github.com/Alhiefikri/telegram-bot/issues/707) | Port notifications, mail, broadcast, waitlist dan Sales Proof | P24 (#706), P19 (#695), P13 (#696), P18 (#698) | ready-for-agent |
| P26 | [#708](https://github.com/Alhiefikri/telegram-bot/issues/708) | Port scheduler, retention, metrics, health dan Python-only operations | P17 (#701), P25 (#707), P23 (#705) | ready-for-agent |
| P27 | [#709](https://github.com/Alhiefikri/telegram-bot/issues/709) | Validasi full visual/browser parity dan public URL/API documentation | P21 (#703), P22 (#704), P23 (#705), P24 (#706), P25 (#707) | ready-for-agent |
| P28 | [#710](https://github.com/Alhiefikri/telegram-bot/issues/710) | Jalankan full correctness, security, recovery dan resource acceptance | P20 (#702), P26 (#708), P27 (#709), HD (#684) | ready-for-agent |
| P29 | [#711](https://github.com/Alhiefikri/telegram-bot/issues/711) | Latih cutover/rollback lokal dalam batas 30 menit | P28 (#710) | ready-for-agent |
| H2 | [#712](https://github.com/Alhiefikri/telegram-bot/issues/712) | Gate manusia: full parity, security, provider qualification dan rehearsal acceptance | P29 (#711) | ready-for-human |
| H3 | [#713](https://github.com/Alhiefikri/telegram-bot/issues/713) | Gate manusia: authorized cutover, rollback window dan retirement approval | H2 (#712) | ready-for-human |
| P30 | [#714](https://github.com/Alhiefikri/telegram-bot/issues/714) | Retire runtime PHP setelah observasi dan approval eksplisit | H3 (#713), P26 (#708) | ready-for-agent |
| P31 | [#715](https://github.com/Alhiefikri/telegram-bot/issues/715) | Audit hasil akhir dan dokumentasikan outcome migrasi | P30 (#714) | ready-for-agent |


## Evidence dan validasi

Catat revision, versions, dataset seed/checksum/provenance, commands, hasil/gagal/skips/unknowns, resource/background workload dan limits. Synthetic scale assumptions bukan production measurements. Money/stock races memakai PostgreSQL nyata dan multiprocess; jangan relax durability atau domain invariants untuk benchmark.

Baseline visual mencakup screenshot/rekaman aman, alur menu, copy/locale, icon Unicode/custom emoji ID, style tombol dan aset yang direview. Customer/credential/payment proof dan QR payable asli tidak diperlukan untuk baseline visual. Tidak ada koleksi/export production yang dijalankan oleh planning ini.

GitHub adalah tracker kanonis. Perubahan berikutnya harus menyelaraskan dokumen lokal dan issue; historical evidence tidak boleh diubah menjadi klaim actual production pass.
