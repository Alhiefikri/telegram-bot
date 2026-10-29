<?php

namespace App\Services\UsdtTopUps;

use App\Enums\ExternalMoneyReceiptTargetType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\BscPaymentReceiver;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\UsdtTransferRecoveryRequest;
use App\Models\User;
use App\Services\Bsc\BscChainFacts;
use App\Services\Bsc\BscFinalReceiptInspector;
use App\Services\Bsc\BscReadiness;
use App\Services\Bsc\BscTokenAmount;
use App\Services\Bsc\BscTransferEvidence;
use App\Services\Bsc\BscVerificationRpcClientInterface;
use App\Services\MoneyReceipts\ExternalMoneyReceiptClaims;
use App\Services\MoneyReceipts\MoneyReceiptIdentity;
use App\Services\MoneyReceipts\MoneyReceiptParentClaim;
use App\Services\MoneyReceipts\MoneyReceiptTarget;
use App\Services\MoneyReceipts\VerifiedMoneyReceipt;
use App\Services\StoreBalance\BalanceAdjustmentPasswordConfirmation;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\VerifiedUsdtRecovery;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Owner attribution of a real finalized transfer, without fabricating an invoice or customer confirmation. */
final class UsdtTransferRecovery
{
    public function __construct(
        private readonly BscVerificationRpcClientInterface $rpc,
        private readonly BscFinalReceiptInspector $inspector,
        private readonly BscTokenAmount $amounts,
        private readonly BalanceAdjustmentPasswordConfirmation $passwords,
        private readonly CustomerUsdtBalanceLedger $ledger,
        private readonly ExternalMoneyReceiptClaims $receipts,
        private readonly TelegramOutboundEffectService $effects,
    ) {}

