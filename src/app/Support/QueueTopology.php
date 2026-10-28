<?php

namespace App\Support;

/**
 * The supervised queue names. Worker topology lives in supervisord.conf; this
 * class only names the queues so health, readiness, cutover and tests cannot
 * drift from each other.
 */
final class QueueTopology
{
    public const INTERACTIONS = 'default';

    public const PAYMENTS = 'payments';

    public const RECEIPTS = 'telegram-receipts';

    public const NOTIFICATIONS = 'notifications';

    public const OUTBOUND = 'telegram-outbound';

    public const BROADCAST = 'telegram-broadcast';

    public const STOCK = 'telegram-stock';

    public const SUPPLIERS = 'suppliers';

    public const BINANCE_VERIFICATION = 'binance-verification';

    /** @return list<string> */
    public static function operational(): array
    {
        return array_values(array_unique([
            self::PAYMENTS,
            self::INTERACTIONS,
            self::RECEIPTS,
            self::NOTIFICATIONS,
            self::OUTBOUND,
            self::BROADCAST,
            self::STOCK,
            self::SUPPLIERS,
            (string) config('services.binance.verification_queue', self::BINANCE_VERIFICATION),
        ]));
    }

    /**
     * Parse the supervised queue:work programs from a supervisord config.
     *
     * @return array<string, array{queues: list<string>, command: string}>
     */
    public static function supervisorPrograms(string $contents): array
    {
        preg_match_all('/^\[program:([^\]]+)\]\s*$(.*?)(?=^\[program:|\z)/ms', $contents, $blocks, PREG_SET_ORDER);

        $programs = [];
        foreach ($blocks as $block) {
            if (preg_match('/^\s*command\s*=\s*(.+)$/m', $block[2], $match) !== 1) {
                continue;
            }

            $command = trim($match[1]);
            if (! str_contains($command, 'queue:work')
                || preg_match('/--queue=(\S+)/', $command, $queues) !== 1) {
                continue;
            }

            $programs[trim($block[1])] = [
                'queues' => array_values(array_filter(explode(',', $queues[1]))),
                'command' => $command,
            ];
        }

        return $programs;
    }
}
