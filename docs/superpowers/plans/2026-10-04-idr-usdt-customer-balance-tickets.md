# Ticket implementasi saldo IDR/USDT

Canonical epic: https://github.com/Alhiefikri/telegram-bot/issues/536

## Ticket map implementasi (2026-10-04)

User menyetujui pemecahan plan/spec ke tiket. Klarifikasi produk: admin menambah **saldo IDR lewat Admin Dashboard dengan pencarian Telegram ID numerik exact** untuk Customer yang sudah terdaftar; preview identitas penerima dan nominal sebelum submit. Telegram ID yang tidak ditemukan tidak membuat customer otomatis. Username bukan canonical identity.

Ada **26 child tickets: 23 ready-for-agent, 2 needs-info, 1 ready-for-human**. `ready-for-agent` berarti kontrak pekerjaan teknis telah ditentukan; **dependency yang masih open tetap memblokir pengerjaan**. Mulai dari A0 (#537). Provider/bisnis belum ditentukan secara fiktif agar tiket tampak siap. H0/H1 memerlukan input operator/pihak berwenang; H2 pelaksanaan/sign-off produksi. Ticket teknis tidak mengotorisasi deploy, menerima dana, atau mengaktifkan rail produksi.

Maker-checker tetap default rancangan; staffing atau pengecualian single-operator diputuskan H0, bukan bypass otomatis. Primary scope USDT ialah BSC dengan permanent exclusive customer receiver dan verified custody/finality; Binance funding opsional dan disabled jika attribution kontraknya tidak terbukti.

| Key | Fase | Ticket / hasil | Label saat dibuat | Blocked by |
| --- | --- | --- | --- | --- |
| A0 | P0 | [#537](https://github.com/Alhiefikri/telegram-bot/issues/537) Audit baseline, tetapkan ADR dan lock graph saldo IDR/USDT | `ready-for-agent` | — |
| H0 | P0 | [#538](https://github.com/Alhiefikri/telegram-bot/issues/538) Finalisasi policy operasional saldo dan staffing approval | `needs-info` | #537 |
| H1 | P0/P5 | [#539](https://github.com/Alhiefikri/telegram-bot/issues/539) Putuskan custody, izin provider dan model customer funds USDT | `needs-info` | #537 |
| A1 | P5 | [#540](https://github.com/Alhiefikri/telegram-bot/issues/540) Kualifikasi kontrak BSC/custody dan kelayakan rail Binance opsional | `ready-for-agent` | #539 |
| R1 | P1 | [#541](https://github.com/Alhiefikri/telegram-bot/issues/541) Bangun global external money receipt registry dan claim service | `ready-for-agent` | #537 |
| R2 | P1 | [#542](https://github.com/Alhiefikri/telegram-bot/issues/542) Backfill receipts legacy dan cutover seluruh payment writers | `ready-for-agent` | #541 |
| I1 | P2 | [#543](https://github.com/Alhiefikri/telegram-bot/issues/543) Tambah IDR adjustment request dan ledger approval atomik | `ready-for-agent` | #537, #541 |
| I2 | P2 | [#544](https://github.com/Alhiefikri/telegram-bot/issues/544) Admin Dashboard: tambah saldo IDR melalui exact Telegram ID | `ready-for-agent` | #543, #538 |
| I3 | P2 | [#545](https://github.com/Alhiefikri/telegram-bot/issues/545) Reversal IDR yang tertaut entry dan pemulihan saldo terpakai | `ready-for-agent` | #543 |
| U1 | P3 | [#546](https://github.com/Alhiefikri/telegram-bot/issues/546) Bangun exact USDT account, immutable ledger dan accounting events | `ready-for-agent` | #537 |
| U2 | P3/P8 | [#547](https://github.com/Alhiefikri/telegram-bot/issues/547) USDT reconciliation: projection, receipts, liability dan treasury | `ready-for-agent` | #546, #542 |
| U3 | P4 | [#548](https://github.com/Alhiefikri/telegram-bot/issues/548) USDT DB policy, exposure quotas dan pause controls | `ready-for-agent` | #546, #538 |
| U4 | P5 | [#549](https://github.com/Alhiefikri/telegram-bot/issues/549) Global receiver ownership: alamat USDT eksklusif per customer | `ready-for-agent` | #537 |
| U5 | P5 | [#550](https://github.com/Alhiefikri/telegram-bot/issues/550) BSC USDT top-up rail dengan verified invoice/finality attribution | `ready-for-agent` | #540, #549, #541, #546 |
| U6 | P4 | [#551](https://github.com/Alhiefikri/telegram-bot/issues/551) USDT confirmation intents dan top-up target yang idempotent | `ready-for-agent` | #548, #549, #550 |
| U7 | P6 | [#552](https://github.com/Alhiefikri/telegram-bot/issues/552) USDT settlement, late money dan durable reconcile/outbound recovery | `ready-for-agent` | #551, #542, #547 |
| U8 | P6 | [#553](https://github.com/Alhiefikri/telegram-bot/issues/553) USDT exception review, compensation dan reorg incident handling | `ready-for-agent` | #552, #543 |
| U9 | P4/P7 | [#554](https://github.com/Alhiefikri/telegram-bot/issues/554) Telegram: saldo IDR/USDT, top-up USDT dan riwayat pribadi | `ready-for-agent` | #552 |
| U10 | P7 | [#555](https://github.com/Alhiefikri/telegram-bot/issues/555) Checkout full-payment dari Customer USDT Balance | `ready-for-agent` | #552, #548 |
| U11 | P7 | [#556](https://github.com/Alhiefikri/telegram-bot/issues/556) USDT refunds penuh/parsial untuk warranty dan fulfillment | `ready-for-agent` | #555 |
| O1 | P8 | [#557](https://github.com/Alhiefikri/telegram-bot/issues/557) Admin multi-currency reports dan authenticated customer web read-only | `ready-for-agent` | #544, #554, #556, #547 |
| O2 | P8 | [#558](https://github.com/Alhiefikri/telegram-bot/issues/558) Operational pause/alerts dan restore/rollback runbooks saldo | `ready-for-agent` | #553, #556, #548, #547 |
| V1 | P9 | [#559](https://github.com/Alhiefikri/telegram-bot/issues/559) Buktikan security, PostgreSQL races dan crash consistency end-to-end | `ready-for-agent` | #542, #544, #545, #553, #554, #556, #557, #558 |
| V2 | P9 | [#560](https://github.com/Alhiefikri/telegram-bot/issues/560) Benchmark balance hot paths, quotas, provider ingestion dan backlog | `ready-for-agent` | #559 |
| O3 | P10 | [#561](https://github.com/Alhiefikri/telegram-bot/issues/561) Release readiness checks dan staged canary/rollback preparation | `ready-for-agent` | #559, #560, #538, #539, #540 |
| H2 | P10 | [#562](https://github.com/Alhiefikri/telegram-bot/issues/562) Operator sign-off dan production canary saldo IDR/USDT | `ready-for-human` | #561 |

### Checklist epic

- [ ] #537 — A0: Audit baseline, tetapkan ADR dan lock graph saldo IDR/USDT
- [ ] #538 — H0: Finalisasi policy operasional saldo dan staffing approval
- [ ] #539 — H1: Putuskan custody, izin provider dan model customer funds USDT
- [ ] #540 — A1: Kualifikasi kontrak BSC/custody dan kelayakan rail Binance opsional
- [ ] #541 — R1: Bangun global external money receipt registry dan claim service
- [ ] #542 — R2: Backfill receipts legacy dan cutover seluruh payment writers
- [ ] #543 — I1: Tambah IDR adjustment request dan ledger approval atomik
- [ ] #544 — I2: Admin Dashboard: tambah saldo IDR melalui exact Telegram ID
- [ ] #545 — I3: Reversal IDR yang tertaut entry dan pemulihan saldo terpakai
- [ ] #546 — U1: Bangun exact USDT account, immutable ledger dan accounting events
- [ ] #547 — U2: USDT reconciliation: projection, receipts, liability dan treasury
- [ ] #548 — U3: USDT DB policy, exposure quotas dan pause controls
- [ ] #549 — U4: Global receiver ownership: alamat USDT eksklusif per customer
- [ ] #550 — U5: BSC USDT top-up rail dengan verified invoice/finality attribution
- [ ] #551 — U6: USDT confirmation intents dan top-up target yang idempotent
- [ ] #552 — U7: USDT settlement, late money dan durable reconcile/outbound recovery
- [ ] #553 — U8: USDT exception review, compensation dan reorg incident handling
- [ ] #554 — U9: Telegram: saldo IDR/USDT, top-up USDT dan riwayat pribadi
- [ ] #555 — U10: Checkout full-payment dari Customer USDT Balance
- [ ] #556 — U11: USDT refunds penuh/parsial untuk warranty dan fulfillment
- [ ] #557 — O1: Admin multi-currency reports dan authenticated customer web read-only
- [ ] #558 — O2: Operational pause/alerts dan restore/rollback runbooks saldo
- [ ] #559 — V1: Buktikan security, PostgreSQL races dan crash consistency end-to-end
- [ ] #560 — V2: Benchmark balance hot paths, quotas, provider ingestion dan backlog
- [ ] #561 — O3: Release readiness checks dan staged canary/rollback preparation
- [ ] #562 — H2: Operator sign-off dan production canary saldo IDR/USDT

Dependency native GitHub dan hubungan sub-issue menjadi live gate; baris Blocked by pada child body merupakan fallback yang eksplisit. Parent ditutup setelah mandatory technical deliverables + operator gates + release/canary evidence selesai, bukan setelah ticket creation/scaffolding.


Referensi: [plan](2026-10-04-idr-usdt-customer-balance-plan.md), [spec](../specs/2026-10-04-idr-usdt-customer-balance-design.md). Labels di tabel adalah snapshot pembuatan; GitHub current state authoritative.
