<?php

namespace App\Services\Telegram;

use App\Catalog\CatalogBrowseEntry;
use App\Catalog\CatalogBrowseQuery;
use App\Catalog\CatalogBrowseResult;
use App\Catalog\CatalogCategoryOptions;
use App\Catalog\CatalogCategoryReadModel;
use App\Catalog\CatalogFamilyReadModel;
use App\Catalog\CatalogIcon;
use App\Catalog\CatalogProductReadModel;
use App\Catalog\CatalogProjectionPayload;
use App\Catalog\LocalizedProductCopy;
use App\Enums\TelegramNavigationScreen;
use App\Models\Customer;
use App\Models\Product;
use App\Services\Admin\ProductImageOptimizer;
use App\Services\Catalog\CatalogBrowse;
use App\Services\Catalog\CatalogFamilyIconResolver;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use App\Services\CheckoutMaintenance\CheckoutMaintenanceState;
use App\Services\Pricing\ProductPriceQuote;
use App\Services\Pricing\ProductPriceTierQuote;
use App\Services\Pricing\ProductQuantityPricing;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

class TelegramCatalogIntents
{
    private const PRODUCTS_PER_PAGE = 10;

    private const FAMILY_LIST_PER_PAGE = 15;

    public function __construct(
        private TelegramBotClient $bot,
        private TelegramLocale $locale,
        private TelegramCheckoutIntents $checkout,
        private TelegramIntentSession $session,
        private CheckoutMaintenance $maintenance,
        private CatalogBrowse $browse,
        private CatalogIconResolver $icons,
        private TelegramCatalogQuerySession $catalogQueries,
        private TelegramFamilyCallbackToken $familyTokens,
        private CatalogFamilyIconResolver $familyIcons,
        private TelegramHtmlCaption $captions,
        private TelegramNavigationRootService $roots,
        private ProductImageOptimizer $images,
        private ProductQuantityPricing $pricing,
        private TelegramCatalogProjectionCache $projectionCache,
        private TelegramUiIcons $uiIcons,
        private TelegramProductContent $content,
    ) {}

    public function sendCategoryCatalog(
        Customer $customer,
        string $chatId,
        ?int $messageId = null,
        bool $establishFreshRoot = false,
        bool $removeLegacyReplyKeyboard = false,
    ): void {
        $this->sendCatalogPage(
            $customer,
            $chatId,
            messageId: $messageId,
            establishFreshRoot: $establishFreshRoot,
            removeLegacyReplyKeyboard: $removeLegacyReplyKeyboard,
        );
    }

    public function sendCatalogPage(
        Customer $customer,
        string $chatId,
        int $page = 1,
        ?string $search = null,
        ?string $category = null,
        ?int $messageId = null,
        bool $establishFreshRoot = false,
        bool $removeLegacyReplyKeyboard = false,
        bool $refresh = false,
    ): void {
        $requestedPage = max(1, $page);
        $query = CatalogBrowseQuery::fromValues(
            search: $search,
            category: $category,
            page: $requestedPage,
            perPage: self::FAMILY_LIST_PER_PAGE,
        );
        $result = $refresh ? $this->browse->browsePublic($query) : $this->cachedPublicBrowse($query);
        if ($result->pagination->total > 0 && $result->pagination->currentPage !== $requestedPage) {
            $this->sendStaleCatalog($customer, $chatId, $messageId, $establishFreshRoot, $removeLegacyReplyKeyboard);

            return;
        }

        $originToken = $this->catalogQueries->putCatalog(
            $customer,
            $result->pagination->currentPage,
            $this->familyIdsFromResult($result),
        );
        $this->sendBrowseResult(
            $customer,
            $chatId,
            $result,
            'catalog.header',
            'catalog',
            null,
            $search,
            null,
            null,
            $originToken,
            $messageId,
            establishFreshRoot: $establishFreshRoot,
            removeLegacyReplyKeyboard: $removeLegacyReplyKeyboard,
            refresh: $refresh,
        );
    }

