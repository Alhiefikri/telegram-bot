<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductFamily;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SeedDemoCatalog extends Command
{
    protected $signature = 'catalog:seed-demo';

    protected $description = 'Seed a representative Product Family catalog for local Telegram testing.';

    /**
     * @var list<array{slug:string,name:string,description:string,sort_order:int,products:list<array{name:string,label:string,description:string,price:int,stock:int}>}>
     */
    private const DEMO_FAMILIES = [
        [
            'slug' => 'canva',
            'name' => 'Canva',
            'description' => 'Template and design subscription packages.',
            'sort_order' => 10,
            'products' => [
                ['name' => 'Canva Pro 1 Bulan', 'label' => '1 Bulan', 'description' => 'Canva Pro access for one month.', 'price' => 15000, 'stock' => 5],
                ['name' => 'Canva Pro 1 Tahun', 'label' => '1 Tahun', 'description' => 'Canva Pro access for one year.', 'price' => 75000, 'stock' => 2],
            ],
        ],
        [
            'slug' => 'capcut',
            'name' => 'CapCut',
            'description' => 'Premium video editing access.',
            'sort_order' => 20,
            'products' => [
                ['name' => 'CapCut Pro 1 Bulan', 'label' => '1 Bulan', 'description' => 'CapCut Pro access for one month.', 'price' => 20000, 'stock' => 4],
            ],
        ],
        [
            'slug' => 'chatgpt',
            'name' => 'ChatGPT',
            'description' => 'AI assistant subscription packages.',
            'sort_order' => 30,
            'products' => [
                ['name' => 'ChatGPT Plus 1 Bulan', 'label' => '1 Bulan', 'description' => 'ChatGPT Plus access for one month.', 'price' => 45000, 'stock' => 3],
            ],
        ],
        [
            'slug' => 'gemini-ai',
            'name' => 'Gemini AI',
            'description' => 'Google AI subscription packages.',
            'sort_order' => 40,
            'products' => [
                ['name' => 'Gemini AI Pro 1 Bulan', 'label' => '1 Bulan', 'description' => 'Gemini AI Pro access for one month.', 'price' => 40000, 'stock' => 3],
            ],
        ],
        [
            'slug' => 'genspark-ai',
            'name' => 'Genspark AI',
            'description' => 'AI productivity subscription packages.',
            'sort_order' => 50,
            'products' => [
                ['name' => 'Genspark AI 1 Bulan', 'label' => '1 Bulan', 'description' => 'Genspark AI access for one month.', 'price' => 35000, 'stock' => 3],
            ],
        ],
        [
            'slug' => 'netflix',
            'name' => 'Netflix',
            'description' => 'Streaming subscription packages.',
            'sort_order' => 60,
            'products' => [
                ['name' => 'Netflix Premium (No VPN)', 'label' => 'Premium (No VPN)', 'description' => 'Netflix Premium profile without VPN.', 'price' => 25000, 'stock' => 5],
                ['name' => 'Netflix Premium (VPN)', 'label' => 'Premium (VPN)', 'description' => 'Netflix Premium profile with VPN support.', 'price' => 15000, 'stock' => 5],
            ],
        ],
        [
            'slug' => 'surfshark',
            'name' => 'Surfshark',
            'description' => 'VPN subscription packages.',
            'sort_order' => 70,
            'products' => [
                ['name' => 'Surfshark VPN 1 Bulan', 'label' => '1 Bulan', 'description' => 'Surfshark VPN access for one month.', 'price' => 30000, 'stock' => 5],
            ],
        ],
    ];

    public function handle(): int
    {
        if (! Schema::hasTable('product_families') || ! Schema::hasTable('products')) {
            $this->error('Product Family migrations are missing. Run "php artisan migrate" first.');

            return self::FAILURE;
        }
        $createdFamilies = 0;
        $createdProducts = 0;

        DB::transaction(function () use (&$createdFamilies, &$createdProducts): void {
            foreach (self::DEMO_FAMILIES as $familyData) {
                $family = ProductFamily::withTrashed()->firstOrNew(['slug' => $familyData['slug']]);
                $createdFamilies += $family->exists ? 0 : 1;
                $family->fill([
                    'name' => $familyData['name'],
                    'description' => $familyData['description'],
                    'sort_order' => $familyData['sort_order'],
                ]);
                if ($family->trashed()) {
                    $family->restore();
                }
                $family->save();

                foreach ($familyData['products'] as $sortOrder => $productData) {
                    $product = Product::withTrashed()->firstOrNew([
                        'product_family_id' => $family->id,
                        'name' => $productData['name'],
                    ]);
                    $createdProducts += $product->exists ? 0 : 1;
                    $product->fill([
                        'family_sort_order' => $sortOrder + 1,
                        'family_option_label' => $productData['label'],
                        'description' => $productData['description'],
                        'price_amount' => $productData['price'],
                        'currency' => 'IDR',
                        'is_active' => true,
                    ]);
                    if (! $product->exists) {
                        $product->forceFill([
                            'stock' => $productData['stock'],
                            'reserved_stock' => 0,
                        ]);
                    }
                    if ($product->trashed()) {
                        $product->restore();
                    }
                    $product->save();
                }
            }
        });

        $this->info(sprintf(
            'Demo catalog ready: %d families, %d products (%d families and %d products created).',
            count(self::DEMO_FAMILIES),
            array_sum(array_map(static fn (array $family): int => count($family['products']), self::DEMO_FAMILIES)),
            $createdFamilies,
            $createdProducts,
        ));

        return self::SUCCESS;
    }
}
