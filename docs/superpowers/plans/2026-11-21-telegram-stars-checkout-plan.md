# Plan: implementasi Telegram Stars

Spec: [Telegram Stars checkout](../specs/2026-11-21-telegram-stars-checkout-spec.md). Tracker: [GitHub #740](https://github.com/Alhiefikri/telegram-bot/issues/740).

## Urutan pengiriman

1. **[T1 — Kebijakan harga #741](https://github.com/Alhiefikri/telegram-bot/issues/741):** harga IDR → XTR, kebijakan DB/Owner, preview/audit. Tidak membuka checkout.
2. **[T2 — Rail dan snapshot #742](https://github.com/Alhiefikri/telegram-bot/issues/742)** (setelah T1): ikat quote ke checkout, stok, identitas dan expiry target. Belum kirim invoice.
3. **[T3 — Telegram transport/fast path #743](https://github.com/Alhiefikri/telegram-bot/issues/743)** (setelah T2): invoice privat, outbound recovery, ingress durable dan pre-checkout deadline.
4. **[T4 — Settlement #744](https://github.com/Alhiefikri/telegram-bot/issues/744)** (setelah T3): verifikasi success, receipts, replay, late money dan recovery. Belum dilepas ke publik.
5. **[T5 — Customer UI & custom icon #745](https://github.com/Alhiefikri/telegram-bot/issues/745)** (setelah T4): detail/Cart, invoice/struk/riwayat dan terms. Terpisah dari refund sehingga T5 dan T6 bisa paralel setelah T4.
6. **[T6 — Support, refund, dan operator review #746](https://github.com/Alhiefikri/telegram-bot/issues/746)** (setelah T4): `/paysupport`, full refund, bukti reversal/reconciliation dan laporan XTR.
7. **[T7 — Readiness proof & runbook #747](https://github.com/Alhiefikri/telegram-bot/issues/747)** (setelah T5/T6): verifikasi integrated, test Telegram jika tersedia, playbook, dokumentasikan risiko & keputusan yang masih menunggu Owner. Tidak launch.

## Verification budget

| Claim | Owner bukti | Bukti minimum |
|---|---|---|
| Harga global benar dan race quote tidak membuat order dengan harga tak disetujui | T1/T2: T1 formula dan dashboard; T2 binding checkout | Golden vectors, stale quote dan PostgreSQL stock race/regresi direct checkout |
| Telegram hanya bisa meminta pembayaran yang masih sah dan tidak tertahan antrean biasa | T3 | HTTP fixtures termasuk worker/interaction lock sibuk, approval vs cancel/expiry di PG, delay budget dan outbound timeout |
| Order hanya settle sekali dan uang terlambat tidak mengeluarkan stok | T4 | Dedicated PostgreSQL race, inbox wakeup hilang, konflik charge, late review; assert order/payment/inventory/outbox |
| UX dan format dapat dibaca tanpa custom emoji | T5 | UI/Telegram fixtures multi-locale, fallback, navigasi, total dan method label |
| Support dan refund aman, tepat satu original tender | T6 | HTTP fixture, persisted refund evidence, ambiguous/wrong direction, PG double refund, no restock/no internal balance |
| Alur native end-to-end bisa dioperasikan tanpa mengubah produksi | T7 | Reuse evidence T1–T6; 1 skenario bayar/refund di test bot jika tersedia, pause drill dan regresi rail lama/web; laporkan gap client/KYC/payout atau batasan test |

Implementasi sebaiknya additive dan dapat digabung saat gate off; tugas yang masih blocked tidak dimulai sampai dependensi selesai. Jangan jalankan CI/deploy atau melakukan transaksi/payout nyata sebagai bagian tiket. Owner menentukan kurs/cadangan produksi, eligibility produk, treatment saldo existing dan keputusan rilis publik di luar pekerjaan agent ini.
