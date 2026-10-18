<?php

namespace App\ApiDocs;

/**
 * Bilingual long-form explanations for the public supplier API.
 *
 * Bodies are Markdown so the documentation page and `/api-docs/reference.md`
 * render the same sentences; the page renders both languages, the Markdown
 * reference renders English. Statements here are limited to behaviour that the
 * implemented services own: see {@see CustomerApiDocumentation} for the exact
 * endpoints, bounds and machine codes.
 */
final class CustomerApiGuide
{
    /**
     * @return list<array{id: string, title: array{id: string, en: string}, body: array{id: string, en: string}}>
     */
    public function sections(): array
    {
        return [
            $this->section('overview', 'Ikhtisar', 'Overview', <<<'MD'
Cakupan API ini sengaja sempit: membaca katalog dan saldo, lalu membuat pesanan yang dibayar penuh dari saldo. Semua endpoint berada di origin website ini dengan prefiks path `/api/v1`, dan hanya untuk komunikasi server ke server.

Yang tersedia:

- saldo IDR dan USD milik akun, beserta preferensi pembayaran tersimpan (`GET /api/v1/balance`);
- katalog Produk yang terlihat publik beserta kutipan harga per kuantitas (`GET /api/v1/products`, `GET /api/v1/products/{id}`);
- pesanan berbayar yang tahan pemutaran ulang (`POST /api/v1/orders`);
- riwayat, status, dan hasil pesanan yang sudah dibeli (`GET /api/v1/orders`, `GET /api/v1/orders/{id}`, `GET /api/v1/orders/{id}/delivery`).

Yang tidak ada di API ini: pengisian atau penyesuaian saldo, refund, transfer, penarikan, pengelolaan key, dan endpoint Admin. Key dikelola dari Store Bot. Bantuan dan refund tetap memakai halaman bantuan akun pada invoice pesanan.

Setiap respons memuat `X-Request-ID`. Catat nilai itu ketika melaporkan masalah: dukungan dapat menelusuri satu permintaan tanpanya.

Setiap permintaan dibatasi anggaran admission bersama, jadi integrasi yang rapi tetap harus menangani 429 dan 503.
MD, <<<'MD'
This API keeps a deliberately narrow scope: read the catalog and balances, then create orders funded completely from a balance. Every endpoint lives on this website origin under the `/api/v1` path prefix and is server-to-server only.

What is available:

- the account's own IDR and USD wallets and its saved payment preference (`GET /api/v1/balance`);
- publicly visible Products with per-quantity quotes (`GET /api/v1/products`, `GET /api/v1/products/{id}`);
- replay-safe paid orders (`POST /api/v1/orders`);
- history, status and results for orders already purchased (`GET /api/v1/orders`, `GET /api/v1/orders/{id}`, `GET /api/v1/orders/{id}/delivery`).

What is not here: funding or adjusting a balance, refunds, transfers, withdrawals, key management and Admin endpoints. Keys are managed from the Store Bot. Support and refunds stay on the existing account-help page of an order invoice.

Every response carries `X-Request-ID`. Record it when reporting a problem: support can trace one request without it.

Every request shares admission budgets, so a well-behaved integration still handles 429 and 503.
MD),

            $this->section('quickstart', 'Mulai cepat', 'Quickstart', <<<'MD'
1. Buat Customer API key dari Store Bot, lalu simpan nilainya di secret store backend Anda.
2. `GET /api/v1/balance` untuk memastikan key bekerja dan melihat saldo yang bisa dipakai.
3. `GET /api/v1/products/{id}?quantity=1` dan catat nilai `total` dari kutipan mata uang yang Anda otorisasi.
4. `POST /api/v1/orders` dengan `Idempotency-Key` yang stabil untuk niat pembelian itu dan `expected_totals` berisi nilai yang baru Anda catat.
5. Simpan `id` pesanan. Bila respons hilang, ulangi langkah 4 dengan key dan payload yang sama, atau baca `GET /api/v1/orders`.
6. `GET /api/v1/orders/{id}/delivery` setelah pengiriman siap. Pembacaan berulang mengembalikan unit yang sama; bila `pagination.has_more` true, lanjutkan dengan `pagination.next_cursor`.

Semua contoh kode di halaman ini memakai variabel lingkungan `STORE_ORIGIN`, `CUSTOMER_API_KEY`, `PRODUCT_ID`, dan `IDEMPOTENCY_KEY`, memakai batas waktu yang tegas, memeriksa status HTTP, dan tidak pernah mencoba ulang pembelian dengan key baru.
MD, <<<'MD'
1. Create a Customer API key from the Store Bot and store the value in your backend secret store.
2. `GET /api/v1/balance` to confirm the key works and to see spendable balances.
3. `GET /api/v1/products/{id}?quantity=1` and note the `total` of each currency quote you authorize.
4. `POST /api/v1/orders` with a stable `Idempotency-Key` for that purchase intent and `expected_totals` holding the values you just noted.
5. Keep the order `id`. If the response is lost, repeat step 4 with the same key and payload, or read `GET /api/v1/orders`.
6. `GET /api/v1/orders/{id}/delivery` once delivery is ready. Repeated reads return the same units; when `pagination.has_more` is true, continue with `pagination.next_cursor`.

Every code example on this page reads `STORE_ORIGIN`, `CUSTOMER_API_KEY`, `PRODUCT_ID` and `IDEMPOTENCY_KEY` from the environment, uses explicit timeouts, checks the HTTP status, and never retries a purchase with a new key.
MD),

            $this->section('authentication', 'Autentikasi dan keamanan key', 'Authentication and key security', <<<'MD'
Setiap permintaan memakai header `Authorization: Bearer <secret>`. Satu akun hanya punya satu key aktif.

- Key dibuat dan ditampilkan satu kali dari Store Bot. Nilai penuh hanya ada pada pesan pertama; setelah itu hanya penanda tersamar yang ditampilkan. Tidak ada endpoint untuk menampilkan kembali key lama.
- Simpan key di backend. Jangan pernah menaruhnya di URL, kode frontend, penyimpanan browser, analytics, tiket, atau log.
- Query string, cookie, sesi browser, sesi Admin, dan kredensial supplier tidak mengautentikasi API ini.
- Permintaan yang membawa header `Origin` browser ditolak dengan 403 `backend_only`.
- Rotasi dan penonaktifan tidak menghapus pesanan atau klaim idempotensi. Key baru milik akun yang sama tetap dapat membaca pesanan API sebelumnya.
- Batas pencabutan jelas: permintaan yang sudah lolos fence autentikasi di dalam transaksi finansial boleh selesai, dan pencabutan menunggu; permintaan yang hanya sudah admitted tetapi belum difence gagal 401 tanpa menulis apa pun. Pembacaan yang sudah diautentikasi sebelum pencabutan boleh selesai.

Saat menerima 401, jangan mengulang permintaan dengan asumsi gangguan sementara. Periksa apakah key benar, masih aktif, dan belum dicabut.
MD, <<<'MD'
Every request sends `Authorization: Bearer <secret>`. An account has exactly one active key.

- A key is created and shown once from the Store Bot. The full value exists only in that first message; afterwards only a masked identifier is displayed. No endpoint reveals an old key.
- Keep the key in a backend secret store. Never place it in a URL, frontend code, browser storage, analytics, tickets or logs.
- Query strings, cookies, browser sessions, Admin sessions and supplier connection credentials never authenticate this API.
- A request carrying a browser `Origin` header is rejected with 403 `backend_only`.
- Rotation and disable never erase orders or idempotency claims. A new key belonging to the same account still reads that account's earlier API orders.
- Revocation has a defined boundary: a request that already passed the authentication fence inside a financial transaction may finish, and revocation waits for it; a request that was merely admitted but not yet fenced fails 401 without writing anything. Reads authenticated before revocation may finish.

When you receive 401, do not retry as if it were a transient failure. Check that the key is correct, active and not revoked.
MD),

            $this->section('money', 'Uang: IDR, USD, dan USDT', 'Money: IDR, USD and USDT', <<<'MD'
Uang selalu dikirim sebagai string desimal, bukan angka JSON. Jangan melakukan aritmetika floating point pada nilai ini.

| Nilai | Bentuk dan contoh |
| --- | --- |
| IDR | Rupiah bulat, misalnya `"15000"` |
| USD | Sampai enam desimal tanpa nol di belakang, misalnya `"2"`, `"0.3"`, `"1.234567"`, `"0"` |

Dompet USD adalah denominasi toko yang didanai 1:1 oleh USDT: satu micro-USDT internal memetakan tepat ke `"0.000001"` USD. Ini bukan klaim kustodi fiat, bukan rekening bank, dan bukan jaminan penukaran. Ledger internal tetap memakai USDT, dan hanya serialisasi publik yang memakai USD; Order, Payment, dan catatan ledger historis tidak pernah ditulis ulang agar cocok dengan label dokumentasi.

Tidak ada konversi mata uang, penggabungan dompet, kredit, utang otomatis, atau saldo negatif. Pesanan dibayar penuh dari satu dompet, dan refund selalu kembali ke mata uang asli pesanan.

Nilai USD yang Anda kirim di `expected_totals` harus tepat dapat direpresentasikan dalam micro-USDT (maksimum enam desimal, tanpa eksponen, tanpa tanda, tanpa pemisah ribuan). Nilai tidak valid dijawab 422, bukan dibulatkan.
MD, <<<'MD'
Money always travels as a decimal string, never as a JSON number. Never run floating-point arithmetic on these values.

| Value | Shape and examples |
| --- | --- |
| IDR | Whole rupiah, for example `"15000"` |
| USD | Up to six decimals without trailing zeros, for example `"2"`, `"0.3"`, `"1.234567"`, `"0"` |

The USD wallet is the store denomination funded 1:1 by USDT: one internal micro-USDT maps exactly to `"0.000001"` USD. It is not a fiat-custody claim, not a bank account and not a redemption guarantee. The internal ledger still uses USDT and only public serialization uses USD; Orders, Payments and historical ledger rows are never rewritten to match the documentation label.

There is no currency conversion, combined wallet, credit, automatic debt or negative balance. An order is paid in full from one wallet, and a refund always returns in the order's original currency.

A USD value you send in `expected_totals` must be exactly representable in micro-USDT (at most six decimals, no exponent, no sign, no thousands separator). An invalid value is answered with 422 rather than rounded.
MD),

            $this->section('pricing', 'Harga: dasar, tier, dan harga reseller', 'Pricing: base, tiers and reseller overrides', <<<'MD'
Setiap kutipan memuat dua lapis harga:

- `regular_unit` dan `regular_total`: harga dasar Produk sebelum aturan kuantitas apa pun;
- `effective_unit` dan `total`: angka yang benar-benar ditagih checkout.

Prioritas harga efektif:

1. bila akun ini memiliki harga reseller untuk mata uang tersebut, harga itu menjadi harga satuan final dan dikalikan kuantitas;
2. bila tidak ada, tier kuantitas normal yang berlaku untuk kuantitas tersebut.

Override reseller tidak bertumpuk dengan tier, member, atau promo, dan tidak menciptakan kelayakan mata uang baru. Produk tanpa harga dasar USD tetap tidak eligible di USD meskipun akun punya override IDR. Harga ritel di bot dan website tidak berubah karena adanya harga reseller.

Katalog API memuat Produk lokal dan Produk yang dipasok supplier, termasuk Produk yang sedang tidak tersedia. `stock` bersifat informasional: angkanya gabungan tanpa rincian sumber, dan tidak menahan unit. Checkout selalu memeriksa ulang harga serta ketersediaan di dalam transaksi terkunci, sehingga kutipan yang sudah lama bisa berubah atau gagal.

`input_requirements` saat ini selalu kosong: checkout otomatis kanonik belum menerima kolom masukan dari pembeli. Mengirim kolom `inputs` dijawab 422, bukan diabaikan.
MD, <<<'MD'
Every quote carries two price layers:

- `regular_unit` and `regular_total`: the Product base price before any quantity rule;
- `effective_unit` and `total`: the numbers checkout actually charges.

Effective price precedence:

1. when this account has a reseller price for that currency, that price becomes the final unit price and is multiplied by quantity;
2. otherwise, the normal quantity tier for that quantity applies.

A reseller override never stacks with tiers, membership or promotions, and never manufactures currency eligibility. A Product without a base USD price stays ineligible in USD even when the account has an IDR override. Retail prices in the bot and on the website do not change because a reseller price exists.

The API catalog exposes local Products and supplier-backed Products, including Products that are currently unavailable. `stock` is informational: an aggregate without a source breakdown, and it reserves nothing. Checkout always rechecks price and availability inside its locked transaction, so a stale quote can change or fail.

`input_requirements` is currently always empty: canonical automatic checkout does not accept buyer-supplied fields yet. Sending an `inputs` key is answered with 422 rather than ignored.
MD),

            $this->section('balances', 'Saldo dan empat mode preferensi', 'Balances and the four preference modes', <<<'MD'
Ada empat mode preferensi pembayaran: `idr_only`, `usd_only`, `idr_first`, dan `usd_first`. Akun memakai `idr_first` sampai pemiliknya mengubahnya dari Store Bot. Override `preference` pada satu permintaan menang atas preferensi akun, dan mode yang terselesaikan disimpan pada klaim idempotensi, jadi mengubah preferensi akun tidak mengubah permintaan yang sedang berjalan atau yang diulang.

Pemilihan mata uang terjadi di dalam satu transaksi terkunci:

- mode tetap (`idr_only`, `usd_only`) hanya mempertimbangkan mata uang yang disebut;
- mode otomatis (`idr_first`, `usd_first`) baru mempertimbangkan mata uang kedua bila mata uang pertama punya kutipan eligible tetapi dompetnya sehat dan saldonya tidak menutup penuh, atau bila Produk tidak punya harga di mata uang pertama;
- mata uang tanpa harga eligible dilewati;
- saldo tidak pernah dibelah antara dua dompet. Satu pesanan, satu dompet, satu debit.

Dompet yang dibekukan, proyeksi ledger yang tidak sehat (drift), pause pengeluaran, restore gate, kegagalan stok, dan hasil eksternal yang belum diketahui bukan alasan berpindah mata uang. Bila tidak ada dompet yang diotorisasi menutup penuh pesanan, permintaan dijawab 402 dan tidak ada efek samping.

Setelah pesanan diterima, mata uang dan nominalnya terkunci untuk retry, pembatalan, dan refund, meskipun saldo, harga, preferensi, atau key berubah.

Dompet yang tidak sehat dilaporkan jujur di `GET /api/v1/balance` sebagai `available: false` dengan `amount: null`, bukan nol palsu.
MD, <<<'MD'
There are four payment preference modes: `idr_only`, `usd_only`, `idr_first` and `usd_first`. An account keeps `idr_first` until its owner changes it from the Store Bot. A per-request `preference` override wins over the account preference, and the resolved mode is snapshotted on the idempotency claim, so editing the account preference never changes a request that is running or being retried.

Currency selection happens inside one locked transaction:

- fixed modes (`idr_only`, `usd_only`) only consider their named currency;
- automatic modes (`idr_first`, `usd_first`) consider the second currency only when the first has an eligible quote but its wallet is healthy and does not fully cover the order, or when the Product has no price in the first currency;
- a currency without an eligible price is skipped;
- a balance is never split across wallets. One order, one wallet, one debit.

A frozen wallet, an unhealthy ledger projection (drift), a spending pause, the restore gate, a stock failure and an unknown external result are never reasons to switch currency. When no authorized wallet fully covers the order, the request is answered with 402 and no side effects.

Once an order is accepted, its currency and amount are fixed for retries, cancellation and refunds, even if balances, prices, preferences or keys change.

An unhealthy wallet is reported honestly by `GET /api/v1/balance` as `available: false` with `amount: null` instead of a fake zero.
MD),

            $this->section('consent', 'Persetujuan harga yang diharapkan', 'Expected price consent', <<<'MD'
Server tidak pernah memakai harga yang dikirim klien. `expected_totals` hanya persetujuan perubahan harga:

- salin nilai `total` dari `GET /api/v1/products/{id}?quantity=N` untuk setiap mata uang kandidat yang Anda otorisasi;
- mata uang primer hasil pemilihan preferensi wajib punya total yang diharapkan bila mata uang itu punya kutipan eligible, jika tidak permintaan dijawab 422;
- bila salah satu total yang diotorisasi tidak lagi cocok, permintaan dijawab 409 `price_changed` dengan `error.quotes` berisi total terbaru, dan tidak ada debit maupun alokasi;
- pada mode otomatis, mata uang kedua hanya boleh dipakai bila totalnya juga Anda otorisasi, jadi klien tidak pernah ditagih harga yang belum disetujui.

Contoh alur yang benar: baca kutipan, teruskan totalnya ke pengguna atau simpan di sisi server, lalu kirim total itu di `expected_totals`. Bila harga berubah di antara kedua langkah, integrasi menerima 409 dan dapat meminta persetujuan baru.

Kutipan tidak mengunci harga dan tidak menahan stok. Tidak ada layanan kutipan bertanda tangan, langganan webhook harga, atau reservasi harga.
MD, <<<'MD'
The server never uses a client-supplied price. `expected_totals` is only price-change consent:

- copy the `total` value from `GET /api/v1/products/{id}?quantity=N` for every candidate currency you authorize;
- the primary currency chosen by preference resolution requires an expected total whenever that currency has an eligible quote, otherwise the request is answered with 422;
- when any authorized total no longer matches, the request is answered with 409 `price_changed` carrying fresh totals in `error.quotes`, and nothing is debited or allocated;
- in automatic modes the second currency is charged only when its total was authorized too, so a client is never charged a price it did not acknowledge.

A correct flow: read the quote, hand the totals to your user or store them server-side, then send those totals in `expected_totals`. If the price changed between the two steps, your integration receives 409 and can ask for fresh consent.

A quote neither locks a price nor reserves stock. There is no signed-quote service, price webhook subscription or price reservation.
MD),

            $this->section('idempotency', 'Idempotensi dan pemulihan timeout', 'Idempotency and timeout recovery', <<<'MD'
`POST /api/v1/orders` mewajibkan header `Idempotency-Key`. Cakupannya adalah Customer + operasi + key, bukan versi key, sehingga rotasi atau penonaktifan key tidak pernah membuka klaim baru.

Klaim mengikat sidik jari payload kanonik: Produk, kuantitas, preferensi yang diminta, dan total yang diharapkan. Mode preferensi yang terselesaikan juga disimpan saat permintaan diterima.

| Situasi | Hasil |
| --- | --- |
| Key sama, payload sama, penerimaan pertama | 201 dengan pesanan baru |
| Key sama, payload sama, setelah respons hilang, restart, atau rotasi key | 200 dengan pesanan yang sama |
| Key sama, payload berbeda | 409 `idempotency_conflict`, tanpa efek |
| Key sama, permintaan identik masih berjalan | tunggu terbatas, lalu 409 `in_progress` dengan `Retry-After` |
| Klaim pra-pesanan ditinggalkan | satu pengambilalihan lease kedaluwarsa; yang kalah menerima pemutaran atau `in_progress` |

Pemulihan setelah timeout:

1. ulangi permintaan yang sama persis dengan key yang sama; atau
2. baca `GET /api/v1/orders` untuk menemukan pesanan yang sudah diterima.

Jangan pernah mengulang pembelian dengan key baru setelah timeout: itu membuat niat pembelian kedua dan bisa mendebit saldo dua kali. Klaim yang sudah berhasil tidak pernah kedaluwarsa, tidak pernah dipangkas, dan tidak bisa menghasilkan pembelian kedua.
MD, <<<'MD'
`POST /api/v1/orders` requires an `Idempotency-Key` header. Its scope is Customer + operation + key, never the key version, so rotation or disable never opens a fresh claim.

A claim binds the canonical payload fingerprint: Product, quantity, requested preference and expected totals. The resolved preference mode is snapshotted when the request is accepted.

| Situation | Result |
| --- | --- |
| Same key, same payload, first acceptance | 201 with the new order |
| Same key, same payload, after a lost response, restart or key rotation | 200 with the same order |
| Same key, different payload | 409 `idempotency_conflict`, no effect |
| Same key, an identical request still running | bounded wait, then 409 `in_progress` with `Retry-After` |
| Abandoned pre-order claim | one takeover of the expired lease; the loser receives the replay or `in_progress` |
| First acceptance | 201 with the already paid order |

Recovery after a timeout:

1. repeat the identical request with the same key; or
2. read `GET /api/v1/orders` to find the order that was already accepted.

Never retry a purchase with a new key after a timeout: that creates a second purchase intent and can debit the balance twice. A successful claim never expires, is never pruned and can never produce a second purchase.
MD),

            $this->section('fulfillment', 'Pesanan, pengiriman asinkron, dan pemulihan hasil', 'Orders, asynchronous delivery and result recovery', <<<'MD'
Pesanan yang diterima sudah dibayar, tetapi hasilnya bisa siap lebih lambat:

- Produk lokal dialokasikan di dalam transaksi checkout;
- Produk yang dipasok supplier diselesaikan pekerjaan fulfillment yang tahan restart, dan hanya unit yang sudah diotorisasi lifecycle kanonik yang dilepas.

Jadi `payment_status: "paid"` berarti uang sudah masuk, bukan bahwa unit sudah bisa dibaca. Progres dibaca dari `fulfillment` dan `delivery`:

| Nilai | Arti |
| --- | --- |
| `not_ready` | belum ada unit yang boleh dilepas |
| `processing` | pekerjaan fulfillment masih berjalan |
| `review` | ada peninjauan manual; tunggu atau hubungi dukungan, jangan membeli ulang |
| `partial` | sebagian unit sudah boleh dibaca |
| `complete` | semua unit pesanan sudah boleh dibaca |
| `refunded` | pesanan selesai melalui refund |

`GET /api/v1/orders/{id}/delivery` mengembalikan hasil yang sama pada setiap pembacaan, tanpa alokasi ulang, debit tambahan, atau pengiriman ulang. Bila respons checkout hilang, ulangi pembacaan hasil ini, bukan pembelian. Pengiriman supplier yang sebagian, peninjauan manual, dan unit yang dihentikan dilaporkan apa adanya; unit yang sudah dihentikan tidak pernah muncul kembali.

Respons hasil dibatasi ukurannya. Bila semua unit tidak muat dalam satu respons, endpoint membaginya menjadi halaman berurutan berdasarkan identitas unit teralokasi dan memberi `pagination.next_cursor`. Lanjutkan dengan cursor itu sampai `pagination.has_more` false: setiap unit muncul tepat sekali, dan mengulang halaman yang sama mengembalikan unit yang sama selama entitlement tidak berubah. `state` serta `progress` tetap menggambarkan seluruh pesanan, jadi `has_more` bukan tanda pengiriman supplier sebagian. Setiap halaman memeriksa ulang kepemilikan dan otorisasi lifecycle; refund, review, atau peninjauan ulang di antara halaman menghentikan pelepasan. Pesanan kecil tetap terbaca penuh dalam satu respons tanpa parameter paginasi.

Pekerjaan yang tertunda tidak hilang karena Redis, queue, atau worker berhenti: kewajiban fulfillment tersimpan di database dan pemulihan membacanya kembali. Hasil supplier yang belum diketahui masuk ke `review` tanpa pembelian baru dan tanpa refund otomatis.
MD, <<<'MD'
An accepted order is already paid, but its results can become ready later:

- local Products are allocated inside the checkout transaction;
- supplier-backed Products are completed by restart-safe fulfillment work, and only units the canonical lifecycle authorizes are released.

So `payment_status: "paid"` means the money is in, not that units are readable. Progress comes from `fulfillment` and `delivery`:

| Value | Meaning |
| --- | --- |
| `not_ready` | no unit may be released yet |
| `processing` | fulfillment work is still running |
| `review` | manual review is open; wait or contact support, do not buy again |
| `partial` | some units are already readable |
| `complete` | every ordered unit is readable |
| `refunded` | the order settled through a refund |

`GET /api/v1/orders/{id}/delivery` returns the same results on every read, with no reallocation, extra debit or resend. When a checkout response is lost, repeat this result read instead of buying again. Partial supplier delivery, manual review and retired units are reported as they are; a retired unit never reappears.

Result responses are size-bounded. When every unit does not fit in one response, the endpoint splits them into consecutive pages by allocated-unit identity and returns `pagination.next_cursor`. Follow that cursor until `pagination.has_more` is false: every unit appears exactly once, and repeating the same page returns the same units while entitlement is unchanged. `state` and `progress` still describe the whole Order, so `has_more` is not a sign of partial supplier fulfillment. Every page rechecks ownership and lifecycle authorization; a refund, review or renewed hold between pages stops release. A small Order still returns in full in one response without pagination input.

Pending work is not lost when Redis, the queue or a worker stops: fulfillment obligations are stored in the database and recovery reads them back. An unknown supplier outcome enters `review` with no new purchase and no automatic refund.
MD),

            $this->section('refunds', 'Refund dan garansi', 'Refunds and warranty', <<<'MD'
Refund dan garansi tetap memakai alur yang sudah ada. API ini tidak menambahkan endpoint refund, penyesuaian saldo, atau persetujuan garansi.

- Refund selalu memakai mata uang asli pesanan dan dipotong dari entitlement yang masih tersisa.
- Refund penuh hanya mengkredit sisa entitlement pada mata uang asli dan melaporkan nominal Payment asli sebagai `refund.confirmed_amount`.
- Unit yang sudah terkirim atau terekspos tidak pernah dikembalikan ke stok, termasuk setelah refund.
- Refund yang masih berjalan muncul sebagai `refund.state` `pending` atau `partial`, dengan `confirmed_amount` dan `pending_amount` yang terpisah.

Status refund dan status pengiriman adalah dua hal berbeda: pesanan yang kehilangan sebagian unit bisa selesai sebagai `refunded` sementara `delivery.state` tetap `partial`. Untuk mengajukan bantuan, buka halaman bantuan akun pada invoice pesanan memakai invoice dan Recovery Key.
MD, <<<'MD'
Refunds and warranty keep their existing flows. This API adds no refund endpoint, no balance adjustment and no warranty approval.

- A refund always uses the order's original currency and is drawn from the remaining entitlement.
- A full refund credits only the remaining entitlement in the original currency and reports the original Payment amount as `refund.confirmed_amount`.
- A unit that was delivered or otherwise exposed is never returned to stock, including after a refund.
- A refund in progress appears as `refund.state` `pending` or `partial`, with `confirmed_amount` and `pending_amount` reported separately.

Refund state and delivery state are different things: an order that lost some units can settle as `refunded` while `delivery.state` stays `partial`. To request help, open the account-help page of the order invoice with the invoice number and its Recovery Key.
MD),

            $this->section('limits', 'Batas laju dan header', 'Rate limits and headers', <<<'MD'
Semua anggaran berlaku bersamaan. Beberapa key, rotasi, atau berpindah endpoint tidak mereset anggaran per akun. Angka pada tabel di bawah adalah kebijakan admission awal, bukan klaim kapasitas terukur.

Setiap respons terautentikasi membawa `X-RateLimit-Limit`, `X-RateLimit-Remaining`, dan `X-RateLimit-Reset` (detik epoch, batas atas konservatif) untuk anggaran dengan sisa paling sedikit. Penolakan 429 menambahkan `Retry-After` dalam detik.

Bila admission bersama tidak dapat ditegakkan, permintaan terautentikasi gagal tertutup dengan 503 tanpa mutasi, bukan melanjutkan ke pekerjaan database atau provider tanpa batas. Kehilangan limiter tidak pernah melewati pengaman finansial di database: constraint PostgreSQL tetap otoritas terakhir.
MD, <<<'MD'
All budgets apply at the same time. Multiple keys, rotation or switching endpoints never resets the per-account budgets. The numbers in the table below are initial admission policy, not a measured-capacity claim.

Every authenticated response carries `X-RateLimit-Limit`, `X-RateLimit-Remaining` and `X-RateLimit-Reset` (epoch seconds, a conservative ceiling) for the budget with the least remaining headroom. A 429 response adds `Retry-After` in seconds.

When shared admission cannot be enforced, authenticated requests fail closed with 503 and no mutation instead of continuing into unbounded database or provider work. Losing the limiter never bypasses the database financial safeguards: PostgreSQL constraints stay the final authority.
MD),

            $this->section('errors', 'Error dan penanganan yang aman', 'Errors and safe handling', <<<'MD'
Setiap error memuat `error.code`, `error.message`, dan `error.request_id` yang sama dengan header `X-Request-ID`. Pakai `code`, jangan mem-parsing pesan manusia yang bisa berubah.

Aturan penanganan:

- periksa status HTTP sebelum membaca body;
- ulangi hanya kegagalan yang jelas sementara, dengan batas waktu dan jumlah percobaan yang tegas;
- untuk 429, hormati `Retry-After` dan header anggaran;
- untuk 409 `in_progress`, ulangi payload yang sama dengan key yang sama;
- untuk 409 `price_changed`, otorisasi kutipan baru secara sadar sebelum mengulang;
- untuk 503 dan timeout, jangan membuat pesanan pengganti. Ulangi permintaan yang sama, atau baca `GET /api/v1/orders` untuk melihat apakah penerimaan sudah tercatat;
- perlakukan 404 sebagai "tidak ada untuk akun ini": bentuknya sengaja tidak membedakan resource yang tidak ada dan resource milik Customer lain;
- jangan pernah mencatat key, header Authorization, atau isi unit hasil pembelian ke log, trace, APM, atau laporan error.

Error tidak pernah memutasi uang. Permintaan yang ditolak tidak meninggalkan pesanan, Payment, debit, reservasi, alokasi stok, atau baris keranjang API.
MD, <<<'MD'
Every error carries `error.code`, `error.message` and `error.request_id` matching the `X-Request-ID` header. Parse `code`; never parse the human message, which can change.

Handling rules:

- check the HTTP status before reading the body;
- retry only clearly transient failures, with explicit timeouts and an attempt bound;
- for 429, honour `Retry-After` and the budget headers;
- for 409 `in_progress`, repeat the same payload with the same key;
- for 409 `price_changed`, deliberately authorize fresh quotes before retrying;
- for 503 and timeouts, never create a replacement order. Repeat the identical request, or read `GET /api/v1/orders` to see whether acceptance was recorded;
- treat 404 as "not here for this account": the shape deliberately does not distinguish a missing resource from another Customer's resource;
- never log keys, Authorization headers, or purchased unit contents into logs, traces, APM or error reports.

No error mutates money. A rejected request leaves no Order, Payment, debit, reservation, stock allocation or API cart row.
MD),

            $this->section('status', 'Cakupan dan status yang jujur', 'Scope and honest status', <<<'MD'
Beberapa batas yang perlu diketahui sebelum integrasi produksi:

- API ini hanya membaca katalog dan saldo lalu membuat pesanan berbayar. Pengisian saldo, kurs, kustodi, dan penarikan bukan bagian dari kontrak ini.
- Dompet USD adalah denominasi toko yang didanai 1:1 oleh USDT. Ini bukan klaim kustodi fiat, bukan rekening bank, bukan jaminan penukaran, dan bukan pernyataan bahwa pengisian saldo USDT sudah aktif untuk umum.
- Kesiapan payment provider, gate aktivasi, pause saldo, dan restore gate tetap berlaku. Permintaan bisa dijawab 503 meskipun dokumentasi ini akurat, dan itu perilaku yang benar.
- Dokumentasi ini tidak menyatakan bahwa kapasitas, perizinan, atau penerimaan produksi sudah disetujui. Status aktivasi produksi di luar cakupan halaman ini.
- Kredensial hasil pembelian adalah data sensitif milik pembeli. Jangan menampilkannya di UI publik, menyimpannya di cache bersama, atau mengirimkannya ke pihak ketiga.
MD, <<<'MD'
Some limits worth knowing before a production integration:

- This API only reads the catalog and balances and then creates paid orders. Funding, exchange rates, custody and withdrawals are outside this contract.
- The USD wallet is the store denomination funded 1:1 by USDT. It is not a fiat-custody claim, not a bank account, not a redemption guarantee and not a statement that USDT funding is generally activated.
- Payment provider readiness, activation gates, balance pauses and the restore gate still apply. A request can be answered with 503 even while this documentation is accurate, and that is correct behaviour.
- This documentation makes no claim that capacity, legal permission or production acceptance has been approved. Production activation status is outside this page.
- Purchased credentials are the buyer's sensitive data. Never display them in a public UI, store them in a shared cache, or forward them to a third party.
MD),

            $this->section('machine', 'Berkas mesin dan cara memakainya', 'Machine-readable files and how to use them', <<<'MD'
Tiga berkas publik memakai kontrak yang sama dengan halaman ini:

- `openapi.json`: spesifikasi OpenAPI 3.1 untuk tool, linter, dan generator klien;
- `reference.md`: referensi Markdown untuk dibaca manusia atau model bahasa;
- `llms.txt`: indeks ringkas di root website yang menautkan keduanya.

Berkas-berkas ini tidak memuat kredensial, saldo nyata, kunci, atau data pesanan. Contoh di dokumentasi tidak menjalankan pembelian otomatis dan tidak pernah menyimpan key di URL atau penyimpanan browser.
MD, <<<'MD'
Three public files carry the same contract as this page:

- `openapi.json`: an OpenAPI 3.1 specification for tools, linters and client generators;
- `reference.md`: a Markdown reference for humans and language models;
- `llms.txt`: a concise index at the website root linking both.

None of these files contain credentials, real balances, keys or order data. Documentation examples never run an automatic purchase and never put a key in a URL or browser storage.
MD),
        ];
    }

    /**
     * @return array{id: string, title: array{id: string, en: string}, body: array{id: string, en: string}}
     */
    private function section(string $id, string $idTitle, string $enTitle, string $idBody, string $enBody): array
    {
        return [
            'id' => $id,
            'title' => ['id' => $idTitle, 'en' => $enTitle],
            'body' => ['id' => trim($idBody), 'en' => trim($enBody)],
        ];
    }
}
