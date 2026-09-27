<?php

namespace Tests\Feature\Telegram;

use App\Models\CatalogBrandIcon;
use App\Services\Telegram\CustomEmojiCapability;
use App\Services\Telegram\ProductBrandIconResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductBrandIconResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(CustomEmojiCapability::class)->markAvailable();
    }

    public function test_a_configured_product_icon_wins_over_the_brand_registry(): void
    {
        $this->brand('chatgpt', ['custom_emoji_id' => '5368324170671202286']);

        $icon = $this->resolver()->resolve('ChatGPT Plus', '🔥');

        $this->assertSame('🔥', $icon->glyph);
        $this->assertNull($icon->customEmojiId);
        $this->assertFalse($icon->hasCustomEmoji());
    }

    public function test_a_matched_brand_uses_its_fallback_glyph_when_no_custom_emoji_is_captured(): void
    {
        $icon = $this->resolver()->resolve('ChatGPT Plus 1 Bulan');

        $this->assertSame('🤖', $icon->glyph);
        $this->assertNull($icon->customEmojiId);
    }

    public function test_a_captured_custom_emoji_is_attached_while_the_glyph_stays_for_plain_text(): void
    {
        $this->brand('netflix', ['custom_emoji_id' => '5368324170671202286']);

        $icon = $this->resolver()->resolve('Netflix Premium');

        $this->assertSame('📺', $icon->glyph);
        $this->assertSame('5368324170671202286', $icon->customEmojiId);
        $this->assertTrue($icon->hasCustomEmoji());
        $this->assertSame('Netflix Premium', $icon->buttonText('Netflix Premium'));
        $this->assertSame(['icon_custom_emoji_id' => '5368324170671202286'], $icon->buttonFields());
    }

    public function test_an_unknown_name_falls_back_to_the_default_glyph(): void
    {
        $icon = $this->resolver()->resolve('Paket Rahasia Tanpa Merek');

        $this->assertSame('🛍️', $icon->glyph);
        $this->assertNull($icon->customEmojiId);
        $this->assertSame('🛍️ Paket Rahasia', $icon->buttonText('Paket Rahasia'));
        $this->assertSame([], $icon->buttonFields());
    }

    public function test_an_unavailable_capability_falls_back_to_the_glyph_on_buttons(): void
    {
        $this->brand('chatgpt', ['custom_emoji_id' => '5368324170671202286']);
        app(CustomEmojiCapability::class)->markUnavailable('Bad Request: custom emoji is not available');

        $icon = $this->resolver()->resolve('ChatGPT Plus');

        $this->assertNull($icon->customEmojiId);
        $this->assertSame('🤖 ChatGPT Plus', $icon->buttonText('ChatGPT Plus'));
    }

    public function test_updating_a_brand_without_a_custom_emoji_id_clears_the_resolved_icon(): void
    {
        $this->brand('netflix', ['custom_emoji_id' => '5368324170671202286']);
        $this->assertTrue($this->resolver()->resolve('Netflix Premium')->hasCustomEmoji());

        $this->brand('netflix', ['custom_emoji_id' => null]);

        $this->assertFalse($this->resolver()->resolve('Netflix Premium')->hasCustomEmoji());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function brand(string $slug, array $attributes = []): CatalogBrandIcon
    {
        return CatalogBrandIcon::query()->updateOrCreate(['slug' => $slug], $attributes);
    }

    private function resolver(): ProductBrandIconResolver
    {
        return app(ProductBrandIconResolver::class);
    }
}
