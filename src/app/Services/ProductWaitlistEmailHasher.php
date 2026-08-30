<?php

namespace App\Services;

use Illuminate\Support\Str;

final class ProductWaitlistEmailHasher
{
    public function normalize(?string $email): ?string
    {
        if (! is_string($email)) {
            return null;
        }

        $normalized = Str::lower(trim($email));

        return $normalized === '' ? null : $normalized;
    }

    public function hash(string $normalizedEmail): string
    {
        $key = trim((string) config('services.storefront.waitlist_hash_key', ''));
        if ($key === '') {
            $key = (string) config('app.key', '');
        }

        return hash_hmac('sha256', $normalizedEmail, $key);
    }
}
