<?php

namespace App\Services\UsdtReceivers;

use App\Enums\BscReceiverVerificationStatus;
use App\Enums\UsdtDepositReceiverSource;
use App\Enums\UsdtReceiverChannel;
use App\Exceptions\UsdtDepositReceiverAlreadyActive;
use App\Exceptions\UsdtDepositReceiverVerificationFailed;
use App\Exceptions\UsdtReceiverAddressConflict;
use App\Exceptions\UsdtReceiverCapacityUnavailable;
use App\Models\Customer;
use App\Models\CustomerUsdtDepositReceiver;
use App\Models\UsdtDepositReceiverOwnershipChallenge;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Bsc\BscReadiness;
use App\Services\Bsc\BscWalletOwnershipVerifierInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Owner service for Customer-exclusive USDT deposit receivers: the public BSC
 * address permanently bound to exactly one Customer for USDT funding.
 *
 * Every registration claims the normalized (chain, address) in the global
 * receiver registry, so an address can never be shared with the checkout
 * pool or with another Customer, and can never be reassigned after expiry,
 * retirement or deletion of a pending binding. Custody provisioning is an
 * explicit, approved-adapter concern: without an available adapter the rail
 * fails closed instead of falling back to a shared address.
 */
final class UsdtDepositReceiverRegistry
{
    public const SUPPORTED_CHAIN_ID = 56;

    /** Self-managed operator custody selected by H1 (#539). */
    public const OPERATOR_CUSTODY_NAMESPACE = 'operator';

    private const MAX_MONITORED_LIMIT = 500;

