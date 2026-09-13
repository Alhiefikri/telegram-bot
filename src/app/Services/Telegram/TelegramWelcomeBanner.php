<?php

namespace App\Services\Telegram;

use App\Enums\TelegramNavigationMediaKind;
use App\Models\StorefrontSetting;
use App\Services\Admin\ProductImageOptimizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class TelegramWelcomeBanner
{
    private const OPTIONS = ['parse_mode' => 'HTML'];

    private const UPLOAD_LOCK_SECONDS = 60;

    public function __construct(
        private TelegramBotClient $bot,
        private TelegramOutboundResponseValidator $responseValidator,
        private TelegramHtmlCaption $captions,
        private ProductImageOptimizer $images,
    ) {}

    /**
     * @param  array<string, mixed>  $keyboard
     * @param  array<string, mixed>  $options
     */
    public function send(
        string $chatId,
        string $caption,
        array $keyboard,
        ?string $preferredReference = null,
        array $options = self::OPTIONS,
        ?int $preferredBannerVersion = null,
        bool $removeLegacyReplyKeyboard = false,
    ): TelegramWelcomeBannerSend {
        $active = $this->activeSetting();
        $activeVersion = $active === null ? null : (int) $active->telegram_welcome_banner_version;
        $replyMarkup = $removeLegacyReplyKeyboard ? ['remove_keyboard' => true] : $keyboard;

        // Telegram stores reply keyboards at chat level. Send the migration
        // removal with the root message, then restore its inline controls by
        // editing that same message so the chat still has one root.

        // Telegram only allows 1024 parsed characters on a media caption.
        // Longer navigation screens are delivered as a text message so the
        // canonical message never truncates customer-relevant content.
        if (! $this->captions->fitsMediaCaption($caption, $options)) {
            $sent = $this->textResult(
                $this->bot->sendMessage($chatId, $caption, $replyMarkup, $options),
                $activeVersion,
            );
        } elseif ($active !== null) {
            $sent = $this->sendActiveBanner(
                $active,
                $chatId,
                $caption,
                $replyMarkup,
                $preferredReference,
                $preferredBannerVersion,
                $options,
            );
        } else {
            $sent = $this->sendFallbackBanner($chatId, $caption, $replyMarkup, $preferredReference, $options);
        }

        if ($removeLegacyReplyKeyboard) {
            $this->restoreInlineReplyMarkup($chatId, $sent, $caption, $keyboard, $options);
        }

        return $sent;
    }

    /**
     * @param  array<string, mixed>  $keyboard
     * @param  array<string, mixed>  $options
     */
    private function restoreInlineReplyMarkup(
        string $chatId,
        TelegramWelcomeBannerSend $sent,
        string $caption,
        array $keyboard,
        array $options,
    ): void {
        if ($sent->mediaKind === TelegramNavigationMediaKind::Photo) {
            $this->bot->editMessageCaption($chatId, $sent->messageId, $caption, $keyboard, $options);

            return;
        }

        $this->bot->editMessageText($chatId, $sent->messageId, $caption, $keyboard, $options);
    }

    /**
     * @param  array<string, mixed>  $keyboard
     * @param  array<string, mixed>  $options
     */
    private function sendActiveBanner(
        StorefrontSetting $active,
        string $chatId,
        string $caption,
        array $keyboard,
        ?string $preferredReference,
        ?int $preferredBannerVersion,
        array $options,
    ): TelegramWelcomeBannerSend {
        $reference = $this->activeReference($active, $preferredReference, $preferredBannerVersion);
        if ($reference !== null) {
            return $this->photoResult(
                $this->bot->sendPhotoReference($chatId, $reference, $caption, $keyboard, $options),
                $reference,
                (int) $active->telegram_welcome_banner_version,
            );
        }

        return Cache::lock($this->uploadLockKey($active), self::UPLOAD_LOCK_SECONDS)->block(10, function () use (
            $chatId,
            $caption,
            $keyboard,
            $preferredReference,
            $preferredBannerVersion,
            $options,
        ): TelegramWelcomeBannerSend {
            $fresh = $this->activeSetting();
            if ($fresh === null) {
                return $this->sendFallbackBanner($chatId, $caption, $keyboard, $preferredReference, $options);
            }

            $version = (int) $fresh->telegram_welcome_banner_version;
            $reference = $this->activeReference($fresh, $preferredReference, $preferredBannerVersion);
            if ($reference !== null) {
                return $this->photoResult(
                    $this->bot->sendPhotoReference($chatId, $reference, $caption, $keyboard, $options),
                    $reference,
                    $version,
                );
            }

            $photo = $this->images->welcomeBannerPhoto($fresh->telegram_welcome_banner_path);
            if ($photo === null) {
                return $this->sendFallbackBanner($chatId, $caption, $keyboard, $preferredReference, $options);
            }

            $response = $this->bot->sendPhoto(
                $chatId,
                $photo['contents'],
                $photo['filename'],
                $caption,
                $keyboard,
                $options,
            );
            $fileId = $this->photoFileId(data_get($response, 'result.photo'));
            if ($fileId !== null) {
                StorefrontSetting::query()
                    ->whereKey($fresh->getKey())
                    ->where('telegram_welcome_banner_version', $version)
                    ->where('telegram_welcome_banner_path', $fresh->telegram_welcome_banner_path)
                    ->update([
                        'telegram_welcome_banner_file_id' => $fileId,
                        'updated_at' => now(),
                    ]);
            }

            return $this->photoResult($response, $fileId, $version);
        });
    }

    /**
     * @param  array<string, mixed>  $keyboard
     * @param  array<string, mixed>  $options
     */
    private function sendFallbackBanner(
        string $chatId,
        string $caption,
        array $keyboard,
        ?string $preferredReference,
        array $options,
    ): TelegramWelcomeBannerSend {
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

    private function activeSetting(): ?StorefrontSetting
    {
        $setting = StorefrontSetting::query()
            ->where('singleton_key', StorefrontSetting::SINGLETON_KEY)
            ->first();
        $path = trim((string) $setting?->telegram_welcome_banner_path);

        if ($setting === null
            || $path === ''
            || ! str_starts_with($path, 'telegram/welcome-banners/')
            || str_contains($path, '..')
            || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        return $setting;
    }

    private function activeReference(
        StorefrontSetting $active,
        ?string $preferredReference,
        ?int $preferredBannerVersion,
    ): ?string {
        $cached = trim((string) $active->telegram_welcome_banner_file_id);
        if ($cached !== '') {
            return $cached;
        }

        $version = (int) $active->telegram_welcome_banner_version;
        if ($preferredBannerVersion !== null
            && $preferredBannerVersion === $version
            && is_string($preferredReference)
            && trim($preferredReference) !== '') {
            return trim($preferredReference);
        }

        return null;
    }

    private function uploadLockKey(StorefrontSetting $active): string
    {
        return 'telegram:welcome-banner:upload:'.$active->getKey().':'.(int) $active->telegram_welcome_banner_version;
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
    private function textResult(array $response, ?int $bannerVersion = null): TelegramWelcomeBannerSend
    {
        return new TelegramWelcomeBannerSend(
            (int) $this->responseValidator->messageIdFromResponse($response, 'Telegram welcome banner'),
            TelegramNavigationMediaKind::Text,
            null,
            $bannerVersion,
        );
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function photoResult(
        array $response,
        ?string $usedReference,
        ?int $bannerVersion = null,
    ): TelegramWelcomeBannerSend {
        return new TelegramWelcomeBannerSend(
            (int) $this->responseValidator->messageIdFromResponse($response, 'Telegram welcome banner'),
            TelegramNavigationMediaKind::Photo,
            $this->photoFileId(data_get($response, 'result.photo')) ?? $usedReference,
            $bannerVersion,
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
