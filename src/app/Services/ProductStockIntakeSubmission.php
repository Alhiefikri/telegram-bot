<?php

namespace App\Services;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The only input object accepted by ProductStockIntake.
 *
 * The object deliberately has no string conversion or general-purpose
 * serialization method: structured items contain credential plaintext and
 * must remain request-memory-only until ProductStockIntake encrypts them.
 */
final readonly class ProductStockIntakeSubmission
{
    public const MODE_LEGACY = 'legacy';

    public const MODE_BULK_MFA_SECRET = 'bulk_mfa_secret';

    public const MODE_BULK_ACCESS_TOKEN = 'bulk_access_token';

    public const MODE_STRUCTURED_ACCOUNT = 'structured_account';

    public const BULK_FORMAT_LEGACY = 'legacy';

    public const BULK_FORMAT_MFA_SECRET = 'email_password_mfa_secret';

    public const BULK_FORMAT_ACCESS_TOKEN = 'email_password_access_token';

    /**
     * @param  list<string|array<string, mixed>>  $items
     */
    private function __construct(
        public string $operationKey,
        public string $mode,
        private array $items,
    ) {
        if (! Str::isUuid($this->operationKey)) {
            throw new InvalidArgumentException('Stock intake operation key must be a UUID.');
        }

        if (! in_array($this->mode, [
            self::MODE_LEGACY,
            self::MODE_BULK_MFA_SECRET,
            self::MODE_BULK_ACCESS_TOKEN,
            self::MODE_STRUCTURED_ACCOUNT,
        ], true)) {
            throw new InvalidArgumentException('Stock intake mode is invalid.');
        }
    }

    /**
     * @param  list<string>  $stockItems
     */
    public static function legacy(array $stockItems, ?string $operationKey = null): self
    {
        return new self(
            operationKey: $operationKey ?? (string) Str::uuid(),
            mode: self::MODE_LEGACY,
            items: array_values($stockItems),
        );
    }

    /**
     * Build the selected bulk triplet format from the Quick Input form.
     *
     * @param  list<string>  $stockItems
     */
    public static function bulk(array $stockItems, string $format, ?string $operationKey = null): self
    {
        $mode = match ($format) {
            self::BULK_FORMAT_LEGACY => self::MODE_LEGACY,
            self::BULK_FORMAT_MFA_SECRET, 'email_password_mfa' => self::MODE_BULK_MFA_SECRET,
            self::BULK_FORMAT_ACCESS_TOKEN, 'email_password_auth_token' => self::MODE_BULK_ACCESS_TOKEN,
            default => throw new InvalidArgumentException('Bulk stock format is invalid.'),
        };

        return new self(
            operationKey: $operationKey ?? (string) Str::uuid(),
            mode: $mode,
            items: array_values($stockItems),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $accounts
     */
    public static function structured(array $accounts, ?string $operationKey = null): self
    {
        return new self(
            operationKey: $operationKey ?? (string) Str::uuid(),
            mode: self::MODE_STRUCTURED_ACCOUNT,
            items: array_values($accounts),
        );
    }

    /**
     * Alias for callers that use the domain term Account Input.
     *
     * @param  list<array<string, mixed>>  $accounts
     */
    public static function accountInput(array $accounts, ?string $operationKey = null): self
    {
        return self::structured($accounts, $operationKey);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        if (isset($input['stock_format'])) {
            $items = $input['items'] ?? ($input['stock_items'] ?? []);
            if (! is_array($items)) {
                throw new InvalidArgumentException('Stock intake items are invalid.');
            }

            return self::bulk(
                $items,
                (string) $input['stock_format'],
                isset($input['operation_key']) ? (string) $input['operation_key'] : null,
            );
        }

        $mode = (string) ($input['mode'] ?? (array_key_exists('accounts', $input)
            ? self::MODE_STRUCTURED_ACCOUNT
            : self::MODE_LEGACY));
        $operationKey = isset($input['operation_key']) ? (string) $input['operation_key'] : null;
        $items = $input['items'] ?? ($input['accounts'] ?? ($input['stock_items'] ?? []));

        if (! is_array($items)) {
            throw new InvalidArgumentException('Stock intake items are invalid.');
        }

        return match ($mode) {
            self::MODE_LEGACY => self::legacy($items, $operationKey),
            self::MODE_BULK_MFA_SECRET => self::bulk($items, self::BULK_FORMAT_MFA_SECRET, $operationKey),
            self::MODE_BULK_ACCESS_TOKEN => self::bulk($items, self::BULK_FORMAT_ACCESS_TOKEN, $operationKey),
            self::MODE_STRUCTURED_ACCOUNT => self::structured($items, $operationKey),
            default => throw new InvalidArgumentException('Stock intake mode is invalid.'),
        };
    }

    public function isLegacy(): bool
    {
        return in_array($this->mode, [self::MODE_LEGACY, self::MODE_BULK_MFA_SECRET], true);
    }

    public function isBulkMfaSecret(): bool
    {
        return $this->mode === self::MODE_BULK_MFA_SECRET;
    }

    public function isBulkAccessToken(): bool
    {
        return $this->mode === self::MODE_BULK_ACCESS_TOKEN;
    }

    public function usesStructuredCredentials(): bool
    {
        return in_array($this->mode, [self::MODE_BULK_ACCESS_TOKEN, self::MODE_STRUCTURED_ACCOUNT], true);
    }

    /**
     * Credential-bearing input is exposed only to the intake service. The
     * property itself stays private so accidental json_encode/logging of the
     * submission cannot serialize the draft values.
     *
     * @return list<string|array<string, mixed>>
     */
    public function items(): array
    {
        return $this->items;
    }

    public function isStructuredAccount(): bool
    {
        return $this->mode === self::MODE_STRUCTURED_ACCOUNT;
    }
}
