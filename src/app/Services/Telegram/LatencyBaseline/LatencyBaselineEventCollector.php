<?php

namespace App\Services\Telegram\LatencyBaseline;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;

/**
 * Collects the existing dogfood timing events for one baseline run.
 *
 * Runner events arrive through the framework logger; worker events arrive on
 * the queue worker's stderr channel. Only dogfood events are retained and the
 * report keeps their bounded context fields.
 */
final class LatencyBaselineEventCollector
{
    /** @var list<array{event: string, context: array<string, mixed>, source: string, collected_at: string}> */
    private array $events = [];

    /** @var array<string, true> */
    private array $seen = [];

    public function register(): void
    {
        Log::listen(function (MessageLogged $event): void {
            if (! str_starts_with($event->message, 'dogfood.')) {
                return;
            }

            $this->remember('runner', $event->message, $event->context);
        });
    }

    public function ingestWorkerOutput(string $output): void
    {
        preg_match_all(
            '/^\[[^\]]+\]\s+\S+\.INFO:\s+(dogfood\.[a-z0-9_.]+)\s+(\{.*\})\s*(?:\[\])?\s*$/mi',
            $output,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $context = json_decode($match[2], true);
            if (! is_array($context)) {
                continue;
            }

            $this->remember('worker', $match[1], $context);
        }
    }

    /**
     * @return list<array{event: string, context: array<string, mixed>, source: string, collected_at: string}>
     */
    public function matching(string $event, string $orderCorrelation): array
    {
        return array_values(array_filter(
            $this->events,
            static fn (array $candidate): bool => $candidate['event'] === $event
                && (($candidate['context']['order_correlation_id'] ?? $candidate['context']['correlation_id'] ?? null) === $orderCorrelation),
        ));
    }

    /** @param array<string, mixed> $context */
    private function remember(string $source, string $event, array $context): void
    {
        $signature = $source.'|'.$event.'|'.md5(json_encode($context, JSON_THROW_ON_ERROR));
        if (isset($this->seen[$signature])) {
            return;
        }

        $this->seen[$signature] = true;
        $this->events[] = [
            'event' => $event,
            'context' => $context,
            'source' => $source,
            'collected_at' => now()->toISOString(),
        ];
    }

    public function first(string $event, string $orderCorrelation): ?array
    {
        return $this->matching($event, $orderCorrelation)[0] ?? null;
    }
}
