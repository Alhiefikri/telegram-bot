<?php

namespace App\Services\Telegram;

final class TelegramFamilyCallbackToken
{
    // Six bytes keeps origin-qualified callbacks within Telegram's 64-byte limit.
    private const SIGNATURE_BYTES = 6;

    public function options(int $familyId, int $page, int $returnPage): string
    {
        return 'v'.$this->encode(pack('qNN', $familyId, $page, $returnPage));
    }

    public function select(int $familyId, int $productId, int $page, int $returnPage): string
    {
        return 'v'.$this->encode(pack('qqNN', $familyId, $productId, $page, $returnPage));
    }

    /** @return array{familyId:int,page:int,returnPage:int}|null */
    public function decodeOptions(string $token): ?array
    {
        $payload = $this->decode($token);
        if ($payload === null || strlen($payload) !== 16) {
            return null;
        }

        $values = unpack('qfamilyId/Npage/NreturnPage', $payload);
        if (! is_array($values)) {
            return null;
        }

        return $this->positiveValues([
            'familyId' => $values['familyId'] ?? null,
            'page' => $values['page'] ?? null,
            'returnPage' => $values['returnPage'] ?? null,
        ]);
    }

    /** @return array{familyId:int,productId:int,page:int,returnPage:int}|null */
    public function decodeSelect(string $token): ?array
    {
        $payload = $this->decode($token);
        if ($payload === null || strlen($payload) !== 24) {
            return null;
        }

        $values = unpack('qfamilyId/qproductId/Npage/NreturnPage', $payload);
        if (! is_array($values)) {
            return null;
        }

        $positive = $this->positiveValues([
            'familyId' => $values['familyId'] ?? null,
            'productId' => $values['productId'] ?? null,
            'page' => $values['page'] ?? null,
            'returnPage' => $values['returnPage'] ?? null,
        ]);
        if ($positive === null) {
            return null;
        }

        return [
            'familyId' => $positive['familyId'],
            'productId' => $positive['productId'],
            'page' => $positive['page'],
            'returnPage' => $positive['returnPage'],
        ];
    }

    private function encode(string $payload): string
    {
        $signature = substr(hash_hmac('sha256', $payload, (string) config('app.key'), true), 0, self::SIGNATURE_BYTES);

        return rtrim(strtr(base64_encode($payload.$signature), '+/', '-_'), '=');
    }

    private function decode(string $token): ?string
    {
        if ($token === '' || preg_match('/\A[A-Za-z0-9_-]+\z/D', $token) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if (! is_string($decoded) || strlen($decoded) <= self::SIGNATURE_BYTES) {
            return null;
        }

        $payload = substr($decoded, 0, -self::SIGNATURE_BYTES);
        $signature = substr($decoded, -self::SIGNATURE_BYTES);
        $expected = substr(hash_hmac('sha256', $payload, (string) config('app.key'), true), 0, self::SIGNATURE_BYTES);

        return hash_equals($expected, $signature) ? $payload : null;
    }

    /** @param array<string, mixed> $values */
    private function positiveValues(array $values): ?array
    {
        foreach ($values as $value) {
            if (! is_int($value) || $value < 1) {
                return null;
            }
        }

        return $values;
    }
}
