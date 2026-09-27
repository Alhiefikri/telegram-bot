<?php

namespace Tests\Feature;

use App\Models\CatalogBrandIcon;
use App\Services\Telegram\CustomEmojiCapability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CatalogBrandIconCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_command_reports_identifiers_that_telegram_still_resolves(): void
    {
        $this->brand('netflix', '5368324170671202286');
        Http::fake([
            'https://api.telegram.org/*/getCustomEmojiStickers' => Http::response([
                'ok' => true,
                'result' => [['custom_emoji_id' => '5368324170671202286', 'emoji' => '📺']],
            ]),
        ]);

        $this->artisan('catalog:brand-icons-check')
            ->expectsOutputToContain('OK   netflix')
            ->assertExitCode(0);
    }

    public function test_the_command_fails_when_an_identifier_no_longer_resolves(): void
    {
        $this->brand('netflix', '5368324170671202286');
        Http::fake([
            'https://api.telegram.org/*/getCustomEmojiStickers' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $this->artisan('catalog:brand-icons-check')
            ->expectsOutputToContain('MISS netflix')
            ->assertExitCode(1);
    }

    public function test_the_command_can_clear_a_remembered_rejection(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        $capability = app(CustomEmojiCapability::class);
        $capability->markUnavailable("Bad Request: can't use custom emoji");

        $this->artisan('catalog:brand-icons-check --reset-capability')
            ->expectsOutputToContain('cleared')
            ->assertExitCode(0);

        $this->assertTrue($capability->isAvailable());
    }

    private function brand(string $slug, string $customEmojiId): void
    {
        CatalogBrandIcon::query()->where('slug', $slug)->sole()->update(['custom_emoji_id' => $customEmojiId]);
    }
}
