<?php

namespace App\Services\Telegram;

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
