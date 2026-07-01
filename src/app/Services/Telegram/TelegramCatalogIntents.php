<?php

namespace App\Services\Telegram;

use App\Models\Category;
use App\Models\Product;

class TelegramCatalogIntents
{
    public function __construct(private TelegramBotClient $bot) {}

    public function sendCategoryCatalog(string $chatId): void
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->whereHas('products', fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')->orderBy('name')->get();
        $hasUncategorized = Product::whereNull('category_id')->where('is_active', true)->exists();
        $keyboard = $categories->map(fn ($category) => [['text' => '📂 '.$category->name, 'callback_data' => 'category:'.$category->id]])->values()->all();
        if ($hasUncategorized) {
            $keyboard[] = [['text' => '📂 Lainnya', 'callback_data' => 'category:uncategorized']];
        }

        $this->bot->sendMessage($chatId, $keyboard === [] ? "🛍️ Katalog masih kosong nih\n━━━━━━━━━━━━━━\nProduk belum tampil karena admin belum menambahkan produk aktif.\n\nCek lagi nanti ya. Biasanya stok baru suka muncul tiba-tiba 👀" : "🛍️ Katalog Produk\n━━━━━━━━━━━━━━\nPilih kategori yang kamu mau.\n\n✨ Semua produk aktif akan muncul otomatis di sini.", ['inline_keyboard' => $keyboard]);
    }

    public function sendStockChoices(string $chatId, Product $product, int $availableStock): void
    {
        $keyboard = [];
        if ($product->isAvailable()) {
            $keyboard[] = [[
                'text' => '🧾 Beli Sekarang - Rp'.number_format($product->price_amount, 0, ',', '.'),
                'callback_data' => 'buy:'.$product->id,
            ]];
        }
        $keyboard[] = [[
            'text' => '⬅️ Balik ke Kategori',
            'callback_data' => $product->category_id ? 'category:'.$product->category_id : 'category:uncategorized',
        ]];

        $lines = [
            '🛍️ '.$product->name,
            '━━━━━━━━━━━━━━',
            '💰 Harga: Rp'.number_format($product->price_amount, 0, ',', '.'),
            $product->isAvailable() ? '✅ Status: Ready' : '❌ Status: Stok kosong',
        ];
        if ($product->isAvailable()) {
            $lines[] = '📦 Stok: '.$availableStock;
        }
        $lines[] = '';
        $lines[] = '📝 Detail produk';
        $lines[] = $this->descriptionText($product);
        $lines[] = '';
        $lines[] = $product->isAvailable()
            ? 'Kalau cocok, langsung gas checkout di bawah.'
            : 'Stoknya lagi habis nih. Cek produk lain dulu ya, atau tunggu restock berikutnya.';

        $this->bot->sendMessage($chatId, implode("\n", $lines), ['inline_keyboard' => $keyboard]);
    }

    public function sendQuantityChoices(string $chatId, Product $product, int $availableStock): void
    {
        $quantityLimit = min($availableStock, 5);
        $keyboard = [];
        for ($quantity = 1; $quantity <= $quantityLimit; $quantity++) {
            $keyboard[] = [['text' => (string) $quantity, 'callback_data' => 'qty:'.$product->id.':'.$quantity]];
        }
        $keyboard[] = [['text' => '⬅️ Detail Produk', 'callback_data' => 'product:'.$product->id]];

        $lines = [
            '🔢 Pilih jumlah',
            '━━━━━━━━━━━━━━',
            '📦 Produk: '.$product->name,
            '✅ Stok ready: '.$availableStock,
            '',
            'Mau ambil berapa item?',
        ];

        $this->bot->sendMessage($chatId, implode("\n", $lines), ['inline_keyboard' => $keyboard]);
    }

