<?php

namespace App\Storefront;

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
        return new self(
            productType: self::text($product->product_type),
            accessDuration: self::text($product->access_duration),
            region: self::text($product->region),
            accountType: self::text($product->account_type),
            deviceLimit: self::text($product->device_limit),
            includedItems: self::lines($product->included_items),
            limitations: self::lines($product->limitations),
            fulfillmentExpectation: self::text($product->fulfillment_expectation),
            warrantyPolicy: self::text($product->warranty_policy),
            supportPolicy: self::text($product->support_policy),
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
