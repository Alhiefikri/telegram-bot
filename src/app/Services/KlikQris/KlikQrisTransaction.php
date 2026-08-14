<?php

namespace App\Services\KlikQris;

use App\Enums\KlikQrisEnvironment;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Support\PaymentProvider;
use Carbon\CarbonImmutable;
use RuntimeException;
use UnexpectedValueException;

final readonly class KlikQrisTransaction
{
    private const STATUSES = ['PENDING', 'SUCCESS', 'EXPIRED'];

    /**
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
        private KlikQrisEnvironment $environment,
    ) {}

    /**
     * @param  array<mixed>  $payload
     */
    public static function fromPayload(array $payload, ?KlikQrisEnvironment $environment = null): self
    {
        return self::fromCreatePayload($payload, $environment);
    }

    /**
     * @param  array<mixed>  $payload
     */
    public static function fromCreatePayload(array $payload, ?KlikQrisEnvironment $environment = null): self
    {
        return self::fromPayloadForContext(
            $payload,
            requirePendingQrisMedia: true,
            environment: $environment ?? KlikQrisEnvironment::runtime(),
        );
    }

    /**
     * @param  array<mixed>  $payload
     */
    public static function fromStatusPayload(array $payload, ?KlikQrisEnvironment $environment = null): self
    {
        return self::fromPayloadForContext(
            $payload,
            requirePendingQrisMedia: false,
            environment: $environment ?? KlikQrisEnvironment::runtime(),
        );
    }

    public static function fromPersistedPayment(Payment $payment, ?KlikQrisEnvironment $environment = null): self
    {
        $environment ??= KlikQrisEnvironment::runtime();
        if (strtolower(trim((string) $payment->provider)) !== PaymentProvider::KLIKQRIS) {
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

        try {
            $transaction = self::fromStatusPayload($payload, $environment);
            $transaction->assertMatches($payment);

            if (! self::isAllowedQrisUrl($transaction->qrisUrl(), environment: $environment)) {
                throw new RuntimeException('Persisted KlikQRIS transaction QRIS URL is not trusted.');
            }

            // Payment timestamps use the database timestamp precision. Compare
            // at that precision so valid provider milliseconds do not make the
            // persisted transaction fail closed on the next customer read.
            if ($payment->provider_expires_at === null || $transaction->expiresAt() === null || $transaction->expiresAt()->getTimestamp() !== $payment->provider_expires_at->getTimestamp()) {
                throw new RuntimeException('Persisted KlikQRIS transaction expiry is inconsistent.');
            }

            if ($payment->status !== PaymentStatus::Paid && ! $payment->isPayableNativeKlikQris()) {
                throw new RuntimeException('Persisted KlikQRIS transaction is not payable.');
            }

            return $transaction;
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (UnexpectedValueException $exception) {
            throw new RuntimeException('Persisted KlikQRIS transaction is inconsistent.', previous: $exception);
        }
    }

    public static function isAllowedQrisUrl(?string $url, ?bool $production = null, ?KlikQrisEnvironment $environment = null): bool
    {
        $environment ??= $production === null
            ? KlikQrisEnvironment::runtime()
            : ($production ? KlikQrisEnvironment::Production : KlikQrisEnvironment::Sandbox);
        if (! is_string($url) || trim($url) === '') {
            return false;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }

        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
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
        if ($path === '' || $path !== $decodedPath || str_contains($decodedPath, '/../') || str_contains($decodedPath, '/./') || ! str_ends_with(strtolower($path), '.png')) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($environment === KlikQrisEnvironment::Sandbox) {
            return $host === 'klikqris.com'
                && preg_match('/^\/storage\/sandbox\/(?:qris_[A-Za-z0-9._~-]+|sandbox_sample)\.png$/', $path) === 1;
        }

        $allowedHosts = array_values(array_filter(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            (array) config(KlikQrisEnvironment::Production->configKey('allowed_hosts'), []),
        )));

        return in_array($host, $allowedHosts, true);
    }

    public static function isAllowedSandboxQrisUrl(?string $url): bool
    {
        return self::isAllowedQrisUrl($url, production: false);
    }

    public static function isAllowedProductionQrisUrl(?string $url): bool
    {
        return self::isAllowedQrisUrl($url, production: true);
    }

    /**
     * @param  array<mixed>  $payload
     */
    private static function fromPayloadForContext(
        array $payload,
        bool $requirePendingQrisMedia,
        KlikQrisEnvironment $environment,
    ): self {
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
            $environment,
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

    public function environment(): KlikQrisEnvironment
    {
        return $this->environment;
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
        if (strtolower(trim((string) $payment->provider)) !== PaymentProvider::KLIKQRIS) {
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

        return KlikQrisTimestamp::parse($data[$key]);
    }
}
