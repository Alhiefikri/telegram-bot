<?php

namespace App\Services\PaidInvoice;

use App\Models\Customer;

/**
 * Builds the public-safe Telegram buyer label used by paid invoice views.
 *
 * The Telegram display name is more useful to Store Channel readers than a
 * raw identifier, but the full name must never be published. A username is a
 * fallback for older/incomplete Customer rows that have no first name.
 */
final class PaidInvoiceBuyerNameMasker
{
    public function mask(?Customer $customer): string
    {
        $name = $this->clean((string) ($customer?->first_name ?? ''));
        if ($name === '' || strcasecmp($name, 'Telegram Customer') === 0) {
            $name = $this->clean((string) ($customer?->username ?? ''));
        }

        $name = ltrim($name, '@');

        return $name === ''
            ? 'Buyer'
            : mb_substr($name, 0, 3, 'UTF-8').'***';
    }

    private function clean(string $value): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);

        return trim(is_string($clean) ? $clean : $value);
    }
}
