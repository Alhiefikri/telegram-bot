<?php

namespace App\Services\MoneyReceipts;

use App\Enums\ExternalMoneyReceiptClaimOutcome;
use App\Models\ExternalMoneyReceiptReservation;
use App\Models\Payment;
use App\Models\StoreBalanceTopUp;
use InvalidArgumentException;

/**
 * Cutover seam between money owners and the ExternalMoneyReceiptClaims
 * registry. Every external money effect claims its verified receipt here,
 * inside the owner's short database transaction and without HTTP. A claim
 * that cannot proceed is never dropped: Conflict and Review outcomes are
 * recorded as blocking legacy reservations for operator review.
 *
 * Receipt builders derive identities only from persisted, stable columns so
 * the runtime claim and the legacy backfill produce the same canonical key
 * for the same physical money.
 */
final class ExternalMoneyReceiptWriter
{
    public const NAMESPACE_DEFAULT = 'default';

    public const NAMESPACE_OWNER_ATTESTATION = 'owner_attestation';

    public function __construct(
        private readonly ExternalMoneyReceiptClaims $claims,
        private readonly ExternalMoneyReceiptReservations $reservations,
    ) {}

    /**
     * Claim a receipt and denylist held outcomes for review.
     */
    public function claim(
        VerifiedMoneyReceipt $receipt,
        string $source,
        string $legacyType,
        string $legacyId,
    ): ExternalMoneyReceiptClaimOutcome {
        $outcome = $this->claims->claim($receipt);

        if ($outcome->isHeld()) {
            $this->reservations->reserve(
                source: $source,
                legacyType: $legacyType,
                legacyId: $legacyId,
                provider: $receipt->identity->provider(),
                reason: $outcome === ExternalMoneyReceiptClaimOutcome::Conflict
                    ? ExternalMoneyReceiptReservation::REASON_REGISTRY_CONFLICT
                    : ExternalMoneyReceiptReservation::REASON_REGISTRY_REVIEW,
                details: [
                    'canonical_key' => $receipt->canonicalKey(),
                    'target_type' => $receipt->target->type->value,
                    'target_id' => $receipt->target->targetId,
                    'currency' => $receipt->currency,
                    'amount_units' => $receipt->amountUnits,
                ],
            );
        }

        return $outcome;
    }

    public function claimStoreBalanceTopUp(StoreBalanceTopUp $topUp, ?string $reference = null): ExternalMoneyReceiptClaimOutcome
    {
        try {
            $receipt = $this->receiptForStoreBalanceTopUp($topUp, $reference);
        } catch (InvalidArgumentException $exception) {
            return $this->holdUnnormalizableTopUp($topUp, $exception);
        }

        return $this->claim(
            $receipt,
            ExternalMoneyReceiptReservations::SOURCE_RUNTIME,
            ExternalMoneyReceiptReservation::LEGACY_TYPE_STORE_BALANCE_TOP_UP,
            (string) $topUp->getKey(),
        );
    }

    public function claimProviderPayment(Payment $payment, ?string $reference = null): ExternalMoneyReceiptClaimOutcome
    {
        try {
            $receipt = $this->receiptForProviderPayment($payment, $reference);
        } catch (InvalidArgumentException $exception) {
            $this->reservePayment($payment, ExternalMoneyReceiptReservation::REASON_UNNORMALIZABLE_IDENTITY, [
                'identity_error' => $exception->getMessage(),
            ]);

            return ExternalMoneyReceiptClaimOutcome::Review;
        }

        return $this->claim(
            $receipt,
            ExternalMoneyReceiptReservations::SOURCE_RUNTIME,
            ExternalMoneyReceiptReservation::LEGACY_TYPE_PAYMENT,
            (string) $payment->getKey(),
        );
    }