    public function __construct(
        private readonly UsdtReceiverAddressClaims $addressClaims,
        private readonly UsdtDepositReceiverProvisioners $provisioners,
        private readonly BscWalletOwnershipVerifierInterface $ownershipVerifier,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Registers one pending Customer-exclusive receiver and issues its
     * ownership-proof challenge. The address is claimed permanently before
     * verification, so a rejected or deleted binding never frees it.
     */
    public function register(
        Customer $customer,
        string $address,
        User $actor,
        ?string $ip = null,
    ): UsdtDepositReceiverRegistration {
        $this->assertOwner($actor);
        $address = CustomerUsdtDepositReceiver::normalizeAddress($address);
        [$chainId, $tokenContract, $tokenDecimals] = $this->supportedToken();

        return $this->translatingActiveCustomerConflict(fn (): UsdtDepositReceiverRegistration => DB::transaction(
            function () use ($customer, $address, $actor, $ip, $chainId, $tokenContract, $tokenDecimals): UsdtDepositReceiverRegistration {
                $this->lockCustomer($customer);
                $this->assertNoActiveReceiver($customer);

                $claim = $this->addressClaims->claim($chainId, $address, UsdtReceiverChannel::CustomerDeposit);

                $receiver = CustomerUsdtDepositReceiver::create([
                    'customer_id' => $customer->getKey(),
                    'address_claim_id' => $claim->getKey(),
                    'chain_id' => $chainId,
                    'address' => $address,
                    'address_normalized' => $address,
                    'token_contract' => $tokenContract,
                    'token_decimals' => $tokenDecimals,
                    'custody_namespace' => self::OPERATOR_CUSTODY_NAMESPACE,
                    'source' => UsdtDepositReceiverSource::Owner,
                    'verification_status' => BscReceiverVerificationStatus::PendingVerification,
                    'verification_method' => null,
                    'verified_at' => null,
                    'verified_by' => null,
                    'registered_by' => $actor->getKey(),
                ]);

                $challenge = $this->issueChallenge($receiver, $actor);

                $this->auditLogger->log(
                    'usdt_deposit_receiver.registered',
                    $receiver,
                    null,
                    $this->safeValues($receiver) + [
                        'challenge_id' => (string) $challenge->getKey(),
                        'reason' => 'owner_registered_exclusive_customer_deposit_receiver',
                    ],
                    $actor,
                    $ip,
                );

                return new UsdtDepositReceiverRegistration($receiver, $challenge);
            },
            5,
        ));
    }

    /**
     * Records a cryptographic proof of control over the receiver address using
     * the established BSC ownership verification contract.
     */
    public function verify(
        CustomerUsdtDepositReceiver $receiver,
        string $challengeId,
        string $walletType,
        string $signature,
        User $actor,
        ?string $ip = null,
    ): CustomerUsdtDepositReceiver {
        $this->assertOwner($actor);
        $walletType = strtolower(trim($walletType));
        if (! in_array($walletType, ['eoa', 'erc1271', 'smart_contract'], true)) {
            throw new InvalidArgumentException('Tipe wallet ownership tidak valid.');
        }

        return DB::transaction(function () use ($receiver, $challengeId, $walletType, $signature, $actor, $ip): CustomerUsdtDepositReceiver {
            $lockedReceiver = CustomerUsdtDepositReceiver::query()->lockForUpdate()->findOrFail($receiver->getKey());
            if ($lockedReceiver->verification_status !== BscReceiverVerificationStatus::PendingVerification) {
                throw new UsdtDepositReceiverVerificationFailed('Deposit receiver ini sudah diverifikasi atau tidak lagi menunggu verifikasi.');
            }

            $challenge = UsdtDepositReceiverOwnershipChallenge::query()
                ->where('receiver_id', $lockedReceiver->getKey())
                ->whereKey($challengeId)
                ->lockForUpdate()
                ->first();
            if ($challenge === null) {
                throw new UsdtDepositReceiverVerificationFailed('Challenge ownership tidak ditemukan.');
            }
            if ((int) $challenge->user_id !== (int) $actor->getKey()) {
                throw new UsdtDepositReceiverVerificationFailed('Challenge ownership hanya dapat dipakai oleh Owner yang memintanya.');
            }
            if (! $challenge->isUsable()) {
                throw new UsdtDepositReceiverVerificationFailed('Challenge ownership sudah kedaluwarsa atau sudah dipakai.');
            }
            if (! hash_equals((string) $challenge->message_hash, hash('sha256', (string) $challenge->message))) {
                throw new UsdtDepositReceiverVerificationFailed('Challenge ownership tidak konsisten. Buat challenge baru.');
            }
            if ((int) $challenge->chain_id !== self::SUPPORTED_CHAIN_ID
                || (int) $lockedReceiver->chain_id !== self::SUPPORTED_CHAIN_ID) {
                throw new UsdtDepositReceiverVerificationFailed('Chain ID challenge tidak sesuai dengan BNB Smart Chain Mainnet.');
            }

            if (! $this->ownershipVerifier->verifyMessage(
                (string) $lockedReceiver->address_normalized,
                (int) $lockedReceiver->chain_id,
                (string) $challenge->message,
                $walletType,
                $signature,
            )) {
                throw new UsdtDepositReceiverVerificationFailed('Signature ownership tidak membuktikan kontrol atas address ini.');
            }

            $challenge->forceFill(['used_at' => now()])->save();
            $lockedReceiver->forceFill([
                'verification_status' => BscReceiverVerificationStatus::Verified,
                'verification_method' => $walletType === 'eoa' ? 'eip191_eoa' : 'erc1271',
                'verified_at' => now(),
                'verified_by' => $actor->getKey(),
            ])->save();

            $this->auditLogger->log(
                'usdt_deposit_receiver.verified',
                $lockedReceiver,
                ['verification_status' => BscReceiverVerificationStatus::PendingVerification->value],
                $this->safeValues($lockedReceiver) + [
                    'challenge_id' => (string) $challenge->getKey(),
                    'verification_method' => $lockedReceiver->verification_method,
                    'reason' => 'cryptographic_ownership_proof_verified',
                ],
                $actor,
                $ip,
            );

            return $lockedReceiver->fresh();
        }, 5);
    }

    /**
     * Records an explicitly acknowledged Owner custody attestation. This is
     * the same high-risk contract used by BSC checkout receivers and never
     * replaces a future qualified custody adapter.
     */
    public function manualVerify(
        CustomerUsdtDepositReceiver $receiver,
        bool $highRiskAcknowledged,
        User $actor,
        ?string $ip = null,
    ): CustomerUsdtDepositReceiver {
        $this->assertOwner($actor);
        if (! $highRiskAcknowledged) {
            throw new UsdtDepositReceiverVerificationFailed('Manual verified adalah jalur high risk dan harus dikonfirmasi secara eksplisit.');
        }

        return DB::transaction(function () use ($receiver, $actor, $ip): CustomerUsdtDepositReceiver {
            $lockedReceiver = CustomerUsdtDepositReceiver::query()->lockForUpdate()->findOrFail($receiver->getKey());
            if ($lockedReceiver->verification_status !== BscReceiverVerificationStatus::PendingVerification) {
                throw new UsdtDepositReceiverVerificationFailed('Deposit receiver ini sudah diverifikasi atau tidak lagi menunggu verifikasi.');
            }

            $lockedReceiver->forceFill([
                'verification_status' => BscReceiverVerificationStatus::ManualVerified,
                'verification_method' => 'manual_verified',
                'verified_at' => now(),
                'verified_by' => $actor->getKey(),
            ])->save();

            $this->auditLogger->log(
                'usdt_deposit_receiver.manual_verified',
                $lockedReceiver,
                ['verification_status' => BscReceiverVerificationStatus::PendingVerification->value],
                $this->safeValues($lockedReceiver) + [
                    'verification_method' => 'manual_verified',
                    'high_risk_acknowledged' => true,
                    'reason' => 'owner_acknowledged_manual_custody_verification_risk',
                ],
                $actor,
                $ip,
            );

            return $lockedReceiver->fresh();
        }, 5);
    }

    /**
     * Stops new invoice assignment for one receiver. The Customer binding,
     * address and history stay immutable, and the retired address keeps being
     * monitored for late money.
     */
    public function retire(
        CustomerUsdtDepositReceiver $receiver,
        User $actor,
        string $reason,
        ?string $ip = null,
    ): CustomerUsdtDepositReceiver {
        $this->assertOwner($actor);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 191) {
            throw new InvalidArgumentException('Alasan retire USDT deposit receiver wajib diisi dan maksimal 191 karakter.');
        }

        return DB::transaction(function () use ($receiver, $actor, $reason, $ip): CustomerUsdtDepositReceiver {
            $lockedReceiver = CustomerUsdtDepositReceiver::query()->lockForUpdate()->findOrFail($receiver->getKey());
            if ($lockedReceiver->isRetired()) {
                return $lockedReceiver;
            }

            $lockedReceiver->forceFill([
                'retired_at' => now(),
                'retirement_reason' => $reason,
                'retired_by' => $actor->getKey(),
            ])->save();

            $this->auditLogger->log(
                'usdt_deposit_receiver.retired',
                $lockedReceiver,
                ['retired_at' => null],
                $this->safeValues($lockedReceiver) + ['reason' => $reason],
                $actor,
                $ip,
            );

            return $lockedReceiver->fresh();
        }, 5);
    }

