<?php

namespace App\Services\Telegram;

use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidCartState;
use App\Exceptions\InvalidCheckout;
use App\Models\Category;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\TelegramUpdate;
use App\Services\CartService;
use App\Services\OrderCheckoutService;
use App\Services\Pakasir\PakasirClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

class TelegramUpdateProcessor
{
    public function __construct(private TelegramBotClient $bot, private CartService $carts, private OrderCheckoutService $checkout, private PakasirClient $pakasir) {}

    public function process(TelegramUpdate $update): void
    {
        if ($update->processed_at) {
            return;
        }

        $lock = Cache::lock('telegram-update:'.$update->id, 60);
        if (! $lock->get()) {
            return;
        }

        try {
            $update->refresh();
            if ($update->processed_at) {
                return;
            }

            $payload = $update->payload;
            if ($message = Arr::get($payload, 'message')) {
                $customer = $this->customer(Arr::get($message, 'from', []));
                $update->update(['customer_id' => $customer->id]);
                $this->message($customer, (string) Arr::get($message, 'chat.id'), trim((string) Arr::get($message, 'text', '')));
            } elseif ($callback = Arr::get($payload, 'callback_query')) {
                $customer = $this->customer(Arr::get($callback, 'from', []));
                $update->update(['customer_id' => $customer->id]);
                $this->callback($customer, (string) Arr::get($callback, 'message.chat.id'), (string) Arr::get($callback, 'id'), (string) Arr::get($callback, 'data'));
            }

            $update->update(['processed_at' => now()]);
        } finally {
            $lock->release();
        }
    }

    private function message(Customer $customer, string $chatId, string $text): void
    {
        if ($text === '/start') {
            Cache::forget($this->intentKey($customer));
            $this->sendCategoryCatalog($chatId);

            return;
        }
        if ($text === '/katalog') {
            Cache::forget($this->intentKey($customer));
            $this->sendCategoryCatalog($chatId);

            return;
        }
        if ($text === '/cari' || str_starts_with($text, '/cari ')) {
            Cache::forget($this->intentKey($customer));
            $keyword = trim(substr($text, strlen('/cari')));
            if ($keyword === '') {
                $this->bot->sendMessage($chatId, "Ketik /cari diikuti kata kunci produk.\nContoh: /cari netflix");

                return;
            }

            $this->sendSearchResults($chatId, $keyword);

            return;
        }
        if ($text === '/batal') {
            Cache::forget($this->intentKey($customer));
            $customer->carts()->where('status', CartStatus::Active)->update(['status' => CartStatus::Abandoned]);
            $this->bot->sendMessage($chatId, 'Keranjang aktif dibatalkan.');

            return;
        }
        if (in_array($text, ['/status', '/pesanan'], true)) {
            Cache::forget($this->intentKey($customer));
            $order = $customer->orders()->latest()->first();
            $this->bot->sendMessage($chatId, $order ? 'Status pesanan '.$order->invoice_number.': '.$order->order_status->value.' / '.$order->payment_status->value."\nLihat riwayat: /riwayat atau detail: /order {$order->invoice_number}" : 'Belum ada pesanan. Buka /katalog untuk mulai belanja.');

            return;
        }
        if ($text === '/riwayat') {
            Cache::forget($this->intentKey($customer));
            $this->sendOrderHistory($customer, $chatId);

            return;
        }
        if ($text === '/order' || str_starts_with($text, '/order ')) {
            Cache::forget($this->intentKey($customer));
            $invoice = trim(substr($text, strlen('/order')));
            if ($invoice === '') {
                $this->bot->sendMessage($chatId, "Ketik /order diikuti nomor invoice.\nContoh: /order INV-12345678");

                return;
            }
            $this->sendOrderDetail($customer, $chatId, $invoice);

            return;
        }
        if ($productId = Cache::get($this->intentKey($customer))) {
            if (ctype_digit($text) && $this->addProductToCart($customer, $chatId, (int) $productId, (int) $text)) {
                Cache::forget($this->intentKey($customer));

                return;
            }

            if (! ctype_digit($text)) {
                $this->bot->sendMessage($chatId, 'Jumlah tidak valid. Ketik angka sesuai stok yang tersedia.');
            }

            return;
        }
        Cache::forget($this->intentKey($customer));
        $this->bot->sendMessage($chatId, 'Perintah tidak dikenal. Ketik /katalog.');
    }

