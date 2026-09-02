<?php

namespace App\Services\Sakurupiah;

use Throwable;

final class SakurupiahHttpException extends SakurupiahProviderException
{
    public function __construct(
        string $operation,
        int $status,
        bool $outcomeUnknown = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            'Sakurupiah '.$operation.' request failed with HTTP '.$status.'.',
            reason: 'http',
            httpStatus: $status,
            retryable: ! $outcomeUnknown && self::isRetryableStatus($status),
            outcomeUnknown: $outcomeUnknown,
            operation: $operation,
            previous: $previous,
        );
    }

    public static function isRetryableStatus(int $status): bool
    {
        return $status === 408
            || $status === 429
            || ($status >= 500 && $status <= 599);
    }
}
