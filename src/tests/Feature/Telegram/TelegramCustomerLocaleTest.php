<?php

namespace Tests\Feature\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Product;
use App\Models\TelegramNavigationRoot;
use App\Services\CartService;
use App\Services\Telegram\TelegramLocale;
use Illuminate\Support\Facades\Http;
use Tests\Support\TelegramBotTestSupport;

class TelegramCustomerLocaleTest extends TelegramBotTestSupport
{
    public function test_first_start_opens_indonesian_home_and_language_remains_changeable(): void
    {
        config()->set('services.telegram.welcome_banner_url', 'banner-file-id');
        $this->fakeTelegramHttp();

        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_username' => 'store_support', 'is_active' => true]);
        $this->postTelegramWebhook($this->messageUpdate(41101, '/start', ['id' => 41101, 'first_name' => 'Maya', 'username' => 'maya'], 41101))->assertOk();

        $homeRequests = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendPhoto')
            && (string) ($request['chat_id'] ?? '') === '41101');
        $this->assertCount(1, $homeRequests);
        $home = $homeRequests->first()[0]->data();
        $this->assertStringContainsString('selamat datang di LHIE STORE', $home['caption']);
        $this->assertStringContainsString('Maya', $home['caption']);
        $this->assertStringContainsString('🌐 Bahasa', json_encode($home['reply_markup'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('Choose your language', $home['caption']);
        $this->assertDatabaseHas('customers', ['telegram_id' => 41101, 'locale' => 'id']);

        $root = TelegramNavigationRoot::query()->sole();
        $this->assertSame('home', $root->screen->value);
        $this->assertSame('id', $root->locale);

        $this->postTelegramWebhook($this->callbackUpdate(
            41102,
            'open-language',
            'menu:language',
            ['id' => 41101, 'first_name' => 'Maya', 'username' => 'maya'],
            41101,
            'private',
            $root->message_id,
        ))->assertOk();

        $picker = Http::recorded(fn ($request) => str_ends_with($request->url(), '/editMessageCaption')
            && (string) ($request['chat_id'] ?? '') === '41101')->last()[0]->data();
        $this->assertSame('🌐 Pilih bahasa', $picker['caption']);
        $languageButtons = array_column(
            array_merge(...$picker['reply_markup']['inline_keyboard']),
            'text',
            'callback_data',
        );
        foreach (['locale:id', 'locale:en', 'locale:ms'] as $callback) {
            $this->assertArrayHasKey($callback, $languageButtons);
            $this->assertNotEmpty($languageButtons[$callback]);
        }

        $this->postTelegramWebhook($this->callbackUpdate(
            41103,
            'locale-ms',
            'locale:ms',
            ['id' => 41101, 'first_name' => 'Maya', 'username' => 'maya'],
            41101,
            'private',
            $root->message_id,
        ))->assertOk();

        $this->assertDatabaseHas('customers', ['telegram_id' => 41101, 'locale' => 'ms']);
        $localizedHome = Http::recorded(fn ($request) => str_ends_with($request->url(), '/editMessageCaption')
            && (string) ($request['chat_id'] ?? '') === '41101')->last()[0]->data();
        $this->assertStringContainsString('selamat datang ke LHIE STORE', $localizedHome['caption']);
        $this->assertStringContainsString('Sokongan', json_encode($localizedHome['reply_markup'], JSON_THROW_ON_ERROR));
        $this->assertSame($root->message_id, $root->fresh()->message_id);
        $this->assertSame('home', $root->fresh()->screen->value);
        $this->assertSame('ms', $root->fresh()->locale);
    }

    public function test_malay_support_and_account_copy_preserve_customer_authored_values(): void
    {
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_username' => 'store_support', 'is_active' => true]);
        $customer = Customer::factory()->create([
            'telegram_id' => 41111,
            'locale' => 'ms',
            'first_name' => 'Nur "A"',
            'username' => 'nur_a',
        ]);
        Order::factory()->for($customer)->create(['invoice_number' => 'INV-MS-ACCOUNT']);

        $this->postTelegramWebhook($this->messageUpdate(41111, '💬 Sokongan', ['id' => 41111, 'first_name' => 'Nur "A"', 'username' => 'nur_a'], 41111))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(41112, '👤 Akaun Saya', ['id' => 41111, 'first_name' => 'Nur "A"', 'username' => 'nur_a'], 41111))->assertOk();

        $messages = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '41111')
            ->map(fn (array $record): array => $record[0]->data());
        $this->assertCount(2, $messages);

        $supportCopy = $this->visibleCopy($messages[0]);
        $this->assertStringContainsString('Ada masalah dengan pesanan, bayaran atau log masuk akaun?', $supportCopy);
        $this->assertStringContainsString('💬 Hubungi Admin', $supportCopy);
        $this->assertStringNotContainsString('Ada kendala order, pembayaran, atau login akun?', $supportCopy);

        $accountCopy = $this->visibleCopy($messages[1]);
        $this->assertStringContainsString('👤 Akaun Saya', $accountCopy);
        $this->assertStringContainsString('Nur "A"', $accountCopy);
        $this->assertStringContainsString('@nur_a', $accountCopy);
        $this->assertStringContainsString('INV-MS-ACCOUNT', $accountCopy);
        $this->assertStringContainsString('Menunggu bayaran', $accountCopy);
        $this->assertStringNotContainsString('Akun Saya', $accountCopy);
        $this->assertStringNotContainsString('My Account', $accountCopy);
    }

