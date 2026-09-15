<?php

namespace App\Services\Bsc;

use App\Enums\OrderStatus;
use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Models\BscPaymentTransferEvidence;
use App\Models\ManualFulfillmentReview;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentBscDetail;
use App\Models\PaymentEvent;
use App\Services\OrderLifecycle;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

final class BscPaymentVerification
{
    public function __construct(
        private readonly BscVerificationRpcClientInterface $rpc,
        private readonly BscTokenTransferReader $reader,
        private readonly OrderLifecycle $orders,
        private readonly BscVerificationQueue $queue,
        private readonly BscConfirmationGrace $confirmationGrace,
    ) {}

    public function enabled(): bool
    {
        return filled(config('services.bsc.rpc_url'))
            && (int) config('services.bsc.chain_id', 56) === 56
            && strtolower(trim((string) config('services.bsc.usdt_contract'))) === BscReadiness::APPROVED_USDT_CONTRACT;
    }

    public function status(Payment $payment): BscVerificationStatus
    {
        $payment->loadMissing('order');
        $detail = $payment->bscDetail()->first();
        $status = (string) ($detail?->verification_status ?? PaymentBscDetail::STATUS_AWAITING_TRANSFER);
        $outcome = $payment->status === PaymentStatus::Paid
            ? (in_array($payment->order?->order_status, [OrderStatus::Expired, OrderStatus::Cancelled], true)
                ? BscVerificationOutcome::ManualReview
                : BscVerificationOutcome::Confirmed)
            : (BscVerificationOutcome::tryFrom($status) ?? BscVerificationOutcome::NotConfigured);

        return new BscVerificationStatus(
            outcome: $outcome,
            status: $status,
            transactionHash: $detail?->current_tx_hash,
            confirmations: $detail?->current_confirmations,
            attempts: (int) ($detail?->tx_attempts ?? 0),
            version: (int) ($detail?->tx_submission_version ?? 0),
            requiredConfirmations: $this->minimumConfirmations($detail),
        );
    }

    /**
     * Queue a targeted receipt lookup for interactive status refreshes. The
     * caller receives only persisted state; authoritative RPC reads remain in
     * VerifyBscPayment on the shared verification queue.
     */
    public function queueForStatusRefresh(Payment $payment): bool
    {
        $payment->loadMissing('bscDetail');
        if ($payment->status !== PaymentStatus::Pending
            || $payment->provider !== 'manual'
            || $payment->method !== 'USDT_BEP20'
            || $payment->bscDetail === null
            || ! filled($payment->bscDetail->current_tx_hash)) {
            return false;
        }

        if (! in_array($payment->bscDetail->verification_status, [
            PaymentBscDetail::STATUS_TX_SUBMITTED,
            PaymentBscDetail::STATUS_CONFIRMING,
            PaymentBscDetail::STATUS_NOT_FOUND,
            PaymentBscDetail::STATUS_PROVIDER_ERROR,
        ], true)) {
            return false;
        }

        $this->queue->dispatchForHash($payment);

        return true;
    }

    /**
     * Persist a customer-submitted hash and enqueue verification. This method
     * deliberately performs no RPC call; the queue job is the only transport
     * boundary for authoritative chain reads.
     */
    public function submitTransactionHash(string $orderId, string $candidate): BscVerificationSubmissionResult
    {
        $normalized = BscTransactionHash::normalize($candidate);
        if ($normalized === null) {
            return BscVerificationSubmissionResult::of(BscVerificationSubmissionOutcome::Invalid);
        }

        $result = DB::transaction(function () use ($orderId, $normalized): BscVerificationSubmissionResult {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $payment = $order->payments()
                ->where('provider', 'manual')
                ->where('method', 'USDT_BEP20')
                ->whereHas('bscDetail')
                ->lockForUpdate()
                ->first();

            if (! $payment) {
                return BscVerificationSubmissionResult::of(BscVerificationSubmissionOutcome::NotConfigured);
            }

            $detail = PaymentBscDetail::query()->whereKey($payment->id)->lockForUpdate()->first();
            if (! $detail) {
                return BscVerificationSubmissionResult::of(BscVerificationSubmissionOutcome::NotConfigured);
            }

            if ($payment->status === PaymentStatus::Paid) {
                return BscVerificationSubmissionResult::of(BscVerificationSubmissionOutcome::AlreadyPaid, $payment->fresh());
            }

            if ($payment->status !== PaymentStatus::Pending || $order->order_status !== OrderStatus::PendingPayment) {
                return BscVerificationSubmissionResult::of(BscVerificationSubmissionOutcome::OrderNotPending, $payment->fresh());
            }

            $deadline = $this->deadline($payment, $order);
            if ($deadline === null) {
                return BscVerificationSubmissionResult::of(BscVerificationSubmissionOutcome::Expired, $payment->fresh());
            }

            $graceUntil = $detail->verification_grace_until;
            $activeGrace = $this->confirmationGrace->isActive($payment, $order);
            if ($graceUntil !== null && $graceUntil->isPast()) {
                return BscVerificationSubmissionResult::of(BscVerificationSubmissionOutcome::GraceExpired, $payment->fresh());
            }

            if ($deadline->isPast() && ! $activeGrace) {
                return BscVerificationSubmissionResult::of(BscVerificationSubmissionOutcome::Expired, $payment->fresh());
            }

            $sameHash = BscTransactionHash::normalize((string) $detail->current_tx_hash) === $normalized;
            if ($sameHash && (int) $detail->tx_submission_version > 0) {
                return BscVerificationSubmissionResult::of(
                    BscVerificationSubmissionOutcome::Accepted,
                    $payment->fresh(),
                    (int) $detail->tx_submission_version,
                    false,
                );
            }

            $existingEvidence = BscPaymentTransferEvidence::query()
                ->where('tx_hash', $normalized)
                ->lockForUpdate()
                ->first();
            if ($existingEvidence !== null && (string) $existingEvidence->payment_id !== (string) $payment->id) {
                $detail->update([
                    'verification_status' => PaymentBscDetail::STATUS_TRANSACTION_ALREADY_USED,
                    'last_verified_at' => now(),
                    'current_tx_hash' => $normalized,
                    'evidence_payload' => [
                        'reason' => 'transaction_already_used',
                        'tx_hash' => $normalized,
                    ],
                ]);
                $this->ensureManualFulfillmentReview(
                    payment: $payment,
                    reason: ManualFulfillmentReview::REASON_BSC_TRANSACTION_ALREADY_USED,
                    providerReference: $normalized,
                );

                return BscVerificationSubmissionResult::of(
                    BscVerificationSubmissionOutcome::TransactionAlreadyUsed,
                    $payment->fresh(),
                );
            }

            $attemptLimit = ManualPaymentSetting::current()?->bscHashAttemptLimit()
                ?? ManualPaymentSetting::DEFAULT_BSC_HASH_ATTEMPT_LIMIT;
            if ((int) $detail->tx_attempts >= $attemptLimit) {
                return BscVerificationSubmissionResult::of(BscVerificationSubmissionOutcome::Limit, $payment->fresh());
            }

            $version = (int) $detail->tx_submission_version + 1;
            $detail->update([
                'current_tx_hash' => $normalized,
                'tx_submission_version' => $version,
                'tx_attempts' => (int) $detail->tx_attempts + 1,
                'verification_status' => PaymentBscDetail::STATUS_TX_SUBMITTED,
                'last_verified_at' => null,
            ]);

            return BscVerificationSubmissionResult::of(
                BscVerificationSubmissionOutcome::Accepted,
                $payment->fresh(),
                $version,
                true,
            );
        }, 5);

        if (! $result->accepted() || ! $result->dispatchRequired || $result->payment === null) {
            return $result;
        }

        try {
            $this->queue->dispatch($result->payment, $result->version);
        } catch (Throwable $exception) {
            report($exception);
            $this->recordQueueFailure($result->payment, $result->version);

            return BscVerificationSubmissionResult::of(
                BscVerificationSubmissionOutcome::Accepted,
                $result->payment->fresh(),
                $result->version,
                true,
                true,
            );
        }

        return $result;
    }

