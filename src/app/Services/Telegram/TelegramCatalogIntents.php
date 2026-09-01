<?php

namespace App\Services\Telegram;

use App\Catalog\CatalogBrowseEntry;
use App\Catalog\CatalogBrowseQuery;
use App\Catalog\CatalogBrowseResult;
use App\Catalog\CatalogCategoryReadModel;
use App\Catalog\CatalogFamilyReadModel;
use App\Catalog\CatalogProductReadModel;
use App\Models\Customer;
use App\Models\Product;
use App\Services\Catalog\CatalogBrowse;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use App\Services\CheckoutMaintenance\CheckoutMaintenanceState;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class TelegramCatalogIntents
{
    private const PRODUCTS_PER_PAGE = 4;

    private const FAMILY_LIST_PER_PAGE = 4;

    public function __construct(
        private TelegramBotClient $bot,
        private TelegramLocale $locale,
        private TelegramCheckoutIntents $checkout,
        private TelegramIntentSession $session,
        private CheckoutMaintenance $maintenance,
        private CatalogBrowse $browse,
        private ProductBrandIconResolver $icons,
        private TelegramCatalogQuerySession $catalogQueries,
        private TelegramFamilyCallbackToken $familyTokens,
    ) {}

    public function sendCategoryCatalog(Customer $customer, string $chatId, ?int $messageId = null): void
    {
        $this->sendCatalogPage($customer, $chatId, messageId: $messageId);
    }

    public function sendCatalogPage(Customer $customer, string $chatId, int $page = 1, ?string $search = null, ?string $category = null, ?int $messageId = null): void
    {
        $requestedPage = max(1, $page);
        $result = $this->browse->browsePublic(CatalogBrowseQuery::fromValues(
            search: $search,
            category: $category,
            page: $requestedPage,
            perPage: self::FAMILY_LIST_PER_PAGE,
        ));

        if ($result->pagination->total > 0 && $result->pagination->currentPage !== $requestedPage) {
            $this->sendStaleCatalog($customer, $chatId, $messageId);

            return;
        }

        $originToken = $this->catalogQueries->putCatalog(
            $customer,
            $result->pagination->currentPage,
            $this->familyIdsFromResult($result),
        );
        $this->sendBrowseResult($customer, $chatId, $result, 'catalog.header', 'catalog', null, $search, null, null, $originToken, $messageId);
    }

    public function sendCategoryPicker(Customer $customer, string $chatId, ?int $messageId = null): void
    {
        $options = $this->browse->publicCategoryOptions(withProductsOnly: true);
        $keyboard = collect($options->categories)
            ->map(fn (CatalogCategoryReadModel $category): array => [[
                'text' => '📂 '.$category->name,
                'callback_data' => 'category:'.$category->id,
            ]])
            ->values()
            ->all();
        if ($options->hasUncategorized) {
            $keyboard[] = [[
                'text' => $this->locale->translate($customer, 'catalog.uncategorized'),
                'callback_data' => 'category:uncategorized',
            ]];
        }
        $keyboard[] = [[
            'text' => $this->locale->translate($customer, 'catalog.family.back_menu'),
            'callback_data' => 'family:back:menu',
        ]];

        $message = $options->categories === [] && ! $options->hasUncategorized
            ? $this->locale->translate($customer, 'catalog.empty')
            : $this->locale->translate($customer, 'catalog.category_filter');
        $this->bot->editMessageTextOrSend($chatId, $message, ['inline_keyboard' => $keyboard], $messageId);
    }

    public function sendFamilyOptions(Customer $customer, string $chatId, int $familyId, int $page = 1, int $returnPage = 1, ?string $originToken = null, ?int $messageId = null): void
    {
        try {
            $family = $this->browse->showPublicFamily($familyId);
        } catch (ModelNotFoundException) {
            $this->sendCatalogMessage($chatId, $this->locale->translate($customer, 'catalog.family.unavailable'), [
                'inline_keyboard' => [[[
                    'text' => $this->locale->translate($customer, 'catalog.family.back_menu'),
                    'callback_data' => 'family:back:menu',
                ]]],
            ], $messageId);

            return;
        }

        $members = collect($family->members);
        $lastPage = max(1, (int) ceil($members->count() / self::PRODUCTS_PER_PAGE));
        $page = min(max(1, $page), $lastPage);
        $returnPage = max(1, $returnPage);
        $pageMembers = $members->forPage($page, self::PRODUCTS_PER_PAGE)->values();
        $familyIcon = $this->familyIcon($family);
        $totalSold = $members->sum(fn (CatalogProductReadModel $member): int => $member->soldQuantity);
        $lines = [
            $this->escapeHtml($this->locale->translate($customer, 'catalog.family.options_title', [
                'icon' => $familyIcon,
                'name' => $family->name,
            ])),
            '━━━━━━━━━━━━━━',
            $this->escapeHtml($this->locale->translate($customer, 'catalog.family.sold_total', ['sold' => $totalSold])),
            '━━━━━━━━━━━━━━',
        ];
        if ($lastPage > 1) {
            $lines[] = $this->escapeHtml($this->locale->translate($customer, 'catalog.family.page', [
                'page' => $page,
                'pages' => $lastPage,
            ]));
        }
        $lines[] = '';
        foreach ($pageMembers as $index => $member) {
            $number = $index + 1;
            $lines[] = $this->familyOptionBlock($customer, $member, $number);
            if ($index < $pageMembers->count() - 1) {
                $lines[] = '';
            }
        }
        $maintenance = $this->maintenance->current();
        if ($maintenance->active) {
            $lines[] = '';
            $lines[] = $this->escapeHtml($this->maintenanceNotice($customer, $maintenance));
        }
        $stateToken = $this->catalogQueries->familyState($customer)['token'] ?? null;
        $optionButtons = $pageMembers
            ->map(function (CatalogProductReadModel $member, int $index) use ($family, $page, $returnPage): array {
                return [
                    ...$this->iconButton($member->name, (string) ($index + 1), $member->icon),
                    'callback_data' => $this->familySelectCallback($family->id, $member->id, $page, $returnPage),
                ];
            })
            ->all();
        $keyboard = array_chunk($optionButtons, 2);
        $pageButtons = [];
        if ($page > 1) {
            $pageButtons[] = [
                'text' => $this->locale->translate($customer, 'catalog.family.previous_page'),
                'callback_data' => $this->familyOptionsCallbackData(
                    $family->id,
                    $page - 1,
                    $returnPage,
                    $originToken,
                    $stateToken,
                ),
            ];
        }
        if ($page < $lastPage) {
            $pageButtons[] = [
                'text' => $this->locale->translate($customer, 'catalog.family.next_page'),
                'callback_data' => $this->familyOptionsCallbackData(
                    $family->id,
                    $page + 1,
                    $returnPage,
                    $originToken,
                    $stateToken,
                ),
            ];
        }
        if ($pageButtons !== []) {
            $keyboard[] = $pageButtons;
        }
        $keyboard[] = [[
            'text' => $this->locale->translate($customer, 'catalog.family.back_list'),
            'callback_data' => $originToken === null
                ? 'family:list:'.$returnPage
                : 'family:list:'.$returnPage.':o'.$originToken,
        ]];

        $this->sendCatalogMessage($chatId, implode("\n", $lines), ['inline_keyboard' => $keyboard], $messageId, ['parse_mode' => 'HTML']);
    }

    public function openFamilyOptions(
        Customer $customer,
        string $chatId,
        int $familyId,
        int $page,
        int $returnPage = 1,
        ?int $expectedProductId = null,
        ?string $originToken = null,
        ?string $stateToken = null,
        ?callable $beforeSend = null,
        ?int $messageId = null,
    ): bool {
        try {
            $family = $this->browse->showPublicFamily($familyId);
        } catch (ModelNotFoundException) {
            return false;
        }
        $members = collect($family->members);
        $lastPage = max(1, (int) ceil($members->count() / self::PRODUCTS_PER_PAGE));
        if ($page < 1 || $page > $lastPage || $returnPage < 1) {
            return false;
        }
        if ($expectedProductId !== null && ! $members->forPage($page, self::PRODUCTS_PER_PAGE)->contains(
            fn (CatalogProductReadModel $member): bool => $member->id === $expectedProductId,
        )) {
            return false;
        }

        $origin = null;
        if ($stateToken !== null) {
            $familyState = $this->catalogQueries->familyState($customer);
            if ($familyState === null
                || ! hash_equals((string) ($familyState['token'] ?? ''), $stateToken)
                || ($familyState['familyId'] ?? null) !== $familyId
                || ($familyState['returnPage'] ?? null) !== $returnPage) {
                return false;
            }
            $stateOrigin = $familyState['origin'] ?? null;
            $stateOriginToken = is_array($stateOrigin) ? ($stateOrigin['token'] ?? null) : null;
            if ($originToken !== null && $originToken !== $stateOriginToken) {
                return false;
            }
            $originToken = is_string($stateOriginToken) ? $stateOriginToken : null;
            $origin = is_array($stateOrigin) ? $stateOrigin : null;
        } elseif ($originToken !== null) {
            $origin = $this->resolveFamilyOrigin($customer, $familyId, $returnPage, $originToken);
            if ($origin === null) {
                return false;
            }
        }

        $this->catalogQueries->putFamily($customer, $familyId, $page, $returnPage, $originToken, $origin);
        if ($beforeSend !== null) {
            $beforeSend();
        }
        $this->sendFamilyOptions($customer, $chatId, $familyId, $page, $returnPage, $originToken, $messageId);

        return true;
    }

    public function openFamilyProduct(Customer $customer, string $chatId, int $familyId, int $productId, int $page, int $returnPage = 1, ?int $messageId = null): bool
    {
        $familyState = $this->catalogQueries->familyState($customer);
        if ($familyState === null
            || $familyState['familyId'] !== $familyId
            || $familyState['page'] !== $page
            || $familyState['returnPage'] !== $returnPage) {
            return false;
        }
        $originToken = is_array($familyState['origin'] ?? null)
            ? ($familyState['origin']['token'] ?? null)
            : null;

        try {
            $family = $this->browse->showPublicFamily($familyId);
        } catch (ModelNotFoundException) {
            return false;
        }
        $members = collect($family->members);
        $lastPage = max(1, (int) ceil($members->count() / self::PRODUCTS_PER_PAGE));
        if ($page < 1 || $page > $lastPage || $returnPage < 1) {
            return false;
        }
        $selectedMember = $members
            ->forPage($page, self::PRODUCTS_PER_PAGE)
            ->first(fn (CatalogProductReadModel $member): bool => $member->id === $productId);
        if (! $selectedMember instanceof CatalogProductReadModel) {
            return false;
        }

        $product = Product::query()->withAvailability()->publicVisible()->whereKey($productId)->first();
        if (! $product || (int) $product->product_family_id !== $familyId) {
            return false;
        }

        if ($selectedMember->isAvailable) {
            $selection = $this->session->get($customer);
            if ($selection !== null
                && $selection['mode'] === 'browsing'
                && $selection['productId'] === $productId
                && ($selection['familyId'] ?? null) === $familyId
                && ($selection['familyPage'] ?? null) === $page
                && ($selection['familyListPage'] ?? 1) === $returnPage
                && ($selection['familyOriginToken'] ?? null) === $originToken) {
                return true;
            }
        }
        $this->sendProductDetail($customer, $chatId, $product, [
            'familyId' => $familyId,
            'familyPage' => $page,
            'familyListPage' => $returnPage,
            'familyOriginToken' => is_string($originToken) ? $originToken : null,
        ], $messageId);

        return true;
    }

    public function openCatalogOrigin(Customer $customer, string $chatId, string $originToken, ?int $messageId = null): bool
    {
        $origin = $this->catalogQueries->originForToken($customer, $originToken);
        if ($origin === null) {
            return false;
        }
        $this->session->forgetWithLock($customer);

        if (($origin['type'] ?? null) === 'search' && is_string($origin['search'] ?? null)) {
            $this->sendSearchResults($customer, $chatId, $origin['search'], (int) ($origin['page'] ?? 1), $messageId);

            return true;
        }
        if (($origin['type'] ?? null) === 'category' && is_string($origin['category'] ?? null)) {
            $this->sendCategoryProducts($customer, $chatId, $origin['category'], (int) ($origin['page'] ?? 1), $messageId);

            return true;
        }

        if (($origin['type'] ?? null) === 'catalog') {
            $this->sendCatalogPage($customer, $chatId, (int) ($origin['page'] ?? 1), messageId: $messageId);

            return true;
        }

        return false;
    }

    /**
     * @param  array{type: 'product'|'family', id: int}  $intent
     */
    public function openStartIntent(Customer $customer, string $chatId, array $intent): bool
    {
        if ($intent['type'] === 'family') {
            return $this->openFamilyOptions($customer, $chatId, $intent['id'], 1);
        }

        $product = Product::query()->withAvailability()->publicVisible()->whereKey($intent['id'])->first();
        if (! $product) {
            return false;
        }

        $this->sendProductDetail($customer, $chatId, $product);

        return true;
    }

    /**
     * @param  array{familyId?: int, familyPage?: int, familyListPage?: int, familyOriginToken?: string}|null  $navigation
     */
    public function sendProductDetail(Customer $customer, string $chatId, Product $product, ?array $navigation = null, ?int $messageId = null): void
    {
        $availableStock = max(0, $product->availableStock());
        if ($availableStock < 1) {
            $this->session->forget($customer);
            $this->renderProductDetail($customer, $chatId, $product, null, $availableStock, $messageId, $navigation);

            return;
        }

        $selection = $this->session->start(
            $customer,
            (int) $product->getKey(),
            1,
            $this->locale->code($customer),
            $navigation ?? [],
        );
        $this->renderProductDetail($customer, $chatId, $product, $selection, $availableStock, $messageId, $navigation);
    }

    /**
     * @param  array{productId: int, quantity: int, interactionToken: string, locale: string, mode: string, familyId?: int, familyPage?: int, familyListPage?: int, familyOriginToken?: string}|null  $selection
     * @param  array{familyId?: int, familyPage?: int, familyListPage?: int, familyOriginToken?: string}|null  $navigation
     */
    public function renderProductDetail(Customer $customer, string $chatId, Product $product, ?array $selection, ?int $availableStock = null, ?int $messageId = null, ?array $navigation = null): void
    {
        $availableStock ??= max(0, $product->availableStock());
        $navigation ??= $this->navigationFromSelection($selection);
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
            && $this->checkout->binanceRailSelectable();
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
            $familyBackCallback = $this->familyOptionsCallback($navigation);
            $keyboard[] = [[
                'text' => $familyBackCallback !== null
                    ? $this->locale->translate($customer, 'catalog.family.back_options')
                    : $this->locale->translate($customer, 'catalog.back_categories'),
                'callback_data' => $familyBackCallback
                    ?? ($product->category_id ? 'category:'.$product->category_id : 'category:uncategorized'),
            ]];
        }

        $lines = [
            $this->productIcon($product->name, $product->icon).' '.$product->name,
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
            $lines[] = $this->locale->qrisCrossBorderSupport($customer);
        }

        $text = implode("\n", $lines);
        $this->bot->editMessageTextOrSend(
            $chatId,
            $text,
            ['inline_keyboard' => $keyboard],
            $messageId,
        );
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

            $product = Product::query()->withAvailability()->publicVisible()->whereKey($selection['productId'])->first();
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

    public function sendCategoryProducts(Customer $customer, string $chatId, string $categoryId, int $page = 1, ?int $messageId = null): void
    {
        $category = $categoryId === 'uncategorized'
            ? null
            : $this->browse->findPublicCategory($categoryId);
        if ($categoryId !== 'uncategorized' && $category === null) {
            $this->sendCatalogMessage($chatId, $this->locale->translate($customer, 'catalog.category.unavailable'), [
                'inline_keyboard' => [[[
                    'text' => $this->locale->translate($customer, 'catalog.back_categories'),
                    'callback_data' => 'catalog:categories',
                ]]],
            ], $messageId);

            return;
        }

        $requestedPage = max(1, $page);
        $result = $this->browse->browsePublic(CatalogBrowseQuery::fromValues(
            category: $categoryId,
            page: $requestedPage,
            perPage: self::PRODUCTS_PER_PAGE,
        ));
        if ($result->pagination->total > 0 && $result->pagination->currentPage !== $requestedPage) {
            $this->sendStaleCatalog($customer, $chatId, $messageId);

            return;
        }

        $title = $category?->name ?? $this->locale->translate($customer, 'catalog.uncategorized');
        $originToken = $this->catalogQueries->putCategory(
            $customer,
            $categoryId,
            $result->pagination->currentPage,
            $this->familyIdsFromResult($result),
        );
        $this->sendBrowseResult(
            $customer,
            $chatId,
            $result,
            'catalog.category.title',
            'category',
            $categoryId,
            null,
            $title,
            $category?->description,
            $originToken,
            $messageId,
        );
    }

    public function sendSearchResults(Customer $customer, string $chatId, string $keyword, int $page = 1, ?int $messageId = null): void
    {
        $search = CatalogBrowseQuery::normalizeSearch($keyword);
        if ($search === '') {
            $this->sendCatalogMessage($chatId, $this->locale->translate($customer, 'search.prompt'), [], $messageId);

            return;
        }

        $requestedPage = max(1, $page);
        $result = $this->browse->browsePublic(CatalogBrowseQuery::fromValues(
            search: $search,
            page: $requestedPage,
            perPage: self::PRODUCTS_PER_PAGE,
        ));
        if ($result->pagination->total > 0 && $result->pagination->currentPage !== $requestedPage) {
            $this->sendStaleCatalog($customer, $chatId, $messageId);

            return;
        }
        $searchToken = $this->catalogQueries->putSearch(
            $customer,
            $search,
            $result->pagination->currentPage,
            $this->familyIdsFromResult($result),
        );
        $this->sendBrowseResult($customer, $chatId, $result, 'search.title', 'search', null, $search, null, null, $searchToken, $messageId);
    }

    private function sendBrowseResult(
        Customer $customer,
        string $chatId,
        CatalogBrowseResult $result,
        string $titleKey,
        string $mode,
        ?string $categoryId = null,
        ?string $search = null,
        ?string $categoryTitle = null,
        ?string $categoryDescription = null,
        ?string $originToken = null,
        ?int $messageId = null,
    ): void {
        $maintenance = $this->maintenance->current();
        $title = $this->locale->translate(
            $customer,
            $titleKey,
            $search === null ? [] : ['keyword' => $search],
        );
        if ($search !== null && ! str_contains($title, $search)) {
            $title .= $search;
        }
        $pagination = $result->pagination;
        $lines = [$title];
        if ($categoryTitle !== null) {
            $lines[] = $this->locale->translate($customer, 'catalog.category.label').$categoryTitle;
        }
        if ($pagination->hasPages()) {
            $lines[] = $this->locale->translate($customer, 'catalog.family.page', [
                'page' => $pagination->currentPage,
                'pages' => $pagination->lastPage,
            ]);
        }
        if ($categoryDescription !== null && trim($categoryDescription) !== '') {
            $lines[] = '';
            $lines[] = $categoryDescription;
        }
        if ($maintenance->active && $result->entries !== []) {
            $lines[] = '';
            $lines[] = $this->maintenanceNotice($customer, $maintenance);
        }

        if ($result->entries === []) {
            $message = match ($mode) {
                'search' => $this->locale->translate($customer, 'search.no_results', ['keyword' => $search ?? '']),
                'category' => $this->locale->translate($customer, 'catalog.category.empty'),
                default => $this->locale->translate($customer, 'catalog.empty'),
            };
            if ($maintenance->active) {
                $message .= "\n\n".$this->maintenanceNotice($customer, $maintenance);
            }
            $this->sendCatalogMessage($chatId, $message, ['inline_keyboard' => $this->browseNavigationKeyboard($customer, $mode)], $messageId);

            return;
        }

        if ($mode !== 'catalog') {
            $lines[] = '';
            $lines[] = $this->locale->translate($customer, 'catalog.category.choose_product');
        }
        foreach ($result->entries as $index => $entry) {
            $number = $index + 1;
            $lines[] = '';
            $lines[] = $this->entryBlock($customer, $entry, $number);
            if ($mode === 'catalog' && $index < count($result->entries) - 1) {
                $lines[] = '━━━━━━━━━━━━━━';
            }
        }

        if ($mode === 'search' && $pagination->hasPages()) {
            $lines[] = '';
            $lines[] = $this->locale->translate($customer, 'search.more');
        }

        $keyboard = $this->entryKeyboard($customer, $result->entries, $pagination->currentPage, $originToken, $mode);
        if ($pagination->hasPages()) {
            $pageButtons = [];
            if ($pagination->currentPage > 1) {
                $pageButtons[] = [
                    'text' => $this->locale->translate($customer, 'catalog.family.previous_page'),
                    'callback_data' => $this->browsePageCallback($mode, $categoryId, $pagination->currentPage - 1, $originToken),
                ];
            }
            if ($pagination->currentPage < $pagination->lastPage) {
                $pageButtons[] = [
                    'text' => $this->locale->translate($customer, 'catalog.family.next_page'),
                    'callback_data' => $this->browsePageCallback($mode, $categoryId, $pagination->currentPage + 1, $originToken),
                ];
            }
            if ($pageButtons !== []) {
                $keyboard[] = $pageButtons;
            }
        }
        $keyboard = [...$keyboard, ...$this->browseNavigationKeyboard($customer, $mode, includeEmptyFallback: false)];

        $this->sendCatalogMessage($chatId, implode("\n", $lines), ['inline_keyboard' => $keyboard], $messageId);
    }

    /**
     * A callback always carries the originating bot message ID. Editing is
     * preferred; sending is a fallback only when there is no message to edit
     * or Telegram definitively rejects that edit.
     */
    private function sendCatalogMessage(
        string $chatId,
        string $text,
        array $replyMarkup = [],
        ?int $messageId = null,
        array $options = [],
    ): void {
        $this->bot->editMessageTextOrSend($chatId, $text, $replyMarkup, $messageId, $options);
    }

    /**
     * @param  list<CatalogBrowseEntry>  $entries
     * @return list<list<array<string, mixed>>>
     */
    private function entryKeyboard(
        Customer $customer,
        array $entries,
        int $listPage = 1,
        ?string $originToken = null,
        string $mode = 'category',
    ): array {
        $buttons = [];
        foreach ($entries as $index => $entry) {
            $number = $index + 1;
            $family = $entry->family;
            $product = $entry->product;
            if ($family !== null) {
                $callback = 'family:options:'.$this->familyTokens->options($family->id, 1, $listPage);
                if ($originToken !== null) {
                    $callback .= ':o'.$originToken;
                }
                $buttons[] = [
                    'text' => $mode === 'catalog'
                        ? $this->productIcon($family->name, $this->familyIconOverride($family)).' ('.$number.')'
                        : $this->locale->translate($customer, 'catalog.family.number_button', ['number' => $number]),
                    'callback_data' => $callback,
                    'style' => $family->isAvailable ? 'success' : 'danger',
                ];
            } elseif ($product !== null) {
                $buttons[] = [
                    'text' => $this->locale->translate($customer, 'catalog.family.number_button', ['number' => $number]),
                    'callback_data' => 'product:'.$product->id,
                    'style' => $product->isAvailable ? 'success' : 'danger',
                ];
            }
        }

        return $mode === 'catalog'
            ? array_chunk($buttons, 2)
            : array_map(static fn (array $button): array => [$button], $buttons);
    }

    private function entryBlock(Customer $customer, CatalogBrowseEntry $entry, int $number): string
    {
        if ($entry->family !== null) {
            $family = $entry->family;
            $stock = array_sum(array_map(
                static fn (CatalogProductReadModel $member): int => max(0, $member->availableStock),
                $family->members,
            ));

            return $this->locale->translate($customer, 'catalog.family.list_item', [
                'number' => sprintf('%02d.', $number),
                'icon' => $this->familyIcon($family),
                'name' => $family->name,
                'stock' => $stock,
            ]);
        }

        $product = $entry->product;
        if ($product === null) {
            return $number.'.';
        }
        $icon = $this->productIcon($product->name, $product->icon);

        return $number.'. '.$icon.' '.$product->name."\n"
            .$this->locale->translate($customer, 'catalog.family.standalone_summary', [
                'price' => number_format($product->priceAmount, 0, ',', '.'),
                'stock' => $product->availableStock,
            ]);
    }

    private function familyOptionBlock(Customer $customer, CatalogProductReadModel $member, int $number): string
    {
        $lines = [
            sprintf('%02d. %s %s', $number, $this->productIcon($member->name, $member->icon), $member->label),
            $this->locale->translate($customer, 'catalog.family.option_price', [
                'amount' => number_format($member->priceAmount, 0, ',', '.'),
            ]),
        ];
        if ($member->usdtPriceAmount !== null) {
            $lines[] = $this->locale->translate($customer, 'catalog.family.option_usdt_price', [
                'amount' => $this->checkout->formatUsdt($member->usdtPriceAmount),
            ]);
        }
        $lines[] = $this->locale->translate($customer, $member->isAvailable
            ? 'catalog.family.option_stock'
            : 'catalog.family.option_sold_out', [
                'stock' => $member->availableStock,
            ]);
        $lines[] = $this->locale->translate($customer, 'catalog.family.option_sold', [
            'sold' => $member->soldQuantity,
        ]);

        return '<blockquote>'.$this->escapeHtml(implode("\n", $lines)).'</blockquote>';
    }

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function productIcon(string $name, ?string $configuredIcon = null): string
    {
        return $this->icons->resolve($name, $configuredIcon);
    }

    private function familyIcon(CatalogFamilyReadModel $family): string
    {
        return $this->productIcon($family->name, $this->familyIconOverride($family));
    }

    private function familyIconOverride(CatalogFamilyReadModel $family): ?string
    {
        foreach ($family->members as $member) {
            if (! $member instanceof CatalogProductReadModel) {
                continue;
            }
            $icon = trim((string) $member->icon);
            if ($icon !== '') {
                return $icon;
            }
        }

        return null;
    }

    /**
     * @return array{text: string}
     */
    private function iconButton(string $name, string $label, ?string $configuredIcon = null): array
    {
        return ['text' => $this->productIcon($name, $configuredIcon).' '.$label];
    }

    private function familyOptionsCallbackData(
        int $familyId,
        int $page,
        int $returnPage,
        ?string $originToken = null,
        ?string $stateToken = null,
    ): string {
        $callback = 'family:options:'.$this->familyTokens->options($familyId, $page, $returnPage);
        if (is_string($stateToken) && preg_match('/\A[a-f0-9]{16}\z/D', $stateToken) === 1) {
            $callback .= ':s'.$stateToken;
        } elseif (is_string($originToken) && preg_match('/\A[a-f0-9]{16}\z/D', $originToken) === 1) {
            $callback .= ':o'.$originToken;
        }

        return $callback;
    }

    private function familySelectCallback(int $familyId, int $productId, int $page, int $returnPage): string
    {
        return 'family:select:'.$this->familyTokens->select($familyId, $productId, $page, $returnPage);
    }

    /**
     * @return list<list<array<string, string>>>
     */
    private function browseNavigationKeyboard(Customer $customer, string $mode, bool $includeEmptyFallback = true): array
    {
        if ($mode === 'catalog') {
            return [
                [[
                    'text' => $this->locale->translate($customer, 'catalog.family.filter_category'),
                    'callback_data' => 'catalog:categories',
                ]],
                [[
                    'text' => $this->locale->translate($customer, 'catalog.family.back_menu'),
                    'callback_data' => 'family:back:menu',
                ]],
            ];
        }
        if ($mode === 'category') {
            return [[[
                'text' => $this->locale->translate($customer, 'catalog.category.back'),
                'callback_data' => 'catalog:categories',
            ]]];
        }
        if ($mode === 'search') {
            return [[[
                'text' => $this->locale->translate($customer, 'search.browse'),
                'callback_data' => 'katalog',
            ]]];
        }

        return $includeEmptyFallback ? [[[
            'text' => $this->locale->translate($customer, 'catalog.family.back_menu'),
            'callback_data' => 'family:back:menu',
        ]]] : [];
    }

    private function browsePageCallback(string $mode, ?string $categoryId, int $page, ?string $searchToken = null): string
    {
        return match ($mode) {
            'category' => 'category:'.$categoryId.':page:'.$page,
            'search' => $searchToken !== null ? 'family:search:'.$searchToken.':'.$page : 'family:list:1',
            default => 'family:list:'.$page,
        };
    }

    private function sendStaleCatalog(Customer $customer, string $chatId, ?int $messageId = null): void
    {
        $this->sendCatalogMessage($chatId, $this->locale->translate($customer, 'catalog.family.stale'), [
            'inline_keyboard' => [[[
                'text' => $this->locale->translate($customer, 'catalog.family.back_menu'),
                'callback_data' => 'family:back:menu',
            ]]],
        ], $messageId);
    }

    /** @param  array<string, mixed>|null  $navigation */
    private function familyOptionsCallback(?array $navigation): ?string
    {
        if (! is_array($navigation)
            || ! is_int($navigation['familyId'] ?? null)
            || $navigation['familyId'] < 1
            || ! is_int($navigation['familyPage'] ?? null)
            || $navigation['familyPage'] < 1) {
            return null;
        }
        $familyListPage = $navigation['familyListPage'] ?? 1;
        if (! is_int($familyListPage) || $familyListPage < 1) {
            return null;
        }

        return $this->familyOptionsCallbackData(
            $navigation['familyId'],
            $navigation['familyPage'],
            $familyListPage,
            is_string($navigation['familyOriginToken'] ?? null) ? $navigation['familyOriginToken'] : null,
        );
    }

    /** @param  array<string, mixed>|null  $selection */
    /** @return array{familyId?: int, familyPage?: int, familyListPage?: int, familyOriginToken?: string} */
    private function navigationFromSelection(?array $selection): array
    {
        if (! is_array($selection)
            || ! is_int($selection['familyId'] ?? null)
            || ! is_int($selection['familyPage'] ?? null)) {
            return [];
        }

        $navigation = [
            'familyId' => $selection['familyId'],
            'familyPage' => $selection['familyPage'],
            'familyListPage' => is_int($selection['familyListPage'] ?? null) && $selection['familyListPage'] > 0
                ? $selection['familyListPage']
                : 1,
        ];
        if (is_string($selection['familyOriginToken'] ?? null)
            && preg_match('/\A[a-f0-9]{16}\z/D', $selection['familyOriginToken']) === 1) {
            $navigation['familyOriginToken'] = $selection['familyOriginToken'];
        }

        return $navigation;
    }

    /** @return array<string, mixed>|null */
    private function resolveFamilyOrigin(
        Customer $customer,
        int $familyId,
        int $returnPage,
        string $originToken,
    ): ?array {
        $state = $this->catalogQueries->state($customer);
        if ($state === null) {
            return null;
        }

        if (($state['type'] ?? null) === 'family') {
            if (($state['familyId'] ?? null) !== $familyId
                || ($state['returnPage'] ?? null) !== $returnPage) {
                return null;
            }

            return $this->catalogQueries->originForToken($customer, $originToken);
        }

        if (! hash_equals((string) ($state['token'] ?? ''), $originToken)
            || ($state['page'] ?? null) !== $returnPage
            || ! in_array($familyId, $state['familyIds'] ?? [], true)) {
            return null;
        }

        return $state;
    }

    /** @return list<int> */
    private function familyIdsFromResult(CatalogBrowseResult $result): array
    {
        $familyIds = [];
        foreach ($result->entries as $entry) {
            if ($entry->family !== null) {
                $familyIds[$entry->family->id] = $entry->family->id;
            }
        }

        return array_values($familyIds);
    }

    private function maintenanceNotice(Customer $customer, CheckoutMaintenanceState $state): string
    {
        return $this->locale->translate($customer, 'checkout.maintenance').$this->locale->checkoutMaintenanceNote($customer, $state->publicNote);
    }

    private function descriptionText(Customer $customer, Product $product): string
    {
        $description = trim((string) $product->description);

        return $description !== '' ? $description : $this->locale->translate($customer, 'catalog.description_missing');
    }
}
