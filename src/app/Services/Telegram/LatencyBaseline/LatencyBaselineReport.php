<?php

namespace App\Services\Telegram\LatencyBaseline;

/**
 * Turns sanitized raw baseline samples into bounded percentile summaries.
 *
 * Percentiles use nearest rank over the available samples. Missing boundary
 * timestamps stay unknown and are never replaced with zero. Cold and warmed
 * samples are summarized separately; this is a local baseline, not a
 * production SLO.
 */
final class LatencyBaselineReport
{
    /** @var array<string, string> */
    public const BOUNDARIES = [
        'provider_observation_ms' => 'payment observed (fixture delay and any bounded history sweep included)',
        'provider_paid_to_observation_ms' => 'provider-reported paid_at to application observation',
        'observation_to_paid_commit_ms' => 'application observation to paid commit',
        'paid_commit_to_enqueue_ms' => 'paid commit to receipt enqueue',
        'queue_wait_ms' => 'receipt enqueue to worker pickup',
        'pickup_to_first_credential_ack_ms' => 'pickup to first credential-bearing Telegram acknowledgement',
        'pickup_to_receipt_completion_ms' => 'pickup to receipt group completion',
        'receipt_completion_to_admin_notice_ms' => 'receipt completion to admin notice acknowledgement',
        'e2e_observation_to_admin_notice_ms' => 'payment observation to admin notice acknowledgement',
    ];

    /**
     * @param  list<array<string, mixed>>  $samples
     * @return array<string, mixed>
     */
    public function summarize(array $samples): array
    {
        $boundaries = [];

        foreach (self::BOUNDARIES as $boundary => $label) {
            $values = [];
            $unknown = 0;

            foreach ($samples as $sample) {
                $value = $sample[$boundary] ?? null;
                if (is_int($value) || is_float($value)) {
                    $values[] = (float) $value;
                } else {
                    $unknown++;
                }
            }

            sort($values, SORT_NUMERIC);

            $boundaries[$boundary] = [
                'label' => $label,
                'samples' => count($values),
                'unknown' => $unknown,
                'p50_ms' => $this->percentile($values, 50),
                'p95_ms' => $this->percentile($values, 95),
                'max_ms' => $values === [] ? null : round(max($values), 2),
            ];
        }

        $effectStates = [];
        foreach ($samples as $sample) {
            foreach (($sample['effect_states'] ?? []) as $state => $count) {
                $effectStates[$state] = ($effectStates[$state] ?? 0) + (int) $count;
            }
        }

        return [
            'sample_count' => count($samples),
            'boundaries' => $boundaries,
            'counts' => [
                'complete' => count(array_filter($samples, static fn (array $sample): bool => ($sample['complete'] ?? false) === true)),
                'incomplete' => count(array_filter($samples, static fn (array $sample): bool => ($sample['complete'] ?? false) !== true)),
                'queue_wait_unknown' => count(array_filter($samples, static fn (array $sample): bool => ($sample['queue_wait_ms'] ?? null) === null)),
                'failed_jobs' => array_sum(array_map(static fn (array $sample): int => (int) ($sample['failed_jobs'] ?? 0), $samples)),
                'retried_samples' => count(array_filter($samples, static fn (array $sample): bool => (int) ($sample['receipt_pickups'] ?? 0) > 1)),
                'duplicate_samples' => count(array_filter($samples, static fn (array $sample): bool => (int) ($sample['duplicate_acknowledgements'] ?? 0) > 0)),
            ],
            'effect_states' => $effectStates,
        ];
    }

    /** @var array<string, string> */
    public const PIN_BOUNDARIES = [
        'pin_queue_wait_ms' => 'pin job enqueue to pin worker pickup',
        'pin_duration_ms' => 'pin attempt duration',
        'pin_finished_after_receipt_completion_ms' => 'positive means the pin finished after the receipt group completed',
        'pin_finished_after_admin_ack_ms' => 'positive means the pin finished after the admin paid notice acknowledgement',
    ];

