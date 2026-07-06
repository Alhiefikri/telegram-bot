<?php

namespace Tests\Support;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStockItem;

trait StorefrontProductScenarios
{
    protected function availableProduct(array $attributes = []): Product
    {
        return Product::factory()->create(array_merge([
            'stock' => 3,
            'reserved_stock' => 0,
            'is_active' => true,
        ], $attributes));
    }

    protected function unavailableProduct(array $attributes = []): Product
    {
        return Product::factory()->create(array_merge([
            'stock' => 1,
            'reserved_stock' => 1,
            'is_active' => true,
        ], $attributes));
    }

    protected function availableProductWithCredentials(
        array $secrets = ['ready-account@example.test|pass'],
        array $attributes = [],
    ): Product {
        $product = $this->availableProduct(array_merge([
            'stock' => max(count($secrets), 1),
            'reserved_stock' => 0,
        ], $attributes));

        foreach ($secrets as $secret) {
            ProductStockItem::create([
                'product_id' => $product->id,
                'secret' => $secret,
            ]);
        }

        return $product->fresh();
    }

    protected function credentialManagedProductWithClaimedCredentials(
        array $secrets = ['claimed-account@example.test|pass'],
        array $attributes = [],
    ): Product {
        $product = $this->availableProduct(array_merge([
            'stock' => max(count($secrets), 1),
            'reserved_stock' => 0,
        ], $attributes));

        foreach ($secrets as $secret) {
            $claimedItem = OrderItem::factory()->create(['product_id' => $product->id]);
            ProductStockItem::create([
                'product_id' => $product->id,
                'order_item_id' => $claimedItem->id,
                'secret' => $secret,
            ]);
        }

        return $product->fresh();
    }
}
