<?php

namespace App\Storefront;

use App\Catalog\CatalogProductReadModel;
use App\Models\Product;

final readonly class StorefrontProductMerchandising
{
    /**
     * @param  list<string>  $includedItems
     * @param  list<string>  $limitations
     */
    public function __construct(
        public ?string $productType,
        public ?string $accessDuration,
        public ?string $region,
        public ?string $accountType,
        public ?string $deviceLimit,
        public array $includedItems,
        public array $limitations,
        public ?string $fulfillmentExpectation,
        public ?string $warrantyPolicy,
        public ?string $supportPolicy,
    ) {}

    public static function fromProduct(Product $product): self
    {
        return self::fromValues([
            'product_type' => $product->product_type,
            'access_duration' => $product->access_duration,
            'region' => $product->region,
            'account_type' => $product->account_type,
            'device_limit' => $product->device_limit,
            'included_items' => $product->included_items,
            'limitations' => $product->limitations,
            'fulfillment_expectation' => $product->fulfillment_expectation,
            'warranty_policy' => $product->warranty_policy,
            'support_policy' => $product->support_policy,
        ]);
    }

    public static function fromReadModel(CatalogProductReadModel $product): self
    {
        return self::fromValues($product->merchandising);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function fromValues(array $values): self
    {
        return new self(
            productType: self::text($values['product_type'] ?? null),
            accessDuration: self::text($values['access_duration'] ?? null),
            region: self::text($values['region'] ?? null),
            accountType: self::text($values['account_type'] ?? null),
            deviceLimit: self::text($values['device_limit'] ?? null),
            includedItems: self::lines($values['included_items'] ?? null),
            limitations: self::lines($values['limitations'] ?? null),
            fulfillmentExpectation: self::text($values['fulfillment_expectation'] ?? null),
            warrantyPolicy: self::text($values['warranty_policy'] ?? null),
            supportPolicy: self::text($values['support_policy'] ?? null),
        );
    }

    public function hasDetails(): bool
    {
        return $this->productType !== null
            || $this->accessDuration !== null
            || $this->region !== null
            || $this->accountType !== null
            || $this->deviceLimit !== null
            || $this->includedItems !== []
            || $this->limitations !== []
            || $this->fulfillmentExpectation !== null
            || $this->warrantyPolicy !== null
            || $this->supportPolicy !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicPayload(): array
    {
        return [
            'productType' => $this->productType,
            'accessDuration' => $this->accessDuration,
            'region' => $this->region,
            'accountType' => $this->accountType,
            'deviceLimit' => $this->deviceLimit,
            'includedItems' => $this->includedItems,
            'limitations' => $this->limitations,
            'fulfillmentExpectation' => $this->fulfillmentExpectation,
            'warrantyPolicy' => $this->warrantyPolicy,
            'supportPolicy' => $this->supportPolicy,
            'hasDetails' => $this->hasDetails(),
        ];
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<string>
     */
    private static function lines(mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        $lines = preg_split('/\R/u', trim($value)) ?: [];
        $lines = array_map(static fn (string $line): string => trim($line), $lines);
        $lines = array_filter($lines, static fn (string $line): bool => $line !== '');

        return array_values($lines);
    }
}
