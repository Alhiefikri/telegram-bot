<?php

namespace App\Services\Telegram;

final readonly class TelegramPaymentInstruction
{
    /**
     * @param  list<string>  $captionLines
     * @param  array<string, mixed>  $replyMarkup
     */
    public function __construct(
        public string $photoContents,
        public string $filename,
        private array $captionLines,
        public array $replyMarkup = [],
        private ?int $copyableAmountLine = null,
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
        return implode("\n", $this->captionLines);
    }

    /** @return array{caption_entities?: list<array{type: string, offset: int, length: int}>} */
    public function options(): array
    {
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
