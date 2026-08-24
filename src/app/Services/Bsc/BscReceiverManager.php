<?php

namespace App\Services\Bsc;

use App\Enums\BscReceiverVerificationStatus;
use App\Exceptions\BscReceiverChallengeException;
use App\Jobs\SendBscReceiverMutationNotification;
use App\Models\BscPaymentReceiver;
use App\Models\BscReceiverOwnershipChallenge;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class BscReceiverManager
{
    public function __construct(
        private readonly BscWalletOwnershipVerifierInterface $ownershipVerifier,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function add(string $label, string $address, User $actor, ?string $ip = null): BscReceiverRegistration
    {
        $this->assertOwner($actor);
        $label = $this->normalizeLabel($label);
        $address = BscPaymentReceiver::normalizeAddress($address);
        [$chainId, $tokenContract, $tokenDecimals] = $this->configuredToken();

        return DB::transaction(function () use ($label, $address, $actor, $ip, $chainId, $tokenContract, $tokenDecimals): BscReceiverRegistration {
            if (BscPaymentReceiver::query()->where('address_normalized', $address)->exists()) {
                throw new BscReceiverChallengeException('BSC Receiving Address sudah terdaftar. Nonaktifkan record lama atau gunakan address lain.');
            }

            $receiver = BscPaymentReceiver::create([
                'label' => $label,
                'address' => $address,
                'chain_id' => $chainId,
                'token_contract' => $tokenContract,
                'token_decimals' => $tokenDecimals,
                'is_active' => false,
                'verification_status' => BscReceiverVerificationStatus::PendingVerification,
            ]);
            $challenge = $this->issueChallenge($receiver, $actor);

            $this->auditLogger->log(
                'bsc_receiver.added',
                $receiver,
                null,
                $this->safeValues($receiver) + [
                    'challenge_id' => (string) $challenge->getKey(),
                    'reason' => 'receiver_added_pending_ownership_verification',
                ],
                $actor,
                $ip,
            );
            $this->notifyAfterCommit('added', $receiver, $actor);

            return new BscReceiverRegistration($receiver, $challenge);
        }, 5);
    }

    public function verify(
        BscPaymentReceiver $receiver,
        string $challengeId,
        string $walletType,
        string $signature,
        User $actor,
        ?string $ip = null,
    ): BscPaymentReceiver {
        $this->assertOwner($actor);
        $walletType = strtolower(trim($walletType));
        if (! in_array($walletType, ['eoa', 'erc1271', 'smart_contract'], true)) {
            throw new BscReceiverChallengeException('Tipe wallet ownership tidak valid.');
        }

        return DB::transaction(function () use ($receiver, $challengeId, $walletType, $signature, $actor, $ip): BscPaymentReceiver {
            $lockedReceiver = BscPaymentReceiver::query()->lockForUpdate()->findOrFail($receiver->getKey());
            if ($lockedReceiver->verification_status !== BscReceiverVerificationStatus::PendingVerification) {
                throw new BscReceiverChallengeException('Receiver ini sudah diverifikasi atau tidak lagi menunggu verifikasi.');
            }

            $challenge = BscReceiverOwnershipChallenge::query()
                ->where('receiver_id', $lockedReceiver->getKey())
                ->whereKey($challengeId)
                ->lockForUpdate()
                ->first();
            if ($challenge === null) {
                throw new BscReceiverChallengeException('Challenge ownership tidak ditemukan.');
            }
            if ((int) $challenge->user_id !== (int) $actor->getKey()) {
                throw new BscReceiverChallengeException('Challenge ownership hanya dapat dipakai oleh Owner yang memintanya.');
            }
            if (! $challenge->isUsable()) {
                throw new BscReceiverChallengeException('Challenge ownership sudah kedaluwarsa atau sudah dipakai.');
            }
            if (! hash_equals((string) $challenge->message_hash, hash('sha256', (string) $challenge->message))) {
                throw new BscReceiverChallengeException('Challenge ownership tidak konsisten. Buat challenge baru.');
            }
            if ((int) $challenge->chain_id !== 56 || (int) $lockedReceiver->chain_id !== 56) {
                throw new BscReceiverChallengeException('Chain ID challenge tidak sesuai dengan BNB Smart Chain Mainnet.');
            }

            if (! $this->ownershipVerifier->verify($lockedReceiver, $challenge, $walletType, $signature)) {
                throw new BscReceiverChallengeException('Signature ownership tidak membuktikan kontrol atas address ini.');
            }

            $challenge->forceFill(['used_at' => now()])->save();
            $lockedReceiver->forceFill([
                'verification_status' => BscReceiverVerificationStatus::Verified,
                'verification_method' => $walletType === 'eoa' ? 'eip191_eoa' : 'erc1271',
                'verified_at' => now(),
                'verified_by' => $actor->getKey(),
                'is_active' => false,
            ])->save();

            $this->auditLogger->log(
                'bsc_receiver.verified',
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
            $this->notifyAfterCommit('verified', $lockedReceiver, $actor);

            return $lockedReceiver->fresh();
        }, 5);
    }

    public function renewChallenge(
        BscPaymentReceiver $receiver,
        User $actor,
        ?string $ip = null,
    ): BscReceiverOwnershipChallenge {
        $this->assertOwner($actor);

        return DB::transaction(function () use ($receiver, $actor, $ip): BscReceiverOwnershipChallenge {
            $lockedReceiver = BscPaymentReceiver::query()->lockForUpdate()->findOrFail($receiver->getKey());
            if ($lockedReceiver->verification_status !== BscReceiverVerificationStatus::PendingVerification) {
                throw new BscReceiverChallengeException('Receiver ini tidak lagi menunggu ownership verification.');
            }

            $challenge = $this->issueChallenge($lockedReceiver, $actor);
            $this->auditLogger->log(
                'bsc_receiver.challenge_issued',
                $lockedReceiver,
                null,
                $this->safeValues($lockedReceiver) + [
                    'challenge_id' => (string) $challenge->getKey(),
                    'reason' => 'previous_ownership_challenge_revoked_and_reissued',
                ],
                $actor,
                $ip,
            );

            return $challenge;
        }, 5);
    }

    public function manualVerify(
        BscPaymentReceiver $receiver,
        bool $highRiskAcknowledged,
        User $actor,
        ?string $ip = null,
    ): BscPaymentReceiver {
        $this->assertOwner($actor);
        if (! $highRiskAcknowledged) {
            throw new BscReceiverChallengeException('Manual verified adalah jalur high risk dan harus dikonfirmasi secara eksplisit.');
        }

        return DB::transaction(function () use ($receiver, $actor, $ip): BscPaymentReceiver {
            $lockedReceiver = BscPaymentReceiver::query()->lockForUpdate()->findOrFail($receiver->getKey());
            if ($lockedReceiver->verification_status !== BscReceiverVerificationStatus::PendingVerification) {
                throw new BscReceiverChallengeException('Receiver ini sudah diverifikasi atau tidak lagi menunggu verifikasi.');
            }

            $lockedReceiver->forceFill([
                'verification_status' => BscReceiverVerificationStatus::ManualVerified,
                'verification_method' => 'manual_verified',
                'verified_at' => now(),
                'verified_by' => $actor->getKey(),
                'is_active' => false,
            ])->save();

            $this->auditLogger->log(
                'bsc_receiver.manual_verified',
                $lockedReceiver,
                ['verification_status' => BscReceiverVerificationStatus::PendingVerification->value],
                $this->safeValues($lockedReceiver) + [
                    'verification_method' => 'manual_verified',
                    'high_risk_acknowledged' => true,
                    'reason' => 'owner_acknowledged_manual_custodial_verification_risk',
                ],
                $actor,
                $ip,
            );
            $this->notifyAfterCommit('manual_verified', $lockedReceiver, $actor);

            return $lockedReceiver->fresh();
        }, 5);
    }

    public function activate(BscPaymentReceiver $receiver, User $actor, ?string $ip = null): BscPaymentReceiver
    {
        $this->assertOwner($actor);
        [$chainId, $tokenContract, $tokenDecimals] = $this->configuredToken();

        return DB::transaction(function () use ($receiver, $actor, $ip, $chainId, $tokenContract, $tokenDecimals): BscPaymentReceiver {
            $lockedReceiver = BscPaymentReceiver::query()->lockForUpdate()->findOrFail($receiver->getKey());
            if (! $lockedReceiver->isVerified()) {
                throw new BscReceiverChallengeException('Receiver harus diverifikasi sebelum dapat diaktifkan.');
            }
            if ((int) $lockedReceiver->chain_id !== $chainId
                || strtolower((string) $lockedReceiver->token_contract) !== $tokenContract
                || (int) $lockedReceiver->token_decimals !== $tokenDecimals) {
                throw new BscReceiverChallengeException('Konfigurasi chain atau token contract berubah. Buat receiver baru dengan konfigurasi BSC yang aktif.');
            }
            if ($lockedReceiver->is_active) {
                return $lockedReceiver;
            }
            if (BscPaymentReceiver::query()
                ->where('address_normalized', $lockedReceiver->address_normalized)
                ->where('is_active', true)
                ->whereKeyNot($lockedReceiver->getKey())
                ->exists()) {
                throw new BscReceiverChallengeException('Address ini sudah aktif pada receiver lain.');
            }

            $lockedReceiver->forceFill(['is_active' => true])->save();
            $this->auditLogger->log(
                'bsc_receiver.activated',
                $lockedReceiver,
                ['is_active' => false],
                $this->safeValues($lockedReceiver) + ['reason' => 'owner_activated_verified_receiver'],
                $actor,
                $ip,
            );
            $this->notifyAfterCommit('activated', $lockedReceiver, $actor);

            return $lockedReceiver->fresh();
        }, 5);
    }

    public function deactivate(BscPaymentReceiver $receiver, User $actor, ?string $ip = null): BscPaymentReceiver
    {
        $this->assertOwner($actor);

        return DB::transaction(function () use ($receiver, $actor, $ip): BscPaymentReceiver {
            $lockedReceiver = BscPaymentReceiver::query()->lockForUpdate()->findOrFail($receiver->getKey());
            if (! $lockedReceiver->is_active) {
                return $lockedReceiver;
            }

            $lockedReceiver->forceFill(['is_active' => false])->save();
            $this->auditLogger->log(
                'bsc_receiver.deactivated',
                $lockedReceiver,
                ['is_active' => true],
                $this->safeValues($lockedReceiver) + ['reason' => 'owner_deactivated_receiver_for_new_checkouts'],
                $actor,
                $ip,
            );
            $this->notifyAfterCommit('deactivated', $lockedReceiver, $actor);

            return $lockedReceiver->fresh();
        }, 5);
    }

    private function issueChallenge(BscPaymentReceiver $receiver, User $actor): BscReceiverOwnershipChallenge
    {
        BscReceiverOwnershipChallenge::query()
            ->where('receiver_id', $receiver->getKey())
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $challengeId = (string) Str::uuid();
        $nonce = bin2hex(random_bytes(32));
        $expiresAt = Carbon::now()->addMinutes((int) config('services.bsc.ownership_challenge_ttl_minutes', 10));
        $message = BscOwnershipChallengeMessage::build(
            (string) $receiver->address_normalized,
            (int) $receiver->chain_id,
            $challengeId,
            $nonce,
            $expiresAt,
        );
        $challenge = new BscReceiverOwnershipChallenge;
        $challenge->id = $challengeId;
        $challenge->fill([
            'receiver_id' => $receiver->getKey(),
            'user_id' => $actor->getKey(),
            'wallet_address' => $receiver->address_normalized,
            'chain_id' => $receiver->chain_id,
            'purpose' => BscOwnershipChallengeMessage::PURPOSE,
            'message' => $message,
            'message_hash' => hash('sha256', $message),
            'nonce_hash' => hash('sha256', $nonce),
            'expires_at' => $expiresAt,
        ]);
        $challenge->save();

        return $challenge;
    }

    /** @return array{0:int,1:string,2:int} */
    private function configuredToken(): array
    {
        $chainId = (int) config('services.bsc.chain_id', 56);
        if ($chainId !== 56) {
            throw new InvalidArgumentException('BSC_CHAIN_ID harus 56 untuk receiver BNB Smart Chain.');
        }

        $contract = BscPaymentReceiver::normalizeAddress((string) config('services.bsc.usdt_contract'));

        return [$chainId, $contract, (int) config('services.bsc.expected_token_decimals', 18)];
    }

    private function normalizeLabel(string $label): string
    {
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 120) {
            throw new InvalidArgumentException('Label BSC Receiving Address wajib diisi dan maksimal 120 karakter.');
        }

        return $label;
    }

    private function assertOwner(User $actor): void
    {
        if (! $actor->role?->canManageBscReceivers()) {
            throw new AuthorizationException('Hanya Owner yang dapat mengubah BSC Receiving Address.');
        }
    }

    /** @return array<string, mixed> */
    private function safeValues(BscPaymentReceiver $receiver): array
    {
        return [
            'receiver_id' => $receiver->getKey(),
            'label' => $receiver->label,
            'address_fingerprint' => hash('sha256', (string) $receiver->address_normalized),
            'chain_id' => (int) $receiver->chain_id,
            'token_contract' => (string) $receiver->token_contract,
            'token_decimals' => (int) $receiver->token_decimals,
            'is_active' => (bool) $receiver->is_active,
            'verification_status' => $receiver->verification_status?->value,
            'verification_method' => $receiver->verification_method,
            'verified_at' => $receiver->verified_at?->toIso8601String(),
        ];
    }

    private function notifyAfterCommit(string $action, BscPaymentReceiver $receiver, User $actor): void
    {
        $payload = [
            'action' => $action,
            'label' => (string) $receiver->label,
            'masked_address' => $receiver->maskedAddress(),
            'actor' => (string) ($actor->name ?: $actor->email ?: 'Owner'),
            'at' => now()->toIso8601String(),
        ];

        DB::afterCommit(function () use ($payload): void {
            SendBscReceiverMutationNotification::dispatch($payload)->onQueue('notifications');
        });
    }
}
