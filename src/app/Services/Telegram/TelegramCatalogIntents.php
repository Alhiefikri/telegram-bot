<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\Customer;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Str;

class TelegramCatalogIntents
{
    public function __construct(private TelegramBotClient $bot, private ProductBrandIconResolver $icons, private TelegramLocale $locale) {}

    public function sendCategoryCatalog(Customer $customer, string $chatId): void
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->whereHas('products', fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')->orderBy('name')->get();
        $hasUncategorized = Product::whereNull('category_id')->where('is_active', true)->exists();
        $keyboard = $categories->map(fn ($category) => [['text' => '📂 '.$category->name, 'callback_data' => 'category:'.$category->id]])->values()->all();
        if ($hasUncategorized) {
            $keyboard[] = [['text' => $this->locale->text($customer, '📂 Lainnya', '📂 Other'), 'callback_data' => 'category:uncategorized']];
        }

        $this->bot->sendMessage($chatId, $keyboard === [] ? $this->locale->text($customer, "🛍️ Produk masih kosong nih\n━━━━━━━━━━━━━━\nProduk belum tampil karena admin belum menambahkan produk aktif.\n\nCek lagi nanti ya.", "🛍️ No products yet\n━━━━━━━━━━━━━━\nThere are no active products to show yet.\n\nPlease check again later.") : $this->locale->text($customer, "🛍️ Lihat Produk\n━━━━━━━━━━━━━━\nPilih kategori yang kamu mau.\n\n✨ Produk ready dan kosong tetap kelihatan jelas di sini.", "🛍️ Browse Products\n━━━━━━━━━━━━━━\nChoose a category.\n\n✨ Available and sold-out products are clearly shown here."), ['inline_keyboard' => $keyboard]);
    }

    public function sendStockChoices(Customer $customer, string $chatId, Product $product, int $availableStock): void
    {
        $soldQuantity = $this->soldQuantity($product);
        $keyboard = [];
        if ($availableStock > 0) {
            $keyboard[] = [[
                'text' => $this->locale->text($customer, '🧾 Beli Sekarang - Rp', '🧾 Buy Now - Rp').number_format($product->price_amount, 0, ',', '.'),
                'callback_data' => 'buy:'.$product->id,
            ]];
        }
        $keyboard[] = [[
            'text' => $this->locale->text($customer, '⬅️ Balik ke Kategori', '⬅️ Back to Categories'),
            'callback_data' => $product->category_id ? 'category:'.$product->category_id : 'category:uncategorized',
        ]];

        $lines = [
            '🛍️ '.$product->name,
            '━━━━━━━━━━━━━━',
            $this->locale->text($customer, '💰 Harga: Rp', '💰 Price: Rp').number_format($product->price_amount, 0, ',', '.'),
            $availableStock > 0 ? $this->locale->text($customer, '✅ Status: Ready', '✅ Status: Available') : $this->locale->text($customer, '❌ Status: Stok kosong', '❌ Status: Sold out'),
        ];
        if ($availableStock > 0) {
            $lines[] = $this->locale->text($customer, '📦 Stok: ', '📦 Stock: ').$availableStock;
        }
        $lines[] = $this->locale->text($customer, '🛒 Terjual: ', '🛒 Sold: ').$soldQuantity;
        $lines[] = '';
        $lines[] = $this->locale->text($customer, '📝 Detail produk', '📝 Product details');
        $lines[] = $this->descriptionText($customer, $product);
        $lines[] = '';
        $lines[] = $availableStock > 0
            ? $this->locale->text($customer, 'Kalau cocok, langsung gas checkout di bawah.', 'Ready to buy? Continue to checkout below.')
            : $this->locale->text($customer, 'Stoknya lagi habis nih. Cek produk lain dulu ya, atau tunggu restock berikutnya.', 'This product is sold out. Check another product or wait for a restock.');

        $this->bot->sendMessage($chatId, implode("\n", $lines), ['inline_keyboard' => $keyboard]);
    }

    public function sendQuantityChoices(Customer $customer, string $chatId, Product $product, int $availableStock): void
    {
        $quantityLimit = min($availableStock, 5);
        $keyboard = [];
        for ($quantity = 1; $quantity <= $quantityLimit; $quantity++) {
            $keyboard[] = [['text' => (string) $quantity, 'callback_data' => 'qty:'.$product->id.':'.$quantity]];
        }
        $keyboard[] = [['text' => $this->locale->text($customer, '⬅️ Detail Produk', '⬅️ Product Details'), 'callback_data' => 'product:'.$product->id]];

        $lines = [
            $this->locale->text($customer, '🔢 Pilih jumlah', '🔢 Choose quantity'),
            '━━━━━━━━━━━━━━',
            $this->locale->text($customer, '📦 Produk: ', '📦 Product: ').$product->name,
            $this->locale->text($customer, '✅ Stok ready: ', '✅ Available stock: ').$availableStock,
            '',
            $this->locale->text($customer, 'Mau ambil berapa item?', 'How many items would you like?'),
        ];

        $this->bot->sendMessage($chatId, implode("\n", $lines), ['inline_keyboard' => $keyboard]);
    }

