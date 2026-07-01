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

        $this->bot->sendMessage($chatId, $keyboard === [] ? "🛍️ Katalog masih kosong.\nProduk baru akan tampil di sini setelah admin menambah produk aktif." : "🛍️ Katalog Produk\n━━━━━━━━━━━━━━\nPilih kategori favorit kamu di bawah.\n\n✨ Produk aktif akan otomatis tampil.", ['inline_keyboard' => $keyboard]);
    }

    public function sendStockChoices(string $chatId, Product $product, int $availableStock): void
    {
        $keyboard = [];
        if ($product->isAvailable()) {
            $keyboard[] = [[
                'text' => '🧾 Beli (Rp'.number_format($product->price_amount, 0, ',', '.').')',
                'callback_data' => 'buy:'.$product->id,
            ]];
        }
        $keyboard[] = [[
            'text' => '⬅️ Kembali ke kategori',
            'callback_data' => $product->category_id ? 'category:'.$product->category_id : 'category:uncategorized',
        ]];

        $lines = [
            '🛍️ '.$product->name,
            '💰 Harga: Rp'.number_format($product->price_amount, 0, ',', '.'),
            $product->isAvailable() ? '✅ Tersedia' : '❌ Stok sedang kosong',
            '📦 Stok: '.$availableStock,
            '━━━━━━━━━━━━━━',
            '📝 Deskripsi',
            $this->descriptionText($product),
        ];

        $this->bot->sendMessage($chatId, implode("\n", $lines), ['inline_keyboard' => $keyboard]);
    }

    public function sendQuantityChoices(string $chatId, Product $product, int $availableStock): void
    {
        $quantityLimit = min($availableStock, 5);
        $keyboard = [];
        for ($quantity = 1; $quantity <= $quantityLimit; $quantity++) {
            $keyboard[] = [['text' => (string) $quantity, 'callback_data' => 'qty:'.$product->id.':'.$quantity]];
        }
        $keyboard[] = [['text' => '⬅️ Detail produk', 'callback_data' => 'product:'.$product->id]];

        $lines = [
            '🔢 Pilih jumlah',
            '━━━━━━━━━━━━━━',
            '📦 '.$product->name,
            '📦 Stok tersedia: '.$availableStock,
            '',
            'Mau beli berapa item?',
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
                $this->bot->sendMessage($chatId, 'Kategori tidak tersedia.', ['inline_keyboard' => [[['text' => '⬅️ Kembali ke kategori', 'callback_data' => 'back:categories']]]]);

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
        $keyboard = $products->values()->map(fn ($product, $i) => [['text' => '👁️ Lihat #'.($i + 1), 'callback_data' => 'product:'.$product->id]])->all();
        $keyboard[] = [['text' => '⬅️ Kembali ke kategori', 'callback_data' => 'back:categories']];

        $this->bot->sendMessage($chatId, $products->isEmpty() ? "📂 Produk kategori ini masih kosong.\nCoba kategori lain atau kembali ke katalog." : implode("\n", $lines), ['inline_keyboard' => $keyboard]);
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
            $this->bot->sendMessage($chatId, "Produk dengan kata kunci \"{$keyword}\" tidak ditemukan.\nCoba kata kunci lain atau buka /katalog.", ['inline_keyboard' => [[['text' => 'Lihat Katalog', 'callback_data' => 'katalog']]]]);

            return;
        }

        $hasMore = $products->count() > $limit;
        $products = $products->take($limit)->values();
        $lines = ['🔎 Hasil pencarian: '.$keyword, ''];
        foreach ($products as $i => $product) {
            $lines[] = ($i + 1).'. '.($product->isAvailable() ? '✅' : '❌').' '.$product->name;
            $lines[] = '   Rp'.number_format($product->price_amount, 0, ',', '.');
            $lines[] = '   '.$this->descriptionExcerpt($product);
        }
        if ($hasMore) {
            $lines[] = '';
            $lines[] = 'Hasil lebih banyak tersedia. Persempit kata kunci agar lebih spesifik.';
        }

        $keyboard = $products->map(fn ($product, $i) => [['text' => '👁️ Lihat #'.($i + 1), 'callback_data' => 'product:'.$product->id]])->all();
        $keyboard[] = [['text' => 'Lihat Katalog', 'callback_data' => 'katalog']];

        $this->bot->sendMessage($chatId, implode("\n", $lines), ['inline_keyboard' => $keyboard]);
    }

    private function descriptionText(Product $product): string
    {
        $description = trim((string) $product->description);

        return $description !== '' ? $description : 'Deskripsi produk segera dilengkapi.';
    }

    private function descriptionExcerpt(Product $product): string
    {
        $description = preg_replace('/\s+/', ' ', $this->descriptionText($product)) ?? '';

        return strlen($description) > 90 ? substr($description, 0, 87).'...' : $description;
    }
}