    public function sendCategoryPicker(Customer $customer, string $chatId, ?int $messageId = null): void
    {
        $options = $this->cachedCategoryOptions();
        $keyboard = collect($options->categories)
            ->map(fn (CatalogCategoryReadModel $category): array => [[
                'text' => $this->uiIcons->renderLegacyIcons('📂 ').$category->name,
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
        $this->sendCatalogMessage($customer, $chatId, $message, ['inline_keyboard' => $keyboard], $messageId);
    }

    public function sendFamilyOptions(Customer $customer, string $chatId, int $familyId, int $page = 1, int $returnPage = 1, ?string $originToken = null, ?int $messageId = null): void
    {
        try {
            $family = $this->browse->showPublicFamily($familyId);
        } catch (ModelNotFoundException) {
            $this->sendCatalogMessage($customer, $chatId, $this->locale->translate($customer, 'catalog.family.unavailable'), [
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
            trim($this->locale->translate($customer, 'catalog.family.options_title', [
                'icon' => $this->captions->icon($familyIcon),
                'name' => $this->escapeText($family->name),
            ])),
            '━━━━━━━━━━━━━━',
            $this->escapeText($this->locale->translate($customer, 'catalog.family.sold_total', ['sold' => $totalSold])),
            '━━━━━━━━━━━━━━',
        ];
        if ($lastPage > 1) {
            $lines[] = $this->escapeText($this->locale->translate($customer, 'catalog.family.page', [
                'page' => $page,
                'pages' => $lastPage,
            ]));
        }
        $lines[] = '';
        foreach ($pageMembers as $index => $member) {
            $number = $index + 1;
            $lines[] = $this->familyOptionBlock($customer, $member, $number, $this->catalogIcon($member));
            if ($index < $pageMembers->count() - 1) {
                $lines[] = '';
            }
        }
        $maintenance = $this->maintenance->current();
        if ($maintenance->active) {
            $lines[] = '';
            $lines[] = $this->escapeText($this->maintenanceNotice($customer, $maintenance));
        }
        $stateToken = $this->catalogQueries->familyState($customer)['token'] ?? null;
        $optionButtons = $pageMembers
            ->map(function (CatalogProductReadModel $member, int $index) use ($customer, $family, $page, $returnPage): array {
                $label = [
                    ...$this->catalogIcon($member)->buttonFields(),
                    'text' => $this->locale->translate($customer, 'catalog.family.option_button', [
                        'number' => (string) ($index + 1),
                    ]),
                ];
                if (! $member->isAvailable) {
                    // A disabled button is its own button type, so it carries no
                    // callback data and Telegram renders it inert.
                    return [...$label, 'style' => 'danger', 'disabled' => (object) []];
                }

                return [
                    ...$label,
                    'style' => 'primary',
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

        $this->sendCatalogMessage($customer, $chatId, implode("\n", $lines), ['inline_keyboard' => $keyboard], $messageId, ['parse_mode' => 'HTML']);
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

        $product = Product::query()->with(['quantityPriceTiers'])->withAvailability()->publicVisible()->whereKey($productId)->first();
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

        $product = Product::query()->with(['quantityPriceTiers'])->withAvailability()->publicVisible()->whereKey($intent['id'])->first();
        if (! $product) {
            return false;
        }

        $this->sendProductDetail($customer, $chatId, $product);

        return true;
    }

    /**
     * @param  array{familyId?: int, familyPage?: int, familyListPage?: int, familyOriginToken?: string, originToken?: string}|null  $navigation
     */
    public function sendProductDetail(Customer $customer, string $chatId, Product $product, ?array $navigation = null, ?int $messageId = null): void
    {
        if ($navigation === null) {
            $navigation = $this->familyNavigationFromProduct($product);
            if ($navigation !== []) {
                // The plan list of a directly opened Product is reachable through
                // its stateless back button, so the family query state has to be
                // current for that callback to validate.
                $this->catalogQueries->putFamily(
                    $customer,
                    $navigation['familyId'],
                    $navigation['familyPage'],
                    $navigation['familyListPage'],
                );
            }
        }

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
     * @param  array{productId: int, quantity: int, interactionToken: string, locale: string, mode: string, familyId?: int, familyPage?: int, familyListPage?: int, familyOriginToken?: string, originToken?: string}|null  $selection
     * @param  array{familyId?: int, familyPage?: int, familyListPage?: int, familyOriginToken?: string, originToken?: string}|null  $navigation
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

        $quote = $this->safeQuote($product, is_int($quantity) ? $quantity : 1);
        $maintenance = $this->maintenance->current();
        $selectionCanPay = $quote !== null
            && $selection !== null
            && $selection['mode'] === 'browsing'
            && is_int($quantity)
            && $quantity >= 1
            && $quantity <= $availableStock;
        $nextTier = $quote === null ? null : $this->nextReachableTier($quote, $availableStock);
        $nextTierQuote = $nextTier === null
            ? null
            : $this->safeQuote($product, $nextTier->minimumQuantity);
        $binanceAvailable = ! $maintenance->active
            && $selectionCanPay
            && $quote->usdtSubtotal !== null
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
            if ($nextTier !== null) {
                $keyboard[] = [[
                    'text' => $this->locale->translate($customer, 'catalog.detail.next_tier_button', [
                        'quantity' => $nextTier->minimumQuantity,
                    ]),
                    'callback_data' => 'detail:qty:tier:'.$productId.':'.$token,
                ], [
                    'text' => $this->locale->translate($customer, 'catalog.detail.custom_quantity'),
                    'callback_data' => 'detail:qty:manual:'.$productId.':'.$token,
                ]];
            } else {
                $keyboard[] = [[
                    'text' => $this->locale->translate($customer, 'catalog.detail.custom_quantity'),
                    'callback_data' => 'detail:qty:manual:'.$productId.':'.$token,
                ]];
            }

            if ($maintenance->active) {
                $keyboard[] = [[
                    'text' => $this->locale->translate($customer, 'catalog.check_status'),
                    'callback_data' => 'checkout-maintenance:status',
                ]];
            } else {
                $keyboard[] = [[
                    'text' => $this->locale->translate($customer, 'catalog.detail.pay_qris', [
                        'amount' => number_format($quote->idrSubtotal, 0, ',', '.'),
                    ]),
                    'callback_data' => 'detail:pay:qris:'.$productId.':'.$token,
                    'style' => 'primary',
                ]];
                $keyboard[] = [[
                    ...$this->uiIcons->apply('menu.balance', $this->locale->translate($customer, 'catalog.detail.pay_balance', [
                        'amount' => number_format($quote->idrSubtotal, 0, ',', '.'),
                    ])),
                    'callback_data' => 'detail:pay:balance:'.$productId.':'.$token,
                ]];
                if ($binanceAvailable) {
                    $keyboard[] = [[
                        'text' => $this->locale->translate($customer, 'catalog.detail.pay_binance', [
                            'amount' => $this->checkout->formatUsdt($quote->usdtSubtotal),
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
            $originBackCallback = $this->originBackCallback($navigation);
            if ($familyBackCallback !== null) {
                $backText = $this->locale->translate($customer, 'catalog.family.back_options');
                $backCallback = $familyBackCallback;
            } elseif ($originBackCallback !== null) {
                $backText = $this->locale->translate($customer, 'catalog.family.back_list');
                $backCallback = $originBackCallback;
            } else {
                $backText = $this->locale->translate($customer, 'catalog.back_categories');
                $backCallback = $product->category_id ? 'category:'.$product->category_id : 'category:uncategorized';
            }
            $keyboard[] = [[
                'text' => $backText,
                'callback_data' => $backCallback,
            ]];
        }

        $baseIdr = $quote?->baseIdrUnitAmount ?? (int) $product->price_amount;
        $showUsdtPricing = $binanceAvailable && $quote?->baseUsdtUnitAmount !== null;
        $detailIcon = $this->detailIcon($product);
        $lines = [
            $this->captions->icon($detailIcon).' '.$this->escapeText($product->name),
            '',
            $this->locale->translate(
                $customer,
                $showUsdtPricing ? 'catalog.detail.base_price_dual' : 'catalog.detail.base_price',
                [
                    'amount' => number_format($baseIdr, 0, ',', '.'),
                    ...($showUsdtPricing ? ['usdt' => $this->checkout->formatUsdt($quote->baseUsdtUnitAmount)] : []),
                ],
            ),
        ];
        if ($quote !== null) {
            foreach ($this->reachableTiers($quote, $availableStock) as $tier) {
                $active = $quote->appliedTierMinimum === $tier->minimumQuantity;
                $key = match ([$showUsdtPricing, $active]) {
                    [true, true] => 'catalog.detail.tier_active_dual',
                    [true, false] => 'catalog.detail.tier_dual',
                    [false, true] => 'catalog.detail.tier_active',
                    default => 'catalog.detail.tier',
                };
                $lines[] = $this->locale->translate($customer, $key, [
                    'minimum' => $tier->minimumQuantity,
                    'amount' => number_format($tier->priceAmount, 0, ',', '.'),
                    ...($showUsdtPricing ? ['usdt' => $this->checkout->formatUsdt((int) $tier->usdtPriceAmount)] : []),
                ]);
            }
        }
        if ($availableStock > 0) {
            $lines[] = $this->locale->translate($customer, 'catalog.stock').$availableStock;
        } else {
            $lines[] = $this->locale->translate($customer, 'catalog.status.sold_out');
        }
        if ($quantity !== null && $quote !== null) {
            $lines[] = '';
            $lines[] = $this->locale->translate($customer, 'catalog.detail.quantity', ['quantity' => $quantity]);
            if ($quote->hasAppliedTier()) {
                $lines[] = $this->locale->translate(
                    $customer,
                    $showUsdtPricing ? 'catalog.detail.effective_unit_dual' : 'catalog.detail.effective_unit',
                    [
                        'amount' => number_format($quote->effectiveIdrUnitAmount, 0, ',', '.'),
                        ...($showUsdtPricing ? ['usdt' => $this->checkout->formatUsdt((int) $quote->effectiveUsdtUnitAmount)] : []),
                    ],
                );
            }
            $lines[] = $this->locale->translate(
                $customer,
                $showUsdtPricing ? 'catalog.detail.subtotal_dual' : 'catalog.detail.subtotal',
                [
                    'amount' => number_format($quote->idrSubtotal, 0, ',', '.'),
                    ...($showUsdtPricing ? ['usdt' => $this->checkout->formatUsdt((int) $quote->usdtSubtotal)] : []),
                ],
            );
            $usdtSavings = $quote->usdtSavings();
            if ($quote->idrSavings() > 0 && $usdtSavings !== null && $usdtSavings > 0) {
                $lines[] = $this->locale->translate($customer, 'catalog.detail.savings_dual', [
                    'idr' => number_format($quote->idrSavings(), 0, ',', '.'),
                    'usdt' => $this->checkout->formatUsdt($usdtSavings),
                ]);
            } elseif ($quote->idrSavings() > 0) {
                $lines[] = $this->locale->translate($customer, 'catalog.detail.savings', [
                    'amount' => number_format($quote->idrSavings(), 0, ',', '.'),
                ]);
            } elseif ($usdtSavings !== null && $usdtSavings > 0) {
                $lines[] = $this->locale->translate($customer, 'catalog.detail.savings_usdt', [
                    'amount' => $this->checkout->formatUsdt($usdtSavings),
                ]);
            }
            if ($nextTier !== null && $nextTierQuote !== null) {
                $lines[] = '';
                $lines[] = $this->locale->translate($customer, 'catalog.detail.next_tier', [
                    'quantity' => $nextTier->minimumQuantity - $quantity,
                ]);
                $lines[] = $this->locale->translate(
                    $customer,
                    $showUsdtPricing ? 'catalog.detail.next_tier_total_dual' : 'catalog.detail.next_tier_total',
                    [
                        'quantity' => $nextTier->minimumQuantity,
                        'amount' => number_format($nextTierQuote->idrSubtotal, 0, ',', '.'),
                        ...($showUsdtPricing ? ['usdt' => $this->checkout->formatUsdt((int) $nextTierQuote->usdtSubtotal)] : []),
                    ],
                );
            }
        }
        $lines[] = '';
        $lines[] = $this->locale->translate($customer, 'catalog.details');
        $copy = LocalizedProductCopy::extract($product->getAttributes());
        $description = LocalizedProductCopy::resolve($copy, 'description', $this->locale->code($customer));
        $lines[] = trim($description['text']) === ''
            ? $this->captions->blockquote($this->locale->translate($customer, 'catalog.description_missing'))
            : '<blockquote>'.$this->content->html($product, $description['column']).'</blockquote>';
        $warrantyPolicy = LocalizedProductCopy::resolve($copy, 'warranty_policy', $this->locale->code($customer));
        if (trim($warrantyPolicy['text']) !== '') {
            $lines[] = '';
            $lines[] = '<blockquote>'.$this->escapeText($this->locale->translate($customer, 'catalog.replacement_refund_policy'))."\n".$this->content->html($product, $warrantyPolicy['column']).'</blockquote>';
        }
        if ($maintenance->active) {
            $lines[] = '';
            $lines[] = $this->escapeText($this->maintenanceNotice($customer, $maintenance));
            if ($availableStock < 1) {
                $lines[] = $this->locale->translate($customer, 'catalog.sold_out_guidance');
            }
        } elseif ($availableStock < 1) {
            $lines[] = '';
            $lines[] = $this->locale->translate($customer, 'catalog.sold_out_guidance');
        } else {
            $lines[] = '';
            $lines[] = $this->captions->blockquote($this->locale->qrisCrossBorderSupport($customer));
        }
        $this->sendProductMessage($customer, $chatId, $product, implode("\n", $lines), ['inline_keyboard' => $keyboard], $messageId);
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
        $lines[] = $this->locale->translate($customer, 'catalog.quantity.product').$this->captions->icon($this->detailIcon($product)).' '.$product->name;
        $lines[] = $this->locale->translate($customer, 'catalog.quantity.current', ['quantity' => $selection['quantity']]);
        $lines[] = $this->locale->translate($customer, 'catalog.quantity.available_stock').$availableStock;
        $lines[] = '';
        $lines[] = $this->locale->translate($customer, 'catalog.quantity.custom_instruction', ['example' => 5]);

        $productId = (int) $product->getKey();
        $this->sendProductMessage($customer, $chatId, $product, implode("\n", $lines), [
            'inline_keyboard' => [[[
                'text' => $this->locale->translate($customer, 'catalog.quantity.cancel'),
                'callback_data' => 'detail:qty:cancel:'.$productId.':'.$selection['interactionToken'],
            ]]],
        ], options: []);
    }

    /**
     * @param  array<string, mixed>  $keyboard
     * @param  array<string, mixed>  $options
     */
    private function sendProductMessage(Customer $customer, string $chatId, Product $product, string $text, array $keyboard, ?int $messageId = null, array $options = ['parse_mode' => 'HTML']): void
    {
        $telegramImagePath = $product->telegram_image_path ?? $product->family?->telegram_image_path;
        $photoUrl = $this->images->telegramUrl($telegramImagePath);
        if ($photoUrl === null) {
            $root = $messageId === null
                ? $this->roots->currentForChat($customer, $chatId)
                : $this->roots->match($customer, $chatId, $messageId);
            if ($root?->screen === TelegramNavigationScreen::Product) {
                $this->roots->render($root, $chatId, new TelegramNavigationSurface(
                    TelegramNavigationScreen::Product,
                    $this->locale->code($customer),
                    $text,
                    $keyboard,
                    $options,
                ));

                return;
            }

            $this->sendCatalogMessage($customer, $chatId, $text, $keyboard, $messageId, $options);

            return;
        }

        $surface = new TelegramNavigationSurface(
            TelegramNavigationScreen::Product,
            $this->locale->code($customer),
            $text,
            $keyboard,
            $options,
            $photoUrl,
        );
        $root = $messageId === null
            ? $this->roots->currentForChat($customer, $chatId)
            : $this->roots->match($customer, $chatId, $messageId);

        if ($root !== null) {
            $this->roots->render($root, $chatId, $surface);

            return;
        }

        if ((string) $customer->telegram_id !== $chatId) {
            if ($this->captions->fitsMediaCaption($text, $options)) {
                $this->bot->sendPhotoReference($chatId, $photoUrl, $text, $keyboard, $options);
            } else {
                $this->bot->sendMessage($chatId, $text, $keyboard, $options);
            }

            return;
        }

        $this->roots->establishProduct($customer, $chatId, $surface);
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

            $product = Product::query()->with(['quantityPriceTiers'])->withAvailability()->publicVisible()->whereKey($selection['productId'])->first();
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

            if ($this->safeQuote($product, (int) $quantity) === null) {
                $this->session->forget($customer);
                $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'catalog.selection.unavailable'));

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
            $this->sendCatalogMessage($customer, $chatId, $this->locale->translate($customer, 'catalog.category.unavailable'), [
                'inline_keyboard' => [[[
                    'text' => $this->locale->translate($customer, 'catalog.back_categories'),
                    'callback_data' => 'catalog:categories',
                ]]],
            ], $messageId);

            return;
        }

        $requestedPage = max(1, $page);
        $query = CatalogBrowseQuery::fromValues(
            category: $categoryId,
            page: $requestedPage,
            perPage: self::PRODUCTS_PER_PAGE,
        );
        $result = $this->cachedPublicBrowse($query);
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
            $this->sendCatalogMessage($customer, $chatId, $this->locale->translate($customer, 'search.prompt'), [], $messageId);

            return;
        }

        $requestedPage = max(1, $page);
        $query = CatalogBrowseQuery::fromValues(
            search: $search,
            page: $requestedPage,
            perPage: self::PRODUCTS_PER_PAGE,
        );
        $result = $this->cachedPublicBrowse($query);
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

    private function cachedPublicBrowse(CatalogBrowseQuery $query): CatalogBrowseResult
    {
        if ($query->search !== '') {
            return $this->browse->browsePublic($query);
        }

        return CatalogProjectionPayload::unpack(CatalogBrowseResult::class, $this->projectionCache->remember(
            'browse',
            [
                'search' => $query->search,
                'category' => $query->category,
                'sort' => $query->sort,
                'page' => $query->page,
                'per_page' => $query->perPage,
            ],
            fn (): array => CatalogProjectionPayload::pack($this->browse->browsePublic($query)),
        ));
    }

    private function cachedCategoryOptions(): CatalogCategoryOptions
    {
        return CatalogProjectionPayload::unpack(CatalogCategoryOptions::class, $this->projectionCache->remember(
            'category-options',
            ['with_products_only' => true],
            fn (): array => CatalogProjectionPayload::pack($this->browse->publicCategoryOptions(withProductsOnly: true)),
        ));
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
        bool $establishFreshRoot = false,
        bool $removeLegacyReplyKeyboard = false,
        bool $refresh = false,
    ): void {
        $maintenance = $this->maintenance->current();
        $html = $mode !== 'catalog';
        $title = $this->locale->translate(
            $customer,
            $titleKey,
            $search === null ? [] : ['keyword' => $search],
        );
        if ($search !== null && ! str_contains($title, $search)) {
            $title .= $search;
        }
        $pagination = $result->pagination;
        $lines = [$html ? $this->escapeText($title) : $title];
        if ($categoryTitle !== null) {
            $categoryLabel = $this->locale->translate($customer, 'catalog.category.label').$categoryTitle;
            $lines[] = $html ? $this->escapeText($categoryLabel) : $categoryLabel;
        }
        if ($pagination->hasPages()) {
            $pageLine = $this->locale->translate($customer, 'catalog.family.page', [
                'page' => $pagination->currentPage,
                'pages' => $pagination->lastPage,
            ]);
            $lines[] = $html ? $this->escapeText($pageLine) : $pageLine;
        }
        if ($categoryDescription !== null && trim($categoryDescription) !== '') {
            $lines[] = '';
            $lines[] = $html ? $this->escapeText($categoryDescription) : $categoryDescription;
        }
        if ($maintenance->active && $result->entries !== []) {
            $lines[] = '';
            $notice = $this->maintenanceNotice($customer, $maintenance);
            $lines[] = $html ? $this->escapeText($notice) : $notice;
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
            $this->sendCatalogMessage(
                $customer,
                $chatId,
                $message,
                ['inline_keyboard' => $this->browseNavigationKeyboard($customer, $mode)],
                $messageId,
                establishFreshRoot: $establishFreshRoot,
                removeLegacyReplyKeyboard: $removeLegacyReplyKeyboard,
                refresh: $refresh,
            );

            return;
        }

        if ($mode === 'catalog') {
            $lines[] = '';
            $lines[] = $this->locale->translate($customer, 'catalog.family.choose');
        } else {
            $lines[] = '';
            $lines[] = $this->escapeText($this->locale->translate($customer, 'catalog.category.choose_product'));
            foreach ($result->entries as $index => $entry) {
                $lines[] = '';
                $lines[] = $this->entryBlock($customer, $entry, $index + 1);
            }
        }

        if ($mode === 'search' && $pagination->hasPages()) {
            $lines[] = '';
            $lines[] = $this->escapeText($this->locale->translate($customer, 'search.more'));
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

        $this->sendCatalogMessage(
            $customer,
            $chatId,
            implode("\n", $lines),
            ['inline_keyboard' => $keyboard],
            $messageId,
            options: $html ? ['parse_mode' => 'HTML'] : [],
            establishFreshRoot: $establishFreshRoot,
            removeLegacyReplyKeyboard: $removeLegacyReplyKeyboard,
            refresh: $refresh,
        );
    }

    /**
     * Inline navigation edits the current root. Text catalog entry points
     * establish a new root at the chat tail when one already exists; the
     * legacy edit-or-send path remains for groups and chats without a root.
     */
    private function sendCatalogMessage(
        Customer $customer,
        string $chatId,
        string $text,
        array $replyMarkup = [],
        ?int $messageId = null,
        array $options = [],
        bool $establishFreshRoot = false,
        bool $removeLegacyReplyKeyboard = false,
        bool $refresh = false,
    ): void {
        $root = $messageId === null
            ? $this->roots->currentForChat($customer, $chatId)
            : $this->roots->match($customer, $chatId, $messageId);

        if ($establishFreshRoot
            && $messageId === null
            && ($root !== null || $removeLegacyReplyKeyboard)) {
            $this->roots->establish(
                $customer,
                $chatId,
                new TelegramNavigationSurface(
                    TelegramNavigationScreen::Catalog,
                    $this->locale->code($customer),
                    $text,
                    $replyMarkup,
                    $options,
                ),
                removeLegacyReplyKeyboard: $removeLegacyReplyKeyboard,
            );

            return;
        }

        if ($root !== null) {
            $this->roots->render($root, $chatId, new TelegramNavigationSurface(
                TelegramNavigationScreen::Catalog,
                $this->locale->code($customer),
                $text,
                $replyMarkup,
                $options,
            ), refresh: $refresh);

            return;
        }
        if ($refresh && $messageId !== null) {
            $this->bot->deleteMessage($chatId, $messageId);
            if ((string) $customer->telegram_id === $chatId) {
                $this->roots->establishText($customer, $chatId, new TelegramNavigationSurface(
                    TelegramNavigationScreen::Catalog,
                    $this->locale->code($customer),
                    $text,
                    $replyMarkup,
                    $options,
                ));
            } else {
                $this->bot->sendMessage($chatId, $text, $replyMarkup, $options);
            }

            return;
        }

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
                $singleOption = $family->optionCount === 1 ? ($family->members[0] ?? null) : null;
                if ($singleOption instanceof CatalogProductReadModel) {
                    $callback = 'product:'.$singleOption->id.$this->originSuffix($originToken);
                } else {
                    $callback = 'family:options:'.$this->familyTokens->options($family->id, 1, $listPage)
                        .$this->originSuffix($originToken);
                }
                $icon = $singleOption instanceof CatalogProductReadModel
                    ? $this->catalogIcon($singleOption)
                    : $this->familyIcon($family);
                $buttons[] = [
                    'text' => $mode === 'catalog'
                        ? $icon->buttonText(($singleOption instanceof CatalogProductReadModel ? $singleOption->name : $family->name).$this->familyAvailabilitySuffix($customer, $family))
                        : $this->locale->translate($customer, 'catalog.family.number_button', ['number' => $number]),
                    ...($mode === 'catalog' ? $icon->buttonFields() : []),
                    'callback_data' => $callback,
                    'style' => $family->isAvailable ? 'primary' : 'danger',
                ];
            } elseif ($product !== null) {
                $icon = $this->catalogIcon($product);
                $buttons[] = [
                    'text' => $mode === 'catalog'
                        ? $icon->buttonText($product->name.' · '.$this->productAvailabilityLabel(
                            $customer,
                            $product->isAvailable,
                            $product->availableStock,
                        ))
                        : $this->locale->translate($customer, 'catalog.family.number_button', ['number' => $number]),
                    ...($mode === 'catalog' ? $icon->buttonFields() : []),
                    'callback_data' => 'product:'.$product->id.$this->originSuffix($originToken),
                    'style' => $product->isAvailable ? 'primary' : 'danger',
                ];
            }
        }

        return array_map(static fn (array $button): array => [$button], $buttons);
    }

    private function entryBlock(Customer $customer, CatalogBrowseEntry $entry, int $number): string
    {
        if ($entry->family !== null) {
            $family = $entry->family;
            $singleOption = $family->optionCount === 1 ? ($family->members[0] ?? null) : null;
            $icon = $singleOption instanceof CatalogProductReadModel
                ? $this->catalogIcon($singleOption)
                : $this->familyIcon($family);

            return trim($this->locale->translate($customer, 'catalog.family.list_item', [
                'number' => $this->escapeText(sprintf('%02d.', $number)),
                'icon' => $this->captions->icon($icon),
                'name' => $this->escapeText($singleOption instanceof CatalogProductReadModel ? $singleOption->name : $family->name),
                'availability' => $this->escapeText($this->familyAvailabilitySuffix($customer, $family)),
            ]));
        }

        $product = $entry->product;
        if ($product === null) {
            return $number.'.';
        }
        $icon = $this->catalogIcon($product);

        return $number.'. '.$this->captions->icon($icon).' '.$this->escapeText($product->name)."\n"
            .$this->escapeText($this->locale->translate($customer, 'catalog.family.standalone_summary', [
                'price' => number_format($product->priceAmount, 0, ',', '.'),
                'stock' => $product->availableStock,
            ]));
    }

    private function familyAvailabilitySuffix(Customer $customer, CatalogFamilyReadModel $family): string
    {
        if ($family->optionCount === 1) {
            $member = $family->members[0] ?? null;
            if ($member instanceof CatalogProductReadModel) {
                return ' · '.$this->productAvailabilityLabel($customer, $member->isAvailable, $member->availableStock);
            }
        }

        if ($family->optionCount > 1 && $family->availableOptionCount > 0) {
            return ' '.$this->locale->translate($customer, 'catalog.family.button_plans', [
                'count' => $family->optionCount,
            ]);
        }

        return ' · '.$this->locale->translate($customer, 'catalog.family.button_sold_out');
    }

    private function productAvailabilityLabel(Customer $customer, bool $isAvailable, int $availableStock): string
    {
        return $this->locale->translate(
            $customer,
            $isAvailable ? 'catalog.family.button_stock' : 'catalog.family.button_sold_out',
            ['stock' => $availableStock],
        );
    }

    private function familyOptionBlock(Customer $customer, CatalogProductReadModel $member, int $number, CatalogIcon $icon): string
    {
        $lines = [
            sprintf('%02d. %s %s', $number, $this->captions->icon($icon), $this->escapeText($member->label)),
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

        return '<blockquote>'.implode("\n", $lines).'</blockquote>';
    }

    private function safeQuote(Product $product, int $quantity): ?ProductPriceQuote
    {
        try {
            return $this->pricing->quote($product, $quantity);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** @return list<ProductPriceTierQuote> */
    private function reachableTiers(ProductPriceQuote $quote, int $availableStock): array
    {
        return array_values(array_filter(
            $quote->tiers(),
            static fn (ProductPriceTierQuote $tier): bool => $tier->minimumQuantity <= $availableStock,
        ));
    }

    private function nextReachableTier(ProductPriceQuote $quote, int $availableStock): ?ProductPriceTierQuote
    {
        $nextTier = $quote->nextTier;

        return $nextTier !== null && $nextTier->minimumQuantity <= $availableStock
            ? $nextTier
            : null;
    }

    /**
     * Escape HTML for catalog copy that may already carry a rendered icon
     * wrapper. `TelegramTranslationCatalog` wraps a pictographic translation as
     * `<tg-emoji … data-omp-icon="1">`, so escaping it with `htmlspecialchars`
     * alone would make the customer read the tag instead of the icon.
     */
    private function escapeText(string $value): string
    {
        return $this->captions->escapeTextPreservingIcons($value);
    }

    private function catalogIcon(CatalogProductReadModel $product): CatalogIcon
    {
        return $this->icons->resolve($product->icon, $product->customEmojiId);
    }

    /**
     * The detail header uses the same effective Product icon as its Plan row:
     * the whole Product override, otherwise the family pair.
     */
    private function detailIcon(Product $product): CatalogIcon
    {
        $selection = $this->familyIcons->forProduct($product);

        return $this->icons->resolve($selection->icon, $selection->customEmojiId);
    }

    /**
     * The family owns its heading icon. Member overrides affect only their own
     * Plan rows, buttons, and detail headers.
     */
    private function familyIcon(CatalogFamilyReadModel $family): CatalogIcon
    {
        return $this->icons->resolve($family->icon, $family->customEmojiId);
    }

    /**
     * Family navigation for a Product opened without an explicit origin: a
     * Product that belongs to a public family returns to that family's plan
     * list instead of the category product list.
     *
     * @return array{familyId?: int, familyPage?: int, familyListPage?: int}
     */
    private function familyNavigationFromProduct(Product $product): array
    {
        $familyId = $product->product_family_id === null ? 0 : (int) $product->product_family_id;
        if ($familyId < 1) {
            return [];
        }

        try {
            $family = $this->browse->showPublicFamily($familyId);
        } catch (ModelNotFoundException) {
            return [];
        }

        // A family with a single public Plan keeps opening that Product Detail
        // directly, so it has no plan list to return to.
        if (count($family->members) < 2) {
            return [];
        }

        $index = collect($family->members)->search(
            fn (CatalogProductReadModel $member): bool => $member->id === (int) $product->getKey(),
        );
        $page = is_int($index) ? intdiv($index, self::PRODUCTS_PER_PAGE) + 1 : 1;

        return ['familyId' => $familyId, 'familyPage' => $page, 'familyListPage' => 1];
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
            return [[
                [
                    ...$this->uiIcons->apply('message.search', $this->locale->translate($customer, 'catalog.family.filter')),
                    'callback_data' => 'catalog:categories',
                ],
                [
                    ...$this->uiIcons->apply('message.refresh', $this->locale->translate($customer, 'catalog.family.refresh')),
                    'callback_data' => 'catalog:refresh',
                ],
                [
                    ...$this->uiIcons->apply('menu.back', $this->locale->translate($customer, 'catalog.family.back_menu')),
                    'callback_data' => 'family:back:menu',
                ],
            ]];
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

    private function sendStaleCatalog(
        Customer $customer,
        string $chatId,
        ?int $messageId = null,
        bool $establishFreshRoot = false,
        bool $removeLegacyReplyKeyboard = false,
    ): void {
        $this->sendCatalogMessage($customer, $chatId, $this->locale->translate($customer, 'catalog.family.stale'), [
            'inline_keyboard' => [[[
                'text' => $this->locale->translate($customer, 'catalog.family.back_menu'),
                'callback_data' => 'family:back:menu',
            ]]],
        ], $messageId, [], $establishFreshRoot, $removeLegacyReplyKeyboard);
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
    /** @return array{familyId?: int, familyPage?: int, familyListPage?: int, familyOriginToken?: string, originToken?: string} */
    private function navigationFromSelection(?array $selection): array
    {
        if (! is_array($selection)) {
            return [];
        }

        $navigation = [];
        if (is_int($selection['familyId'] ?? null) && is_int($selection['familyPage'] ?? null)) {
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
        }
        if (is_string($selection['originToken'] ?? null)
            && preg_match('/\A[a-f0-9]{16}\z/D', $selection['originToken']) === 1) {
            $navigation['originToken'] = $selection['originToken'];
        }

        return $navigation;
    }

    /**
     * The list a catalog entry was rendered from, carried into the callbacks of
     * that entry so the screen it opens can return to the exact list. A missing
     * or malformed token leaves that destination with its own fallback.
     */
    private function originSuffix(?string $originToken): string
    {
        return is_string($originToken) && preg_match('/\A[a-f0-9]{16}\z/D', $originToken) === 1
            ? ':o'.$originToken
            : '';
    }

    /**
     * The back callback for a Product Detail opened straight from a browse list:
     * a single-plan family or a standalone Product, which skip the plan list. The
     * stateless origin token resolves to the list the customer tapped, exactly
     * like the plan list back button, so the detail does not detour through the
     * category list of the Product.
     *
     * @param  array<string, mixed>|null  $navigation
     */
    private function originBackCallback(?array $navigation): ?string
    {
        $token = $navigation['originToken'] ?? null;
        if (! is_string($token) || preg_match('/\A[a-f0-9]{16}\z/D', $token) !== 1) {
            return null;
        }

        // The page segment stays unused: the origin token resolves to the page
        // the list was rendered with.
        return 'family:list:1:o'.$token;
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
}
