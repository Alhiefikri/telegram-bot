<?php

namespace App\Services\KlikQris;

use RuntimeException;

final class KlikQrisReportingException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly ?int $status = null,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }

    public static function readiness(): self
    {
        return new self(
            'KlikQRIS reporting is unavailable because the provider is not ready.',
            reason: 'readiness',
        );
    }

    public static function connection(): self
    {
        return new self(
            'KlikQRIS reporting is temporarily unavailable because the provider connection failed.',
            reason: 'connection',
            retryable: true,
        );
    }

    public static function http(int $status): self
    {
        $retryable = $status === 408 || $status === 429 || ($status >= 500 && $status <= 599);

        return new self(
            sprintf('KlikQRIS reporting request failed with HTTP %d.', $status),
            reason: 'http',
            status: $status,
            retryable: $retryable,
        );
    }

    public static function json(): self
    {
        return new self(
            'KlikQRIS reporting returned an invalid JSON response.',
            reason: 'json',
        );
    }

    public static function schema(): self
    {
        return new self(
            'KlikQRIS reporting returned an invalid response schema.',
            reason: 'schema',
        );
    }

    public static function validation(): self
    {
        return new self(
            'KlikQRIS reporting query is invalid.',
            reason: 'validation',
        );
    }

    public static function transport(): self
    {
        return new self(
            'KlikQRIS reporting request failed before a provider response was received.',
            reason: 'transport',
        );
    }
}
