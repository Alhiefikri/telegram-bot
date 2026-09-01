<?php

namespace App\Services\Admin;

use App\Models\BscPaymentReceiver;
use App\Models\User;
use App\Services\Bsc\BscReceiverManager;
use InvalidArgumentException;
use RuntimeException;

final class BscReceiverMutationExecutor
{
    public function __construct(private readonly BscReceiverManager $manager) {}

    public function add(string $label, string $address, User $actor, ?string $ip = null): BscReceiverMutationResult
    {
        return $this->execute(
            fn (): mixed => $this->manager->add($label, $address, $actor, $ip),
            'BSC Receiving Address berhasil disimpan dan langsung diaktifkan.',
        );
    }

    public function verify(
        BscPaymentReceiver $receiver,
        string $challengeId,
        string $walletType,
        string $signature,
        User $actor,
        ?string $ip = null,
    ): BscReceiverMutationResult {
        return $this->execute(
            fn (): mixed => $this->manager->verify($receiver, $challengeId, $walletType, $signature, $actor, $ip),
            'Ownership BSC receiver berhasil diverifikasi. Aktifkan receiver jika siap menerima pembayaran.',
        );
    }

    public function renewChallenge(BscPaymentReceiver $receiver, User $actor, ?string $ip = null): BscReceiverMutationResult
    {
        return $this->execute(
            fn (): mixed => $this->manager->renewChallenge($receiver, $actor, $ip),
            'Ownership challenge baru dibuat. Challenge sebelumnya sudah tidak berlaku.',
        );
    }

    public function manualVerify(BscPaymentReceiver $receiver, User $actor, ?string $ip = null): BscReceiverMutationResult
    {
        return $this->execute(
            fn (): mixed => $this->manager->manualVerify($receiver, true, $actor, $ip),
            'BSC Receiving Address berhasil diaktifkan.',
        );
    }

    public function activate(BscPaymentReceiver $receiver, User $actor, ?string $ip = null): BscReceiverMutationResult
    {
        return $this->execute(
            fn (): mixed => $this->manager->activate($receiver, $actor, $ip),
            'BSC receiver diaktifkan untuk checkout baru.',
        );
    }

    public function deactivate(BscPaymentReceiver $receiver, User $actor, ?string $ip = null): BscReceiverMutationResult
    {
        return $this->execute(
            fn (): mixed => $this->manager->deactivate($receiver, $actor, $ip),
            'BSC receiver dinonaktifkan. Payment existing tidak berubah.',
        );
    }

    public function executePending(BscReceiverPendingMutation $pending, User $actor): BscReceiverMutationResult
    {
        if ($pending->actorId !== (int) $actor->getKey()) {
            return BscReceiverMutationResult::failure('Pending BSC mutation tidak cocok dengan Owner yang sedang login.');
        }

        return match ($pending->action) {
            BscReceiverPendingMutation::ACTION_STORE => $this->executePendingAdd($pending->payload, $actor, $pending->ip),
            BscReceiverPendingMutation::ACTION_VERIFY => $this->executePendingVerify($pending->payload, $actor, $pending->ip),
            BscReceiverPendingMutation::ACTION_CHALLENGE => $this->executePendingChallenge($pending->payload, $actor, $pending->ip),
            BscReceiverPendingMutation::ACTION_MANUAL_VERIFY => $this->executePendingManualVerify($pending->payload, $actor, $pending->ip),
            BscReceiverPendingMutation::ACTION_ACTIVATE => $this->executePendingActivate($pending->payload, $actor, $pending->ip),
            BscReceiverPendingMutation::ACTION_DEACTIVATE => $this->executePendingDeactivate($pending->payload, $actor, $pending->ip),
            default => BscReceiverMutationResult::failure('Pending BSC mutation tidak valid. Silakan ulangi dari dashboard.'),
        };
    }

    private function executePendingAdd(array $payload, User $actor, ?string $ip): BscReceiverMutationResult
    {
        $label = $this->stringPayload($payload, 'label');
        $address = $this->stringPayload($payload, 'address');
        if ($label === null || $address === null) {
            return $this->invalidPending();
        }

        return $this->add($label, $address, $actor, $ip);
    }

    private function executePendingVerify(array $payload, User $actor, ?string $ip): BscReceiverMutationResult
    {
        $receiver = $this->receiverPayload($payload);
        $challengeId = $this->stringPayload($payload, 'challenge_id');
        $walletType = $this->stringPayload($payload, 'wallet_type');
        $signature = $this->stringPayload($payload, 'signature');
        if ($receiver === null || $challengeId === null || $walletType === null || $signature === null) {
            return $this->invalidPending();
        }

        return $this->verify($receiver, $challengeId, $walletType, $signature, $actor, $ip);
    }

    private function executePendingChallenge(array $payload, User $actor, ?string $ip): BscReceiverMutationResult
    {
        $receiver = $this->receiverPayload($payload);
        if ($receiver === null) {
            return $this->invalidPending();
        }

        return $this->renewChallenge($receiver, $actor, $ip);
    }

    private function executePendingManualVerify(array $payload, User $actor, ?string $ip): BscReceiverMutationResult
    {
        if (($payload['high_risk_ack'] ?? null) !== true) {
            return $this->invalidPending();
        }

        $receiver = $this->receiverPayload($payload);
        if ($receiver === null) {
            return $this->invalidPending();
        }

        return $this->manualVerify($receiver, $actor, $ip);
    }

    private function executePendingActivate(array $payload, User $actor, ?string $ip): BscReceiverMutationResult
    {
        $receiver = $this->receiverPayload($payload);
        if ($receiver === null) {
            return $this->invalidPending();
        }

        return $this->activate($receiver, $actor, $ip);
    }

    private function executePendingDeactivate(array $payload, User $actor, ?string $ip): BscReceiverMutationResult
    {
        $receiver = $this->receiverPayload($payload);
        if ($receiver === null) {
            return $this->invalidPending();
        }

        return $this->deactivate($receiver, $actor, $ip);
    }

    private function execute(callable $mutation, string $successMessage): BscReceiverMutationResult
    {
        try {
            $mutation();

            return BscReceiverMutationResult::success($successMessage);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return BscReceiverMutationResult::failure($exception->getMessage());
        }
    }

    private function receiverPayload(array $payload): ?BscPaymentReceiver
    {
        $receiverId = $this->integerPayload($payload, 'receiver_id');
        if ($receiverId === null) {
            return null;
        }

        return BscPaymentReceiver::query()->find($receiverId);
    }

    private function stringPayload(array $payload, string $key): ?string
    {
        return is_string($payload[$key] ?? null) ? $payload[$key] : null;
    }

    private function integerPayload(array $payload, string $key): ?int
    {
        $value = $payload[$key] ?? null;
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return $integer === false || $integer < 1 ? null : $integer;
    }

    private function invalidPending(): BscReceiverMutationResult
    {
        return BscReceiverMutationResult::failure('Pending BSC mutation tidak valid. Silakan ulangi dari dashboard.');
    }
}
