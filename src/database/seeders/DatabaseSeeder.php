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

        // Clear existing categories and products for a clean seed matching the mockup
        DB::table('products')->delete();
        DB::table('categories')->delete();

        $now = now();
        $categories = [
            ['name' => 'AI Premium', 'slug' => 'ai-premium', 'description' => 'Akun AI premium untuk menunjang produktivitas.', 'sort_order' => 10],
            ['name' => 'Tools Digital', 'slug' => 'tools-digital', 'description' => 'Layanan digital dan cloud storage premium.', 'sort_order' => 20],
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

        $categoryIds = DB::table('categories')->whereIn('slug', ['ai-premium', 'tools-digital'])->pluck('id', 'slug');
        $products = [
            [
                'name' => 'ChatGPT Plus',
                'description' => 'Akun ChatGPT Plus premium dengan akses GPT-4o, DALL-E 3, dan analisis data tingkat lanjut.',
                'price_amount' => 49000,
                'stock' => 128,
                'category_id' => $categoryIds['ai-premium'] ?? null,
            ],
            [
                'name' => 'Claude Pro',
                'description' => 'Akun Claude Pro premium dengan akses Claude 3.5 Sonnet, context window lebih besar, dan prioritas akses.',
                'price_amount' => 59000,
                'stock' => 96,
                'category_id' => $categoryIds['ai-premium'] ?? null,
            ],
            [
                'name' => 'Midjourney',
                'description' => 'Akun Midjourney premium siap pakai untuk pembuatan gambar AI berkualitas tinggi dan ultra-realistis.',
                'price_amount' => 69000,
                'stock' => 74,
                'category_id' => $categoryIds['ai-premium'] ?? null,
            ],
            [
                'name' => 'Google One 2TB',
                'description' => 'Langganan Google One 2TB untuk penyimpanan cloud Google Drive, Google Photos, dan akses Google Gemini Advanced.',
                'price_amount' => 39000,
                'stock' => 112,
                'category_id' => $categoryIds['tools-digital'] ?? null,
            ],
            [
                'name' => 'Microsoft 365 Personal',
                'description' => 'Akses Microsoft 365 Personal siap pakai termasuk Word, Excel, PowerPoint, Outlook, dan penyimpanan cloud OneDrive 1TB.',
                'price_amount' => 65000,
                'stock' => 58,
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
