<?php

namespace App\Support;

class DogfoodExternalFixtures
{
    public const MANUAL_PAYMENT_PROOF = 'manual_payment_proof';

    public const STORE_CHANNEL_MEMBER_GATE = 'store_channel_member_gate';

    public const STORE_CHANNEL_SEND = 'store_channel_send';

    public const SALES_PROOF_POST = 'sales_proof_post';


    public const CALLBACK_ANSWER = 'callback_answer';

    public const ADMIN_AUTHENTICATED_SESSION = 'admin_authenticated_session';

    private const LABELS = [
        'admin_email' => 'DOGFOOD_ADMIN_EMAIL',
        'admin_password' => 'DOGFOOD_ADMIN_PASSWORD',
        'telegram_photo_file_id' => 'DOGFOOD_TELEGRAM_PHOTO_FILE_ID',
        'store_channel_chat_id' => 'DOGFOOD_STORE_CHANNEL_CHAT_ID',
        'store_channel_username' => 'DOGFOOD_STORE_CHANNEL_USERNAME',
        'store_channel_link' => 'DOGFOOD_STORE_CHANNEL_LINK',
        'store_channel_member_user_id' => 'DOGFOOD_STORE_CHANNEL_MEMBER_USER_ID',
        'store_channel_non_member_user_id' => 'DOGFOOD_STORE_CHANNEL_NON_MEMBER_USER_ID',
        'telegram_callback_query_id' => 'DOGFOOD_TELEGRAM_CALLBACK_QUERY_ID',
    ];

    public function __construct(private readonly array $values) {}

    public static function fromConfig(): self
    {
        return new self([
            'admin_email' => config('services.dogfood_fixtures.admin_email'),
            'admin_password' => config('services.dogfood_fixtures.admin_password'),
            'telegram_photo_file_id' => config('services.dogfood_fixtures.telegram_photo_file_id'),
            'store_channel_chat_id' => config('services.dogfood_fixtures.store_channel_chat_id'),
            'store_channel_username' => config('services.dogfood_fixtures.store_channel_username'),
            'store_channel_link' => config('services.dogfood_fixtures.store_channel_link'),
            'store_channel_member_user_id' => config('services.dogfood_fixtures.store_channel_member_user_id'),
            'store_channel_non_member_user_id' => config('services.dogfood_fixtures.store_channel_non_member_user_id'),
            'telegram_callback_query_id' => config('services.dogfood_fixtures.telegram_callback_query_id'),
        ]);
    }

    public function missingFor(string $scenario): array
    {
        return match ($scenario) {
            self::MANUAL_PAYMENT_PROOF => $this->missing(['telegram_photo_file_id']),
            self::STORE_CHANNEL_MEMBER_GATE => $this->missing(['store_channel_chat_id', 'store_channel_username', 'store_channel_link', 'store_channel_member_user_id', 'store_channel_non_member_user_id']),
            self::STORE_CHANNEL_SEND, self::SALES_PROOF_POST => $this->missing(['store_channel_chat_id', 'store_channel_username', 'store_channel_link']),
            self::CALLBACK_ANSWER => $this->missing(['telegram_callback_query_id']),
            self::ADMIN_AUTHENTICATED_SESSION => $this->missing(['admin_email', 'admin_password']),
            default => [],
        };
    }

    public function blockedReason(string $scenario): ?string
    {
        $missing = $this->missingFor($scenario);
        if ($missing === []) {
            return null;
        }

        return 'Missing real dogfood fixture(s): '.implode(', ', $missing).'.';
    }

    public function telegramPhotoFileId(): string
    {
        return $this->string('telegram_photo_file_id');
    }

    public function adminEmail(): string
    {
        return $this->string('admin_email');
    }

    public function adminPassword(): string
    {
        return $this->string('admin_password');
    }

    public function storeChannelChatId(): string
    {
        return $this->string('store_channel_chat_id');
    }

    public function storeChannelUsername(): ?string
    {
        $value = $this->string('store_channel_username');

        return $value === '' ? null : $value;
    }

    public function storeChannelLink(): ?string
    {
        $value = $this->string('store_channel_link');

        return $value === '' ? null : $value;
    }

    public function storeChannelMemberUserId(): int
    {
        return (int) $this->string('store_channel_member_user_id');
    }

    public function storeChannelNonMemberUserId(): int
    {
        return (int) $this->string('store_channel_non_member_user_id');
    }

    public function telegramCallbackQueryId(): string
    {
        return $this->string('telegram_callback_query_id');
    }


    public function summary(): array
    {
        return [
            'admin_email' => $this->redactedValue('admin_email'),
            'admin_password_configured' => $this->string('admin_password') !== '',
            'telegram_photo_file_id' => $this->redactedValue('telegram_photo_file_id'),
            'store_channel_chat_id' => $this->redactedValue('store_channel_chat_id'),
            'store_channel_username_configured' => $this->string('store_channel_username') !== '',
            'store_channel_link_configured' => $this->string('store_channel_link') !== '',
            'store_channel_member_user_id' => $this->redactedValue('store_channel_member_user_id'),
            'store_channel_non_member_user_id' => $this->redactedValue('store_channel_non_member_user_id'),
            'telegram_callback_query_id' => $this->redactedValue('telegram_callback_query_id'),
        ];
    }

    private function missing(array $keys): array
    {
        return array_values(array_map(
            fn (string $key): string => self::LABELS[$key] ?? $key,
            array_filter($keys, fn (string $key): bool => ! $this->filled($key)),
        ));
    }

    private function filled(string $key): bool
    {
        $value = $this->values[$key] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        return trim((string) $value) !== '';
    }

    private function string(string $key): string
    {
        return trim((string) ($this->values[$key] ?? ''));
    }

    private function redactedValue(string $key): array
    {
        $value = $this->string($key);

        return [
            'configured' => $value !== '',
            'correlation_id' => $value === '' ? null : DogfoodTiming::correlation($value),
        ];
    }
}
