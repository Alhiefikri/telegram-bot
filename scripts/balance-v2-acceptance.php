<?php

/**
 * Issue #560's internal-operation SLOs. Arrival/scheduling latency remains
 * reported separately; it is not an invented p99 posting SLO. No threshold
 * depends on observed performance, and missing observations fail closed.
 *
 * @param  array<string, array<string, mixed>>  $measurements
 * @param  array<string, array{reads: int, postings: int}>  $expected
 * @return array<string, bool>
 */
function balanceV2Acceptance(array $measurements, array $expected): array
{
    $checks = [];
    $within = static fn ($value, float $limit): bool => is_numeric($value)
        && is_finite((float) $value) && (float) $value >= 0 && (float) $value <= $limit;
    foreach ($expected as $phase => $counts) {
        $data = $measurements[$phase] ?? [];
        $checks[$phase.'.drained'] = ($data['all_scheduled_work_completed'] ?? false) === true
            && ($data['remaining_scheduled_work'] ?? null) === 0;
        foreach (['usdt', 'idr'] as $currency) {
            $read = $data[$currency.'_read'] ?? [];
            $posting = $data[$currency.'_posting'] ?? [];
            $checks[$phase.'.'.$currency.'.population'] = ($read['latency_ms']['count'] ?? null) === $counts['reads']
                && ($posting['latency_ms']['count'] ?? null) === $counts['postings']
                && ($read['errors'] ?? null) === 0 && ($posting['errors'] ?? null) === 0;
            $checks[$phase.'.'.$currency.'.read_p95'] = $within($read['latency_ms']['p95'] ?? null, 100);
            $checks[$phase.'.'.$currency.'.posting_p95'] = $within($posting['latency_ms']['p95'] ?? null, 250);
            if ($phase !== 'hot') {
                $checks[$phase.'.'.$currency.'.distinct_lock_p99'] = $within($posting['lock_query_ms']['p99'] ?? null, 500);
            }
        }
    }

    return $checks;
}