    public function submitTxHash(string $orderId, string $candidate): BscVerificationSubmissionResult
    {
        return $this->submitTransactionHash($orderId, $candidate);
    }

    public function verify(Payment|string $payment, ?string $submittedHash = null, ?int $submissionVersion = null): BscVerificationResult
    {
        $submissionVersionBound = $submissionVersion !== null;
        $payment = is_string($payment)
            ? Payment::query()->findOrFail($payment)
            : $payment;
        $payment->loadMissing('order');

        $detail = $payment->bscDetail()->first();
        $submissionVersion ??= (int) ($detail?->tx_submission_version ?? 0);
        $lateHash = $submittedHash !== null
            ? BscTransactionHash::normalize($submittedHash)
            : BscTransactionHash::normalize((string) $detail?->current_tx_hash);
        if ($detail !== null && ! $this->submissionIsCurrent($detail->payment_id, $submissionVersion)) {
            return BscVerificationResult::of(BscVerificationOutcome::StaleSubmission, retryable: false);
        }
        if ($payment->status !== PaymentStatus::Paid
            && $payment->provider === 'manual'
            && $payment->method === 'USDT_BEP20'
            && ($payment->status !== PaymentStatus::Pending
                || $payment->order?->order_status !== OrderStatus::PendingPayment)
            && ! in_array($payment->status, [PaymentStatus::Refunded], true)
            && $detail !== null) {
            if ($lateHash === null) {
                return BscVerificationResult::of(BscVerificationOutcome::OrderNotPending, retryable: false);
            }

            $result = $this->verifyLateCandidate($payment, $detail, $lateHash, $submissionVersion);

            return $this->staleIfSubmissionChanged($detail, $submissionVersion, $result);
        }

        $guard = $this->guard($payment);
        if ($guard !== null) {
            return $guard;
        }

        if (! $detail) {
            return BscVerificationResult::of(BscVerificationOutcome::NotConfigured, retryable: false);
        }

        $configuredContract = strtolower(trim((string) config('services.bsc.usdt_contract')));
        $configuredChainId = (int) config('services.bsc.chain_id', 56);
        if ($configuredChainId !== 56
            || $configuredContract !== BscReadiness::APPROVED_USDT_CONTRACT
            || (int) $detail->chain_id !== 56
            || strtolower((string) $detail->token_contract) !== $configuredContract
            || (int) $detail->token_decimals < 6) {
            $this->persistStatus($payment, $detail, PaymentBscDetail::STATUS_PROVIDER_ERROR, $submissionVersion, null, [
                'reason' => 'configured_bsc_contract_or_chain_is_invalid',
            ]);

            return $this->staleIfSubmissionChanged(
                $detail,
                $submissionVersion,
                BscVerificationResult::of(BscVerificationOutcome::NotConfigured, retryable: false),
            );
        }

        $hash = $submittedHash !== null
            ? BscTransactionHash::normalize($submittedHash)
            : BscTransactionHash::normalize((string) $detail->current_tx_hash);
        if ($submittedHash !== null && $hash === null) {
            return BscVerificationResult::of(BscVerificationOutcome::StaleSubmission, retryable: false);
        }
        if ($submissionVersionBound
            && ((int) $detail->tx_submission_version !== $submissionVersion
                || $hash === null
                || $detail->current_tx_hash !== $hash)) {
            return BscVerificationResult::of(BscVerificationOutcome::StaleSubmission, retryable: false);
        }

        if ($hash === null && $this->automaticWindowClosed($payment, $detail)) {
            $this->persistStatus($payment, $detail, PaymentBscDetail::STATUS_EXPIRED, $submissionVersion, null, [
                'reason' => 'payment_window_closed',
            ]);

            return $this->staleIfSubmissionChanged(
                $detail,
                $submissionVersion,
                BscVerificationResult::of(BscVerificationOutcome::Expired, retryable: false),
            );
        }
        if ($hash === null) {
            return $this->staleIfSubmissionChanged(
                $detail,
                $submissionVersion,
                BscVerificationResult::of(BscVerificationOutcome::AwaitingTransfer, retryable: true),
            );
        }

        try {
            $chainId = $this->rpc->chainId();
            if ($chainId !== 56 || $chainId !== (int) $detail->chain_id) {
                $result = $this->mismatch($payment, $detail, $submissionVersion, $hash, null, 'wrong_chain');

                return $this->staleIfSubmissionChanged($detail, $submissionVersion, $result);
            }

            $result = $this->verifySubmittedHash($payment, $detail, $hash, $submissionVersion);

            return $this->staleIfSubmissionChanged($detail, $submissionVersion, $result);
        } catch (BscRpcException $exception) {
            report($exception);
            $this->persistStatus($payment, $detail, PaymentBscDetail::STATUS_PROVIDER_ERROR, $submissionVersion, $hash, [
                'reason' => 'rpc_error',
            ]);

            return $this->staleIfSubmissionChanged(
                $detail,
                $submissionVersion,
                BscVerificationResult::of(BscVerificationOutcome::ProviderError, retryable: $exception->retryable),
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->persistStatus($payment, $detail, PaymentBscDetail::STATUS_PROVIDER_ERROR, $submissionVersion, $hash, [
                'reason' => 'unexpected_verification_error',
            ]);

            return $this->staleIfSubmissionChanged(
                $detail,
                $submissionVersion,
                BscVerificationResult::of(BscVerificationOutcome::ProviderError, retryable: false),
            );
        }
    }

    public function verifyPayment(string $paymentId, ?int $submissionVersion = null): BscVerificationResult
    {
        return $this->verify($paymentId, null, $submissionVersion);
    }

    public function retry(Payment|string $payment, ?int $submissionVersion = null): BscVerificationResult
    {
        return $this->verify($payment, null, $submissionVersion);
    }

    public function retryPayment(string $paymentId, ?int $submissionVersion = null): BscVerificationResult
    {
        return $this->retry($paymentId, $submissionVersion);
    }

    public function recordOutcome(Payment $payment, BscVerificationResult $result, ?int $submissionVersion = null): void
    {
        if (in_array($result->outcome, [
            BscVerificationOutcome::StaleSubmission,
            BscVerificationOutcome::OrderNotPending,
            BscVerificationOutcome::AlreadyPaid,
        ], true)) {
            return;
        }

        $status = match ($result->outcome) {
            BscVerificationOutcome::Confirmed => PaymentBscDetail::STATUS_CONFIRMED,
            BscVerificationOutcome::AwaitingTransfer => PaymentBscDetail::STATUS_AWAITING_TRANSFER,
            BscVerificationOutcome::TxSubmitted => PaymentBscDetail::STATUS_TX_SUBMITTED,
            BscVerificationOutcome::Confirming => PaymentBscDetail::STATUS_CONFIRMING,
            BscVerificationOutcome::NotFound => PaymentBscDetail::STATUS_NOT_FOUND,
            BscVerificationOutcome::Mismatch => PaymentBscDetail::STATUS_MISMATCH,
            BscVerificationOutcome::TransactionAlreadyUsed => PaymentBscDetail::STATUS_TRANSACTION_ALREADY_USED,
            BscVerificationOutcome::ProviderError => PaymentBscDetail::STATUS_PROVIDER_ERROR,
            BscVerificationOutcome::ManualReview => PaymentBscDetail::STATUS_MANUAL_REVIEW,
            BscVerificationOutcome::Expired => PaymentBscDetail::STATUS_EXPIRED,
            default => PaymentBscDetail::STATUS_PROVIDER_ERROR,
        };
        $detail = PaymentBscDetail::query()->whereKey($payment->id)->first();
        if ($detail !== null) {
            $submissionVersion ??= (int) $detail->tx_submission_version;
            if (! $this->submissionIsCurrent($detail->payment_id, $submissionVersion)) {
                return;
            }
            $this->persistStatus($payment, $detail, $status, $submissionVersion, $result->evidence?->txHash, evidence: $result->evidence);
        }
    }

    public function recordQueueFailure(Payment $payment, ?int $submissionVersion = null): BscVerificationResult
    {
        $detail = PaymentBscDetail::query()->whereKey($payment->id)->first();
        if (! $detail) {
            return BscVerificationResult::of(BscVerificationOutcome::NotConfigured, retryable: false);
        }

        $submissionVersion ??= (int) $detail->tx_submission_version;

        $updated = PaymentBscDetail::query()
            ->whereKey($payment->id)
            ->whereIn('verification_status', [
                PaymentBscDetail::STATUS_AWAITING_TRANSFER,
                PaymentBscDetail::STATUS_TX_SUBMITTED,
                PaymentBscDetail::STATUS_CONFIRMING,
                PaymentBscDetail::STATUS_NOT_FOUND,
                PaymentBscDetail::STATUS_PROVIDER_ERROR,
            ])
            ->where('tx_submission_version', $submissionVersion)
            ->update([
                'verification_status' => PaymentBscDetail::STATUS_PROVIDER_ERROR,
                'last_verified_at' => now(),
            ]);

        return $updated === 1
            ? BscVerificationResult::of(BscVerificationOutcome::ProviderError)
            : BscVerificationResult::of(BscVerificationOutcome::StaleSubmission, retryable: false);
    }

    private function verifyLateCandidate(
        Payment $payment,
        PaymentBscDetail $detail,
        string $hash,
        int $submissionVersion,
    ): BscVerificationResult {
        if (! $this->enabled()) {
            return BscVerificationResult::of(BscVerificationOutcome::OrderNotPending, retryable: false);
        }
        if (! $this->submissionIsCurrent($detail->payment_id, $submissionVersion)) {
            return BscVerificationResult::of(BscVerificationOutcome::StaleSubmission, retryable: false);
        }
        if (! $this->validConfiguredSnapshot($detail)) {
            $this->persistStatus($payment, $detail, PaymentBscDetail::STATUS_PROVIDER_ERROR, $submissionVersion, $hash, [
                'reason' => 'late_transfer_invalid_bsc_snapshot',
            ]);

            return BscVerificationResult::of(BscVerificationOutcome::NotConfigured, retryable: false);
        }

        try {
            if ($this->rpc->chainId() !== (int) $detail->chain_id || (int) $detail->chain_id !== 56) {
                return $this->flagForManualReview(
                    payment: $payment,
                    detail: $detail,
                    submissionVersion: $submissionVersion,
                    outcome: BscVerificationOutcome::ManualReview,
                    detailStatus: PaymentBscDetail::STATUS_MANUAL_REVIEW,
                    reason: ManualFulfillmentReview::REASON_BSC_MANUAL_REVIEW,
                    hash: $hash,
                    evidence: null,
                    metadata: ['reason' => 'late_transfer_wrong_chain'],
                );
            }

            $receipt = $this->rpc->transactionReceipt($hash);
            if ($receipt === null) {
                return BscVerificationResult::of(BscVerificationOutcome::Expired, retryable: false);
            }

            $inspection = $this->reader->inspectReceipt(
                receipt: $receipt,
                expectedContract: (string) $detail->token_contract,
                expectedReceiver: (string) $detail->receiver_address,
                expectedAmountUnits: (string) $detail->expected_token_units,
                submittedHash: $hash,
            );
            if ($inspection->exactTransfers === []) {
                $evidence = $inspection->transfers[0] ?? null;

                return $this->flagForManualReview(
                    payment: $payment,
                    detail: $detail,
                    submissionVersion: $submissionVersion,
                    outcome: BscVerificationOutcome::ManualReview,
                    detailStatus: PaymentBscDetail::STATUS_MANUAL_REVIEW,
                    reason: ManualFulfillmentReview::REASON_BSC_MISMATCH,
                    hash: $hash,
                    evidence: $evidence,
                    metadata: ['reason' => 'late_transfer_did_not_match_payment'],
                );
            }

            if (count($inspection->exactTransfers) !== 1) {
                $evidence = $inspection->exactTransfers[0];

                return $this->flagForManualReview(
                    payment: $payment,
                    detail: $detail,
                    submissionVersion: $submissionVersion,
                    outcome: BscVerificationOutcome::ManualReview,
                    detailStatus: PaymentBscDetail::STATUS_MANUAL_REVIEW,
                    reason: ManualFulfillmentReview::REASON_BSC_AMBIGUOUS,
                    hash: $hash,
                    evidence: $evidence,
                    metadata: ['reason' => 'late_transfer_multiple_matching_logs'],
                );
            }

            $evidence = $inspection->exactTransfers[0];
            if ($this->evidenceBelongsToAnotherPayment($payment, $evidence)) {
                return $this->flagForManualReview(
                    payment: $payment,
                    detail: $detail,
                    submissionVersion: $submissionVersion,
                    outcome: BscVerificationOutcome::TransactionAlreadyUsed,
                    detailStatus: PaymentBscDetail::STATUS_TRANSACTION_ALREADY_USED,
                    reason: ManualFulfillmentReview::REASON_BSC_TRANSACTION_ALREADY_USED,
                    hash: $hash,
                    evidence: $evidence,
                    metadata: ['reason' => 'late_transaction_already_used'],
                );
            }

            $block = $this->rpc->blockByNumber($evidence->blockNumber);
            $latest = $this->rpc->blockByNumber('latest');
            $blockNumber = $this->blockNumber($block['number'] ?? null);
            $latestNumber = $this->blockNumber($latest['number'] ?? null);
            $blockTimestamp = $this->blockTimestamp($block['timestamp'] ?? null);
            if ($block === null || $latest === null || $blockNumber === null || $latestNumber === null || $blockTimestamp === null) {
                throw new BscRpcException('BSC RPC returned incomplete late-payment block facts.');
            }

            $receiptBlockHash = BscTransactionHash::normalize((string) ($receipt['blockHash'] ?? ''));
            $blockHash = BscTransactionHash::normalize((string) ($block['hash'] ?? ''));
            if ($blockNumber !== $evidence->blockNumber
                || $latestNumber < $blockNumber
                || ($receiptBlockHash !== null && $blockHash !== null && ! hash_equals($receiptBlockHash, $blockHash))) {
                return $this->flagForManualReview(
                    payment: $payment,
                    detail: $detail,
                    submissionVersion: $submissionVersion,
                    outcome: BscVerificationOutcome::ManualReview,
                    detailStatus: PaymentBscDetail::STATUS_MANUAL_REVIEW,
                    reason: ManualFulfillmentReview::REASON_BSC_MANUAL_REVIEW,
                    hash: $hash,
                    evidence: $evidence,
                    metadata: ['reason' => 'late_transfer_reorg_or_invalid_block'],
                );
            }

            if ($payment->created_at !== null
                && ($blockTimestamp->timestamp < $payment->created_at->timestamp || $blockTimestamp->isFuture())) {
                return $this->flagForManualReview(
                    payment: $payment,
                    detail: $detail,
                    submissionVersion: $submissionVersion,
                    outcome: BscVerificationOutcome::ManualReview,
                    detailStatus: PaymentBscDetail::STATUS_MANUAL_REVIEW,
                    reason: ManualFulfillmentReview::REASON_BSC_MANUAL_REVIEW,
                    hash: $hash,
                    evidence: $evidence,
                    metadata: [
                        'reason' => $blockTimestamp->isFuture()
                            ? 'late_transfer_future_block'
                            : 'late_transfer_before_payment_window',
                    ],
                );
            }

            $evidence = $evidence
                ->withBlockHash($blockHash ?? $receiptBlockHash ?? $evidence->blockHash)
                ->withChainFacts($blockTimestamp, $latestNumber - $blockNumber + 1);

            return $this->recordLatePayment($payment, $evidence, $submissionVersion);
        } catch (BscRpcException $exception) {
            report($exception);
            $this->persistStatus($payment, $detail, PaymentBscDetail::STATUS_PROVIDER_ERROR, $submissionVersion, $hash, [
                'reason' => 'late_transfer_rpc_error',
            ]);

            return BscVerificationResult::of(BscVerificationOutcome::ProviderError, retryable: $exception->retryable);
        } catch (Throwable $exception) {
            report($exception);
            $this->persistStatus($payment, $detail, PaymentBscDetail::STATUS_PROVIDER_ERROR, $submissionVersion, $hash, [
                'reason' => 'late_transfer_verification_error',
            ]);

            return BscVerificationResult::of(BscVerificationOutcome::ProviderError, retryable: false);
        }
    }

    private function verifySubmittedHash(
        Payment $payment,
        PaymentBscDetail $detail,
        string $hash,
        int $submissionVersion,
    ): BscVerificationResult {
        if (! $this->submissionIsCurrent($detail->payment_id, $submissionVersion)) {
            return BscVerificationResult::of(BscVerificationOutcome::StaleSubmission, retryable: false);
        }

        $receipt = $this->rpc->transactionReceipt($hash);
        if ($receipt === null) {
            if ($this->automaticWindowClosed($payment, $detail)) {
                $this->persistStatus($payment, $detail, PaymentBscDetail::STATUS_EXPIRED, $submissionVersion, $hash, [
                    'reason' => 'payment_window_closed_without_receipt',
                ]);

                return BscVerificationResult::of(BscVerificationOutcome::Expired, retryable: false);
            }

            $this->persistStatus($payment, $detail, PaymentBscDetail::STATUS_NOT_FOUND, $submissionVersion, $hash, [
                'reason' => 'receipt_not_found',
            ]);

            return BscVerificationResult::of(BscVerificationOutcome::NotFound, retryable: true);
        }

        $inspection = $this->reader->inspectReceipt(
            receipt: $receipt,
            expectedContract: (string) $detail->token_contract,
            expectedReceiver: (string) $detail->receiver_address,
            expectedAmountUnits: (string) $detail->expected_token_units,
            submittedHash: $hash,
        );

        if (! $inspection->successfulReceipt()) {
            return $this->flagForManualReview(
                payment: $payment,
                detail: $detail,
                submissionVersion: $submissionVersion,
                outcome: BscVerificationOutcome::Mismatch,
                detailStatus: PaymentBscDetail::STATUS_MISMATCH,
                reason: ManualFulfillmentReview::REASON_BSC_MISMATCH,
                hash: $hash,
                evidence: null,
                metadata: [
                    'reason' => $inspection->receiptState === 'failed' ? 'failed_receipt' : 'invalid_receipt',
                    'receipt_status' => $inspection->receiptState,
                ],
            );
        }

        if ($inspection->transfers === []) {
            return $this->flagForManualReview(
                payment: $payment,
                detail: $detail,
                submissionVersion: $submissionVersion,
                outcome: BscVerificationOutcome::Mismatch,
                detailStatus: PaymentBscDetail::STATUS_MISMATCH,
                reason: ManualFulfillmentReview::REASON_BSC_MISMATCH,
                hash: $hash,
                evidence: null,
                metadata: ['reason' => 'no_transfer_log'],
            );
        }

        if ($inspection->exactTransfers === []) {
            $evidence = $inspection->transfers[0];
            if ($this->evidenceBelongsToAnotherPayment($payment, $evidence)) {
                return $this->flagForManualReview(
                    payment: $payment,
                    detail: $detail,
                    submissionVersion: $submissionVersion,
                    outcome: BscVerificationOutcome::TransactionAlreadyUsed,
                    detailStatus: PaymentBscDetail::STATUS_TRANSACTION_ALREADY_USED,
                    reason: ManualFulfillmentReview::REASON_BSC_TRANSACTION_ALREADY_USED,
                    hash: $hash,
                    evidence: $evidence,
                    metadata: ['reason' => 'transaction_already_used'],
                );
            }

            return $this->flagForManualReview(
                payment: $payment,
                detail: $detail,
                submissionVersion: $submissionVersion,
                outcome: BscVerificationOutcome::Mismatch,
                detailStatus: PaymentBscDetail::STATUS_MISMATCH,
                reason: ManualFulfillmentReview::REASON_BSC_MISMATCH,
                hash: $hash,
                evidence: $evidence,
                metadata: ['reason' => 'transfer_predicate_mismatch'],
            );
        }

        if (count($inspection->exactTransfers) !== 1) {
            $evidence = $inspection->exactTransfers[0];

            return $this->flagForManualReview(
                payment: $payment,
                detail: $detail,
                submissionVersion: $submissionVersion,
                outcome: BscVerificationOutcome::ManualReview,
                detailStatus: PaymentBscDetail::STATUS_MANUAL_REVIEW,
                reason: ManualFulfillmentReview::REASON_BSC_AMBIGUOUS,
                hash: $hash,
                evidence: $evidence,
                metadata: [
                    'reason' => 'multiple_matching_transfer_logs',
                    'matching_log_count' => count($inspection->exactTransfers),
                ],
            );
        }

        return $this->evaluateExactTransfer(
            $payment,
            $detail,
            $inspection->exactTransfers[0],
            $receipt,
            $submissionVersion,
        );
    }

    /** @param array<string, mixed> $receipt */
    private function evaluateExactTransfer(
        Payment $payment,
        PaymentBscDetail $detail,
        BscTransferEvidence $evidence,
        array $receipt,
        int $submissionVersion,
    ): BscVerificationResult {
        $block = $this->rpc->blockByNumber($evidence->blockNumber);
        $latest = $this->rpc->blockByNumber('latest');
        $blockNumber = $this->blockNumber($block['number'] ?? null);
        $latestNumber = $this->blockNumber($latest['number'] ?? null);
        $blockTimestamp = $this->blockTimestamp($block['timestamp'] ?? null);
        if ($block === null || $latest === null || $blockNumber === null || $latestNumber === null || $blockTimestamp === null) {
            throw new BscRpcException('BSC RPC returned incomplete block facts.');
        }
        if ($blockNumber !== $evidence->blockNumber || $latestNumber < $blockNumber) {
            return $this->flagForManualReview(
                payment: $payment,
                detail: $detail,
                submissionVersion: $submissionVersion,
                outcome: BscVerificationOutcome::ManualReview,
                detailStatus: PaymentBscDetail::STATUS_MANUAL_REVIEW,
                reason: ManualFulfillmentReview::REASON_BSC_MANUAL_REVIEW,
                hash: $evidence->txHash,
                evidence: $evidence,
                metadata: ['reason' => 'reorg_or_invalid_block_number'],
            );
        }

        $receiptBlockHash = BscTransactionHash::normalize((string) ($receipt['blockHash'] ?? ''));
        $blockHash = BscTransactionHash::normalize((string) ($block['hash'] ?? ''));
        if ($receiptBlockHash !== null && $blockHash !== null && ! hash_equals($receiptBlockHash, $blockHash)) {
            return $this->flagForManualReview(
                payment: $payment,
                detail: $detail,
                submissionVersion: $submissionVersion,
                outcome: BscVerificationOutcome::ManualReview,
                detailStatus: PaymentBscDetail::STATUS_MANUAL_REVIEW,
                reason: ManualFulfillmentReview::REASON_BSC_MANUAL_REVIEW,
                hash: $evidence->txHash,
                evidence: $evidence,
                metadata: ['reason' => 'receipt_block_hash_changed'],
            );
        }

        $evidence = $evidence
            ->withBlockHash($blockHash ?? $receiptBlockHash ?? $evidence->blockHash)
            ->withChainFacts($blockTimestamp, $latestNumber - $blockNumber + 1);
        $deadline = $this->deadline($payment, $payment->order);
        $createdAt = $payment->created_at;
        if ($deadline === null || $createdAt === null) {
            return BscVerificationResult::of(BscVerificationOutcome::NotConfigured, $evidence, retryable: false);
        }

        if ($blockTimestamp->timestamp < $createdAt->timestamp
            || $blockTimestamp->timestamp > $deadline->timestamp
            || $blockTimestamp->isFuture()) {
            if ($blockTimestamp->timestamp > $deadline->timestamp
                && $payment->status === PaymentStatus::Pending
                && $payment->order?->order_status === OrderStatus::PendingPayment) {
                if (! $this->applyPendingExpiryForSubmission($payment, $submissionVersion)) {
                    return BscVerificationResult::of(BscVerificationOutcome::StaleSubmission, retryable: false);
                }

                $payment->refresh();

                return $this->recordLatePayment($payment, $evidence, $submissionVersion);
            }

            return $this->flagForManualReview(
                payment: $payment,
                detail: $detail,
                submissionVersion: $submissionVersion,
                outcome: BscVerificationOutcome::ManualReview,
                detailStatus: PaymentBscDetail::STATUS_MANUAL_REVIEW,
                reason: ManualFulfillmentReview::REASON_BSC_MANUAL_REVIEW,
                hash: $evidence->txHash,
                evidence: $evidence,
                metadata: [
                    'reason' => $blockTimestamp->isFuture()
                        ? 'future_block_timestamp'
                        : ($blockTimestamp->timestamp > $deadline->timestamp ? 'late_transfer' : 'transfer_before_payment_window'),
                ],
            );
        }
        $graceUntil = $detail->verification_grace_until;
        if ($graceUntil === null && $blockTimestamp->timestamp <= $deadline->timestamp) {
            $graceUntil = $deadline->copy()->addMinutes($this->confirmationGraceMinutes($detail));
            $updated = PaymentBscDetail::query()
                ->whereKey($detail->payment_id)
                ->where('tx_submission_version', $submissionVersion)
                ->whereNull('verification_grace_until')
                ->update(['verification_grace_until' => $graceUntil]);
            if ($updated === 0) {
                $currentDetail = PaymentBscDetail::query()->whereKey($detail->payment_id)->first();
                if ($currentDetail === null || (int) $currentDetail->tx_submission_version !== $submissionVersion) {
                    return BscVerificationResult::of(BscVerificationOutcome::StaleSubmission, retryable: false);
                }

                $graceUntil = $currentDetail->verification_grace_until;
            }
        }

        if ($graceUntil !== null && $graceUntil->isPast() && $evidence->confirmations < $this->minimumConfirmations($detail)) {
            return $this->flagForManualReview(
                payment: $payment,
                detail: $detail,
                submissionVersion: $submissionVersion,
                outcome: BscVerificationOutcome::ManualReview,
                detailStatus: PaymentBscDetail::STATUS_MANUAL_REVIEW,
                reason: ManualFulfillmentReview::REASON_BSC_MANUAL_REVIEW,
                hash: $evidence->txHash,
                evidence: $evidence,
                metadata: ['reason' => 'confirmation_grace_expired'],
            );
        }

        $this->persistEvidence($payment, $evidence, PaymentBscDetail::STATUS_CONFIRMING, $submissionVersion);

        if (($evidence->confirmations ?? 0) < $this->minimumConfirmations($detail)) {
            $this->persistStatus($payment, $detail, PaymentBscDetail::STATUS_CONFIRMING, $submissionVersion, $evidence->txHash, [
                'reason' => 'confirmation_threshold_not_reached',
                'required_confirmations' => $this->minimumConfirmations($detail),
            ], $evidence);

            return BscVerificationResult::of(BscVerificationOutcome::Confirming, $evidence, retryable: true);
        }

        if ($this->requiresFinalizedBlock($detail)) {
            $finalized = $this->rpc->blockByNumber('finalized');
            $finalizedNumber = $this->blockNumber($finalized['number'] ?? null);
            if ($finalized === null || $finalizedNumber === null || $finalizedNumber < $blockNumber) {
                $this->persistStatus($payment, $detail, PaymentBscDetail::STATUS_CONFIRMING, $submissionVersion, $evidence->txHash, [
                    'reason' => 'finalized_block_not_reached',
                    'required_confirmations' => $this->minimumConfirmations($detail),
                ], $evidence);

                return BscVerificationResult::of(BscVerificationOutcome::Confirming, $evidence, retryable: true);
            }
        }

        if ($graceUntil !== null && $graceUntil->isPast() && $blockTimestamp->timestamp <= $deadline->timestamp && $payment->status === PaymentStatus::Pending) {
            // A transfer that was included before expiry is safe to claim only
            // while the short confirmation grace remains open.
            return $this->flagForManualReview(
                payment: $payment,
                detail: $detail,
                submissionVersion: $submissionVersion,
                outcome: BscVerificationOutcome::ManualReview,
                detailStatus: PaymentBscDetail::STATUS_MANUAL_REVIEW,
                reason: ManualFulfillmentReview::REASON_BSC_MANUAL_REVIEW,
                hash: $evidence->txHash,
                evidence: $evidence,
                metadata: ['reason' => 'confirmation_grace_expired'],
            );
        }

        return $this->claim($payment, $detail, $evidence, $submissionVersion);
    }

    private function applyPendingExpiryForSubmission(Payment $payment, int $submissionVersion): bool
    {
        return DB::transaction(function () use ($payment, $submissionVersion): bool {
            $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
            $detail = PaymentBscDetail::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ((int) $detail->tx_submission_version !== $submissionVersion) {
                return false;
            }

            $this->orders->applyPendingExpiry($order);

            return true;
        }, 5);
    }

    private function recordLatePayment(
        Payment $payment,
        BscTransferEvidence $evidence,
        int $submissionVersion,
    ): BscVerificationResult {
        try {
            $outcome = DB::transaction(function () use ($payment, $evidence, $submissionVersion): BscVerificationOutcome {
                $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
                $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
                $lockedDetail = PaymentBscDetail::query()->whereKey($locked->id)->lockForUpdate()->firstOrFail();

                if ($locked->status === PaymentStatus::Paid) {
                    return BscVerificationOutcome::AlreadyPaid;
                }

                if ((int) $lockedDetail->tx_submission_version !== $submissionVersion) {
                    return BscVerificationOutcome::StaleSubmission;
                }

                $existing = BscPaymentTransferEvidence::query()
                    ->where('tx_hash', $evidence->txHash)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null && (string) $existing->payment_id !== (string) $locked->id) {
                    return BscVerificationOutcome::TransactionAlreadyUsed;
                }

                if ($existing === null) {
                    BscPaymentTransferEvidence::query()->create($this->evidenceAttributes(
                        $locked->id,
                        $evidence,
                        PaymentBscDetail::STATUS_MANUAL_REVIEW,
                    ));
                } else {
                    $existing->update([
                        'verification_status' => PaymentBscDetail::STATUS_MANUAL_REVIEW,
                        'confirmations' => $evidence->confirmations,
                        'last_verified_at' => now(),
                    ]);
                }

                $locked->update([
                    'provider_reference' => $evidence->txHash,
                    'provider_payload' => [
                        'reason' => 'late_payment',
                        ...$evidence->sanitizedPayload(),
                    ],
                ]);
                $lockedDetail->update([
                    'verification_status' => PaymentBscDetail::STATUS_MANUAL_REVIEW,
                    'last_verified_at' => now(),
                    'current_tx_hash' => $evidence->txHash,
                    'current_confirmations' => $evidence->confirmations,
                    'current_block_number' => $evidence->blockNumber,
                    'current_from_address' => $evidence->fromAddress,
                    'evidence_payload' => [
                        'reason' => 'late_payment',
                        ...$evidence->sanitizedPayload(),
                    ],
                ]);

                // OrderLifecycle deliberately keeps the terminal Order
                // terminal, marks the financially valid Payment paid, and
                // creates the existing ManualFulfillmentReview boundary.
                $this->orders->applyPaymentPaid($locked);
                $review = ManualFulfillmentReview::query()
                    ->where('payment_id', $locked->id)
                    ->lockForUpdate()
                    ->first();
                if ($review !== null
                    && $review->state === ManualFulfillmentReview::STATE_PENDING
                    && $review->reason !== ManualFulfillmentReview::REASON_LATE_PAYMENT) {
                    $review->update([
                        'reason' => ManualFulfillmentReview::REASON_LATE_PAYMENT,
                        'provider_reference' => $evidence->txHash,
                    ]);
                }

                $receivedAt = now();
                PaymentEvent::query()->firstOrCreate(
                    [
                        'provider' => 'bsc',
                        'event_type' => PaymentEventType::Reconciliation->value,
                        'idempotency_key' => $this->eventIdempotencyKey($evidence->txHash),
                    ],
                    [
                        'payment_id' => $locked->id,
                        'merchant_ref' => $locked->merchant_ref,
                        'provider_reference' => $evidence->txHash,
                        'provider_status' => 'LATE_PAYMENT',
                        'raw_payload' => [
                            'reason' => 'late_payment',
                            ...$evidence->sanitizedPayload(),
                        ],
                        'received_at' => $receivedAt,
                        'last_received_at' => $receivedAt,
                        'received_count' => 1,
                        'processed_at' => $receivedAt,
                    ],
                );

                return BscVerificationOutcome::ManualReview;
            }, 5);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $this->ensureManualFulfillmentReview(
                payment: $payment,
                reason: ManualFulfillmentReview::REASON_BSC_TRANSACTION_ALREADY_USED,
                providerReference: $evidence->txHash,
            );

            return BscVerificationResult::of(BscVerificationOutcome::TransactionAlreadyUsed, $evidence, retryable: false);
        }

        return BscVerificationResult::of($outcome, $evidence, retryable: false);
    }

