<?php

namespace App\Services\Telegram;

use App\Enums\TelegramNavigationMediaKind;

class TelegramWelcomeBanner
{
    private const OPTIONS = ['parse_mode' => 'HTML'];

    public function __construct(
        private TelegramBotClient $bot,
        private TelegramOutboundResponseValidator $responseValidator,
    ) {}

    /**
     * @param  array<string, mixed>  $keyboard
     */
    public function send(string $chatId, string $caption, array $keyboard, ?string $preferredReference = null): TelegramWelcomeBannerSend
    {
        $reference = $this->bannerReference($preferredReference);
        if ($reference !== null) {
            return $this->photoResult(
                $this->bot->sendPhotoReference($chatId, $reference, $caption, $keyboard, self::OPTIONS),
                $reference,
            );
        }

        $bannerPath = resource_path('images/banner-welcome.png');
        if (is_file($bannerPath) && is_readable($bannerPath)) {
            $contents = file_get_contents($bannerPath);
            if ($contents !== false) {
                return $this->photoResult(
                    $this->bot->sendPhoto($chatId, $contents, 'banner-welcome.png', $caption, $keyboard, self::OPTIONS),
                    null,
                );
            }
        }

        $response = $this->bot->sendMessage($chatId, $caption, $keyboard, self::OPTIONS);

        return new TelegramWelcomeBannerSend(
            (int) $this->responseValidator->messageIdFromResponse($response, 'Telegram welcome banner'),
            TelegramNavigationMediaKind::Text,
            null,
        );
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
