<?php

namespace App\Services\UsdtTopUps;

use App\Exceptions\UsdtReceiverCapacityUnavailable;
use App\Models\Customer;
use App\Models\CustomerUsdtDepositReceiver;
use App\Models\CustomerUsdtTopUp;
use App\Models\ManualPaymentSetting;
use App\Models\UsdtBscRailInvoice;
use App\Services\Bsc\BscCapabilityProbe;
use App\Services\Bsc\BscChainFacts;
use App\Services\Bsc\BscFinalReceiptInspection;
use App\Services\Bsc\BscFinalReceiptInspector;
use App\Services\Bsc\BscReadiness;
use App\Services\Bsc\BscRpcException;
use App\Services\Bsc\BscTokenAmount;
use App\Services\Bsc\BscTransactionHash;
use App\Services\Bsc\BscVerificationRpcClientInterface;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtReceivers\UsdtDepositReceiverRegistry;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Actual watch-only BSC rail for operator-registered public receivers. It never
 * provisions keys, signs, claims a receipt, posts money or invokes OrderLifecycle.
 * H1 remains unqualified: creation is restricted to disposable local/testing.
 */
final class BscUsdtTopUpRail implements UsdtTopUpRail
{
    public function __construct(
        private readonly UsdtDepositReceiverRegistry $receivers,
        private readonly BscVerificationRpcClientInterface $rpc,
        private readonly BscCapabilityProbe $capabilities,
        private readonly BscTokenAmount $amounts,
        private readonly BscFinalReceiptInspector $inspector,
    ) {}

    public function readiness(Customer $customer): UsdtTopUpRailReadiness
    {
        $this->outsideTransaction();
        try {
            $snapshot = $this->receivers->snapshotFor($customer);
        } catch (UsdtReceiverCapacityUnavailable) {
            return new UsdtTopUpRailReadiness(false, false, 'verified_receiver_unavailable');
        }
        if ($snapshot->chainId !== 56 || $snapshot->tokenContract !== BscReadiness::APPROVED_USDT_CONTRACT
            || $snapshot->tokenDecimals !== 18) {
            return new UsdtTopUpRailReadiness(false, false, 'receiver_network_or_token_mismatch');
        }
        if ((int) config('services.bsc.chain_id', 56) !== 56
            || strtolower((string) config('services.bsc.usdt_contract', BscReadiness::APPROVED_USDT_CONTRACT)) !== $snapshot->tokenContract
            || (int) config('services.bsc.expected_token_decimals', 18) !== $snapshot->tokenDecimals) {
            return new UsdtTopUpRailReadiness(false, false, 'configured_network_or_token_mismatch');
        }
        if (DB::table('usdt_bsc_scan_checkpoints')->where('receiver_id', $snapshot->receiverId)->whereNotNull('review_reason')->exists()) {
            return new UsdtTopUpRailReadiness(false, false, 'receiver_ingestion_on_review');
        }
        if (ManualPaymentSetting::current() === null) {
            return new UsdtTopUpRailReadiness(false, false, 'finality_policy_unavailable');
        }
        if (! $this->capabilities->probe()->isQualified()) {
            return new UsdtTopUpRailReadiness(false, false, 'rpc_capabilities_unqualified');
        }

        return new UsdtTopUpRailReadiness(true, false, 'h1_funding_unqualified');
    }

    public function createOrRecover(Customer $customer, string $reference, UsdtMicroAmount $amount, CarbonImmutable $expiresAt, ?CustomerUsdtTopUp $target = null): UsdtBscRailInvoice
    {
        $this->outsideTransaction();
        $this->validateReference($reference);
        if ($target !== null && ((int) $target->customer_id !== (int) $customer->id
            || $target->reference !== $reference || $target->payable_micro !== $amount->units()
            || ! $target->expires_at->equalTo($expiresAt))) {
            throw new DomainException('Confirmed USDT target does not match rail request.');
        }
        $fingerprint = hash('sha256', json_encode(['v' => 1, 'customer' => (int) $customer->getKey(),
            'reference' => $reference, 'currency' => 'USDT', 'amount_micro' => $amount->integerUnits(),
            'expires_at_epoch' => $expiresAt->getTimestamp(), ...($target === null ? [] : ['target_fingerprint' => $target->request_fingerprint])], JSON_THROW_ON_ERROR));
        $existing = UsdtBscRailInvoice::query()->where('reference', $reference)->first();
        if ($existing !== null) {
            return $this->replay($existing, $customer, $fingerprint);
        }
        // This is a qualification gate, not an ENV rollout switch. No amount,
        // receiver, fee or presentation configuration is taken from ENV.
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('H1 funding qualification is incomplete; BSC invoice creation is limited to disposable environments.');
        }
        $now = CarbonImmutable::now()->utc()->startOfSecond();
        $expiresAt = $expiresAt->utc()->startOfSecond();
        if ($expiresAt->lessThanOrEqualTo($now)) {
            throw new InvalidArgumentException('A new BSC invoice requires a future deadline.');
        }
        if (! $this->readiness($customer)->technicalReady) {
            throw new RuntimeException('BSC top-up technical readiness is unavailable.');
        }
        // All RPC work is before the retryable transaction. This local rail has
        // no remote create: crash-after-commit recovery queries the same ref.
        $preparedReceiver = $this->receivers->snapshotFor($customer);
        if ($target !== null && ($preparedReceiver->receiverId !== $target->receiver_id
            || $preparedReceiver->address !== $target->payment_snapshot['receiver_address']
            || $preparedReceiver->chainId !== $target->payment_snapshot['chain_id']
            || $preparedReceiver->tokenContract !== $target->payment_snapshot['token_contract']
            || $preparedReceiver->tokenDecimals !== $target->payment_snapshot['token_decimals']
            || $preparedReceiver->custodyNamespace !== $target->payment_snapshot['custody_namespace'])) {
            throw new DomainException('Confirmed USDT receiver changed; no fallback is permitted.');
        }
        $head = BscChainFacts::block($this->rpc->blockByNumber('latest'));
        if ($head === null) {
            throw new BscRpcException('BSC invoice requires complete latest-block facts.');
        }
        $policy = ManualPaymentSetting::current();
        if ($policy === null) {
            throw new RuntimeException('BSC finality policy is unavailable.');
        }
        $minimumConfirmations = $target?->payment_snapshot['minimum_confirmations'] ?? $policy->bscMinConfirmations();