    public function activeFor(Customer $customer): ?CustomerUsdtDepositReceiver
    {
        return CustomerUsdtDepositReceiver::query()
            ->forCustomer($customer->getKey())
            ->active()
            ->orderBy('id')
            ->first();
    }

    /**
     * Snapshot of the Customer's current assignable receiver. It fails closed
     * when the binding is missing, retired or still unverified.
     */
    public function snapshotFor(Customer $customer): UsdtDepositReceiverSnapshot
    {
        $receiver = $this->activeFor($customer);
        if ($receiver === null) {
            throw new UsdtReceiverCapacityUnavailable('Customer belum memiliki USDT deposit receiver aktif.');
        }

        if (! $receiver->isReadyForAssignment()) {
            throw new UsdtReceiverCapacityUnavailable('USDT deposit receiver Customer belum terverifikasi ownership.');
        }

        return UsdtDepositReceiverSnapshot::fromReceiver($receiver);
    }

    /**
     * Returns the Customer's assignable receiver, provisioning one exclusive
     * address through an approved custody adapter when none exists. When no
     * adapter is approved, capacity is exhausted, or the provisioned address
     * is already owned, this fails closed: it never substitutes a shared
     * address or a unique-amount workaround.
     */
    public function allocateFor(Customer $customer): UsdtDepositReceiverSnapshot
    {
        $existing = $this->activeFor($customer);
        if ($existing !== null) {
            if (! $existing->isReadyForAssignment()) {
                throw new UsdtReceiverCapacityUnavailable(
                    'USDT deposit receiver Customer belum terverifikasi ownership. Selesaikan verifikasi sebelum membuat invoice baru.',
                );
            }

            return UsdtDepositReceiverSnapshot::fromReceiver($existing);
        }

        $provisioner = $this->provisioners->available();
        if ($provisioner === null) {
            throw new UsdtReceiverCapacityUnavailable(
                'Tidak ada custody provisioning adapter USDT deposit receiver yang approved.',
            );
        }

        $address = $provisioner->provide($customer);
        if (! is_string($address) || trim($address) === '') {
            throw new UsdtReceiverCapacityUnavailable('Kapasitas address USDT deposit receiver habis.');
        }

        try {
            $receiver = $this->registerProvisioned($customer, $address, $provisioner->key());
        } catch (UsdtReceiverAddressConflict|UsdtDepositReceiverAlreadyActive $exception) {
            // A concurrent allocation won the same Customer binding. Converge
            // on the winning receiver instead of creating a second address.
            $winner = $this->activeFor($customer);
            if ($winner !== null && $winner->isReadyForAssignment()) {
                return UsdtDepositReceiverSnapshot::fromReceiver($winner);
            }

            throw $exception;
        }

        if (! $receiver->isReadyForAssignment()) {
            throw new UsdtReceiverCapacityUnavailable('USDT deposit receiver hasil provisioning tidak assignable.');
        }

        return UsdtDepositReceiverSnapshot::fromReceiver($receiver);
    }