    private function claim(
        Payment $payment,
        PaymentBscDetail $detail,
        BscTransferEvidence $evidence,
        int $submissionVersion,
    ): BscVerificationResult {
        try {
            $outcome = DB::transaction(function () use ($payment, $evidence, $submissionVersion): BscVerificationOutcome {
                $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
                $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
                $lockedDetail = PaymentBscDetail::query()->whereKey($locked->id)->lockForUpdate()->firstOrFail();

                if ($locked->status === PaymentStatus::Paid) {
                    return BscVerificationOutcome::AlreadyPaid;
                }
                if ((int) $lockedDetail->tx_submission_version !== $submissionVersion) {
                    return BscVerificationOutcome::StaleSubmission;
                }
                if ($locked->status !== PaymentStatus::Pending || $order->order_status !== OrderStatus::PendingPayment) {
                    $existingLateEvidence = BscPaymentTransferEvidence::query()
                        ->where('tx_hash', $evidence->txHash)
                        ->where('payment_id', $locked->id)
                        ->lockForUpdate()
                        ->first();
                    if ($existingLateEvidence !== null) {
                        $existingLateEvidence->update([
                            'verification_status' => PaymentBscDetail::STATUS_MANUAL_REVIEW,
                            'last_verified_at' => now(),
                        ]);
                        $lockedDetail->update([
                            'verification_status' => PaymentBscDetail::STATUS_MANUAL_REVIEW,
                            'last_verified_at' => now(),
                            'evidence_payload' => [
                                'reason' => 'transfer_found_after_order_lifecycle_closed',
                                ...$evidence->sanitizedPayload(),
                            ],
                        ]);
                        $this->ensureManualFulfillmentReview(
                            payment: $locked,
                            reason: ManualFulfillmentReview::REASON_BSC_MANUAL_REVIEW,
                            providerReference: $evidence->txHash,
                        );

                        return BscVerificationOutcome::ManualReview;
                    }

                    return BscVerificationOutcome::OrderNotPending;
                }
                $existing = BscPaymentTransferEvidence::query()
                    ->where('tx_hash', $evidence->txHash)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null && (string) $existing->payment_id !== (string) $locked->id) {
                    $lockedDetail->update([
                        'verification_status' => PaymentBscDetail::STATUS_TRANSACTION_ALREADY_USED,
                        'last_verified_at' => now(),
                        'current_tx_hash' => $evidence->txHash,
                        'evidence_payload' => [
                            'reason' => 'transaction_already_used',
                            'tx_hash' => $evidence->txHash,
                        ],
                    ]);
                    $this->ensureManualFulfillmentReview(
                        payment: $locked,
                        reason: ManualFulfillmentReview::REASON_BSC_TRANSACTION_ALREADY_USED,
                        providerReference: $evidence->txHash,
                    );

                    return BscVerificationOutcome::TransactionAlreadyUsed;
                }

                if ($existing === null) {
                    BscPaymentTransferEvidence::query()->create($this->evidenceAttributes($locked->id, $evidence, PaymentBscDetail::STATUS_CONFIRMED));
                } else {
                    $existing->update([
                        'verification_status' => PaymentBscDetail::STATUS_CONFIRMED,
                        'confirmations' => $evidence->confirmations,
                        'last_verified_at' => now(),
                    ]);
                }

                $locked->update([
                    'provider_reference' => $evidence->txHash,
                    'provider_payload' => $evidence->sanitizedPayload(),
                ]);
                $lockedDetail->update([
                    'verification_status' => PaymentBscDetail::STATUS_CONFIRMED,
                    'last_verified_at' => now(),
                    'current_tx_hash' => $evidence->txHash,
                    'current_confirmations' => $evidence->confirmations,
                    'current_block_number' => $evidence->blockNumber,
                    'current_from_address' => $evidence->fromAddress,
                    'evidence_payload' => $evidence->sanitizedPayload(),
                ]);

                $this->orders->applyPaymentPaid($locked);

                $receivedAt = now();
                PaymentEvent::create([
                    'payment_id' => $locked->id,
                    'provider' => 'bsc',
                    'event_type' => PaymentEventType::Reconciliation,
                    'merchant_ref' => $locked->merchant_ref,
                    'provider_reference' => $evidence->txHash,
                    'provider_status' => 'CONFIRMED',
                    'raw_payload' => $evidence->sanitizedPayload(),
                    'idempotency_key' => $this->eventIdempotencyKey($evidence->txHash),
                    'received_at' => $receivedAt,
                    'last_received_at' => $receivedAt,
                    'received_count' => 1,
                    'processed_at' => $receivedAt,
                ]);

                return BscVerificationOutcome::Confirmed;
            }, 5);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $existing = BscPaymentTransferEvidence::query()->where('tx_hash', $evidence->txHash)->first();
            if ($existing !== null && (string) $existing->payment_id === (string) $payment->id
                && Payment::query()->whereKey($payment->id)->where('status', PaymentStatus::Paid->value)->exists()) {
                return BscVerificationResult::of(BscVerificationOutcome::AlreadyPaid, $evidence, retryable: false);
            }

            $this->ensureManualFulfillmentReview(
                payment: $payment,
                reason: ManualFulfillmentReview::REASON_BSC_TRANSACTION_ALREADY_USED,
                providerReference: $evidence->txHash,
            );

            return BscVerificationResult::of(BscVerificationOutcome::TransactionAlreadyUsed, $evidence, retryable: false);
        }

