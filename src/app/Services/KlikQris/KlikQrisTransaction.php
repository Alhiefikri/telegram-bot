<?php

namespace App\Services\KlikQris;

use App\Models\Payment;
use Carbon\CarbonImmutable;
use RuntimeException;
use UnexpectedValueException;

final readonly class KlikQrisTransaction
{
    private const STATUSES = ['PENDING', 'SUCCESS', 'EXPIRED'];

    /**
     * @param  array<mixed>  $payload
     * @param  array<mixed>  $sanitizedPayload
     */
    private function __construct(
        private string $orderId,
        private KlikQrisAmount $amount,
        private KlikQrisAmount $totalAmount,
        private string $status,
        private ?string $qrisUrl,
        private ?string $qrisImageBase64,
        private ?string $signature,
        private ?CarbonImmutable $expiresAt,
        private ?CarbonImmutable $paidAt,
        private array $sanitizedPayload,
    ) {}

    /**
     * @param  array<mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        return self::fromCreatePayload($payload);
    }

    /**
     * @param  array<mixed>  $payload
     */
    public static function fromCreatePayload(array $payload): self
    {
        return self::fromPayloadForContext($payload, requirePendingQrisMedia: true);
    }

    /**
     * @param  array<mixed>  $payload
     */
    public static function fromStatusPayload(array $payload): self
    {
        return self::fromPayloadForContext($payload, requirePendingQrisMedia: false);
    }

    public static function fromPersistedPayment(Payment $payment): self
    {
        if (strtolower(trim((string) $payment->provider)) !== 'klikqris') {
            throw new RuntimeException('Persisted KlikQRIS transaction provider mismatch.');
        }

        if (! is_array($payment->provider_payload)) {
            throw new RuntimeException('Persisted KlikQRIS transaction payload is missing.');
        }

        $payload = $payment->provider_payload;
        $data = $payload['data'] ?? $payload;
        if (! is_array($data)) {
            throw new RuntimeException('Persisted KlikQRIS transaction payload is invalid.');
        }

        foreach (['order_id', 'amount', 'total_amount', 'status', 'qris_url', 'expired_at'] as $key) {
            if (! array_key_exists($key, $data)) {
                throw new RuntimeException('Persisted KlikQRIS transaction payload is incomplete.');
            }
        }

        if (! filled($payment->provider_transaction_token)) {
            throw new RuntimeException('Persisted KlikQRIS transaction token is missing.');
        }

        $payload['data'] = array_replace($data, ['signature' => $payment->provider_transaction_token]);

        try {
            $transaction = self::fromStatusPayload($payload);
            $transaction->assertMatches($payment);

            if (! self::isAllowedSandboxQrisUrl($transaction->qrisUrl())) {
                throw new RuntimeException('Persisted KlikQRIS transaction QRIS URL is not trusted.');
            }

            if ($payment->provider_expires_at === null || $transaction->expiresAt() === null || ! $transaction->expiresAt()->equalTo($payment->provider_expires_at)) {
                throw new RuntimeException('Persisted KlikQRIS transaction expiry is inconsistent.');
            }

            if (! $payment->isPayableNativeKlikQris()) {
                throw new RuntimeException('Persisted KlikQRIS transaction is not payable.');
            }

            return $transaction;
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (UnexpectedValueException $exception) {
            throw new RuntimeException('Persisted KlikQRIS transaction is inconsistent.', previous: $exception);
        }
    }

    public static function isAllowedSandboxQrisUrl(?string $url): bool
    {
        if (! is_string($url) || trim($url) === '') {
            return false;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }

        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'klikqris.com'
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('port', $parts)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
        ) {
            return false;
        }

        $path = (string) ($parts['path'] ?? '');
        $decodedPath = rawurldecode($path);

        return $path === $decodedPath
            && preg_match('/^\/storage\/sandbox\/(?:qris_[A-Za-z0-9._~-]+|sandbox_sample)\.png$/', $path) === 1
            && ! str_contains($decodedPath, '/../')
            && ! str_contains($decodedPath, '/./');
    }

    /**
     * @param  array<mixed>  $payload
     */
    private static function fromPayloadForContext(array $payload, bool $requirePendingQrisMedia): self
    {
        $data = self::payloadData($payload);
        $orderId = self::requiredString($data, 'order_id', 'KlikQRIS order_id is required.');
        $amount = KlikQrisAmount::from($data['amount'] ?? null);
        $totalAmount = KlikQrisAmount::from($data['total_amount'] ?? null);
        $status = strtoupper(self::requiredString($data, 'status', 'KlikQRIS status is required.'));

        if (! in_array($status, self::STATUSES, true)) {
            throw new UnexpectedValueException('KlikQRIS status is unsupported.');
        }

        $qrisUrl = self::optionalString($data['qris_url'] ?? null);
        $qrisImage = self::optionalString($data['qris_image'] ?? null);
        if ($requirePendingQrisMedia && $status === 'PENDING' && $qrisUrl === null && $qrisImage === null) {
            throw new UnexpectedValueException('KlikQRIS pending transaction requires qris_url or qris_image.');
        }

        return new self(
            $orderId,
            $amount,
            $totalAmount,
            $status,
            $qrisUrl,
            $qrisImage,
            self::optionalString($data['signature'] ?? null),
            self::optionalDate($data, 'expired_at'),
            self::optionalDate($data, 'paid_at'),
            KlikQrisPayloadSanitizer::sanitize($payload),
        );
    }

    public function orderId(): string
    {
        return $this->orderId;
    }

    public function amount(): KlikQrisAmount
    {
        return $this->amount;
    }

    public function totalAmount(): int
    {
        return $this->totalAmount->rupiah();
    }

    public function totalAmountDisplay(): string
    {
        return $this->totalAmount->display();
    }

    public function status(): string
    {
        return $this->status;
    }

    public function qrisUrl(): ?string
    {
        return $this->qrisUrl;
    }

    public function qrisImageBase64(): ?string
    {
        return $this->qrisImageBase64;
    }

    public function signature(): ?string
    {
        return $this->signature;
    }

    public function expiresAt(): ?CarbonImmutable
    {
        return $this->expiresAt;
    }

    public function paidAt(): ?CarbonImmutable
    {
        return $this->paidAt;
    }

    /**
     * @return array<mixed>
     */
    public function sanitizedPayload(): array
    {
        return $this->sanitizedPayload;
    }

    public function isPending(): bool
    {
        return $this->status === 'PENDING';
    }

    public function isSuccess(): bool
    {
        return $this->status === 'SUCCESS';
    }

    public function assertMatches(Payment $payment): void
    {
        if (strtolower(trim((string) $payment->provider)) !== 'klikqris') {
            throw new UnexpectedValueException('KlikQRIS provider mismatch.');
        }

        if (trim((string) $payment->merchant_ref) !== $this->orderId) {
            throw new UnexpectedValueException('KlikQRIS order_id mismatch.');
        }

        if ((int) $payment->amount !== $this->amount->rupiah()) {
            throw new UnexpectedValueException('KlikQRIS amount mismatch.');
        }

        if ($payment->provider_total_payment !== null && (int) $payment->provider_total_payment !== $this->totalAmount()) {
            throw new UnexpectedValueException('KlikQRIS total_amount mismatch.');
        }
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    private static function payloadData(array $payload): array
    {
        $data = $payload['data'] ?? $payload;
        if (! is_array($data)) {
            throw new UnexpectedValueException('KlikQRIS payload data is required.');
        }

        return $data;
    }

    /** @param array<mixed> $data */
    private static function requiredString(array $data, string $key, string $message): string
    {
        $value = self::optionalString($data[$key] ?? null);
        if ($value === null) {
            throw new UnexpectedValueException($message);
        }

        return $value;
    }

    private static function optionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    /** @param array<mixed> $data */
    private static function optionalDate(array $data, string $key): ?CarbonImmutable
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        $string = self::optionalString($data[$key]);
        if ($string === null) {
            throw new UnexpectedValueException('KlikQRIS timestamp is invalid.');
        }

        try {
            return CarbonImmutable::parse($string, 'Asia/Jakarta');
        } catch (\Throwable) {
            throw new UnexpectedValueException('KlikQRIS timestamp is invalid.');
        }
    }
}
