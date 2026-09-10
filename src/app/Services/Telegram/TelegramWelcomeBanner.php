<?php

namespace App\Services\Telegram;

use App\Enums\TelegramNavigationMediaKind;

class TelegramWelcomeBanner
{
    private const OPTIONS = ['parse_mode' => 'HTML'];

    public function __construct(
        private TelegramBotClient $bot,
        private TelegramOutboundResponseValidator $responseValidator,
        private TelegramHtmlCaption $captions,
    ) {}

    /**
     * @param  array<string, mixed>  $keyboard
     * @param  array<string, mixed>  $options
     */
    public function send(string $chatId, string $caption, array $keyboard, ?string $preferredReference = null, array $options = self::OPTIONS): TelegramWelcomeBannerSend
    {
        // Telegram only allows 1024 parsed characters on a media caption.
        // Longer navigation screens are delivered as a text message so the
        // canonical message never truncates customer-relevant content.
        if (! $this->captions->fitsMediaCaption($caption, $options)) {
            return $this->textResult($this->bot->sendMessage($chatId, $caption, $keyboard, $options));
        }

        $reference = $this->bannerReference($preferredReference);
        if ($reference !== null) {
            return $this->photoResult(
                $this->bot->sendPhotoReference($chatId, $reference, $caption, $keyboard, $options),
                $reference,
            );
        }

        $bannerPath = resource_path('images/banner-welcome.png');
        if (is_file($bannerPath) && is_readable($bannerPath)) {
            $contents = file_get_contents($bannerPath);
            if ($contents !== false) {
                return $this->photoResult(
                    $this->bot->sendPhoto($chatId, $contents, 'banner-welcome.png', $caption, $keyboard, $options),
                    null,
                );
            }
        }

        return $this->textResult($this->bot->sendMessage($chatId, $caption, $keyboard, $options));
    }

    private function bannerReference(?string $preferred): ?string
    {
        foreach ([$preferred, config('services.telegram.welcome_banner_url')] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function textResult(array $response): TelegramWelcomeBannerSend
    {
        return new TelegramWelcomeBannerSend(
            (int) $this->responseValidator->messageIdFromResponse($response, 'Telegram welcome banner'),
            TelegramNavigationMediaKind::Text,
            null,
        );
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function photoResult(array $response, ?string $usedReference): TelegramWelcomeBannerSend
    {
        return new TelegramWelcomeBannerSend(
            (int) $this->responseValidator->messageIdFromResponse($response, 'Telegram welcome banner'),
            TelegramNavigationMediaKind::Photo,
            $this->photoFileId(data_get($response, 'result.photo')) ?? $usedReference,
        );
    }

    private function photoFileId(mixed $photo): ?string
    {
        if (is_string($photo)) {
            return trim($photo) !== '' ? trim($photo) : null;
        }

        if (! is_array($photo)) {
            return null;
        }

        $fileId = null;
        foreach ($photo as $size) {
            $candidate = is_array($size) ? ($size['file_id'] ?? null) : null;
            if (is_string($candidate) && trim($candidate) !== '') {
                $fileId = trim($candidate);
            }
        }

        return $fileId;
    }
}
