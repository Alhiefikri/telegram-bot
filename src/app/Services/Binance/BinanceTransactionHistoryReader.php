<?php

namespace App\Services\Binance;

final readonly class BinanceHistoryReadResult
{
    /** @param list<array<string, mixed>> $transactions */
    public function __construct(
        public array $transactions,
        public bool $complete,
        public int $requests,
    ) {}
}

final class BinanceTransactionHistoryReader
{
    private const PAGE_SIZE = 100;

    public function __construct(
        private BinancePayClient $client,
        private ?int $maxPages = null,
        private ?int $maxWindowSplits = null,
        private ?int $maxDurationSeconds = null,
    ) {}

    public function read(int $startTimeMs, ?int $endTimeMs = null): BinanceHistoryReadResult
    {
        $bucketMs = 60_000;
        $startTimeMs = intdiv($startTimeMs, $bucketMs) * $bucketMs;
        $requestedEndTimeMs = $endTimeMs ?? now()->getTimestampMs();
        $endTimeMs = $requestedEndTimeMs - $startTimeMs >= $bucketMs
            ? (int) (ceil($requestedEndTimeMs / $bucketMs) * $bucketMs)
            : $requestedEndTimeMs;
        $maxPages = max(1, $this->maxPages ?? (int) config('services.binance.history_max_pages', 8));
        $maxSplits = max(0, $this->maxWindowSplits ?? (int) config('services.binance.history_max_window_splits', 8));
        $maxDurationMs = max(1, $this->maxDurationSeconds ?? (int) config('services.binance.history_max_duration_seconds', 45)) * 1000;
        $windows = [[$startTimeMs, $endTimeMs, 0]];
        $transactions = [];
        $seen = [];
        $requests = 0;
        $complete = true;
        $startedAt = hrtime(true);

        while ($windows !== []) {
            [$windowStart, $windowEnd, $splits] = array_shift($windows);
            if ($requests >= $maxPages || intdiv(hrtime(true) - $startedAt, 1_000_000) >= $maxDurationMs) {
                $complete = false;
                break;
            }

            $page = $this->client->payTransactions($windowStart, $windowEnd, self::PAGE_SIZE);
            $requests++;
            foreach ($page as $payload) {
                if (! is_array($payload)) {
                    continue;
                }

                $identity = filled($payload['transactionId'] ?? null)
                    ? 'transaction:'.hash('sha256', (string) $payload['transactionId'])
                    : (filled($payload['orderId'] ?? null)
                        ? 'order:'.hash('sha256', (string) $payload['orderId'])
                        : 'payload:'.hash('sha256', json_encode($payload) ?: ''));
                if (! isset($seen[$identity])) {
                    $seen[$identity] = true;
                    $transactions[] = $payload;
                }
            }

            if (count($page) < self::PAGE_SIZE) {
                continue;
            }

            if ($splits >= $maxSplits || $windowEnd - $windowStart <= 1) {
                $complete = false;

                continue;
            }

            $midpoint = $windowStart + intdiv($windowEnd - $windowStart, 2);
            $windows[] = [$windowStart, $midpoint, $splits + 1];
            $windows[] = [$midpoint + 1, $windowEnd, $splits + 1];
        }

        return new BinanceHistoryReadResult($transactions, $complete, $requests);
    }
}
