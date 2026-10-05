<?php

namespace App\Services\UsdtFunding;

use App\Models\CustomerUsdtFundingPolicy;
use App\Services\UsdtBalance\UsdtMicroAmount;
use InvalidArgumentException;

/**
 * Validated Admin input for the Customer USDT funding policy. There is no
 * business minimum/maximum/preset/cap field: only the Owner activation flag,
 * a bounded confirmation TTL, a nonnegative exact application fee and the
 * customer-facing terms body. The fee input is either integer micro-USDT or a
 * canonical USDT decimal string; zero is the H0-v1 default.
 */
final readonly class CustomerUsdtFundingPolicyDefinition
{
    private function __construct(
        public bool $fundingEnabled,
        public int $confirmationTtlSeconds,
        public int $applicationFeeMicro,
        public string $termsBody,
    ) {}

    public static function from(
        mixed $fundingEnabled,
        mixed $confirmationTtlSeconds,
        mixed $applicationFeeMicro,
        mixed $termsBody,
    ): self {
        $errors = self::validationErrors($fundingEnabled, $confirmationTtlSeconds, $applicationFeeMicro, $termsBody);
        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', array_values($errors)));
        }

        return new self(
            fundingEnabled: filter_var($fundingEnabled, FILTER_VALIDATE_BOOLEAN),
            confirmationTtlSeconds: (int) self::parseTtlSeconds($confirmationTtlSeconds),
            applicationFeeMicro: (int) self::parseFeeMicro($applicationFeeMicro),
            termsBody: (string) self::normalizeTermsBody($termsBody),
        );
    }

    /** @return array<string, string> */
    public static function validationErrors(
        mixed $fundingEnabled,
        mixed $confirmationTtlSeconds,
        mixed $applicationFeeMicro,
        mixed $termsBody,
    ): array {
        $errors = [];

        if (! is_bool($fundingEnabled) && filter_var($fundingEnabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === null) {
            $errors['funding_enabled'] = 'Status top-up USDT tidak valid.';
        }

        $ttl = self::parseTtlSeconds($confirmationTtlSeconds);
        if ($ttl === null) {
            $errors['confirmation_ttl_seconds'] = 'Masa konfirmasi harus antara '
                .CustomerUsdtFundingPolicy::MIN_CONFIRMATION_TTL_SECONDS.' dan '
                .CustomerUsdtFundingPolicy::MAX_CONFIRMATION_TTL_SECONDS.' detik.';
        }

        if (self::parseFeeMicro($applicationFeeMicro) === null) {
            $errors['application_fee_micro'] = 'Fee aplikasi harus nol atau nominal USDT positif maksimal enam desimal tanpa pembulatan.';
        }

        if (self::normalizeTermsBody($termsBody) === null) {
            $errors['terms_body'] = 'Syarat top-up USDT wajib diisi dan maksimal '
                .CustomerUsdtFundingPolicy::MAX_TERMS_BODY_LENGTH.' karakter tanpa karakter kontrol.';
        }

        return $errors;
    }

    public static function parseTtlSeconds(mixed $value): ?int
    {
        if (is_int($value)) {
            $raw = (string) $value;
        } elseif (is_string($value)) {
            $raw = trim($value);
        } else {
            return null;
        }

        if (preg_match('/\A[1-9][0-9]{0,5}\z/D', $raw) !== 1) {
            return null;
        }

        $seconds = (int) $raw;

        return $seconds >= CustomerUsdtFundingPolicy::MIN_CONFIRMATION_TTL_SECONDS
            && $seconds <= CustomerUsdtFundingPolicy::MAX_CONFIRMATION_TTL_SECONDS
                ? $seconds
                : null;
    }

    /**
     * Zero, an integer micro-USDT amount, or a canonical USDT decimal string
     * (at most six fractional digits). Never floats or rounded values.
     */
    public static function parseFeeMicro(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $raw = trim($value);
        if (preg_match('/\A0+(?:\.0+)?\z/', $raw) === 1) {
            return 0;
        }

        try {
            return UsdtMicroAmount::fromDecimalString($raw)->units();
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function normalizeTermsBody(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $body = trim($value);
        if ($body === '' || strlen($body) > CustomerUsdtFundingPolicy::MAX_TERMS_BODY_LENGTH) {
            return null;
        }

        // Control characters (except tab/newline/carriage return) are rejected
        // so stored terms stay safe for every customer surface.
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $body) === 1) {
            return null;
        }

        return $body;
    }
}
