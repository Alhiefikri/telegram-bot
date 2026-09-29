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

    protected $description = 'Seed the Lhie Store product family catalog for local Telegram testing.';

    /**
     * Mirror of the live catalog: one family per product line, each holding its
     * plans with the price, USDT price (micro-USDT), stock, and default glyph.
     * Captured premium icons stay untouched because `icon` is only written when
     * a plan is created.
     *
     * @var list<array{slug:string,name:string,description:string,sort_order:int,products:list<array{name:string,label:string,description:string,price:int,usdt:int,stock:int,icon:string}>}>
     */
    private const DEMO_FAMILIES = [
        [
            'slug' => 'adobe',
            'name' => 'ADOBE',
            'description' => 'Adobe Express activation links.',
            'sort_order' => 10,
            'products' => [
                ['name' => 'LINK ADOBE EXPRESS 1 YEAR', 'label' => 'Express 1 Year', 'description' => 'Adobe Express link for one year.', 'price' => 10000, 'usdt' => 500_000, 'stock' => 8, 'icon' => '🖌️'],
            ],
        ],
        [
            'slug' => 'apple-music',
            'name' => 'APPLE MUSIC',
            'description' => 'Apple Music subscription links.',
            'sort_order' => 20,
            'products' => [
                ['name' => 'LINK APPLE MUSIC 6 MONTH', 'label' => '6 Months', 'description' => 'Apple Music link for six months.', 'price' => 15000, 'usdt' => 800_000, 'stock' => 4, 'icon' => '🎵'],
            ],
        ],
        [
            'slug' => 'capcut',
            'name' => 'CAPCUT',
            'description' => 'CapCut Pro editing access.',
            'sort_order' => 30,
            'products' => [
                ['name' => 'Capcut Pro 1 Month Full Garansi', 'label' => '1 Month', 'description' => 'CapCut Pro access for one month with warranty.', 'price' => 20000, 'usdt' => 1_000_000, 'stock' => 3, 'icon' => '🎬'],
                ['name' => 'CAPCUT PRO 7D IND', 'label' => '7 Days', 'description' => 'CapCut Pro Indonesia access for seven days.', 'price' => 5000, 'usdt' => 400_000, 'stock' => 5, 'icon' => '🎬'],
            ],
        ],
        [
            'slug' => 'chatgpt',
            'name' => 'CHATGPT',
            'description' => 'ChatGPT Plus accounts.',
            'sort_order' => 40,
            'products' => [
                ['name' => 'ChatGPT PLUS K12 Edu 2 Years (NW)', 'label' => 'K12 Edu 2 Years', 'description' => 'ChatGPT Plus K12 Edu account for two years.', 'price' => 110000, 'usdt' => 6_000_000, 'stock' => 2, 'icon' => '🤖'],
                ['name' => 'CHATGPT PLUS PRIVATE', 'label' => 'Plus Private', 'description' => 'Private ChatGPT Plus account.', 'price' => 55000, 'usdt' => 3_000_000, 'stock' => 5, 'icon' => '🤖'],
            ],
        ],
        [
            'slug' => 'duolingo',
            'name' => 'DUOLINGO',
            'description' => 'Super Duolingo activation links.',
            'sort_order' => 50,
            'products' => [
                ['name' => 'LINK SUPER DUOLINGO 12 MONTH', 'label' => '12 Months', 'description' => 'Super Duolingo link for twelve months.', 'price' => 13000, 'usdt' => 700_000, 'stock' => 4, 'icon' => '🦉'],
                ['name' => 'LINK SUPER DUOLINGO 2 MONTH', 'label' => '2 Months', 'description' => 'Super Duolingo link for two months.', 'price' => 5000, 'usdt' => 500_000, 'stock' => 9, 'icon' => '🦉'],
            ],
        ],
        [
            'slug' => 'gemini',
            'name' => 'GEMINI',
            'description' => 'Google Gemini Pro accounts.',
            'sort_order' => 60,
            'products' => [
                ['name' => 'LINK GEMINI PRO 18 BULAN HEAD', 'label' => 'Pro 18 Months', 'description' => 'Gemini Pro head account for eighteen months.', 'price' => 13000, 'usdt' => 720_000, 'stock' => 15, 'icon' => '✨'],
            ],
        ],
        [
            'slug' => 'gmail',
            'name' => 'GMAIL',
            'description' => 'Fresh Gmail accounts.',
            'sort_order' => 70,
            'products' => [
                ['name' => 'Gmail Fresh', 'label' => 'Fresh', 'description' => 'Fresh Gmail account.', 'price' => 5000, 'usdt' => 500_000, 'stock' => 8, 'icon' => '📧'],
            ],
        ],
        [
            'slug' => 'prime-video',
            'name' => 'PRIME VIDEO',
            'description' => 'Amazon Prime Video access.',
            'sort_order' => 80,
            'products' => [
                ['name' => 'PRIME VIDEO 1 MONTH', 'label' => '1 Month', 'description' => 'Prime Video access for one month.', 'price' => 8000, 'usdt' => 500_000, 'stock' => 3, 'icon' => '🎥'],
                ['name' => 'PRIME VIDEO PRIVATE | 6 MONTH', 'label' => 'Private 6 Months', 'description' => 'Private Prime Video access for six months.', 'price' => 15000, 'usdt' => 900_000, 'stock' => 3, 'icon' => '🎥'],
            ],
        ],
        [
            'slug' => 'spotify',
            'name' => 'SPOTIFY',
            'description' => 'Spotify Premium access.',
            'sort_order' => 90,
            'products' => [
                ['name' => 'SPOTIFY 2 MONTH PRIVATE', 'label' => 'Private 2 Months', 'description' => 'Private Spotify Premium for two months.', 'price' => 13000, 'usdt' => 800_000, 'stock' => 9, 'icon' => '🎧'],
            ],
        ],
        [
            'slug' => 'surfshark-vpn',
            'name' => 'SURFSHARK VPN',
            'description' => 'Surfshark VPN access.',
            'sort_order' => 100,
            'products' => [
                ['name' => 'SURFSHARK VPN | 2 Month', 'label' => '2 Months', 'description' => 'Surfshark VPN access for two months.', 'price' => 20000, 'usdt' => 1_500_000, 'stock' => 4, 'icon' => '🦈'],
            ],
        ],
        [
            'slug' => 'claude-api-key',
            'name' => 'CLAUDE API KEY',
            'description' => 'Claude API credit keys.',
            'sort_order' => 110,
            'products' => [
                ['name' => 'CLAUDE API KEY 100$', 'label' => '100 USD', 'description' => 'Claude API key with 100 USD credit.', 'price' => 25000, 'usdt' => 1_500_000, 'stock' => 2, 'icon' => '🧠'],
                ['name' => 'CLAUDE API KEY 500$', 'label' => '500 USD', 'description' => 'Claude API key with 500 USD credit.', 'price' => 115000, 'usdt' => 6_500_000, 'stock' => 3, 'icon' => '🧠'],
            ],
        ],
        [
            'slug' => 'netflix',
            'name' => 'NETFLIX',
            'description' => 'Netflix Premium profiles.',
            'sort_order' => 120,
            'products' => [
                ['name' => 'NETFLIX NO VPN PRIVATE | 5 PROFILE 4K', 'label' => 'No VPN Private 4K', 'description' => 'Private Netflix 4K profile without VPN.', 'price' => 18000, 'usdt' => 1_400_000, 'stock' => 5, 'icon' => '🍿'],
                ['name' => 'NETFLIX NO VPN WITH UR MAIL | 5 PROFILE', 'label' => 'No VPN + Your Mail', 'description' => 'Netflix profile registered with your own email.', 'price' => 17000, 'usdt' => 1_300_000, 'stock' => 5, 'icon' => '🍿'],
                ['name' => 'NETFLIX PREMIUM | 4K 5 PROFILE', 'label' => 'Premium 4K', 'description' => 'Netflix Premium 4K profile.', 'price' => 13000, 'usdt' => 1_000_000, 'stock' => 5, 'icon' => '🍿'],
            ],
        ],
        [
            'slug' => 'supergrok',
            'name' => 'SUPERGROK',
            'description' => 'SuperGrok access.',
            'sort_order' => 130,
            'products' => [
                ['name' => 'GROK SUPER 9-10 Days (1D W)', 'label' => '9-10 Days', 'description' => 'SuperGrok access for nine to ten days.', 'price' => 40000, 'usdt' => 2_500_000, 'stock' => 5, 'icon' => '🚀'],
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
                        'usdt_price_amount' => $productData['usdt'],
                        'currency' => 'IDR',
                        'is_active' => true,
                    ]);
                    if (! $product->exists) {
                        // Captured premium icons stay untouched: icon and stock are
                        // only written for a plan this command creates.
                        $product->forceFill([
                            'icon' => $productData['icon'],
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
            'Catalog ready: %d families, %d products (%d families and %d products created).',
            count(self::DEMO_FAMILIES),
            array_sum(array_map(static fn (array $family): int => count($family['products']), self::DEMO_FAMILIES)),
            $createdFamilies,
            $createdProducts,
        ));

        return self::SUCCESS;
    }
}
