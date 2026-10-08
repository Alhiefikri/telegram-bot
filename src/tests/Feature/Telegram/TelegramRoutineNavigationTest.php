<?php

namespace Tests\Feature\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\StoreBalanceEntryKind;
use App\Enums\StoreBalanceFundingIntentStatus;
use App\Enums\TelegramNavigationMediaKind;
use App\Enums\TelegramNavigationScreen;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\StoreBalanceEntry;
use App\Models\StoreBalanceFundingIntent;
use App\Models\StoreBalanceFundingPolicy;
use App\Models\TelegramNavigationRoot;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\Telegram\TelegramLocale;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TelegramBotTestSupport;

final class TelegramRoutineNavigationTest extends TelegramBotTestSupport
{
    public function test_home_balance_home_language_home_orders_support_change_one_message(): void
    {
        $this->fakeCanonicalTelegram();
        $customer = Customer::factory()->create([
            'telegram_id' => 45401,
            'locale' => 'en',
            'first_name' => 'Ayu',
            'username' => 'ayu_store',
        ]);
        $this->paidOrder($customer, 'INV-NAV-45401');

        $this->postTelegramWebhook($this->messageUpdate(45401, '/start', [
            'id' => 45401,
            'first_name' => 'Ayu',
            'username' => 'ayu_store',
        ], 45401))->assertOk();

        $root = TelegramNavigationRoot::query()->sole();
        $messageId = $root->message_id;
        $this->assertSame(1, $this->routineMessageCount(45401), 'The welcome banner is the only routine message.');

        $steps = [
            ['balance', '💰 My Balance', '➕ Add Balance'],
            ['menu:home', '👤 My Profile', '🛍️ Browse Products'],
            ['menu:language', '🌐 Choose your language', 'locale:en'],
            ['locale:en', '👤 My Profile', '🛍️ Browse Products'],
            ['menu:orders', '📦 Successful Orders', 'INV-NAV-45401'],
            ['menu:support', '💬 Support', '⬅️ Back'],
        ];

        foreach ($steps as $index => [$data, $captionNeedle, $markupNeedle]) {
            $this->postTelegramWebhook($this->callbackUpdate(
                45410 + $index,
                'nav-'.$index,
                $data,
                ['id' => 45401],
                45401,
                'private',
                $messageId,
            ))->assertOk();

            $edit = $this->lastCanonicalEdit(45401);
            $this->assertSame($messageId, (int) $edit['message_id'], 'Routine navigation must edit the canonical message.');
            $this->assertStringContainsString($captionNeedle, $this->editCopy($edit));
            $this->assertStringContainsString(
                $markupNeedle,
                json_encode($edit['reply_markup'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            );
            $this->assertSame($index + 1, $this->editCount(45401), 'Each action renders exactly one canonical edit.');
        }

        $this->assertSame(1, $this->routineMessageCount(45401), 'Routine navigation must not send a second message.');
        $stored = TelegramNavigationRoot::query()->sole();
        $this->assertSame($messageId, $stored->message_id);
        $this->assertSame('en', $stored->locale);
        $this->assertSame(TelegramNavigationScreen::Support, $stored->screen);
    }

    public function test_home_profile_shows_customer_transaction_totals_in_the_same_quote(): void
    {
        $this->fakeCanonicalTelegram();
        $customer = Customer::factory()->create([
            'telegram_id' => 45521,
            'locale' => 'id',
            'first_name' => 'Total Buyer',
            'username' => 'total_buyer',
        ]);

        $idrOrder = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-TOTAL-IDR-45521',
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'currency' => 'IDR',
            'total_amount' => 125_000,
        ]);
        Payment::factory()->for($idrOrder)->create([
            'amount' => 125_000,
            'currency' => 'IDR',
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        $usdtOrder = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-TOTAL-USDT-45521',
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'currency' => 'USDT',
            'total_amount' => 2_500_000,
        ]);
        Payment::factory()->for($usdtOrder)->create([
            'amount' => 2_500_000,
            'currency' => 'USDT',
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        $this->postTelegramWebhook($this->messageUpdate(45521, '/start', [
            'id' => 45521,
            'first_name' => 'Total Buyer',
            'username' => 'total_buyer',
        ], 45521))->assertOk();

        $request = Http::recorded(
            fn (Request $recorded): bool => str_ends_with($recorded->url(), '/sendPhoto'),
        )->sole()[0];
        $caption = (string) $this->telegramRequestField($request, 'caption');

        $this->assertStringContainsString(
            "<blockquote>🆔 ID Telegram: 45521\n🔗 Username: @total_buyer\n💳 Total Transaksi: Rp 125.000 / 2.5 USDT\n💰 Saldo Saya: Rp 0</blockquote>",
            $caption,
        );
    }

    public function test_balance_screen_shows_fresh_store_balance_and_add_balance_action(): void
    {
        $this->fakeCanonicalTelegram();
        $customer = Customer::factory()->create(['telegram_id' => 45411, 'locale' => 'id']);
        app(StoreBalanceLedger::class)->credit(
            $customer,
            25000,
            StoreBalanceEntryKind::TopUpCredit,
            'balance_top_up',
            '1',
            'balance_top_up:1:credit',
        );

        $this->postTelegramWebhook($this->messageUpdate(45411, '/start', [], 45411))->assertOk();
        $root = TelegramNavigationRoot::query()->sole();

        $this->postTelegramWebhook($this->callbackUpdate(45412, 'balance-open', 'balance', [], 45411, 'private', $root->message_id))->assertOk();

        $edit = $this->lastCanonicalEdit(45411);
        $this->assertSame($root->message_id, (int) $edit['message_id']);
        $this->assertStringContainsString('💰 Saldo Saya', $this->editCopy($edit));
        $this->assertStringContainsString('Saldo tersedia: Rp 25.000', $this->editCopy($edit));
        $this->assertStringNotContainsString('Aktivitas terbaru', $this->editCopy($edit));
        $keyboard = $edit['reply_markup']['inline_keyboard'];
        $this->assertSame('balance:add', $keyboard[0][0]['callback_data']);
        $this->assertSame('balance:activity', $keyboard[1][0]['callback_data']);
        $this->assertSame('usdt:history', $keyboard[2][0]['callback_data']);
        $this->assertSame(['menu:back', 'menu:home'], array_column($keyboard[3], 'callback_data'));
        $this->assertStringContainsString('💎 Saldo USDT: 0 USDT', $this->editCopy($edit));

        $this->postTelegramWebhook($this->callbackUpdate(45413, 'balance-activity', 'balance:activity', [], 45411, 'private', $root->message_id))->assertOk();

        $activityEdit = $this->lastCanonicalEdit(45411);
        $this->assertSame($root->message_id, (int) $activityEdit['message_id']);
        $this->assertStringContainsString('Aktivitas terbaru', $this->editCopy($activityEdit));
        $this->assertStringContainsString('Referensi: #1', $this->editCopy($activityEdit));

        $this->postTelegramWebhook($this->callbackUpdate(45414, 'balance-add', 'balance:add', [], 45411, 'private', $root->message_id))->assertOk();

        $addEdit = $this->lastCanonicalEdit(45411);
        $this->assertSame($root->message_id, (int) $addEdit['message_id']);
        $this->assertStringContainsString('Pilih mata uang saldo:', $this->editCopy($addEdit));

        $currencyKeyboard = $addEdit['reply_markup']['inline_keyboard'];
        $this->assertSame(['balance:currency:idr'], array_column($currencyKeyboard[0], 'callback_data'));
        $this->assertSame(['balance:currency:usd'], array_column($currencyKeyboard[1], 'callback_data'));

        $this->postTelegramWebhook($this->callbackUpdate(45418, 'balance-idr', 'balance:currency:idr', [], 45411, 'private', $root->message_id))->assertOk();

        $idrEdit = $this->lastCanonicalEdit(45411);
        $this->assertStringContainsString('Minimum tambah saldo: Rp 5.000', $this->editCopy($idrEdit));
        $presetRows = array_slice($idrEdit['reply_markup']['inline_keyboard'], 0, -1);
        $this->assertSame(
            ['Rp 5.000', 'Rp 10.000', 'Rp 25.000', 'Rp 50.000', 'Rp 100.000', 'Rp 250.000'],
            array_map(static fn (array $row): string => $row[0]['text'], $presetRows),
        );
        foreach ($presetRows as $row) {
            $callbackData = $row[0]['callback_data'];
            $this->assertMatchesRegularExpression('/\Abalance:p:0:[A-Za-z0-9_-]{11}\z/', $callbackData);
            $this->assertStringNotContainsString('5000', $callbackData);
            $this->assertStringNotContainsString('250000', $callbackData);
        }
        $this->assertSame(['menu:back', 'menu:home'], array_column($idrEdit['reply_markup']['inline_keyboard'][array_key_last($idrEdit['reply_markup']['inline_keyboard'])], 'callback_data'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && ($request['callback_query_id'] ?? null) === 'balance-idr');

        $this->postTelegramWebhook($this->callbackUpdate(45419, 'balance-preset', $presetRows[0][0]['callback_data'], [], 45411, 'private', $root->message_id))->assertOk();

        $confirmation = $this->lastCanonicalEdit(45411);
        $this->assertStringContainsString('Saldo yang dikreditkan: Rp 5.000', $this->editCopy($confirmation));
        $this->assertStringContainsString('Biaya layanan dan total pembayaran akhir ditentukan setelah konfirmasi.', $this->editCopy($confirmation));
        $this->assertStringContainsString('Belum ada invoice pembayaran.', $this->editCopy($confirmation));
        $confirmationKeyboard = $confirmation['reply_markup']['inline_keyboard'];
        $this->assertMatchesRegularExpression('/\Abalance:confirm:[a-f0-9]{32}\z/', $confirmationKeyboard[0][0]['callback_data']);
        $this->assertSame(['balance:cancel', 'balance:back'], [
            substr($confirmationKeyboard[1][0]['callback_data'], 0, strlen('balance:cancel')),
            substr($confirmationKeyboard[1][1]['callback_data'], 0, strlen('balance:back')),
        ]);
        $this->assertSame(1, StoreBalanceFundingIntent::query()->count());
        $this->assertSame(1, StoreBalanceEntry::query()->where('kind', StoreBalanceEntryKind::TopUpCredit)->count());
        $this->assertSame(5, $this->editCount(45411), 'Preset selection must edit the canonical message.');
        $this->assertSame(1, $this->routineMessageCount(45411), 'Preset selection must not send a second message.');
        $nonce = StoreBalanceFundingIntent::query()->sole()->nonce;
        $this->postTelegramWebhook($this->callbackUpdate(45416, 'balance-preset-replay', $presetRows[0][0]['callback_data'], [], 45411, 'private', $root->message_id))->assertOk();
        $this->assertSame(1, StoreBalanceFundingIntent::query()->count());
        $this->assertSame($nonce, StoreBalanceFundingIntent::query()->sole()->nonce);
        $this->assertSame(6, $this->editCount(45411), 'Repeated preset selection must reuse the canonical confirmation.');
        $this->assertSame(1, $this->routineMessageCount(45411));

        $this->postTelegramWebhook($this->callbackUpdate(45417, 'balance-back', 'menu:back', [], 45411, 'private', $root->message_id))->assertOk();
        $this->assertSame($root->message_id, (int) $this->lastCanonicalEdit(45411)['message_id']);
        $this->assertSame(TelegramNavigationScreen::Home, $root->fresh()->screen);
        $this->assertSame(7, $this->editCount(45411), 'Back must reuse the canonical message.');
        $this->assertSame(1, $this->routineMessageCount(45411));
    }

    public function test_stale_funding_preset_recovers_to_the_current_chooser_without_sending(): void
    {
        $this->fakeCanonicalTelegram();
        $customer = Customer::factory()->create(['telegram_id' => 45414, 'locale' => 'en']);

        $this->postTelegramWebhook($this->messageUpdate(45414, '/start', [], 45414))->assertOk();
        $root = TelegramNavigationRoot::query()->sole();
        $this->postTelegramWebhook($this->callbackUpdate(45415, 'funding-open', 'balance:add', [], 45414, 'private', $root->message_id))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(45416, 'funding-idr', 'balance:currency:idr', [], 45414, 'private', $root->message_id))->assertOk();
        $stalePreset = $this->lastCanonicalEdit(45414)['reply_markup']['inline_keyboard'][0][0]['callback_data'];

        $policy = StoreBalanceFundingPolicy::query()->firstOrFail();
        $policy->forceFill([
            'minimum_amount' => 5_000,
            'preset_amounts' => [5_000, 15_000],
            'version' => 1,
        ])->save();

        $this->postTelegramWebhook($this->callbackUpdate(45417, 'funding-stale', $stalePreset, [], 45414, 'private', $root->message_id))->assertOk();

        $edit = $this->lastCanonicalEdit(45414);
        $this->assertSame($root->message_id, (int) $edit['message_id']);
        $this->assertStringContainsString('Choose a top-up amount:', $this->editCopy($edit));
        $keyboard = $edit['reply_markup']['inline_keyboard'];
        $this->assertSame(['Rp 5.000', 'Rp 15.000'], array_map(
            static fn (array $row): string => $row[0]['text'],
            array_slice($keyboard, 0, -1),
        ));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && ($request['callback_query_id'] ?? null) === 'funding-stale'
            && ($request['text'] ?? null) === 'The top-up options changed. The latest options are shown.');
        $this->assertSame(3, $this->editCount(45414));
        $this->assertSame(1, $this->routineMessageCount(45414));
        $this->assertSame(0, StoreBalanceEntry::query()->count());
    }

    public function test_stale_navigation_callback_recovers_locally_without_sending(): void
    {
        $this->fakeCanonicalTelegram();
        $customer = Customer::factory()->create(['telegram_id' => 45421, 'locale' => 'en']);
        $this->paidOrder($customer, 'INV-NAV-45421');

        $this->postTelegramWebhook($this->messageUpdate(45421, '/start', [], 45421))->assertOk();
        $staleMessageId = TelegramNavigationRoot::query()->sole()->message_id;

        // A returning /start deliberately supersedes the pointer with a new root.
        $this->postTelegramWebhook($this->messageUpdate(45422, '/start', [], 45421))->assertOk();
        $current = TelegramNavigationRoot::query()->sole();
        $currentMessageId = $current->message_id;
        $this->assertNotSame($staleMessageId, $currentMessageId);

        $editsBefore = $this->editCount(45421);
        $sendsBefore = $this->routineMessageCount(45421);

        $this->postTelegramWebhook($this->callbackUpdate(45423, 'stale-nav', 'menu:orders', [], 45421, 'private', $staleMessageId))->assertOk();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && ($request['callback_query_id'] ?? null) === 'stale-nav'
            && ($request['text'] ?? null) === app(TelegramLocale::class)->translate($customer, 'callback.navigation_stale'));
        $this->assertSame($editsBefore, $this->editCount(45421), 'A stale callback must not edit any message.');
        $this->assertSame($sendsBefore, $this->routineMessageCount(45421), 'A stale callback must not send a message.');
        $this->assertSame($currentMessageId, $current->fresh()->message_id);
    }

