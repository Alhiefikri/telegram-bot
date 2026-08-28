<?php

namespace App\Services\Admin;

final readonly class BscReceiverPendingMutation
{
    public const ACTION_STORE = 'store';

    public const ACTION_VERIFY = 'verify';

    public const ACTION_CHALLENGE = 'challenge';

    public const ACTION_MANUAL_VERIFY = 'manual_verify';

    public const ACTION_ACTIVATE = 'activate';

    public const ACTION_DEACTIVATE = 'deactivate';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $action,
        public int $actorId,
        public array $payload,
        public ?string $ip,
    ) {}

    /**
     * @return list<string>
     */
    public static function actions(): array
    {
        return [
            self::ACTION_STORE,
            self::ACTION_VERIFY,
            self::ACTION_CHALLENGE,
            self::ACTION_MANUAL_VERIFY,
            self::ACTION_ACTIVATE,
            self::ACTION_DEACTIVATE,
        ];
    }
}
