<?php

namespace App\Services\UsdtFunding;

use App\Models\CustomerUsdtFundingPolicy;
use App\Services\UsdtBalance\UsdtMicroAmount;
use InvalidArgumentException;

/**
 * Validated Admin input for the Customer USDT funding policy. There is no
 * business minimum/maximum/aggregate cap field: only the Owner activation
 * flag, a bounded confirmation TTL, a nonnegative exact application fee, the
 * presentation-only USD preset list and the customer-facing terms body. Fees
 * and presets are either integer micro-USDT or a canonical USDT decimal
 * string; zero is the H0-v1 fee default, an omitted preset list falls back to
 * the seeded default list, and neither input ever caps a top-up.
 */
final readonly class CustomerUsdtFundingPolicyDefinition
{
    /** @param list<int> $presetAmountsMicro */
    private function __construct(
        public bool $fundingEnabled,
        public int $confirmationTtlSeconds,
        public int $applicationFeeMicro,
        public array $presetAmountsMicro,
        public string $termsBody,
    ) {}

    public static function from(
        mixed $fundingEnabled,
        mixed $confirmationTtlSeconds,
        mixed $applicationFeeMicro,
        mixed $termsBody,
        mixed $presetAmounts = null,
    ): self {
        $errors = self::validationErrors($fundingEnabled, $confirmationTtlSeconds, $applicationFeeMicro, $termsBody, $presetAmounts);
        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', array_values($errors)));
        }

        return new self(
            fundingEnabled: filter_var($fundingEnabled, FILTER_VALIDATE_BOOLEAN),
            confirmationTtlSeconds: (int) self::parseTtlSeconds($confirmationTtlSeconds),
            applicationFeeMicro: (int) self::parseFeeMicro($applicationFeeMicro),
            presetAmountsMicro: self::parsePresetAmounts($presetAmounts),
            termsBody: (string) self::normalizeTermsBody($termsBody),
        );
    }

    /** @return array<string, string> */
    public static function validationErrors(
        mixed $fundingEnabled,
        mixed $confirmationTtlSeconds,
        mixed $applicationFeeMicro,
        mixed $termsBody,
        mixed $presetAmounts = null,
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

        if ($presetAmounts !== null && ! is_array($presetAmounts)) {
            $errors['preset_amounts'] = 'Daftar preset top-up USDT harus berupa daftar nominal.';
        } elseif (is_array($presetAmounts)) {
            if (! array_is_list($presetAmounts)) {
                $errors['preset_amounts'] = 'Daftar preset top-up USDT harus berupa daftar nominal.';
            } elseif (count($presetAmounts) < 1) {
                $errors['preset_amounts'] = 'Daftar preset top-up USDT harus memiliki sedikitnya satu nominal.';
            } elseif (count($presetAmounts) > CustomerUsdtFundingPolicy::MAX_PRESETS) {
                $errors['preset_amounts'] = 'Daftar preset top-up USDT maksimal '
                    .CustomerUsdtFundingPolicy::MAX_PRESETS.' nominal.';
            } else {
                foreach ($presetAmounts as $index => $amount) {
                    if (self::parsePresetMicro($amount) === null) {
                        $message = 'Setiap preset top-up USDT harus nominal USDT positif maksimal enam desimal tanpa pembulatan.';
                        $errors['preset_amounts.'.$index] = $message;
                        $errors['preset_amounts'] = $message;
                    }
                }
            }
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

    /**
     * Canonical preset list: integer micro-USDT input, or USD decimal strings
     * exactly like the fee input, from one up to MAX_PRESETS entries and each
     * amount between 1 micro-USDT and the representational ceiling.
     * Duplicates are allowed and de-duplicated in list order. A null list
     * means "not provided" and yields the seeded default list.
     *
     * @return list<int>
     */
    public static function parsePresetAmounts(mixed $value): array
    {
        $candidates = $value ?? CustomerUsdtFundingPolicy::DEFAULT_PRESET_AMOUNTS_MICRO;

        if (! is_array($candidates) || ! array_is_list($candidates)
            || count($candidates) < 1 || count($candidates) > CustomerUsdtFundingPolicy::MAX_PRESETS) {
            return [];
        }

        $presets = [];
        foreach ($candidates as $amount) {
            $micro = self::parsePresetMicro($amount);
            if ($micro === null) {
                return [];
            }

            if (! in_array($micro, $presets, true)) {
                $presets[] = $micro;
            }
        }

        return $presets;
    }

    /**
     * A single preset: a positive integer micro-USDT amount (>= 1), or a
     * canonical USDT decimal string. Integer input is micro-USDT, string
     * input is USD. Never floats or rounded values.
     */
    public static function parsePresetMicro(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 1 && $value <= CustomerUsdtFundingPolicy::MAX_PRESET_AMOUNT_MICRO ? $value : null;
        }

        if (! is_string($value)) {
            return null;
        }

        try {
            $micro = UsdtMicroAmount::fromDecimalString(trim($value))->units();
        } catch (InvalidArgumentException) {
            return null;
        }

        return $micro <= CustomerUsdtFundingPolicy::MAX_PRESET_AMOUNT_MICRO ? $micro : null;
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
