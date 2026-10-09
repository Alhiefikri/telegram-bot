<?php

namespace Tests\Unit\Services\Telegram;

use App\Models\Product;
use App\Services\Telegram\TelegramProductContent;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TelegramProductContentTest extends TestCase
{
    private const FIRE_ID = '5368324170671202286';

    private const STAR_ID = '5368324170671202287';

    private function product(string $text, array $entities, string $field = 'description'): Product
    {
        return (new Product)->forceFill([
            $field => $text,
            'telegram_content' => [$field => ['text' => $text, 'entities' => $entities]],
        ]);
    }

    public function test_utf16_offsets_render_adjacent_emoji_after_a_non_bmp_prefix(): void
    {
        $content = new TelegramProductContent;
        // 😀 uses two UTF-16 units; 🔥 starts at unit four, not character three.
        $entities = $content->capture('😀 A🔥⭐!', [
            ['type' => 'custom_emoji', 'offset' => 6, 'length' => 1, 'custom_emoji_id' => self::STAR_ID],
            ['type' => 'custom_emoji', 'offset' => 4, 'length' => 2, 'custom_emoji_id' => self::FIRE_ID],
        ]);

        $this->assertSame(
            '😀 A<tg-emoji emoji-id="5368324170671202286">🔥</tg-emoji><tg-emoji emoji-id="5368324170671202287">⭐</tg-emoji>!',
            $content->html($this->product('😀 A🔥⭐!', $entities), 'description'),
        );
    }

    public function test_emoji_at_both_text_boundaries_preserve_multiline_content_between_them(): void
    {
        $text = "🔥\nA & B\n⭐";
        $content = new TelegramProductContent;
        $entities = $content->capture($text, [
            ['type' => 'custom_emoji', 'offset' => 0, 'length' => 2, 'custom_emoji_id' => self::FIRE_ID],
            ['type' => 'custom_emoji', 'offset' => 9, 'length' => 1, 'custom_emoji_id' => self::STAR_ID],
        ]);

        $this->assertSame(
            "<tg-emoji emoji-id=\"5368324170671202286\">🔥</tg-emoji>\nA &amp; B\n<tg-emoji emoji-id=\"5368324170671202287\">⭐</tg-emoji>",
            $content->html($this->product($text, $entities), 'description'),
        );
    }

    public function test_user_authored_html_is_inert_even_when_it_contains_a_fake_custom_emoji_tag(): void
    {
        $text = '<tg-emoji emoji-id="999">⭐</tg-emoji> <b>"A" & B</b>';

        $this->assertSame(
            '&lt;tg-emoji emoji-id=&quot;999&quot;&gt;⭐&lt;/tg-emoji&gt; &lt;b&gt;&quot;A&quot; &amp; B&lt;/b&gt;',
            (new TelegramProductContent)->html($this->product($text, []), 'description'),
        );
    }

    public function test_non_custom_entities_do_not_turn_text_into_formatting(): void
    {
        $content = new TelegramProductContent;
        $text = '<b>🔥</b>';
        $entities = $content->capture($text, [
            ['type' => 'bold', 'offset' => 0, 'length' => 9],
            ['type' => 'text_link', 'offset' => 0, 'length' => 9, 'url' => 'https://example.test'],
            ['type' => 'custom_emoji', 'offset' => 3, 'length' => 2, 'custom_emoji_id' => self::FIRE_ID],
        ]);

        $this->assertSame(
            '&lt;b&gt;<tg-emoji emoji-id="5368324170671202286">🔥</tg-emoji>&lt;/b&gt;',
            $content->html($this->product($text, $entities), 'description'),
        );
    }

    #[DataProvider('invalidEmojiEntities')]
    public function test_invalid_emoji_entities_are_rejected(array $entity): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new TelegramProductContent)->capture('🔥⭐', [$entity]);
    }

    public static function invalidEmojiEntities(): iterable
    {
        $valid = ['type' => 'custom_emoji', 'offset' => 0, 'length' => 2, 'custom_emoji_id' => self::FIRE_ID];
        yield 'negative offset' => [array_replace($valid, ['offset' => -1])];
        yield 'string offset' => [array_replace($valid, ['offset' => '0'])];
        yield 'missing offset' => [array_diff_key($valid, ['offset' => true])];
        yield 'zero length' => [array_replace($valid, ['length' => 0])];
        yield 'negative length' => [array_replace($valid, ['length' => -1])];
        yield 'string length' => [array_replace($valid, ['length' => '2'])];
        yield 'missing length' => [array_diff_key($valid, ['length' => true])];
        yield 'extends past text' => [array_replace($valid, ['length' => 4])];
        yield 'starts after text' => [array_replace($valid, ['offset' => 4])];
        yield 'high surrogate without low surrogate' => [array_replace($valid, ['length' => 1])];
        yield 'starts inside surrogate pair' => [array_replace($valid, ['offset' => 1, 'length' => 1])];
        yield 'missing emoji id' => [array_diff_key($valid, ['custom_emoji_id' => true])];
        yield 'empty emoji id' => [array_replace($valid, ['custom_emoji_id' => ''])];
        yield 'integer emoji id' => [array_replace($valid, ['custom_emoji_id' => 123])];
        yield 'emoji id contains markup' => [array_replace($valid, ['custom_emoji_id' => '123"><b>'])];
        yield 'emoji id contains whitespace' => [array_replace($valid, ['custom_emoji_id' => '123 '])];
    }

    public function test_overlapping_emoji_are_rejected_even_when_each_utf16_slice_is_valid(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new TelegramProductContent)->capture('🔥⭐', [
            ['type' => 'custom_emoji', 'offset' => 0, 'length' => 3, 'custom_emoji_id' => self::FIRE_ID],
            ['type' => 'custom_emoji', 'offset' => 2, 'length' => 1, 'custom_emoji_id' => self::STAR_ID],
        ]);
    }

    public function test_duplicate_emoji_ranges_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new TelegramProductContent)->capture('🔥', [
            ['type' => 'custom_emoji', 'offset' => 0, 'length' => 2, 'custom_emoji_id' => self::FIRE_ID],
            ['type' => 'custom_emoji', 'offset' => 0, 'length' => 2, 'custom_emoji_id' => self::STAR_ID],
        ]);
    }

    public function test_metadata_for_different_text_cannot_attach_emoji_to_a_plain_replacement(): void
    {
        $product = $this->product('🔥', [
            ['type' => 'custom_emoji', 'offset' => 0, 'length' => 2, 'custom_emoji_id' => self::FIRE_ID],
        ]);
        $product->description = '<b>Replacement</b>';
        $content = new TelegramProductContent;

        $this->assertSame([], $content->entities($product, 'description'));
        $this->assertSame('&lt;b&gt;Replacement&lt;/b&gt;', $content->html($product, 'description'));
    }

    public function test_refund_policy_and_description_keep_independent_emoji_metadata(): void
    {
        $product = (new Product)->forceFill([
            'description' => '🔥',
            'warranty_policy' => '⭐',
            'telegram_content' => [
                'description' => ['text' => '🔥', 'entities' => [
                    ['type' => 'custom_emoji', 'offset' => 0, 'length' => 2, 'custom_emoji_id' => self::FIRE_ID],
                ]],
                'warranty_policy' => ['text' => '⭐', 'entities' => [
                    ['type' => 'custom_emoji', 'offset' => 0, 'length' => 1, 'custom_emoji_id' => self::STAR_ID],
                ]],
            ],
        ]);
        $product->description = 'Changed details';
        $content = new TelegramProductContent;

        $this->assertSame('Changed details', $content->html($product, 'description'));
        $this->assertSame('<tg-emoji emoji-id="5368324170671202287">⭐</tg-emoji>', $content->html($product, 'warranty_policy'));
    }

    public function test_legacy_product_without_telegram_metadata_renders_plain_text_safely(): void
    {
        $product = (new Product)->forceFill(['description' => "A & B\n🔥", 'warranty_policy' => '']);
        $content = new TelegramProductContent;

        $this->assertSame("A &amp; B\n🔥", $content->html($product, 'description'));
        $this->assertSame('', $content->html($product, 'warranty_policy'));
    }

    public function test_locale_variant_metadata_is_bound_to_the_variant_column(): void
    {
        $product = (new Product)->forceFill([
            'description' => '🔥',
            'description_en' => 'Warranty 🔥',
            'telegram_content' => [
                'description' => ['text' => '🔥', 'entities' => [
                    ['type' => 'custom_emoji', 'offset' => 0, 'length' => 2, 'custom_emoji_id' => self::FIRE_ID],
                ]],
                'description_en' => ['text' => 'Warranty 🔥', 'entities' => [
                    ['type' => 'custom_emoji', 'offset' => 9, 'length' => 2, 'custom_emoji_id' => self::STAR_ID],
                ]],
            ],
        ]);
        $content = new TelegramProductContent;

        $this->assertSame('<tg-emoji emoji-id="'.self::FIRE_ID.'">🔥</tg-emoji>', $content->html($product, 'description'));
        $this->assertSame('Warranty <tg-emoji emoji-id="'.self::STAR_ID.'">🔥</tg-emoji>', $content->html($product, 'description_en'));
    }

    public function test_units_count_utf16_code_units_including_surrogate_pairs(): void
    {
        $content = new TelegramProductContent;

        $this->assertSame(5, $content->units('abcde'));
        $this->assertSame(4, $content->units('🔥🔥'));
        $this->assertSame(TelegramProductContent::MAX_PRODUCT_DETAIL_UNITS, $content->units(str_repeat('x', 3000)));
    }
}
