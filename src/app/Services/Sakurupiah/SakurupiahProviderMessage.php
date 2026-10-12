<?php

namespace App\Services\Sakurupiah;

/**
 * Bounded, identifier-free provider failure text.
 *
 * The provider documents the failure reason of a rejected request in the
 * response `message` field. Operators need that reason to correct a merchant,
 * channel, or credential condition, and the reason must survive as evidence
 * without persisting credentials, identifiers, or unbounded provider text.
 */
final class SakurupiahProviderMessage
{
    public const MAX_LENGTH = 200;

    /**
     * @param  list<string|null>  $redactions  identifiers this caller already knows
     */
    public static function sanitize(mixed $value, array $redactions = []): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $message = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $message = trim(preg_replace('/\s+/u', ' ', $message) ?? '');
        if ($message === '') {
            return null;
        }

        foreach ($redactions as $redaction) {
            $redaction = trim((string) $redaction);
            if ($redaction !== '') {
                $message = str_replace($redaction, '[redacted]', $message);
            }
        }

        if (mb_strlen($message) > self::MAX_LENGTH) {
            $message = mb_substr($message, 0, self::MAX_LENGTH).'…';
        }

        return $message;
    }
}