    /**
     * @param  list<array<string, mixed>>  $samples
     * @return array<string, mixed>
     */
    public function summarizePins(array $samples): array
    {
        $boundaries = [];

        foreach (self::PIN_BOUNDARIES as $boundary => $label) {
            $values = [];
            $unknown = 0;

            foreach ($samples as $sample) {
                $value = $sample[$boundary] ?? null;
                if (is_int($value) || is_float($value)) {
                    $values[] = (float) $value;
                } else {
                    $unknown++;
                }
            }

            sort($values, SORT_NUMERIC);

            $boundaries[$boundary] = [
                'label' => $label,
                'samples' => count($values),
                'unknown' => $unknown,
                'p50_ms' => $this->percentile($values, 50),
                'p95_ms' => $this->percentile($values, 95),
                'max_ms' => $values === [] ? null : round(max($values), 2),
            ];
        }

        $outcomes = [];
        foreach ($samples as $sample) {
            $outcome = (string) ($sample['pin_outcome'] ?? 'none');
            $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
        }

        return [
            'sample_count' => count($samples),
            'attempts' => array_sum(array_map(static fn (array $sample): int => (int) ($sample['pin_attempts'] ?? 0), $samples)),
            'pinned' => count(array_filter($samples, static fn (array $sample): bool => ($sample['pinned'] ?? false) === true)),
            'failed' => (int) ($outcomes['failed'] ?? 0),
            'outcomes' => $outcomes,
            'boundaries' => $boundaries,
            'counts' => [
                'dispatched' => count(array_filter($samples, static fn (array $sample): bool => (int) ($sample['pin_dispatches'] ?? 0) > 0)),
                'picked_up' => count(array_filter($samples, static fn (array $sample): bool => (int) ($sample['pin_pickups'] ?? 0) > 0)),
                'receipt_completed_before_pin_finished' => count(array_filter($samples, static fn (array $sample): bool => ($sample['pin_finished_after_receipt_completion_ms'] ?? null) !== null && (float) $sample['pin_finished_after_receipt_completion_ms'] > 0)),
                'admin_ack_before_pin_finished' => count(array_filter($samples, static fn (array $sample): bool => ($sample['pin_finished_after_admin_ack_ms'] ?? null) !== null && (float) $sample['pin_finished_after_admin_ack_ms'] > 0)),
                'pointer_on_credential_effect' => count(array_filter($samples, static fn (array $sample): bool => ($sample['pin_pointer_on_credential_effect'] ?? false) === true)),
                'recovered' => count(array_filter($samples, static fn (array $sample): bool => ($sample['pinned'] ?? false) === true && (int) ($sample['pin_failed_attempts'] ?? 0) > 0)),
            ],
        ];
    }

    /** @param  list<float>  $sorted */
    private function percentile(array $sorted, int $percentile): ?float
    {
        if ($sorted === []) {
            return null;
        }

        $count = count($sorted);
        $rank = (int) ceil(($percentile / 100) * $count);
        $index = max(0, min($count - 1, $rank - 1));

        return round($sorted[$index], 2);
    }

