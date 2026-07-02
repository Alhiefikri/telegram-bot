<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\ManualPaymentSetting;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

$adminEmail = env('DOGFOOD_ADMIN_EMAIL', 'benchmark-owner@example.test');
$adminPassword = env('DOGFOOD_ADMIN_PASSWORD', 'BenchmarkPassword123!');
$adminChatId = (string) env('TELEGRAM_ADMIN_CHAT_ID', '700000001');
$adminUserId = (string) env('TELEGRAM_ADMIN_USER_ID', '700000001');

User::query()->updateOrCreate(
    ['email' => $adminEmail],
    [
        'name' => 'Benchmark Owner',
        'password' => Hash::make($adminPassword),
        'role' => UserRole::Owner,
    ],
);

$category = Category::query()->firstOrCreate(
    ['slug' => 'benchmark-dogfood'],
    ['name' => 'Benchmark Dogfood', 'description' => 'Synthetic benchmark category', 'sort_order' => 998, 'is_active' => true],
);

$product = Product::query()->firstOrCreate(
    ['name' => 'Benchmark Dogfood Product'],
    [
        'category_id' => $category->id,
        'description' => "Synthetic benchmark product\nEmail: benchmark@example.test\nPassword: secret",
        'price_amount' => 35000,
        'currency' => 'IDR',
        'stock' => 500,
        'reserved_stock' => 0,
        'image_path' => null,
        'is_active' => true,
    ],
);
$product->forceFill(['category_id' => $category->id, 'stock' => 500, 'reserved_stock' => 0, 'is_active' => true])->save();

ManualPaymentSetting::query()->updateOrCreate(
    ['is_active' => true],
    [
        'payment_mode' => 'manual',
        'admin_contact_url' => null,
        'admin_username' => null,
        'admin_telegram_chat_id' => $adminChatId,
        'instructions' => 'Synthetic benchmark manual payment instructions.',
    ],
);

echo json_encode([
    'admin_email' => $adminEmail,
    'telegram_chat_id' => (int) $adminChatId,
    'telegram_user_id' => (int) $adminUserId,
    'category_id' => $category->id,
    'product_id' => $product->id,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
