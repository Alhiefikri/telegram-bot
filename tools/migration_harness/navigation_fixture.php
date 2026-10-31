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
$catalog = $app->make(App\Services\Telegram\TelegramCatalogIntents::class);
$home = $app->make(App\Services\Telegram\TelegramMenuIntents::class)->homeSurface($customer, '1');
$expected = ['home' => ['caption' => $home->caption, 'keyboard' => $home->keyboard, 'options' => $home->options]];
$catalog->sendCatalogPage($customer, '1');
$expected['catalog'] = end($bot->surfaces);
$catalog->sendFamilyOptions($customer, '1', $family->id);
$expected['family'] = end($bot->surfaces);
$catalog->sendProductDetail($customer, '1', $products[1]);
$expected['detail'] = end($bot->surfaces);
echo json_encode(['customer_id' => $customer->id, 'family_id' => $family->id,
    'product_id' => $products[1]->id, 'standalone_id' => $standalone->id,
    'expected' => $expected], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
