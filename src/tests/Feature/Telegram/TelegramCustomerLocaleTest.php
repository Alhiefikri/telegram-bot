<?php

namespace Tests\Feature\Telegram;

use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Support\Facades\Http;
use Tests\Support\TelegramBotTestSupport;

class TelegramCustomerLocaleTest extends TelegramBotTestSupport
{
    public function test_main_menu_copy_and_keyboard_follow_customer_locale(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 41001, 'locale' => 'id']);
        Customer::factory()->create(['telegram_id' => 41002, 'locale' => 'en']);

        $this->postTelegramWebhook($this->messageUpdate(41001, '/menu', ['id' => 41001], 41001))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(41002, '/menu', ['id' => 41002], 41002))->assertOk();

        $idPayload = $this->customerPayload('/sendMessage', 41001);
        $enPayload = $this->customerPayload('/sendMessage', 41002);
        $this->assertStringNotContainsString('\\n', (string) ($idPayload['text'] ?? ''));
        $this->assertStringNotContainsString('\\n', (string) ($enPayload['text'] ?? ''));

        $this->assertLocalizedCopy(
            $idPayload,
            ['Menu utama', 'Lihat Produk', 'Pilih tombol atau ketik /produk'],
            ['Main menu', 'Browse Products', 'Choose a button or type /products', '🚀'],
        );
        $this->assertLocalizedCopy(
            $enPayload,
            ['Main menu', 'Browse Products', 'Choose a button or type /products'],
            ['Menu utama', 'Lihat Produk', 'Pilih tombol atau ketik /produk', '🚀'],
        );
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

    public function test_history_english_command_alias_opens_order_history(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 41032, 'locale' => 'en']);

        $this->postTelegramWebhook($this->messageUpdate(41032, '/history', ['id' => 41032], 41032))->assertOk();

        $copy = $this->visibleCopy($this->customerPayload('/sendMessage', 41032));
        $this->assertStringContainsString('No order history', $copy);
        $this->assertStringNotContainsString('Riwayat masih kosong', $copy);
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

    public function test_binance_payment_instructions_follow_customer_locale_without_extra_action_button(): void
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
        $indonesianProduct = Product::factory()->create(['name' => 'ID Locale Product', 'usdt_price_amount' => 2_500_000]);
        $englishProduct = Product::factory()->create(['name' => 'EN Locale Product', 'usdt_price_amount' => 3_000_000]);
        $carts = app(CartService::class);
        $carts->addItem($carts->activeCartFor($indonesian), $indonesianProduct, 1);
        $carts->addItem($carts->activeCartFor($english), $englishProduct, 1);

        $this->postTelegramWebhook($this->callbackUpdate(41061, 'binance-id', 'checkout:binance', ['id' => 41061], 41061))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(41062, 'binance-en', 'checkout:binance', ['id' => 41062], 41062))->assertOk();

        $this->assertLocalizedCopy(
            $this->customerPhotoPayload(41061),
            ['💎 Checkout Binance Pay', 'BINANCE PAY ID', 'Scan QR code atau masukkan Pay ID', 'Kirim screenshot pembayaran sebagai foto dengan caption /payment'],
            ['💎 Binance Pay checkout', '💎 CHECKOUT USDT', '1️⃣', 'Send the payment screenshot'],
        );
        $this->assertLocalizedCopy(
            $this->customerPhotoPayload(41062),
            ['💎 Binance Pay checkout', 'BINANCE PAY ID', 'Scan the QR code or enter the Pay ID', 'Send the payment screenshot as a photo with the caption /payment'],
            ['💎 Checkout Binance Pay', '💎 USDT CHECKOUT', '1️⃣', 'Kirim screenshot pembayaran'],
        );
    }

    public function test_payment_options_prioritize_qris_and_explain_cross_border_support(): void
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
        $product = Product::factory()->create([
            'price_amount' => 50000,
            'usdt_price_amount' => 3_000_000,
            'stock' => 2,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $this->postTelegramWebhook($this->callbackUpdate(41071, 'payment-options-id', 'product:'.$product->id, ['id' => 41071], 41071))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(41072, 'payment-options-en', 'product:'.$product->id, ['id' => 41072], 41072))->assertOk();

        $idPayload = $this->customerPayload('/sendMessage', 41071);
        $enPayload = $this->customerPayload('/sendMessage', 41072);
        $idButtons = collect($idPayload['reply_markup']['inline_keyboard'])->map(fn (array $row): string => $row[0]['callback_data'] ?? '')->values()->all();
        $enButtons = collect($enPayload['reply_markup']['inline_keyboard'])->map(fn (array $row): string => $row[0]['callback_data'] ?? '')->values()->all();

        $this->assertSame('buy:qris:'.$product->id, $idButtons[0]);
        $this->assertSame('buy:binance:'.$product->id, $idButtons[1]);
        $this->assertSame('buy:qris:'.$product->id, $enButtons[0]);
        $this->assertSame('buy:binance:'.$product->id, $enButtons[1]);
        $this->assertLocalizedCopy($idPayload, ['QRIS Antarnegara saat ini mendukung koneksi dengan:', '🇮🇩 Indonesia', '🇹🇭 Thailand', '🇲🇾 Malaysia', '🇸🇬 Singapura', '🇯🇵 Jepang', '🇰🇷 Korea Selatan', '🇨🇳 Tiongkok', 'Ketersediaan tetap bergantung pada aplikasi pembayaran/PJP'], ['QRIS Cross-Border currently supports connections with:', 'Availability still depends on the payment app/provider']);
        $this->assertLocalizedCopy($enPayload, ['QRIS Cross-Border currently supports connections with:', '🇮🇩 Indonesia', '🇹🇭 Thailand', '🇲🇾 Malaysia', '🇸🇬 Singapore', '🇯🇵 Japan', '🇰🇷 South Korea', '🇨🇳 China', 'Availability still depends on the payment app/provider'], ['QRIS Antarnegara saat ini mendukung koneksi dengan:', 'Ketersediaan tetap bergantung pada aplikasi pembayaran/PJP']);
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
        $product = Product::factory()->create([
            'price_amount' => 50000,
            'usdt_price_amount' => 3_000_000,
            'stock' => 2,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        $carts = app(CartService::class);
        $carts->addItem($carts->activeCartFor($indonesian), $product, 1);
        $carts->addItem($carts->activeCartFor($english), $product, 1);

        $this->postTelegramWebhook($this->callbackUpdate(41081, 'qris-choice-id', 'checkout', ['id' => 41081], 41081))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(41082, 'qris-choice-en', 'checkout', ['id' => 41082], 41082))->assertOk();

        $idCheckoutPayload = $this->customerPayload('/sendMessage', 41081);
        $enCheckoutPayload = $this->customerPayload('/sendMessage', 41082);
        $idCheckoutButtons = collect($idCheckoutPayload['reply_markup']['inline_keyboard'])->map(fn (array $row): string => $row[0]['callback_data'] ?? '')->values()->all();
        $enCheckoutButtons = collect($enCheckoutPayload['reply_markup']['inline_keyboard'])->map(fn (array $row): string => $row[0]['callback_data'] ?? '')->values()->all();

        $this->assertSame('checkout:current', $idCheckoutButtons[0]);
        $this->assertSame('checkout:binance', $idCheckoutButtons[1]);
        $this->assertSame('checkout:current', $enCheckoutButtons[0]);
        $this->assertSame('checkout:binance', $enCheckoutButtons[1]);
        $this->assertLocalizedCopy(
            $idCheckoutPayload,
            ['Pilih metode pembayaran di bawah.', '🇮🇩 Indonesia', '🇹🇭 Thailand', '🇲🇾 Malaysia', '🇸🇬 Singapura', '🇯🇵 Jepang', '🇰🇷 Korea Selatan', '🇨🇳 Tiongkok'],
            ['Choose a payment method below.', '🇸🇬 Singapore'],
        );
        $this->assertLocalizedCopy(
            $enCheckoutPayload,
            ['Choose a payment method below.', '🇮🇩 Indonesia', '🇹🇭 Thailand', '🇲🇾 Malaysia', '🇸🇬 Singapore', '🇯🇵 Japan', '🇰🇷 South Korea', '🇨🇳 China'],
            ['Pilih metode pembayaran di bawah.', '🇸🇬 Singapura'],
        );
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
            $this->assertStringContainsString($fragment, $copy);
        }
        foreach ($oppositeLocale as $fragment) {
            $this->assertStringNotContainsString($fragment, $copy);
        }
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
