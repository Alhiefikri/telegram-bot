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
}
