<?php

namespace App\Services\UsdtTopUps;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerUsdtBalanceEntry;
use App\Models\CustomerUsdtDepositReceiver;
use App\Models\CustomerUsdtFundingReservation;
use App\Models\CustomerUsdtTopUp;
use App\Models\UsdtBscRailInvoice;
use App\Models\UsdtTopUpReviewRequest;
use App\Models\User;
use App\Services\Bsc\BscChainFacts;
use App\Services\Bsc\BscFinalReceiptInspector;
use App\Services\Bsc\BscReadiness;
use App\Services\Bsc\BscTokenAmount;
use App\Services\Bsc\BscTransactionHash;
use App\Services\Bsc\BscTransferEvidence;
use App\Services\Bsc\BscVerificationRpcClientInterface;
use App\Services\MoneyReceipts\ExternalMoneyReceiptClaims;
use App\Services\MoneyReceipts\MoneyReceiptIdentity;
use App\Services\MoneyReceipts\MoneyReceiptParentClaim;
use App\Services\MoneyReceipts\MoneyReceiptTarget;
use App\Services\MoneyReceipts\VerifiedMoneyReceipt;
use App\Services\StoreBalance\BalanceAdjustmentPasswordConfirmation;
use App\Services\Telegram\UsdtTopUpOutbound;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\VerifiedUsdtFunding;
use App\Services\UsdtFunding\CustomerUsdtFundingReservationService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** One Owner-confirmed resolution backed by freshly verified external money. */
final class UsdtTopUpReview
{
    public function __construct(
        private readonly BscVerificationRpcClientInterface $rpc,
        private readonly BscFinalReceiptInspector $inspector,
        private readonly BscTokenAmount $amounts,
        private readonly BalanceAdjustmentPasswordConfirmation $passwords,
        private readonly CustomerUsdtBalanceLedger $ledger,
        private readonly ExternalMoneyReceiptClaims $receipts,
        private readonly CustomerUsdtFundingReservationService $reservations,
        private readonly UsdtTopUpOutbound $outbound,
    ) {}

    /** Trusted authenticated Customer adapter supplies actor; Owner cannot choose a beneficiary. */
    public function authorizeExternalReturn(Customer $actor, string $topUpId, string $amountDecimal,
        string $destinationAddress, string $nonce): object
    {
        $this->outsideTransaction();
        $amount = UsdtMicroAmount::fromDecimalString($amountDecimal);
        $destinationAddress = strtolower($destinationAddress);
        if (preg_match('/\A0x[0-9a-f]{40}\z/', $destinationAddress) !== 1
            || $destinationAddress === '0x'.str_repeat('0', 40) || preg_match('/\A[a-zA-Z0-9_-]{16,64}\z/', $nonce) !== 1) {
            throw new DomainException('Invalid Customer return destination or nonce.');
        }

        return DB::transaction(function () use ($actor, $topUpId, $amount, $destinationAddress, $nonce): object {
            Customer::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $target = CustomerUsdtTopUp::query()->whereKey($topUpId)->where('customer_id', $actor->id)->lockForUpdate()->first();
            if ($target === null) {
                throw new AuthorizationException('Customer does not own this USDT target.');
            }
            $existing = DB::table('usdt_top_up_external_return_authorizations')->where('customer_id', $actor->id)->where('nonce', $nonce)->first();
            if ($existing !== null) {
                if ($existing->top_up_id !== $topUpId || (int) $existing->amount_micro !== $amount->units()
                    || $existing->destination_address !== $destinationAddress) {
                    throw new DomainException('Customer external-return authorization nonce replay differs.');
                }

                return $existing;
            }
            if (DB::table('customer_usdt_top_up_resolutions')->where('top_up_id', $topUpId)->exists()) {
                throw new DomainException('USDT target already resolved.');
            }
            $invoice = $this->invoice($target);
            if ($destinationAddress === $invoice->receiver_address) {
                throw new DomainException('External return must leave the custody receiver.');
            }
            $id = (string) Str::uuid();
            DB::table('usdt_top_up_external_return_authorizations')->insert(['id' => $id, 'top_up_id' => $topUpId,
                'customer_id' => $actor->id, 'amount_micro' => $amount->units(), 'chain_id' => $invoice->chain_id,
                'token_contract' => $invoice->token_contract, 'destination_address' => $destinationAddress,
                'nonce' => $nonce, 'created_at' => now()]);

            return DB::table('usdt_top_up_external_return_authorizations')->where('id', $id)->firstOrFail();
        }, 5);
    }

