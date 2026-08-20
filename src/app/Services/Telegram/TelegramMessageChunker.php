<?php

namespace App\Services\Telegram;

use InvalidArgumentException;

final class TelegramMessageChunker
{
    /**
     * Keep line boundaries where possible while staying below Telegram's 4096-character limit.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    public function fromLines(array $lines, int $limit = 3900): array
    {
        $chunks = [];
        $current = '';

        foreach ($lines as $line) {
            foreach ($this->splitLine($line, $limit) as $part) {
                $candidate = $current === '' ? $part : $current."\n".$part;
                if ($current !== '' && mb_strlen($candidate, 'UTF-8') > $limit) {
                    $chunks[] = $current;
                    $current = $part;
                } else {
                    $current = $candidate;
                }
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks === [] ? [''] : $chunks;
    }

    /**
     * Preserve credential/button units as indivisible sends. The caller must
     * split non-secret tutorial material or a long token into another unit;
     * this boundary never silently truncates or splits a credential entity.
     *
     * @param  list<TelegramMessageUnit>  $units
     * @return list<TelegramMessageUnit>
     */
    public function fromAtomicUnits(array $units, int $limit = 3900): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Telegram message limit must be positive.');
        }

        foreach ($units as $unit) {
            if (! $unit instanceof TelegramMessageUnit) {
                throw new InvalidArgumentException('Telegram atomic units must be message units.');
            }
            if ($unit->characterLength() > $limit) {
                throw new InvalidArgumentException('Telegram atomic message unit exceeds the configured limit.');
            }
        }

        return array_values($units);
    }

    /** @return list<string> */
    private function splitLine(string $line, int $limit): array
    {
        if (mb_strlen($line, 'UTF-8') <= $limit) {
            return [$line];
        }

        $parts = [];
        while ($line !== '') {
            $parts[] = mb_substr($line, 0, $limit, 'UTF-8');
            $line = mb_substr($line, $limit, null, 'UTF-8');
        }

        return $parts;
    }
}
