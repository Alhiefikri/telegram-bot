<?php

namespace App\Services\Telegram;

/**
 * One transient Telegram photo send. PNG bytes never cross a durable queue or
 * outbound-effect boundary and are discarded after the transport attempt.
 */
final readonly class TelegramPhotoUnit extends TelegramMessageUnit
{
    public function __construct(
        private string $photoContents,
        private string $filename,
        ?string $caption = null,
        array $captionEntities = [],
        array $replyMarkup = [],
    ) {
        parent::__construct($caption ?? '', $captionEntities, $replyMarkup);
    }

    public function photoContents(): string
    {
        return $this->photoContents;
    }

    /** @return array<string, mixed> */
    public function options(): array
    {
        return array_merge(
            ['protect_content' => true],
            $this->entities() === [] ? [] : ['caption_entities' => $this->entities()],
        );
    }

    public function filename(): string
    {
        return $this->filename;
    }
}
