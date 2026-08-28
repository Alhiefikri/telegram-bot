<?php

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use JsonException;

final class BscReceiverPendingMutationStore
{
    public const SESSION_KEY = 'admin.bsc_receiver_pending_mutation';

    public const PASSWORD_CONFIRMATION_TIMEOUT_SECONDS = 900;

    public const PENDING_ACTION_TTL_SECONDS = 900;

    public function isPasswordConfirmed(Request $request): bool
    {
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);

        return now()->timestamp - $confirmedAt <= self::PASSWORD_CONFIRMATION_TIMEOUT_SECONDS;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function redirectIfRequired(
        Request $request,
        User $actor,
        string $action,
        array $payload,
    ): ?RedirectResponse {
        if ($this->isPasswordConfirmed($request)) {
            return null;
        }

        $pending = [
            'action' => $action,
            'actor_id' => (int) $actor->getKey(),
            'payload' => $payload,
            'ip' => $request->ip(),
            'nonce' => Str::random(40),
            'expires_at' => now()->addSeconds(self::PENDING_ACTION_TTL_SECONDS)->timestamp,
        ];

        $request->session()->put(
            self::SESSION_KEY,
            Crypt::encryptString(json_encode($pending, JSON_THROW_ON_ERROR)),
        );

        return redirect()->route('password.confirm');
    }

    public function consume(Request $request, User $actor): ?BscReceiverPendingMutation
    {
        $encrypted = $request->session()->pull(self::SESSION_KEY);
        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            $pending = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        if (! is_array($pending)) {
            return null;
        }

        $action = $pending['action'] ?? null;
        $actorId = $this->integerPayload($pending['actor_id'] ?? null);
        $expiresAt = $this->integerPayload($pending['expires_at'] ?? null);
        $payload = $pending['payload'] ?? null;
        $nonce = $pending['nonce'] ?? null;
        $ip = $pending['ip'] ?? null;

        if (! is_string($action)
            || ! in_array($action, BscReceiverPendingMutation::actions(), true)
            || $actorId === false
            || (int) $actorId !== (int) $actor->getKey()
            || $expiresAt === false
            || (int) $expiresAt < now()->timestamp
            || ! is_array($payload)
            || ! is_string($nonce)
            || $nonce === ''
            || ($ip !== null && ! is_string($ip))) {
            return null;
        }

        return new BscReceiverPendingMutation(
            $action,
            (int) $actorId,
            $payload,
            $ip,
        );
    }

    private function integerPayload(mixed $value): int|false
    {
        if (! is_int($value) && ! is_string($value)) {
            return false;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return $integer === false ? false : (int) $integer;
    }
}