    public function test_unknown_customer_locale_uses_english_catalog_fallback(): void
    {
        config()->set('services.telegram.welcome_banner_url', 'banner-file-id');
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 41121, 'locale' => 'xx']);

        $this->postTelegramWebhook($this->messageUpdate(41121, '/menu', ['id' => 41121], 41121))->assertOk();

        $copy = $this->visibleCopy($this->homePayload(41121));
        $this->assertStringContainsString('My Profile', $copy);
        $this->assertStringContainsString('Browse Products', $copy);
        $this->assertStringNotContainsString('Profil Saya', $copy);
        $this->assertStringNotContainsString('Lihat Produk', $copy);
    }

    public function test_missing_catalog_key_uses_english_fallback_for_every_locale(): void
    {
        $locale = app(TelegramLocale::class);

        foreach (['id', 'en', 'ms', 'xx', null] as $customerLocale) {
            $customer = Customer::factory()->make(['locale' => $customerLocale]);

            $this->assertSame('Translation unavailable.', $locale->translate($customer, 'missing.catalog.key'));
        }
    }

    public function test_null_customer_locale_uses_english_lifecycle_status_fallback(): void
    {
        $locale = app(TelegramLocale::class);
        $customer = Customer::factory()->make(['locale' => null]);

        $this->assertSame('Awaiting payment', $locale->orderStatus($customer, OrderStatus::PendingPayment));
        $this->assertSame('Awaiting payment', $locale->paymentStatus($customer, PaymentStatus::Pending));
    }

    public function test_null_customer_locale_is_normalized_to_indonesian_when_routed(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 41124, 'locale' => null]);
        Order::factory()->create([
            'customer_id' => $customer->id,
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
        ]);

        $this->postTelegramWebhook($this->messageUpdate(41124, '/status', ['id' => 41124], 41124))->assertOk();

        $copy = $this->visibleCopy($this->customerPayload('/sendMessage', 41124));
        $this->assertStringContainsString('PESANAN SAYA', $copy);
        $this->assertStringContainsString('Menunggu pembayaran', $copy);
        $this->assertStringNotContainsString('MY ORDERS', $copy);
        $this->assertDatabaseHas('customers', ['telegram_id' => 41124, 'locale' => 'id']);
    }

    public function test_menu_command_opens_the_localized_canonical_home(): void
    {
        config()->set('services.telegram.welcome_banner_url', 'banner-file-id');
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 41001, 'locale' => 'id']);
        Customer::factory()->create(['telegram_id' => 41002, 'locale' => 'en']);

        $this->postTelegramWebhook($this->messageUpdate(41001, '/menu', ['id' => 41001], 41001))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(41002, '/menu', ['id' => 41002], 41002))->assertOk();

        $idPayload = $this->homePayload(41001);
        $enPayload = $this->homePayload(41002);
        $this->assertStringNotContainsString('\\n', (string) ($idPayload['caption'] ?? ''));
        $this->assertStringNotContainsString('\\n', (string) ($enPayload['caption'] ?? ''));

        $this->assertLocalizedCopy(
            $idPayload,
            ['Profil Saya', 'Lihat Produk', '💰 Saldo Saya: Rp 0'],
            ['My Profile', 'Browse Products', 'My Balance'],
        );
        $this->assertLocalizedCopy(
            $enPayload,
            ['My Profile', 'Browse Products', '💰 My Balance: Rp 0'],
            ['Profil Saya', 'Lihat Produk', 'Saldo Saya'],
        );

        $this->assertArrayNotHasKey('keyboard', $idPayload['reply_markup']);
    }

    public function test_support_copy_and_contact_button_follow_customer_locale(): void
    {
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_username' => 'store_support', 'is_active' => true]);
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 41011, 'locale' => 'id']);
        Customer::factory()->create(['telegram_id' => 41012, 'locale' => 'en']);

        $this->postTelegramWebhook($this->messageUpdate(41011, '/support', ['id' => 41011], 41011))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(41012, '/support', ['id' => 41012], 41012))->assertOk();

        $this->assertLocalizedCopy(
            $this->customerPayload('/sendMessage', 41011),
            ['Ada kendala order, pembayaran, atau login akun?', '💬 Hubungi Admin'],
            ['Need help with an order, payment, or account login?', '💬 Chat with Admin', '━━━━━━━━━━━━━━'],
        );
        $this->assertLocalizedCopy(
            $this->customerPayload('/sendMessage', 41012),
            ['Need help with an order, payment, or account login?', '💬 Chat with Admin'],
            ['Ada kendala order, pembayaran, atau login akun?', '💬 Hubungi Admin', '━━━━━━━━━━━━━━'],
        );
    }

    public function test_account_latest_payment_status_is_human_readable_in_customer_locale(): void
    {
        $this->fakeTelegramHttp();
        $indonesian = Customer::factory()->create(['telegram_id' => 41021, 'locale' => 'id', 'first_name' => 'Ayu']);
        $english = Customer::factory()->create(['telegram_id' => 41022, 'locale' => 'en', 'first_name' => 'John']);
        Order::factory()->for($indonesian)->create(['invoice_number' => 'INV-ID-ACCOUNT']);
        Order::factory()->for($english)->create(['invoice_number' => 'INV-EN-ACCOUNT']);

        $this->postTelegramWebhook($this->messageUpdate(41021, '/akun', ['id' => 41021], 41021))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(41022, '/akun', ['id' => 41022], 41022))->assertOk();

        $indonesianCopy = $this->visibleCopy($this->customerPayload('/sendMessage', 41021));
        $this->assertStringContainsString('👤 Akun Saya', $indonesianCopy);
        $this->assertStringContainsString('Pesanan terakhir: INV-ID-ACCOUNT · Menunggu pembayaran', $indonesianCopy);
        $this->assertStringNotContainsString('My Account', $indonesianCopy);
        $this->assertStringNotContainsString(' · pending', $indonesianCopy);

        $englishCopy = $this->visibleCopy($this->customerPayload('/sendMessage', 41022));
        $this->assertStringContainsString('👤 My Account', $englishCopy);
        $this->assertStringContainsString('Latest order: INV-EN-ACCOUNT · Awaiting payment', $englishCopy);
        $this->assertStringNotContainsString('Akun Saya', $englishCopy);
        $this->assertStringNotContainsString(' · pending', $englishCopy);
    }

    public function test_search_english_command_alias_opens_search_prompt(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 41031, 'locale' => 'en']);

        $this->postTelegramWebhook($this->messageUpdate(41031, '/search', ['id' => 41031], 41031))->assertOk();

        $copy = $this->visibleCopy($this->customerPayload('/sendMessage', 41031));
        $this->assertStringContainsString('What product are you looking for?', $copy);
        $this->assertStringNotContainsString('Mau cari produk apa?', $copy);
        $this->assertStringNotContainsString('I did not understand that command', $copy);
    }

    public function test_malay_command_aliases_route_to_existing_customer_intents(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 41034, 'locale' => 'ms', 'first_name' => 'Maya']);

        $this->postTelegramWebhook($this->messageUpdate(41034, '/bantuan', ['id' => 41034], 41034))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(41035, '/akaun', ['id' => 41034], 41034))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(41036, '/pesanan', ['id' => 41034], 41034))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(41037, '/sejarah', ['id' => 41034], 41034))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(41038, '/carian', ['id' => 41034], 41034))->assertOk();

        $messages = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '41034')
            ->map(fn (array $record): string => (string) ($record[0]['text'] ?? ''));

        $this->assertCount(5, $messages);
        $this->assertStringContainsString('Ada masalah dengan pesanan, bayaran atau log masuk akaun?', $messages[0]);
        $this->assertStringContainsString('👤 Akaun Saya', $messages[1]);
        $this->assertStringContainsString('Tiada pesanan lagi', $messages[2]);
        $this->assertStringContainsString('Tiada pesanan berjaya lagi.', $messages[3]);
        $this->assertStringContainsString('Apakah produk yang anda cari?', $messages[4]);
        $this->assertStringNotContainsString('Saya tidak memahami perintah itu', $messages->implode("\n"));
    }

    public function test_history_english_command_alias_opens_order_history(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 41032, 'locale' => 'en']);

        $this->postTelegramWebhook($this->messageUpdate(41032, '/history', ['id' => 41032], 41032))->assertOk();

        $copy = $this->visibleCopy($this->customerPayload('/sendMessage', 41032));
        $this->assertStringContainsString('No successful orders yet.', $copy);
        $this->assertStringNotContainsString('Belum ada pesanan berhasil.', $copy);
        $this->assertStringNotContainsString('I did not understand that command', $copy);
    }

    public function test_account_english_command_alias_opens_account_summary(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 41033, 'locale' => 'en', 'first_name' => 'Jane']);

        $this->postTelegramWebhook($this->messageUpdate(41033, '/account', ['id' => 41033], 41033))->assertOk();

        $copy = $this->visibleCopy($this->customerPayload('/sendMessage', 41033));
        $this->assertStringContainsString('👤 My Account', $copy);
        $this->assertStringNotContainsString('👤 Akun Saya', $copy);
        $this->assertStringNotContainsString('I did not understand that command', $copy);
    }

    public function test_payment_proof_callback_guidance_follows_customer_locale(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 41041, 'locale' => 'id']);
        Customer::factory()->create(['telegram_id' => 41042, 'locale' => 'en']);

        $this->postTelegramWebhook($this->callbackUpdate(41041, 'proof-id', 'payment-proof:101', ['id' => 41041], 41041))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(41042, 'proof-en', 'payment-proof:102', ['id' => 41042], 41042))->assertOk();

        $this->assertLocalizedCopy(
            $this->callbackPayload('proof-id'),
            ['Kirim foto bukti pembayaran', '/payment INVOICE'],
            ['Send a photo'],
        );
        $this->assertLocalizedCopy(
            $this->callbackPayload('proof-en'),
            ['Send a photo', '/payment INVOICE'],
            ['Kirim foto bukti pembayaran'],
        );
    }

    public function test_generic_checkout_error_follows_customer_locale(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 41051, 'locale' => 'id']);
        Customer::factory()->create(['telegram_id' => 41052, 'locale' => 'en']);

        $this->postTelegramWebhook($this->callbackUpdate(41051, 'checkout-id', 'checkout', ['id' => 41051], 41051))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(41052, 'checkout-en', 'checkout', ['id' => 41052], 41052))->assertOk();

        $this->assertLocalizedCopy(
            $this->customerPayload('/sendMessage', 41051),
            ['Checkout belum berhasil.', 'Keranjang kosong'],
            ['Checkout could not be completed.', 'Your cart is empty'],
        );
        $this->assertLocalizedCopy(
            $this->customerPayload('/sendMessage', 41052),
            ['Checkout could not be completed.', 'Your cart is empty'],
            ['Checkout belum berhasil.', 'Keranjang kosong'],
        );
    }

    public function test_binance_rail_selector_follows_customer_locale(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => true,
            'admin_telegram_chat_id' => '999',
            'is_active' => true,
        ]);
        $indonesian = Customer::factory()->create(['telegram_id' => 41061, 'locale' => 'id']);
        $english = Customer::factory()->create(['telegram_id' => 41062, 'locale' => 'en']);
        $malay = Customer::factory()->create(['telegram_id' => 41063, 'locale' => 'ms']);
        $indonesianProduct = Product::factory()->create(['name' => 'ID Locale Product', 'usdt_price_amount' => 2_500_000]);
        $englishProduct = Product::factory()->create(['name' => 'EN Locale Product', 'usdt_price_amount' => 3_000_000]);
        $malayProduct = Product::factory()->create(['name' => 'MS Locale Product', 'usdt_price_amount' => 3_500_000]);
        $carts = app(CartService::class);
        $carts->addItem($carts->activeCartFor($indonesian), $indonesianProduct, 1);
        $carts->addItem($carts->activeCartFor($english), $englishProduct, 1);
        $carts->addItem($carts->activeCartFor($malay), $malayProduct, 1);

        $this->postTelegramWebhook($this->callbackUpdate(41061, 'binance-id', 'checkout:binance', ['id' => 41061], 41061, 'private', 91061))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(41062, 'binance-en', 'checkout:binance', ['id' => 41062], 41062, 'private', 91062))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(41063, 'binance-ms', 'checkout:binance', ['id' => 41063], 41063, 'private', 91063))->assertOk();

        $this->assertLocalizedCopy(
            $this->customerPayload('/sendMessage', 41061),
            ['💎 Pilih metode pembayaran USDT', 'Bayar dengan Binance Pay ID', '❌ Batal'],
            ['Choose a USDT payment method', 'Pay with Binance Pay ID'],
        );
        $this->assertLocalizedCopy(
            $this->customerPayload('/sendMessage', 41062),
            ['💎 Choose a USDT payment method', 'Pay with Binance Pay ID', '❌ Cancel'],
            ['Pilih metode pembayaran USDT', 'Bayar dengan Binance Pay ID'],
        );
        $this->assertLocalizedCopy(
            $this->customerPayload('/sendMessage', 41063),
            ['💎 Pilih kaedah bayaran USDT', 'Bayar dengan Binance Pay ID', '❌ Batal'],
            ['Choose a USDT payment method', 'Pay with Binance Pay ID'],
        );

    }

    public function test_product_detail_payment_options_prioritize_qris_and_explain_cross_border_support_in_customer_locale(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => true,
            'admin_telegram_chat_id' => '999',
            'is_active' => true,
        ]);
        $indonesian = Customer::factory()->create(['telegram_id' => 41071, 'locale' => 'id']);
        $english = Customer::factory()->create(['telegram_id' => 41072, 'locale' => 'en']);
        $malay = Customer::factory()->create(['telegram_id' => 41073, 'locale' => 'ms']);
        $product = Product::factory()->create([
            'price_amount' => 50000,
            'usdt_price_amount' => 3_000_000,
            'stock' => 3,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $this->postTelegramWebhook($this->callbackUpdate(41071, 'payment-options-id', 'product:'.$product->id, ['id' => 41071], 41071))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(41072, 'payment-options-en', 'product:'.$product->id, ['id' => 41072], 41072))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(41073, 'payment-options-ms', 'product:'.$product->id, ['id' => 41073], 41073))->assertOk();

        $idPayload = $this->customerPayload('/sendMessage', 41071);
        $enPayload = $this->customerPayload('/sendMessage', 41072);
        $msPayload = $this->customerPayload('/sendMessage', 41073);
        $idPaymentButtons = collect($idPayload['reply_markup']['inline_keyboard'])
            ->flatten(1)
            ->filter(fn (array $button): bool => str_starts_with((string) ($button['callback_data'] ?? ''), 'detail:pay:'))
            ->values()
            ->all();
        $enPaymentButtons = collect($enPayload['reply_markup']['inline_keyboard'])
            ->flatten(1)
            ->filter(fn (array $button): bool => str_starts_with((string) ($button['callback_data'] ?? ''), 'detail:pay:'))
            ->values()
            ->all();
        $msPaymentButtons = collect($msPayload['reply_markup']['inline_keyboard'])
            ->flatten(1)
            ->filter(fn (array $button): bool => str_starts_with((string) ($button['callback_data'] ?? ''), 'detail:pay:'))
            ->values()
            ->all();

        $this->assertCount(3, $idPaymentButtons);
        $this->assertCount(3, $enPaymentButtons);
        $this->assertCount(3, $msPaymentButtons);
        $this->assertSame('💳 QRIS · Rp50.000', $idPaymentButtons[0]['text']);
        $this->assertSame('💰 Bayar dengan Saldo', $idPaymentButtons[1]['text']);
        $this->assertSame('💎 USDT · 3 USDT', $idPaymentButtons[2]['text']);
        $this->assertSame('💳 QRIS · Rp50.000', $enPaymentButtons[0]['text']);
        $this->assertSame('💰 Pay with Balance', $enPaymentButtons[1]['text']);
        $this->assertSame('💎 USDT · 3 USDT', $enPaymentButtons[2]['text']);
        $this->assertSame('💳 QRIS · Rp50.000', $msPaymentButtons[0]['text']);
        $this->assertSame('💰 Bayar dengan Baki', $msPaymentButtons[1]['text']);
        $this->assertSame('💎 USDT · 3 USDT', $msPaymentButtons[2]['text']);
        $this->assertStringNotContainsString('checkout.payment_method', json_encode($idPayload, JSON_THROW_ON_ERROR));
        $this->assertLocalizedCopy($idPayload, ['🌍 QRIS Antarnegara', 'ID', 'TH', 'MY', 'SG', 'JP', 'KR', 'CN', 'Ketersediaan bergantung pada aplikasi, PJP, dan merchant.'], ['QRIS Antarnegara tersedia melalui koneksi:', 'Ketersediaan tetap bergantung pada aplikasi pembayaran/PJP']);
        $this->assertLocalizedCopy($enPayload, ['🌍 Cross-Border QRIS', 'ID', 'TH', 'MY', 'SG', 'JP', 'KR', 'CN', 'Availability depends on the payment app, provider, and merchant.'], ['QRIS Cross-Border currently supports connections with:', 'Availability still depends on the payment app/provider']);
        $this->assertLocalizedCopy($msPayload, ['🌍 QRIS Rentas Sempadan', 'ID', 'TH', 'MY', 'SG', 'JP', 'KR', 'CN', 'Ketersediaan bergantung pada aplikasi bayaran, penyedia pembayaran dan peniaga.'], ['QRIS Rentas Sempadan:', 'Ketersediaan masih bergantung pada aplikasi/penyedia pembayaran']);
    }

    public function test_checkout_method_prompt_explains_qris_cross_border_support_in_customer_locale(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => true,
            'admin_telegram_chat_id' => '999',
            'is_active' => true,
        ]);
        $indonesian = Customer::factory()->create(['telegram_id' => 41081, 'locale' => 'id']);
        $english = Customer::factory()->create(['telegram_id' => 41082, 'locale' => 'en']);
        $malay = Customer::factory()->create(['telegram_id' => 41083, 'locale' => 'ms']);
        $product = Product::factory()->create([
            'price_amount' => 50000,
            'usdt_price_amount' => 3_000_000,
            'stock' => 3,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        $carts = app(CartService::class);
        $carts->addItem($carts->activeCartFor($indonesian), $product, 1);
        $carts->addItem($carts->activeCartFor($english), $product, 1);
        $carts->addItem($carts->activeCartFor($malay), $product, 1);

        $this->postTelegramWebhook($this->callbackUpdate(41081, 'qris-choice-id', 'checkout', ['id' => 41081], 41081))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(41082, 'qris-choice-en', 'checkout', ['id' => 41082], 41082))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(41083, 'qris-choice-ms', 'checkout', ['id' => 41083], 41083))->assertOk();

        $idCheckoutPayload = $this->customerPayload('/sendMessage', 41081);
        $enCheckoutPayload = $this->customerPayload('/sendMessage', 41082);
        $msCheckoutPayload = $this->customerPayload('/sendMessage', 41083);
        $this->assertLocalizedCopy(
            $idCheckoutPayload,
            ['Pilih metode pembayaran di bawah.', '🌍 QRIS Antarnegara', 'ID', 'TH', 'MY', 'SG', 'JP', 'KR', 'CN'],
            ['Choose a payment method below.', 'Cross-Border QRIS'],
        );
        $this->assertLocalizedCopy(
            $enCheckoutPayload,
            ['Choose a payment method below.', '🌍 Cross-Border QRIS', 'ID', 'TH', 'MY', 'SG', 'JP', 'KR', 'CN'],
            ['Pilih metode pembayaran di bawah.', 'QRIS Antarnegara'],
        );
        $this->assertLocalizedCopy(
            $msCheckoutPayload,
            ['Pilih kaedah bayaran di bawah.', '🌍 QRIS Rentas Sempadan', 'ID', 'TH', 'MY', 'SG', 'JP', 'KR', 'CN'],
            ['Pilih metode pembayaran di bawah.', 'Choose a payment method below.'],
        );

        // The cross-border block is quoted so it reads as one panel on every locale.
        foreach ([$idCheckoutPayload, $enCheckoutPayload, $msCheckoutPayload] as $checkoutPayload) {
            $this->assertSame('HTML', $checkoutPayload['parse_mode'] ?? null);
            $this->assertMatchesRegularExpression('/<blockquote>🌍 (?:QRIS|Cross-Border)/u', (string) $checkoutPayload['text']);
            $this->assertStringEndsWith('</blockquote>', (string) $checkoutPayload['text']);
        }
    }

    public function test_router_error_and_empty_order_copy_follow_customer_voice(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 41091, 'locale' => 'id']);
        Customer::factory()->create(['telegram_id' => 41092, 'locale' => 'id']);
        Customer::factory()->create(['telegram_id' => 41093, 'locale' => 'id']);

        $this->postTelegramWebhook($this->messageUpdate(41091, '/status', ['id' => 41091], 41091))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(41092, '/order', ['id' => 41092], 41092))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(41093, '/tidak-dikenal', ['id' => 41093], 41093))->assertOk();

        $emptyOrderCopy = $this->visibleCopy($this->customerPayload('/sendMessage', 41091));
        $this->assertStringContainsString("📦 Belum ada pesanan nih.\n\nBuka /produk dulu untuk mulai belanja.", $emptyOrderCopy);
        $this->assertStringNotContainsString('🛍️', $emptyOrderCopy);

        $missingInvoiceCopy = $this->visibleCopy($this->customerPayload('/sendMessage', 41092));
        $this->assertStringContainsString('Ketik nomor invoice setelah command.', $missingInvoiceCopy);
        $this->assertStringNotContainsString('command ya', $missingInvoiceCopy);

        $unknownCopy = $this->visibleCopy($this->customerPayload('/sendMessage', 41093));
        $this->assertStringContainsString('Aku belum paham perintah itu.', $unknownCopy);
        $this->assertStringNotContainsString('😅', $unknownCopy);
    }

    /**
     * @param  list<string>  $expected
     * @param  list<string>  $oppositeLocale
     */
    private function assertLocalizedCopy(array $payload, array $expected, array $oppositeLocale): void
    {
        $copy = $this->visibleCopy($payload);
        foreach ($expected as $fragment) {
            $this->assertStringContainsString($fragment, $copy, "Missing localized fragment: {$fragment}");
        }
        foreach ($oppositeLocale as $fragment) {
            $this->assertStringNotContainsString($fragment, $copy);
        }
    }

    private function homePayload(int $chatId): array
    {
        $requests = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendPhoto')
            && (string) ($request['chat_id'] ?? '') === (string) $chatId);
        $this->assertCount(1, $requests, "Expected one /sendPhoto request for customer chat {$chatId}.");

        return $requests->first()[0]->data();
    }

    private function customerPayload(string $endpoint, int $chatId): array
    {
        $requests = Http::recorded(fn ($request) => str_ends_with($request->url(), $endpoint)
            && (string) ($request['chat_id'] ?? '') === (string) $chatId);
        $this->assertCount(1, $requests, "Expected one {$endpoint} request for customer chat {$chatId}.");

        return $requests->first()[0]->data();
    }

    private function customerPhotoPayload(int $chatId): array
    {
        $requests = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendPhoto')
            && str_contains($request->body(), 'name="chat_id"')
            && str_contains($request->body(), "\r\n{$chatId}\r\n"));
        $this->assertCount(1, $requests, "Expected one /sendPhoto request for customer chat {$chatId}.");

        return ['caption' => $requests->first()[0]->body()];
    }

    private function callbackPayload(string $callbackId): array
    {
        $requests = Http::recorded(fn ($request) => str_ends_with($request->url(), '/answerCallbackQuery')
            && ($request['callback_query_id'] ?? null) === $callbackId);
        $this->assertCount(1, $requests, "Expected one callback answer for {$callbackId}.");

        return $requests->first()[0]->data();
    }

    private function visibleCopy(array $payload): string
    {
        $fragments = [
            (string) ($payload['text'] ?? ''),
            (string) ($payload['caption'] ?? ''),
            (string) ($payload['reply_markup']['input_field_placeholder'] ?? ''),
        ];
        foreach (['keyboard', 'inline_keyboard'] as $keyboardType) {
            foreach (($payload['reply_markup'][$keyboardType] ?? []) as $row) {
                foreach ($row as $button) {
                    $fragments[] = (string) ($button['text'] ?? '');
                }
            }
        }

        return implode("\n", array_filter($fragments, fn (string $fragment): bool => $fragment !== ''));
    }
}
