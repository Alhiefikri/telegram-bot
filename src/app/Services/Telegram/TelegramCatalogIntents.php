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
    public function __construct(private TelegramBotClient $bot, private ProductBrandIconResolver $icons, private TelegramLocale $locale, private TelegramCheckoutIntents $checkout) {}

    public function sendCategoryCatalog(Customer $customer, string $chatId): void
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->whereHas('products', fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')->orderBy('name')->get();
        $hasUncategorized = Product::whereNull('category_id')->where('is_active', true)->exists();

        if ($categories->count() + ($hasUncategorized ? 1 : 0) === 1) {
            $this->sendCategoryProducts($customer, $chatId, $hasUncategorized ? 'uncategorized' : (string) $categories->first()->id);

            return;
        }

        $keyboard = $categories->map(fn ($category) => [['text' => '📂 '.$category->name, 'callback_data' => 'category:'.$category->id]])->values()->all();
        if ($hasUncategorized) {
            $keyboard[] = [['text' => $this->locale->text($customer, '📂 Lainnya', '📂 Other'), 'callback_data' => 'category:uncategorized']];
        }

        $this->bot->sendMessage($chatId, $keyboard === [] ? $this->locale->text($customer, "🛍️ Produk masih kosong nih\nBelum ada produk yang bisa ditampilkan sekarang.\n\nCek lagi nanti ya.", "🛍️ No products yet\nThere are no products to show right now.\n\nPlease check again later.") : $this->locale->text($customer, "🛍️ Lihat Produk\n━━━━━━━━━━━━━━\nPilih kategori yang kamu mau.\n\n✨ Produk tersedia dan kosong tetap kelihatan jelas di sini.", "🛍️ Browse Products\n━━━━━━━━━━━━━━\nChoose a category.\n\n✨ Available and sold-out products are clearly shown here."), ['inline_keyboard' => $keyboard]);
    }

    public function sendStockChoices(Customer $customer, string $chatId, Product $product, int $availableStock): void
    {
        $soldQuantity = $this->soldQuantity($product);
        $binanceAvailable = $availableStock > 0
            && $product->usdt_price_amount !== null
            && $this->checkout->binancePayEnabled();
        $keyboard = [];
        if ($availableStock > 0) {
            $qrisButton = [[
                'text' => '💳 QRIS · Rp'.number_format($product->price_amount, 0, ',', '.'),
                'callback_data' => 'buy:qris:'.$product->id,
            ]];
            if ($binanceAvailable) {
                $binanceButton = [[
                    'text' => '💎 Binance Pay · '.$this->checkout->formatUsdt($product->usdt_price_amount).' USDT',
                    'callback_data' => 'buy:binance:'.$product->id,
                ]];
                $keyboard = [$qrisButton, $binanceButton];
            } else {
                $keyboard[] = $qrisButton;
            }
        }
        $keyboard[] = [[
            'text' => $this->locale->text($customer, '⬅️ Balik ke Kategori', '⬅️ Back to Categories'),
            'callback_data' => $product->category_id ? 'category:'.$product->category_id : 'category:uncategorized',
        ]];

        $lines = [
            '🛍️ '.$product->name,
            '━━━━━━━━━━━━━━',
            $this->locale->text($customer, '💰 Harga: Rp', '💰 Price: Rp').number_format($product->price_amount, 0, ',', '.'),
        ];
        if ($binanceAvailable) {
            $lines[] = '💎 Binance Pay: '.$this->checkout->formatUsdt($product->usdt_price_amount).' USDT';
        }
        $lines[] = $availableStock > 0 ? $this->locale->text($customer, '✅ Status: Tersedia', '✅ Status: Available') : $this->locale->text($customer, '❌ Status: Stok kosong', '❌ Status: Sold out');
        if ($availableStock > 0) {
            $lines[] = $this->locale->text($customer, '📦 Stok: ', '📦 Stock: ').$availableStock;
        }
        $lines[] = $this->locale->text($customer, '🛒 Terjual: ', '🛒 Sold: ').$soldQuantity;
        $lines[] = '';
        $lines[] = $this->locale->text($customer, '📝 Detail produk', '📝 Product details');
        $lines[] = $this->descriptionText($customer, $product);
        $lines[] = '';
        $lines[] = $availableStock > 0
            ? $this->locale->text($customer, 'Pilih cara bayar di bawah.', 'Choose a payment method below.')
                ."\n\n"
                .$this->locale->qrisCrossBorderSupport($customer)
                ."\n\n"
                .$this->locale->text($customer, 'Setelah pilih jumlah, checkout langsung dibuat.', 'After you choose the quantity, checkout is created immediately.')
            : $this->locale->text($customer, 'Stoknya lagi habis. Cek produk lain atau tunggu restock berikutnya.', 'This product is sold out. Check another product or wait for a restock.');

        $this->bot->sendMessage($chatId, implode("\n", $lines), ['inline_keyboard' => $keyboard]);
    }

    public function sendQuantityChoices(Customer $customer, string $chatId, Product $product, int $availableStock, ?string $paymentMethod = null): void
    {
        $quantityLimit = min($availableStock, 5);
        $directCheckout = in_array($paymentMethod, ['qris', 'binance'], true);
        $keyboard = [];
        for ($quantity = 1; $quantity <= $quantityLimit; $quantity++) {
            if ($paymentMethod === 'binance') {
                $total = $this->checkout->formatUsdt((int) $product->usdt_price_amount * $quantity).' USDT';
            } elseif ($paymentMethod === 'qris') {
                $total = 'Rp'.number_format($product->price_amount * $quantity, 0, ',', '.');
            } else {
                $total = null;
            }
            $keyboard[] = [[
                'text' => $total === null ? (string) $quantity : $quantity.' · '.$total,
                'callback_data' => $directCheckout ? 'qty:'.$paymentMethod.':'.$product->id.':'.$quantity : 'qty:'.$product->id.':'.$quantity,
            ]];
        }
        $keyboard[] = [['text' => $this->locale->text($customer, '⬅️ Detail Produk', '⬅️ Product Details'), 'callback_data' => 'product:'.$product->id]];

        $lines = [
            $this->locale->text($customer, '🔢 Pilih jumlah', '🔢 Choose quantity'),
            '━━━━━━━━━━━━━━',
            $this->locale->text($customer, '📦 Produk: ', '📦 Product: ').$product->name,
            $this->locale->text($customer, '✅ Stok tersedia: ', '✅ Available stock: ').$availableStock,
        ];
        if ($paymentMethod === 'binance') {
            $lines[] = '💎 Binance Pay: '.$this->checkout->formatUsdt((int) $product->usdt_price_amount).' USDT'.$this->locale->text($customer, ' per item', ' / item');
        } elseif ($paymentMethod === 'qris') {
            $lines[] = '💳 QRIS: Rp'.number_format($product->price_amount, 0, ',', '.').$this->locale->text($customer, ' per item', ' / item');
        }
        $lines[] = '';
        $lines[] = $directCheckout
            ? $this->locale->text($customer, 'Tekan jumlah yang kamu mau. Checkout langsung dibuat tanpa langkah tambahan.', 'Tap the quantity you want. Checkout will be created immediately with no extra step.')
            : $this->locale->text($customer, 'Mau ambil berapa item?', 'How many items would you like?');

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
                $this->bot->sendMessage($chatId, $this->locale->text($customer, 'Kategori ini sudah tidak tersedia. Coba pilih kategori lain.', 'This category is no longer available. Please choose another category.'), ['inline_keyboard' => [[['text' => $this->locale->text($customer, '⬅️ Balik ke Kategori', '⬅️ Back to Categories'), 'callback_data' => 'back:categories']]]]);

                return;
            }
            $query->where('category_id', $category->id);
            $title = $category->name;
        }

        $products = $query->withAvailability()->limit(12)->get();
        $lines = [$this->locale->text($customer, '🛍️ Daftar Produk', '🛍️ Product List'), '━━━━━━━━━━━━━━', $this->locale->text($customer, 'Kategori: ', 'Category: ').$title];
        if ($category?->description) {
            $lines[] = '';
            $lines[] = $category->description;
        }
        $lines[] = '';
        $lines[] = $this->locale->text($customer, 'Pilih produk untuk melihat detail.', 'Choose a product to view its details.');

        $keyboard = $products->values()->map(fn ($product) => [$this->productButton($product)])->all();
        if ($this->hasMultipleCategoryOptions()) {
            $keyboard[] = [['text' => $this->locale->text($customer, '⬅️ Pilih Kategori', '⬅️ Choose Category'), 'callback_data' => 'back:categories']];
        }

        $this->bot->sendMessage($chatId, $products->isEmpty() ? $this->locale->text($customer, "📂 Belum ada produk di kategori ini\nCoba cek kategori lain dulu ya.", "📂 No products in this category\nPlease check another category.") : implode("\n", $lines), ['inline_keyboard' => $keyboard]);
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
            ->withAvailability()
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

    private function hasMultipleCategoryOptions(): bool
    {
        $categoryCount = Category::query()
            ->where('is_active', true)
            ->whereHas('products', fn ($q) => $q->where('is_active', true))
            ->count();
        $hasUncategorized = Product::whereNull('category_id')->where('is_active', true)->exists();

        return $categoryCount + ($hasUncategorized ? 1 : 0) > 1;
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
        $priceParts = ['Rp '.number_format($product->price_amount, 0, ',', '.')];

        if ($availableStock > 0
            && $product->usdt_price_amount !== null
            && $this->checkout->binancePayEnabled()) {
            $priceParts[] = $this->checkout->formatUsdt($product->usdt_price_amount).' USDT';
        }

        $suffix = ' · '.implode(' · ', $priceParts);
        $prefix = $status.' '.$fallbackIcon;

        $button = [
            'text' => $this->productButtonLabel($prefix, $product->name, $suffix),
            'callback_data' => 'product:'.$product->id,
            'style' => $availableStock > 0 ? 'success' : 'danger',
        ];

        if ($customEmojiId) {
            $button['icon_custom_emoji_id'] = $customEmojiId;
        }

        return $button;
    }

    private function productButtonLabel(string $prefix, string $name, string $suffix): string
    {
        $maxLength = 64;
        $nameLimit = $maxLength - Str::length($prefix.$suffix);

        if ($nameLimit <= 0) {
            return Str::substr($prefix.$suffix, 0, $maxLength);
        }

        if (Str::length($name) <= $nameLimit) {
            return $prefix.$name.$suffix;
        }

        $nameLabel = $nameLimit > 3
            ? Str::substr($name, 0, $nameLimit - 3).'...'
            : Str::substr($name, 0, $nameLimit);

        return $prefix.$nameLabel.$suffix;
    }
}