    public function request(User $owner, string $telegramId, int $receiverId, string $decimal, string $hash, string $reason): UsdtTransferRecoveryRequest
    {
        $this->owner($owner);
        if (! preg_match('/^[1-9][0-9]{0,18}$/D', $telegramId) || trim($reason) === '' || mb_strlen($reason) > 1000) {
            throw new DomainException('Exact Telegram ID and recovery reason are required.');
        }
        $customer = Customer::query()->where('telegram_id', $telegramId)->firstOrFail();
        $receiver = BscPaymentReceiver::query()->findOrFail($receiverId);
        $amount = UsdtMicroAmount::fromDecimalString($decimal);
        $hash = strtolower(trim($hash));
        if (! preg_match('/^0x[0-9a-f]{64}$/D', $hash)) {
            throw new DomainException('A BSC transaction hash is required.');
        }
        $evidence = $this->inspect($receiver, $amount, $hash);
        $attributes = [
            'id' => (string) Str::uuid(), 'owner_user_id' => $owner->id, 'customer_id' => $customer->id,
            'receiver_id' => $receiver->id, 'amount_micro' => $amount->units(), 'transaction_hash' => $hash,
            'reason' => trim($reason), 'evidence' => $this->stable($evidence),
            'credential_binding' => hash('sha256', $owner->password),
            'session_binding' => hash('sha256', request()->session()->getId()),
            'expires_at' => now()->addMinutes(5)->toDateTimeString(), 'created_at' => now()->toDateTimeString(),
        ];
        $attributes['fingerprint'] = hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));

        return UsdtTransferRecoveryRequest::query()->create($attributes);
    }

    public function confirm(User $owner, string $id, string $password): object
    {
        $this->owner($owner);
        $preview = UsdtTransferRecoveryRequest::query()->findOrFail($id);
        $this->binding($owner, $preview);
        $existing = DB::table('usdt_transfer_recoveries')->where('request_id', $id)->first();
        if ($existing !== null) {
            return $existing;
        }
        $amount = UsdtMicroAmount::fromUnits($preview->amount_micro);
        $receiver = BscPaymentReceiver::query()->findOrFail($preview->receiver_id);
        $evidence = $this->inspect($receiver, $amount, $preview->transaction_hash);
        if ($this->stable($evidence) !== $preview->evidence) {
            throw new DomainException('Transfer evidence changed; request a fresh preview.');
        }
        $authority = $this->passwords->verify($owner, $password, $preview);
        try {
            return DB::transaction(function () use ($owner, $preview, $amount, $evidence, $authority): object {
                $fresh = User::query()->whereKey($owner->id)->lockForUpdate()->firstOrFail();
                $this->owner($fresh);
                $this->binding($fresh, $preview);
                $customer = Customer::query()->whereKey($preview->customer_id)->lockForUpdate()->firstOrFail();
                $this->ledger->lockForTransaction($customer);
                $receiver = BscPaymentReceiver::query()->whereKey($preview->receiver_id)->lockForUpdate()->firstOrFail();
                if ($receiver->address_normalized !== $evidence->receiverAddress || ! $receiver->isVerified()) {
                    throw new DomainException('Verified receiving address changed.');
                }
                UsdtTransferRecoveryRequest::query()->whereKey($preview->id)->lockForUpdate()->firstOrFail();
                $this->passwords->consume($fresh, $authority->token);
                $existing = DB::table('usdt_transfer_recoveries')->where('request_id', $preview->id)->first();
                if ($existing !== null) {
                    return $existing;
                }
                $receipt = new VerifiedMoneyReceipt(
                    MoneyReceiptIdentity::bscLog(56, $evidence->txHash, $evidence->logIndex),
                    'customer:'.$customer->id, 'USDT', $evidence->amountUnits, 18,
                    new MoneyReceiptTarget(ExternalMoneyReceiptTargetType::CustomerUsdtRecovery, $preview->id),
                    $preview->evidence,
                    parentClaim: MoneyReceiptParentClaim::for(56, $evidence->txHash),
                );
                if (! $this->receipts->claim($receipt)->allowsMoneyEffect()) {
                    throw new DomainException('Transfer is already claimed or held for review.');
                }
                $receiptId = (int) DB::table('external_money_receipts')->where('canonical_key', $receipt->canonicalKey())->value('id');
                $entry = $this->ledger->creditVerifiedRecovery($customer, new VerifiedUsdtRecovery((int) $customer->id, $amount, $preview->id, $receiptId, $authority));
                $result = ['id' => (string) Str::uuid(), 'request_id' => $preview->id, 'receipt_id' => $receiptId, 'entry_id' => $entry->entryId, 'created_at' => now()];
                DB::table('usdt_transfer_recoveries')->insert($result);
                AuditLog::create(['user_id' => $fresh->id, 'action' => 'usdt_transfer.recovered', 'subject_type' => UsdtTransferRecoveryRequest::class,
                    'subject_id' => $preview->id, 'new_values' => ['customer_id' => $customer->id, 'telegram_id' => (string) $customer->telegram_id,
                        'currency' => 'USDT', 'amount_micro' => $amount->units(), 'tx_hash' => $evidence->txHash, 'reason' => $preview->reason,
                        'receipt_id' => $receiptId, 'entry_id' => $entry->entryId]]);
                $message = 'Pemulihan pembayaran: +'.$amount->decimal().' USD. Saldo setelah kredit: '.UsdtMicroAmount::formatUnits($entry->balanceAfterMicro).' USD. TxID: '.$evidence->txHash;
                $this->effects->recordBalanceAdjustmentNotice($preview->id, 'customer', $message, $customer, UsdtTransferRecoveryRequest::class);
                $this->effects->recordBalanceAdjustmentNotice($preview->id, 'owner', 'Telegram '.$customer->telegram_id.': '.$message, $customer, UsdtTransferRecoveryRequest::class);

                return (object) $result;
            }, 5);
        } finally {
            $this->passwords->release($owner, $authority->token);
        }
    }

    private function inspect(BscPaymentReceiver $receiver, UsdtMicroAmount $amount, string $hash): BscTransferEvidence
    {
        if (DB::transactionLevel() !== 0) {
            throw new DomainException('Recovery verification must run outside money transactions.');
        }
        if (! $receiver->isVerified()
            || (int) $receiver->chain_id !== 56 || strtolower($receiver->token_contract) !== BscReadiness::APPROVED_USDT_CONTRACT
            || (int) $receiver->token_decimals !== 18 || $this->rpc->chainId() !== 56
            || $this->rpc->tokenDecimals(BscReadiness::APPROVED_USDT_CONTRACT) !== 18) {
            throw new DomainException('Verified BSC USDT checkout receiver required.');
        }
        $receipt = $this->rpc->transactionReceipt($hash);
        $number = BscChainFacts::quantity($receipt['blockNumber'] ?? null);
        if ($number === null) {
            throw new DomainException('Authentic mined receipt required.');
        }
        $finalized = $this->rpc->blockByNumber('finalized');
        $final = BscChainFacts::block($finalized);
        if ($final === null || BscChainFacts::block($this->rpc->blockByNumber($final['number'])) !== $final) {
            throw new DomainException('Canonical finalized block required.');
        }
        $inspection = $this->inspector->inspect($hash, $receipt, $this->rpc->blockByNumber($number),
            $this->rpc->blockByNumber('latest'), $finalized, BscReadiness::APPROVED_USDT_CONTRACT,
            $receiver->address_normalized, $this->amounts->expectedTokenUnits($amount->units(), 18), 18,
            $receiver->verified_at?->timestamp ?? PHP_INT_MAX, now()->timestamp,
            max(1, (int) ManualPaymentSetting::current()?->bsc_min_confirmations));
        if (! $inspection->verified() || $inspection->evidence === null) {
            throw new DomainException('Recovery requires one exact finalized transfer: '.$inspection->reason);
        }

        return $inspection->evidence;
    }

    private function stable(BscTransferEvidence $evidence): array
    {
        $payload = $evidence->sanitizedPayload();
        unset($payload['confirmations']);

        return $payload;
    }

    private function owner(User $owner): void
    {
        if ($owner->role !== UserRole::Owner || ! $owner->canAccessAdmin() || (int) Auth::id() !== (int) $owner->id || ! request()->hasSession()) {
            throw new DomainException('Authenticated Owner and request session required.');
        }
    }

    private function binding(User $owner, UsdtTransferRecoveryRequest $preview): void
    {
        if ((int) $preview->owner_user_id !== (int) $owner->id || $preview->expires_at->isPast()
            || $preview->credential_binding !== hash('sha256', $owner->password)
            || $preview->session_binding !== hash('sha256', request()->session()->getId())) {
            throw new DomainException('Recovery authorization expired or changed.');
        }
    }
}