        return BscVerificationResult::of($outcome, $evidence, retryable: false);
    }

    private function guard(Payment $payment): ?BscVerificationResult
    {
        if (! $this->enabled()) {
            return BscVerificationResult::of(BscVerificationOutcome::NotConfigured, retryable: false);
        }
        if ($payment->provider !== 'manual' || $payment->method !== 'USDT_BEP20') {
            return BscVerificationResult::of(BscVerificationOutcome::OrderNotPending, retryable: false);
        }
        if ($payment->status === PaymentStatus::Paid) {
            return BscVerificationResult::of(BscVerificationOutcome::AlreadyPaid, retryable: false);
        }
        if ($payment->status !== PaymentStatus::Pending || $payment->order?->order_status !== OrderStatus::PendingPayment) {
            return BscVerificationResult::of(BscVerificationOutcome::OrderNotPending, retryable: false);
        }

        return null;
    }

    private function mismatch(
        Payment $payment,
        PaymentBscDetail $detail,
        int $submissionVersion,
        ?string $hash,
        ?BscTransferEvidence $evidence,
        string $reason,
    ): BscVerificationResult {
        return $this->flagForManualReview(
            payment: $payment,
            detail: $detail,
            submissionVersion: $submissionVersion,
            outcome: BscVerificationOutcome::Mismatch,
            detailStatus: PaymentBscDetail::STATUS_MISMATCH,
            reason: ManualFulfillmentReview::REASON_BSC_MISMATCH,
            hash: $hash,
            evidence: $evidence,
            metadata: ['reason' => $reason],
        );
    }

    /**
     * Persist the BSC evidence state and create the one review task that an
     * owner can act on. The payment-level unique key makes retries and
     * reconciliation idempotent while the submission-version predicate keeps
     * stale verification work from creating a review for a newer hash.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function flagForManualReview(
        Payment $payment,
        PaymentBscDetail $detail,
        int $submissionVersion,
        BscVerificationOutcome $outcome,
        string $detailStatus,
        string $reason,
        ?string $hash,
        ?BscTransferEvidence $evidence,
        array $metadata = [],
    ): BscVerificationResult {
        if ($evidence !== null) {
            $this->persistEvidence($payment, $evidence, $detailStatus, $submissionVersion);
        }

        $persisted = $this->persistStatus(
            payment: $payment,
            detail: $detail,
            status: $detailStatus,
            submissionVersion: $submissionVersion,
            hash: $hash,
            metadata: $metadata,
            evidence: $evidence,
        );
        if ($persisted) {
            $this->ensureManualFulfillmentReview(
                payment: $payment,
                reason: $reason,
                providerReference: $evidence?->txHash ?? $hash,
            );
        }

        return BscVerificationResult::of($outcome, $evidence, retryable: false);
    }

    private function ensureManualFulfillmentReview(
        Payment $payment,
        string $reason,
        ?string $providerReference = null,
    ): ?ManualFulfillmentReview {
        $payment->loadMissing('order');
        $providerReference ??= $payment->provider_reference ?: $payment->merchant_ref;

        try {
            return DB::transaction(
                fn (): ManualFulfillmentReview => ManualFulfillmentReview::query()->firstOrCreate(
                    ['payment_id' => $payment->id],
                    [
                        'order_id' => $payment->order_id,
                        'amount' => $payment->provider_total_payment ?? $payment->amount,
                        'currency' => $payment->currency,
                        'reason' => $reason,
                        'state' => ManualFulfillmentReview::STATE_PENDING,
                        'provider_reference' => $providerReference,
                    ],
                ),
                attempts: 5,
            );
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            return ManualFulfillmentReview::query()->where('payment_id', $payment->id)->first();
        }
    }

    private function evidenceBelongsToAnotherPayment(Payment $payment, BscTransferEvidence $evidence): bool
    {
        return BscPaymentTransferEvidence::query()
            ->where('tx_hash', $evidence->txHash)
            ->where('payment_id', '!=', $payment->id)
            ->exists();
    }

    private function submissionIsCurrent(string $paymentId, int $submissionVersion): bool
    {
        return PaymentBscDetail::query()
            ->whereKey($paymentId)
            ->where('tx_submission_version', $submissionVersion)
            ->exists();
    }

    private function staleIfSubmissionChanged(
        PaymentBscDetail $detail,
        int $submissionVersion,
        BscVerificationResult $result,
    ): BscVerificationResult {
        return $this->submissionIsCurrent($detail->payment_id, $submissionVersion)
            ? $result
            : BscVerificationResult::of(BscVerificationOutcome::StaleSubmission, retryable: false);
    }

    private function persistEvidence(
        Payment $payment,
        BscTransferEvidence $evidence,
        string $status,
        int $submissionVersion,
    ): ?BscPaymentTransferEvidence {
        try {
            return DB::transaction(function () use ($payment, $evidence, $status, $submissionVersion): ?BscPaymentTransferEvidence {
                $detail = PaymentBscDetail::query()
                    ->whereKey($payment->id)
                    ->lockForUpdate()
                    ->first();
                if ($detail === null || (int) $detail->tx_submission_version !== $submissionVersion) {
                    return null;
                }

                $existing = BscPaymentTransferEvidence::query()
                    ->where('tx_hash', $evidence->txHash)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    if ((string) $existing->payment_id === (string) $payment->id) {
                        $existing->update([
                            'verification_status' => $status,
                            'confirmations' => $evidence->confirmations,
                            'last_verified_at' => now(),
                        ]);
                    }

                    return $existing;
                }

                return BscPaymentTransferEvidence::query()->create($this->evidenceAttributes($payment->id, $evidence, $status));
            }, 5);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            return BscPaymentTransferEvidence::query()->where('tx_hash', $evidence->txHash)->first();
        }
    }

    /** @return array<string, mixed> */
    private function evidenceAttributes(string $paymentId, BscTransferEvidence $evidence, string $status): array
    {
        return [
            'payment_id' => $paymentId,
            'tx_hash' => $evidence->txHash,
            'token_contract' => $evidence->tokenContract,
            'receiver_address' => $evidence->receiverAddress,
            'from_address' => $evidence->fromAddress,
            'amount_units' => $evidence->amountUnits,
            'block_number' => $evidence->blockNumber,
            'block_hash' => $evidence->blockHash,
            'block_timestamp' => $evidence->blockTimestamp,
            'receipt_status' => $evidence->receiptStatus,
            'log_index' => $evidence->logIndex,
            'confirmations' => $evidence->confirmations,
            'verification_status' => $status,
            'evidence_payload' => $evidence->sanitizedPayload(),
            'first_seen_at' => now(),
            'last_verified_at' => now(),
        ];
    }

    /** @param array<string, mixed> $metadata */
    private function persistStatus(
        Payment $payment,
        PaymentBscDetail $detail,
        string $status,
        int $submissionVersion,
        ?string $hash,
        array $metadata = [],
        ?BscTransferEvidence $evidence = null,
    ): bool {
        $payload = $metadata;
        if ($evidence !== null) {
            $payload = [...$payload, ...$evidence->sanitizedPayload()];
        }

        $updates = [
            'verification_status' => $status,
            'last_verified_at' => now(),
            'evidence_payload' => $payload === [] ? null : $payload,
        ];
        if ($hash !== null) {
            $updates['current_tx_hash'] = $hash;
        }
        if ($evidence !== null) {
            $updates['current_confirmations'] = $evidence->confirmations;
            $updates['current_block_number'] = $evidence->blockNumber;
            $updates['current_from_address'] = $evidence->fromAddress;
        }

        $query = PaymentBscDetail::query()
            ->whereKey($detail->payment_id)
            ->where('tx_submission_version', $submissionVersion);

        return $query->update($updates) === 1;
    }

    private function deadline(Payment $payment, ?Order $order = null): ?Carbon
    {
        $order ??= $payment->order;

        return $payment->expired_at ?? $order?->expires_at;
    }

    private function automaticWindowClosed(Payment $payment, PaymentBscDetail $detail): bool
    {
        $deadline = $this->deadline($payment, $payment->order);
        if ($deadline === null) {
            return true;
        }

        if ($this->confirmationGrace->isActive($payment, $payment->order)) {
            return false;
        }

        return $deadline->isPast();
    }

    private function minimumConfirmations(?PaymentBscDetail $detail = null): int
    {
        return max(
            ManualPaymentSetting::DEFAULT_BSC_MIN_CONFIRMATIONS,
            min(
                100,
                (int) ($detail?->required_confirmations ?: ManualPaymentSetting::DEFAULT_BSC_MIN_CONFIRMATIONS),
            ),
        );
    }

    private function confirmationGraceMinutes(?PaymentBscDetail $detail = null): int
    {
        return max(
            0,
            min(
                60,
                (int) ($detail?->confirmation_grace_minutes ?? ManualPaymentSetting::DEFAULT_BSC_CONFIRMATION_GRACE_MINUTES),
            ),
        );
    }

    private function requiresFinalizedBlock(?PaymentBscDetail $detail = null): bool
    {
        return (bool) ($detail?->requires_finalized_block ?? false);
    }

    private function blockNumber(mixed $value): ?int
    {
        $value = strtolower(trim((string) $value));
        if (preg_match('/^0x[0-9a-f]+$/', $value) !== 1) {
            return null;
        }

        $hex = ltrim(substr($value, 2), '0');
        if ($hex === '') {
            return 0;
        }
        if (strlen($hex) > 16) {
            return null;
        }

        return hexdec($hex);
    }

    private function blockTimestamp(mixed $value): ?Carbon
    {
        $seconds = $this->blockNumber($value);
        if ($seconds === null || $seconds < 0) {
            return null;
        }

        return Carbon::createFromTimestampUTC($seconds);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[0] ?? ''), ['23000', '23505'], true)
            || str_contains(strtolower($exception->getMessage()), 'unique');
    }

    private function eventIdempotencyKey(string $transactionHash): string
    {
        return substr(hash('sha256', strtolower($transactionHash)), 0, 64);
    }

    private function validConfiguredSnapshot(PaymentBscDetail $detail): bool
    {
        return (int) config('services.bsc.chain_id', 56) === 56
            && strtolower(trim((string) config('services.bsc.usdt_contract'))) === BscReadiness::APPROVED_USDT_CONTRACT
            && (int) $detail->chain_id === 56
            && strtolower((string) $detail->token_contract) === BscReadiness::APPROVED_USDT_CONTRACT
            && (int) $detail->token_decimals >= 6;
    }
}
