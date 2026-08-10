# LHIE STORE — Telegram Bot Commerce

LHIE STORE adalah aplikasi commerce hybrid: **Store Bot** di Telegram untuk katalog dan checkout, **Web Storefront** untuk browsing/guest checkout, dan dashboard admin untuk operasi katalog, order, pembayaran, stok, broadcast, serta Store Channel.

## Mulai membaca repo

Untuk memahami codebase dengan cepat, baca berurutan:

1. [Panduan codebase untuk agent/AI](docs/agents/codebase.md) — peta modul, alur, invariant, dan file entry point.
2. [Glossary domain](CONTEXT.md) — istilah canonical yang harus dipakai.
3. [Architecture Decision Records](docs/adr/) — keputusan yang tidak boleh diubah diam-diam.
4. [Panduan operasional aplikasi](src/README.md) — setup, Docker, scheduler, webhook, dan troubleshooting.
5. [Issue tracker guide](docs/agents/issue-tracker.md) — cara membaca dan mengerjakan issue GitHub repo ini.

## Struktur repository

- `src/` — aplikasi Laravel PHP, routes, models, services, views, assets, dan tests.
- `docs/` — ADR, runbook, spesifikasi, dan dokumentasi agent.
- `scripts/` — smoke test, concurrency gate, benchmark, backup, dan operasi Docker.
- `docker-compose.yml` — topology production-like: app, Nginx, PostgreSQL, queue workers, dan scheduler.
- `CONTEXT.md` — glossary domain; jangan masukkan detail implementasi ke file ini.

## Quick start lokal

```bash
cd src
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
composer test
```

Untuk production-like validation, gunakan Docker dan ikuti [operational guide](src/README.md#50-docker-startup-and-dogfood-smoke-checks). Production menggunakan PostgreSQL, database queue, Supervisor, dan scheduler; suite test cepat menggunakan SQLite in-memory.

## Aturan penting

- `Payment Provider Switch` hanya memengaruhi checkout baru. Payment yang sudah dibuat tetap memakai provider asal.
- Provider yang dipilih owner tidak boleh berubah menjadi provider lain secara diam-diam. Khusus KlikQRIS, tidak ada automatic fallback ke Manual; outcome yang tidak pasti dipulihkan pada Payment yang sama.
- Order/payment transition harus melalui `OrderLifecycle`/`OrderStateMachine`, bukan mengubah status langsung dari controller.
- Callback provider adalah sinyal tidak tepercaya. Pembayaran otomatis hanya boleh menjadi paid setelah status provider authoritative cocok dengan Payment lokal.
- Jangan menulis credential, signature, raw payload provider, QR base64, atau data kontak privat ke log, test artifact, issue, atau dokumentasi.
- Saat mengubah istilah domain atau keputusan arsitektur, perbarui `CONTEXT.md` atau ADR yang sesuai.
