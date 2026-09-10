<?php

namespace App\Services\KlikQris;

use App\Enums\KlikQrisEnvironment;
use App\Enums\PaymentStatus;
use App\Enums\StoreBalanceTopUpStatus;
use App\Models\Payment;
use App\Models\StoreBalanceTopUp;
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
        private ?int $providerFee,
        private ?string $providerReference,
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

        $encryptedQrisUrl = trim((string) $payment->provider_qris_url);
        if ($encryptedQrisUrl !== '') {
            if (array_key_exists('qris_url', $data)
                && self::optionalString($data['qris_url']) !== $encryptedQrisUrl) {
                throw new RuntimeException('Persisted KlikQRIS transaction QRIS URL does not match its protected value.');
            }

            if (array_key_exists('data', $payload)) {
                $payload['data']['qris_url'] = $encryptedQrisUrl;
            } else {
                $payload['qris_url'] = $encryptedQrisUrl;
            }

            $data = $payload['data'] ?? $payload;
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

    public static function fromPersistedTopUp(StoreBalanceTopUp $topUp, ?KlikQrisEnvironment $environment = null): self
    {
        $environment ??= KlikQrisEnvironment::runtime();
        if (strtolower(trim((string) $topUp->provider)) !== PaymentProvider::KLIKQRIS) {
            throw new RuntimeException('Persisted KlikQRIS top-up provider mismatch.');
        }

        if (! is_array($topUp->provider_payload)) {
            throw new RuntimeException('Persisted KlikQRIS top-up payload is missing.');
        }

        $payload = $topUp->provider_payload;
        $data = $payload['data'] ?? $payload;
        if (! is_array($data)) {
            throw new RuntimeException('Persisted KlikQRIS top-up payload is invalid.');
        }

        $protectedQrisUrl = trim((string) $topUp->provider_qris_url);
        if ($protectedQrisUrl === '') {
            throw new RuntimeException('Persisted KlikQRIS top-up QRIS URL is missing.');
        }

        if (array_key_exists('qris_url', $data)) {
            $payloadQrisUrl = self::optionalString($data['qris_url']);
            if ($payloadQrisUrl !== null && $payloadQrisUrl !== $protectedQrisUrl) {
                throw new RuntimeException('Persisted KlikQRIS top-up QRIS URL does not match its protected value.');
            }
        }

        $data = self::completePersistedTopUpPayload($data, $topUp, $protectedQrisUrl);
        if (array_key_exists('data', $payload)) {
            $payload['data'] = $data;
        } else {
            $payload = $data;
        }

        foreach (['order_id', 'amount', 'total_amount', 'status', 'qris_url', 'expired_at'] as $key) {
            if (! array_key_exists($key, $data)) {
                throw new RuntimeException('Persisted KlikQRIS top-up payload is incomplete.');
            }
        }

        try {
            $transaction = self::fromStatusPayload($payload, $environment);
            $transaction->assertMatchesTopUp($topUp);

            if (! self::isAllowedQrisUrl($transaction->qrisUrl(), environment: $environment)
                || $transaction->expiresAt() === null
                || $topUp->provider_expires_at === null
                || $transaction->expiresAt()->getTimestamp() !== $topUp->provider_expires_at->getTimestamp()
                || $topUp->provider_fee === null
                || $transaction->providerFee() !== (int) $topUp->provider_fee) {
                throw new RuntimeException('Persisted KlikQRIS top-up quote is inconsistent.');
            }

            if ($topUp->status !== StoreBalanceTopUpStatus::Paid && ! $topUp->isPayable()) {
                throw new RuntimeException('Persisted KlikQRIS top-up is not payable.');
            }

            return $transaction;
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (UnexpectedValueException $exception) {
            throw new RuntimeException('Persisted KlikQRIS top-up is inconsistent.', previous: $exception);
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
            self::optionalNonNegativeAmount($data, [
                'fee',
                'provider_fee',
                'fee_amount',
                'qris_fee',
            ]),
            self::firstOptionalString($data, [
                'provider_reference',
                'transaction_id',
                'transaction_ref',
                'reference',
            ]),
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

    public function providerFee(): ?int
    {
        return $this->providerFee;
    }

    public function providerReference(): ?string
    {
        return $this->providerReference;
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

    public function assertMatchesTopUp(StoreBalanceTopUp $topUp): void
    {
        if (strtolower(trim((string) $topUp->provider)) !== PaymentProvider::KLIKQRIS) {
            throw new UnexpectedValueException('KlikQRIS top-up provider mismatch.');
        }

        if (trim((string) $topUp->merchant_ref) !== $this->orderId) {
            throw new UnexpectedValueException('KlikQRIS top-up order_id mismatch.');
        }

        if ((int) $topUp->amount !== $this->amount->rupiah()) {
            throw new UnexpectedValueException('KlikQRIS top-up amount mismatch.');
        }

        if ($topUp->provider_total_payment !== null
            && (int) $topUp->provider_total_payment !== $this->totalAmount()) {
            throw new UnexpectedValueException('KlikQRIS top-up total_amount mismatch.');
        }

        if ($topUp->provider_fee !== null
            && $this->providerFee !== null
            && (int) $topUp->provider_fee !== $this->providerFee) {
            throw new UnexpectedValueException('KlikQRIS top-up fee mismatch.');
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
    private static function optionalNonNegativeAmount(array $data, array $keys): ?int
    {
        $values = [];

        foreach ($keys as $key) {
            if (! array_key_exists($key, $data) || $data[$key] === null) {
                continue;
            }

            $value = $data[$key];
            if (! is_int($value) && ! is_string($value)) {
                throw new UnexpectedValueException('KlikQRIS fee must be a non-negative whole-rupiah value.');
            }

            $raw = trim((string) $value);
            if (preg_match('/^(?:0|[1-9][0-9]*)(?:\\.0+)?$/', $raw) !== 1) {
                throw new UnexpectedValueException('KlikQRIS fee must be a non-negative whole-rupiah value.');
            }

            $values[] = $raw === '0' || str_starts_with($raw, '0.')
                ? 0
                : KlikQrisAmount::from($raw)->rupiah();
        }

        if ($values === []) {
            return null;
        }

        if (count(array_unique($values)) !== 1) {
            throw new UnexpectedValueException('KlikQRIS fee aliases disagree.');
        }

        return $values[0];
    }

    /** @param array<mixed> $data @param list<string> $keys */
    private static function firstOptionalString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = self::optionalString($data[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private static function completePersistedTopUpPayload(
        array $data,
        StoreBalanceTopUp $topUp,
        string $qrisUrl,
    ): array {
        $data['qris_url'] = $qrisUrl;

        if (! self::hasNonNullKey($data, ['fee', 'provider_fee', 'fee_amount', 'qris_fee'])
            && $topUp->provider_fee !== null) {
            $data['provider_fee'] = (int) $topUp->provider_fee;
        }

        if ((! array_key_exists('expired_at', $data) || $data['expired_at'] === null)
            && $topUp->provider_expires_at !== null) {
            $data['expired_at'] = $topUp->provider_expires_at->toISOString();
        }

        return $data;
    }

    /** @param array<mixed> $data @param list<string> $keys */
    private static function hasNonNullKey(array $data, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null) {
                return true;
            }
        }

        return false;
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
