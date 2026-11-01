<?php

// Disposable schema oracle only; invoked by smoke_navigation.py in the P01 namespace.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('testing') || Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'disposable') {
    throw new RuntimeException('Synthetic fixture refused');
}
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Carbon::setTestNow('2026-11-01 10:00:00 Asia/Jakarta');
config(['cache.default' => 'array', 'services.telegram.bot_token' => '', 'app.key' => str_repeat('a', 64)]);
$customer = App\Models\Customer::factory()->create(['telegram_id' => 1, 'first_name' => 'Synthetic', 'username' => null, 'locale' => 'id']);
$category = App\Models\Category::factory()->create(['name' => 'Synthetic Category', 'is_active' => true]);
$family = App\Models\ProductFamily::factory()->create(['name' => 'Synthetic Family', 'icon' => '📺', 'custom_emoji_id' => null]);
$products = [];
foreach ([['Basic', 0, 10000], ['Premium', 8, 25000]] as $position => [$label, $stock, $price]) {
    $products[] = App\Models\Product::factory()->create([
        'name' => 'Synthetic '.$label, 'family_option_label' => $label,
        'category_id' => $category->id, 'product_family_id' => $family->id,
        'family_sort_order' => $position, 'icon' => null, 'custom_emoji_id' => null,
        'price_amount' => $price, 'usdt_price_amount' => null, 'stock' => $stock, 'reserved_stock' => 0,
        'is_active' => true, 'description' => 'Synthetic <description>',
        'description_en' => 'English <description>', 'description_ms' => 'Malay <description>',
        'warranty_policy' => null, 'warranty_policy_en' => null, 'warranty_policy_ms' => null,
        'telegram_image_path' => null,
    ]);
}
$standalone = App\Models\Product::factory()->create(['name' => 'Synthetic Standalone',
    'category_id' => $category->id, 'product_family_id' => null, 'price_amount' => 5000,
    'usdt_price_amount' => null, 'stock' => 2, 'reserved_stock' => 0, 'is_active' => true,
    'icon' => '🎧', 'custom_emoji_id' => null, 'telegram_image_path' => null]);
$bot = new class extends App\Services\Telegram\TelegramBotClient {
    public array $surfaces = [];
    public function sendMessage(int|string $chatId, string $text, array $replyMarkup = [], array $options = []): array {
        $this->surfaces[] = ['caption' => $text, 'keyboard' => $replyMarkup, 'options' => $options];
        return ['ok' => true, 'result' => ['message_id' => 100 + count($this->surfaces)]];
    }
    public function editMessageText(int|string $chatId, int $messageId, string $text, array $replyMarkup = [], array $options = []): array {
        $this->surfaces[] = ['caption' => $text, 'keyboard' => $replyMarkup, 'options' => $options];
        return ['ok' => true, 'result' => ['message_id' => $messageId]];
    }
    public function deleteMessage(int|string $chatId, int|string $messageId): void {}
};
$app->instance(App\Services\Telegram\TelegramBotClient::class, $bot);
foreach (range(1, 12) as $index) {
    App\Models\Order::factory()->for($customer)->create([
        'invoice_number' => 'INV-P06-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
        'order_status' => in_array($index, [3, 4], true)
            ? App\Enums\OrderStatus::Processing : App\Enums\OrderStatus::Completed,
        'payment_status' => App\Enums\PaymentStatus::Paid,
        'created_at' => now()->subMinutes($index),
        'updated_at' => now()->subMinutes($index),
        'telegram_receipt_sent_at' => $index === 4 ? null : now(),
    ]);
}
App\Models\Order::factory()->for($customer)->create([
    'invoice_number' => 'INV-P06-UNPAID', 'order_status' => App\Enums\OrderStatus::PendingPayment,
    'payment_status' => App\Enums\PaymentStatus::Pending, 'created_at' => now(),
]);
App\Models\Order::factory()->for($customer)->create([
    'invoice_number' => 'INV-P06-CANCELLED', 'order_status' => App\Enums\OrderStatus::Cancelled,
    'payment_status' => App\Enums\PaymentStatus::Paid, 'created_at' => now()->addMinute(),
]);
$catalog = $app->make(App\Services\Telegram\TelegramCatalogIntents::class);
$home = $app->make(App\Services\Telegram\TelegramMenuIntents::class)->homeSurface($customer, '1');
$expected = ['home' => ['caption' => $home->caption, 'keyboard' => $home->keyboard, 'options' => $home->options]];
$catalog->sendCatalogPage($customer, '1');
$expected['catalog'] = end($bot->surfaces);
$catalog->sendFamilyOptions($customer, '1', $family->id);
$expected['family'] = end($bot->surfaces);
$catalog->sendProductDetail($customer, '1', $products[1]);
$expected['detail'] = end($bot->surfaces);
$orders = $app->make(App\Services\Telegram\TelegramOrderIntents::class);
$ordersSurface = $orders->orderHistorySurface($customer);
$expected['orders'] = ['caption' => $ordersSurface->caption, 'keyboard' => $ordersSurface->keyboard,
    'options' => $ordersSurface->options];
$next = collect($ordersSurface->keyboard['inline_keyboard'])->flatten(1)
    ->first(fn (array $button): bool => str_starts_with((string) ($button['callback_data'] ?? ''), 'orders:older:'));
if ($next === null) {
    throw new RuntimeException('Synthetic Orders fixture did not produce a next page');
}
$ordersNext = $orders->orderHistorySurface($customer, $next['callback_data']);
$expected['orders_next'] = ['caption' => $ordersNext->caption, 'keyboard' => $ordersNext->keyboard,
    'options' => $ordersNext->options];
echo json_encode(['customer_id' => $customer->id, 'family_id' => $family->id,
    'product_id' => $products[1]->id, 'standalone_id' => $standalone->id,
    'expected' => $expected], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
