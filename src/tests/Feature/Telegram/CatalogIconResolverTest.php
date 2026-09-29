<?php

namespace Tests\Feature\Telegram;

use App\Services\Telegram\CatalogIconResolver;
use App\Services\Telegram\CustomEmojiCapability;
use Tests\TestCase;

class CatalogIconResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(CustomEmojiCapability::class)->markAvailable();
    }

    public function test_a_product_glyph_is_used_when_no_custom_emoji_is_captured(): void
    {
        $icon = $this->resolver()->resolve('🔥');

        $this->assertSame('🔥', $icon->glyph);
        $this->assertNull($icon->customEmojiId);
        $this->assertFalse($icon->hasCustomEmoji());
        $this->assertSame('🔥 Paket', $icon->buttonText('Paket'));
        $this->assertSame([], $icon->buttonFields());
    }

    public function test_a_captured_custom_emoji_is_attached_while_the_glyph_stays_for_plain_text(): void
    {
        $icon = $this->resolver()->resolve('📩', '5368324170671202286');

        $this->assertSame('📩', $icon->glyph);
        $this->assertSame('5368324170671202286', $icon->customEmojiId);
        $this->assertTrue($icon->hasCustomEmoji());
        $this->assertSame('Gmail Fresh', $icon->buttonText('Gmail Fresh'));
        $this->assertSame(['icon_custom_emoji_id' => '5368324170671202286'], $icon->buttonFields());
    }

    public function test_a_product_without_an_icon_falls_back_to_the_default_glyph(): void
    {
        $icon = $this->resolver()->resolve(null);

        $this->assertSame('🛍️', $icon->glyph);
        $this->assertNull($icon->customEmojiId);
        $this->assertSame('🛍️ Paket Rahasia', $icon->buttonText('Paket Rahasia'));
        $this->assertSame([], $icon->buttonFields());
    }

    public function test_blank_values_are_ignored_instead_of_rendered(): void
    {
        $icon = $this->resolver()->resolve('   ', '  ');

        $this->assertSame('🛍️', $icon->glyph);
        $this->assertNull($icon->customEmojiId);
    }

    public function test_an_unavailable_capability_falls_back_to_the_glyph_on_buttons(): void
    {
        app(CustomEmojiCapability::class)->markUnavailable('Bad Request: custom emoji is not available');

        $icon = $this->resolver()->resolve('📩', '5368324170671202286');

        $this->assertNull($icon->customEmojiId);
        $this->assertSame('📩 Gmail Fresh', $icon->buttonText('Gmail Fresh'));
    }

    private function resolver(): CatalogIconResolver
    {
        return app(CatalogIconResolver::class);
    }
}
