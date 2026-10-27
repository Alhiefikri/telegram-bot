<?php

namespace App\Services\KlikQris;

use RuntimeException;
use Throwable;

final class KlikQrisStatusResponseException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public function diagnosticCode(): string
    {
        if ($this->httpStatus !== null) {
            return 'status_http_'.$this->httpStatus;
        }

        return match ($this->getPrevious()?->getMessage()) {
            'KlikQRIS order_id mismatch.' => 'status_order_id_mismatch',
            'KlikQRIS amount mismatch.' => 'status_amount_mismatch',
            'KlikQRIS total_amount mismatch.' => 'status_total_amount_mismatch',
            'KlikQRIS provider mismatch.' => 'status_provider_mismatch',
            'KlikQRIS order_id is required.' => 'status_order_id_missing',
            'KlikQRIS status is required.' => 'status_missing',
            'KlikQRIS status is unsupported.' => 'status_unsupported',
            'KlikQRIS payload data is required.' => 'status_data_missing',
            'KlikQRIS amount must be an integer or decimal string.',
            'KlikQRIS amount must be a positive whole-rupiah value.',
            'KlikQRIS amount exceeds supported integer range.' => 'status_amount_invalid',
            default => 'status_response_invalid',
        };
    }
}
