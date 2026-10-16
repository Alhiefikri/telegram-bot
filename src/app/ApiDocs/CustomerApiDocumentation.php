<?php

namespace App\ApiDocs;

use App\Http\Controllers\CustomerApiReadController;
use App\Services\CustomerApi\CustomerApiAdmission;
use App\Services\CustomerApi\CustomerApiOrderRequest;
use App\Services\CustomerApi\CustomerApiOrders;

/**
 * Canonical description of the public supplier API.
 *
 * The website page, `/api-docs/openapi.json` and `/api-docs/reference.md` are
 * all rendered from this class, {@see CustomerApiExamples} and
 * {@see CustomerApiGuide}. A contract change therefore lands in one place
 * instead of three hand-maintained copies. Every value mirrors the exercised
 * implementation: routes in `routes/api.php`, request bounds in
 * {@see CustomerApiReadController},
 * {@see CustomerApiOrderRequest},
 * {@see CustomerApiOrders} and admission budgets in
 * {@see CustomerApiAdmission}.
 *
 * Descriptions written as `['id' => …, 'en' => …]` are bilingual because the
 * documentation website renders both languages; schema field descriptions stay
 * in English alone because they are the API's own field vocabulary and the
 * OpenAPI document is the machine contract.
 */
final class CustomerApiDocumentation
{
    /** Version of the published document, not of the API path (`/api/v1`). */
    public const DOCUMENT_VERSION = '1.0.0';

    public const API_PREFIX = '/api/v1';

    public const BODY_LIMIT_BYTES = 32_768;

    public const PAGE_LIMIT_DEFAULT = 20;

    public const PAGE_LIMIT_MAX = 100;

    public const QUANTITY_MIN = 1;

    public const QUANTITY_MAX = 100;

    public const IDEMPOTENCY_KEY_MAX_LENGTH = 128;

    public const DELIVERY_RESPONSE_LIMIT_BYTES = 1_048_576;

    /** Canonical money strings exactly as the serializers emit them. */
    public const IDR_PATTERN = '^(?:0|[1-9][0-9]*)$';

    public const USD_PATTERN = '^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?$';

    public const POSITIVE_IDR_PATTERN = '^[1-9][0-9]*$';

    public const POSITIVE_USD_PATTERN = '^(?:[1-9][0-9]*(?:\.[0-9]{1,6})?|0\.(?:[0-9]{0,5}[1-9]))$';

    public const SERVER_TO_SERVER_ONLY = 'Authorization: Bearer <secret>';

    /**
     * @return array{id: string, en: string}
     */
    public function title(): array
    {
        return [
            'id' => 'API Supplier Lhie Store',
            'en' => 'Lhie Store Supplier API',
        ];
    }

    /**
     * @return array{id: string, en: string}
     */
    public function lead(): array
    {
        return [
            'id' => 'Hubungkan backend Anda ke toko ini sebagai supplier: baca katalog dan saldo, lalu buat pesanan yang dibayar dari saldo IDR atau USD. Semua endpoint berada di origin website ini dan hanya untuk komunikasi server ke server.',
            'en' => 'Connect your backend to this store as a supplier: read the catalog and balances, then create orders paid from the IDR or USD balance. Every endpoint lives on this website origin and is server-to-server only.',
        ];
    }

    /**
     * @return list<array{name: string, description: string}>
     */
    public function tags(): array
    {
        return [
            ['name' => 'Balance', 'description' => 'Own wallet snapshot and saved payment preference.'],
            ['name' => 'Catalog', 'description' => 'Publicly visible Products, their quotes and supported requirements.'],
            ['name' => 'Orders', 'description' => 'Replay-safe balance-funded ordering through the canonical checkout.'],
            ['name' => 'Delivery', 'description' => 'Recovery reads for units already authorized by the order lifecycle.'],
        ];
    }

    /**
     * @return array{scheme: string, header: string, format: string, description: string}
     */
    public function security(): array
    {
        return [
            'scheme' => 'bearerAuth',
            'header' => 'Authorization',
            'format' => self::SERVER_TO_SERVER_ONLY,
            'description' => 'Customer API key issued from the private Store Bot. Send it as `Authorization: Bearer <secret>` over HTTPS. Query strings, cookies, browser sessions, Admin sessions and supplier connection credentials never authenticate this API.',
        ];
    }

    /**
     * Concrete initial admission budgets. They are policy, not measured capacity.
     *
     * @return list<array{scope: array{id: string, en: string}, bound: array{id: string, en: string}}>
     */
    public function budgets(): array
    {
        return [
            ['scope' => ['id' => 'Semua trafik v1 per IP nyata', 'en' => 'All v1 traffic per trusted real IP'], 'bound' => ['id' => '600 permintaan / 60 detik', 'en' => '600 requests / 60 seconds']],
            ['scope' => ['id' => 'Autentikasi hilang atau tidak valid per IP', 'en' => 'Missing or invalid authentication per IP'], 'bound' => ['id' => '30 permintaan / 60 detik', 'en' => '30 requests / 60 seconds']],
            ['scope' => ['id' => 'Baca terautentikasi per akun (semua endpoint, semua key)', 'en' => 'Authenticated reads per account (all endpoints, all keys)'], 'bound' => ['id' => '120 permintaan / 60 detik', 'en' => '120 requests / 60 seconds']],
            ['scope' => ['id' => 'Baca hasil pengiriman, tambahan', 'en' => 'Delivery-result reads, additionally'], 'bound' => ['id' => '10 permintaan / 60 detik', 'en' => '10 requests / 60 seconds']],
            ['scope' => ['id' => 'Percobaan pesanan per akun (termasuk retry dan konflik)', 'en' => 'Order attempts per account (including retries and conflicts)'], 'bound' => ['id' => '10 permintaan / 60 detik', 'en' => '10 requests / 60 seconds']],
            ['scope' => ['id' => 'Baca global seluruh toko', 'en' => 'Global store-wide reads'], 'bound' => ['id' => '300 / detik', 'en' => '300 / second']],
            ['scope' => ['id' => 'Pesanan global seluruh toko', 'en' => 'Global store-wide orders'], 'bound' => ['id' => '20 / detik', 'en' => '20 / second']],
            ['scope' => ['id' => 'Pesanan bersamaan per akun', 'en' => 'Concurrent orders per account'], 'bound' => ['id' => '2 permintaan sekaligus, permit 90 detik', 'en' => '2 in flight, 90-second permit']],
        ];
    }