    public function findByAddress(int $chainId, string $address): ?CustomerUsdtDepositReceiver
    {
        $claim = $this->addressClaims->find($chainId, $address);
        if ($claim === null || $claim->channel !== UsdtReceiverChannel::CustomerDeposit) {
            return null;
        }

        return $claim->depositReceiver()->first();
    }

    public function channelOf(int $chainId, string $address): ?UsdtReceiverChannel
    {
        return $this->addressClaims->find($chainId, $address)?->channel;
    }

    /**
     * Bounded, keyset-paginated receiver inventory for ingestion/recovery
     * scans. Retired receivers are included so late money stays monitored.
     *
     * @return Collection<int, CustomerUsdtDepositReceiver>
     */
    public function monitoredReceivers(?int $afterId = null, int $limit = 100): Collection
    {
        $limit = max(1, min($limit, self::MAX_MONITORED_LIMIT));

        return CustomerUsdtDepositReceiver::query()
            ->when($afterId !== null, fn ($query) => $query->where('id', '>', $afterId))
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    private function registerProvisioned(
        Customer $customer,
        string $address,
        string $custodyNamespace,
    ): CustomerUsdtDepositReceiver {
        if (preg_match(UsdtDepositReceiverProvisioners::KEY_PATTERN, $custodyNamespace) !== 1) {
            throw new InvalidArgumentException('Custody namespace provisioning tidak valid.');
        }

        $address = CustomerUsdtDepositReceiver::normalizeAddress($address);
        [$chainId, $tokenContract, $tokenDecimals] = $this->supportedToken();

        return $this->translatingActiveCustomerConflict(fn (): CustomerUsdtDepositReceiver => DB::transaction(
            function () use ($customer, $address, $custodyNamespace, $chainId, $tokenContract, $tokenDecimals): CustomerUsdtDepositReceiver {
                $this->lockCustomer($customer);
                $this->assertNoActiveReceiver($customer);

                $claim = $this->addressClaims->claim($chainId, $address, UsdtReceiverChannel::CustomerDeposit);

                // A qualified custody adapter is the address authority, so the
                // provisioned binding is recorded as adapter-attested custody
                // rather than a wallet-signature proof.
                $receiver = CustomerUsdtDepositReceiver::create([
                    'customer_id' => $customer->getKey(),
                    'address_claim_id' => $claim->getKey(),
                    'chain_id' => $chainId,
                    'address' => $address,
                    'address_normalized' => $address,
                    'token_contract' => $tokenContract,
                    'token_decimals' => $tokenDecimals,
                    'custody_namespace' => $custodyNamespace,
                    'source' => UsdtDepositReceiverSource::Provisioner,
                    'verification_status' => BscReceiverVerificationStatus::ManualVerified,
                    'verification_method' => 'custody_provisioner:'.$custodyNamespace,
                    'verified_at' => now(),
                    'verified_by' => null,
                    'registered_by' => null,
                ]);

                $this->auditLogger->log(
                    'usdt_deposit_receiver.provisioned',
                    $receiver,
                    null,
                    $this->safeValues($receiver) + [
                        'custody_provisioner' => $custodyNamespace,
                        'reason' => 'approved_custody_adapter_provisioned_exclusive_customer_receiver',
                    ],
                    null,
                );

                return $receiver;
            },
            5,
        ));
    }

    private function lockCustomer(Customer $customer): void
    {
        Customer::query()->lockForUpdate()->findOrFail($customer->getKey());
    }

    private function assertNoActiveReceiver(Customer $customer): void
    {
        if (CustomerUsdtDepositReceiver::query()->forCustomer($customer->getKey())->active()->exists()) {
            throw new UsdtDepositReceiverAlreadyActive(
                'Customer sudah memiliki USDT deposit receiver aktif. Retire receiver lama sebelum menambah address baru.',
            );
        }
    }

    private function issueChallenge(
        CustomerUsdtDepositReceiver $receiver,
        User $actor,
    ): UsdtDepositReceiverOwnershipChallenge {
        UsdtDepositReceiverOwnershipChallenge::query()
            ->where('receiver_id', $receiver->getKey())
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $challengeId = (string) Str::uuid();
        $nonce = bin2hex(random_bytes(32));
        $expiresAt = Carbon::now()->addMinutes((int) config('services.bsc.ownership_challenge_ttl_minutes', 10));
        $message = UsdtReceiverOwnershipMessage::build(
            (string) $receiver->address_normalized,
            (int) $receiver->chain_id,
            $challengeId,
            $nonce,
            $expiresAt,
        );

        $challenge = new UsdtDepositReceiverOwnershipChallenge;
        $challenge->id = $challengeId;
        $challenge->fill([
            'receiver_id' => $receiver->getKey(),
            'user_id' => $actor->getKey(),
            'wallet_address' => $receiver->address_normalized,
            'chain_id' => $receiver->chain_id,
            'purpose' => UsdtReceiverOwnershipMessage::PURPOSE,
            'message' => $message,
            'message_hash' => hash('sha256', $message),
            'nonce_hash' => hash('sha256', $nonce),
            'expires_at' => $expiresAt,
        ]);
        $challenge->save();

        return $challenge;
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function translatingActiveCustomerConflict(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (QueryException $exception) {
            if (! $this->isActiveCustomerUniqueViolation($exception)) {
                throw $exception;
            }

            throw new UsdtDepositReceiverAlreadyActive(
                'Customer sudah memiliki USDT deposit receiver aktif. Retire receiver lama sebelum menambah address baru.',
                previous: $exception,
            );
        }
    }

    private function isActiveCustomerUniqueViolation(QueryException $exception): bool
    {
        if (! in_array((string) ($exception->errorInfo[0] ?? ''), ['23000', '23505'], true)) {
            return false;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'usdt_deposit_receivers_active_customer_unique')
            || (str_contains($message, 'customer_usdt_deposit_receivers')
                && str_contains($message, 'customer_id'));
    }

    /** @return array{0:int,1:string,2:int} */
    private function supportedToken(): array
    {
        return [self::SUPPORTED_CHAIN_ID, BscReadiness::APPROVED_USDT_CONTRACT, 18];
    }

    private function assertOwner(User $actor): void
    {
        if (! $actor->role?->canManageUsdtReceivers()) {
            throw new AuthorizationException('Hanya Owner yang dapat mengelola USDT deposit receiver Customer.');
        }
    }

    /** @return array<string, mixed> */
    private function safeValues(CustomerUsdtDepositReceiver $receiver): array
    {
        return [
            'receiver_id' => (int) $receiver->getKey(),
            'customer_id' => (int) $receiver->customer_id,
            'address_fingerprint' => hash('sha256', (string) $receiver->address_normalized),
            'masked_address' => $receiver->maskedAddress(),
            'chain_id' => (int) $receiver->chain_id,
            'token_contract' => (string) $receiver->token_contract,
            'token_decimals' => (int) $receiver->token_decimals,
            'custody_namespace' => (string) $receiver->custody_namespace,
            'source' => $receiver->source?->value,
            'verification_status' => $receiver->verification_status?->value,
            'verification_method' => $receiver->verification_method,
            'retired_at' => $receiver->retired_at?->toIso8601String(),
        ];
    }
}