    public function test_legacy_reply_keyboard_browse_products_opens_at_chat_tail(): void
    {
        $this->fakeCanonicalTelegram();
        $customer = Customer::factory()->create(['telegram_id' => 45425, 'locale' => 'en']);
        Product::factory()->create([
            'name' => 'Legacy Keyboard Product',
            'is_active' => true,
            'stock' => 1,
            'reserved_stock' => 0,
        ]);

        $this->postTelegramWebhook($this->messageUpdate(45425, '/start', [], 45425))->assertOk();
        $root = TelegramNavigationRoot::query()->sole();

        // This is the text Telegram sends for the old persistent reply keyboard.
        $this->postTelegramWebhook($this->messageUpdate(45426, '🛍️ Browse Products', [], 45425))->assertOk();

        $catalogMessages = Http::recorded(fn (Request $request): bool => (
            str_ends_with($request->url(), '/sendMessage')
            || str_ends_with($request->url(), '/sendPhoto')
        )
            && (string) ($request['chat_id'] ?? '') === '45425'
            && str_contains(
                (string) ($request['text'] ?? $this->telegramRequestField($request, 'caption')),
                'Choose a family or product',
            ));
        $this->assertCount(1, $catalogMessages, 'The legacy keyboard action must create a visible tail message.');
        $catalogRequest = $catalogMessages->sole()[0];
        $replyMarkup = $this->telegramRequestField($catalogRequest, 'reply_markup');
        if (is_string($replyMarkup)) {
            $replyMarkup = json_decode($replyMarkup, true, 512, JSON_THROW_ON_ERROR);
        }
        $this->assertSame(['remove_keyboard' => true], $replyMarkup);
        $this->assertSame(2, $this->routineMessageCount(45425));
        $current = $root->fresh();
        $this->assertSame(TelegramNavigationScreen::Catalog, $current->screen);
        $this->assertNotSame($root->message_id, $current->message_id);
        $restoredControls = Http::recorded(fn (Request $request): bool => (
            str_ends_with($request->url(), '/editMessageCaption')
            || str_ends_with($request->url(), '/editMessageText')
        )
            && (string) ($request['chat_id'] ?? '') === '45425'
            && (string) ($request['message_id'] ?? '') === (string) $current->message_id
            && str_contains(json_encode($request->data()), 'Legacy Keyboard Product'));
        $this->assertCount(1, $restoredControls, 'The fresh catalog root restores direct product controls.');
        $oldRootEdits = Http::recorded(fn (Request $request): bool => (
            (str_ends_with($request->url(), '/editMessageCaption') || str_ends_with($request->url(), '/editMessageText'))
            && (string) ($request['message_id'] ?? '') === (string) $root->message_id
        ));
        $this->assertCount(0, $oldRootEdits, 'The old root must not be silently edited.');
    }

