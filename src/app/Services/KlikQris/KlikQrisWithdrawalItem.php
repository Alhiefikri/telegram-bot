<?php

namespace App\Services\KlikQris;

use App\Enums\KlikQrisEnvironment;
use Carbon\CarbonImmutable;
use UnexpectedValueException;

final readonly class KlikQrisWithdrawalItem
{
    private function __construct(
        private string $orderId,
        private KlikQrisAmount $originalAmount,
        private KlikQrisAmount $amountPaidUniq,
        private KlikQrisAmount $mdr,
        private KlikQrisAmount $netAmountSettled,
        private string $withdrawalStatus,
        private ?CarbonImmutable $transactionPaidAt,
        private ?CarbonImmutable $withdrawalRequestedAt,
        private ?CarbonImmutable $withdrawalProcessedAt,
        private ?string $withdrawalCode,
        private ?string $withdrawalProofUrl,
    ) {}

    /**
     * @param  array<mixed>  $payload
     */
    public static function fromPayload(array $payload, KlikQrisEnvironment $environment): self
    {
        return new self(
            orderId: self::requiredString($payload, 'order_id'),
            originalAmount: KlikQrisAmount::from($payload['original_amount'] ?? null),
            amountPaidUniq: KlikQrisAmount::from($payload['amount_paid_uniq'] ?? null),
            mdr: KlikQrisAmount::from($payload['mdr'] ?? null),
            netAmountSettled: KlikQrisAmount::from($payload['net_amount_settled'] ?? null),
            withdrawalStatus: self::requiredStatus($payload, 'withdrawal_status'),
            transactionPaidAt: self::requiredNullableTimestamp($payload, 'transaction_paid_at'),
            withdrawalRequestedAt: self::requiredNullableTimestamp($payload, 'withdrawal_requested_at'),
            withdrawalProcessedAt: self::requiredNullableTimestamp($payload, 'withdrawal_processed_at'),
            withdrawalCode: self::optionalString($payload['withdrawal_code'] ?? null),
            withdrawalProofUrl: self::safeProofUrl($payload['withdrawal_proof_url'] ?? null, $environment),
        );
    }

    public function orderId(): string
    {
        return $this->orderId;
    }

    public function originalAmount(): int
    {
        return $this->originalAmount->rupiah();
    }

    public function originalAmountDisplay(): string
    {
        return $this->originalAmount->display();
    }

    public function amountPaidUniq(): int
    {
        return $this->amountPaidUniq->rupiah();
    }

    public function amountPaidUniqDisplay(): string
    {
        return $this->amountPaidUniq->display();
    }

    public function mdr(): int
    {
        return $this->mdr->rupiah();
    }

    public function mdrDisplay(): string
    {
        return $this->mdr->display();
    }

    public function netAmountSettled(): int
    {
        return $this->netAmountSettled->rupiah();
    }

    public function netAmountSettledDisplay(): string
    {
        return $this->netAmountSettled->display();
    }

    public function withdrawalStatus(): string
    {
        return $this->withdrawalStatus;
    }

    public function transactionPaidAt(): ?CarbonImmutable
    {
        return $this->transactionPaidAt;
    }

    public function withdrawalRequestedAt(): ?CarbonImmutable
    {
        return $this->withdrawalRequestedAt;
    }

    public function withdrawalProcessedAt(): ?CarbonImmutable
    {
        return $this->withdrawalProcessedAt;
    }

    public function withdrawalCode(): ?string
    {
        return $this->withdrawalCode;
    }

    public function withdrawalProofUrl(): ?string
    {
        return $this->withdrawalProofUrl;
    }

    /** @param array<mixed> $payload */
    private static function requiredString(array $payload, string $key): string
    {
        $value = self::optionalString($payload[$key] ?? null);
        if ($value === null) {
            throw new UnexpectedValueException('KlikQRIS withdrawal field is missing.');
        }

        return $value;
    }

    /** @param array<mixed> $payload */
    private static function requiredStatus(array $payload, string $key): string
    {
        return strtoupper(self::requiredString($payload, $key));
    }

    /** @param array<mixed> $payload */
    private static function requiredNullableTimestamp(array $payload, string $key): ?CarbonImmutable
    {
        if (! array_key_exists($key, $payload)) {
            throw new UnexpectedValueException('KlikQRIS withdrawal timestamp is missing.');
        }

        return KlikQrisTimestamp::nullable($payload[$key]);
    }

    private static function optionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_scalar($value)) {
            throw new UnexpectedValueException('KlikQRIS withdrawal field is invalid.');
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private static function safeProofUrl(mixed $value, KlikQrisEnvironment $environment): ?string
    {
        $url = self::optionalString($value);
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('port', $parts)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
        ) {
            return null;
        }

        $allowedHosts = array_values(array_filter(array_map(
            static fn (mixed $host): string => strtolower(trim((string) $host)),
            (array) config($environment->configKey('allowed_hosts'), []),
        )));
        if ($allowedHosts === []) {
            $baseHost = parse_url((string) config($environment->configKey('base_url')), PHP_URL_HOST);
            if (is_string($baseHost) && $baseHost !== '') {
                $allowedHosts[] = strtolower($baseHost);
            }
        }

        return in_array(strtolower((string) $parts['host']), $allowedHosts, true) ? $url : null;
    }
}