    public function request(User $actor, string $topUpId, string $decision, string $amountDecimal,
        string $transactionHash, string $reason, ?string $refundTransactionHash = null,
        ?string $refundAuthorizationId = null): UsdtTopUpReviewRequest
    {
        $this->outsideTransaction();
        $actor = $this->owner($actor);
        if (! in_array($decision, ['settled', 'resolved_by_compensation', 'externally_refunded'], true)
            || trim($reason) === '' || mb_strlen($reason) > 1000) {
            throw new DomainException('Invalid USDT review decision or reason.');
        }
        $amount = UsdtMicroAmount::fromDecimalString($amountDecimal);
        $hash = $this->hash($transactionHash);
        $refundAddress = null;
        if ($decision === 'externally_refunded') {
            $refundTransactionHash = $this->hash($refundTransactionHash ?? '');
            $authorization = DB::table('usdt_top_up_external_return_authorizations')->where('id', $refundAuthorizationId)
                ->where('top_up_id', $topUpId)->where('amount_micro', $amount->units())->first();
            if ($authorization === null) {
                throw new DomainException('Authenticated Customer external-return authorization required.');
            }
            $refundAddress = $authorization->destination_address;
            if (preg_match('/\A0x[0-9a-f]{40}\z/', $refundAddress) !== 1 || $hash === $refundTransactionHash) {
                throw new DomainException('External refund requires a distinct outbound transaction and sender address.');
            }
        } elseif ($refundTransactionHash !== null || $refundAuthorizationId !== null) {
            throw new DomainException('Outbound evidence is only valid for external refund.');
        }
        $target = CustomerUsdtTopUp::query()->findOrFail($topUpId);
        $invoice = $this->invoice($target);
        if ($decision !== 'externally_refunded' && $amount->units() <= (int) $target->fee_micro) {
            throw new DomainException('Actual deposit does not cover the original application fee.');
        }
        $work = DB::table('customer_usdt_top_up_settlement_work')->where('top_up_id', $target->id)->firstOrFail();
        $checkpoint = DB::table('usdt_bsc_scan_checkpoints')->where('receiver_id', $target->receiver_id)->first();
        $evidence = $this->inspect($invoice, $hash, $amount, $invoice->receiver_address);
        $this->attribution($target, $evidence);
        $refund = null;
        if ($decision === 'externally_refunded') {
            if ((int) $authorization->customer_id !== (int) $target->customer_id
                || (int) $authorization->chain_id !== $invoice->chain_id || $authorization->token_contract !== $invoice->token_contract) {
                throw new DomainException('External-return authorization does not match the immutable target.');
            }
            $refund = $this->inspect($invoice, $refundTransactionHash, $amount, $refundAddress,
                $evidence->blockTimestamp->timestamp);
            if ($refund->fromAddress !== $invoice->receiver_address) {
                throw new DomainException('External refund must originate from the permanently bound receiver.');
            }
        }
        $attributes = ['id' => (string) Str::uuid(), 'top_up_id' => $target->id,
            'owner_user_id' => $actor->id, 'decision' => $decision, 'amount_micro' => $amount->units(),
            'transaction_hash' => $hash, 'refund_transaction_hash' => $refundTransactionHash,
            'refund_address' => $refundAddress, 'refund_authorization_id' => $refundAuthorizationId, 'reason' => trim($reason), 'version' => 1,
            'work_version' => (int) $work->version, 'checkpoint_version' => $checkpoint?->version,
            'target_fingerprint' => $target->request_fingerprint,
            'session_binding' => hash('sha256', request()->session()->getId()),
            'credential_binding' => hash('sha256', $actor->password),
            'chain_evidence' => $evidence->sanitizedPayload(), 'refund_evidence' => $refund?->sanitizedPayload(),
            'created_at' => now(), 'expires_at' => now()->addMinutes(5)];
        $attributes['fingerprint'] = hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $target, $attributes): UsdtTopUpReviewRequest {
            $this->owner($actor);
            Customer::query()->whereKey($target->customer_id)->lockForUpdate()->firstOrFail();
            CustomerUsdtTopUp::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $receiver = CustomerUsdtDepositReceiver::query()->whereKey($target->receiver_id)->lockForUpdate()->firstOrFail();
            if ((int) $receiver->customer_id !== (int) $target->customer_id || $receiver->verified_at === null
                || $receiver->address_normalized !== $target->payment_snapshot['receiver_address']) {
                throw new DomainException('Permanent receiver binding unavailable.');
            }
            $work = DB::table('customer_usdt_top_up_settlement_work')->where('top_up_id', $target->id)->lockForUpdate()->firstOrFail();
            $reservation = CustomerUsdtFundingReservation::query()->whereKey($target->reservation_id)->lockForUpdate()->firstOrFail();
            $checkpoint = DB::table('usdt_bsc_scan_checkpoints')->where('receiver_id', $target->receiver_id)->lockForUpdate()->first();
            if ($reservation->resolved_at !== null || (int) $work->version !== $attributes['work_version']
                || ($checkpoint?->version) != $attributes['checkpoint_version'] || $checkpoint?->review_reason !== null
                || DB::table('customer_usdt_top_up_resolutions')->where('top_up_id', $target->id)->exists()) {
                throw new DomainException('USDT target resolved or evidence changed; request a fresh preview.');
            }
            $preview = new UsdtTopUpReviewRequest;
            $preview->forceFill($attributes)->save();

            return $preview;
        }, 5);
    }

    public function confirm(User $actor, string $requestId, string $fingerprint, int $version, string $password): object
    {
        $this->outsideTransaction();
        $preview = UsdtTopUpReviewRequest::query()->findOrFail($requestId);
        $this->binding($actor, $preview, $fingerprint, $version);
        $existing = DB::table('customer_usdt_top_up_resolutions')->where('review_request_id', $requestId)->first();
        if ($existing !== null) {
            return $existing;
        }
        $target = CustomerUsdtTopUp::query()->findOrFail($preview->top_up_id);
        $invoice = $this->invoice($target);
        $amount = UsdtMicroAmount::fromUnits($preview->amount_micro);
        // Fresh RPC before KDF/money locks, never in retry closures.
        $evidence = $this->inspect($invoice, $preview->transaction_hash, $amount, $invoice->receiver_address);
        $this->attribution($target, $evidence);
        $refund = $preview->decision === 'externally_refunded'
            ? $this->inspect($invoice, $preview->refund_transaction_hash, $amount, $preview->refund_address,
                $evidence->blockTimestamp->timestamp) : null;
        if (! $this->matchesPreviewEvidence($evidence, $preview->chain_evidence)
            || ! $this->matchesPreviewEvidence($refund, $preview->refund_evidence)) {
            throw new DomainException('External evidence changed; request a fresh preview.');
        }
        $authority = $this->passwords->verify($this->owner($actor), $password, $preview);
        try {
            return DB::transaction(function () use ($actor, $preview, $fingerprint, $version, $authority, $target, $invoice, $evidence, $refund, $amount): object {
                $freshActor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $this->binding($freshActor, $preview, $fingerprint, $version);
                $authority->assertMatches($preview);
                $customer = Customer::query()->whereKey($target->customer_id)->lockForUpdate()->firstOrFail();
                $this->ledger->lockForTransaction($customer);
                $lockedTarget = CustomerUsdtTopUp::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
                $receiver = CustomerUsdtDepositReceiver::query()->whereKey($target->receiver_id)->lockForUpdate()->firstOrFail();
                $work = DB::table('customer_usdt_top_up_settlement_work')->where('top_up_id', $target->id)->lockForUpdate()->firstOrFail();
                $reservation = CustomerUsdtFundingReservation::query()->whereKey($target->reservation_id)->lockForUpdate()->firstOrFail();
                $checkpoint = DB::table('usdt_bsc_scan_checkpoints')->where('receiver_id', $target->receiver_id)->lockForUpdate()->first();
                $existing = DB::table('customer_usdt_top_up_resolutions')->where('top_up_id', $target->id)->first();
                if ($existing !== null) {
                    if ($existing->review_request_id === $preview->id) {
                        $this->passwords->consume($freshActor, $authority->token);

                        return $existing;
                    }
                    throw new DomainException('USDT target already has an economic resolution.');
                }
                if ($reservation->resolved_at !== null || (int) $work->version !== $preview->work_version
                    || ($checkpoint?->version) != $preview->checkpoint_version || $checkpoint?->review_reason !== null
                    || $lockedTarget->request_fingerprint !== $preview->target_fingerprint
                    || (int) $receiver->customer_id !== (int) $customer->id || $receiver->verified_at === null
                    || $receiver->address_normalized !== $invoice->receiver_address) {
                    throw new DomainException('USDT review evidence or receiver changed.');
                }
                $this->attribution($lockedTarget, $evidence);
                $this->passwords->consume($freshActor, $authority->token);
                DB::table('customer_usdt_top_up_inbox')->insertOrIgnore(['top_up_id' => $target->id,
                    'tx_hash' => $evidence->txHash, 'state' => 'review', 'attempts' => 0, 'next_check_at' => now(), 'created_at' => now()]);
                $inbox = DB::table('customer_usdt_top_up_inbox')->where('top_up_id', $target->id)->where('tx_hash', $evidence->txHash)->firstOrFail();
                $evidenceId = DB::table('customer_usdt_top_up_evidence')->insertGetId(['top_up_id' => $target->id,
                    'inbox_id' => $inbox->id, 'version' => (int) $work->evidence_version + 1, 'status' => 'verified',
                    'reason' => 'owner_verified_resolution', 'chain_evidence' => json_encode($evidence->sanitizedPayload(), JSON_THROW_ON_ERROR), 'created_at' => now()]);
                $receiptId = $this->claim($customer, $target, $invoice, $evidence);
                $refundReceiptId = $refund === null ? null : $this->claim($customer, $target, $invoice, $refund, true);
                $entry = null;
                if ($refund === null) {
                    // Revised amount is actual received principal less immutable application fee.
                    $principal = UsdtMicroAmount::fromUnits($amount->units() - (int) $target->fee_micro);
                    $result = $this->ledger->creditVerifiedTopUp($customer,
                        new VerifiedUsdtFunding($customer->id, $principal, $target->id, (string) $receiptId, (string) $evidenceId, $preview->id, $authority),
                        'usdt-top-up:'.$target->id);
                    $entry = CustomerUsdtBalanceEntry::query()->findOrFail($result->entryId);
                }
                $late = $target->expires_at->isPast() || $evidence->blockTimestamp->timestamp > $invoice->expires_at_epoch;
                DB::table('customer_usdt_top_up_resolutions')->insert(['top_up_id' => $target->id,
                    'kind' => $preview->decision, 'receipt_id' => $receiptId, 'refund_receipt_id' => $refundReceiptId,
                    'entry_id' => $entry?->id, 'evidence_id' => $evidenceId, 'review_request_id' => $preview->id,
                    'resolved_amount_micro' => $amount->units(), 'late' => $late, 'created_at' => now()]);
                if ($entry !== null) {
                    if ($target->fee_micro > 0) {
                        DB::table('customer_usdt_top_up_fee_events')->insert(['top_up_id' => $target->id,
                            'receipt_id' => $receiptId, 'amount_micro' => $target->fee_micro, 'created_at' => now()]);
                    }
                    $this->reservations->markReviewedSettled($customer, $target->reservation_id, $entry->id, $target->id, $preview->id);
                    $this->outbound->recordReceipt($target, $entry, $late);
                } else {
                    $reservation->forceFill(['state' => 'released', 'resolved_at' => now(),
                        'resolution_kind' => 'externally_returned', 'resolution_reference' => $preview->id,
                        'resolution_actor' => 'owner:'.$freshActor->id])->save();
                }
                DB::table('customer_usdt_top_up_settlement_work')->where('top_up_id', $target->id)->update([
                    'state' => $preview->decision, 'version' => (int) $work->version + 1,
                    'evidence_version' => (int) $work->evidence_version + 1, 'lease_token' => null, 'lease_until' => null,
                    'last_error' => null, 'next_check_at' => now()->addMinute(), 'updated_at' => now()]);
                AuditLog::create(['user_id' => $freshActor->id, 'action' => 'usdt_top_up.review_resolved',
                    'subject_type' => CustomerUsdtTopUp::class, 'subject_id' => $target->id,
                    'new_values' => ['request_id' => $preview->id, 'decision' => $preview->decision,
                        'amount_micro' => (string) $amount->units(), 'entry_id' => $entry?->id, 'receipt_id' => $receiptId]]);

                return DB::table('customer_usdt_top_up_resolutions')->where('top_up_id', $target->id)->firstOrFail();
            }, 5);
        } catch (Throwable $exception) {
            $this->passwords->release($actor, $authority->token);
            throw $exception;
        }
    }

    private function inspect(UsdtBscRailInvoice $invoice, string $hash, UsdtMicroAmount $amount,
        string $receiver, ?int $startsAt = null): BscTransferEvidence
    {
        if ($this->rpc->chainId() !== $invoice->chain_id || $this->rpc->tokenDecimals($invoice->token_contract) !== $invoice->token_decimals) {
            throw new DomainException('RPC network/token mismatch.');
        }
        $receipt = $this->rpc->transactionReceipt($hash);
        $number = BscChainFacts::quantity($receipt['blockNumber'] ?? null);
        if ($number === null) {
            throw new DomainException('Authentic mined receipt required.');
        }
        $block = $this->rpc->blockByNumber($number);
        $head = $this->rpc->blockByNumber('latest');
        $finalized = $this->rpc->blockByNumber('finalized');
        $final = BscChainFacts::block($finalized);
        if ($final === null || BscChainFacts::block($this->rpc->blockByNumber($final['number'])) !== $final) {
            throw new DomainException('Canonical finality required.');
        }
        $inspection = $this->inspector->inspect($hash, $receipt, $block, $head, $finalized,
            $invoice->token_contract, $receiver, $this->amounts->expectedTokenUnits($amount->units(), $invoice->token_decimals),
            $invoice->token_decimals, $startsAt ?? $invoice->starts_at_epoch, PHP_INT_MAX, $invoice->minimum_confirmations);
        if (! $inspection->verified() || $inspection->evidence === null) {
            throw new DomainException('USDT review requires one authentic exactly representable finalized transfer: '.$inspection->reason);
        }

        return $inspection->evidence;
    }

    /** Confirmation count is a moving chain fact; transaction identity/finality facts remain bound. */
    private function matchesPreviewEvidence(?BscTransferEvidence $evidence, ?array $previewEvidence): bool
    {
        if ($evidence === null || $previewEvidence === null) {
            return $evidence === null && $previewEvidence === null;
        }

        $current = $evidence->sanitizedPayload();
        unset($current['confirmations'], $previewEvidence['confirmations']);

        return $current === $previewEvidence;
    }

    private function invoice(CustomerUsdtTopUp $target): UsdtBscRailInvoice
    {
        $invoice = UsdtBscRailInvoice::query()->where('reference', $target->reference)->firstOrFail();
        $snapshot = $target->payment_snapshot;
        foreach (['chain_id', 'token_contract', 'token_decimals', 'custody_namespace', 'minimum_confirmations'] as $field) {
            if ($snapshot[$field] !== $invoice->$field) {
                throw new DomainException('Immutable invoice snapshot mismatch.');
            }
        }
        if ($invoice->receiver_address !== $snapshot['receiver_address'] || $invoice->customer_id !== (int) $target->customer_id
            || $invoice->receiver_id !== (int) $target->receiver_id || $invoice->payable_micro !== (int) $target->payable_micro
            || $invoice->chain_id !== 56 || $invoice->token_contract !== BscReadiness::APPROVED_USDT_CONTRACT
            || $invoice->token_decimals !== 18 || ! $invoice->requires_finalized_block) {
            throw new DomainException('USDT review requires a known immutable BSC invoice.');
        }

        return $invoice;
    }

    private function attribution(CustomerUsdtTopUp $target, BscTransferEvidence $evidence): void
    {
        if (CustomerUsdtTopUp::query()->where('receiver_id', $target->receiver_id)->where('intent_id', '>', $target->intent_id)
            ->where('created_at', '<=', $evidence->blockTimestamp->copy()->setTimezone(config('app.timezone')))->exists()) {
            throw new DomainException('Receipt belongs to a later receiver payment window.');
        }
    }

    private function claim(Customer $customer, CustomerUsdtTopUp $target, UsdtBscRailInvoice $invoice, BscTransferEvidence $evidence, bool $outbound = false): int
    {
        $payload = $evidence->sanitizedPayload();
        if ($outbound) {
            $payload['direction'] = 'external_refund';
        }
        $receipt = new VerifiedMoneyReceipt(MoneyReceiptIdentity::bscLog($invoice->chain_id, $evidence->txHash, $evidence->logIndex),
            'customer:'.$customer->id, 'USDT', $evidence->amountUnits, $invoice->token_decimals,
            MoneyReceiptTarget::customerUsdtTopUp($target->id), $payload,
            parentClaim: MoneyReceiptParentClaim::for($invoice->chain_id, $evidence->txHash));
        if (! $this->receipts->claim($receipt)->allowsMoneyEffect()) {
            throw new DomainException('External receipt is claimed for another economic target.');
        }

        return (int) DB::table('external_money_receipts')->where('canonical_key', $receipt->canonicalKey())->value('id');
    }

    private function binding(User $actor, UsdtTopUpReviewRequest $preview, string $fingerprint, int $version): void
    {
        $actor = $this->owner($actor);
        if ((int) $preview->owner_user_id !== (int) $actor->id || ! hash_equals($preview->fingerprint, $fingerprint)
            || $preview->version !== $version || now()->gte($preview->expires_at)
            || $preview->credential_binding !== hash('sha256', $actor->password)
            || $preview->session_binding !== hash('sha256', request()->session()->getId())) {
            throw new AuthorizationException('USDT review confirmation binding expired or changed.');
        }
    }

    private function owner(User $actor): User
    {
        $fresh = $actor->fresh();
        if ($fresh === null || (int) Auth::id() !== (int) $actor->id || $fresh->role !== UserRole::Owner
            || ! $fresh->canAccessAdmin() || ! request()->hasSession()) {
            throw new AuthorizationException('Authenticated Owner session required.');
        }

        return $fresh;
    }

    private function hash(string $hash): string
    {
        $normalized = BscTransactionHash::normalize($hash);
        if ($normalized === null || strlen($hash) !== 66) {
            throw new DomainException('Invalid BSC transaction hash.');
        }

        return $normalized;
    }

    private function outsideTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new DomainException('USDT review network/password work requires an independent transaction.');
        }
    }
}
