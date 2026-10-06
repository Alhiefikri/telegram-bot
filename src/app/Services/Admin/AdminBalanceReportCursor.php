<?php

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

final class AdminBalanceReportCursor
{
    /** @param array{section: string, currency: string, telegram_id: ?string, state: ?string, limit: int} $filters */
    public function encode(User $actor, array $filters, string $occurredAt, string $id): string
    {
        return Crypt::encryptString(json_encode([
            'version' => 1,
            'scope' => $this->scope($actor, $filters),
            'occurred_at' => $occurredAt,
            'id' => $id,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array{section: string, currency: string, telegram_id: ?string, state: ?string, limit: int}  $filters
     * @return array{occurred_at: string, id: string}|null
     */
    public function decode(User $actor, array $filters, string $token): ?array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        if (! is_array($payload)
            || ($payload['version'] ?? null) !== 1
            || ! is_string($payload['scope'] ?? null)
            || ! hash_equals($this->scope($actor, $filters), $payload['scope'])
            || ! is_string($payload['occurred_at'] ?? null)
            || ! is_string($payload['id'] ?? null)
            || $payload['id'] === ''
            || strlen($payload['id']) > 64) {
            return null;
        }

        return [
            'occurred_at' => $payload['occurred_at'],
            'id' => $payload['id'],
        ];
    }

    /** @param array{section: string, currency: string, telegram_id: ?string, state: ?string, limit: int} $filters */
    private function scope(User $actor, array $filters): string
    {
        return hash('sha256', json_encode([
            'actor' => (string) $actor->getKey(),
            'section' => $filters['section'],
            'currency' => $filters['currency'],
            'telegram_id' => $filters['telegram_id'],
            'state' => $filters['state'],
            'limit' => $filters['limit'],
        ], JSON_THROW_ON_ERROR));
    }
}