    private function callback(Customer $customer, string $chatId, string $id, string $data): void
    {
        if ($data === 'katalog' || $data === 'back:categories') {
            $this->bot->answerCallbackQuery($id);
            Cache::forget($this->intentKey($customer));
            $this->sendCategoryCatalog($chatId);

            return;
        }
        if (preg_match('/^category:([1-9][0-9]*|uncategorized)$/', $data, $matches)) {
            $this->bot->answerCallbackQuery($id);
            Cache::forget($this->intentKey($customer));
            $this->sendCategoryProducts($chatId, $matches[1]);

            return;
        }
        if ($data === 'balance') {
            $this->bot->answerCallbackQuery($id, 'Fitur saldo segera hadir.');

            return;
        }
        if ($data === 'status') {
            $this->bot->answerCallbackQuery($id);
            $order = $customer->orders()->latest()->first();
            $this->bot->sendMessage($chatId, $order ? '📦 Status pesanan '.$order->invoice_number.': '.$order->order_status->value.' / '.$order->payment_status->value : 'Belum ada pesanan.');

            return;
        }
        if (preg_match('/^order:([^\s:]+)$/', $data, $matches)) {
            $this->bot->answerCallbackQuery($id);
            $this->sendOrderDetail($customer, $chatId, $matches[1]);

            return;
        }
        if ($data === 'batal') {
            $this->bot->answerCallbackQuery($id);
            Cache::forget($this->intentKey($customer));
            $customer->carts()->where('status', CartStatus::Active)->update(['status' => CartStatus::Abandoned]);
            $this->bot->sendMessage($chatId, 'Keranjang aktif dibatalkan.');

            return;
        }
        if (preg_match('/^product:([1-9][0-9]*)$/', $data, $matches)) {
            $productId = (int) $matches[1];
            $product = Product::whereKey($productId)->where('is_active', true)->first();
            $availableStock = $product ? $product->stock - $product->reserved_stock : 0;
            if (! $product || $availableStock < 1) {
                $this->bot->answerCallbackQuery($id, 'Produk tidak tersedia.');

                return;
            }
            $this->bot->answerCallbackQuery($id);
            Cache::put($this->intentKey($customer), $product->id, now()->addMinutes(15));
            $this->sendStockChoices($chatId, $product, $availableStock);

            return;
        }
        if (preg_match('/^qty:([1-9][0-9]*):([1-9][0-9]*)$/', $data, $matches)) {
            $this->bot->answerCallbackQuery($id);
            Cache::forget($this->intentKey($customer));
            $this->addProductToCart($customer, $chatId, (int) $matches[1], (int) $matches[2]);

            return;
        }
        if ($data === 'checkout') {
            $this->bot->answerCallbackQuery($id);
            try {
                $paymentMode = (string) config('services.telegram.payment_mode', 'manual_dana');
                $order = $paymentMode === 'pakasir'
                    ? $this->checkout->checkout($this->carts->activeCartFor($customer), 'hosted', 'pakasir')
                    : $this->checkout->checkout($this->carts->activeCartFor($customer), 'DANA_QR', 'manual');
            } catch (InvalidCheckout|InvalidCartState|InsufficientStock) {
                $this->bot->sendMessage($chatId, 'Checkout gagal. Pastikan keranjang tidak kosong.');

                return;
            }
            $payment = $order->payments()->latest()->first();
            if ($payment?->provider === 'pakasir') {
                $this->sendPakasirPaymentInstructions($chatId, $order, $payment);
            } else {
                $this->sendPaymentInstructions($chatId, $order, $payment);
                $this->notifyAdminAboutManualOrder($order);
            }

            return;
        }

        $this->bot->answerCallbackQuery($id, 'Aksi tidak dikenal.');
    }

    private function sendCategoryCatalog(string $chatId): void
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->whereHas('products', fn ($q) => $q->where('is_active', true)->whereColumn('stock', '>', 'reserved_stock'))
            ->orderBy('sort_order')->orderBy('name')->get();
        $hasUncategorized = Product::whereNull('category_id')->where('is_active', true)->whereColumn('stock', '>', 'reserved_stock')->exists();
        $keyboard = $categories->map(fn ($category) => [['text' => '📂 '.$category->name, 'callback_data' => 'category:'.$category->id]])->values()->all();
        if ($hasUncategorized) {
            $keyboard[] = [['text' => '📂 Lainnya', 'callback_data' => 'category:uncategorized']];
        }

