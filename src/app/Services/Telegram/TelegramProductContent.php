<?php

namespace App\Services\Telegram;

use App\Models\Product;
use InvalidArgumentException;

/** Plain catalog text stays canonical; Telegram metadata is bound to that exact text. */
final class TelegramProductContent
{
    public function entities(Product $product, string $field): array
    {
        $content = $product->telegram_content[$field] ?? null;

        return is_array($content) && ($content['text'] ?? null) === (string) $product->{$field}
            ? ($content['entities'] ?? []) : [];
    }

    public function capture(string $text, array $entities): array
    {
        $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $result = [];
        foreach ($entities as $entity) {
            if (($entity['type'] ?? null) !== 'custom_emoji') {
                continue;
            }
            $offset = $entity['offset'] ?? null;
            $length = $entity['length'] ?? null;
            $id = $entity['custom_emoji_id'] ?? null;
            if (! is_int($offset) || ! is_int($length) || $offset < 0 || $length < 1
                || ($offset + $length) * 2 > strlen($utf16)
                || ! is_string($id) || preg_match('/\A[0-9]+\z/D', $id) !== 1) {
                throw new InvalidArgumentException('Entity emoji tidak valid. Kirim ulang teks dari Telegram.');
            }
            $glyph = substr($utf16, $offset * 2, $length * 2);
            if (! mb_check_encoding($glyph, 'UTF-16LE')) {
                throw new InvalidArgumentException('Batas emoji tidak valid.');
            }
            $result[] = ['type' => 'custom_emoji', 'offset' => $offset, 'length' => $length, 'custom_emoji_id' => $id];
        }
        usort($result, fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);
        $end = 0;
        foreach ($result as $entity) {
            if ($entity['offset'] < $end) {
                throw new InvalidArgumentException('Entity emoji bertumpuk.');
            }
            $end = $entity['offset'] + $entity['length'];
        }

        return $result;
    }

    public function html(Product $product, string $field): string
    {
        $utf16 = mb_convert_encoding((string) $product->{$field}, 'UTF-16LE', 'UTF-8');
        $html = '';
        $cursor = 0;
        foreach ($this->entities($product, $field) as $entity) {
            $start = $entity['offset'] * 2;
            $length = $entity['length'] * 2;
            $html .= $this->escape(mb_convert_encoding(substr($utf16, $cursor, $start - $cursor), 'UTF-8', 'UTF-16LE'));
            $glyph = mb_convert_encoding(substr($utf16, $start, $length), 'UTF-8', 'UTF-16LE');
            $html .= '<tg-emoji emoji-id="'.$entity['custom_emoji_id'].'">'.$this->escape($glyph).'</tg-emoji>';
            $cursor = $start + $length;
        }

        return $html.$this->escape(mb_convert_encoding(substr($utf16, $cursor), 'UTF-8', 'UTF-16LE'));
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