    /**
     * Every machine code this API can return, with the canonical HTTP status.
     *
     * @return array<string, array{status: int, meaning: array{id: string, en: string}}>
     */
    public function errors(): array
    {
        return [
            'unauthenticated' => ['status' => 401, 'meaning' => [
                'id' => 'Key hilang, tidak valid, atau sudah dicabut. Verifikasi juga gagal bila key dicabut sebelum fence finansial.',
                'en' => 'Missing, invalid or revoked key. Verification also fails when the key was revoked before the financial fence.']],
            'insufficient_funds' => ['status' => 402, 'meaning' => [
                'id' => 'Tidak ada dompet kandidat yang diotorisasi dan menutup penuh pesanan. Parameter status juga membawa `error.quotes` pada `price_changed`, bukan di sini.',
                'en' => 'No authorized candidate wallet fully covers the order. `error.quotes` accompanies `price_changed`, not this code.']],
            'backend_only' => ['status' => 403, 'meaning' => [
                'id' => 'Permintaan membawa header `Origin` browser. Key Customer API hanya untuk server ke server.',
                'en' => 'The request carried a browser `Origin` header. Customer API keys are server-to-server only.']],
            'not_found' => ['status' => 404, 'meaning' => [
                'id' => 'Produk tidak ada atau tidak publik, atau pesanan tidak dimiliki akun ini. Bentuk responsnya sengaja tidak bisa dibedakan.',
                'en' => 'The Product does not exist or is not public, or the Order is not owned by this account. The response shape is deliberately indistinguishable.']],
            'method_not_allowed' => ['status' => 405, 'meaning' => [
                'id' => 'Metode HTTP tidak didukung endpoint tersebut.',
                'en' => 'The HTTP method is not supported by that endpoint.']],
            'idempotency_conflict' => ['status' => 409, 'meaning' => [
                'id' => 'Key ini sudah pernah diterima dengan payload berbeda. Kirim payload yang ingin diubah memakai key baru.',
                'en' => 'The key was already accepted with a different payload. Send the changed payload with a new key.']],
            'price_changed' => ['status' => 409, 'meaning' => [
                'id' => 'Salah satu total yang diotorisasi tidak lagi cocok. `error.quotes` berisi total IDR dan USD terbaru; tidak ada debit atau alokasi.',
                'en' => 'An authorized expected total no longer matches. `error.quotes` carries the fresh IDR and USD totals; nothing was debited or allocated.']],
            'stock_conflict' => ['status' => 409, 'meaning' => [
                'id' => 'Kuantitas yang diminta sudah tidak tersedia.',
                'en' => 'The requested quantity is no longer available.']],
            'in_progress' => ['status' => 409, 'meaning' => [
                'id' => 'Permintaan identik masih berjalan. Tunggu `Retry-After`, lalu ulangi dengan key dan payload yang sama.',
                'en' => 'An identical request is still running. Wait for `Retry-After`, then retry with the same key and payload.']],
            'payload_too_large' => ['status' => 413, 'meaning' => [
                'id' => 'Body permintaan melewati 32 KiB.',
                'en' => 'The request body exceeds 32 KiB.']],
            'invalid_input' => ['status' => 422, 'meaning' => [
                'id' => 'Ada kolom, nilai, atau parameter yang tidak dikenal, hilang, atau tidak sesuai batas. Termasuk total yang diharapkan hilang untuk mata uang primer yang punya kutipan.',
                'en' => 'A field, value or parameter is unknown, missing or outside its bounds. This includes a missing expected total for a primary currency that has a quote.']],
            'currency_unavailable' => ['status' => 422, 'meaning' => [
                'id' => 'Permintaan memaksa `currency=USD` padahal Produk tidak punya harga dasar USD.',
                'en' => 'The request asserted `currency=USD` while the Product has no base USD price.']],
            'quote_unavailable' => ['status' => 422, 'meaning' => [
                'id' => 'Jadwal harga kanonik atau overflow representasi membuat kutipan aman tidak bisa dibentuk. Tidak ada kutipan parsial.',
                'en' => 'The canonical price schedule or a representational overflow prevents a safe quote. No partial quote is returned.']],
            'rate_limited' => ['status' => 429, 'meaning' => [
                'id' => 'Kuota admission habis. Baca `Retry-After` dan header `X-RateLimit-*`, lalu ulangi setelah jendela anggaran berakhir.',
                'en' => 'An admission budget is exhausted. Read `Retry-After` and the `X-RateLimit-*` headers, then retry after the budget window resets.']],
            'temporarily_unavailable' => ['status' => 503, 'meaning' => [
                'id' => 'Admission bersama tidak dapat ditegakkan, maintenance checkout, pause saldo, drift, atau hasil pengiriman melewati batas aman. Tidak ada uang yang dipindahkan.',
                'en' => 'Shared admission cannot be enforced, checkout maintenance, a balance pause, drift, or a delivery result beyond its safe bound. Nothing was charged.']],
        ];
    }

