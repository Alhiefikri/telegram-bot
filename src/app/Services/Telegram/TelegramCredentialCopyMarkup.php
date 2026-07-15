<?php

namespace App\Services\Telegram;

use App\Services\CredentialDeliveryFormatter;

final class TelegramCredentialCopyMarkup
{
    public function __construct(private readonly CredentialDeliveryFormatter $formatter) {}

    /**
     * @param  iterable<array-key, object>  $stockItems
     * @return list<list<array<string, mixed>>>
     */
    public function rows(iterable $stockItems): array
    {
        $rows = [];
        foreach ($stockItems as $stockItem) {
            $parts = $this->formatter->parts((string) $stockItem->secret);
            if ($parts === null || ! $this->copyable($parts)) {
                continue;
            }

            $rows[] = [
                ['text' => '📋 Email', 'copy_text' => ['text' => $parts['email']]],
                ['text' => '📋 Password', 'copy_text' => ['text' => $parts['password']]],
                ['text' => '📋 MFA', 'copy_text' => ['text' => $parts['mfa']]],
            ];
        }

        return $rows;
    }

    /** @param  array{email: string, password: string, mfa: string}  $parts */
    private function copyable(array $parts): bool
    {
        return collect($parts)->every(fn (string $value): bool => mb_strlen($value, 'UTF-8') <= 256);
    }
}