    public function claimOwnerAttestation(Payment $payment): ExternalMoneyReceiptClaimOutcome
    {
        try {
            $receipt = $this->receiptForOwnerAttestation($payment);
        } catch (InvalidArgumentException $exception) {
            $this->reservePayment($payment, ExternalMoneyReceiptReservation::REASON_UNNORMALIZABLE_IDENTITY, [
                'identity_error' => $exception->getMessage(),
            ]);

            return ExternalMoneyReceiptClaimOutcome::Review;
        }

        return $this->claim(
            $receipt,
            ExternalMoneyReceiptReservations::SOURCE_RUNTIME,
            ExternalMoneyReceiptReservation::LEGACY_TYPE_PAYMENT,
            (string) $payment->getKey(),
        );
    }

    public function resolveCompensationReservation(StoreBalanceTopUp $topUp): int
    {
        return $this->reservations->resolveReason(
            ExternalMoneyReceiptReservation::LEGACY_TYPE_STORE_BALANCE_TOP_UP,
            (string) $topUp->getKey(),
            ExternalMoneyReceiptReservation::REASON_COMPENSATED_UNRESOLVED_MONEY,
        );
    }

    private function holdUnnormalizableTopUp(StoreBalanceTopUp $topUp, InvalidArgumentException $exception): ExternalMoneyReceiptClaimOutcome
    {
        $this->reservations->reserve(
            source: ExternalMoneyReceiptReservations::SOURCE_RUNTIME,
            legacyType: ExternalMoneyReceiptReservation::LEGACY_TYPE_STORE_BALANCE_TOP_UP,
            legacyId: (string) $topUp->getKey(),
            provider: (string) $topUp->provider,
            reason: ExternalMoneyReceiptReservation::REASON_UNNORMALIZABLE_IDENTITY,
            details: ['identity_error' => $exception->getMessage()],
        );

        return ExternalMoneyReceiptClaimOutcome::Review;
    }

    /**
     * Binance Pay order payment identity. The receiving account namespace is
     * the persisted checkout snapshot and never rotating API credentials.
     *
     * @return VerifiedMoneyReceipt|null null when no stable receiver namespace exists
     */
    public function receiptForBinancePayment(
        Payment $payment,
        string $transactionId,
        string $currency,
        int $amountUnits,
        ?string $orderId = null,
    ): ?VerifiedMoneyReceipt {
        $receiver = $this->binanceReceiverFor($payment);
        if ($receiver === null) {
            return null;
        }

        [$receiverType, $receiverValue] = $receiver;
        $aliases = [];
        $orderId = trim((string) $orderId);
        if ($orderId !== '' && $orderId !== $transactionId) {
            $aliases[] = new MoneyReceiptAlias(
                'binance_order_id',
                MoneyReceiptIdentity::binancePay($receiverType, $receiverValue, $this->referenceSegment($orderId)),
            );
        }

        return new VerifiedMoneyReceipt(
            identity: MoneyReceiptIdentity::binancePay($receiverType, $receiverValue, $this->referenceSegment($transactionId)),
            beneficiary: $receiverType.':'.$receiverValue,
            currency: strtoupper(trim($currency)),
            amountUnits: (string) $amountUnits,
            decimals: 6,
            target: MoneyReceiptTarget::orderPayment((string) $payment->getKey()),
            evidence: [
                'provider' => 'binance',
                'identity' => 'receiver_namespace',
            ],
            aliases: $aliases,
        );
    }

    /**
     * BSC log identity: (chain_id, normalized tx hash, log index) plus the
     * parent transaction claim shared by every log of one transaction.
     */
    public function receiptForBscPayment(
        Payment $payment,
        int $chainId,
        string $txHash,
        int $logIndex,
        string $receiverAddress,
        string $amountUnits,
        ?int $decimals,
    ): VerifiedMoneyReceipt {
        $identity = MoneyReceiptIdentity::bscLog($chainId, $txHash, $logIndex);

        return new VerifiedMoneyReceipt(
            identity: $identity,
            beneficiary: strtolower(trim($receiverAddress)),
            currency: 'USDT',
            amountUnits: $this->integerUnits($amountUnits),
            decimals: $decimals,
            target: MoneyReceiptTarget::orderPayment((string) $payment->getKey()),
            evidence: [
                'provider' => 'bsc',
                'chain_id' => $chainId,
                'log_index' => $logIndex,
            ],
            parentClaim: MoneyReceiptParentClaim::for($chainId, $txHash),
        );
    }