    public function sendCategoryProducts(Customer $customer, string $chatId, string $categoryId): void
    {
        $query = Product::publicVisible()->storefrontOrder();
        $category = null;
        if ($categoryId === 'uncategorized') {
            $query->whereNull('category_id');
            $title = $this->locale->text($customer, 'Lainnya', 'Other');
        } else {
            $category = Category::whereKey((int) $categoryId)->where('is_active', true)->first();
            if (! $category) {
                $this->bot->sendMessage($chatId, $this->locale->text($customer, 'Kategori ini belum tersedia atau sudah dipindahkan.', 'This category is no longer available.'), ['inline_keyboard' => [[['text' => $this->locale->text($customer, '⬅️ Balik ke Kategori', '⬅️ Back to Categories'), 'callback_data' => 'back:categories']]]]);

                return;
            }
            $query->where('category_id', $category->id);
            $title = $category->name;
        }

        $products = $query->limit(12)->get();
        $lines = [$this->locale->text($customer, '🛍️ Daftar Produk', '🛍️ Product List'), '━━━━━━━━━━━━━━', $this->locale->text($customer, 'Kategori: ', 'Category: ').$title];
        if ($category?->description) {
            $lines[] = '';
            $lines[] = $category->description;
        }
        $lines[] = '';
        $lines[] = $this->locale->text($customer, 'Pilih produk untuk melihat detail.', 'Choose a product to view its details.');

        $keyboard = $products->values()->map(fn ($product) => [$this->productButton($product)])->all();
        $keyboard[] = [['text' => $this->locale->text($customer, '⬅️ Pilih Kategori', '⬅️ Choose Category'), 'callback_data' => 'back:categories']];

        $this->bot->sendMessage($chatId, $products->isEmpty() ? $this->locale->text($customer, "📂 Belum ada produk di kategori ini\n━━━━━━━━━━━━━━\nCoba cek kategori lain dulu ya.", "📂 No products in this category\n━━━━━━━━━━━━━━\nPlease check another category.") : implode("\n", $lines), ['inline_keyboard' => $keyboard]);
    }

    public function sendSearchResults(Customer $customer, string $chatId, string $keyword): void
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
            $this->bot->sendMessage($chatId, $this->locale->text($customer, "Produk dengan kata kunci \"{$keyword}\" belum ketemu.\n\nCoba kata kunci lain, atau buka /produk untuk lihat semua produk.", "No products matched \"{$keyword}\".\n\nTry another keyword or open /products to browse all products."), ['inline_keyboard' => [[['text' => $this->locale->text($customer, '🛍️ Lihat Produk', '🛍️ Browse Products'), 'callback_data' => 'katalog']]]]);

            return;
        }

        $hasMore = $products->count() > $limit;
        $products = $products->take($limit)->values();
        $lines = [$this->locale->text($customer, '🔎 Hasil pencarian: ', '🔎 Search results: ').$keyword, '━━━━━━━━━━━━━━', ''];
        foreach ($products as $i => $product) {
            $lines[] = ($i + 1).'. '.($product->isAvailable() ? '✅' : '❌').' '.$product->name;
            $lines[] = '   💰 Rp'.number_format($product->price_amount, 0, ',', '.');
            $lines[] = '   📝 '.$this->descriptionExcerpt($customer, $product);
        }
        if ($hasMore) {
            $lines[] = '';
            $lines[] = $this->locale->text($customer, 'Masih ada hasil lain. Coba pakai kata kunci yang lebih spesifik biar lebih cepat ketemu.', 'There are more results. Try a more specific keyword.');
        }

        $keyboard = $products->map(fn ($product) => [$this->productButton($product)])->all();
        $keyboard[] = [['text' => $this->locale->text($customer, '🛍️ Lihat Produk', '🛍️ Browse Products'), 'callback_data' => 'katalog']];

        $this->bot->sendMessage($chatId, implode("\n", $lines), ['inline_keyboard' => $keyboard]);
    }

    private function descriptionText(Customer $customer, Product $product): string
    {
        $description = trim((string) $product->description);

        return $description !== '' ? $description : $this->locale->text($customer, 'Deskripsi produk belum ditambahkan. Tapi tenang, produknya tetap bisa dicek dari info harga dan stok.', 'No product description has been added yet. You can still check the price and stock information.');
    }

    private function soldQuantity(Product $product): int
    {
        return (int) OrderItem::query()
            ->where('product_id', $product->id)
            ->whereHas('order', fn ($order) => $order
                ->where('order_status', OrderStatus::Completed)
                ->where('payment_status', PaymentStatus::Paid))
            ->sum('quantity');
    }

    private function descriptionExcerpt(Customer $customer, Product $product): string
    {
        $description = preg_replace('/\s+/', ' ', $this->descriptionText($customer, $product)) ?? '';

        return strlen($description) > 90 ? substr($description, 0, 87).'...' : $description;
    }

    /**
     * @return array{text: string, callback_data: string, style?: string, icon_custom_emoji_id?: string}
     */
    private function productButton(Product $product): array
    {
        $availableStock = max(0, $product->availableStock());
        $icon = $this->icons->resolve($product->name);
        $customEmojiId = $icon['custom_emoji_id'];
        $fallbackIcon = $customEmojiId ? '' : $icon['fallback'].' ';
        $status = $availableStock > 0 ? '✨' : '❌';

        $button = [
            'text' => $status.' '.$fallbackIcon.Str::limit($product->name, 44, '...')
                .' | Rp'.number_format($product->price_amount, 0, ',', '.')
                .' | 📦 '.$availableStock,
            'callback_data' => 'product:'.$product->id,
            'style' => $availableStock > 0 ? 'success' : 'danger',
        ];

        if ($customEmojiId) {
            $button['icon_custom_emoji_id'] = $customEmojiId;
        }

        return $button;
    }
}
