<?php

namespace App\Services\Sakurupiah;

use InvalidArgumentException;

final readonly class SakurupiahCreateInvoice
{
    public const PAYMENT_METHOD_QRIS = 'QRIS';

    public const METHOD_QRISC = 'QRISC';

    public SakurupiahAmount $amount;

    public string $method;

    public string $phone;

    public int $merchantFee;

    public string $merchantRef;

    public string $callbackUrl;

    public string $returnUrl;

    public ?string $name;

    public ?string $email;

    public ?int $expired;

    public function __construct(
        string $method,
        string $phone,
        SakurupiahAmount|int|string $amount,
        int $merchantFee,
        string $merchantRef,
        string $callbackUrl,
        string $returnUrl,
        ?string $name = null,
        ?string $email = null,
        ?int $expired = null,
    ) {
        $this->method = self::requiredText($method, 'method', 64);
        $this->phone = self::requiredText($phone, 'phone', 32);
        if ($this->method !== self::METHOD_QRISC) {
            throw new InvalidArgumentException('Sakurupiah invoice method must be QRISC.');
        }
        $this->amount = $amount instanceof SakurupiahAmount
            ? $amount
            : SakurupiahAmount::from($amount);
        if ($merchantFee !== 1 && $merchantFee !== 2) {
            throw new InvalidArgumentException('Sakurupiah merchant fee must be 1 or 2.');
        }

        $this->merchantFee = $merchantFee;
        $this->merchantRef = self::requiredText($merchantRef, 'merchant reference', 255);
        $this->callbackUrl = self::httpsUrl($callbackUrl, 'callback URL');
        $this->returnUrl = self::httpsUrl($returnUrl, 'return URL');
        $this->name = $name === null ? null : self::optionalText($name, 'name', 255);
        $this->email = $email === null ? null : self::optionalText($email, 'email', 320);
        if ($expired !== null && $expired < 1) {
            throw new InvalidArgumentException('Sakurupiah invoice expiry must be a positive hour count.');
        }

        $this->expired = $expired;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        return new self(
            method: $attributes['method'] ?? throw new InvalidArgumentException('Sakurupiah invoice method is required.'),
            phone: $attributes['phone'] ?? throw new InvalidArgumentException('Sakurupiah invoice phone is required.'),
            amount: $attributes['amount'] ?? throw new InvalidArgumentException('Sakurupiah invoice amount is required.'),
            merchantFee: $attributes['merchant_fee'] ?? throw new InvalidArgumentException('Sakurupiah invoice merchant fee is required.'),
            merchantRef: $attributes['merchant_ref'] ?? throw new InvalidArgumentException('Sakurupiah invoice merchant reference is required.'),
            callbackUrl: $attributes['callback_url'] ?? throw new InvalidArgumentException('Sakurupiah invoice callback URL is required.'),
            returnUrl: $attributes['return_url'] ?? throw new InvalidArgumentException('Sakurupiah invoice return URL is required.'),
            name: $attributes['name'] ?? null,
            email: $attributes['email'] ?? null,
            expired: $attributes['expired'] ?? null,
        );
    }

    /**
     * @return array<string, int|string>
     */
    public function toProviderPayload(string $apiId, string $signature): array
    {
        if ($apiId === '' || trim($apiId) !== $apiId) {
            throw new InvalidArgumentException('Sakurupiah API ID is required.');
        }

        if ($signature === '' || trim($signature) !== $signature) {
            throw new InvalidArgumentException('Sakurupiah invoice signature is required.');
        }

        $payload = [
            'api_id' => $apiId,
            'method' => $this->method,
            'phone' => $this->phone,
            'amount' => $this->amount->submitted(),
            'merchant_fee' => $this->merchantFee,
            'merchant_ref' => $this->merchantRef,
            'callback_url' => $this->callbackUrl,
            'return_url' => $this->returnUrl,
            'signature' => $signature,
        ];

        if ($this->name !== null) {
            $payload['name'] = $this->name;
        }
        if ($this->email !== null) {
            $payload['email'] = $this->email;
        }
        if ($this->expired !== null) {
            $payload['expired'] = $this->expired;
        }

        return $payload;
    }

    public function amountValue(): int
    {
        return $this->amount->value();
    }

    public function amountSubmitted(): string
    {
        return $this->amount->submitted();
    }

    private static function requiredText(string $value, string $label, int $maxLength): string
    {
        if ($value === '' || trim($value) !== $value || strlen($value) > $maxLength) {
            throw new InvalidArgumentException('Sakurupiah '.$label.' is invalid.');
        }

        return $value;
    }

    private static function optionalText(string $value, string $label, int $maxLength): ?string
    {
        if ($value === '') {
            return null;
        }

        return self::requiredText($value, $label, $maxLength);
    }

    private static function httpsUrl(string $value, string $label): string
    {
        self::requiredText($value, $label, 2048);
        $parts = parse_url($value);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('fragment', $parts)
        ) {
            throw new InvalidArgumentException('Sakurupiah '.$label.' must be a safe HTTPS URL.');
        }

        return $value;
    }
}