    /**
     * Every operation plus the body-size and method boundaries that the shared
     * `/api/v1` admission middleware applies to all of them.
     *
     * @return list<array<string, mixed>>
     */
    public function operations(): array
    {
        return array_map(static function (array $operation): array {
            $operation['errors'] = array_values(array_unique([...$operation['errors'], 'method_not_allowed', 'payload_too_large']));

            return $operation;
        }, $this->definedOperations());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function definedOperations(): array
    {
        return [
            [
                'id' => 'getBalance',
                'tag' => 'Balance',
                'method' => 'GET',
                'path' => self::API_PREFIX.'/balance',
                'title' => ['id' => 'Saldo dan preferensi pembayaran', 'en' => 'Balance and payment preference'],
                'summary' => [
                    'id' => 'Baca saldo IDR dan USD milik akun, kesehatan setiap dompet, dan preferensi pembayaran tersimpan.',
                    'en' => "Read the account's own IDR and USD wallets, the health of each wallet, and the saved payment preference.",
                ],
                'description' => [
                    'id' => 'Identitas Customer datang hanya dari key yang diterima; tidak ada parameter identitas. Dompet IDR dan USD selalu dikembalikan, dengan `amount` bernilai null bila proyeksi ledger tidak dapat dipercaya (drift) atau akun USD sedang dibekukan. Raw reason pembekuan, id akun, dan kursor ledger tidak pernah dikirim.',
                    'en' => 'Customer identity comes only from the admitted key; there is no identity parameter. Both the IDR and USD wallet are always returned, with a null `amount` when the ledger projection cannot be trusted (drift) or when the USD account is frozen. Freeze reasons, account ids and ledger cursors are never returned.',
                ],
                'parameters' => [],
                'request' => null,
                'responses' => [
                    ['status' => 200, 'schema' => 'BalanceEnvelope', 'description' => ['id' => 'Snapshot dompet akun ini.', 'en' => 'Wallet snapshot for this account.']],
                ],
                'errors' => ['unauthenticated', 'backend_only', 'rate_limited', 'temporarily_unavailable'],
                'notes' => [
                    ['id' => 'Saldo yang dilaporkan adalah snapshot ledger akun, bukan izin belanja: pause pengeluaran dan restore gate tetap dinilai saat checkout.',
                        'en' => 'A reported balance is an account ledger snapshot, not spend authorization: spending pauses and the restore gate are still evaluated at checkout.'],
                    ['id' => 'Akun tanpa baris ledger adalah nol yang sehat, bukan dompet yang tidak diketahui.',
                        'en' => 'An account without ledger rows is a healthy zero, not an unknown wallet.'],
                ],
            ],
            [
                'id' => 'listProducts',
                'tag' => 'Catalog',
                'method' => 'GET',
                'path' => self::API_PREFIX.'/products',
                'title' => ['id' => 'Katalog Produk', 'en' => 'Product catalog'],
                'summary' => [
                    'id' => 'Telusuri Produk yang terlihat publik dengan kutipan harga untuk kuantitas yang diminta.',
                    'en' => 'Traverse publicly visible Products with price quotes for the requested quantity.',
                ],
                'description' => [
                    'id' => 'Urutan menaik berdasarkan id Produk dengan keyset `cursor`. Kutipan memakai harga normal, tier kuantitas, dan override reseller akun yang sama seperti checkout, tetapi tidak mengunci harga dan tidak menahan stok. Produk yang tidak dapat dibeli tetap muncul dengan `stock` yang jujur.',
                    'en' => 'Ascending Product id with keyset `cursor` pagination. Quotes use the same normal price, quantity tiers and account reseller override as checkout, but they neither lock a price nor reserve stock. Unavailable Products still appear with honest `stock`.',
                ],
                'parameters' => [
                    self::query('limit', 'string', 'Jumlah baris per halaman, 1 sampai 100. Default 20. Tidak ada query total.',
                        'Rows per page, 1 to 100. Default 20. There is no total-count query.', ['pattern' => '^(?:[1-9]|[1-9][0-9]|100)$', 'default' => '20']),
                    self::query('cursor', 'string', 'Cursor opaque `pagination.next_cursor` dari halaman sebelumnya. Isi `0` untuk mulai dari awal. Hanya boleh dimasukkan pada halaman berikutnya dengan filter yang sama.',
                        'Opaque `pagination.next_cursor` from the previous page. Send `0` to start from the beginning. Accepted from the previous response only, with the same filters.', ['pattern' => '^(?:0|[1-9][0-9]*)$', 'default' => '0']),
                    self::query('quantity', 'string', 'Kuantitas yang dikutip, 1 sampai 100. Default 1. Stok yang kurang tidak menyembunyikan kutipan.',
                        'Quantity to quote, 1 to 100. Default 1. A stock shortage does not suppress the quote.', ['pattern' => '^(?:[1-9]|[1-9][0-9]|100)$', 'default' => '1']),
                    self::query('category_id', 'string', 'Filter opsional kategori Produk berupa id positif, digabung dengan AND.',
                        'Optional Product category filter as a positive id, combined with AND.', ['pattern' => '^[1-9][0-9]*$']),
                    self::query('family_id', 'string', 'Filter opsional Product Family berupa id positif, digabung dengan AND.',
                        'Optional Product Family filter as a positive id, combined with AND.', ['pattern' => '^[1-9][0-9]*$']),
                    self::query('available', 'string', 'Opsional. `true` membatasi pada Produk yang dapat dibeli, `false` pada Produk yang tidak dapat dibeli, kosong untuk keduanya.',
                        'Optional. `true` restricts to purchasable Products, `false` to unavailable ones, omitted for both.', ['enum' => ['true', 'false']]),
                ],
                'request' => null,
                'responses' => [
                    ['status' => 200, 'schema' => 'ProductPage', 'description' => ['id' => 'Halaman Produk beserta paginasi.', 'en' => 'A page of Products with pagination.']],
                ],
                'errors' => ['unauthenticated', 'backend_only', 'invalid_input', 'rate_limited', 'temporarily_unavailable'],
                'notes' => [
                    ['id' => 'Produk nonaktif, terhapus lunak, dan kategori privat berperilaku seperti id yang tidak ada.',
                        'en' => 'Inactive, soft-deleted and private-category Products behave exactly like a missing id.'],
                    ['id' => 'Stok bersifat informasional: angka gabungan, tanpa rincian sumber lokal atau supplier, dan bukan reservasi.',
                        'en' => 'Stock is informational: an aggregate number without local or supplier source breakdown, and it is not a reservation.'],
                ],
            ],
            [
                'id' => 'getProduct',
                'tag' => 'Catalog',
                'method' => 'GET',
                'path' => self::API_PREFIX.'/products/{id}',
                'title' => ['id' => 'Detail Produk dan kutipan kuantitas', 'en' => 'Product detail and quantity quote'],
                'summary' => [
                    'id' => 'Baca satu Produk dengan kutipan IDR dan USD yang otoritatif untuk kuantitas yang diminta.',
                    'en' => 'Read one Product with authoritative IDR and USD quotes for the requested quantity.',
                ],
                'description' => [
                    'id' => 'Selalu mengembalikan objek kutipan IDR dan USD. Harga yang tersedia dan prioritas reseller atau tier mengikuti aturan harga kanonik yang sama dengan checkout, jadi nilai `total` di sini adalah angka yang harus Anda salin ke `expected_totals` saat membuat pesanan. `input_requirements` saat ini selalu kosong karena checkout otomatis kanonik tidak menerima kolom masukan pembeli.',
                    'en' => 'Always returns both the IDR and USD quote objects. Eligible prices and reseller or tier precedence follow the same canonical pricing as checkout, so the `total` value here is the number to copy into `expected_totals` when creating an order. `input_requirements` is currently always empty because canonical automatic checkout accepts no buyer-supplied input fields.',
                ],
                'parameters' => [
                    self::path('id', 'Id Produk positif. Perlakukan sebagai nilai opaque.', 'Positive Product id. Treat it as an opaque value.'),
                    self::query('quantity', 'string', 'Kuantitas yang dikutip, 1 sampai 100. Default 1.',
                        'Quantity to quote, 1 to 100. Default 1.', ['pattern' => '^(?:[1-9]|[1-9][0-9]|100)$', 'default' => '1']),
                    self::query('currency', 'string', 'Opsional. `IDR` atau `USD`. Menegaskan `USD` pada Produk tanpa harga dasar USD menghasilkan 422 `currency_unavailable`.',
                        'Optional. `IDR` or `USD`. Asserting `USD` on a Product without a base USD price returns 422 `currency_unavailable`.', ['enum' => ['IDR', 'USD']]),
                ],
                'request' => null,
                'responses' => [
                    ['status' => 200, 'schema' => 'ProductEnvelope', 'description' => ['id' => 'Satu Produk dengan kedua kutipan.', 'en' => 'One Product with both quotes.']],
                ],
                'errors' => ['unauthenticated', 'backend_only', 'not_found', 'invalid_input', 'currency_unavailable', 'quote_unavailable', 'rate_limited', 'temporarily_unavailable'],
                'notes' => [
                    ['id' => '`regular_unit` dan `regular_total` adalah harga dasar sebelum tier atau override reseller; `effective_unit` dan `total` adalah angka yang dipakai checkout.',
                        'en' => '`regular_unit` and `regular_total` are the base price before any tier or reseller override; `effective_unit` and `total` are the numbers checkout uses.'],
                ],
            ],
            [
                'id' => 'createOrder',
                'tag' => 'Orders',
                'method' => 'POST',
                'path' => self::API_PREFIX.'/orders',
                'title' => ['id' => 'Buat pesanan berbayar', 'en' => 'Create a paid order'],
                'summary' => [
                    'id' => 'Buat tepat satu pesanan yang dibayar penuh dari satu dompet saldo, dilindungi klaim idempotensi yang tahan restart.',
                    'en' => 'Create exactly one order funded completely by one balance wallet, protected by an idempotency claim that survives restarts.',
                ],
                'description' => [
                    'id' => 'Satu Produk dan kuantitas positif per permintaan. Server selalu menghitung ulang harga dari snapshot terkunci dan membandingkannya dengan `expected_totals` yang Anda otorisasi sebelum debit apa pun. Permintaan identik yang diulang dengan key sama mengembalikan pesanan yang sama, bukan pembelian kedua. Endpoint ini tidak menyentuh keranjang Telegram atau web milik Customer.',
                    'en' => 'One Product and a positive quantity per request. The server always recomputes the price from the locked snapshot and checks it against the `expected_totals` you authorized before any debit. Repeating an identical request with the same key returns the same order instead of buying again. This endpoint never touches the Customer\'s Telegram or web cart.',
                ],
                'parameters' => [
                    self::header('Idempotency-Key', 'Wajib. Kunci checkout yang stabil dan unik untuk satu niat pembelian, 1 sampai 128 karakter ASCII yang dapat dicetak. Jangan pernah memakai key baru untuk mengulang permintaan yang gagal atau kehilangan respons.',
                        'Required. A stable checkout key that is unique per purchase intent, 1 to 128 printable ASCII characters. Never use a new key to retry a failed or lost-response request.',
                        ['pattern' => '^[\\x20-\\x7E]{1,128}$']),
                ],
                'request' => [
                    'schema' => 'CreateOrderRequest',
                    'required' => true,
                    'description' => [
                        'id' => 'Objek JSON dengan kolom `product_id`, `quantity`, `preference` opsional, dan `expected_totals` opsional. Maksimum body 32 KiB dan kolom tak dikenal selalu ditolak.',
                        'en' => 'A JSON object with `product_id`, `quantity`, an optional `preference` and optional `expected_totals`. The body is limited to 32 KiB and unknown fields are always rejected.',
                    ],
                ],
                'responses' => [
                    ['status' => 201, 'schema' => 'OrderCreateEnvelope', 'description' => ['id' => 'Pesanan baru diterima dan sudah dibayar.', 'en' => 'A new order was accepted and paid.']],
                    ['status' => 200, 'schema' => 'OrderCreateEnvelope', 'description' => ['id' => 'Pemutaran ulang klaim yang sama: pesanan, mata uang, dan nominal asli dipertahankan dengan `idempotent_replay: true`.', 'en' => 'Replay of the same accepted claim: the original order, currency and amount are preserved with `idempotent_replay: true`.']],
                ],
                'errors' => ['unauthenticated', 'backend_only', 'not_found', 'payload_too_large', 'invalid_input', 'insufficient_funds', 'price_changed', 'idempotency_conflict', 'stock_conflict', 'in_progress', 'rate_limited', 'temporarily_unavailable'],
                'notes' => [
                    ['id' => 'Untuk mengubah apa pun di body, kirim key **baru**. Payload berbeda pada key lama selalu 409 `idempotency_conflict`.',
                        'en' => 'To change anything in the body, send a **new** key. A changed payload on an old key is always 409 `idempotency_conflict`.'],
                    ['id' => 'Klaim yang berhasil tidak pernah kedaluwarsa dan tidak pernah dipangkas, termasuk setelah rotasi atau penonaktifan key.',
                        'en' => 'A successful claim never expires and is never pruned, including after key rotation or disable.'],
                    ['id' => 'Jika respons hilang karena timeout, ulangi permintaan yang sama dengan key yang sama, atau baca `GET /api/v1/orders`.',
                        'en' => 'When a response is lost to a timeout, repeat the identical request with the same key, or read `GET /api/v1/orders`.'],
                ],
            ],
            [
                'id' => 'listOrders',
                'tag' => 'Orders',
                'method' => 'GET',
                'path' => self::API_PREFIX.'/orders',
                'title' => ['id' => 'Riwayat pesanan API', 'en' => 'API order history'],
                'summary' => [
                    'id' => 'Telusuri pesanan milik akun ini yang berasal dari API, dengan kursor stabil dan filter status.',
                    'en' => 'Traverse this account\'s API-origin orders with a stable cursor and a status filter.',
                ],
                'description' => [
                    'id' => 'Hanya pesanan dengan asal API yang muncul; pesanan ritel dari bot atau web tidak pernah masuk ke jalur ini. `status` memfilter status **Order** kanonik, bukan status pembayaran atau pengiriman. Ringkasan tidak pernah memuat kredensial, kunci, biaya, atau data upstream.',
                    'en' => 'Only API-origin orders appear; retail orders from the bot or web never enter this path. `status` filters the canonical **Order** status, not the payment or delivery state. Summaries never contain credentials, keys, costs or upstream data.',
                ],
                'parameters' => [
                    self::query('limit', 'string', 'Jumlah baris per halaman, 1 sampai 100. Default 20.',
                        'Rows per page, 1 to 100. Default 20.', ['pattern' => '^(?:[1-9]|[1-9][0-9]|100)$', 'default' => '20']),
                    self::query('cursor', 'string', 'Cursor opaque `pagination.next_cursor` dari halaman sebelumnya. Perlakukan sebagai nilai opaque dan pakai filter yang sama.',
                        'Opaque `pagination.next_cursor` from the previous page. Treat it as opaque and reuse the same filters.', ['format' => 'uuid']),
                    self::query('status', 'string', 'Opsional. Filter status Order kanonik.',
                        'Optional. Filter by the canonical Order status.', ['enum' => ['draft', 'pending_payment', 'processing', 'completed', 'cancelled', 'expired']]),
                ],
                'request' => null,
                'responses' => [
                    ['status' => 200, 'schema' => 'OrderPage', 'description' => ['id' => 'Ringkasan pesanan beserta paginasi.', 'en' => 'Order summaries with pagination.']],
                ],
                'errors' => ['unauthenticated', 'backend_only', 'invalid_input', 'rate_limited', 'temporarily_unavailable'],
                'notes' => [
                    ['id' => 'Paginasi bukan snapshot beku: pesanan baru bisa muncul di halaman berikutnya dan perubahan status bisa mengubah keanggotaan filter.',
                        'en' => 'Pagination is not a frozen snapshot: new orders may appear on later pages and status changes may move rows in or out of the filter.'],
                ],
            ],
            [
                'id' => 'getOrder',
                'tag' => 'Orders',
                'method' => 'GET',
                'path' => self::API_PREFIX.'/orders/{id}',
                'title' => ['id' => 'Status pesanan', 'en' => 'Order status'],
                'summary' => [
                    'id' => 'Baca status, progres fulfillment, dan status refund satu pesanan API milik akun ini.',
                    'en' => 'Read the status, fulfillment progress and refund state of one API order owned by this account.',
                ],
                'description' => [
                    'id' => '`payment_status` yang bernilai `paid` bukan bukti pengiriman: jumlah unit yang benar-benar dilepas dilaporkan terpisah di `fulfillment.released_units` dan `delivery.state`. Pesanan milik Customer lain, id yang tidak ada, id yang salah bentuk, dan pesanan ritel semuanya menghasilkan 404 yang sama.',
                    'en' => 'A `paid` `payment_status` is not proof of delivery: the units actually released are reported separately in `fulfillment.released_units` and `delivery.state`. Foreign orders, missing ids, malformed ids and retail orders all produce the same 404.',
                ],
                'parameters' => [
                    self::path('id', 'Id pesanan UUID. Perlakukan sebagai nilai opaque.', 'Order UUID. Treat it as an opaque value.'),
                ],
                'request' => null,
                'responses' => [
                    ['status' => 200, 'schema' => 'OrderEnvelope', 'description' => ['id' => 'Ringkasan pesanan milik akun ini.', 'en' => 'Order summary owned by this account.']],
                ],
                'errors' => ['unauthenticated', 'backend_only', 'not_found', 'invalid_input', 'rate_limited', 'temporarily_unavailable'],
                'notes' => [],
            ],
            [
                'id' => 'getOrderDelivery',
                'tag' => 'Delivery',
                'method' => 'GET',
                'path' => self::API_PREFIX.'/orders/{id}/delivery',
                'title' => ['id' => 'Ambil hasil pembelian', 'en' => 'Retrieve purchase results'],
                'summary' => [
                    'id' => 'Ambil kembali unit yang sudah dibeli persis seperti sebelumnya, tanpa alokasi ulang atau debit baru.',
                    'en' => 'Recover the units already purchased exactly as before, with no reallocation and no new debit.',
                ],
                'description' => [
                    'id' => 'Ini jalur pemulihan ketika respons checkout atau pekerjaan fulfillment hilang: unit yang sama dikembalikan pada pembacaan berulang. Hanya unit yang sudah diotorisasi oleh lifecycle kanonik yang dilepas. Pengiriman supplier yang belum selesai, penggantian manual, atau review melaporkan `not_ready` atau `partial` secara jujur, dan pembacaan tidak pernah membeli, mengalokasikan, mengirim ulang, atau memberi refund.',
                    'en' => 'This is the recovery path when a checkout response or fulfillment work is lost: the same units come back on repeated reads. Only units already authorized by the canonical lifecycle are released. Pending supplier delivery, manual replacement or review report `not_ready` or `partial` honestly, and a read never buys, allocates, resends or refunds.',
                ],
                'parameters' => [
                    self::path('id', 'Id pesanan UUID. Perlakukan sebagai nilai opaque.', 'Order UUID. Treat it as an opaque value.'),
                ],
                'request' => null,
                'responses' => [
                    ['status' => 200, 'schema' => 'DeliveryEnvelope', 'description' => ['id' => 'Unit yang sudah boleh dibaca beserta panduan pemakaiannya. `not_ready` dan `partial` juga 200.', 'en' => 'Released units with their usage guides. `not_ready` and `partial` are also 200.']],
                ],
                'errors' => ['unauthenticated', 'backend_only', 'not_found', 'invalid_input', 'rate_limited', 'temporarily_unavailable'],
                'notes' => [
                    ['id' => 'Endpoint ini memakai anggaran baca hasil pengiriman tersendiri (10 permintaan per menit per akun) selain anggaran baca umum.',
                        'en' => 'This endpoint consumes its own delivery-read budget (10 requests per minute per account) in addition to the general read budget.'],
                    ['id' => 'Semua respons berhasil maupun error memakai `Cache-Control: no-store, private`, `Referrer-Policy: no-referrer`, dan `X-Request-ID`.',
                        'en' => 'Every success and error response uses `Cache-Control: no-store, private`, `Referrer-Policy: no-referrer` and `X-Request-ID`.'],
                ],
            ],
        ];
    }

    /**
     * JSON Schema definitions for every response and request body.
     *
     * @return array<string, array<string, mixed>>
     */
    public function schemas(): array
    {
        return [
            'PaymentPreference' => [
                'type' => 'string',
                'enum' => ['idr_only', 'usd_only', 'idr_first', 'usd_first'],
                'description' => 'Saved account payment preference. `idr_first` is the default for an account that never changed it.',
            ],
            'Wallet' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['currency', 'available', 'frozen', 'amount'],
                'description' => 'One wallet of this account. A wallet whose projection cannot be trusted is unavailable with a null amount, never a fake zero.',
                'properties' => [
                    'currency' => ['type' => 'string', 'enum' => ['IDR', 'USD'], 'description' => 'Public denomination.'],
                    'available' => ['type' => 'boolean', 'description' => 'True when the account ledger snapshot is healthy and readable.'],
                    'frozen' => ['type' => 'boolean', 'description' => 'True only for a frozen USD account. The IDR ledger has no account-freeze flag, so it is always false there.'],
                    'amount' => ['type' => ['string', 'null'], 'description' => 'IDR amount as whole rupiah, USD amount with up to six decimals, or null when the wallet is unavailable.'],
                ],
            ],
            'Balance' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['payment_preference', 'wallets'],
                'description' => 'Own wallet snapshot and the saved payment preference. Availability describes snapshot health, not spending authorization.',
                'properties' => [
                    'payment_preference' => ['$ref' => '#/components/schemas/PaymentPreference'],
                    'wallets' => ['type' => 'array', 'description' => 'Always exactly the IDR wallet followed by the USD wallet.', 'items' => ['$ref' => '#/components/schemas/Wallet']],
                ],
            ],
            'BalanceEnvelope' => self::envelope('Balance'),
            'Category' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['id', 'name', 'slug', 'description'],
                'properties' => [
                    'id' => self::stringId('Category id.'),
                    'name' => ['type' => 'string', 'description' => 'Store-authored category name.'],
                    'slug' => ['type' => 'string', 'description' => 'System-managed category slug.'],
                    'description' => ['type' => ['string', 'null'], 'description' => 'Store-authored category description.'],
                ],
            ],
            'Family' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['id', 'name', 'slug', 'description', 'icon', 'custom_emoji_id'],
                'description' => 'Product Family presentation grouping. It owns no price, stock or fulfillment rule.',
                'properties' => [
                    'id' => self::stringId('Product Family id.'),
                    'name' => ['type' => 'string', 'description' => 'Family name.'],
                    'slug' => ['type' => 'string', 'description' => 'Family slug used by storefront links.'],
                    'description' => ['type' => ['string', 'null'], 'description' => 'Family description.'],
                    'icon' => ['type' => ['string', 'null'], 'description' => 'Family Unicode glyph, inherited by members without their own icon.'],
                    'custom_emoji_id' => ['type' => ['string', 'null'], 'description' => 'Family Telegram custom emoji identifier, when one was captured.'],
                ],
            ],
            'ProductStock' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['available', 'is_available', 'quantity_available'],
                'description' => 'Aggregate availability for the requested quantity. Informational only; it is not a reservation.',
                'properties' => [
                    'available' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Aggregate sellable units across local and supplier-backed capacity.'],
                    'is_available' => ['type' => 'boolean', 'description' => 'True when at least one unit is available.'],
                    'quantity_available' => ['type' => 'boolean', 'description' => 'True when the requested `quantity` is covered.'],
                ],
            ],
            'Quote' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['currency', 'eligible', 'regular_unit', 'effective_unit', 'regular_total', 'total'],
                'description' => 'Price quote for one currency at the requested quantity. `regular_*` is the base price before any tier or reseller override; `effective_unit` and `total` are what checkout charges.',
                'properties' => [
                    'currency' => ['type' => 'string', 'enum' => ['IDR', 'USD'], 'description' => 'Public denomination of this quote.'],
                    'eligible' => ['type' => 'boolean', 'description' => 'True when this Product has a base price in this currency.'],
                    'regular_unit' => ['type' => ['string', 'null'], 'description' => 'Base unit price, or null when the currency is ineligible.'],
                    'effective_unit' => ['type' => ['string', 'null'], 'description' => 'Unit price after quantity tier or reseller override precedence.'],
                    'regular_total' => ['type' => ['string', 'null'], 'description' => 'Base unit price multiplied by quantity.'],
                    'total' => ['type' => ['string', 'null'], 'description' => 'Charged total for this quantity. Copy it into `expected_totals` to authorize a purchase.'],
                ],
            ],
            'ProductMetadata' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'Store-authored informational fields. The API never derives structured entitlements, guarantees or buyer-input schemas from this copy.',
                'properties' => array_merge(...array_map(
                    static fn (string $field): array => [$field => ['type' => ['string', 'null'], 'description' => 'Store-authored text for `'.$field.'`.']],
                    ['product_type', 'access_duration', 'region', 'account_type', 'device_limit', 'included_items',
                        'limitations', 'fulfillment_expectation', 'warranty_policy', 'warranty_policy_ms', 'warranty_policy_en',
                        'support_policy', 'delivery_note', 'usage_guide_title', 'usage_guide_url'],
                )),
            ],
            'Product' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['id', 'name', 'plan_label', 'description', 'description_ms', 'description_en', 'icon', 'custom_emoji_id',
                    'category', 'family', 'stock', 'quantity', 'quotes', 'input_requirements', 'metadata', 'informational'],
                'description' => 'One publicly visible Product option. A Plan is this Product option, not a separate sellable entity.',
                'properties' => [
                    'id' => self::stringId('Sellable Product id.'),
                    'name' => ['type' => 'string', 'description' => 'Store-owned Product name.'],
                    'plan_label' => ['type' => ['string', 'null'], 'description' => 'Plan label when the Product is an option inside a Product Family.'],
                    'description' => ['type' => ['string', 'null'], 'description' => 'Base description.'],
                    'description_ms' => ['type' => ['string', 'null'], 'description' => 'Malay description, when authored.'],
                    'description_en' => ['type' => ['string', 'null'], 'description' => 'English description, when authored.'],
                    'icon' => ['type' => ['string', 'null'], 'description' => 'Product icon glyph, or the inherited family glyph.'],
                    'custom_emoji_id' => ['type' => ['string', 'null'], 'description' => 'Telegram custom emoji identifier when captured.'],
                    'category' => ['oneOf' => [['$ref' => '#/components/schemas/Category'], ['type' => 'null']]],
                    'family' => ['oneOf' => [['$ref' => '#/components/schemas/Family'], ['type' => 'null']]],
                    'stock' => ['$ref' => '#/components/schemas/ProductStock'],
                    'quantity' => ['type' => 'integer', 'minimum' => self::QUANTITY_MIN, 'maximum' => self::QUANTITY_MAX, 'description' => 'Quantity used for both quotes.'],
                    'quotes' => ['type' => 'array', 'description' => 'Exactly the IDR quote followed by the USD quote, including an ineligible USD quote with null values.', 'items' => ['$ref' => '#/components/schemas/Quote']],
                    'input_requirements' => ['type' => 'array', 'maxItems' => 0, 'items' => ['type' => 'object'], 'description' => 'Always empty: canonical automatic checkout accepts no buyer-supplied Product input fields.'],
                    'metadata' => ['$ref' => '#/components/schemas/ProductMetadata'],
                    'informational' => ['type' => 'boolean', 'const' => true, 'description' => 'Always true: nothing here reserves stock or freezes a price.'],
                ],
            ],
            'Pagination' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['next_cursor', 'has_more'],
                'description' => 'Keyset pagination state. There is no total count.',
                'properties' => [
                    'next_cursor' => ['type' => ['string', 'null'], 'description' => 'Pass this value back as `cursor` for the next page, or null when the traversal ended.'],
                    'has_more' => ['type' => 'boolean', 'description' => 'True when another page exists.'],
                ],
            ],
            'ProductPage' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['data', 'pagination'],
                'properties' => [
                    'data' => ['type' => 'array', 'description' => 'Visible Products selected by the query, ordered by ascending Product id.', 'items' => ['$ref' => '#/components/schemas/Product']],
                    'pagination' => ['$ref' => '#/components/schemas/Pagination'],
                ],
            ],
            'ProductEnvelope' => self::envelope('Product'),
            'ExpectedTotals' => [
                'type' => 'object',
                'additionalProperties' => false,
                'minProperties' => 1,
                'description' => 'Price-change consent only. The server always recomputes the price and never treats these values as payment authority. Both `2` and `2.000000` are the same USD amount.',
                'properties' => [
                    'IDR' => ['type' => 'string', 'pattern' => self::POSITIVE_IDR_PATTERN, 'description' => 'Expected whole-rupiah total for the authorized quantity.'],
                    'USD' => ['type' => 'string', 'pattern' => self::POSITIVE_USD_PATTERN, 'description' => 'Expected positive USD total with up to six decimals, exactly representable in micro-USDT.'],
                ],
            ],
            'CreateOrderRequest' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['product_id', 'quantity'],
                'description' => 'One Product and one positive quantity per checkout. A JSON number for `product_id` is rejected; send it as a canonical decimal string.',
                'properties' => [
                    'product_id' => self::stringId('Canonical positive decimal string of one visible sellable Product.'),
                    'quantity' => ['type' => 'integer', 'minimum' => self::QUANTITY_MIN, 'maximum' => self::QUANTITY_MAX, 'description' => 'Ordered units. Existing stricter Product and overflow checks still apply.'],
                    'preference' => array_merge(['description' => 'Optional per-order override. It wins over the saved account preference and is snapshotted on the durable claim.'], ['$ref' => '#/components/schemas/PaymentPreference']),
                    'expected_totals' => ['$ref' => '#/components/schemas/ExpectedTotals'],
                ],
            ],
            'OrderItem' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['product_id', 'name', 'quantity'],
                'properties' => [
                    'product_id' => self::stringId('Ordered Product id.'),
                    'name' => ['type' => 'string', 'description' => 'Product name snapshotted at checkout.'],
                    'quantity' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Ordered units of this item.'],
                ],
            ],
            'Fulfillment' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['state', 'ordered_units', 'allocated_units', 'released_units', 'processing', 'review_required'],
                'description' => 'Source-of-truth fulfillment progress. `complete` means every ordered unit is currently releasable, not merely that payment finished.',
                'properties' => [
                    'state' => ['type' => 'string', 'enum' => ['not_ready', 'processing', 'review', 'partial', 'complete', 'refunded'], 'description' => '`review` takes precedence over partial progress.'],
                    'ordered_units' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Total purchased quantity.'],
                    'allocated_units' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Durable stock-row allocations, including retired and replaced historical rows. Not a release entitlement.'],
                    'released_units' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Currently non-retired units the lifecycle authorizes for release; zero while release is blocked.'],
                    'processing' => ['type' => 'boolean', 'description' => 'True while a durable supplier fulfillment is still open.'],
                    'review_required' => ['type' => 'boolean', 'description' => 'True while a Manual Fulfillment Review is open for this Order.'],
                ],
            ],
            'OrderDelivery' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['available', 'state'],
                'description' => 'Availability of a delivery read. `available` means authorized rows exist, not that this summary decrypted them.',
                'properties' => [
                    'available' => ['type' => 'boolean', 'description' => 'True when at least one unit is currently releasable.'],
                    'state' => ['type' => 'string', 'enum' => ['not_ready', 'partial', 'ready'], 'description' => 'Delivery readiness derived from released units.'],
                ],
            ],
            'Refund' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['state', 'currency', 'confirmed_amount', 'pending_amount'],
                'description' => 'Refund state in the Order\'s original currency. A full refund reports the original Payment amount as confirmed, including earlier partial credits exactly once.',
                'properties' => [
                    'state' => ['type' => 'string', 'enum' => ['none', 'pending', 'partial', 'refunded'], 'description' => 'Aggregate refund state.'],
                    'currency' => ['type' => 'string', 'enum' => ['IDR', 'USD'], 'description' => 'Original public currency of the refund.'],
                    'confirmed_amount' => ['type' => ['string', 'null'], 'description' => 'Already confirmed refund amount, or null when nothing was paid back.'],
                    'pending_amount' => ['type' => 'string', 'description' => 'Amount still requested or uncertain. `0` when none.'],
                ],
            ],
            'OrderSummary' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['id', 'invoice_number', 'origin', 'status', 'payment_status', 'currency', 'amount', 'quantity', 'items', 'fulfillment', 'delivery', 'refund', 'created_at'],
                'description' => 'Safe order projection reconstructed from the durable Order, its items and its Payment. It never contains credentials, ledger rows, costs, supplier data or secrets.',
                'properties' => [
                    'id' => self::uuid('Order UUID.'),
                    'invoice_number' => ['type' => 'string', 'description' => 'Store invoice number.'],
                    'origin' => ['type' => 'string', 'const' => 'api', 'description' => 'Immutable Order origin. Only API-origin Orders appear on these routes.'],
                    'status' => ['type' => 'string', 'enum' => ['draft', 'pending_payment', 'processing', 'completed', 'cancelled', 'expired'], 'description' => 'Canonical Order status.'],
                    'payment_status' => ['type' => 'string', 'enum' => ['pending', 'paid', 'failed', 'expired', 'refunded'], 'description' => 'Canonical Payment status. `paid` is not proof of delivery.'],
                    'currency' => ['type' => 'string', 'enum' => ['IDR', 'USD'], 'description' => 'Public currency selected for this Order. Internal USDT is reported as USD.'],
                    'amount' => ['type' => ['string', 'null'], 'description' => 'Exact Payment amount in `currency`, or null when no Payment exists.'],
                    'quantity' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Total ordered units.'],
                    'items' => ['type' => 'array', 'description' => 'Order items snapshotted at checkout, ordered by ascending item id.', 'items' => ['$ref' => '#/components/schemas/OrderItem']],
                    'fulfillment' => ['$ref' => '#/components/schemas/Fulfillment'],
                    'delivery' => ['$ref' => '#/components/schemas/OrderDelivery'],
                    'refund' => ['$ref' => '#/components/schemas/Refund'],
                    'created_at' => ['type' => 'string', 'format' => 'date-time', 'description' => 'ISO 8601 creation timestamp with timezone.'],
                ],
            ],
            'OrderPage' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['data', 'pagination'],
                'properties' => [
                    'data' => ['type' => 'array', 'description' => 'API-origin Orders owned by this account, ordered by ascending Order id.', 'items' => ['$ref' => '#/components/schemas/OrderSummary']],
                    'pagination' => ['$ref' => '#/components/schemas/Pagination'],
                ],
            ],
            'OrderEnvelope' => self::envelope('OrderSummary'),
            'OrderCreate' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['id', 'invoice_number', 'origin', 'status', 'payment_status', 'currency', 'amount', 'quantity', 'product', 'created_at', 'request_id'],
                'description' => 'Accepted Order metadata. It is rebuilt from the canonical Order on every response, including replays, and never stores a credential-bearing body.',
                'properties' => [
                    'id' => self::uuid('Accepted Order UUID.'),
                    'invoice_number' => ['type' => 'string', 'description' => 'Store invoice number.'],
                    'origin' => ['type' => 'string', 'const' => 'api', 'description' => 'Immutable Order origin; always `api` on this surface.'],
                    'status' => ['type' => 'string', 'enum' => ['draft', 'pending_payment', 'processing', 'completed', 'cancelled', 'expired'], 'description' => 'Current Order status; it may differ between two responses to the same key.'],
                    'payment_status' => ['type' => 'string', 'enum' => ['pending', 'paid', 'failed', 'expired', 'refunded'], 'description' => 'Current Payment status.'],
                    'currency' => ['type' => 'string', 'enum' => ['IDR', 'USD'], 'description' => 'Original selected currency, preserved by every later retry, refund or cancellation.'],
                    'amount' => ['type' => ['string', 'null'], 'description' => 'Original Payment amount, or null when no Payment row exists.'],
                    'quantity' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Total ordered units.'],
                    'product' => [
                        'oneOf' => [
                            ['type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'name'], 'properties' => ['id' => self::stringId('Ordered Product id.'), 'name' => ['type' => 'string', 'description' => 'Product name snapshotted at checkout.']]],
                            ['type' => 'null'],
                        ],
                    ],
                    'created_at' => ['type' => 'string', 'format' => 'date-time', 'description' => 'ISO 8601 creation timestamp with timezone.'],
                    'request_id' => ['type' => 'string', 'format' => 'uuid', 'description' => 'HTTP request id of this response, matching the `X-Request-ID` header and `error.request_id`.'],
                ],
            ],
            'OrderCreateEnvelope' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['data', 'idempotent_replay'],
                'properties' => [
                    'data' => ['$ref' => '#/components/schemas/OrderCreate'],
                    'idempotent_replay' => ['type' => 'boolean', 'description' => 'False for a newly accepted order (201), true when this response replayed an already accepted claim (200).'],
                ],
            ],
            'UsageGuide' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['title', 'url'],
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'Usage guide title from the canonical Product usage guide.'],
                    'url' => ['type' => 'string', 'description' => 'Usage guide URL. The API never fetches it for you.'],
                ],
            ],
            'DeliveryUnit' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['id', 'product_id', 'number', 'format', 'text', 'email', 'password', 'access_method', 'access_value', 'reader_mail_link', 'tutorial'],
                'description' => 'One released unit. Unit identity and values stay stable until an authorized warranty or refund operation retires the unit.',
                'properties' => [
                    'id' => self::stringId('Allocated unit id.'),
                    'product_id' => self::stringId('Product id of the owning Order item.'),
                    'number' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Logical account number from the shared delivery presentation.'],
                    'format' => ['type' => 'string', 'enum' => ['legacy', 'structured_account'], 'description' => 'Credential shape of this unit.'],
                    'text' => ['type' => 'string', 'description' => 'Canonical formatted credential text, including supported legacy MFA fields.'],
                    'email' => ['type' => ['string', 'null'], 'description' => 'Email of a structured credential, when one exists.'],
                    'password' => ['type' => ['string', 'null'], 'description' => 'Password of a structured credential, when one exists.'],
                    'access_method' => ['type' => ['string', 'null'], 'enum' => ['none', 'otp_mail_link', 'auth_token', null], 'description' => 'Structured access method. Alternatives are never combined.'],
                    'access_value' => ['type' => ['string', 'null'], 'description' => 'Access value matching `access_method`.'],
                    'reader_mail_link' => ['type' => ['string', 'null'], 'description' => 'Supported mailbox reader link, when the unit provides one.'],
                    'tutorial' => ['type' => ['string', 'null'], 'description' => 'Item-specific tutorial, or the snapshotted delivery instruction.'],
                ],
            ],
            'DeliveryResult' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['id', 'state', 'progress', 'units', 'usage_guides'],
                'properties' => [
                    'id' => self::uuid('Order UUID.'),
                    'state' => ['type' => 'string', 'enum' => ['not_ready', 'partial', 'ready'], 'description' => '`not_ready` and `partial` are honest states, not errors.'],
                    'progress' => ['$ref' => '#/components/schemas/Fulfillment'],
                    'units' => ['type' => 'array', 'description' => 'Released units only. Retired units never appear.', 'items' => ['$ref' => '#/components/schemas/DeliveryUnit']],
                    'usage_guides' => ['type' => 'array', 'description' => 'Usage guides attached to the released units, from the canonical Product usage guide.', 'items' => ['$ref' => '#/components/schemas/UsageGuide']],
                ],
            ],
            'DeliveryEnvelope' => self::envelope('DeliveryResult'),
            'PriceChangeQuote' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['currency', 'total'],
                'properties' => [
                    'currency' => ['type' => 'string', 'enum' => ['IDR', 'USD'], 'description' => 'Currency of this fresh quote.'],
                    'total' => ['type' => 'string', 'description' => 'Fresh total for the requested quantity in this currency.'],
                ],
            ],
            'Error' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['code', 'message', 'request_id'],
                'description' => 'Stable machine code, human message and request id. Parse `code`, never the message.',
                'properties' => [
                    'code' => ['type' => 'string', 'description' => 'Stable machine code from the error table.'],
                    'message' => ['type' => 'string', 'description' => 'Human-readable message that may change without notice.'],
                    'request_id' => ['type' => 'string', 'format' => 'uuid', 'description' => 'Matches the `X-Request-ID` response header.'],
                    'quotes' => ['type' => 'array', 'description' => 'Only on 409 `price_changed`: fresh totals to authorize before retrying.', 'items' => ['$ref' => '#/components/schemas/PriceChangeQuote']],
                ],
            ],
            'ErrorEnvelope' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['error'],
                'properties' => ['error' => ['$ref' => '#/components/schemas/Error']],
            ],
        ];
    }

    /**
     * @return array{type: string, additionalProperties: bool, required: list<string>, properties: array<string, mixed>}
     */
    private static function envelope(string $schema): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['data'],
            'properties' => ['data' => ['$ref' => '#/components/schemas/'.$schema]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function uuid(string $description): array
    {
        return ['type' => 'string', 'format' => 'uuid', 'description' => $description];
    }

    /**
     * @return array<string, mixed>
     */
    private static function stringId(string $description): array
    {
        return ['type' => 'string', 'pattern' => '^[1-9][0-9]*$', 'description' => $description];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function query(string $name, string $type, string $id, string $en, array $schema = []): array
    {
        return ['name' => $name, 'in' => 'query', 'required' => false, 'type' => $type,
            'schema' => $schema === [] ? ['type' => 'string'] : array_merge(['type' => 'string'], $schema),
            'description' => ['id' => $id, 'en' => $en]];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function path(string $name, string $id, string $en, array $schema = ['type' => 'string']): array
    {
        return ['name' => $name, 'in' => 'path', 'required' => true, 'type' => 'string', 'schema' => $schema,
            'description' => ['id' => $id, 'en' => $en]];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function header(string $name, string $id, string $en, array $schema = []): array
    {
        return ['name' => $name, 'in' => 'header', 'required' => true, 'type' => 'string',
            'schema' => $schema === [] ? ['type' => 'string'] : array_merge(['type' => 'string'], $schema),
            'description' => ['id' => $id, 'en' => $en]];
    }
}