        $this->bot->sendMessage($chatId, $keyboard === [] ? 'Katalog kosong.' : "🛍️ Katalog Produk\n[BANNER PLACEHOLDER - isi URL/gambar di admin kategori]\n\nPilih kategori:", ['inline_keyboard' => $keyboard]);
    }

    private function sendStockChoices(string $chatId, Product $product, int $availableStock): void
    {
        $quantityLimit = min($availableStock, 5);
        $keyboard = [];
        for ($quantity = 1; $quantity <= $quantityLimit; $quantity++) {
            $keyboard[] = [['text' => (string) $quantity, 'callback_data' => 'qty:'.$product->id.':'.$quantity]];
        }
        $keyboard[] = [['text' => '⬅️ Kembali ke kategori', 'callback_data' => $product->category_id ? 'category:'.$product->category_id : 'category:uncategorized']];

        $lines = [
            '📦 '.$product->name,
            'Harga: Rp '.number_format($product->price_amount, 0, ',', '.'),
            'Stok tersedia: '.$availableStock,
            '',
            'Pilih jumlah:',
        ];

        $this->bot->sendMessage($chatId, implode("\n", $lines), ['inline_keyboard' => $keyboard]);
    }

    private function addProductToCart(Customer $customer, string $chatId, int $productId, int $quantity): bool
    {
        if ($quantity < 1) {
            $this->bot->sendMessage($chatId, 'Jumlah tidak valid.');

            return false;
        }

        try {
            $product = Product::findOrFail($productId);
            $cart = $this->carts->activeCartFor($customer);
            $this->carts->addItem($cart, $product, $quantity);
            $this->bot->sendMessage($chatId, "Keranjang: {$product->name} x {$quantity}", ['inline_keyboard' => [[['text' => 'Pembayaran', 'callback_data' => 'checkout']]]]);

            return true;
        } catch (Throwable) {
            $this->bot->sendMessage($chatId, 'Produk tidak tersedia atau stok tidak cukup.');

            return false;
        }
    }

    private function sendCategoryProducts(string $chatId, string $categoryId): void
    {
        $query = Product::where('is_active', true)->whereColumn('stock', '>', 'reserved_stock')->orderBy('id');
        $category = null;
        if ($categoryId === 'uncategorized') {
            $query->whereNull('category_id');
            $title = 'Lainnya';
        } else {
            $category = Category::whereKey((int) $categoryId)->where('is_active', true)->first();
            if (! $category) {
                $this->bot->sendMessage($chatId, 'Kategori tidak tersedia.', ['inline_keyboard' => [[['text' => '⬅️ Kembali ke kategori', 'callback_data' => 'back:categories']]]]);

                return;
            }
            $query->where('category_id', $category->id);
            $title = $category->name;
        }

        $products = $query->limit(9)->get();
        $lines = ['📂 '.$title];
        if ($category?->description) {
            $lines[] = $category->description;
        }
        $lines[] = $category?->banner_image_path ? '[BANNER: '.$category->banner_image_path.']' : '[BANNER PLACEHOLDER - isi URL/gambar di admin kategori]';
        $lines[] = '';
        foreach ($products as $i => $product) {
            $lines[] = ($i + 1).'. '.$product->name.' - Rp '.number_format($product->price_amount, 0, ',', '.').' (stok '.($product->stock - $product->reserved_stock).')';
        }
        $keyboard = $products->values()->map(fn ($product, $i) => [['text' => (string) ($i + 1), 'callback_data' => 'product:'.$product->id]])->all();
        $keyboard[] = [['text' => '⬅️ Kembali ke kategori', 'callback_data' => 'back:categories']];

        $this->bot->sendMessage($chatId, $products->isEmpty() ? 'Produk kategori ini kosong.' : implode("\n", $lines), ['inline_keyboard' => $keyboard]);
    }

    private function sendSearchResults(string $chatId, string $keyword): void
    {
        $limit = 5;
        $like = '%'.addcslashes(strtolower($keyword), '%_\\').'%';
        $products = Product::query()
            ->where('is_active', true)
            ->whereColumn('stock', '>', 'reserved_stock')
            ->where(function ($query) {
                $query->whereNull('category_id')
                    ->orWhereHas('category', fn ($category) => $category->where('is_active', true));
            })
            ->where(function ($query) use ($like) {
                $query->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(description) LIKE ?', [$like])
                    ->orWhereHas('category', fn ($category) => $category->where('is_active', true)->whereRaw('LOWER(name) LIKE ?', [$like]));
            })
            ->orderBy('name')
            ->limit($limit + 1)
            ->get();

        if ($products->isEmpty()) {
            $this->bot->sendMessage($chatId, "Produk dengan kata kunci \"{$keyword}\" tidak ditemukan.\nCoba kata kunci lain atau buka /katalog.", ['inline_keyboard' => [[['text' => 'Lihat Katalog', 'callback_data' => 'katalog']]]]);

            return;
        }

        $hasMore = $products->count() > $limit;
        $products = $products->take($limit)->values();
        $lines = ['🔎 Hasil pencarian: '.$keyword, ''];
        foreach ($products as $i => $product) {
            $lines[] = ($i + 1).'. '.$product->name.' - Rp '.number_format($product->price_amount, 0, ',', '.').' (stok '.($product->stock - $product->reserved_stock).')';
        }
        if ($hasMore) {
            $lines[] = '';
            $lines[] = 'Hasil lebih banyak tersedia. Persempit kata kunci agar lebih spesifik.';
        }

        $keyboard = $products->map(fn ($product, $i) => [['text' => (string) ($i + 1), 'callback_data' => 'product:'.$product->id]])->all();
        $keyboard[] = [['text' => 'Lihat Katalog', 'callback_data' => 'katalog']];

        $this->bot->sendMessage($chatId, implode("\n", $lines), ['inline_keyboard' => $keyboard]);
    }

    private function sendOrderHistory(Customer $customer, string $chatId): void
    {
        $orders = $customer->orders()->latest()->limit(5)->get();
        if ($orders->isEmpty()) {
            $this->bot->sendMessage($chatId, 'Belum ada riwayat pesanan. Yuk mulai belanja lewat /katalog.', ['inline_keyboard' => [[['text' => 'Lihat Katalog', 'callback_data' => 'katalog']]]]);

            return;
        }

        $lines = ['📦 Riwayat Pesanan Terakhir', 'Tekan Detail untuk melihat isi pesanan.'];
        $keyboard = [];
        foreach ($orders as $i => $order) {
            $lines[] = ($i + 1).'. '.$order->invoice_number.' · '.$order->order_status->value.' / '.$order->payment_status->value.' · Rp '.number_format($order->total_amount, 0, ',', '.').' · '.$this->formatShortDate($order->created_at);
            $keyboard[] = [['text' => 'Detail '.$order->invoice_number, 'callback_data' => 'order:'.$order->invoice_number]];
        }

        $this->bot->sendMessage($chatId, implode("\n", $lines), ['inline_keyboard' => $keyboard]);
    }

    private function sendOrderDetail(Customer $customer, string $chatId, string $invoice): void
    {
        $order = $customer->orders()->where('invoice_number', $invoice)->with(['orderItems', 'payments' => fn ($query) => $query->latest()])->first();
        if (! $order) {
            $this->bot->sendMessage($chatId, 'Pesanan tidak ditemukan. Pastikan nomor invoice benar atau cek /riwayat.');

            return;
        }

        $payment = $order->payments->first();
        $paymentStatus = $payment?->status ?? $order->payment_status;
        $lines = [
            '🧾 Detail Pesanan',
            'Invoice: '.$order->invoice_number,
            'Status: '.$order->order_status->value.' / '.$paymentStatus->value,
            'Dibuat: '.$this->formatDate($order->created_at),
            '',
            'Item:',
        ];
        foreach ($order->orderItems as $item) {
            $lines[] = '- '.$item->product_name.' x '.$item->quantity;
        }
        $lines[] = 'Total: Rp '.number_format($order->total_amount, 0, ',', '.');
        if ($payment) {
            $lines[] = 'Pembayaran: '.$payment->method.' ('.$payment->status->value.')';
            if ($payment->paid_at) {
                $lines[] = 'Dibayar: '.$this->formatDate($payment->paid_at);
            }
        }
        if (($order->order_status === OrderStatus::PendingPayment || $paymentStatus === PaymentStatus::Pending) && ($payment?->expired_at || $order->expires_at)) {
            $lines[] = 'Batas pembayaran: '.$this->formatDate($payment?->expired_at ?? $order->expires_at);
        }
        if (in_array($paymentStatus, [PaymentStatus::Expired, PaymentStatus::Failed], true) && ($payment?->expired_at || $order->expires_at)) {
            $lines[] = 'Berakhir: '.$this->formatDate($payment?->expired_at ?? $order->expires_at);
        }
        $lines[] = '';
        $lines[] = $this->orderInstruction($order, $payment);

        $this->bot->sendMessage($chatId, implode("\n", $lines));
    }

    private function orderInstruction(Order $order, ?Payment $payment): string
    {
        $orderStatus = $order->order_status;
        $paymentStatus = $payment?->status ?? $order->payment_status;
        if ($orderStatus === OrderStatus::PendingPayment || $paymentStatus === PaymentStatus::Pending) {
            return 'Status masih pending. Selesaikan pembayaran atau cek status berkala.';
        }
        if (in_array($orderStatus, [OrderStatus::Processing, OrderStatus::Completed], true) || $paymentStatus === PaymentStatus::Paid) {
            return 'Pembayaran diterima. Pesanan sedang/selesai diproses. Bukti transaksi akan dikirim jika tersedia.';
        }
        if (in_array($orderStatus, [OrderStatus::Expired, OrderStatus::Cancelled], true) || in_array($paymentStatus, [PaymentStatus::Expired, PaymentStatus::Failed], true)) {
            return 'Pesanan tidak aktif. Silakan checkout ulang lewat /katalog.';
        }

        return 'Jika membutuhkan bantuan, silakan hubungi admin toko.';
    }

    private function formatDate($date): string
    {
        return $date->timezone(config('app.timezone'))->format('d M Y H:i').' WIB';
    }

    private function formatShortDate($date): string
    {
        return $date->timezone(config('app.timezone'))->format('d M Y');
    }

    private function sendPaymentInstructions(string $chatId, Order $order, ?Payment $payment): void
    {
        $settings = ManualPaymentSetting::current();
        $danaQrImageUrl = $settings?->dana_qr_image_url ?: config('services.telegram.dana_qr_image_url');
        $danaQrImagePath = $settings?->dana_qr_image_path;
        $danaAccountName = $settings?->dana_account_name ?: config('services.telegram.dana_account_name');
        $danaAccountNumber = $settings?->dana_account_number ?: config('services.telegram.dana_account_number');
        $adminContactUrl = $settings?->admin_contact_url ?: config('services.telegram.admin_contact_url');
        $adminUsername = $settings?->admin_username ?: config('services.telegram.admin_username');
        $adminTelegramChatId = $settings?->admin_telegram_chat_id;
        $instructions = $settings?->instructions;

        if (! $danaQrImageUrl && $danaQrImagePath) {
            /** @var \Illuminate\Filesystem\FilesystemAdapter $publicDisk */
            $publicDisk = Storage::disk('public');
            $danaQrImageUrl = $publicDisk->url($danaQrImagePath);
        }

        $lines = [
            '✅ Checkout berhasil dibuat.',
            '',
            'Invoice: '.$order->invoice_number,
            'Total: Rp '.number_format($order->total_amount, 0, ',', '.'),
        ];

        if ($payment?->merchant_ref) {
            $lines[] = 'Kode Referensi: '.$payment->merchant_ref;
        }

        if ($order->expires_at) {
            $lines[] = 'Batas pembayaran: '.$this->formatDate($order->expires_at);
        }

        $lines[] = '';
        $lines[] = $instructions ?: 'Silakan bayar manual via DANA dengan nominal sesuai total di atas.';

        if ($danaAccountName || $danaAccountNumber) {
            $lines[] = '';
            $lines[] = 'Tujuan DANA:';
            if ($danaAccountName) {
                $lines[] = 'Nama: '.$danaAccountName;
            }
            if ($danaAccountNumber) {
                $lines[] = 'Nomor: '.$danaAccountNumber;
            }
        }

        if ($danaQrImageUrl) {
            $lines[] = '';
            $lines[] = 'QR DANA: '.$danaQrImageUrl;
        }

        $lines[] = '';
        $lines[] = 'Detail pembelian untuk admin:';
        foreach ($order->orderItems as $item) {
            $lines[] = '- '.$item->product_name.' x '.$item->quantity.' = Rp '.number_format($item->subtotal, 0, ',', '.');
        }

        $lines[] = '';
        $lines[] = 'Setelah transfer, kirim bukti pembayaran ke admin untuk diproses.';
        $lines[] = '';
        $lines[] = $this->adminPurchaseMessage($order);

        if ($adminContactUrl || $adminUsername || $adminTelegramChatId) {
            if ($adminContactUrl) {
                $lines[] = 'Kontak admin: '.$adminContactUrl;
            }
            if ($adminUsername) {
                $lines[] = 'Username admin: '.$adminUsername;
            }
            if ($adminTelegramChatId) {
                $lines[] = 'Chat ID admin: '.$adminTelegramChatId;
            }
        } else {
            $lines[] = 'Jika membutuhkan bantuan, silakan hubungi admin toko.';
        }

        $replyMarkup = [];
        if ($adminContactUrl) {
            $replyMarkup = ['inline_keyboard' => [[['text' => 'Chat Admin', 'url' => $adminContactUrl]]]];
        } elseif ($adminUsername) {
            $replyMarkup = ['inline_keyboard' => [[['text' => 'Chat Admin', 'url' => 'https://t.me/'.ltrim($adminUsername, '@')]]]];
        }

        $this->bot->sendMessage($chatId, implode("\n", $lines), $replyMarkup);
    }

    private function sendPakasirPaymentInstructions(string $chatId, Order $order, Payment $payment): void
    {
        $lines = [
            '✅ Checkout berhasil dibuat.',
            '',
            'Invoice: '.$order->invoice_number,
            'Total: Rp '.number_format($order->total_amount, 0, ',', '.'),
            'Kode Referensi: '.$payment->merchant_ref,
        ];

        if ($order->expires_at) {
            $lines[] = 'Batas pembayaran: '.$this->formatDate($order->expires_at);
        }

        $lines[] = '';
        $lines[] = 'Link pembayaran Pakasir:';
        $lines[] = $this->pakasir->hostedPaymentUrl($payment);
        $lines[] = '';
        $lines[] = 'Klik link di atas, lalu pilih metode pembayaran di Pakasir.';

        $this->bot->sendMessage($chatId, implode("\n", $lines));
    }

    private function notifyAdminAboutManualOrder(Order $order): void
    {
        $settings = ManualPaymentSetting::current();
        $adminTelegramChatId = $settings?->admin_telegram_chat_id ?: config('services.telegram.admin_telegram_chat_id');
        if (! $adminTelegramChatId) {
            return;
        }

        $order->loadMissing(['customer', 'orderItems']);
        $lines = [
            '🧾 Order manual baru masuk dashboard.',
            'Invoice: '.$order->invoice_number,
            'Customer: @'.($order->customer?->username ?: '-').' ('.$order->customer?->telegram_id.')',
            'Total: Rp '.number_format($order->total_amount, 0, ',', '.'),
            '',
            'Item:',
        ];
        foreach ($order->orderItems as $item) {
            $lines[] = '- '.$item->product_name.' x '.$item->quantity;
        }
        $lines[] = '';
        $lines[] = 'Buka dashboard admin, lalu klik Transaksi selesai setelah pembayaran valid.';

        $this->bot->sendMessage($adminTelegramChatId, implode("\n", $lines));
    }

    private function adminPurchaseMessage(Order $order): string
    {
        $order->loadMissing('orderItems');
        $lines = [
            'Halo admin, saya ingin konfirmasi pembelian:',
            'Invoice: '.$order->invoice_number,
        ];
        foreach ($order->orderItems as $item) {
            $lines[] = '- '.$item->product_name.' x '.$item->quantity;
        }
        $lines[] = 'Total: Rp '.number_format($order->total_amount, 0, ',', '.');

        return implode("\n", $lines);
    }

    private function customer(array $from): Customer
    {
        return Customer::updateOrCreate(['telegram_id' => (int) $from['id']], ['first_name' => $from['first_name'] ?? 'Telegram Customer', 'username' => $from['username'] ?? null]);
    }

    private function intentKey(Customer $customer): string
    {
        return 'telegram:selected-product:'.$customer->telegram_id;
    }
}