    /** @param  array<string, mixed>  $report */
    public function markdown(array $report): string
    {
        $lines = [
            '# Telegram receipt latency baseline',
            '',
            '- Run: `'.(string) ($report['run_id'] ?? 'unknown').'`',
            '- Generated at: `'.(string) ($report['generated_at'] ?? 'unknown').'`',
            '- Environment: PHP '.(string) ($report['environment']['php_version'] ?? 'unknown')
                .' / Laravel '.(string) ($report['environment']['laravel_version'] ?? 'unknown')
                .' / '.($report['environment']['database'] ?? 'unknown')
                .' / '.($report['environment']['redis'] ?? 'unknown'),
            '- Contract: '.($report['contract']['note'] ?? ''),
            '',
        ];

        if (isset($report['contract']['boundaries']) && is_array($report['contract']['boundaries'])) {
            $lines[] = '## Measurement contract';
            $lines[] = '';
            foreach ($report['contract']['boundaries'] as $boundary => $label) {
                $lines[] = '- `'.$boundary.'`: '.$label;
            }
            $lines[] = '';
        }

        if (isset($report['contract']['pin_boundaries']) && is_array($report['contract']['pin_boundaries'])) {
            $lines[] = '## Pin measurement contract';
            $lines[] = '';
            foreach ($report['contract']['pin_boundaries'] as $boundary => $label) {
                $lines[] = '- `'.$boundary.'`: '.$label;
            }
            $lines[] = '';
        }

        foreach (($report['scenarios'] ?? []) as $scenario => $result) {
            $lines[] = '## '.$scenario;
            $lines[] = '';
            $lines[] = 'Dataset: '.json_encode($result['dataset'] ?? [], JSON_UNESCAPED_SLASHES);
            $lines[] = '';
            $lines[] = 'Worker: '.json_encode($result['worker'] ?? [], JSON_UNESCAPED_SLASHES);
            $lines[] = '';
            if (isset($result['workers']) && is_array($result['workers'])) {
                $lines[] = 'Workers: '.json_encode($result['workers'], JSON_UNESCAPED_SLASHES);
                $lines[] = '';
            }
            if (isset($result['interaction_probe']) && is_array($result['interaction_probe'])) {
                $lines[] = 'Payment/update isolation probe: '.json_encode($result['interaction_probe'], JSON_UNESCAPED_SLASHES);
                $lines[] = '';
            }
            $lines[] = 'Fixture: '.json_encode($result['fixture'] ?? [], JSON_UNESCAPED_SLASHES);
            $lines[] = '';

            foreach (['cold' => 'Cold-start (warm-up batches)', 'warm' => 'Warmed runs (measured batches)'] as $key => $heading) {
                $summary = $result[$key] ?? null;
                if (! is_array($summary)) {
                    continue;
                }

                $lines[] = '### '.$heading.' — '.((int) ($summary['sample_count'] ?? 0)).' samples';
                $lines[] = '';
                $lines[] = '| Boundary | n | p50 ms | p95 ms | max ms | unknown |';
                $lines[] = '| --- | ---: | ---: | ---: | ---: | ---: |';
                foreach (($summary['boundaries'] ?? []) as $boundary => $values) {
                    $lines[] = '| `'.$boundary.'` | '.(int) ($values['samples'] ?? 0)
                        .' | '.$this->format($values['p50_ms'] ?? null)
                        .' | '.$this->format($values['p95_ms'] ?? null)
                        .' | '.$this->format($values['max_ms'] ?? null)
                        .' | '.(int) ($values['unknown'] ?? 0).' |';
                }
                $lines[] = '';
                $lines[] = 'Counts: '.json_encode($summary['counts'] ?? [], JSON_UNESCAPED_SLASHES);
                $lines[] = 'Effect states: '.json_encode($summary['effect_states'] ?? [], JSON_UNESCAPED_SLASHES);
                $lines[] = '';
            }

            if (isset($result['pin']) && is_array($result['pin'])) {
                $pin = $result['pin'];
                $lines[] = '### Credential pin isolation';
                $lines[] = '';
                $lines[] = 'Attempts: '.(int) ($pin['attempts'] ?? 0).' | pinned: '.(int) ($pin['pinned'] ?? 0)
                    .' | failed outcomes: '.(int) ($pin['failed'] ?? 0)
                    .' | outcomes: '.json_encode($pin['outcomes'] ?? [], JSON_UNESCAPED_SLASHES);
                $lines[] = '';
                $lines[] = '| Pin boundary | n | p50 ms | p95 ms | max ms | unknown |';
                $lines[] = '| --- | ---: | ---: | ---: | ---: | ---: |';
                foreach (($pin['boundaries'] ?? []) as $boundary => $values) {
                    $lines[] = '| `'.$boundary.'` | '.(int) ($values['samples'] ?? 0)
                        .' | '.$this->format($values['p50_ms'] ?? null)
                        .' | '.$this->format($values['p95_ms'] ?? null)
                        .' | '.$this->format($values['max_ms'] ?? null)
                        .' | '.(int) ($values['unknown'] ?? 0).' |';
                }
                $lines[] = '';
                $lines[] = 'Counts: '.json_encode($pin['counts'] ?? [], JSON_UNESCAPED_SLASHES);
                $lines[] = '';
            }

            if (isset($result['pin_recovery']) && is_array($result['pin_recovery'])) {
                $lines[] = 'Pin recovery: '.json_encode($result['pin_recovery'], JSON_UNESCAPED_SLASHES);
                $lines[] = '';
            }
        }

        $lines[] = '## Limits';
        $lines[] = '';
        $lines[] = '- Local synthetic baseline: one disposable PostgreSQL/Redis stack and the local fixture transports; not production latency.';
        $lines[] = '- A Telegram Bot API acknowledgement is not proof that the customer read the message.';
        $lines[] = '- Percentiles are not a production SLO or release budget until repeated on the production-equivalent stack.';
        $lines[] = '- Credentials, tokens, chat identifiers and message payloads are not recorded.';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function format(mixed $value): string
    {
        return is_int($value) || is_float($value) ? number_format((float) $value, 2, '.', '') : 'unknown';
    }
}
