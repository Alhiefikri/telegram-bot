<?php

namespace App\Services\Telegram;

final readonly class TelegramPaymentInstruction
{
    /**
     * @param  list<string>  $captionLines
     * @param  array<string, mixed>  $replyMarkup
     * @param  list<int>  $boldLines
     */
    public function __construct(
        public string $photoContents,
        public string $filename,
        private array $captionLines,
        public array $replyMarkup = [],
        private ?int $copyableAmountLine = null,
        private array $boldLines = [],
    ) {}

    /**
     * @return list<string>
     */
    public function captionLines(): array
    {
        return $this->captionLines;
    }

    public function caption(): string
    {
        if ($this->boldLines === []) {
            return implode("\n", $this->captionLines);
        }

        $html = new TelegramHtmlCaption;
        $lines = [];
        foreach ($this->captionLines as $index => $line) {
            if ($index === $this->copyableAmountLine
                && preg_match('/[0-9][0-9.,]*(?![^<]*>)/', $line, $match, PREG_OFFSET_CAPTURE) === 1) {
                [$amount, $offset] = $match[0];
                $line = $html->escapeTextPreservingIcons(substr($line, 0, $offset))
                    .'<code>'.$amount.'</code>'
                    .$html->escapeTextPreservingIcons(substr($line, $offset + strlen($amount)));
            } else {
                $line = $html->escapeTextPreservingIcons($line);
            }
            $lines[] = in_array($index, $this->boldLines, true) ? '<b>'.$line.'</b>' : $line;
        }

        return implode("\n", $lines);
    }

    /** @return array{parse_mode?: string, caption_entities?: list<array{type: string, offset: int, length: int}>} */
    public function options(): array
    {
        if ($this->boldLines !== []) {
            return ['parse_mode' => 'HTML'];
        }

        if ($this->copyableAmountLine === null
            || ! isset($this->captionLines[$this->copyableAmountLine])) {
            return [];
        }

        $line = $this->captionLines[$this->copyableAmountLine];
        if (preg_match('/[0-9][0-9.,]*/', $line, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return [];
        }

        [$amount, $offset] = $match[0];
        $prefix = implode("\n", array_slice($this->captionLines, 0, $this->copyableAmountLine));
        $prefix .= ($this->copyableAmountLine > 0 ? "\n" : '').substr($line, 0, $offset);

        return ['caption_entities' => [[
            'type' => 'code',
            'offset' => intdiv(strlen(mb_convert_encoding($prefix, 'UTF-16LE', 'UTF-8')), 2),
            'length' => strlen($amount),
        ]]];
    }
}
