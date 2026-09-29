<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\TelegramUiIcon;
use App\Services\Telegram\CustomEmojiCapability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CatalogIconCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_command_reports_identifiers_that_telegram_still_resolves(): void
    {
        $this->product('QA Gmail Fresh', '5368324170671202286');
        TelegramUiIcon::query()->where('key', 'menu.products')->sole()->update(['custom_emoji_id' => '5368324170671202287']);
        TelegramUiIcon::query()->where('key', 'message.payment_card')->sole()->update(['custom_emoji_id' => '5368324170671202288']);
        Http::fake([
            'https://api.telegram.org/*/getCustomEmojiStickers' => Http::response([
                'ok' => true,
                'result' => [
                    ['custom_emoji_id' => '5368324170671202286', 'emoji' => '📩'],
                    ['custom_emoji_id' => '5368324170671202287', 'emoji' => '🛍️'],
                    ['custom_emoji_id' => '5368324170671202288', 'emoji' => '🪙'],
                ],
            ]),
        ]);

        $this->artisan('catalog:icons-check')
            ->expectsOutputToContain('Captured identifiers: 3 (products: 1, menu slots: 1, message-copy slots: 1)')
            ->expectsOutputToContain('OK   product #')
            ->expectsOutputToContain('message-copy message.payment_card')
            ->assertExitCode(0);
    }

    public function test_the_command_sends_numeric_identifiers_as_strings(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        $this->product('QA ChatGPT Plus 1 Bulan', '6134246530380472478');

        Http::fake(function ($request) {
            $payload = json_decode((string) ($request->data()['custom_emoji_ids'] ?? '[]'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(['6134246530380472478'], $payload);

            return Http::response([
                'ok' => true,
                'result' => [
                    ['custom_emoji_id' => '6134246530380472478', 'emoji' => '😄'],
                ],
            ]);
        });

        $this->artisan('catalog:icons-check')
            ->expectsOutputToContain('OK   product #')
            ->assertExitCode(0);
    }

    public function test_the_command_fails_when_an_identifier_no_longer_resolves(): void
    {
        $this->product('QA Gmail Fresh', '5368324170671202286');
        Http::fake([
            'https://api.telegram.org/*/getCustomEmojiStickers' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $this->artisan('catalog:icons-check')
            ->expectsOutputToContain('MISS product #')
            ->assertExitCode(1);
    }

    public function test_the_command_is_content_without_identifiers(): void
    {
        Http::fake();

        $this->artisan('catalog:icons-check')
            ->expectsOutputToContain('No captured custom emoji identifiers to verify.')
            ->assertExitCode(0);
    }

    public function test_the_command_can_clear_a_remembered_rejection(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        $capability = app(CustomEmojiCapability::class);
        $capability->markUnavailable("Bad Request: can't use custom emoji");

        $this->artisan('catalog:icons-check --reset-capability')
            ->expectsOutputToContain('cleared')
            ->assertExitCode(0);

        $this->assertTrue($capability->isAvailable());
    }

    private function product(string $name, string $customEmojiId): Product
    {
        return Product::factory()->create([
            'name' => $name,
            'custom_emoji_id' => $customEmojiId,
            'stock' => 1,
            'reserved_stock' => 0,
        ]);
    }
}
