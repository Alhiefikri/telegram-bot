<?php

namespace App\Console\Commands;

use App\Services\Admin\ProductImageOptimizer;
use Illuminate\Console\Command;

final class OptimizeStorefrontImages extends Command
{
    protected $signature = 'storefront:optimize-images';

    protected $description = 'Downscale legacy product images for the public storefront.';

    public function handle(ProductImageOptimizer $images): int
    {
        $optimized = $images->optimizeStoredProductImages();

        $this->info("Storefront product images optimized: {$optimized}.");

        return self::SUCCESS;
    }
}
