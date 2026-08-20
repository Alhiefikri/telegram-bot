<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\Customer;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use App\Services\CheckoutMaintenance\CheckoutMaintenanceState;

class TelegramCatalogIntents
{
    public function __construct(
        private TelegramBotClient $bot,
        private ProductBrandIconResolver $icons,
        private TelegramLocale $locale,
        private TelegramCheckoutIntents $checkout,
        private CheckoutMaintenance $maintenance,
    ) {}

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
            $keyboard[] = [['text' => $this->locale->translate($customer, 'catalog.uncategorized'), 'callback_data' => 'category:uncategorized']];
        }

        $message = $keyboard === []
            ? $this->locale->translate($customer, 'catalog.empty')
            : $this->locale->translate($customer, 'catalog.header');
        $maintenance = $this->maintenance->current();
        if ($maintenance->active) {
            $message .= "\n\n".$this->maintenanceNotice($customer, $maintenance);
        }

        $this->bot->sendMessage($chatId, $message, ['inline_keyboard' => $keyboard]);
    }

    public function sendStockChoices(Customer $customer, string $chatId, Product $product, int $availableStock): void
    {
        $soldQuantity = $this->soldQuantity($product);
        $maintenance = $this->maintenance->current();
        $checkoutMaintenanceActive = $maintenance->active;
        $binanceAvailable = ! $checkoutMaintenanceActive
            && $availableStock > 0
            && $product->usdt_price_amount !== null
            && $this->checkout->binancePayEnabled();
        $keyboard = [];
        if ($checkoutMaintenanceActive) {
            $keyboard[] = [[
                'text' => $this->locale->translate($customer, 'catalog.check_status'),
                'callback_data' => 'checkout-maintenance:status',
            ]];
        } elseif ($availableStock > 0) {
            $qrisButton = [[
                'text' => $this->locale->translate($customer, 'catalog.payment.qris_button', [
                    'amount' => number_format($product->price_amount, 0, ',', '.'),
                ]),
                'callback_data' => 'buy:qris:'.$product->id,
            ]];
            if ($binanceAvailable) {
                $binanceButton = [[
                    'text' => $this->locale->translate($customer, 'catalog.payment.binance_button', [
                        'amount' => $this->checkout->formatUsdt($product->usdt_price_amount),
                    ]),
                    'callback_data' => 'buy:binance:'.$product->id,
                ]];
                $keyboard = [$qrisButton, $binanceButton];
            } else {
                $keyboard[] = $qrisButton;
            }
        }
        $keyboard[] = [[
            'text' => $this->locale->translate($customer, 'catalog.back_categories'),
            'callback_data' => $product->category_id ? 'category:'.$product->category_id : 'category:uncategorized',
        ]];

        $lines = [
            '🛍️ '.$product->name,
            '━━━━━━━━━━━━━━',
            $this->locale->translate($customer, 'catalog.price').number_format($product->price_amount, 0, ',', '.'),
        ];
        if ($binanceAvailable) {
            $lines[] = $this->locale->translate($customer, 'catalog.payment.binance_total', [
                'amount' => $this->checkout->formatUsdt($product->usdt_price_amount),
            ]);
        }
        $lines[] = $availableStock > 0
            ? $this->locale->translate($customer, 'catalog.status.available')
            : $this->locale->translate($customer, 'catalog.status.sold_out');
        if ($availableStock > 0) {
            $lines[] = $this->locale->translate($customer, 'catalog.stock').$availableStock;
        }
        $lines[] = $this->locale->translate($customer, 'catalog.sold').$soldQuantity;
        $lines[] = '';
        $lines[] = $this->locale->translate($customer, 'catalog.details');
        $lines[] = $this->descriptionText($customer, $product);
        $lines[] = '';
        $lines[] = $checkoutMaintenanceActive
            ? $this->maintenanceNotice($customer, $maintenance)
            : ($availableStock > 0
                ? $this->locale->translate($customer, 'catalog.payment_method')
                    ."\n\n"
                    .$this->locale->qrisCrossBorderSupport($customer)
                    ."\n\n"
                    .$this->locale->translate($customer, 'catalog.after_quantity')
                : $this->locale->translate($customer, 'catalog.sold_out_guidance'));

        $this->bot->sendMessage($chatId, implode("\n", $lines), ['inline_keyboard' => $keyboard]);
    }

    public function sendQuantityChoices(Customer $customer, string $chatId, Product $product, int $availableStock, ?string $paymentMethod = null): void
    {
        $quantityLimit = min($availableStock, 5);
        $maintenance = $this->maintenance->current();
        $checkoutMaintenanceActive = $maintenance->active;
        $directCheckout = in_array($paymentMethod, ['qris', 'binance'], true);
        $keyboard = [];
        if ($checkoutMaintenanceActive) {
            $keyboard[] = [[
                'text' => $this->locale->translate($customer, 'catalog.check_status'),
                'callback_data' => 'checkout-maintenance:status',
            ]];
        } else {
            for ($quantity = 1; $quantity <= $quantityLimit; $quantity++) {
                if ($paymentMethod === 'binance') {
                    $total = $this->locale->translate($customer, 'catalog.quantity.binance_total', [
                        'amount' => $this->checkout->formatUsdt((int) $product->usdt_price_amount * $quantity),
                    ]);
                } elseif ($paymentMethod === 'qris') {
                    $total = $this->locale->translate($customer, 'catalog.quantity.qris_total', [
                        'amount' => number_format($product->price_amount * $quantity, 0, ',', '.'),
                    ]);
                } else {
                    $total = null;
                }
                $keyboard[] = [[
                    'text' => $total === null ? (string) $quantity : $quantity.' · '.$total,
                    'callback_data' => $directCheckout ? 'qty:'.$paymentMethod.':'.$product->id.':'.$quantity : 'qty:'.$product->id.':'.$quantity,
                ]];
            }
        }
        $keyboard[] = [['text' => $this->locale->translate($customer, 'catalog.quantity.back_details'), 'callback_data' => 'product:'.$product->id]];

        $lines = [
            $this->locale->translate($customer, 'catalog.quantity.title'),
            '━━━━━━━━━━━━━━',
            $this->locale->translate($customer, 'catalog.quantity.product').$product->name,
            $this->locale->translate($customer, 'catalog.quantity.available_stock').$availableStock,
        ];
        if ($paymentMethod === 'binance') {
            $lines[] = $this->locale->translate($customer, 'catalog.payment.binance_total', [
                'amount' => $this->checkout->formatUsdt((int) $product->usdt_price_amount),
            ]).$this->locale->translate($customer, 'catalog.quantity.per_item');
        } elseif ($paymentMethod === 'qris') {
            $lines[] = $this->locale->translate($customer, 'catalog.payment.qris_total', [
                'amount' => number_format($product->price_amount, 0, ',', '.'),
            ]).$this->locale->translate($customer, 'catalog.quantity.per_item');
        }
        $lines[] = '';
        $lines[] = $checkoutMaintenanceActive
            ? $this->maintenanceNotice($customer, $maintenance)
            : ($directCheckout
                ? $this->locale->translate($customer, 'catalog.quantity.direct_prompt')
                : $this->locale->translate($customer, 'catalog.quantity.prompt'));

        $this->bot->sendMessage($chatId, implode("\n", $lines), ['inline_keyboard' => $keyboard]);
    }

    public function sendCategoryProducts(Customer $customer, string $chatId, string $categoryId): void
    {
        $query = Product::publicVisible()->storefrontOrder();
        $category = null;
        if ($categoryId === 'uncategorized') {
            $query->whereNull('category_id');
            $title = $this->locale->translate($customer, 'catalog.uncategorized');
        } else {
            $category = Category::whereKey((int) $categoryId)->where('is_active', true)->first();
            if (! $category) {
                $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'catalog.category.unavailable'), ['inline_keyboard' => [[['text' => $this->locale->translate($customer, 'catalog.back_categories'), 'callback_data' => 'back:categories']]]]);

                return;
            }
            $query->where('category_id', $category->id);
            $title = $category->name;
        }

        $products = $query->withAvailability()->limit(12)->get();
        $maintenance = $this->maintenance->current();
        $lines = [$this->locale->translate($customer, 'catalog.category.title'), '━━━━━━━━━━━━━━', $this->locale->translate($customer, 'catalog.category.label').$title];
        if ($category?->description) {
            $lines[] = '';
            $lines[] = $category->description;
        }
        foreach ($products as $index => $product) {
            $this->appendProductSummary($lines, $customer, $product, $index + 1);
        }
        if ($maintenance->active) {
            $lines[] = '';
            $lines[] = $this->maintenanceNotice($customer, $maintenance);
        }
        $lines[] = '';
        $lines[] = $this->locale->translate($customer, 'catalog.category.choose_product');

        $keyboard = $products->values()->map(fn ($product, $index) => [$this->productButton($customer, $product, $index + 1)])->all();
        if ($this->hasMultipleCategoryOptions()) {
            $keyboard[] = [['text' => $this->locale->translate($customer, 'catalog.category.back'), 'callback_data' => 'back:categories']];
        }

        $message = $products->isEmpty()
            ? $this->locale->translate($customer, 'catalog.category.empty')
            : implode("\n", $lines);
        if ($maintenance->active && $products->isEmpty()) {
            $message .= "\n\n".$this->maintenanceNotice($customer, $maintenance);
        }

        $this->bot->sendMessage($chatId, $message, ['inline_keyboard' => $keyboard]);
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

        $maintenance = $this->maintenance->current();
        if ($products->isEmpty()) {
            $message = $this->locale->translate($customer, 'search.no_results', ['keyword' => $keyword]);
            if ($maintenance->active) {
                $message .= "\n\n".$this->maintenanceNotice($customer, $maintenance);
            }
            $this->bot->sendMessage($chatId, $message, ['inline_keyboard' => [[['text' => $this->locale->translate($customer, 'search.browse'), 'callback_data' => 'katalog']]]]);

            return;
        }

        $hasMore = $products->count() > $limit;
        $products = $products->take($limit)->values();
        $lines = [$this->locale->translate($customer, 'search.title').$keyword, '━━━━━━━━━━━━━━', ''];
        if ($maintenance->active) {
            $lines[] = $this->maintenanceNotice($customer, $maintenance);
            $lines[] = '';
        }
        foreach ($products as $i => $product) {
            $lines[] = ($i + 1).'. '.($product->isAvailable() ? '✅' : '❌').' '.$product->name;
            $lines[] = '   '.$this->productPrice($customer, $product);
            if ($this->hasBinancePrice($product)) {
                $lines[] = '   '.$this->locale->translate($customer, 'catalog.payment.binance_total', [
                    'amount' => $this->checkout->formatUsdt($product->usdt_price_amount),
                ]);
            }
            $lines[] = '   📝 '.$this->descriptionExcerpt($customer, $product);
        }
        if ($hasMore) {
            $lines[] = '';
            $lines[] = $this->locale->translate($customer, 'search.more');
        }

        $keyboard = $products->map(fn ($product, $index) => [$this->productButton($customer, $product, $index + 1)])->all();
        $keyboard[] = [['text' => $this->locale->translate($customer, 'search.browse'), 'callback_data' => 'katalog']];

        $this->bot->sendMessage($chatId, implode("\n", $lines), ['inline_keyboard' => $keyboard]);
    }

    private function maintenanceNotice(Customer $customer, CheckoutMaintenanceState $state): string
    {
        return $this->locale->translate($customer, 'checkout.maintenance').$this->locale->checkoutMaintenanceNote($customer, $state->publicNote);
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

        return $description !== '' ? $description : $this->locale->translate($customer, 'catalog.description_missing');
    }

    /**
     * @param  list<string>  $lines
     */
    private function appendProductSummary(array &$lines, Customer $customer, Product $product, int $number): void
    {
        $lines[] = $number.'. '.($product->isAvailable() ? '✅' : '❌').' '.$product->name;
        $lines[] = '   '.$this->productPrice($customer, $product);
        if ($this->hasBinancePrice($product)) {
            $lines[] = '   '.$this->locale->translate($customer, 'catalog.payment.binance_total', [
                'amount' => $this->checkout->formatUsdt($product->usdt_price_amount),
            ]);
        }
    }

    private function productPrice(Customer $customer, Product $product): string
    {
        return $this->locale->translate($customer, 'catalog.price').number_format($product->price_amount, 0, ',', '.');
    }

    private function hasBinancePrice(Product $product): bool
    {
        return $product->isAvailable()
            && $product->usdt_price_amount !== null
            && $this->checkout->binancePayEnabled();
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
    private function productButton(Customer $customer, Product $product, int $number): array
    {
        $availableStock = max(0, $product->availableStock());
        $icon = $this->icons->resolve($product->name);
        $customEmojiId = $icon['custom_emoji_id'];
        $fallbackIcon = $customEmojiId ? '' : $icon['fallback'].' ';

        $button = [
            'text' => $fallbackIcon.$this->locale->translate($customer, 'catalog.product_button', ['number' => $number]),
            'callback_data' => 'product:'.$product->id,
            'style' => $availableStock > 0 ? 'success' : 'danger',
        ];

        if ($customEmojiId) {
            $button['icon_custom_emoji_id'] = $customEmojiId;
        }

        return $button;
    }
}