    public function sendCategoryProducts(string $chatId, string $categoryId): void
    {
        $query = Product::publicVisible()->orderBy('id');
        $category = null;
        if ($categoryId === 'uncategorized') {
            $query->whereNull('category_id');
            $title = 'Lainnya';
        } else {
            $category = Category::whereKey((int) $categoryId)->where('is_active', true)->first();
            if (! $category) {
                $this->bot->sendMessage($chatId, 'Kategori ini belum tersedia atau sudah dipindahkan.', ['inline_keyboard' => [[['text' => '⬅️ Balik ke Kategori', 'callback_data' => 'back:categories']]]]);

                return;
            }
            $query->where('category_id', $category->id);
            $title = $category->name;
        }

        $products = $query->limit(9)->get();
        $lines = ['📂 '.$title, '━━━━━━━━━━━━━━'];
        if ($category?->description) {
            $lines[] = $category->description;
        }
        $lines[] = '';
        foreach ($products as $i => $product) {
            $lines[] = ($i + 1).'. '.($product->isAvailable() ? '✅' : '❌').' '.$product->name;
            $lines[] = '   💰 Rp'.number_format($product->price_amount, 0, ',', '.');
            $lines[] = '   📝 '.$this->descriptionExcerpt($product);
        }
        $keyboard = $products->values()->map(fn ($product, $i) => [['text' => '👀 Intip #'.($i + 1), 'callback_data' => 'product:'.$product->id]])->all();
        $keyboard[] = [['text' => '⬅️ Balik ke Kategori', 'callback_data' => 'back:categories']];

        $this->bot->sendMessage($chatId, $products->isEmpty() ? "📂 Belum ada produk di kategori ini\n━━━━━━━━━━━━━━\nCoba cek kategori lain dulu ya. Siapa tahu stok incaran kamu ada di sana 👀" : implode("\n", $lines), ['inline_keyboard' => $keyboard]);
    }

    public function sendSearchResults(string $chatId, string $keyword): void
    {
        $limit = 5;
        $like = '%'.addcslashes(strtolower($keyword), '%_\\').'%';
        $products = Product::query()
            ->publicVisible()
            ->where(function ($query) use ($like) {
                $query->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(description) LIKE ?', [$like])
                    ->orWhereHas('category', fn ($category) => $category->where('is_active', true)->whereRaw('LOWER(name) LIKE ?', [$like]));
            })
            ->orderBy('name')
            ->limit($limit + 1)
            ->get();

        if ($products->isEmpty()) {
            $this->bot->sendMessage($chatId, "Produk dengan kata kunci \"{$keyword}\" belum ketemu.\n\nCoba kata kunci lain, atau buka katalog untuk lihat semua produk.", ['inline_keyboard' => [[['text' => '🛍️ Buka Katalog', 'callback_data' => 'katalog']]]]);

            return;
        }

        $hasMore = $products->count() > $limit;
        $products = $products->take($limit)->values();
        $lines = ['🔎 Hasil pencarian: '.$keyword, '━━━━━━━━━━━━━━', ''];
        foreach ($products as $i => $product) {
            $lines[] = ($i + 1).'. '.($product->isAvailable() ? '✅' : '❌').' '.$product->name;
            $lines[] = '   💰 Rp'.number_format($product->price_amount, 0, ',', '.');
            $lines[] = '   📝 '.$this->descriptionExcerpt($product);
        }
        if ($hasMore) {
            $lines[] = '';
            $lines[] = 'Masih ada hasil lain. Coba pakai kata kunci yang lebih spesifik biar lebih cepat ketemu.';
        }

        $keyboard = $products->map(fn ($product, $i) => [['text' => '👀 Intip #'.($i + 1), 'callback_data' => 'product:'.$product->id]])->all();
        $keyboard[] = [['text' => '🛍️ Buka Katalog', 'callback_data' => 'katalog']];

        $this->bot->sendMessage($chatId, implode("\n", $lines), ['inline_keyboard' => $keyboard]);
    }

    private function descriptionText(Product $product): string
    {
        $description = trim((string) $product->description);

        return $description !== '' ? $description : 'Deskripsi produk belum ditambahkan. Tapi tenang, produknya tetap bisa dicek dari info harga dan stok.';
    }

    private function descriptionExcerpt(Product $product): string
    {
        $description = preg_replace('/\s+/', ' ', $this->descriptionText($product)) ?? '';

        return strlen($description) > 90 ? substr($description, 0, 87).'...' : $description;
    }
}
