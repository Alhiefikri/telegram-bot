<?php

namespace App\Services\Checkout;

use App\Models\Product;
use App\Services\Pricing\ProductPriceQuote;
use App\Services\Pricing\ProductQuantityPricing;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The single pricing snapshot shared by Order creation and payment assembly.
 *
 * Product and tier reads happen before construction, while checkout Product
 * locks are held. Once built, this value object contains only immutable quote
 * values and cannot observe later catalog changes.
 */
final readonly class CheckoutPricingSnapshot
{
    /**
     * @var array<int, ProductPriceQuote>
     */
    public array $quotes;

    public int $idrTotal;

    public ?int $usdtTotal;

    /**
     * @param  array<int, ProductPriceQuote>  $quotes
     */
    public function __construct(array $quotes)
    {
        if ($quotes === []) {
            throw new InvalidArgumentException('A checkout pricing snapshot requires at least one quote.');
        }

        $normalizedQuotes = [];
        $idrTotal = 0;
        $usdtTotal = 0;
        $hasCompleteUsdtTotal = true;

        foreach ($quotes as $productId => $quote) {
            $productId = (int) $productId;
            if ($productId < 1 || ! $quote instanceof ProductPriceQuote || $quote->productId !== $productId) {
                throw new InvalidArgumentException('A checkout pricing snapshot contains an invalid Product quote.');
            }

            if (array_key_exists($productId, $normalizedQuotes)) {
                throw new InvalidArgumentException('A checkout pricing snapshot cannot contain duplicate Product quotes.');
            }

            $normalizedQuotes[$productId] = $quote;
            $idrTotal = $this->add($idrTotal, $quote->idrSubtotal, 'IDR');

            if ($quote->usdtSubtotal === null) {
                $hasCompleteUsdtTotal = false;
            } elseif ($hasCompleteUsdtTotal) {
                $usdtTotal = $this->add($usdtTotal, $quote->usdtSubtotal, 'USDT');
            }
        }

        $this->quotes = $normalizedQuotes;
        $this->idrTotal = $idrTotal;
        $this->usdtTotal = $hasCompleteUsdtTotal ? $usdtTotal : null;
    }

    /**
     * Quote every Cart Item once after the caller has acquired Product locks.
     *
     * @param  Collection<int, object>  $items
     * @param  Collection<int, Product>  $products
     */
    public static function fromCartItems(
        Collection $items,
        Collection $products,
        ProductQuantityPricing $pricing,
    ): self {
        if ($items->isEmpty()) {
            throw new InvalidArgumentException('A checkout pricing snapshot requires at least one Cart Item.');
        }

        $quotes = [];
        foreach ($items as $item) {
            $productId = (int) ($item->product_id ?? 0);
            if ($productId < 1) {
                throw new InvalidArgumentException('A Cart Item requires a valid Product.');
            }

            $product = $products->get($productId);
            if (! $product instanceof Product) {
                $product = $products->first(
                    static fn (mixed $candidate): bool => $candidate instanceof Product
                        && (int) $candidate->getKey() === $productId,
                );
            }
            if (! $product instanceof Product) {
                throw new InvalidArgumentException('A checkout Cart Item references a missing Product.');
            }

            if (array_key_exists($productId, $quotes)) {
                throw new InvalidArgumentException('A checkout Cart cannot contain duplicate Product quotes.');
            }

            $quotes[$productId] = $pricing->quote($product, (int) ($item->quantity ?? 0));
        }

        return new self($quotes);
    }

    public function quoteForProduct(int $productId): ProductPriceQuote
    {
        if (! array_key_exists($productId, $this->quotes)) {
            throw new InvalidArgumentException('The checkout pricing snapshot has no quote for this Product.');
        }

        return $this->quotes[$productId];
    }

    public function quoteForItem(object $item): ProductPriceQuote
    {
        return $this->quoteForProduct((int) ($item->product_id ?? 0));
    }

    /**
     * @return list<ProductPriceQuote>
     */
    public function quotes(): array
    {
        return array_values($this->quotes);
    }

    public function idrTotal(): int
    {
        return $this->idrTotal;
    }

    public function usdtTotal(): ?int
    {
        return $this->usdtTotal;
    }

    private function add(int $total, int $amount, string $currency): int
    {
        if (($amount > 0 && $total > PHP_INT_MAX - $amount)
            || ($amount < 0 && $total < PHP_INT_MIN - $amount)) {
            throw new InvalidArgumentException("{$currency} checkout total exceeds the supported integer range.");
        }

        return $total + $amount;
    }
}
