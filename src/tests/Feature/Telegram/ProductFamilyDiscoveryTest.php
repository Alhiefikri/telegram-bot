<?php

namespace Tests\Feature\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Cart;
use App\Models\CatalogBrandIcon;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Services\Catalog\CatalogBrandIcons;
use App\Services\Telegram\TelegramCatalogIntents;
use App\Services\Telegram\TelegramFamilyCallbackToken;
use App\Services\Telegram\TelegramIntentSession;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\TelegramBotTestSupport;

class ProductFamilyDiscoveryTest extends TelegramBotTestSupport
{
    public function test_product_menu_lists_family_and_standalone_entries_then_opens_ordered_options(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 41001, 'locale' => 'id']);
        $family = ProductFamily::factory()->create(['name' => 'Netflix', 'slug' => 'netflix']);
        $first = Product::factory()->create([
            'name' => 'Netflix Family',
            'product_family_id' => $family->id,
            'family_sort_order' => 1,
            'family_option_label' => 'Family 5 Profile',
            'price_amount' => 28000,
            'stock' => 0,
            'reserved_stock' => 0,
        ]);
        $second = Product::factory()->create([
            'name' => 'Netflix Individual',
            'product_family_id' => $family->id,
            'family_sort_order' => 2,
            'family_option_label' => 'Individual 1 Profile',
            'price_amount' => 25000,
            'stock' => 3,
            'reserved_stock' => 0,
        ]);
        $standalone = Product::factory()->create(['name' => 'Spotify Standalone', 'stock' => 2, 'reserved_stock' => 0]);

        $this->postTelegramWebhook($this->messageUpdate(41001, '/product', [], 41001))->assertOk();
        $catalog = $this->lastMessageContaining('Netflix');
        $catalogText = (string) $catalog['text'];
        $this->assertStringContainsString('DAFTAR PRODUK DIGITAL', $catalogText);
        $this->assertStringContainsString('Pilih keluarga atau produk', $catalogText);
        $this->assertStringNotContainsString('Netflix', $catalogText);
        $this->assertStringNotContainsString('Spotify Standalone', $catalogText);

        $keyboard = $catalog['reply_markup']['inline_keyboard'];
        $familyButton = $keyboard[0][0];
        $this->assertStringContainsString('Netflix', $familyButton['text']);
        $this->assertStringContainsString('▶ 2 Plan »', $familyButton['text']);
        $familyCallback = $familyButton['callback_data'];
        $this->assertStringStartsWith('family:options:v', $familyCallback);
        $this->assertCount(1, $keyboard[0]);
        $this->assertSame('product:'.$standalone->id, $keyboard[1][0]['callback_data']);
        $this->assertStringContainsString('Spotify Standalone', $keyboard[1][0]['text']);
        $this->assertStringContainsString('Stok 2', $keyboard[1][0]['text']);
        $this->assertCount(1, $keyboard[1]);
        $this->assertCount(4, $keyboard);

        $this->postTelegramWebhook($this->callbackUpdate(41002, 'family-open', 'family:options:'.$family->id.':1,', [], 41001))->assertOk();
        $this->assertSame(0, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage') && str_contains((string) ($request['text'] ?? ''), 'Family 5 Profile'))->count());
        $this->postTelegramWebhook($this->callbackUpdate(41003, 'family-open-valid', $familyCallback, [], 41001))->assertOk();
        $options = $this->lastMessageContaining('Family 5 Profile');
        $this->assertStringContainsString('01. ', (string) $options['text']);
        $this->assertStringContainsString('Family 5 Profile', (string) $options['text']);
        $this->assertStringContainsString('Harga: Rp28.000', (string) $options['text']);
        $this->assertStringContainsString('❌ Stok: Habis', (string) $options['text']);
        $this->assertStringContainsString('02. ', (string) $options['text']);
        $this->assertStringContainsString('Individual 1 Profile', (string) $options['text']);
        $this->assertStringContainsString('Stok tersedia: 3', (string) $options['text']);
        $this->assertStringContainsString('Terjual: 0', (string) $options['text']);
        $soldOutButton = $options['reply_markup']['inline_keyboard'][0][0];
        $this->assertSame('danger', $soldOutButton['style']);
        $this->assertSame('{}', json_encode($soldOutButton['disabled'], JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('callback_data', $soldOutButton);
        $availableButton = $options['reply_markup']['inline_keyboard'][0][1];
        $this->assertSame('primary', $availableButton['style']);
        $this->assertArrayNotHasKey('disabled', $availableButton);
        $this->assertStringStartsWith('family:select:v', $availableButton['callback_data']);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains($request->body(), '"style":"danger","disabled":{}'));
        $this->assertStringStartsWith('family:list:1:o', $options['reply_markup']['inline_keyboard'][1][0]['callback_data']);
        $selectCallback = $availableButton['callback_data'];
        $this->postTelegramWebhook($this->callbackUpdate(41004, 'family-select-token', $selectCallback, [], 41001))->assertOk();
        $this->assertStringContainsString('Netflix Individual', (string) $this->lastMessageContaining('Netflix Individual')['text']);
        $selection = app(TelegramIntentSession::class)->get($customer);
        $this->assertIsArray($selection);

        $this->postTelegramWebhook($this->callbackUpdate(41005, 'family-detail-back', 'detail:back:'.$second->id.':'.$selection['interactionToken'], [], 41001))->assertOk();
        $optionsAfterBack = $this->lastMessageContaining('Family 5 Profile');
        $catalogBackCallback = collect($optionsAfterBack['reply_markup']['inline_keyboard'])
            ->flatten(1)
            ->first(fn (array $button): bool => str_starts_with((string) ($button['callback_data'] ?? ''), 'family:list:'));
        $this->assertIsArray($catalogBackCallback);
        $this->postTelegramWebhook($this->callbackUpdate(41006, 'family-catalog-back', $catalogBackCallback['callback_data'], [], 41001))->assertOk();
        $catalogAfterBack = $this->lastMessageContaining('Netflix');
        $this->assertStringNotContainsString('Netflix Individual', (string) $catalogAfterBack['text']);
    }

    public function test_family_options_aggregate_sold_quantity_across_paid_processing_variants(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 41006, 'locale' => 'id']);
        $family = ProductFamily::factory()->create(['name' => 'Aggregate Family', 'slug' => 'aggregate-family']);
        $first = Product::factory()->create([
            'name' => 'Aggregate Basic',
            'product_family_id' => $family->id,
            'family_sort_order' => 1,
            'family_option_label' => 'Basic',
            'stock' => 2,
            'reserved_stock' => 0,
        ]);
        $second = Product::factory()->create([
            'name' => 'Aggregate Premium',
            'product_family_id' => $family->id,
            'family_sort_order' => 2,
            'family_option_label' => 'Premium',
            'stock' => 2,
            'reserved_stock' => 0,
        ]);
        $order = Order::factory()->create([
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
        ]);
        Payment::factory()->create([
            'order_id' => $order->id,
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $first->id, 'quantity' => 4]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $second->id, 'quantity' => 6]);

        $this->openFamilyFromDeepLink($customer, 41007, $family);

        $options = $this->lastMessageContaining('Pilih Plan Aggregate Family');
        $this->assertStringContainsString('Terjual: 10', (string) $options['text']);
        $this->assertStringContainsString('Terjual: 4', (string) $options['text']);
        $this->assertStringContainsString('Terjual: 6', (string) $options['text']);
    }

    public function test_product_menu_paginates_fifteen_product_families_per_page(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 41007, 'locale' => 'en']);

        foreach (range(1, 16) as $number) {
            $family = ProductFamily::factory()->create([
                'name' => 'Paged Family '.$number,
                'slug' => 'paged-family-'.$number,
                'sort_order' => $number,
            ]);
            Product::factory()->create([
                'name' => 'Paged Product '.$number,
                'product_family_id' => $family->id,
                'family_sort_order' => 1,
                'stock' => 1,
                'reserved_stock' => 0,
            ]);
        }

        $this->postTelegramWebhook($this->messageUpdate(41007, '/produk', [], 41007))->assertOk();
        $firstPage = $this->lastMessageContaining('Paged Family 15');
        $this->assertStringContainsString('Choose a family or product', (string) $firstPage['text']);
        $this->assertStringNotContainsString('Paged Family 15', (string) $firstPage['text']);
        $this->assertStringNotContainsString('Paged Family 16', (string) $firstPage['text']);
        $keyboard = $firstPage['reply_markup']['inline_keyboard'];
        $familyRows = array_slice($keyboard, 0, 15);
        $this->assertCount(15, $familyRows);
        foreach ($familyRows as $row) {
            $this->assertCount(1, $row);
        }
        $this->assertSame('family:list:2', $keyboard[15][0]['callback_data']);

        $this->postTelegramWebhook($this->callbackUpdate(41008, 'paged-family-next', 'family:list:2', [], 41007))->assertOk();
        $secondPage = $this->lastMessageContaining('Paged Family 16');
        $this->assertStringContainsString('Page 2 of 2', (string) $secondPage['text']);
        $this->assertStringNotContainsString('Paged Family 15', (string) $secondPage['text']);
    }

    public function test_signed_family_option_callback_is_rejected_without_current_catalog_state(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 41012, 'locale' => 'id']);
        $family = ProductFamily::factory()->create(['name' => 'Replay Family', 'slug' => 'replay-family']);
        Product::factory()->create([
            'name' => 'Replay Option',
            'product_family_id' => $family->id,
            'family_option_label' => 'Replay Package',
            'stock' => 1,
            'reserved_stock' => 0,
        ]);

        $this->postTelegramWebhook($this->callbackUpdate(
            41016,
            'replayed-family-callback',
            $this->optionsCallback($family->id),
            [],
            41012,
        ))->assertOk();

        $this->assertSame(0, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Replay Package'))->count());
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && ($request['callback_query_id'] ?? null) === 'replayed-family-callback'
            && str_contains((string) ($request['text'] ?? ''), 'sudah tidak berlaku'));
        $this->assertSame(0, Cart::count());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_family_option_callbacks_reject_invalid_contexts_without_commerce_effects(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 41017, 'locale' => 'id']);
        $family = ProductFamily::factory()->create(['name' => 'Invalid Family', 'slug' => 'invalid-family']);
        $firstProduct = Product::factory()->create([
            'name' => 'Invalid Option 1',
            'product_family_id' => $family->id,
            'stock' => 1,
            'reserved_stock' => 0,
        ]);
        foreach (range(2, 5) as $number) {
            Product::factory()->create([
                'name' => 'Invalid Option '.$number,
                'product_family_id' => $family->id,
                'family_sort_order' => $number,
                'stock' => 1,
                'reserved_stock' => 0,
            ]);
        }
        $otherFamily = ProductFamily::factory()->create(['name' => 'Other Invalid Family', 'slug' => 'other-invalid-family']);
        Product::factory()->create([
            'name' => 'Other Invalid Option',
            'product_family_id' => $otherFamily->id,
            'stock' => 1,
            'reserved_stock' => 0,
        ]);

        $this->openFamilyFromDeepLink($customer, 41017, $family);
        $before = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'))->count();
        $validOptionsCallback = $this->optionsCallback($family->id);
        $tamperedOptionsCallback = substr_replace(
            $validOptionsCallback,
            $validOptionsCallback[-2] === 'a' ? 'b' : 'a',
            -2,
            1,
        );
        $cases = [
            ['out-of-range', $this->optionsCallback($family->id, 99)],
            ['cross-family', $this->optionsCallback($otherFamily->id)],
            ['malformed', 'family:options:vnot-a-real-token'],
            ['tampered', $tamperedOptionsCallback],
        ];

        foreach ($cases as [$callbackId, $data]) {
            $invalidCallbackIds[] = $callbackId;
            $this->postTelegramWebhook($this->callbackUpdate(
                41018 + count($invalidCallbackIds),
                $callbackId,
                $data,
                [],
                41017,
            ))->assertOk();
        }

        Product::query()->where('product_family_id', $family->id)->update(['is_active' => false]);
        $invalidCallbackIds[] = 'inactive-family';
        $this->postTelegramWebhook($this->callbackUpdate(
            41023,
            'inactive-family',
            $this->optionsCallback($family->id),
            [],
            41017,
        ))->assertOk();

        $this->openFamilyFromDeepLink($customer, 41024, $otherFamily);
        $otherFamily->delete();
        $invalidCallbackIds[] = 'deleted-family';
        $this->postTelegramWebhook($this->callbackUpdate(
            41025,
            'deleted-family',
            $this->optionsCallback($otherFamily->id),
            [],
            41017,
        ))->assertOk();

        $after = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'))->count();
        $this->assertSame($before + 1, $after);
        foreach ($invalidCallbackIds as $callbackId) {
            Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
                && ($request['callback_query_id'] ?? null) === $callbackId
                && str_contains((string) ($request['text'] ?? ''), 'sudah tidak berlaku'));
        }
        $this->assertSame(0, Cart::count());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_origin_qualified_family_callback_cannot_cross_current_family(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 41016, 'locale' => 'en']);
        $firstFamily = ProductFamily::factory()->create(['name' => 'First Origin Family', 'slug' => 'first-origin-family']);
        $secondFamily = ProductFamily::factory()->create(['name' => 'Second Origin Family', 'slug' => 'second-origin-family']);
        Product::factory()->create([
            'name' => 'First Origin Product',
            'product_family_id' => $firstFamily->id,
            'family_sort_order' => 1,
            'stock' => 1,
            'reserved_stock' => 0,
        ]);
        Product::factory()->create([
            'name' => 'Second Origin Product',
            'product_family_id' => $secondFamily->id,
            'family_sort_order' => 1,
            'stock' => 1,
            'reserved_stock' => 0,
        ]);
        Product::factory()->create([
            'name' => 'First Origin Add-on',
            'product_family_id' => $firstFamily->id,
            'family_sort_order' => 2,
            'stock' => 1,
            'reserved_stock' => 0,
        ]);
        Product::factory()->create([
            'name' => 'Second Origin Add-on',
            'product_family_id' => $secondFamily->id,
            'family_sort_order' => 2,
            'stock' => 1,
            'reserved_stock' => 0,
        ]);

        $this->postTelegramWebhook($this->messageUpdate(41026, '/produk', [], 41016))->assertOk();
        $catalog = $this->lastMessageContaining('First Origin Family');
        $familyButtons = collect($catalog['reply_markup']['inline_keyboard'])
            ->flatten(1)
            ->filter(fn (array $button): bool => str_starts_with((string) ($button['callback_data'] ?? ''), 'family:options:'))
            ->values();
        $firstCallback = $familyButtons[0]['callback_data'];
        $secondCallback = $familyButtons[1]['callback_data'];
        [, $originToken] = explode(':o', substr($firstCallback, strlen('family:options:')), 2);
        [$secondToken] = explode(':o', substr($secondCallback, strlen('family:options:')), 2);

        $this->postTelegramWebhook($this->callbackUpdate(
            41027,
            'first-origin-family',
            $firstCallback,
            [],
            41016,
            'private',
            123457,
        ))->assertOk();
        $this->assertSame(1, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageText'))->count());

        $this->postTelegramWebhook($this->callbackUpdate(
            41028,
            'cross-origin-family',
            'family:options:'.$secondToken.':o'.$originToken,
            [],
            41016,
            'private',
            123457,
        ))->assertOk();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && ($request['callback_query_id'] ?? null) === 'cross-origin-family'
            && str_contains((string) ($request['text'] ?? ''), 'expired'));
    }

    public function test_family_option_callback_is_rejected_after_catalog_context_changes(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 41011, 'locale' => 'id']);
        $family = ProductFamily::factory()->create(['name' => 'Stale Family', 'slug' => 'stale-family']);
        Product::factory()->create([
            'name' => 'Stale Option',
            'product_family_id' => $family->id,
            'family_sort_order' => 1,
            'family_option_label' => 'Stale Package',
            'stock' => 1,
            'reserved_stock' => 0,
        ]);
        Product::factory()->create([
            'name' => 'Stale Option Extra',
            'product_family_id' => $family->id,
            'family_sort_order' => 2,
            'family_option_label' => 'Stale Package Extra',
            'stock' => 1,
            'reserved_stock' => 0,
        ]);

        $this->postTelegramWebhook($this->messageUpdate(41013, '/produk', [], 41011))->assertOk();
        $catalog = $this->lastMessageContaining('Stale Family');
        $familyCallback = $catalog['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $this->postTelegramWebhook($this->messageUpdate(41014, '/produk', [], 41011))->assertOk();
        $before = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Stale Package'))->count();

        $this->postTelegramWebhook($this->callbackUpdate(41015, 'stale-family-callback', $familyCallback, [], 41011))->assertOk();
        $after = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Stale Package'))->count();
        $this->assertSame($before, $after);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && ($request['callback_query_id'] ?? null) === 'stale-family-callback'
            && str_contains((string) ($request['text'] ?? ''), 'sudah tidak berlaku'));
    }

    public function test_family_option_selection_reuses_product_detail_and_back_context(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 41002, 'locale' => 'en']);
        $family = ProductFamily::factory()->create(['name' => 'CapCut', 'slug' => 'capcut']);
        $product = Product::factory()->create([
            'name' => 'CapCut Team 30 Days',
            'product_family_id' => $family->id,
            'family_sort_order' => 1,
            'family_option_label' => 'Team 30 Days',
            'price_amount' => 35000,
            'stock' => 2,
            'reserved_stock' => 0,
        ]);

        $this->openFamilyFromDeepLink($customer, 41008, $family);
        $this->postTelegramWebhook($this->callbackUpdate(41011, 'select-family-option', $this->selectCallback($family->id, $product->id), [], 41002))->assertOk();

        $detail = $this->lastMessageContaining('CapCut Team 30 Days');
        $this->assertStringContainsString('Price: Rp35.000', (string) $detail['text']);
        $this->assertStringContainsString('Available stock: 2', (string) $detail['text']);
        $this->assertStringContainsString('detail:pay:qris:'.$product->id.':', json_encode($detail, JSON_THROW_ON_ERROR));
        $state = app(TelegramIntentSession::class)->get($customer);
        $this->assertSame($family->id, $state['familyId']);
        $this->assertSame(1, $state['familyPage']);

        $this->postTelegramWebhook($this->callbackUpdate(41012, 'back-family', 'detail:back:'.$product->id.':'.$state['interactionToken'], [], 41002))->assertOk();
        $back = $this->lastMessageContaining('Choose a plan for CapCut');
        $this->assertStringContainsString('Team 30 Days', (string) $back['text']);
        $this->assertSame('family:list:1', $back['reply_markup']['inline_keyboard'][1][0]['callback_data']);
        $this->assertSame(0, Cart::count());
        $this->assertSame(0, Order::count());
    }

    public function test_sold_out_family_option_is_readable_and_side_effect_free(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 41003, 'locale' => 'id']);
        $family = ProductFamily::factory()->create(['name' => 'Sold Out Family', 'slug' => 'sold-out-family']);
        $product = Product::factory()->create([
            'name' => 'Sold Out Option',
            'product_family_id' => $family->id,
            'family_option_label' => 'Unavailable Package',
            'stock' => 1,
            'reserved_stock' => 1,
            'is_active' => true,
        ]);

        $this->openFamilyFromDeepLink($customer, 41018, $family);
        $this->postTelegramWebhook($this->callbackUpdate(41021, 'select-sold-option', $this->selectCallback($family->id, $product->id), [], 41003))->assertOk();

        $detail = $this->lastMessageContaining('Sold Out Option');
        $this->assertStringContainsString('Stok kosong', (string) $detail['text']);
        $this->assertStringNotContainsString('detail:pay:', json_encode($detail, JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('family:options:v', json_encode($detail, JSON_THROW_ON_ERROR));
        $this->assertSame(0, Cart::count());
        $this->assertSame(0, Order::count());
    }

    public function test_family_search_collapses_member_matches_and_tampered_selection_has_no_commerce_effect(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 41004, 'locale' => 'id']);
        $family = ProductFamily::factory()->create(['name' => 'Netflix', 'slug' => 'netflix']);
        $member = Product::factory()->create([
            'name' => 'Netflix Individual',
            'description' => 'Netflix profile package',
            'product_family_id' => $family->id,
            'family_sort_order' => 1,
            'family_option_label' => 'Individual',
            'stock' => 2,
            'reserved_stock' => 0,
        ]);
        Product::factory()->create([
            'name' => 'Netflix Shared',
            'description' => 'Netflix shared package',
            'product_family_id' => $family->id,
            'family_sort_order' => 2,
            'family_option_label' => 'Shared',
            'stock' => 2,
            'reserved_stock' => 0,
        ]);
        $otherFamily = ProductFamily::factory()->create(['name' => 'Spotify', 'slug' => 'spotify']);
        $other = Product::factory()->create([
            'name' => 'Spotify Premium',
            'product_family_id' => $otherFamily->id,
            'stock' => 2,
            'reserved_stock' => 0,
        ]);

        $this->postTelegramWebhook($this->messageUpdate(41030, '/cari netflix', [], 41004))->assertOk();
        $search = $this->lastMessageContaining('Hasil pencarian: netflix');
        $this->assertStringContainsString('Netflix', (string) $search['text']);
        $this->assertStringNotContainsString('Netflix Individual', (string) $search['text']);
        $this->assertStringStartsWith('family:options:v', $search['reply_markup']['inline_keyboard'][0][0]['callback_data']);
        $this->assertStringNotContainsString('product:'.$member->id, json_encode($search, JSON_THROW_ON_ERROR));

        $validSelectionCallback = $this->selectCallback($family->id, $other->id);
        $lastCharacter = substr($validSelectionCallback, -1);
        $tamperedSelectionCallback = substr($validSelectionCallback, 0, -1).($lastCharacter === 'a' ? 'b' : 'a');
        $this->postTelegramWebhook($this->callbackUpdate(41031, 'tampered-family-option', $tamperedSelectionCallback, [], 41004))->assertOk();
        $this->assertSame(0, Cart::count());
        $this->assertSame(0, Order::count());
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && ($request['callback_query_id'] ?? null) === 'tampered-family-option'
            && str_contains((string) ($request['text'] ?? ''), 'sudah tidak berlaku'));
    }

    public function test_family_back_restores_search_origin_without_exposing_member_duplicates(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 41010, 'locale' => 'id']);
        $family = ProductFamily::factory()->create(['name' => 'Origin Family', 'slug' => 'origin-family']);
        Product::factory()->create([
            'name' => 'Origin Family Member',
            'description' => 'Origin search member',
            'product_family_id' => $family->id,
            'family_sort_order' => 1,
            'family_option_label' => 'Origin Package',
            'stock' => 1,
            'reserved_stock' => 0,
        ]);
        Product::factory()->create([
            'name' => 'Origin Family Extra',
            'description' => 'Origin search extra',
            'product_family_id' => $family->id,
            'family_sort_order' => 2,
            'family_option_label' => 'Origin Package Extra',
            'stock' => 1,
            'reserved_stock' => 0,
        ]);

        $this->postTelegramWebhook($this->messageUpdate(41032, '/cari origin', [], 41010))->assertOk();
        $search = $this->lastMessageContaining('Hasil pencarian: origin');
        $familyCallback = $search['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $this->assertStringContainsString(':o', $familyCallback);
        $this->postTelegramWebhook($this->callbackUpdate(41033, 'origin-family-open', $familyCallback, [], 41010))->assertOk();

        $options = $this->lastMessageContaining('Origin Package');
        $backCallback = $options['reply_markup']['inline_keyboard'][1][0]['callback_data'];
        $this->assertMatchesRegularExpression('/^family:list:1:o[a-f0-9]{16}$/', $backCallback);
        $this->postTelegramWebhook($this->callbackUpdate(41034, 'origin-family-back', $backCallback, [], 41010))->assertOk();
        $restored = $this->lastMessageContaining('Hasil pencarian: origin');
        $this->assertStringContainsString('Origin Family', (string) $restored['text']);
        $this->assertStringNotContainsString('Origin Family Member', (string) $restored['text']);
    }

    public function test_family_options_use_ten_per_page_and_bounded_navigation(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 41005, 'locale' => 'en']);
        $family = ProductFamily::factory()->create(['name' => 'Paged Family', 'slug' => 'paged-family']);
        foreach (range(1, 11) as $number) {
            Product::factory()->create([
                'name' => 'Paged Option '.$number,
                'product_family_id' => $family->id,
                'family_sort_order' => $number,
                'family_option_label' => 'Package '.$number,
                'stock' => 2,
                'reserved_stock' => 0,
            ]);
        }

        $this->openFamilyFromDeepLink($customer, 41038, $family);
        $first = $this->lastMessageContaining('Choose a plan for Paged Family');
        $firstKeyboard = $first['reply_markup']['inline_keyboard'];
        $this->assertCount(7, $firstKeyboard);
        $optionRows = array_slice($firstKeyboard, 0, 5);
        foreach ($optionRows as $row) {
            $this->assertCount(2, $row);
        }
        $this->assertStringStartsWith('family:options:v', $firstKeyboard[5][0]['callback_data']);
        $this->assertSame('family:list:1', $firstKeyboard[6][0]['callback_data']);
        $this->assertStringContainsString('01. 🛍️ Package 1', (string) $first['text']);
        $this->assertStringContainsString('10. 🛍️ Package 10', (string) $first['text']);
        $this->assertStringNotContainsString('11. 🛍️ Package 11', (string) $first['text']);

        $this->postTelegramWebhook($this->callbackUpdate(41041, 'options-page-two', $firstKeyboard[5][0]['callback_data'], [], 41005))->assertOk();
        $second = $this->lastMessageContaining('Package 11');
        $secondKeyboard = $second['reply_markup']['inline_keyboard'];
        $this->assertCount(3, $secondKeyboard);
        $this->assertStringStartsWith('family:select:v', $secondKeyboard[0][0]['callback_data']);
        $this->assertStringStartsWith('family:options:v', $secondKeyboard[1][0]['callback_data']);
        $this->assertSame('family:list:1', $secondKeyboard[2][0]['callback_data']);
        $this->assertStringContainsString('01. 🛍️ Package 11', (string) $second['text']);
        $messagesBeforeReplay = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'))->count();
        $this->postTelegramWebhook($this->callbackUpdate(41042, 'replayed-options-page', $firstKeyboard[5][0]['callback_data'], [], 41005))->assertOk();
        $this->assertSame($messagesBeforeReplay, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'))->count());
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && ($request['callback_query_id'] ?? null) === 'replayed-options-page'
            && str_contains((string) ($request['text'] ?? ''), 'expired'));
    }

    public function test_first_time_product_and_family_start_targets_open_immediately_in_indonesian(): void
    {
        $this->fakeTelegramHttp();
        $product = Product::factory()->create(['name' => 'Deep Linked Product', 'stock' => 2, 'reserved_stock' => 0]);
        $family = ProductFamily::factory()->create(['name' => 'Deep Linked Family', 'slug' => 'deep-linked-family']);
        Product::factory()->create([
            'name' => 'Deep Linked Option',
            'product_family_id' => $family->id,
            'family_option_label' => 'Option A',
            'stock' => 1,
            'reserved_stock' => 0,
        ]);

        $this->postTelegramWebhook($this->messageUpdate(41050, '/start product_'.$product->id, [], 41006))->assertOk();
        $deepLink = $this->lastMessageContaining('Deep Linked Product');
        $this->assertStringContainsString('Detail produk', (string) $deepLink['text']);
        $this->assertDatabaseHas('customers', ['telegram_id' => 41006, 'locale' => 'id']);

        $this->postTelegramWebhook($this->messageUpdate(41052, '/start family_'.$family->id, [], 41007))->assertOk();
        $familyOptions = $this->lastMessageContaining('Pilih Plan Deep Linked Family');
        $this->assertStringContainsString('Option A', (string) $familyOptions['text']);
        $this->assertDatabaseHas('customers', ['telegram_id' => 41007, 'locale' => 'id']);
    }

    public function test_search_pagination_token_is_rejected_after_leaving_search_context(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 41006, 'locale' => 'id']);
        foreach (range(1, 11) as $number) {
            Product::factory()->create([
                'name' => 'Alpha Standalone '.$number,
                'stock' => 2,
                'reserved_stock' => 0,
            ]);
        }

        $this->postTelegramWebhook($this->messageUpdate(41070, '/cari alpha', [], 41006))->assertOk();
        $search = $this->lastMessageContaining('Hasil pencarian: alpha');
        $searchButtons = $search['reply_markup']['inline_keyboard'];
        $nextButton = collect($searchButtons)->flatten(1)->first(fn (array $button): bool => str_starts_with((string) ($button['callback_data'] ?? ''), 'family:search:'));
        $this->assertIsArray($nextButton);
        $next = $nextButton['callback_data'];

        $this->postTelegramWebhook($this->messageUpdate(41071, '/produk', [], 41006))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(41072, 'stale-search-page', $next, [], 41006))->assertOk();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && ($request['callback_query_id'] ?? null) === 'stale-search-page'
            && str_contains((string) ($request['text'] ?? ''), 'sudah tidak berlaku'));
    }

    public function test_detail_back_rejects_family_context_after_product_moves(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 41007, 'locale' => 'en']);
        $original = ProductFamily::factory()->create(['name' => 'Original Family', 'slug' => 'original-family']);
        $destination = ProductFamily::factory()->create(['name' => 'Destination Family', 'slug' => 'destination-family']);
        $product = Product::factory()->create([
            'name' => 'Moved Family SKU',
            'product_family_id' => $original->id,
            'family_option_label' => 'Moved Option',
            'stock' => 2,
            'reserved_stock' => 0,
        ]);
        Product::factory()->create([
            'name' => 'Destination Other SKU',
            'product_family_id' => $destination->id,
            'family_option_label' => 'Other Option',
            'stock' => 2,
            'reserved_stock' => 0,
        ]);

        $this->openFamilyFromDeepLink($customer, 41078, $original);
        $this->postTelegramWebhook($this->callbackUpdate(41081, 'move-select', $this->selectCallback($original->id, $product->id), [], 41007))->assertOk();
        $state = app(TelegramIntentSession::class)->get($customer);
        $messagesBeforeBack = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage') && str_contains((string) ($request['text'] ?? ''), 'Original Family'))->count();

        $product->forceFill(['product_family_id' => $destination->id])->save();
        $this->postTelegramWebhook($this->callbackUpdate(41082, 'move-back-stale', 'detail:back:'.$product->id.':'.$state['interactionToken'], [], 41007))->assertOk();

        $messagesAfterBack = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage') && str_contains((string) ($request['text'] ?? ''), 'Original Family'))->count();
        $this->assertSame($messagesBeforeBack, $messagesAfterBack);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && ($request['callback_query_id'] ?? null) === 'move-back-stale'
            && str_contains((string) ($request['text'] ?? ''), 'expired'));
    }

    public function test_selected_family_product_reaches_existing_cart_and_order_identity(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => false,
            'admin_telegram_chat_id' => '999',
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 41009, 'locale' => 'en']);
        $family = ProductFamily::factory()->create(['name' => 'Checkout Family', 'slug' => 'checkout-family']);
        $selected = Product::factory()->create([
            'name' => 'Checkout Family SKU',
            'product_family_id' => $family->id,
            'family_option_label' => 'Selected SKU',
            'price_amount' => 11000,
            'stock' => 1,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        Product::factory()->create([
            'name' => 'Checkout Family Other SKU',
            'product_family_id' => $family->id,
            'family_option_label' => 'Other SKU',
            'price_amount' => 12000,
            'stock' => 1,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $this->openFamilyFromDeepLink($customer, 41088, $family);
        $this->postTelegramWebhook($this->callbackUpdate(41091, 'checkout-family-select', $this->selectCallback($family->id, $selected->id), [], 41009))->assertOk();
        $state = app(TelegramIntentSession::class)->get($customer);
        $this->postTelegramWebhook($this->callbackUpdate(41092, 'checkout-family-pay', 'detail:pay:qris:'.$selected->id.':'.$state['interactionToken'], [], 41009, 'private', 99009))->assertOk();

        $this->assertSame(1, Cart::count(), 'cart count');
        $this->assertSame(1, Order::count(), 'order count');
        $this->assertSame($selected->id, $customer->carts()->sole()->cartItems()->sole()->product_id, 'cart Product ID');
        $orderItem = Order::query()->sole()->orderItems()->sole();
        $this->assertSame($selected->id, $orderItem->product_id, 'order Product ID');
        $this->assertSame($family->id, $orderItem->family_id, 'order family ID');
        $this->assertSame('Checkout Family', $orderItem->family_name, 'order family snapshot');
        $this->assertSame(1, Product::query()->whereKey($selected->id)->firstOrFail()->fresh()->reserved_stock, 'reserved stock');
        $this->assertSame('QR_MANUAL', Payment::query()->sole()->method, 'payment method');
    }

    public function test_catalog_uses_stored_product_icons_and_compact_family_rows(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 41100, 'locale' => 'id']);

        foreach ([
            ['name' => 'Canva', 'slug' => 'canva', 'sort_order' => 1],
            ['name' => 'CapCut', 'slug' => 'capcut', 'sort_order' => 2],
            ['name' => 'ChatGPT', 'slug' => 'chatgpt', 'sort_order' => 3],
            ['name' => 'Gemini AI', 'slug' => 'gemini-ai', 'sort_order' => 4],
        ] as $familyData) {
            $family = ProductFamily::factory()->create($familyData);
            Product::factory()->create([
                'name' => $familyData['name'].' 1 Bulan',
                'icon' => $familyData['name'] === 'Canva' ? '🖌️' : null,
                'product_family_id' => $family->id,
                'family_sort_order' => 1,
                'family_option_label' => '1 Bulan',
                'stock' => 2,
                'reserved_stock' => 0,
            ]);
        }

        $this->postTelegramWebhook($this->messageUpdate(41101, '/produk', [], 41100))->assertOk();

        $catalog = $this->lastMessageContaining('Canva');
        $keyboard = $catalog['reply_markup']['inline_keyboard'];
        $this->assertStringNotContainsString('Canva', (string) $catalog['text']);
        $this->assertStringContainsString('Pilih keluarga atau produk', (string) $catalog['text']);
        $this->assertCount(6, $keyboard);
        $this->assertSame('🖌️ Canva · Stok 2', $keyboard[0][0]['text']);
        $this->assertSame('🎬 CapCut · Stok 2', $keyboard[1][0]['text']);
        $this->assertSame('🤖 ChatGPT · Stok 2', $keyboard[2][0]['text']);
        $this->assertSame('✨ Gemini AI · Stok 2', $keyboard[3][0]['text']);
        foreach (array_slice($keyboard, 0, 4) as $row) {
            $this->assertCount(1, $row);
        }
    }

    public function test_captured_brand_custom_emoji_icon_is_rendered_on_catalog_buttons(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 41200, 'locale' => 'id']);
        CatalogBrandIcon::query()->where('slug', 'chatgpt')->sole()->update(['custom_emoji_id' => '5368324170671202286']);
        app(CatalogBrandIcons::class)->forget();

        $family = ProductFamily::factory()->create(['name' => 'ChatGPT', 'slug' => 'chatgpt']);
        foreach ([
            ['name' => 'ChatGPT Plus 1 Bulan', 'label' => 'Plus 1 Bulan'],
            ['name' => 'ChatGPT Go 1 Bulan', 'label' => 'Go 1 Bulan'],
        ] as $sortOrder => $productData) {
            Product::factory()->create([
                'name' => $productData['name'],
                'product_family_id' => $family->id,
                'family_sort_order' => $sortOrder + 1,
                'family_option_label' => $productData['label'],
                'stock' => 2,
                'reserved_stock' => 0,
            ]);
        }

        $this->postTelegramWebhook($this->messageUpdate(41201, '/produk', [], 41200))->assertOk();

        $catalog = $this->lastMessageContaining('ChatGPT');
        $entry = $catalog['reply_markup']['inline_keyboard'][0][0];
        $this->assertSame('5368324170671202286', $entry['icon_custom_emoji_id']);
        $this->assertSame('ChatGPT ▶ 2 Plan »', $entry['text']);

        $this->openFamilyFromDeepLink($customer, 41202, $family);

        $options = $this->lastMessageContaining('Pilih Plan ChatGPT');
        $this->assertStringContainsString('🤖 Pilih Plan ChatGPT', (string) $options['text']);
        $plan = $options['reply_markup']['inline_keyboard'][0][0];
        $this->assertSame('5368324170671202286', $plan['icon_custom_emoji_id']);
        $this->assertSame('1', $plan['text']);
    }

    public function test_family_options_show_per_product_quotes_and_two_column_icon_buttons(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 41110, 'locale' => 'id']);
        $family = ProductFamily::factory()->create(['name' => 'Netflix', 'slug' => 'netflix']);
        foreach ([
            ['name' => 'Netflix Premium (No VPN)', 'label' => 'Premium (No VPN)', 'price' => 25000],
            ['name' => 'Netflix Premium (VPN)', 'label' => 'Premium (VPN)', 'price' => 15000],
            ['name' => 'Netflix Extra', 'label' => 'Extra', 'price' => 10000],
        ] as $sortOrder => $productData) {
            Product::factory()->create([
                'name' => $productData['name'],
                'icon' => $sortOrder === 0 ? '🟥' : null,
                'product_family_id' => $family->id,
                'family_sort_order' => $sortOrder + 1,
                'family_option_label' => $productData['label'],
                'price_amount' => $productData['price'],
                'stock' => 2,
                'reserved_stock' => 0,
            ]);
        }

        $this->openFamilyFromDeepLink($customer, 41111, $family);

        $options = $this->lastMessageContaining('Pilih Plan Netflix');
        $this->assertStringContainsString('🟥 Pilih Plan Netflix', (string) $options['text']);
        $this->assertStringContainsString('Terjual: 0', (string) $options['text']);
        $this->assertStringContainsString('01. 🟥 Premium (No VPN)', (string) $options['text']);
        $this->assertStringContainsString('Harga: Rp25.000', (string) $options['text']);
        $keyboard = $options['reply_markup']['inline_keyboard'];
        $this->assertCount(2, $keyboard[0]);
        $this->assertSame('🟥 1', $keyboard[0][0]['text']);
        $this->assertSame('📺 2', $keyboard[0][1]['text']);
        $this->assertCount(1, $keyboard[1]);
        $this->assertSame('📺 3', $keyboard[1][0]['text']);
        $this->assertStringStartsWith('family:list:1', $keyboard[2][0]['callback_data']);
    }

    public function test_demo_catalog_seed_is_repeatable_and_matches_the_family_examples(): void
    {
        $this->artisan('catalog:seed-demo')
            ->expectsOutputToContain('Demo catalog ready: 7 families, 9 products (7 families and 9 products created).')
            ->assertSuccessful();

        $this->assertSame(7, ProductFamily::query()->count());
        $this->assertSame(9, Product::query()->count());
        $this->assertDatabaseHas('products', [
            'name' => 'Netflix Premium (No VPN)',
            'family_option_label' => 'Premium (No VPN)',
            'price_amount' => 25000,
            'stock' => 5,
        ]);
        $this->assertDatabaseHas('products', [
            'name' => 'Netflix Premium (VPN)',
            'family_option_label' => 'Premium (VPN)',
            'price_amount' => 15000,
            'stock' => 5,
        ]);

        $this->artisan('catalog:seed-demo')
            ->expectsOutputToContain('Demo catalog ready: 7 families, 9 products (0 families and 0 products created).')
            ->assertSuccessful();

        $this->assertSame(7, ProductFamily::query()->count());
        $this->assertSame(9, Product::query()->count());
    }

    public function test_family_navigation_edits_the_original_message_for_options_and_product_detail(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 41120, 'locale' => 'id']);
        $family = ProductFamily::factory()->create(['name' => 'Single Message Family', 'slug' => 'single-message-family']);
        $product = Product::factory()->create([
            'name' => 'Single Message Option',
            'product_family_id' => $family->id,
            'family_sort_order' => 1,
            'family_option_label' => 'Premium 30 Hari',
            'price_amount' => 25000,
            'stock' => 2,
            'reserved_stock' => 0,
        ]);
        Product::factory()->create([
            'name' => 'Single Message Option Extra',
            'product_family_id' => $family->id,
            'family_sort_order' => 2,
            'family_option_label' => 'Premium 90 Hari',
            'price_amount' => 60000,
            'stock' => 2,
            'reserved_stock' => 0,
        ]);

        $this->postTelegramWebhook($this->messageUpdate(41121, '/produk', [], 41120))->assertOk();
        $catalog = $this->lastMessageContaining('Single Message Family');
        $familyCallback = $catalog['reply_markup']['inline_keyboard'][0][0]['callback_data'];

        $this->postTelegramWebhook($this->callbackUpdate(
            41122,
            'single-family-open',
            $familyCallback,
            [],
            41120,
            'private',
            123457,
        ))->assertOk();

        $sendMessages = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'));
        $editMessages = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageText'));
        $this->assertCount(1, $sendMessages);
        $this->assertCount(1, $editMessages);
        $options = $editMessages->last()[0]->data();
        $this->assertStringContainsString('Premium 30 Hari', (string) $options['text']);
        $this->assertSame(123457, $options['message_id']);
        $this->assertSame('HTML', $options['parse_mode']);
        $this->assertStringContainsString('<blockquote>', (string) $options['text']);
        $this->assertStringContainsString('</blockquote>', (string) $options['text']);
        $selectCallback = $options['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $this->postTelegramWebhook($this->callbackUpdate(
            41124,
            'single-family-open-repeat',
            $familyCallback,
            [],
            41120,
            'private',
            123457,
        ))->assertOk();
        $this->assertCount(2, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageText')));

        $this->postTelegramWebhook($this->callbackUpdate(
            41123,
            'single-product-select',
            $selectCallback,
            [],
            41120,
            'private',
            123457,
        ))->assertOk();

        $sendMessages = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'));
        $editMessages = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageText'));
        $this->assertCount(1, $sendMessages);
        $this->assertCount(3, $editMessages);
        $detail = $editMessages->last()[0]->data();
        $this->assertStringContainsString('Single Message Option', (string) $detail['text']);
        $this->assertSame(123457, $detail['message_id']);
        $this->assertStringContainsString('detail:pay:qris:'.$product->id.':', json_encode($detail, JSON_THROW_ON_ERROR));
    }

    public function test_signed_family_callbacks_round_trip_and_stay_within_telegram_limit(): void
    {
        $tokens = app(TelegramFamilyCallbackToken::class);
        $options = 'family:options:'.$tokens->options(PHP_INT_MAX, 4_000_000_000, 4_000_000_000).':o'.str_repeat('a', 16);
        $select = 'family:select:'.$tokens->select(PHP_INT_MAX, PHP_INT_MAX, 4_000_000_000, 4_000_000_000);
        $optionToken = explode(':o', substr($options, strlen('family:options:')))[0];
        $selectToken = substr($select, strlen('family:select:'));
        $this->assertLessThanOrEqual(64, strlen($options));
        $this->assertLessThanOrEqual(64, strlen($select));

        $this->assertSame(PHP_INT_MAX, $tokens->decodeOptions(substr($optionToken, 1))['familyId']);
        $this->assertSame(PHP_INT_MAX, $tokens->decodeSelect(substr($selectToken, 1))['productId']);
    }

    public function test_unavailable_families_show_habis_and_remain_openable(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 41130, 'locale' => 'id']);
        $single = ProductFamily::factory()->create([
            'name' => 'Single Sold Out',
            'slug' => 'single-sold-out',
            'sort_order' => 1,
        ]);
        $singleProduct = Product::factory()->create([
            'name' => 'Single Sold Out Product',
            'product_family_id' => $single->id,
            'stock' => 8,
            'reserved_stock' => 8,
        ]);
        $multiple = ProductFamily::factory()->create([
            'name' => 'Multiple Sold Out',
            'slug' => 'multiple-sold-out',
            'sort_order' => 2,
        ]);
        foreach (range(1, 2) as $number) {
            Product::factory()->create([
                'name' => 'Multiple Sold Out Product '.$number,
                'product_family_id' => $multiple->id,
                'family_sort_order' => $number,
                'stock' => 5 + $number,
                'reserved_stock' => 5 + $number,
            ]);
        }

        $this->postTelegramWebhook($this->messageUpdate(41131, '/product', [], 41130))->assertOk();
        $catalog = $this->lastMessageContaining('Single Sold Out');
        $keyboard = $catalog['reply_markup']['inline_keyboard'];
        $singleButton = $keyboard[0][0];
        $multipleButton = $keyboard[1][0];
        $this->assertStringContainsString('Single Sold Out', $singleButton['text']);
        $this->assertStringContainsString('Habis', $singleButton['text']);
        $this->assertStringContainsString('Multiple Sold Out', $multipleButton['text']);
        $this->assertStringContainsString('Habis', $multipleButton['text']);
        $this->assertSame('danger', $singleButton['style']);
        $this->assertSame('product:'.$singleProduct->id, $singleButton['callback_data']);
        $this->assertSame('danger', $multipleButton['style']);
        $this->assertStringStartsWith('family:options:v', $multipleButton['callback_data']);

        $this->postTelegramWebhook($this->callbackUpdate(41132, 'open-sold-out-family', $singleButton['callback_data'], [], 41130))->assertOk();
        $this->assertStringContainsString('Single Sold Out Product', (string) $this->lastMessageContaining('Single Sold Out Product')['text']);
    }

    public function test_family_options_refresh_stock_after_the_catalog_button_was_rendered(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 41140, 'locale' => 'id']);
        $family = ProductFamily::factory()->create(['name' => 'Fresh Family', 'slug' => 'fresh-family']);
        $product = Product::factory()->create([
            'name' => 'Fresh Option',
            'product_family_id' => $family->id,
            'family_sort_order' => 1,
            'family_option_label' => 'Fresh Option',
            'price_amount' => 18000,
            'stock' => 6,
            'reserved_stock' => 0,
        ]);
        Product::factory()->create([
            'name' => 'Fresh Option Partner',
            'product_family_id' => $family->id,
            'family_sort_order' => 2,
            'family_option_label' => 'Fresh Option Partner',
            'price_amount' => 22000,
            'stock' => 3,
            'reserved_stock' => 0,
        ]);

        $this->postTelegramWebhook($this->messageUpdate(41141, '/product', [], 41140))->assertOk();
        $catalog = $this->lastMessageContaining('Fresh Family');
        $familyCallback = $catalog['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $this->assertStringContainsString('▶ 2 Plan »', $catalog['reply_markup']['inline_keyboard'][0][0]['text']);

        $product->forceFill(['stock' => 2])->save();
        $this->postTelegramWebhook($this->callbackUpdate(41142, 'fresh-family-open', $familyCallback, [], 41140))->assertOk();
        $options = $this->lastMessageContaining('Fresh Option');
        $this->assertStringContainsString('Harga: Rp18.000', (string) $options['text']);
        $this->assertStringContainsString('Stok tersedia: 2', (string) $options['text']);
        $this->assertStringNotContainsString('Stok tersedia: 6', (string) $options['text']);

        $product->forceFill(['stock' => 0])->save();
        $this->postTelegramWebhook($this->callbackUpdate(41143, 'fresh-family-open-again', $familyCallback, [], 41140))->assertOk();
        $soldOutOptions = $this->lastMessageContaining('Fresh Option');
        $this->assertStringContainsString('❌ Stok: Habis', (string) $soldOutOptions['text']);
        $soldOutButton = $soldOutOptions['reply_markup']['inline_keyboard'][0][0];
        $this->assertSame('danger', $soldOutButton['style']);
        $this->assertArrayNotHasKey('callback_data', $soldOutButton);

        $this->postTelegramWebhook($this->callbackUpdate(
            41144,
            'select-now-sold-out',
            $this->selectCallback($family->id, $product->id),
            [],
            41140,
        ))->assertOk();
        $detail = $this->lastMessageContaining('Fresh Option');
        $this->assertStringContainsString('Stok kosong', (string) $detail['text']);
        $this->assertStringNotContainsString('detail:pay:', json_encode($detail, JSON_THROW_ON_ERROR));
        $this->assertSame(0, Cart::count());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_family_selection_rejects_products_that_are_inactive_or_moved(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 41150, 'locale' => 'id']);
        $family = ProductFamily::factory()->create(['name' => 'Mutable Family', 'slug' => 'mutable-family']);
        $product = Product::factory()->create([
            'name' => 'Mutable Product',
            'product_family_id' => $family->id,
            'family_sort_order' => 1,
            'family_option_label' => 'Mutable Product',
            'stock' => 2,
            'reserved_stock' => 0,
        ]);
        Product::factory()->create([
            'name' => 'Remaining Product',
            'product_family_id' => $family->id,
            'family_sort_order' => 2,
            'stock' => 2,
            'reserved_stock' => 0,
        ]);
        $destination = ProductFamily::factory()->create(['name' => 'Destination Family', 'slug' => 'destination-family']);

        $this->openFamilyFromDeepLink($customer, 41151, $family);
        $options = $this->lastMessageContaining('Mutable Product');
        $selectionCallback = $options['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $messagesBefore = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains($request->body(), 'Mutable Product'))->count();

        $product->forceFill(['is_active' => false])->save();
        $this->postTelegramWebhook($this->callbackUpdate(41152, 'inactive-family-product', $selectionCallback, [], 41150))->assertOk();
        $messagesAfterInactive = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains($request->body(), 'Mutable Product'))->count();
        $this->assertSame($messagesBefore, $messagesAfterInactive);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && ($request['callback_query_id'] ?? null) === 'inactive-family-product'
            && str_contains((string) ($request['text'] ?? ''), 'sudah tidak berlaku'));

        $product->forceFill(['is_active' => true])->save();
        $this->openFamilyFromDeepLink($customer, 41153, $family);
        $options = $this->lastMessageContaining('Mutable Product');
        $selectionCallback = $options['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $messagesBeforeMove = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains($request->body(), 'Mutable Product'))->count();
        $product->forceFill(['product_family_id' => $destination->id])->save();

        $this->postTelegramWebhook($this->callbackUpdate(41154, 'moved-family-product', $selectionCallback, [], 41150))->assertOk();
        $messagesAfterMove = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains($request->body(), 'Mutable Product'))->count();
        $this->assertSame($messagesBeforeMove, $messagesAfterMove);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && ($request['callback_query_id'] ?? null) === 'moved-family-product'
            && str_contains((string) ($request['text'] ?? ''), 'sudah tidak berlaku'));
        $this->assertSame(0, Cart::count());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_catalog_query_count_stays_bounded_as_family_count_grows(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 41160, 'locale' => 'en']);
        $catalog = app(TelegramCatalogIntents::class);
        $measuring = false;
        $queryCount = 0;
        DB::listen(function (QueryExecuted $query) use (&$measuring, &$queryCount): void {
            if ($measuring) {
                $queryCount++;
            }
        });
        $measureRender = function () use ($catalog, $customer, &$measuring, &$queryCount): int {
            $queryCount = 0;
            $measuring = true;
            try {
                $catalog->sendCatalogPage($customer, (string) $customer->telegram_id);
            } finally {
                $measuring = false;
            }

            return $queryCount;
        };
        $createFamily = function (int $number): void {
            $family = ProductFamily::factory()->create([
                'name' => 'Query Family '.$number,
                'slug' => 'query-family-'.$number,
                'sort_order' => $number,
            ]);
            Product::factory()->create([
                'name' => 'Query Product '.$number,
                'product_family_id' => $family->id,
                'stock' => 1,
                'reserved_stock' => 0,
            ]);
        };

        $createFamily(1);
        $smallCatalogQueries = $measureRender();
        foreach (range(2, 16) as $number) {
            $createFamily($number);
        }
        $largeCatalogQueries = $measureRender();

        $this->assertLessThanOrEqual(
            $smallCatalogQueries + 1,
            $largeCatalogQueries,
            sprintf('Query count grew from %d to %d for fifteen additional families.', $smallCatalogQueries, $largeCatalogQueries),
        );
    }

    public function test_public_catalog_projection_is_cached_until_catalog_changes(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 41161, 'locale' => 'en']);
        $product = Product::factory()->create([
            'name' => 'Cacheable Catalog Product',
            'stock' => 2,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        $family = ProductFamily::factory()->create([
            'name' => 'Cacheable Catalog Family',
            'slug' => 'cacheable-catalog-family',
        ]);
        Product::factory()->create([
            'name' => 'Catalog Family Member',
            'product_family_id' => $family->id,
            'stock' => 2,
            'reserved_stock' => 0,
        ]);
        $catalog = app(TelegramCatalogIntents::class);
        $catalogQueries = [];
        $measuring = false;
        DB::listen(function (QueryExecuted $query) use (&$catalogQueries, &$measuring): void {
            if ($measuring && str_contains(strtolower($query->sql), 'products')) {
                $catalogQueries[] = $query->sql;
            }
        });

        $measuring = true;
        $catalog->sendCatalogPage($customer, (string) $customer->telegram_id);
        $measuring = false;

        $catalogQueries = [];
        $measuring = true;
        $catalog->sendCatalogPage($customer, (string) $customer->telegram_id);
        $measuring = false;
        $this->assertSame([], $catalogQueries, 'A warm catalog read must not scan Products again.');
        $warmKeyboard = json_encode($this->lastMessageContaining('Choose a family')['reply_markup'], JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('Cacheable Catalog Product', $warmKeyboard);

        $product->update(['name' => 'Updated Catalog Product']);

        $catalogQueries = [];
        $measuring = true;
        $catalog->sendCatalogPage($customer, (string) $customer->telegram_id);
        $measuring = false;
        $updatedKeyboard = json_encode($this->lastMessageContaining('Choose a family')['reply_markup'], JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('Updated Catalog Product', $updatedKeyboard);
        $this->assertStringNotContainsString('Cacheable Catalog Product', $updatedKeyboard);
        $family->update(['name' => 'Updated Catalog Family']);

        $catalog->sendCatalogPage($customer, (string) $customer->telegram_id);
        $updatedFamilyKeyboard = json_encode($this->lastMessageContaining('Choose a family')['reply_markup'], JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('Updated Catalog Family', $updatedFamilyKeyboard);
        $this->assertStringNotContainsString('Cacheable Catalog Family', $updatedFamilyKeyboard);
        $catalogQueries = [];
        $measuring = true;
        $catalog->sendSearchResults($customer, (string) $customer->telegram_id, 'Updated Catalog');
        $measuring = false;
        $this->assertSame(1, count($catalogQueries), 'The first nonempty search must use a live Product projection.');
        $this->assertStringContainsString('Updated Catalog Product', (string) $this->lastMessageContaining('Updated Catalog Product')['text']);

        $catalogQueries = [];
        $measuring = true;
        $catalog->sendSearchResults($customer, (string) $customer->telegram_id, 'Updated Catalog');
        $measuring = false;
        $this->assertSame(1, count($catalogQueries), 'Repeated nonempty searches must not grow the cache by search key.');
    }

    public function test_single_plan_family_entry_opens_detail_without_the_plan_list(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 41130, 'locale' => 'id']);
        $family = ProductFamily::factory()->create(['name' => 'Spotify Solo', 'slug' => 'spotify-solo']);
        $product = Product::factory()->create([
            'name' => 'Spotify Solo 1 Bulan',
            'product_family_id' => $family->id,
            'family_option_label' => 'Solo 1 Bulan',
            'price_amount' => 45000,
            'stock' => 5,
            'reserved_stock' => 0,
        ]);

        $this->postTelegramWebhook($this->messageUpdate(41130, '/produk', [], 41130))->assertOk();
        $catalog = $this->lastMessageContaining('Spotify Solo');
        $entryButton = $catalog['reply_markup']['inline_keyboard'][0][0];
        $this->assertSame('product:'.$product->id, $entryButton['callback_data']);
        $this->assertStringContainsString('Stok 5', $entryButton['text']);
        $this->assertStringNotContainsString('Plan', $entryButton['text']);
        $this->assertSame('primary', $entryButton['style']);

        $this->postTelegramWebhook($this->callbackUpdate(41131, 'single-plan-open', 'product:'.$product->id, [], 41130))->assertOk();
        $detail = $this->lastMessageContaining('Spotify Solo 1 Bulan');
        $this->assertStringContainsString('Detail produk', (string) $detail['text']);
        $this->assertStringContainsString('Harga: Rp45.000', (string) $detail['text']);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->body(), 'Pilih Plan Spotify Solo'));
        $this->assertSame(0, Cart::count());
        $this->assertSame(0, Order::count());
    }

    public function test_sold_out_single_plan_family_entry_opens_detail_instead_of_an_empty_plan_list(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 41131, 'locale' => 'id']);
        $family = ProductFamily::factory()->create(['name' => 'Viu Solo', 'slug' => 'viu-solo']);
        $product = Product::factory()->create([
            'name' => 'Viu Solo 1 Bulan',
            'product_family_id' => $family->id,
            'family_option_label' => 'Solo 1 Bulan',
            'stock' => 1,
            'reserved_stock' => 1,
            'is_active' => true,
        ]);

        $this->postTelegramWebhook($this->messageUpdate(41132, '/produk', [], 41131))->assertOk();
        $catalog = $this->lastMessageContaining('Viu Solo');
        $entryButton = $catalog['reply_markup']['inline_keyboard'][0][0];
        $this->assertSame('product:'.$product->id, $entryButton['callback_data']);
        $this->assertStringContainsString('Habis', $entryButton['text']);
        $this->assertSame('danger', $entryButton['style']);

        $this->postTelegramWebhook($this->callbackUpdate(41133, 'sold-out-single-plan-open', 'product:'.$product->id, [], 41131))->assertOk();
        $detail = $this->lastMessageContaining('Viu Solo 1 Bulan');
        $this->assertStringContainsString('Stok kosong', (string) $detail['text']);
        $this->assertStringNotContainsString('family:options:v', json_encode($detail, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('detail:pay:', json_encode($detail, JSON_THROW_ON_ERROR));
        $this->assertSame(0, Cart::count());
        $this->assertSame(0, Order::count());
    }

    public function test_multi_plan_family_label_is_localized_and_still_opens_the_plan_list(): void
    {
        $this->fakeTelegramHttp();
        $family = ProductFamily::factory()->create(['name' => 'Locale Plans', 'slug' => 'locale-plans']);
        foreach ([
            ['name' => 'Locale Plans Basic', 'label' => 'Basic', 'price' => 10000],
            ['name' => 'Locale Plans Pro', 'label' => 'Pro', 'price' => 20000],
        ] as $sortOrder => $option) {
            Product::factory()->create([
                'name' => $option['name'],
                'product_family_id' => $family->id,
                'family_sort_order' => $sortOrder + 1,
                'family_option_label' => $option['label'],
                'price_amount' => $option['price'],
                'stock' => 2,
                'reserved_stock' => 0,
            ]);
        }

        foreach ([
            'id' => [41132, '▶ 2 Plan »', 'Pilih Plan Locale Plans'],
            'en' => [41133, '▶ 2 Plans »', 'Choose a plan for Locale Plans'],
            'ms' => [41134, '▶ 2 Plan »', 'Pilih Plan Locale Plans'],
        ] as $locale => [$chatId, $expectedLabel, $expectedTitle]) {
            Customer::factory()->create(['telegram_id' => $chatId, 'locale' => $locale]);
            $this->postTelegramWebhook($this->messageUpdate(41140 + $chatId, '/product', [], $chatId))->assertOk();

            $catalogPayloads = Http::recorded(fn (Request $request): bool => (str_ends_with($request->url(), '/sendMessage')
                    || str_ends_with($request->url(), '/editMessageText'))
                && (string) ($request['chat_id'] ?? '') === (string) $chatId
                && str_contains($request->body(), 'Locale Plans'));
            $this->assertNotEmpty($catalogPayloads, 'Expected a catalog message for locale '.$locale);

            $button = $catalogPayloads->last()[0]->data()['reply_markup']['inline_keyboard'][0][0];
            $this->assertStringContainsString($expectedLabel, $button['text']);
            $this->assertStringStartsWith('family:options:v', $button['callback_data']);

            $this->postTelegramWebhook($this->callbackUpdate(41150 + $chatId, 'plan-list-'.$locale, $button['callback_data'], [], $chatId))->assertOk();
            $optionPayloads = Http::recorded(fn (Request $request): bool => (str_ends_with($request->url(), '/sendMessage')
                    || str_ends_with($request->url(), '/editMessageText'))
                && (string) ($request['chat_id'] ?? '') === (string) $chatId
                && str_contains($request->body(), $expectedTitle));
            $this->assertNotEmpty($optionPayloads, 'Expected a plan list for locale '.$locale);

            $options = $optionPayloads->last()[0]->data();
            $this->assertStringContainsString('Basic', (string) $options['text']);
            $this->assertStringContainsString('Pro', (string) $options['text']);
            $this->assertStringStartsWith('family:select:v', $options['reply_markup']['inline_keyboard'][0][0]['callback_data']);
        }
    }

    private function openFamilyFromDeepLink(Customer $customer, int $updateId, ProductFamily $family): void
    {
        $chatId = (int) $customer->telegram_id;
        $this->postTelegramWebhook($this->messageUpdate(
            $updateId,
            '/start family_'.$family->id,
            [],
            $chatId,
        ))->assertOk();
        $this->lastMessageContaining($family->name);
    }

    private function optionsCallback(int $familyId, int $page = 1, int $returnPage = 1): string
    {
        return 'family:options:'.app(TelegramFamilyCallbackToken::class)->options($familyId, $page, $returnPage);
    }

    private function selectCallback(int $familyId, int $productId, int $page = 1, int $returnPage = 1): string
    {
        return 'family:select:'.app(TelegramFamilyCallbackToken::class)->select($familyId, $productId, $page, $returnPage);
    }

    private function lastMessageContaining(string $needle): array
    {
        $matches = Http::recorded(fn (Request $request): bool => str_contains($request->body(), $needle)
            && (str_ends_with($request->url(), '/sendMessage')
                || str_ends_with($request->url(), '/editMessageText')
                || str_ends_with($request->url(), '/editMessageCaption')));
        $this->assertNotEmpty($matches, 'Expected a Telegram message containing: '.$needle);

        $payload = $matches->last()[0]->data();
        if (! isset($payload['text']) && isset($payload['caption'])) {
            $payload['text'] = $payload['caption'];
        }

        return $payload;
    }
}
