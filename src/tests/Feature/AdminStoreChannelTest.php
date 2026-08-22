<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\StoreChannelSetting;
use App\Models\User;
use App\Services\Telegram\TelegramAdminStatsIntents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminStoreChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config()->set('services.telegram.token', 'bot-token');
        config()->set('services.telegram.api_base_url', 'https://api.telegram.org');
        Cache::flush();
    }

    public function test_owner_can_render_and_update_store_channel_settings(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));

        $this->get(route('admin.dashboard', ['tab' => 'telegram-channel']))
            ->assertOk()
            ->assertSee('Telegram Channel')
            ->assertSee('Channel')
            ->assertSee('Not configured');

        $this->post(route('admin.store-channel.update'), [
            'channel_username' => '@lhie_store',
            'channel_link' => 'https://t.me/lhie_store',
            'community_gate_enabled' => '1',
        ])->assertRedirect('/admin?tab=telegram-channel#admin-section-store-channel');

        $this->assertDatabaseHas('store_channel_settings', [
            'channel_username' => null,
            'channel_link' => 'https://t.me/lhie_store',
            'community_gate_enabled' => true,
            'sales_proof_enabled' => false,
        ]);
    }

    public function test_test_channel_success_persists_chat_and_message_without_enabling_toggles(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        StoreChannelSetting::create(['channel_username' => '@lhie_store']);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/botbot-token/getChat' => Http::response(['ok' => true, 'result' => ['id' => -100123, 'title' => 'LHIE']]),
            'https://api.telegram.org/botbot-token/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 77]]),
        ]);

        $this->post(route('admin.store-channel.test'))
            ->assertRedirect('/admin?tab=telegram-channel#admin-store-channel-test');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.telegram.org/botbot-token/sendMessage'
            && $request['text'] === "✅ LHIE STORE channel test succeeded.\n\nThe channel can receive test messages. Automatic features are not enabled yet.");

        $this->assertDatabaseHas('store_channel_settings', [
            'telegram_chat_id' => '-100123',
            'last_test_success' => true,
            'last_test_message_id' => '77',
            'community_gate_enabled' => false,
            'sales_proof_enabled' => false,
        ]);
    }

    public function test_test_channel_failure_records_actionable_reason(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        StoreChannelSetting::create(['channel_username' => '@lhie_store']);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/botbot-token/getChat' => Http::response(['ok' => false, 'description' => 'Forbidden: bot is not a member'], 403),
        ]);

        $this->post(route('admin.store-channel.test'));

        $this->assertDatabaseHas('store_channel_settings', [
            'last_test_success' => false,
        ]);
        $this->assertStringContainsString('Bot cannot access channel', StoreChannelSetting::first()->last_test_error);
    }

    public function test_test_channel_failure_redacts_telegram_bot_token_from_session_database_and_audit_log(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        StoreChannelSetting::create(['channel_username' => '@lhie_store']);
        config()->set('services.telegram.token', 'SECRET-issue-109');

        $botApiUrl = 'https://api.telegram.org/botSECRET-issue-109/getChat';
        $rawToken = 'SECRET-issue-109';
        $rawBotSegment = '/botSECRET-issue-109/';

        Http::preventStrayRequests();
        Http::fake([
            $botApiUrl => Http::response([
                'ok' => false,
                'description' => "Forbidden: {$botApiUrl} leaked from /botSECRET-issue-109/getChat",
            ], 403),
        ]);

        $this->post(route('admin.store-channel.test'))
            ->assertRedirect('/admin?tab=telegram-channel#admin-store-channel-test')
            ->assertSessionHas('error');

        $sessionError = (string) session('error');
        $storedError = (string) StoreChannelSetting::firstOrFail()->last_test_error;
        $auditPayload = json_encode(
            AuditLog::query()->where('action', 'store_channel.tested')->firstOrFail()->new_values,
            JSON_THROW_ON_ERROR
        );

        foreach ([
            'session error' => $sessionError,
            'stored error' => $storedError,
            'audit payload' => $auditPayload,
        ] as $surface => $value) {
            $this->assertStringContainsString('Bot cannot access channel', $value, "{$surface} dropped the actionable Telegram failure.");
            $this->assertStringNotContainsString($rawToken, $value, "{$surface} leaked the raw Telegram bot token.");
            $this->assertStringNotContainsString($rawBotSegment, $value, "{$surface} leaked the raw Telegram bot URL segment.");
            $this->assertStringNotContainsString($botApiUrl, $value, "{$surface} leaked the full Telegram Bot API URL.");
        }

        $dashboardContent = $this->get(route('admin.dashboard', ['tab' => 'telegram-channel']))
            ->assertOk()
            ->assertSee('Bot cannot access channel')
            ->getContent();

        $this->assertStringNotContainsString($rawToken, $dashboardContent, 'dashboard leaked the raw Telegram bot token.');
        $this->assertStringNotContainsString($rawBotSegment, $dashboardContent, 'dashboard leaked the raw Telegram bot URL segment.');
        $this->assertStringNotContainsString($botApiUrl, $dashboardContent, 'dashboard leaked the full Telegram Bot API URL.');
    }

    public function test_configured_channel_shows_needs_test_before_first_test(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        StoreChannelSetting::create(['channel_username' => '@lhie_store']);

        $this->get(route('admin.dashboard', ['tab' => 'telegram-channel']))
            ->assertOk()
            ->assertSee('Needs test')
            ->assertSee('Jalankan Test Channel');
    }

    public function test_updating_channel_target_clears_previous_test_result(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        StoreChannelSetting::create([
            'channel_username' => '@old_store',
            'telegram_chat_id' => '-100123',
            'last_test_success' => true,
            'last_test_message_id' => '77',
        ]);

        $this->post(route('admin.store-channel.update'), [
            'channel_username' => '@new_store',
        ])->assertRedirect('/admin?tab=telegram-channel#admin-section-store-channel');

        $setting = StoreChannelSetting::first();
        $this->assertSame('@new_store', $setting->channel_username);
        $this->assertNull($setting->telegram_chat_id);
        $this->assertNull($setting->last_test_success);
        $this->assertNull($setting->last_test_message_id);
    }

    public function test_store_channel_validation_uses_store_channel_error_bag_and_channel_tab(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->from('/admin?tab=telegram-channel')
            ->post(route('admin.store-channel.update'), [
                'channel_username' => str_repeat('x', 256),
            ])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'telegram-channel']))
            ->assertSessionHasErrors(['channel_username'], null, 'storeChannel');

        $this->assertDatabaseCount('store_channel_settings', 0);
    }

    public function test_ready_channel_tab_renders_fake_sales_proof_product_selector_and_random_interval_copy(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        $firstProduct = Product::factory()->create([
            'name' => 'Alpha Channel Voucher',
            'is_active' => true,
            'stock' => 3,
            'reserved_stock' => 0,
        ]);
        $secondProduct = Product::factory()->create([
            'name' => 'Beta Channel Voucher',
            'is_active' => true,
            'stock' => 5,
            'reserved_stock' => 1,
        ]);
        $inactiveProduct = Product::factory()->create([
            'name' => 'Inactive Channel Voucher',
            'is_active' => false,
            'stock' => 5,
            'reserved_stock' => 0,
        ]);
        $soldOutProduct = Product::factory()->create([
            'name' => 'Sold Out Channel Voucher',
            'is_active' => true,
            'stock' => 2,
            'reserved_stock' => 2,
        ]);
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'channel_username' => '@lhie_store',
            'telegram_chat_id' => '-100123',
            'last_test_success' => true,
            'sales_proof_enabled' => true,
        ]);

        $content = $this->get(route('admin.dashboard', ['tab' => 'telegram-channel']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(url('/admin/store-channel/fake-sales-proof'), $content);
        $this->assertSame(
            1,
            preg_match('/<select\b(?=[^>]*\bid="fake-sales-proof-product")(?=[^>]*\bname="product_id")[^>]*>.*?<\/select>/s', $content, $selectorMatches),
            'Expected fake sales proof product selector to render.'
        );
        $productSelectorHtml = $selectorMatches[0];

        foreach ([$firstProduct, $secondProduct] as $product) {
            $this->assertStringContainsString('value="'.$product->id.'"', $productSelectorHtml);
            $this->assertStringContainsString($product->name, $productSelectorHtml);
        }

        foreach ([$inactiveProduct, $soldOutProduct] as $product) {
            $this->assertStringNotContainsString('value="'.$product->id.'"', $productSelectorHtml);
            $this->assertStringNotContainsString($product->name, $productSelectorHtml);
        }

        foreach (['2 jam', '3 jam', '4 jam', '5 jam'] as $intervalCopy) {
            $this->assertStringContainsString($intervalCopy, $content);
        }
    }

    public function test_post_sends_fake_sales_proof_for_selected_product_without_persisting_orders_or_proofs(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        $selectedProduct = Product::factory()->create([
            'name' => 'Selected Channel Voucher',
            'is_active' => true,
            'stock' => 8,
            'reserved_stock' => 2,
        ]);
        $otherActiveProduct = Product::factory()->create([
            'name' => 'Other Active Channel Voucher',
            'is_active' => true,
            'stock' => 6,
            'reserved_stock' => 1,
        ]);
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'telegram_chat_id' => '-100123',
            'last_test_success' => true,
            'sales_proof_enabled' => true,
        ]);

        Http::fake([
            'https://api.telegram.org/botbot-token/sendPhoto' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 901],
            ]),
        ]);

        $this->post(route('admin.store-channel.fake-sales-proof'), [
            'product_id' => $selectedProduct->id,
        ])->assertRedirect('/admin?tab=telegram-channel#admin-store-channel-fake-sales-proof');

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($selectedProduct, $otherActiveProduct): bool {
            $caption = (string) $this->multipartField($request, 'caption');
            $photo = (string) $this->multipartField($request, 'photo');
            $replyMarkup = json_decode((string) $this->multipartField($request, 'reply_markup'), true);
            $dimensions = getimagesizefromstring($photo);
            $amount = number_format((int) $selectedProduct->price_amount, 0, ',', '.');

            return $request->url() === 'https://api.telegram.org/botbot-token/sendPhoto'
                && $this->multipartField($request, 'chat_id') === '-100123'
                && ($dimensions['mime'] ?? null) === 'image/png'
                && ($dimensions[0] ?? null) === 720
                && ($dimensions[1] ?? null) === 720
                && str_contains($caption, 'ORDER PAYMENT CONFIRMED')
                && str_contains($caption, 'Buyer ID: FAKE***')
                && preg_match('/Order ID: FAKE-[A-Z0-9_-]+/', $caption) === 1
                && str_contains($caption, $selectedProduct->name.' × 1')
                && ! str_contains($caption, $otherActiveProduct->name)
                && str_contains($caption, 'Paid via: QRIS')
                && str_contains($caption, 'Subtotal: Rp '.$amount)
                && str_contains($caption, 'Amount paid: Rp '.$amount)
                && str_contains($caption, 'Successful orders: #1')
                && str_contains($caption, 'Thank you for choosing Lhie Store.')
                && mb_strlen($caption, 'UTF-8') <= 1024
                && ($replyMarkup['inline_keyboard'][0][0]['text'] ?? null) === '🛒 Shop Now'
                && ($replyMarkup['inline_keyboard'][0][0]['url'] ?? null) === 'https://t.me/lhiestore_bot?start=product_'.$selectedProduct->id;
        });

        $this->assertSame(8, $selectedProduct->refresh()->stock);
        $this->assertSame(2, $selectedProduct->reserved_stock);
        $this->assertSame(6, $otherActiveProduct->refresh()->stock);
        $this->assertSame(1, $otherActiveProduct->reserved_stock);
        $this->assertNoRealOrderPersistence();
        $this->assertNoFakeSalesProofPosts();

        $stats = app(TelegramAdminStatsIntents::class)->calculateTodayStats();
        $this->assertSame(0, $stats['new_orders']);
        $this->assertSame(0, $stats['paid_orders']);
        $this->assertSame(0, $stats['sold_products']);
        $this->assertSame(0, $stats['earning']);
    }

    public function test_fake_sales_proof_rejects_missing_message_id_with_safe_admin_feedback(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $product = Product::factory()->create([
            'name' => 'Invalid Response Voucher',
            'is_active' => true,
            'stock' => 4,
            'reserved_stock' => 0,
        ]);
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'telegram_chat_id' => '-100123',
            'last_test_success' => true,
            'sales_proof_enabled' => true,
        ]);
        Http::fake([
            'https://api.telegram.org/botbot-token/sendPhoto' => Http::response([
                'ok' => true,
                'result' => [],
            ]),
        ]);

        $this->post(route('admin.store-channel.fake-sales-proof'), [
            'product_id' => $product->id,
        ])->assertRedirect('/admin?tab=telegram-channel#admin-store-channel-fake-sales-proof')
            ->assertSessionHas('error');

        $feedback = (string) session('error');
        $this->assertStringContainsString('Gagal mengirim info pembelian fake', $feedback);
        $this->assertStringNotContainsString('buyer@example.test', $feedback);
        $this->assertSame(4, $product->refresh()->stock);
        $this->assertSame(0, $product->reserved_stock);
        $this->assertNoRealOrderPersistence();
        $this->assertNoFakeSalesProofPosts();
    }

    public function test_fake_sales_proof_cooldown_labels_render_in_configured_app_timezone(): void
    {
        $previousTimezone = config('app.timezone');
        config()->set('app.timezone', 'Asia/Singapore');

        $now = Carbon::parse('2026-07-06 08:58:00', 'Asia/Singapore');
        Carbon::setTestNow($now);

        try {
            $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
            $product = Product::factory()->create([
                'name' => 'Timezone Channel Voucher',
                'is_active' => true,
                'stock' => 4,
                'reserved_stock' => 0,
            ]);
            StoreChannelSetting::create([
                'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
                'telegram_chat_id' => '-100123',
                'last_test_success' => true,
                'sales_proof_enabled' => true,
            ]);
            Http::fake(['https://api.telegram.org/botbot-token/sendPhoto' => Http::response(['ok' => true, 'result' => ['message_id' => 905]])]);

            $this->artisan('telegram:fake-sales-proof:auto')->assertSuccessful();

            $content = $this->get(route('admin.dashboard', ['tab' => 'telegram-channel']))
                ->assertOk()
                ->getContent();

            $this->assertSame(
                1,
                preg_match('/Auto berikutnya<\/p>\s*<p[^>]*>\s*2026-07-06 (10|11|12|13):58\s*<\/p>/', $content),
                'Expected the automatic fake sales proof availability label to keep the 08:58 local wall-clock minutes and add the recorded 2/3/4/5 hour cooldown.'
            );
            foreach (['2026-07-06 02:58', '2026-07-06 03:58', '2026-07-06 04:58', '2026-07-06 05:58'] as $utcLabel) {
                $this->assertStringNotContainsString($utcLabel, $content, 'Dashboard rendered the cached UTC timestamp instead of local app time.');
            }
        } finally {
            Carbon::setTestNow();
            config()->set('app.timezone', $previousTimezone);
        }
    }

    public function test_post_rejects_unavailable_fake_sales_proof_product_ids_without_sending(): void
    {
        foreach ([
            'inactive' => ['is_active' => false, 'stock' => 5, 'reserved_stock' => 0],
            'sold out' => ['is_active' => true, 'stock' => 3, 'reserved_stock' => 3],
        ] as $case => $attributes) {
            Cache::flush();
            StoreChannelSetting::query()->delete();
            Product::query()->delete();

            $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
            $product = Product::factory()->create([
                'name' => 'Rejected '.$case.' Channel Voucher',
                ...$attributes,
            ]);
            StoreChannelSetting::create([
                'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
                'telegram_chat_id' => '-100123',
                'last_test_success' => true,
                'sales_proof_enabled' => true,
            ]);
            Http::fake(['https://api.telegram.org/botbot-token/sendPhoto' => Http::response(['ok' => true, 'result' => ['message_id' => 902]])]);

            $this->post(route('admin.store-channel.fake-sales-proof'), [
                'product_id' => $product->id,
            ])->assertRedirect('/admin?tab=telegram-channel#admin-store-channel-fake-sales-proof');

            Http::assertNothingSent();
            $this->assertSame($attributes['stock'], $product->refresh()->stock);
            $this->assertSame($attributes['reserved_stock'], $product->reserved_stock);
            $this->assertNoRealOrderPersistence();
            $this->assertNoFakeSalesProofPosts();
        }
    }

    public function test_finance_and_support_cannot_send_fake_sales_proof(): void
    {
        foreach ([UserRole::Finance, UserRole::Support] as $role) {
            Cache::flush();
            StoreChannelSetting::query()->delete();
            Product::query()->delete();

            $product = Product::factory()->create(['name' => 'Manager Only Product', 'is_active' => true, 'stock' => 5, 'reserved_stock' => 0]);
            StoreChannelSetting::create([
                'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
                'telegram_chat_id' => '-100123',
                'last_test_success' => true,
                'sales_proof_enabled' => true,
            ]);
            Http::fake(['https://api.telegram.org/botbot-token/sendPhoto' => Http::response(['ok' => true, 'result' => ['message_id' => 903]])]);

            $this->actingAs(User::factory()->create(['role' => $role]))
                ->post(route('admin.store-channel.fake-sales-proof'), [
                    'product_id' => $product->id,
                ])
                ->assertForbidden();

            Http::assertNothingSent();
            $this->assertSame(5, $product->refresh()->stock);
            $this->assertSame(0, $product->reserved_stock);
            $this->assertNoRealOrderPersistence();
            $this->assertNoFakeSalesProofPosts();
        }
    }

    public function test_manual_fake_sales_proof_posts_ignore_automatic_cooldown_and_stay_side_effect_free(): void
    {
        $now = Carbon::parse('2026-07-05 12:00:00');
        Carbon::setTestNow($now);

        try {
            $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
            $product = Product::factory()->create(['name' => 'Manual Cooldown Channel Voucher', 'is_active' => true, 'stock' => 4, 'reserved_stock' => 0]);
            StoreChannelSetting::create([
                'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
                'telegram_chat_id' => '-100123',
                'last_test_success' => true,
                'sales_proof_enabled' => true,
            ]);
            Http::fake(['https://api.telegram.org/botbot-token/sendPhoto' => Http::response(['ok' => true, 'result' => ['message_id' => 904]])]);

            $this->post(route('admin.store-channel.fake-sales-proof'), [
                'product_id' => $product->id,
            ])->assertRedirect('/admin?tab=telegram-channel#admin-store-channel-fake-sales-proof');

            $this->post(route('admin.store-channel.fake-sales-proof'), [
                'product_id' => $product->id,
            ])->assertRedirect('/admin?tab=telegram-channel#admin-store-channel-fake-sales-proof');

            $matchingSends = Http::recorded(function (Request $request) use ($product): bool {
                $caption = (string) $this->multipartField($request, 'caption');

                return $request->url() === 'https://api.telegram.org/botbot-token/sendPhoto'
                    && $this->multipartField($request, 'chat_id') === '-100123'
                    && str_contains($caption, 'ORDER PAYMENT CONFIRMED')
                    && str_contains($caption, $product->name.' × 1');
            });

            $this->assertCount(2, $matchingSends);
            $captions = $matchingSends
                ->map(fn (array $record): string => (string) $this->multipartField($record[0], 'caption'))
                ->implode("\n");
            preg_match_all('/Successful orders: #(\d+)/', $captions, $orderNumberMatches);
            $this->assertSame(['1', '2'], $orderNumberMatches[1]);
            $this->assertSame(4, $product->refresh()->stock);
            $this->assertSame(0, $product->reserved_stock);
            $this->assertNoRealOrderPersistence();
            $this->assertNoFakeSalesProofPosts();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_auto_fake_sales_proof_command_sends_once_when_due_then_skips_until_random_interval_passes(): void
    {
        $now = Carbon::parse('2026-07-05 12:00:00');
        Carbon::setTestNow($now);

        try {
            $availableProduct = Product::factory()->create(['name' => 'Auto Channel Voucher', 'is_active' => true, 'stock' => 4, 'reserved_stock' => 0]);
            $inactiveProduct = Product::factory()->create(['name' => 'Inactive Auto Channel Voucher', 'is_active' => false, 'stock' => 4, 'reserved_stock' => 0]);
            $soldOutProduct = Product::factory()->create(['name' => 'Sold Out Auto Channel Voucher', 'is_active' => true, 'stock' => 4, 'reserved_stock' => 4]);
            StoreChannelSetting::create([
                'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
                'telegram_chat_id' => '-100123',
                'last_test_success' => true,
                'sales_proof_enabled' => true,
            ]);
            Http::fake(['https://api.telegram.org/botbot-token/sendPhoto' => Http::response(['ok' => true, 'result' => ['message_id' => 906]])]);

            $this->artisan('telegram:fake-sales-proof:auto')->assertSuccessful();

            Carbon::setTestNow($now->copy()->addHour());

            Http::assertSent(function (Request $request) use ($availableProduct, $inactiveProduct, $soldOutProduct): bool {
                $caption = (string) $this->multipartField($request, 'caption');
                $photo = (string) $this->multipartField($request, 'photo');
                $dimensions = getimagesizefromstring($photo);

                return $request->url() === 'https://api.telegram.org/botbot-token/sendPhoto'
                    && $this->multipartField($request, 'chat_id') === '-100123'
                    && ($dimensions['mime'] ?? null) === 'image/png'
                    && str_contains($caption, 'ORDER PAYMENT CONFIRMED')
                    && str_contains($caption, $availableProduct->name.' × 1')
                    && ! str_contains($caption, $inactiveProduct->name)
                    && ! str_contains($caption, $soldOutProduct->name)
                    && mb_strlen($caption, 'UTF-8') <= 1024;
            });
            $this->assertSame(4, $availableProduct->refresh()->stock);
            $this->assertSame(0, $availableProduct->reserved_stock);
            $this->assertNoRealOrderPersistence();
            $this->assertNoFakeSalesProofPosts();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_non_manager_cannot_update_store_channel(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Finance]));

        $this->post(route('admin.store-channel.update'), ['channel_username' => '@x'])->assertForbidden();
    }

    public function test_non_manager_cannot_test_store_channel(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Finance]));
        StoreChannelSetting::create(['channel_username' => '@lhie_store']);

        $this->post(route('admin.store-channel.test'))->assertForbidden();
    }

    private function assertNoRealOrderPersistence(): void
    {
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('sales_proof_posts', 0);
    }

    private function assertNoFakeSalesProofPosts(): void
    {
        if (Schema::hasTable('fake_sales_proof_posts')) {
            $this->assertDatabaseCount('fake_sales_proof_posts', 0);
        }
    }

    private function multipartField(Request $request, string $name): mixed
    {
        $field = collect($request->data())->firstWhere('name', $name);

        return is_array($field) ? ($field['contents'] ?? null) : null;
    }
}