    /**
     * Provider-reference identity for QRIS order payments. KlikQRIS, Sakurupiah
     * and Pakasir all persist (or derive) a provider order/reference; the
     * fallback is the unique merchant reference, so the same payment always
     * maps to one identity.
     */
    public function receiptForProviderPayment(Payment $payment, ?string $reference = null): VerifiedMoneyReceipt
    {
        $provider = strtolower(trim((string) $payment->provider));
        $reference = $this->paymentReference($payment, $reference);

        return new VerifiedMoneyReceipt(
            identity: MoneyReceiptIdentity::providerReference($provider, self::NAMESPACE_DEFAULT, $this->referenceSegment($reference)),
            beneficiary: 'order:'.(string) $payment->order_id,
            currency: strtoupper(trim((string) $payment->currency)),
            amountUnits: $this->integerUnits((string) $payment->amount),
            decimals: 0,
            target: MoneyReceiptTarget::orderPayment((string) $payment->getKey()),
            evidence: [
                'provider' => $provider,
                'identity' => 'provider_reference',
            ],
        );
    }

    /**
     * Owner-attested confirmation without an independent provider identity
     * (manual QRIS approval). The unique merchant reference is the identity so
     * one payment confirmation can never be replayed for another target.
     */
    public function receiptForOwnerAttestation(Payment $payment): VerifiedMoneyReceipt
    {
        $reference = trim((string) $payment->merchant_ref);
        if ($reference === '') {
            throw new InvalidArgumentException('Owner-attested payment receipt requires a merchant reference.');
        }

        return new VerifiedMoneyReceipt(
            identity: MoneyReceiptIdentity::providerReference(
                'manual',
                self::NAMESPACE_OWNER_ATTESTATION,
                $this->referenceSegment($reference),
            ),
            beneficiary: 'order:'.(string) $payment->order_id,
            currency: strtoupper(trim((string) $payment->currency)),
            amountUnits: $this->integerUnits((string) $payment->amount),
            decimals: 0,
            target: MoneyReceiptTarget::orderPayment((string) $payment->getKey()),
            evidence: [
                'provider' => 'manual',
                'identity' => 'owner_attestation',
            ],
        );
    }

    /**
     * Store Balance top-up identity for every rail (KlikQRIS, Sakurupiah,
     * manual). The provider account namespace is the persisted payment setting
     * row, stable across credential rotation.
     */
    public function receiptForStoreBalanceTopUp(StoreBalanceTopUp $topUp, ?string $reference = null): VerifiedMoneyReceipt
    {
        $provider = strtolower(trim((string) $topUp->provider));
        $reference = $this->topUpReference($topUp, $reference);
        $namespace = $topUp->payment_setting_id === null
            ? self::NAMESPACE_DEFAULT
            : 'setting:'.(int) $topUp->payment_setting_id;

        return new VerifiedMoneyReceipt(
            identity: MoneyReceiptIdentity::providerReference($provider, $namespace, $this->referenceSegment($reference)),
            beneficiary: 'customer:'.(string) $topUp->customer_id,
            currency: strtoupper(trim((string) $topUp->currency)),
            amountUnits: $this->integerUnits((string) $topUp->amount),
            decimals: 0,
            target: MoneyReceiptTarget::storeBalanceTopUp((string) $topUp->getKey()),
            evidence: [
                'provider' => $provider,
                'identity' => 'provider_reference',
            ],
        );
    }

