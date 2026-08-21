<?php

namespace App\Services\Telegram;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use App\Services\CheckoutMaintenance\CheckoutMaintenanceState;

class TelegramCatalogIntents
{
    private const PRODUCTS_PER_PAGE = 12;

    public function __construct(
        private TelegramBotClient $bot,
        private TelegramLocale $locale,
        private TelegramCheckoutIntents $checkout,
        private TelegramIntentSession $session,
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

    public function sendProductDetail(Customer $customer, string $chatId, Product $product): void
    {
        $availableStock = max(0, $product->availableStock());
        if ($availableStock < 1) {
            $this->session->forget($customer);
            $this->renderProductDetail($customer, $chatId, $product, null, $availableStock);

            return;
        }

        $selection = $this->session->start(
            $customer,
            (int) $product->getKey(),
            1,
            $this->locale->code($customer),
        );
        $this->renderProductDetail($customer, $chatId, $product, $selection, $availableStock);
    }

    /**
     * @param  array{productId: int, quantity: int, interactionToken: string, locale: string, mode: string}  $selection
     */
    public function renderProductDetail(Customer $customer, string $chatId, Product $product, ?array $selection, ?int $availableStock = null): void
    {
        $availableStock ??= max(0, $product->availableStock());
        $quantity = $selection['quantity'] ?? null;
        if ($selection !== null && $selection['mode'] === 'browsing') {
            if ($availableStock < 1 || ! is_int($quantity) || $quantity < 1) {
                $this->session->forget($customer);
                $selection = null;
                $quantity = null;
            } elseif ($quantity > $availableStock) {
                $selection = $this->session->browsing(
                    $customer,
                    $selection,
                    $availableStock,
                    $this->locale->code($customer),
                );
                $quantity = $availableStock;
            }
        }

        $maintenance = $this->maintenance->current();
        $selectionCanPay = $selection !== null
            && $selection['mode'] === 'browsing'
            && is_int($quantity)
            && $quantity >= 1
            && $quantity <= $availableStock;
        $binanceAvailable = ! $maintenance->active
            && $availableStock > 0
            && $product->usdt_price_amount !== null
            && $this->checkout->binancePayEnabled();
        $keyboard = [];

        if ($selectionCanPay) {
            $productId = (int) $product->getKey();
            $token = $selection['interactionToken'];
            $keyboard[] = [[
                'text' => $this->locale->translate($customer, 'catalog.detail.decrease'),
                'callback_data' => 'detail:qty:-:'.$productId.':'.$token,
            ], [
                'text' => (string) $quantity,
                'callback_data' => 'detail:qty:current:'.$productId.':'.$token,
            ], [
                'text' => $this->locale->translate($customer, 'catalog.detail.increase'),
                'callback_data' => 'detail:qty:+:'.$productId.':'.$token,
            ]];
            $keyboard[] = [[
                'text' => $this->locale->translate($customer, 'catalog.detail.custom_quantity'),
                'callback_data' => 'detail:qty:manual:'.$productId.':'.$token,
            ]];

            if ($maintenance->active) {
                $keyboard[] = [[
                    'text' => $this->locale->translate($customer, 'catalog.check_status'),
                    'callback_data' => 'checkout-maintenance:status',
                ]];
            } else {
                $keyboard[] = [[
                    'text' => $this->locale->translate($customer, 'catalog.detail.pay_qris', [
                        'amount' => number_format($product->price_amount * $quantity, 0, ',', '.'),
                    ]),
                    'callback_data' => 'detail:pay:qris:'.$productId.':'.$token,
                    'style' => 'success',
                ]];
                if ($binanceAvailable) {
                    $keyboard[] = [[
                        'text' => $this->locale->translate($customer, 'catalog.detail.pay_binance', [
                            'amount' => $this->checkout->formatUsdt((int) $product->usdt_price_amount * $quantity),
                        ]),
                        'callback_data' => 'detail:pay:binance:'.$productId.':'.$token,
                    ]];
                }
            }

            $keyboard[] = [[
                'text' => $this->locale->translate($customer, 'catalog.detail.back_products'),
                'callback_data' => 'detail:back:'.$productId.':'.$token,
            ]];
        } else {
            if ($maintenance->active) {
                $keyboard[] = [[
                    'text' => $this->locale->translate($customer, 'catalog.check_status'),
                    'callback_data' => 'checkout-maintenance:status',
                ]];
            }
            $keyboard[] = [[
                'text' => $this->locale->translate($customer, 'catalog.back_categories'),
                'callback_data' => $product->category_id ? 'category:'.$product->category_id : 'category:uncategorized',
            ]];
        }

        $lines = [
            '🛍️ '.$product->name,
            '━━━━━━━━━━━━━━',
            $this->locale->translate($customer, 'catalog.price').number_format($product->price_amount, 0, ',', '.'),
        ];
        if ($binanceAvailable) {
            $lines[] = $this->locale->translate($customer, 'catalog.detail.binance_price', [
                'amount' => $this->checkout->formatUsdt((int) $product->usdt_price_amount),
            ]);
        }
        if ($availableStock > 0) {
            $lines[] = $this->locale->translate($customer, 'catalog.stock').$availableStock;
        } else {
            $lines[] = $this->locale->translate($customer, 'catalog.status.sold_out');
        }
        if ($quantity !== null) {
            $lines[] = $this->locale->translate($customer, 'catalog.detail.quantity', ['quantity' => $quantity]);
            $lines[] = $this->locale->translate($customer, 'catalog.detail.subtotal', [
                'amount' => number_format($product->price_amount * (int) $quantity, 0, ',', '.'),
            ]);
        }
        $lines[] = '';
        $lines[] = $this->locale->translate($customer, 'catalog.details');
        $lines[] = $this->descriptionText($customer, $product);
        if ($maintenance->active) {
            $lines[] = '';
            $lines[] = $this->maintenanceNotice($customer, $maintenance);
            if ($availableStock < 1) {
                $lines[] = $this->locale->translate($customer, 'catalog.sold_out_guidance');
            }
        } elseif ($availableStock < 1) {
            $lines[] = '';
            $lines[] = $this->locale->translate($customer, 'catalog.sold_out_guidance');
        } else {
            $lines[] = '';
            $lines[] = $this->locale->translate($customer, 'catalog.qris_support');
        }

        $this->bot->sendMessage($chatId, implode("\n", $lines), ['inline_keyboard' => $keyboard]);
    }

    /**
     * @param  array{productId: int, quantity: int, interactionToken: string, locale: string, mode: string}  $selection
     */
    public function sendCustomQuantityPrompt(Customer $customer, string $chatId, Product $product, array $selection, ?string $feedback = null): void
    {
        $availableStock = max(0, $product->availableStock());
        $lines = [];
        if ($feedback !== null) {
            $lines[] = $feedback;
            $lines[] = '';
        }
        $lines[] = $this->locale->translate($customer, 'catalog.quantity.custom_title');
        $lines[] = '━━━━━━━━━━━━━━';
        $lines[] = $this->locale->translate($customer, 'catalog.quantity.product').$product->name;
        $lines[] = $this->locale->translate($customer, 'catalog.quantity.current', ['quantity' => $selection['quantity']]);
        $lines[] = $this->locale->translate($customer, 'catalog.quantity.available_stock').$availableStock;
        $lines[] = '';
        $lines[] = $this->locale->translate($customer, 'catalog.quantity.custom_instruction', ['example' => 5]);

        $productId = (int) $product->getKey();
        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'inline_keyboard' => [[[
                'text' => $this->locale->translate($customer, 'catalog.quantity.cancel'),
                'callback_data' => 'detail:qty:cancel:'.$productId.':'.$selection['interactionToken'],
            ]]],
        ]);
    }

    public function handleCustomQuantityInput(Customer $customer, string $chatId, string $text): void
    {
        $this->session->withLock($customer, function () use ($customer, $chatId, $text): void {
            $selection = $this->session->get($customer);
            if ($selection === null || $selection['mode'] !== 'awaiting_custom_quantity') {
                $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'catalog.selection.stale'));

                return;
            }

            $maintenance = $this->maintenance->current();
            if ($maintenance->active) {
                $this->session->forget($customer);
                $this->bot->sendMessage(
                    $chatId,
                    $this->maintenanceNotice($customer, $maintenance),
                    ['inline_keyboard' => [[[
                        'text' => $this->locale->translate($customer, 'checkout.status_button'),
                        'callback_data' => 'checkout-maintenance:status',
                    ]]]],
                );

                return;
            }

            $product = Product::publicVisible()->whereKey($selection['productId'])->first();
            $availableStock = $product ? max(0, $product->availableStock()) : 0;
            if (! $product || $availableStock < 1) {
                $this->session->forget($customer);
                $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'catalog.selection.unavailable'));

                return;
            }
            $stockChanged = (int) $selection['quantity'] > $availableStock;
            if ($stockChanged) {
                $selection = $this->session->awaitCustomQuantity(
                    $customer,
                    [...$selection, 'quantity' => $availableStock],
                    $this->locale->code($customer),
                );
            }


            $candidate = trim($text);
            $quantity = preg_match('/^[0-9]+$/D', $candidate) === 1
                ? filter_var($candidate, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                : false;
            if ($quantity === false) {
                $retry = $this->session->awaitCustomQuantity(
                    $customer,
                    $selection,
                    $this->locale->code($customer),
                );
                $feedback = $stockChanged
                    ? $this->locale->translate($customer, 'catalog.quantity.stock_changed')."\n\n"
                        .$this->locale->translate($customer, 'catalog.quantity.retry')
                    : $this->locale->translate($customer, 'catalog.quantity.retry');
                $this->sendCustomQuantityPrompt(
                    $customer,
                    $chatId,
                    $product,
                    $retry,
                    $feedback,
                );

                return;
            }
            if ($quantity > $availableStock) {
                $retry = $this->session->awaitCustomQuantity(
                    $customer,
                    $selection,
                    $this->locale->code($customer),
                );
                $this->sendCustomQuantityPrompt(
                    $customer,
                    $chatId,
                    $product,
                    $retry,
                    $this->locale->translate($customer, 'catalog.quantity.over_stock', ['stock' => $availableStock]),
                );

                return;
            }

            $next = $this->session->browsing($customer, $selection, (int) $quantity, $this->locale->code($customer));
            $this->renderProductDetail($customer, $chatId, $product, $next, $availableStock);
        });
    }

    public function sendCategoryProducts(Customer $customer, string $chatId, string $categoryId, int $page = 1): void
    {
        $page = max(1, $page);
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

        $totalProducts = (clone $query)->count();
        $lastPage = max(1, (int) ceil($totalProducts / self::PRODUCTS_PER_PAGE));
        $page = min($page, $lastPage);
        $products = $query
            ->withAvailability()
            ->forPage($page, self::PRODUCTS_PER_PAGE)
            ->get();
        $maintenance = $this->maintenance->current();
        $lines = [$this->locale->translate($customer, 'catalog.category.title'), '━━━━━━━━━━━━━━', $this->locale->translate($customer, 'catalog.category.label').$title];
        if ($lastPage > 1) {
            $lines[] = $this->locale->translate($customer, 'catalog.category.page', [
                'page' => $page,
                'pages' => $lastPage,
            ]);
        }
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

        $keyboard = $this->productKeyboard($products);
        if ($lastPage > 1) {
            $pageButtons = [];
            if ($page > 1) {
                $pageButtons[] = [
                    'text' => $this->locale->translate($customer, 'catalog.category.previous_page'),
                    'callback_data' => 'category:'.$categoryId.':page:'.($page - 1),
                ];
            }
            if ($page < $lastPage) {
                $pageButtons[] = [
                    'text' => $this->locale->translate($customer, 'catalog.category.next_page'),
                    'callback_data' => 'category:'.$categoryId.':page:'.($page + 1),
                ];
            }
            if ($pageButtons !== []) {
                $keyboard[] = $pageButtons;
            }
        }
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
            $lines[] = '   '.$this->compactProductPrice($customer, $product);
            $lines[] = '   📝 '.$this->descriptionExcerpt($customer, $product);
        }
        if ($hasMore) {
            $lines[] = '';
            $lines[] = $this->locale->translate($customer, 'search.more');
        }

        $keyboard = $this->productKeyboard($products);
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
        $lines[] = '   '.$this->compactProductPrice($customer, $product);
    }

    private function compactProductPrice(Customer $customer, Product $product): string
    {
        $parts = [
            $this->locale->translate($customer, 'catalog.payment.price_compact', [
                'amount' => number_format($product->price_amount, 0, ',', '.'),
            ]),
        ];
        if ($this->hasBinancePrice($product)) {
            $parts[] = $this->locale->translate($customer, 'catalog.payment.binance_total', [
                'amount' => $this->checkout->formatUsdt($product->usdt_price_amount),
            ]);
        }

        return implode(' · ', $parts);
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

    private function descriptionExcerpt(Customer $customer, Product $product): string
    {
        $description = preg_replace('/\s+/', ' ', $this->descriptionText($customer, $product)) ?? '';

        return strlen($description) > 90 ? substr($description, 0, 87).'...' : $description;
    }

    /**
     * @param  iterable<int, Product>  $products
     * @return list<list<array{text: string, callback_data: string, style: string}>>
     */
    private function productKeyboard(iterable $products): array
    {
        $buttons = [];
        $number = 1;
        foreach ($products as $product) {
            $buttons[] = $this->productButton($product, $number);
            $number++;
        }

        return array_chunk($buttons, 3);
    }

    /**
     * @return array{text: string, callback_data: string, style: string}
     */
    private function productButton(Product $product, int $number): array
    {
        $availableStock = max(0, $product->availableStock());

        return [
            'text' => (string) $number,
            'callback_data' => 'product:'.$product->id,
            'style' => $availableStock > 0 ? 'success' : 'danger',
        ];
    }
}