    public function test_definitively_missing_root_is_replaced_once_for_navigation(): void
    {
        $this->fakeCanonicalTelegram(Http::response([
            'ok' => false,
            'description' => 'Bad Request: message to edit not found',
        ], 400));
        $customer = Customer::factory()->create(['telegram_id' => 45431, 'locale' => 'en']);
        $this->paidOrder($customer, 'INV-NAV-45431');

        $this->postTelegramWebhook($this->messageUpdate(45431, '/start', [], 45431))->assertOk();
        $original = TelegramNavigationRoot::query()->sole();

        $this->postTelegramWebhook($this->callbackUpdate(45432, 'missing-root', 'menu:orders', [], 45431, 'private', $original->message_id))->assertOk();

        $stored = TelegramNavigationRoot::query()->sole();
        $this->assertNotSame($original->message_id, $stored->message_id);
        $this->assertSame(TelegramNavigationScreen::Orders, $stored->screen);
        $this->assertSame(TelegramNavigationMediaKind::Photo, $stored->media_kind);
        $this->assertSame(2, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'))->count());
        $this->assertSame(0, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'))->count());
        $replacement = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'))->last()[0];
        $replyMarkup = $this->telegramRequestField($replacement, 'reply_markup');
        if (is_string($replyMarkup)) {
            $replyMarkup = json_decode($replyMarkup, true, 512, JSON_THROW_ON_ERROR);
        }
        $this->assertStringContainsString('INV-NAV-45431', json_encode($replyMarkup, JSON_THROW_ON_ERROR));
    }

    public function test_ambiguous_edit_keeps_the_previous_screen_and_sends_nothing(): void
    {
        $this->fakeCanonicalTelegram(Http::response([
            'ok' => false,
            'description' => 'temporary Telegram failure',
        ], 500));
        $customer = Customer::factory()->create(['telegram_id' => 45441, 'locale' => 'en']);

        $this->postTelegramWebhook($this->messageUpdate(45441, '/start', [], 45441))->assertOk();
        $root = TelegramNavigationRoot::query()->sole();

        $this->postTelegramWebhook($this->callbackUpdate(45442, 'ambiguous-nav', 'balance', [], 45441, 'private', $root->message_id))->assertOk();

        $stored = $root->fresh();
        $this->assertSame(TelegramNavigationScreen::Home, $stored->screen, 'An unproven edit must not advance the stored screen.');
        $this->assertSame($root->message_id, $stored->message_id);
        $this->assertSame(1, $this->routineMessageCount(45441));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && ($request['callback_query_id'] ?? null) === 'ambiguous-nav'
            && ($request['text'] ?? null) === app(TelegramLocale::class)->translate($customer, 'callback.navigation_recovery'));
    }

    public function test_catalog_navigation_edits_the_canonical_message_and_returns_home(): void
    {
        $this->fakeCanonicalTelegram();
        $customer = Customer::factory()->create(['telegram_id' => 45451, 'locale' => 'id']);
        $category = Category::factory()->create(['name' => 'Streaming']);
        Category::factory()->create(['name' => 'Design']);
        $product = Product::factory()->create([
            'name' => 'Netflix Premium',
            'description' => 'Akun streaming.',
            'category_id' => $category->id,
            'price_amount' => 25000,
            'stock' => 2,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $this->postTelegramWebhook($this->messageUpdate(45451, '/start', [], 45451))->assertOk();
        $messageId = TelegramNavigationRoot::query()->sole()->message_id;

        $this->postTelegramWebhook($this->callbackUpdate(45452, 'catalog-open', 'katalog', [], 45451, 'private', $messageId))->assertOk();
        $catalogEdit = $this->lastCanonicalEdit(45451);
        $this->assertStringContainsString('Pilih keluarga atau produk', $this->editCopy($catalogEdit));
        $this->assertStringNotContainsString('Netflix Premium', $this->editCopy($catalogEdit));
        $this->assertStringContainsString('Netflix Premium', json_encode($catalogEdit['reply_markup'], JSON_THROW_ON_ERROR));

        $this->postTelegramWebhook($this->callbackUpdate(45453, 'catalog-category', 'category:'.$category->id, [], 45451, 'private', $messageId))->assertOk();
        $this->assertStringContainsString('Kategori: Streaming', $this->editCopy($this->lastCanonicalEdit(45451)));

        $this->postTelegramWebhook($this->callbackUpdate(45454, 'catalog-product', 'product:'.$product->id, [], 45451, 'private', $messageId))->assertOk();
        $productDetail = $this->lastCanonicalEdit(45451);
        $this->assertStringContainsString('Netflix Premium', $this->editCopy($productDetail));
        $this->assertSame($messageId, (int) $productDetail['message_id']);

        $this->postTelegramWebhook($this->callbackUpdate(45455, 'catalog-back-home', 'family:back:menu', [], 45451, 'private', $messageId))->assertOk();
        $home = $this->lastCanonicalEdit(45451);
        $this->assertStringContainsString('👤 Profil Saya', $this->editCopy($home));
        $this->assertSame($messageId, (int) $home['message_id']);

        $this->assertSame(4, $this->editCount(45451), 'Catalog navigation must edit the canonical message.');
        $this->assertSame(1, $this->routineMessageCount(45451), 'Catalog navigation must not send a second message.');
        $this->assertSame($messageId, (int) TelegramNavigationRoot::query()->sole()->message_id);
    }

    public function test_product_photo_replaces_banner_and_quantity_edits_only_the_new_detail(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('telegram-products/first.webp', 'first-image');
        Storage::disk('public')->put('telegram-products/second.webp', 'second-image');
        $this->fakeCanonicalTelegram();
        Customer::factory()->create(['telegram_id' => 45459, 'locale' => 'id']);
        $category = Category::factory()->create(['name' => 'Design']);
        $first = Product::factory()->create([
            'name' => 'Design First',
            'category_id' => $category->id,
            'telegram_image_path' => 'telegram-products/first.webp',
            'stock' => 3,
        ]);
        $second = Product::factory()->create([
            'name' => 'Design Second',
            'category_id' => $category->id,
            'telegram_image_path' => 'telegram-products/second.webp',
            'stock' => 3,
        ]);

        $this->postTelegramWebhook($this->messageUpdate(45459, '/start', [], 45459))->assertOk();
        $bannerId = TelegramNavigationRoot::query()->sole()->message_id;
        $this->postTelegramWebhook($this->callbackUpdate(45460, 'open-catalog', 'katalog', [], 45459, 'private', $bannerId))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(45461, 'open-category', 'category:'.$category->id, [], 45459, 'private', $bannerId))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(45462, 'open-first', 'product:'.$first->id, [], 45459, 'private', $bannerId))->assertOk();

        $detail = TelegramNavigationRoot::query()->sole();
        $this->assertNotSame($bannerId, $detail->message_id);
        $this->assertSame(TelegramNavigationScreen::Product, $detail->screen);
        $this->assertSame(TelegramNavigationMediaKind::Photo, $detail->media_kind);
        $firstPhoto = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'))->last()[0];
        $this->assertStringContainsString('/storage/telegram-products/first.webp', $firstPhoto['photo']);
        $this->assertStringContainsString('Design First', $firstPhoto['caption']);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/deleteMessage')
            && (int) $request['message_id'] === $bannerId);

        $increase = $firstPhoto['reply_markup']['inline_keyboard'][0][2]['callback_data'];
        $this->postTelegramWebhook($this->callbackUpdate(45463, 'increase-first', $increase, [], 45459, 'private', $detail->message_id))->assertOk();
        $this->assertSame($detail->message_id, TelegramNavigationRoot::query()->sole()->message_id);
        $this->assertStringContainsString('Jumlah: 2', $this->editCopy($this->lastCanonicalEdit(45459)));
        $updatedDetail = $this->lastCanonicalEdit(45459);
        $manual = collect($updatedDetail['reply_markup']['inline_keyboard'])->flatten(1)
            ->first(fn (array $button): bool => str_starts_with($button['callback_data'], 'detail:qty:manual:'))['callback_data'];
        $this->postTelegramWebhook($this->callbackUpdate(45466, 'manual-first', $manual, [], 45459, 'private', $detail->message_id))->assertOk();
        $this->assertSame($detail->message_id, TelegramNavigationRoot::query()->sole()->message_id);
        $prompt = $this->lastCanonicalEdit(45459);
        $this->assertStringContainsString('Design First', $this->editCopy($prompt));
        $cancel = $prompt['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $this->postTelegramWebhook($this->callbackUpdate(45467, 'cancel-manual', $cancel, [], 45459, 'private', $detail->message_id))->assertOk();
        $updatedDetail = $this->lastCanonicalEdit(45459);
        $back = collect($updatedDetail['reply_markup']['inline_keyboard'])->flatten(1)
            ->first(fn (array $button): bool => str_starts_with($button['callback_data'], 'detail:back:'))['callback_data'];
        $this->assertSame(2, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'))->count());

        $this->postTelegramWebhook($this->callbackUpdate(45464, 'back-first', $back, [], 45459, 'private', $detail->message_id))->assertOk();
        $catalog = TelegramNavigationRoot::query()->sole();
        $this->assertSame(TelegramNavigationScreen::Catalog, $catalog->screen);
        $this->assertNotSame($detail->message_id, $catalog->message_id);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/deleteMessage')
            && (int) $request['message_id'] === $detail->message_id);

        $this->postTelegramWebhook($this->callbackUpdate(45465, 'open-second', 'product:'.$second->id, [], 45459, 'private', $catalog->message_id))->assertOk();
        $secondPhoto = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'))->last()[0];
        $this->assertStringContainsString('/storage/telegram-products/second.webp', $secondPhoto['photo']);
        $this->assertStringContainsString('Design Second', $secondPhoto['caption']);

        $secondRoot = TelegramNavigationRoot::query()->sole();
        $second->update(['telegram_image_path' => null]);
        $increaseSecond = $secondPhoto['reply_markup']['inline_keyboard'][0][2]['callback_data'];
        $this->postTelegramWebhook($this->callbackUpdate(45468, 'image-removed', $increaseSecond, [], 45459, 'private', $secondRoot->message_id))->assertOk();
        $textRoot = TelegramNavigationRoot::query()->sole();
        $this->assertSame(TelegramNavigationMediaKind::Text, $textRoot->media_kind);
        $this->assertSame(TelegramNavigationScreen::Product, $textRoot->screen);
        $this->assertNotSame($secondRoot->message_id, $textRoot->message_id);
    }

    public function test_long_catalog_screen_replaces_the_photo_root_once_and_keeps_editing(): void
    {
        $this->fakeCanonicalTelegram();
        $customer = Customer::factory()->create(['telegram_id' => 45461, 'locale' => 'en']);
        $family = ProductFamily::factory()->create(['name' => 'Collector Family', 'slug' => 'collector-family']);
        foreach (range(1, 10) as $number) {
            Product::factory()->create([
                'name' => 'Collector Bundle '.$number,
                'product_family_id' => $family->id,
                'family_option_label' => 'Premium Collector Edition Package '.$number.' with extended warranty and priority delivery',
                'stock' => 1,
                'reserved_stock' => 0,
                'is_active' => true,
            ]);
        }

        $this->postTelegramWebhook($this->messageUpdate(45461, '/start', [], 45461))->assertOk();
        $photoRoot = TelegramNavigationRoot::query()->sole();
        $this->assertSame(TelegramNavigationMediaKind::Photo, $photoRoot->media_kind);

        $this->postTelegramWebhook($this->callbackUpdate(45462, 'long-catalog', 'katalog', [], 45461, 'private', $photoRoot->message_id))->assertOk();
        $list = $this->lastCanonicalEdit(45461);
        $this->assertSame($photoRoot->message_id, (int) $list['message_id']);
        $this->assertStringContainsString('Choose a family or product', $this->editCopy($list));
        $this->assertStringNotContainsString('Collector Family', $this->editCopy($list));
        $this->assertStringContainsString('Collector Family', json_encode($list['reply_markup'], JSON_THROW_ON_ERROR));

        $options = null;
        foreach ($list['reply_markup']['inline_keyboard'] as $row) {
            foreach ($row as $button) {
                if (str_starts_with((string) ($button['callback_data'] ?? ''), 'family:options:v')) {
                    $options = $button['callback_data'];
                }
            }
        }
        $this->assertNotNull($options);

        $this->postTelegramWebhook($this->callbackUpdate(45463, 'long-options', $options, [], 45461, 'private', $photoRoot->message_id))->assertOk();

        $stored = TelegramNavigationRoot::query()->sole();
        $this->assertSame(TelegramNavigationMediaKind::Text, $stored->media_kind, 'Oversized catalog screens become one text root.');
        $this->assertSame(TelegramNavigationScreen::Catalog, $stored->screen);
        $this->assertNotSame($photoRoot->message_id, $stored->message_id);
        $this->assertSame(1, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'))->count());
        $replacement = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'))->sole()[0];
        $this->assertStringContainsString('Collector Family', (string) ($replacement['text'] ?? ''));
        $this->assertSame('banner-'.$photoRoot->message_id, $stored->banner_reference);

        $this->postTelegramWebhook($this->callbackUpdate(45464, 'long-back-home', 'family:back:menu', [], 45461, 'private', $stored->message_id))->assertOk();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageText')
            && (string) ($request['message_id'] ?? '') === (string) $stored->message_id
            && str_contains((string) ($request['text'] ?? ''), '👤 My Profile'));
        $this->assertSame(1, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'))->count());
        $this->assertSame(1, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'))->count());
    }

    public function test_group_chats_expose_only_public_safe_navigation(): void
    {
        $this->fakeCanonicalTelegram();
        $customer = Customer::factory()->create([
            'telegram_id' => 45471,
            'locale' => 'id',
            'first_name' => 'Group User',
            'username' => 'group_user',
        ]);

        $this->postTelegramWebhook($this->messageUpdate(45471, '/start', [
            'id' => 45471,
            'first_name' => 'Group User',
            'username' => 'group_user',
        ], -10045471, 'group'))->assertOk();

        $banner = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'))->sole()[0];
        $markup = $banner->data()['reply_markup'];
        $this->assertArrayNotHasKey('keyboard', $markup);
        $this->assertCount(1, $markup['inline_keyboard']);
        $this->assertSame(['katalog'], array_column($markup['inline_keyboard'][0], 'callback_data'));
        $this->assertStringNotContainsString('balance', json_encode($markup, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('45471', (string) $this->telegramRequestField($banner, 'caption'));
        $this->assertSame(0, TelegramNavigationRoot::query()->count());

        $denied = ['balance' => 'no-balance', 'menu:orders' => 'no-orders', 'menu:home' => 'no-home'];
        $updateId = 45480;
        foreach ($denied as $data => $callbackId) {
            $this->postTelegramWebhook($this->callbackUpdate($updateId++, $callbackId, $data, ['id' => 45471], -10045471, 'group', 9001))->assertOk();
            Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
                && ($request['callback_query_id'] ?? null) === $callbackId
                && ($request['text'] ?? null) === 'Demi keamanan, lanjutkan di chat pribadi dengan bot.');
        }
        $this->assertSame(0, $this->editCount(-10045471));

        $this->postTelegramWebhook($this->callbackUpdate(45490, 'group-catalog', 'katalog', ['id' => 45471], -10045471, 'group', 9001))->assertOk();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageText')
            && (string) ($request['message_id'] ?? '') === '9001');
        $this->assertSame(0, TelegramNavigationRoot::query()->count());
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('navigationScreensByLocale')]
    public function test_navigation_screens_are_localized_for_every_customer_locale(string $locale, array $expected): void
    {
        $this->fakeCanonicalTelegram();
        $customer = Customer::factory()->create(['telegram_id' => 45481, 'locale' => $locale]);
        $this->paidOrder($customer, 'INV-LOCALE-45481');

        $this->postTelegramWebhook($this->messageUpdate(45481, '/start', [], 45481))->assertOk();
        $messageId = TelegramNavigationRoot::query()->sole()->message_id;

        $this->postTelegramWebhook($this->callbackUpdate(45482, 'locale-balance', 'balance', [], 45481, 'private', $messageId))->assertOk();
        $balance = $this->editCopy($this->lastCanonicalEdit(45481));
        $this->assertStringContainsString($expected[0], $balance);
        $this->assertStringContainsString($expected[1], $balance);
        $this->assertStringContainsString($expected[4], json_encode($this->lastCanonicalEdit(45481)['reply_markup'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString($expected[5], json_encode($this->lastCanonicalEdit(45481)['reply_markup'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        $this->postTelegramWebhook($this->callbackUpdate(45483, 'locale-add', 'balance:add', [], 45481, 'private', $messageId))->assertOk();
        $addBalance = $this->editCopy($this->lastCanonicalEdit(45481));
        $this->assertStringContainsString($expected[6], $addBalance);
        $addMarkup = json_encode($this->lastCanonicalEdit(45481)['reply_markup'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString($expected[4], $addMarkup);
        $this->assertStringContainsString($expected[5], $addMarkup);
        $this->postTelegramWebhook($this->callbackUpdate(45484, 'locale-idr', 'balance:currency:idr', [], 45481, 'private', $messageId))->assertOk();
        $idrBalance = $this->editCopy($this->lastCanonicalEdit(45481));
        $this->assertStringContainsString($expected[6], $idrBalance);
        $this->assertStringContainsString($expected[7], $idrBalance);
        $this->assertStringContainsString($expected[8], $idrBalance);
        $this->assertStringContainsString($expected[9], $idrBalance);

        $this->postTelegramWebhook($this->callbackUpdate(45485, 'locale-orders', 'menu:orders', [], 45481, 'private', $messageId))->assertOk();
        $this->assertStringContainsString($expected[2], $this->editCopy($this->lastCanonicalEdit(45481)));

        $this->postTelegramWebhook($this->callbackUpdate(45486, 'locale-support', 'menu:support', [], 45481, 'private', $messageId))->assertOk();
        $this->assertStringContainsString($expected[3], $this->editCopy($this->lastCanonicalEdit(45481)));

        $this->assertSame(5, $this->editCount(45481));
        $this->assertSame(1, $this->routineMessageCount(45481));
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function navigationScreensByLocale(): array
    {
        return [
            'indonesian' => ['id', [
                '💰 Saldo Saya',
                'Saldo tersedia: Rp 0',
                '📦 Pesanan Berhasil',
                'Ada kendala order, pembayaran, atau login akun?',
                '⬅️ Kembali',
                '🏠 Beranda',
                '➕ Tambah Saldo',
                'Minimum tambah saldo: Rp 5.000',
                '/deposit &lt;amount&gt;',
                'Pilih nominal top-up:',
            ]],
            'english' => ['en', [
                '💰 My Balance',
                'Available balance: Rp 0',
                '📦 Successful Orders',
                'Need help with an order, payment, or account login?',
                '⬅️ Back',
                '🏠 Home',
                '➕ Add Balance',
                'Minimum top-up: Rp 5.000',
                '/deposit &lt;amount&gt;',
                'Choose a top-up amount:',
            ]],
            'malay' => ['ms', [
                '💰 Baki Saya',
                'Baki tersedia: Rp 0',
                '📦 Pesanan Berjaya',
                'Ada masalah dengan pesanan, bayaran atau log masuk akaun?',
                '⬅️ Kembali',
                '🏠 Utama',
                '➕ Tambah Baki',
                'Minimum tambah baki: Rp 5.000',
                '/deposit &lt;amount&gt;',
                'Pilih jumlah tambah baki:',
            ]],
        ];
    }

    public function test_repeated_home_callbacks_converge_without_a_second_message(): void
    {
        $edits = 0;
        $this->fakeCanonicalTelegram(function () use (&$edits) {
            $edits++;

            return $edits > 1
                ? Http::response(['ok' => false, 'description' => 'Bad Request: message is not modified'], 400)
                : Http::response(['ok' => true]);
        });
        $customer = Customer::factory()->create(['telegram_id' => 45501, 'locale' => 'en']);

        $this->postTelegramWebhook($this->messageUpdate(45501, '/start', [], 45501))->assertOk();
        $root = TelegramNavigationRoot::query()->sole();

        $this->postTelegramWebhook($this->callbackUpdate(45502, 'home-first', 'menu:home', [], 45501, 'private', $root->message_id))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(45503, 'home-repeat', 'menu:home', [], 45501, 'private', $root->message_id))->assertOk();

        $stored = $root->fresh();
        $this->assertSame($root->message_id, $stored->message_id);
        $this->assertSame(TelegramNavigationScreen::Home, $stored->screen);
        $this->assertSame('en', $stored->locale);
        $this->assertNull($stored->render_token);
        $this->assertNull($stored->render_started_at);
        $this->assertSame(2, $this->editCount(45501));
        $this->assertSame(1, $this->routineMessageCount(45501), 'A not-modified render is a successful no-op.');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && ($request['callback_query_id'] ?? null) === 'home-repeat'
            && ! isset($request['text']));
    }

    public function test_orders_screen_reads_history_with_one_bounded_query(): void
    {
        $this->fakeCanonicalTelegram();
        $customer = Customer::factory()->create(['telegram_id' => 45511, 'locale' => 'en']);
        foreach (range(1, 5) as $index) {
            $this->paidOrder($customer, 'INV-BOUND-45511-'.$index);
        }

        $this->postTelegramWebhook($this->messageUpdate(45511, '/start', [], 45511))->assertOk();
        $messageId = TelegramNavigationRoot::query()->sole()->message_id;

        $orderQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$orderQueries): void {
            if (preg_match('/from ["`]?orders["`]?/i', $query->sql) === 1) {
                $orderQueries[] = $query->sql;
            }
        });

        $this->postTelegramWebhook($this->callbackUpdate(45512, 'orders-bounded', 'menu:orders', [], 45511, 'private', $messageId))->assertOk();

        $this->assertCount(1, $orderQueries, 'The Orders screen must read its history with one bounded query.');
        $edit = $this->lastCanonicalEdit(45511);
        $this->assertSame('📦 Successful Orders', $this->editCopy($edit));
        $orderCallbacks = array_values(array_filter(
            array_map(fn (array $row) => $row[0]['callback_data'] ?? null, $edit['reply_markup']['inline_keyboard']),
            fn ($callback): bool => is_string($callback) && str_starts_with($callback, 'order:'),
        ));
        $this->assertSame([
            'order:INV-BOUND-45511-5',
            'order:INV-BOUND-45511-4',
            'order:INV-BOUND-45511-3',
            'order:INV-BOUND-45511-2',
            'order:INV-BOUND-45511-1',
        ], $orderCallbacks);
        $this->assertSame(1, $this->routineMessageCount(45511));
    }

    public function test_custom_deposit_reuses_one_confirmation_intent_without_provider_side_effects(): void
    {
        $this->fakeCanonicalTelegram();
        $customer = Customer::factory()->create(['telegram_id' => 45431, 'locale' => 'en']);
        $root = $this->openFundingChooser($customer, 45431);

        $this->postTelegramWebhook($this->messageUpdate(45433, '/deposit 25000', [], 45431))->assertOk();

        $intent = StoreBalanceFundingIntent::query()->sole();
        $this->assertSame(StoreBalanceFundingIntentStatus::Pending, $intent->status);
        $this->assertSame(25_000, $intent->amount);
        $this->assertSame((int) $root->id, $intent->telegram_navigation_root_id);
        $this->assertSame((int) $root->message_id, $intent->canonical_message_id);
        $this->assertTrue($intent->expires_at->isFuture());

        $confirmation = $this->lastCanonicalEdit(45431);
        $caption = $this->editCopy($confirmation);
        $this->assertStringContainsString('Confirm Store Balance top-up', $caption);
        $this->assertStringContainsString('Store Balance credit: Rp 25.000', $caption);
        $this->assertStringContainsString('service fee and final payable total', $caption);
        $this->assertStringContainsString('No payment invoice exists yet.', $caption);
        $keyboard = $confirmation['reply_markup']['inline_keyboard'];
        $this->assertMatchesRegularExpression('/\Abalance:confirm:[a-f0-9]{32}\z/', $keyboard[0][0]['callback_data']);
        $this->assertMatchesRegularExpression('/\Abalance:cancel:[a-f0-9]{32}\z/', $keyboard[1][0]['callback_data']);
        $this->assertMatchesRegularExpression('/\Abalance:back:[a-f0-9]{32}\z/', $keyboard[1][1]['callback_data']);
        foreach ($keyboard as $row) {
            foreach ($row as $button) {
                $this->assertStringNotContainsString('25000', $button['callback_data'] ?? '');
            }
        }

        $nonce = $intent->nonce;
        $this->postTelegramWebhook($this->messageUpdate(45434, '/deposit 25000', [], 45431))->assertOk();
        $this->assertSame(1, StoreBalanceFundingIntent::query()->count());
        $this->assertSame($nonce, StoreBalanceFundingIntent::query()->sole()->nonce);
        $this->assertSame(1, $this->routineMessageCount(45431));
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, StoreBalanceEntry::query()->count());
        $this->assertSame(0, Http::recorded(
            fn (Request $request): bool => str_contains($request->url(), 'klikqris'),
        )->count());
    }

    public function test_custom_deposit_without_existing_root_establishes_one_canonical_message(): void
    {
        $this->fakeCanonicalTelegram();
        $customer = Customer::factory()->create(['telegram_id' => 45435, 'locale' => 'en']);

        $this->postTelegramWebhook($this->messageUpdate(45436, '/deposit 25000', [], 45435))->assertOk();

        $intent = StoreBalanceFundingIntent::query()->sole();
        $this->assertSame(1, $this->routineMessageCount(45435));
        $this->assertSame(1, $this->editCount(45435));
        $this->assertSame(
            TelegramNavigationScreen::AddBalance,
            TelegramNavigationRoot::query()->sole()->screen,
        );
        $this->assertStringContainsString(
            'Store Balance credit: Rp 25.000',
            $this->editCopy($this->lastCanonicalEdit(45435)),
        );
        $this->assertSame(TelegramNavigationRoot::query()->sole()->id, $intent->telegram_navigation_root_id);
    }

    #[DataProvider('confirmationLocales')]
    public function test_confirmation_surface_is_localized_for_supported_customer_locales(
        string $locale,
        string $expectedTitle,
        string $expectedNoInvoice,
    ): void {
        $this->fakeCanonicalTelegram();
        $customer = Customer::factory()->create(['telegram_id' => 45437, 'locale' => $locale]);
        $this->openFundingChooser($customer, 45437);
        $this->postTelegramWebhook($this->messageUpdate(45439, '/deposit 25000', [], 45437))->assertOk();

        $caption = $this->editCopy($this->lastCanonicalEdit(45437));
        $this->assertStringContainsString($expectedTitle, $caption);
        $this->assertStringContainsString('Rp 25.000', $caption);
        $this->assertStringContainsString($expectedNoInvoice, $caption);
    }

    public static function confirmationLocales(): array
    {
        return [
            'english' => ['en', 'Confirm Store Balance top-up', 'No payment invoice exists yet.'],
            'indonesian' => ['id', 'Konfirmasi tambah saldo', 'Belum ada invoice pembayaran.'],
            'malay' => ['ms', 'Sahkan tambah baki', 'Belum ada invois bayaran.'],
        ];
    }

    #[DataProvider('invalidDepositAmounts')]
    public function test_invalid_custom_deposit_reopens_current_chooser_without_side_effects(
        string $text,
        string $expectedCopy,
    ): void {
        $this->fakeCanonicalTelegram();
        $customer = Customer::factory()->create(['telegram_id' => 45440, 'locale' => 'en']);
        $this->openFundingChooser($customer, 45440);

        $this->postTelegramWebhook($this->messageUpdate(45442, $text, [], 45440))->assertOk();

        $this->assertSame(0, StoreBalanceFundingIntent::query()->count());
        $this->assertStringContainsString($expectedCopy, $this->editCopy($this->lastCanonicalEdit(45440)));
        $this->assertSame(1, $this->routineMessageCount(45440));
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, StoreBalanceEntry::query()->count());
    }

    public static function invalidDepositAmounts(): array
    {
        return [
            'leading zero' => ['/deposit 025000', 'Enter one whole-IDR amount'],
            'missing amount' => ['/deposit', 'Enter one whole-IDR amount'],
            'grouped digits' => ['/deposit 25,000', 'Enter one whole-IDR amount'],
            'decimal' => ['/deposit 25000.00', 'Enter one whole-IDR amount'],
            'signed' => ['/deposit +25000', 'Enter one whole-IDR amount'],
            'exponent' => ['/deposit 2e4', 'Enter one whole-IDR amount'],
            'trailing text' => ['/deposit 25000 extra', 'Enter one whole-IDR amount'],
            'overflow' => ['/deposit 999999999999999999999999999999', 'Enter one whole-IDR amount'],
            'below minimum' => ['/deposit 4999', 'The minimum top-up is Rp 5.000'],
        ];
    }

    public function test_confirmation_claims_are_replay_safe_and_do_not_create_payment_work(): void
    {
        $this->fakeCanonicalTelegram();
        $customer = Customer::factory()->create(['telegram_id' => 45451, 'locale' => 'en']);
        $root = $this->openFundingChooser($customer, 45451);
        $preset = $this->lastCanonicalEdit(45451)['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $this->postTelegramWebhook($this->callbackUpdate(45454, 'funding-preset-real', $preset, [], 45451, 'private', $root->message_id))->assertOk();
        $intent = StoreBalanceFundingIntent::query()->sole();
        $confirm = 'balance:confirm:'.$intent->nonce;

        $this->postTelegramWebhook($this->callbackUpdate(45455, 'funding-confirm', $confirm, [], 45451, 'private', $root->message_id))->assertOk();
        $this->assertSame(StoreBalanceFundingIntentStatus::Confirmed, $intent->fresh()->status);
        $accepted = $this->editCopy($this->lastCanonicalEdit(45451));
        $this->assertStringContainsString('Confirmation received.', $accepted);
        $this->assertStringContainsString('Store Balance credit: Rp 5.000', $accepted);

        $this->postTelegramWebhook($this->callbackUpdate(45456, 'funding-confirm-replay', $confirm, [], 45451, 'private', $root->message_id))->assertOk();
        $this->assertSame(StoreBalanceFundingIntentStatus::Confirmed, $intent->fresh()->status);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && ($request['callback_query_id'] ?? null) === 'funding-confirm-replay'
            && ($request['text'] ?? null) === 'This confirmation was already accepted.');
        $this->assertStringContainsString(
            'This confirmation was already accepted.',
            $this->editCopy($this->lastCanonicalEdit(45451)),
        );

        $this->postTelegramWebhook($this->messageUpdate(45457, '/deposit 10000', [], 45451))->assertOk();
        $cancelledIntent = StoreBalanceFundingIntent::query()->where('status', StoreBalanceFundingIntentStatus::Pending)->sole();
        $cancel = 'balance:cancel:'.$cancelledIntent->nonce;
        $this->postTelegramWebhook($this->callbackUpdate(45458, 'funding-cancel', $cancel, [], 45451, 'private', $root->message_id))->assertOk();
        $this->assertSame(StoreBalanceFundingIntentStatus::Cancelled, $cancelledIntent->fresh()->status);
        $this->assertStringContainsString('Top-up confirmation cancelled.', $this->editCopy($this->lastCanonicalEdit(45451)));

        $this->postTelegramWebhook($this->callbackUpdate(45459, 'funding-cancel-replay', $cancel, [], 45451, 'private', $root->message_id))->assertOk();
        $this->assertSame(StoreBalanceFundingIntentStatus::Cancelled, $cancelledIntent->fresh()->status);
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, StoreBalanceEntry::query()->count());
        $this->assertSame(0, Http::recorded(
            fn (Request $request): bool => str_contains($request->url(), 'klikqris'),
        )->count());
    }

    public function test_expired_confirmation_recovers_to_current_chooser(): void
    {
        $this->fakeCanonicalTelegram();
        $customer = Customer::factory()->create(['telegram_id' => 45461, 'locale' => 'en']);
        $root = $this->openFundingChooser($customer, 45461);
        $this->postTelegramWebhook($this->messageUpdate(45463, '/deposit 25000', [], 45461))->assertOk();
        $intent = StoreBalanceFundingIntent::query()->sole();
        $intent->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->postTelegramWebhook($this->callbackUpdate(
            45464,
            'funding-expired',
            'balance:confirm:'.$intent->nonce,
            [],
            45461,
            'private',
            $root->message_id,
        ))->assertOk();

        $this->assertSame(StoreBalanceFundingIntentStatus::Expired, $intent->fresh()->status);
        $this->assertStringContainsString(
            'This top-up confirmation expired.',
            $this->editCopy($this->lastCanonicalEdit(45461)),
        );
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, StoreBalanceEntry::query()->count());
    }

    public function test_policy_change_invalidates_pending_confirmation_without_provider_work(): void
    {
        $this->fakeCanonicalTelegram();
        $customer = Customer::factory()->create(['telegram_id' => 45471, 'locale' => 'en']);
        $root = $this->openFundingChooser($customer, 45471);
        $this->postTelegramWebhook($this->messageUpdate(45473, '/deposit 25000', [], 45471))->assertOk();
        $intent = StoreBalanceFundingIntent::query()->sole();

        StoreBalanceFundingPolicy::query()->firstOrFail()->forceFill([
            'minimum_amount' => 5_000,
            'preset_amounts' => [5_000, 15_000],
            'version' => 1,
        ])->save();

        $this->postTelegramWebhook($this->callbackUpdate(
            45474,
            'funding-policy-stale',
            'balance:confirm:'.$intent->nonce,
            [],
            45471,
            'private',
            $root->message_id,
        ))->assertOk();

        $this->assertSame(StoreBalanceFundingIntentStatus::Superseded, $intent->fresh()->status);
        $this->assertStringContainsString(
            'This top-up confirmation is no longer current.',
            $this->editCopy($this->lastCanonicalEdit(45471)),
        );
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, StoreBalanceEntry::query()->count());
    }

    public function test_foreign_confirmation_message_recovers_on_current_canonical_root(): void
    {
        $this->fakeCanonicalTelegram();
        $customer = Customer::factory()->create(['telegram_id' => 45475, 'locale' => 'en']);
        $root = $this->openFundingChooser($customer, 45475);
        $this->postTelegramWebhook($this->messageUpdate(45477, '/deposit 25000', [], 45475))->assertOk();
        $intent = StoreBalanceFundingIntent::query()->sole();

        $this->postTelegramWebhook($this->callbackUpdate(
            45478,
            'funding-foreign-message',
            'balance:confirm:'.$intent->nonce,
            [],
            45475,
            'private',
            $root->message_id + 999,
        ))->assertOk();

        $this->assertSame(StoreBalanceFundingIntentStatus::Pending, $intent->fresh()->status);
        $this->assertStringContainsString(
            'This top-up confirmation is no longer current.',
            $this->editCopy($this->lastCanonicalEdit(45475)),
        );
        $this->assertSame(4, $this->editCount(45475));
        $this->assertSame(1, $this->routineMessageCount(45475));
    }

    public function test_custom_deposit_is_private_only_and_does_not_create_intent_in_group(): void
    {
        $this->fakeCanonicalTelegram();
        Customer::factory()->create(['telegram_id' => 45481, 'locale' => 'en']);

        $this->postTelegramWebhook($this->messageUpdate(
            45482,
            '/deposit 25000',
            ['id' => 45481],
            -10045481,
            'group',
        ))->assertOk();

        $this->assertSame(0, StoreBalanceFundingIntent::query()->count());
        $this->assertSame(0, StoreBalanceEntry::query()->count());
        $this->assertSame(0, Payment::query()->count());
    }

    private function openFundingChooser(Customer $customer, int $updateId): TelegramNavigationRoot
    {
        $chatId = (int) $customer->telegram_id;
        $this->postTelegramWebhook($this->messageUpdate($updateId, '/start', [], $chatId))->assertOk();
        $root = TelegramNavigationRoot::query()->sole();
        $this->postTelegramWebhook($this->callbackUpdate(
            $updateId + 100,
            'funding-open-'.$chatId,
            'balance:add',
            [],
            $chatId,
            'private',
            $root->message_id,
        ))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(
            $updateId + 101,
            'funding-idr-'.$chatId,
            'balance:currency:idr',
            [],
            $chatId,
            'private',
            $root->message_id,
        ))->assertOk();

        return $root->fresh();
    }

    private function paidOrder(Customer $customer, string $invoice): Order
    {
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => $invoice,
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        return $order;
    }

    private function fakeCanonicalTelegram(mixed $editResponse = null): void
    {
        $nextMessageId = 700;
        config()->set('services.telegram.welcome_banner_url', 'banner-file-id');
        $editResolver = $editResponse instanceof \Closure
            ? $editResponse
            : fn (Request $request) => $editResponse ?? Http::response(['ok' => true]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => function () use (&$nextMessageId) {
                $nextMessageId++;

                return Http::response([
                    'ok' => true,
                    'result' => ['message_id' => $nextMessageId, 'photo' => [['file_id' => 'banner-'.$nextMessageId]]],
                ]);
            },
            'https://api.telegram.org/*/sendMessage' => function () use (&$nextMessageId) {
                $nextMessageId++;

                return Http::response(['ok' => true, 'result' => ['message_id' => $nextMessageId]]);
            },
            'https://api.telegram.org/*/editMessageCaption' => fn (Request $request) => $editResolver($request),
            'https://api.telegram.org/*/editMessageText' => fn (Request $request) => $editResolver($request),
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);
    }

    private function lastCanonicalEdit(int $chatId): array
    {
        $edits = Http::recorded(fn (Request $request): bool => $this->isEditRequest($request)
            && (string) ($request['chat_id'] ?? '') === (string) $chatId);
        $this->assertNotEmpty($edits, "Expected a canonical edit for chat {$chatId}.");

        return $edits->last()[0]->data();
    }

    private function editCount(int $chatId): int
    {
        return Http::recorded(fn (Request $request): bool => $this->isEditRequest($request)
            && (string) ($request['chat_id'] ?? '') === (string) $chatId)->count();
    }

    private function routineMessageCount(int $chatId): int
    {
        return Http::recorded(fn (Request $request): bool => (str_ends_with($request->url(), '/sendMessage')
                || str_ends_with($request->url(), '/sendPhoto'))
            && (string) ($request['chat_id'] ?? '') === (string) $chatId)->count();
    }

    private function isEditRequest(Request $request): bool
    {
        return str_ends_with($request->url(), '/editMessageCaption')
            || str_ends_with($request->url(), '/editMessageText');
    }

    private function editCopy(array $edit): string
    {
        return (string) ($edit['caption'] ?? $edit['text'] ?? '');
    }
}
