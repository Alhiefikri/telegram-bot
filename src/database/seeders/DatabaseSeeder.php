<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

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

        // Keep the demo catalog intentionally small and predictable.
        DB::table('payment_events')->delete();
        DB::table('payments')->delete();
        DB::table('inventory_movements')->delete();
        DB::table('order_items')->delete();
        DB::table('orders')->delete();
        DB::table('cart_items')->delete();
        DB::table('carts')->delete();
        DB::table('products')->delete();
        DB::table('categories')->delete();

        $now = now();
        $chatGptImagePath = 'products/chatgpt-plus-35k.png';
        $chatGptImageSource = database_path('seeders/assets/products/chatgpt-plus-35k.png');

        if (is_file($chatGptImageSource) && ($chatGptImageContents = file_get_contents($chatGptImageSource)) !== false) {
            Storage::disk('public')->put($chatGptImagePath, $chatGptImageContents);
        }

        $categories = [
            ['name' => 'AI Premium', 'slug' => 'ai-premium', 'description' => 'Akun AI premium untuk menunjang produktivitas.', 'sort_order' => 10],
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

        $categoryIds = DB::table('categories')->whereIn('slug', ['ai-premium'])->pluck('id', 'slug');
        $products = [
            [
                'name' => 'ChatGPT Plus',
                'description' => 'Akun ChatGPT Plus premium siap pakai untuk kebutuhan produktivitas, riset, penulisan, dan bantuan kerja harian.',
                'price_amount' => 35000,
                'stock' => 128,
                'category_id' => $categoryIds['ai-premium'] ?? null,
                'image_path' => $chatGptImagePath,
            ],
        ];

        foreach ($products as $product) {
            DB::table('products')->updateOrInsert(
                ['name' => $product['name']],
                [
                    ...$product,
                    'currency' => 'IDR',
                    'reserved_stock' => 0,
                    'image_path' => $product['image_path'],
                    'is_active' => true,
                    'deleted_at' => null,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }
    }
}