        try {
            return DB::transaction(function () use ($customer, $reference, $amount, $expiresAt, $now, $fingerprint, $head, $minimumConfirmations, $preparedReceiver): UsdtBscRailInvoice {
                Customer::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();
                $existing = UsdtBscRailInvoice::query()->where('reference', $reference)->first();
                if ($existing !== null) {
                    return $this->replay($existing, $customer, $fingerprint);
                }
                if ($expiresAt->lessThanOrEqualTo(CarbonImmutable::now())) {
                    throw new DomainException('BSC invoice deadline elapsed during preparation.');
                }
                $snapshot = $this->receivers->snapshotFor($customer);
                CustomerUsdtDepositReceiver::query()->whereKey($snapshot->receiverId)->lockForUpdate()->firstOrFail();
                // Retirement can race preparation; re-read after the receiver lock.
                $snapshot = $this->receivers->snapshotFor($customer);
                if ($snapshot != $preparedReceiver) {
                    throw new DomainException('BSC receiver changed during invoice preparation; no fallback assignment is permitted.');
                }
                if (UsdtBscRailInvoice::query()->where('receiver_id', $snapshot->receiverId)
                    ->where('expires_at_epoch', '>=', $now->getTimestamp())
                    ->whereNotExists(function ($query): void {
                        $query->selectRaw('1')->from('customer_usdt_top_ups as targets')
                            ->join('customer_usdt_top_up_resolutions as resolutions', 'resolutions.top_up_id', '=', 'targets.id')
                            ->whereColumn('targets.reference', 'usdt_bsc_rail_invoices.reference');
                    })->exists()) {
                    throw new DomainException('An overlapping BSC invoice already owns this receiver payment window.');
                }

                DB::table('usdt_bsc_scan_checkpoints')->insertOrIgnore([
                    'receiver_id' => $snapshot->receiverId, 'first_block' => max(0, $head['number'] - 12),
                    'version' => 0, 'updated_at' => $now,
                ]);
                $checkpoint = DB::table('usdt_bsc_scan_checkpoints')->where('receiver_id', $snapshot->receiverId)->lockForUpdate()->first();
                if ($checkpoint->review_reason !== null) {
                    throw new DomainException('BSC receiver ingestion entered review during invoice preparation.');
                }

                return UsdtBscRailInvoice::query()->create([
                    'id' => (string) Str::uuid(), 'reference' => $reference,
                    'customer_id' => $snapshot->customerId, 'receiver_id' => $snapshot->receiverId,
                    'request_fingerprint' => $fingerprint, 'currency' => 'USDT',
                    'amount_micro' => $amount->units(), 'fee_micro' => 0, 'payable_micro' => $amount->units(),
                    'chain_id' => $snapshot->chainId, 'token_contract' => $snapshot->tokenContract,
                    'token_decimals' => $snapshot->tokenDecimals, 'receiver_address' => $snapshot->address,
                    'custody_namespace' => $snapshot->custodyNamespace,
                    'minimum_confirmations' => $minimumConfirmations, 'requires_finalized_block' => true,
                    'start_block' => max(0, $head['number'] - 12),
                    'starts_at_epoch' => $now->getTimestamp(), 'expires_at_epoch' => $expiresAt->getTimestamp(),
                    'created_at' => $now->setTimezone(config('app.timezone')),
                ]);
            }, 5);
        } catch (QueryException $exception) {
            // Catch outside the transaction: PostgreSQL is no longer aborted.
            $existing = UsdtBscRailInvoice::query()->where('reference', $reference)->first();
            if (in_array((string) ($exception->errorInfo[0] ?? ''), ['23505', '23000'], true) && $existing !== null) {
                return $this->replay($existing, $customer, $fingerprint);
            }
            throw $exception;
        }
    }

    public function inspect(Customer $customer, string $reference, string $transactionHash, bool $allowLate = false): BscFinalReceiptInspection
    {
        $this->outsideTransaction();
        $this->validateReference($reference);
        $invoice = UsdtBscRailInvoice::query()->where('reference', $reference)->firstOrFail();
        if ($invoice->customer_id !== (int) $customer->getKey()) {
            throw new AuthorizationException('The BSC invoice belongs to another Customer.');
        }
        $hash = BscTransactionHash::normalize($transactionHash);
        if ($hash === null || strlen($transactionHash) > 66) {
            return new BscFinalReceiptInspection('review', 'transaction_hash_invalid');
        }
        $receiver = CustomerUsdtDepositReceiver::query()->find($invoice->receiver_id);
        if ($receiver === null || (int) $receiver->customer_id !== $invoice->customer_id
            || $receiver->address_normalized !== $invoice->receiver_address
            || (int) $receiver->chain_id !== $invoice->chain_id
            || $receiver->token_contract !== $invoice->token_contract
            || (int) $receiver->token_decimals !== $invoice->token_decimals
            || $receiver->verified_at === null) {
            return new BscFinalReceiptInspection('review', 'receiver_binding_changed');
        }
        if ($invoice->chain_id !== 56 || $invoice->token_contract !== BscReadiness::APPROVED_USDT_CONTRACT
            || $invoice->token_decimals !== 18 || ! $invoice->requires_finalized_block) {
            return new BscFinalReceiptInspection('review', 'invoice_network_or_policy_invalid');
        }
        $checkpoint = DB::table('usdt_bsc_scan_checkpoints')->where('receiver_id', $invoice->receiver_id)->first();
        if ($checkpoint?->review_reason !== null) {
            return new BscFinalReceiptInspection('review', 'receiver_ingestion_on_review');
        }
        try {
            if ($this->rpc->chainId() !== $invoice->chain_id
                || $this->rpc->tokenDecimals($invoice->token_contract) !== $invoice->token_decimals) {
                return new BscFinalReceiptInspection('review', 'rpc_network_or_token_mismatch');
            }
            $receipt = $this->rpc->transactionReceipt($hash);
            $number = BscChainFacts::quantity($receipt['blockNumber'] ?? null);
            if ($number === null) {
                return $receipt === null
                    ? new BscFinalReceiptInspection('pending', 'receipt_missing')
                    : new BscFinalReceiptInspection('review', 'receipt_identity_malformed');
            }
            $block = $this->rpc->blockByNumber($number);
            $latest = $this->rpc->blockByNumber('latest');
            $finalized = $this->rpc->blockByNumber('finalized');
            $finalFacts = BscChainFacts::block($finalized);
            if ($finalFacts !== null) {
                $canonicalFinal = BscChainFacts::block($this->rpc->blockByNumber($finalFacts['number']));
                if ($canonicalFinal === null) {
                    return new BscFinalReceiptInspection('pending', 'canonical_finality_unavailable');
                }
                if ($canonicalFinal !== $finalFacts) {
                    return new BscFinalReceiptInspection('review', 'finalized_block_not_canonical');
                }
            }

            return $this->inspector->inspect(
                $hash, $receipt, $block, $latest, $finalized,
                $invoice->token_contract, $invoice->receiver_address,
                $this->amounts->expectedTokenUnits($invoice->payable_micro, $invoice->token_decimals),
                $invoice->token_decimals, $invoice->starts_at_epoch, $allowLate ? PHP_INT_MAX : $invoice->expires_at_epoch,
                $invoice->minimum_confirmations,
            );
        } catch (BscRpcException) {
            return new BscFinalReceiptInspection('pending', 'rpc_unavailable');
        }
    }

    private function replay(UsdtBscRailInvoice $invoice, Customer $customer, string $fingerprint): UsdtBscRailInvoice
    {
        if ($invoice->customer_id !== (int) $customer->getKey()) {
            throw new AuthorizationException('The BSC reference belongs to another Customer.');
        }
        if (! hash_equals($invoice->request_fingerprint, $fingerprint)) {
            throw new DomainException('BSC invoice reference replay payload differs.');
        }

        return $invoice;
    }

    private function outsideTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('BSC rail RPC operations cannot run inside a database transaction.');
        }
    }

    private function validateReference(string $reference): void
    {
        if (strlen($reference) > 96 || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9:_-]{0,95}\z/', $reference) !== 1) {
            throw new InvalidArgumentException('BSC rail reference is invalid.');
        }
    }
}
