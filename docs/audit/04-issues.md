# Issue map: Logic App Bot + Web audit

Tanggal: 2026-08-17  
Status: **Stage 4 selesai**  
Semua issue di bawah berstatus OPEN dan berlabel `ready-for-agent`. Tidak ada kode aplikasi, config/env, production server, atau database production yang diubah dalam workflow audit ini.

## Spec issues

| Issue | Judul | Link | Scope |
|---:|---|---|---|
| #264 | Spec: durable Telegram Outbound Effects and ambiguity-safe delivery | https://github.com/Alhiefikri/telegram-bot/issues/264 | Temuan #1, #2, #3, dan #6a. Shared transactional outbox/ledger, state `pending/sending/sent/failed/unknown/manual_review`, lease, recovery, provider preservation, rollout. |
| #265 | Spec: CustomerLoginLink and admin login throttling hardening | https://github.com/Alhiefikri/telegram-bot/issues/265 | Temuan #4 dan #5. Explicit mass-assignment allowlist dan global per-IP limiter dengan increment atomik. |

## Tracer-bullet tickets

Ticket dibuat dalam urutan dependency. Native GitHub `blocked_by` edges berhasil dibuat dan setiap body juga menyimpan fallback `Blocked by`.

| Order | Issue | Judul | Blocked by | Kompleksitas | What it delivers |
|---:|---:|---|---|---|---|
| 1 | #266 | Build Telegram Outbound Effect foundation and durable Manual checkout delivery | None | besar | Shared ledger/state machine/lease/recovery dan satu jalur QR Manual checkout durable dari public Telegram callback sampai Telegram send. |
| 2 | #267 | Add operator review for ambiguous Telegram deliveries | #266 | sedang | Admin dapat melihat metadata aman, mark-as-sent tanpa resend, atau explicit resend version untuk `unknown/manual_review`. |
| 3 | #268 | Migrate KlikQRIS, Pakasir, and Binance checkout instructions to durable effects | #266, #267 | besar | Semua rail instruksi checkout memakai shared effect tanpa mengubah same-Payment KlikQRIS, legacy Pakasir, atau Binance verification semantics. |
| 4 | #269 | Migrate receipt and credential delivery to ordered durable effects | #266, #267 | besar | Receipt multi-chunk dan credential delivery tidak mengulang chunk yang sudah sent ketika chunk berikutnya ambigu. |
| 5 | #270 | Migrate order lifecycle and manual payment-proof notifications | #266, #267 | besar | Paid/pending/expired admin notification serta payment-proof forwarding durable dan transaction-aligned. |
| 6 | #271 | Migrate Sales Proof Post to ambiguity-safe durable delivery | #266, #267 | besar | Sales Proof tidak stranded di `sending`, tidak blind repost setelah ambiguous send, dan punya operator review. |
| 7 | #272 | Migrate Admin Broadcast recipient delivery to shared effects | #266, #267 | besar | Broadcast recipient memiliki outcome independen dengan content, locale, batching, dan flood control tetap terjaga. |
| 8 | #273 | Migrate stock and price notifications to shared effects | #266, #267 | besar | Stock/price recipient dan Store Channel notification menggunakan shared state tanpa claim reset duplicate. |
| 9 | #274 | Remove legacy Telegram claim columns after ledger migration | #266, #268, #269, #270, #271, #272, #273 | besar | Final expand-contract: ledger menjadi source of truth dan kolom/query claim legacy dihapus setelah compatibility gate. |
| 10 | #275 | Harden CustomerLoginLink mass assignment | None | kecil | Explicit allowlist dan one-time consume tetap bekerja melalui explicit `used_at` assignment. |
| 11 | #276 | Add global per-IP admin login throttling with atomic increments | None | sedang | Identifier bucket tetap ada, IP-wide bucket mencegah identifier rotation, dan increment tidak bergantung pada check-then-hit race. |

## Native dependency verification

Observed through GitHub API on 2026-08-17:

- #267 has 1 blocker: #266.
- #268, #269, #270, #271, #272, and #273 each have 2 blockers: #266 and #267.
- #274 has 7 blockers: #266, #268, #269, #270, #271, #272, and #273.
- #275 and #276 have no blockers and can start immediately.

Spec issues #264 and #265 are parent references, not blockers. They remain open as the durable problem/solution record while implementation tickets are worked independently.

## Implementer notes

- Start with #266. Do not implement a per-feature outbox beside the shared ledger.
- Treat Telegram transport ambiguity as `unknown`, never as safe automatic retry.
- Keep `orders.checkout_key` for checkout mutation idempotency.
- Preserve KlikQRIS same-Payment recovery, final provider `total_amount`, 15-minute TTL, and Telegram-only QRIS UX.
- Run the PostgreSQL concurrency gate for checkout, lifecycle, lease, and recovery changes. The normal suite alone is not sufficient for row-lock claims.
- Keep the temporary red-capable Stage 1 test `src/tests/Feature/AuditValidationTemporaryTest.php` uncommitted as a draft regression candidate until implementation tickets decide which assertions become permanent tests.
- Do not start the legacy column removal ticket until every outbound effect family has a green migration and operational report.
