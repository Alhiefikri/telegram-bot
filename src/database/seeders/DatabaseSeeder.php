<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        if (app()->environment(['local', 'testing'])) {
            User::query()->updateOrCreate(
                ['email' => 'test@example.com'],
                [
                    'name' => 'Test User',
                    'email_verified_at' => now(),
                    'password' => Hash::make('password'),
                    'role' => UserRole::Owner,
                ],
            );
        }

        $now = now();
        $categories = [
            ['name' => 'Streaming', 'slug' => 'streaming', 'description' => 'Produk langganan streaming populer.', 'sort_order' => 10],
            ['name' => 'Tools Digital', 'slug' => 'tools-digital', 'description' => 'Produk tools digital untuk produktivitas.', 'sort_order' => 20],
        ];

        foreach ($categories as $category) {
            DB::table('categories')->updateOrInsert(
                ['slug' => $category['slug']],
                [
                    ...$category,
                    'banner_image_path' => null,
                    'is_active' => true,
                    'deleted_at' => null,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }

        $categoryIds = DB::table('categories')->whereIn('slug', ['streaming', 'tools-digital'])->pluck('id', 'slug');
        $products = [
            [
                'name' => 'Netflix Premium 1 Bulan',
                'description' => 'Akun Netflix Premium private 1 bulan untuk testing katalog bot.',
                'price_amount' => 45000,
                'stock' => 25,
                'category_id' => $categoryIds['streaming'] ?? null,
            ],
            [
                'name' => 'Netflix Sharing 1 Bulan',
                'description' => 'Slot sharing Netflix 1 bulan. Produk dummy untuk simulasi order.',
                'price_amount' => 28000,
                'stock' => 40,
                'category_id' => $categoryIds['streaming'] ?? null,
            ],
            [
                'name' => 'Spotify Premium 1 Bulan',
                'description' => 'Paket Spotify Premium 1 bulan untuk data contoh katalog.',
                'price_amount' => 30000,
                'stock' => 30,
                'category_id' => $categoryIds['streaming'] ?? null,
            ],
            [
                'name' => 'YouTube Premium 1 Bulan',
                'description' => 'Paket YouTube Premium 1 bulan untuk simulasi produk digital.',
                'price_amount' => 35000,
                'stock' => 20,
                'category_id' => $categoryIds['streaming'] ?? null,
            ],
            [
                'name' => 'Canva Pro 1 Bulan',
                'description' => 'Akses Canva Pro 1 bulan sebagai dummy item katalog.',
                'price_amount' => 25000,
                'stock' => 35,
                'category_id' => $categoryIds['tools-digital'] ?? null,
            ],
        ];

        foreach ($products as $product) {
            DB::table('products')->updateOrInsert(
                ['name' => $product['name']],
                [
                    ...$product,
                    'currency' => 'IDR',
                    'reserved_stock' => 0,
                    'image_path' => null,
                    'is_active' => true,
                    'deleted_at' => null,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }
    }
}
