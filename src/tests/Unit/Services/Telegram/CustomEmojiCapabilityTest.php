<?php

namespace Tests\Unit\Services\Telegram;

use App\Services\Telegram\CustomEmojiCapability;
use Tests\TestCase;

class CustomEmojiCapabilityTest extends TestCase
{
    private const EMOJI_ID = '5368324170671202286';

    public function test_text_without_markup_keeps_the_entities_untouched(): void
    {
        $entities = [['type' => 'code', 'offset' => 4, 'length' => 3]];

        $result = app(CustomEmojiCapability::class)->textAndEntities('Invoice 123', $entities);

        $this->assertSame('Invoice 123', $result['text']);
        $this->assertSame($entities, $result['entities']);
    }

    public function test_entity_after_custom_emoji_markup_is_rebased_to_the_rendered_text(): void
    {
        $text = 'Pay <tg-emoji emoji-id="'.self::EMOJI_ID.'" data-omp-icon="1">🪙</tg-emoji> to 987654321 now';
        $entities = [[
            'type' => 'code',
            'offset' => $this->utf16Offset($text, '987654321'),
            'length' => 9,
        ]];

        $result = app(CustomEmojiCapability::class)->textAndEntities($text, $entities);

        $this->assertSame('Pay 🪙 to 987654321 now', $result['text']);
        $this->assertSame(
            ['custom_emoji', 'code'],
            array_column($result['entities'], 'type'),
        );
        $this->assertSame(
            ['🪙', '987654321'],
            array_map(
                fn (array $entity): string => $this->resolve($result['text'], $entity),
                $result['entities'],
            ),
        );
    }

    public function test_entities_before_markup_keep_their_offset(): void
    {
        $text = 'Amount 2.5 then <tg-emoji emoji-id="'.self::EMOJI_ID.'" data-omp-icon="1">🪙</tg-emoji> tail';
        $entities = [[
            'type' => 'code',
            'offset' => $this->utf16Offset($text, '2.5'),
            'length' => 3,
        ]];

        $result = app(CustomEmojiCapability::class)->textAndEntities($text, $entities);

        $this->assertSame('Amount 2.5 then 🪙 tail', $result['text']);
        $this->assertSame('2.5', $this->resolve($result['text'], $result['entities'][0]));
    }

    public function test_multiple_markups_shift_each_entity_by_its_preceding_removals(): void
    {
        $markup = '<tg-emoji emoji-id="'.self::EMOJI_ID.'" data-omp-icon="1">🪙</tg-emoji>';
        $text = $markup.' amount 2.5 '.$markup.' receiver 987654321';
        $entities = [
            ['type' => 'code', 'offset' => $this->utf16Offset($text, '2.5'), 'length' => 3],
            ['type' => 'code', 'offset' => $this->utf16Offset($text, '987654321'), 'length' => 9],
        ];

        $result = app(CustomEmojiCapability::class)->textAndEntities($text, $entities);

        $this->assertSame('🪙 amount 2.5 🪙 receiver 987654321', $result['text']);
        $codes = array_values(array_filter($result['entities'], fn (array $entity): bool => $entity['type'] === 'code'));
        $this->assertSame(['2.5', '987654321'], array_map(
            fn (array $entity): string => $this->resolve($result['text'], $entity),
            $codes,
        ));
    }

    public function test_entity_overlapping_removed_markup_is_dropped_instead_of_corrupted(): void
    {
        $markup = '<tg-emoji emoji-id="'.self::EMOJI_ID.'" data-omp-icon="1">🪙</tg-emoji>';
        $text = 'lead '.$markup.' tail';
        $entities = [[
            'type' => 'code',
            'offset' => $this->utf16Offset($text, $markup) + 3,
            'length' => 10,
        ]];

        $result = app(CustomEmojiCapability::class)->textAndEntities($text, $entities);

        $this->assertSame(['custom_emoji'], array_column($result['entities'], 'type'));
    }

    public function test_entities_are_returned_sorted_by_offset(): void
    {
        $text = 'B <tg-emoji emoji-id="'.self::EMOJI_ID.'" data-omp-icon="1">🪙</tg-emoji> A 123';
        $entities = [[
            'type' => 'code',
            'offset' => $this->utf16Offset($text, '123'),
            'length' => 3,
        ]];

        $result = app(CustomEmojiCapability::class)->textAndEntities($text, $entities);

        $offsets = array_column($result['entities'], 'offset');
        $sorted = $offsets;
        sort($sorted);
        $this->assertSame($sorted, $offsets);
    }

    private function utf16Offset(string $text, string $needle): int
    {
        $byteOffset = strpos($text, $needle);
        $this->assertNotFalse($byteOffset, "Missing needle {$needle}");

        return intdiv(strlen(mb_convert_encoding(substr($text, 0, $byteOffset), 'UTF-16LE', 'UTF-8')), 2);
    }

    /** @param array<string, mixed> $entity */
    private function resolve(string $text, array $entity): string
    {
        $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');

        return mb_convert_encoding(
            substr($utf16, (int) $entity['offset'] * 2, (int) $entity['length'] * 2),
            'UTF-8',
            'UTF-16LE',
        );
    }
}
