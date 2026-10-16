<?php

namespace App\Services\CustomerApi;

use App\Models\Product;
use App\Services\Catalog\CatalogFamilyIconResolver;
use App\Services\Pricing\ProductPriceQuote;
use App\Services\Pricing\ProductQuantityPricing;
use App\Services\Pricing\ResellerPricingContext;
use App\Services\UsdtBalance\UsdtMicroAmount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class CustomerApiCatalog
{
    private const COLUMNS = [
        'id', 'category_id', 'product_family_id', 'family_option_label', 'name', 'icon', 'custom_emoji_id',
        'description', 'description_ms', 'description_en', 'product_type', 'access_duration', 'region',
        'account_type', 'device_limit', 'included_items', 'limitations', 'fulfillment_expectation',
        'warranty_policy', 'warranty_policy_ms', 'warranty_policy_en', 'support_policy', 'delivery_note',
        'usage_guide_title', 'usage_guide_url', 'price_amount', 'usdt_price_amount', 'reseller_price_amount',
        'reseller_usdt_price_amount', 'stock', 'reserved_stock',
    ];

    public function __construct(private ProductQuantityPricing $pricing, private CatalogFamilyIconResolver $icons) {}

    public function page(CustomerApiPrincipal $principal, array $filters): array
    {
        $query = $this->query()->where('products.id', '>', $filters['after']);
        foreach (['category_id', 'product_family_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (($filters['available'] ?? null) === 'true') {
            $query->availableForPurchase();
        }
        if (($filters['available'] ?? null) === 'false') {
            $query->whereNot(fn (Builder $available) => $available->availableForPurchase());
        }
        $products = $query->orderBy('products.id')->limit($filters['limit'] + 1)->get();
        $hasMore = $products->count() > $filters['limit'];
        $products = $products->take($filters['limit']);

        return ['data' => $this->serialize($products, $filters['quantity'], $principal),
            'pagination' => ['next_cursor' => $hasMore ? (string) $products->last()->id : null, 'has_more' => $hasMore]];
    }

    public function detail(CustomerApiPrincipal $principal, int $id, int $quantity, ?string $currency): array
    {
        $product = $this->query()->whereKey($id)->first();
        if ($product === null) {
            throw new CustomerApiFailure(404, 'not_found', 'Resource not found.');
        }
        if ($currency === 'USD' && $product->usdt_price_amount === null) {
            throw new CustomerApiFailure(422, 'currency_unavailable', 'This Product has no USD price.');
        }

        return ['data' => $this->serialize(collect([$product]), $quantity, $principal)[0]];
    }

    private function query(): Builder
    {
        return Product::query()->select(self::COLUMNS)->publicVisible()->withAvailability()->with([
            'category:id,name,slug,description',
            'family:id,name,slug,description,icon,custom_emoji_id',
            'quantityPriceTiers:id,product_id,minimum_quantity,price_amount,usdt_price_amount',
        ]);
    }

    private function serialize(Collection $products, int $quantity, CustomerApiPrincipal $principal): array
    {
        try {
            $quotes = $this->pricing->quoteMany($products, $quantity, ResellerPricingContext::forAuthenticatedApiRequest($principal->customerId));
        } catch (InvalidArgumentException) {
            throw new CustomerApiFailure(422, 'quote_unavailable', 'The requested quantity cannot be quoted safely.');
        }
        $icons = $this->icons->forProducts($products);

        return $products->map(function (Product $product) use ($quotes, $icons, $quantity): array {
            $quote = $quotes[$product->id];
            $stock = $product->availableStock();
            $metadata = [];
            foreach (['product_type', 'access_duration', 'region', 'account_type', 'device_limit', 'included_items',
                'limitations', 'fulfillment_expectation', 'warranty_policy', 'warranty_policy_ms', 'warranty_policy_en',
                'support_policy', 'delivery_note', 'usage_guide_title', 'usage_guide_url'] as $field) {
                $metadata[$field] = $product->{$field};
            }

            return [
                'id' => (string) $product->id, 'name' => $product->name, 'plan_label' => $product->family_option_label,
                'description' => $product->description, 'description_ms' => $product->description_ms, 'description_en' => $product->description_en,
                'icon' => $icons[$product->id]->icon, 'custom_emoji_id' => $icons[$product->id]->customEmojiId,
                'category' => $product->category === null ? null : ['id' => (string) $product->category->id,
                    'name' => $product->category->name, 'slug' => $product->category->slug, 'description' => $product->category->description],
                'family' => $product->family === null ? null : ['id' => (string) $product->family->id,
                    'name' => $product->family->name, 'slug' => $product->family->slug, 'description' => $product->family->description,
                    'icon' => $product->family->icon, 'custom_emoji_id' => $product->family->custom_emoji_id],
                'stock' => ['available' => $stock, 'is_available' => $stock > 0, 'quantity_available' => $stock >= $quantity],
                'quantity' => $quantity, 'quotes' => [$this->money($quote, false), $this->money($quote, true)],
                'input_requirements' => [], 'metadata' => $metadata, 'informational' => true,
            ];
        })->values()->all();
    }

    private function money(ProductPriceQuote $quote, bool $usd): array
    {
        $base = $usd ? $quote->baseUsdtUnitAmount : $quote->baseIdrUnitAmount;
        $effective = $usd ? $quote->effectiveUsdtUnitAmount : $quote->effectiveIdrUnitAmount;
        $total = $usd ? $quote->usdtSubtotal : $quote->idrSubtotal;
        $regularTotal = $usd ? $quote->baseUsdtSubtotal : $quote->baseIdrSubtotal;
        $format = static fn (?int $amount): ?string => $amount === null ? null : ($usd ? UsdtMicroAmount::formatUnits($amount) : (string) $amount);

        return ['currency' => $usd ? 'USD' : 'IDR', 'eligible' => $base !== null,
            'regular_unit' => $format($base), 'effective_unit' => $format($effective),
            'regular_total' => $format($regularTotal), 'total' => $format($total)];
    }
}