    public function reserveCompensatedUnresolvedMoney(StoreBalanceTopUp $topUp, string $providerReference): void
    {
        $this->reservations->reserve(
            source: ExternalMoneyReceiptReservations::SOURCE_RUNTIME,
            legacyType: ExternalMoneyReceiptReservation::LEGACY_TYPE_STORE_BALANCE_TOP_UP,
            legacyId: (string) $topUp->getKey(),
            provider: (string) $topUp->provider,
            reason: ExternalMoneyReceiptReservation::REASON_COMPENSATED_UNRESOLVED_MONEY,
            details: [
                'amount' => (int) $topUp->amount,
                'currency' => strtoupper(trim((string) $topUp->currency)),
                'provider_reference_hash' => $providerReference === '' ? null : hash('sha256', $providerReference),
            ],
        );
    }

    public function reservePayment(Payment $payment, string $reason, array $details = []): void
    {
        $this->reservations->reserve(
            source: ExternalMoneyReceiptReservations::SOURCE_RUNTIME,
            legacyType: ExternalMoneyReceiptReservation::LEGACY_TYPE_PAYMENT,
            legacyId: (string) $payment->getKey(),
            provider: (string) $payment->provider,
            reason: $reason,
            details: $details,
        );
    }

    /** @return array{0: string, 1: string}|null */
    private function binanceReceiverFor(Payment $payment): ?array
    {
        if (in_array($payment->binance_receiver_type, ['pay_id', 'uid'], true)
            && trim((string) $payment->binance_receiver_value) !== '') {
            return [(string) $payment->binance_receiver_type, trim((string) $payment->binance_receiver_value)];
        }

        $payId = trim((string) config('services.binance.pay_id'));
        if ($payId !== '') {
            return ['pay_id', $payId];
        }

        $uid = trim((string) config('services.binance.pay_uid'));
        if ($uid !== '') {
            return ['uid', $uid];
        }

        return null;
    }

    private function paymentReference(Payment $payment, ?string $reference): string
    {
        $reference = $reference === null ? '' : trim($reference);
        if ($reference === '') {
            $reference = trim((string) $payment->provider_reference);
        }
        if ($reference === '') {
            $reference = trim((string) $payment->merchant_ref);
        }
        if ($reference === '') {
            throw new InvalidArgumentException('Payment receipt requires a provider or merchant reference.');
        }

        return $reference;
    }

    private function topUpReference(StoreBalanceTopUp $topUp, ?string $reference): string
    {
        $reference = $reference === null ? '' : trim($reference);
        if ($reference === '') {
            $reference = trim((string) $topUp->provider_reference);
        }
        if ($reference === '') {
            $reference = trim((string) $topUp->merchant_ref);
        }
        if ($reference === '') {
            throw new InvalidArgumentException('Store Balance top-up receipt requires a provider or merchant reference.');
        }

        return $reference;
    }

    private function integerUnits(string $units): string
    {
        $units = trim($units);
        if (preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/D', $units) !== 1) {
            throw new InvalidArgumentException('Money receipt amount units must be a non-negative decimal string.');
        }

        $units = str_contains($units, '.')
            ? rtrim(rtrim($units, '0'), '.')
            : $units;
        $units = ltrim($units, '0');

        if ($units === '') {
            throw new InvalidArgumentException('Money receipt amount units must be positive.');
        }

        return $units;
    }

    /**
     * Provider references are used verbatim when they already satisfy the
     * canonical identity alphabet; anything else (spaces, separators, long
     * legacy references) is encoded deterministically as a SHA-256 digest so
     * the runtime claim and the backfill always derive the same identity.
     */
    private function referenceSegment(string $reference): string
    {
        $reference = trim($reference);
        if ($reference === '') {
            throw new InvalidArgumentException('Money receipt reference must not be empty.');
        }

        if (strlen($reference) <= 64 && preg_match('/[^\x21-\x7E]|\|/', $reference) !== 1) {
            return $reference;
        }

        return hash('sha256', $reference);
    }
}
