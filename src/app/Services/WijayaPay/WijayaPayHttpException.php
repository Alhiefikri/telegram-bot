<?php

namespace App\Services\WijayaPay;

final class WijayaPayHttpException extends WijayaPayProviderException
{
    public function __construct(
        string $operation,
        public readonly int $status,
        bool $outcomeUnknown = false,
    ) {
        parent::__construct(
            'WijayaPay '.$operation.' request failed with HTTP '.$status.'.',
            operation: $operation,
            httpStatus: $status,
            outcomeUnknown: $outcomeUnknown,
            retryable: self::isRetryableStatus($status),
            reason: 'http_'.$status,
        );
    }

    public static function isRetryableStatus(int $status): bool
    {
        return in_array($status, [408, 425, 429, 500, 502, 503, 504], true);
    }
}
