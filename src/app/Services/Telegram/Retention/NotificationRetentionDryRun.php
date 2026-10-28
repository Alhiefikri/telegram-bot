<?php

namespace App\Services\Telegram\Retention;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDOException;
use RuntimeException;
use Throwable;

final class NotificationRetentionDryRun
{
    public function __construct(private readonly NotificationRetentionClassifier $classifier) {}

    public function run(RetentionWindow $window): array
    {
        $start = microtime(true);
        $deadline = $start + $window->budgets['seconds'];
        $report = [
            'schema' => 'notification-retention-report-v1', 'policy' => RetentionWindow::POLICY,
            'revision' => config('app.revision', 'unavailable'), 'framework' => app()->version(),
            'baseline_observed_at' => '2026-10-28T14:00:45+07:00/2026-10-28T14:05:00+07:00',
            'baseline_is_live' => false, 'as_of' => $window->asOf->toIso8601String(), 'timezone' => $window->timezone,
            'scenario_days' => $window->horizon, 'family' => $window->family, 'table' => $window->table,
            'requested_budgets' => $window->budgets, 'effective_budgets' => $window->budgets + ['statement_ms' => 2000, 'lock_ms' => 100, 'idle_transaction_ms' => 2000],
            'roots_visited' => 0, 'roots_evaluated' => 0, 'dependency_rows' => 0,
            'state_only_terminal' => 0, 'archive_review_candidates' => 0, 'protected' => 0, 'delete_candidates' => 0,
            'reason_counts' => [], 'reason_counts_overlap' => true, 'details' => [],
            'partial' => false, 'stop_reason' => 'window_complete', 'error_code' => null,
            'consistency' => 'observational_between_pages_and_runs_not_global_snapshot',
        ];
        $after = $window->after;
        $stop = $window->stop;
        $db = null;
        $reader = null;
        $exit = 0;
        $salt = random_bytes(32);
        try {
            $config = config('database.connections.'.config('database.default'));
            if (($config['driver'] ?? '') === 'pgsql') {
                $config['options'][\PDO::ATTR_TIMEOUT] = 1;
            }
            // A private connection has no query listeners, model retrieval or telemetry.
            $db = DB::build($config);
            $db->unsetEventDispatcher();
            $db->disableQueryLog();
            $pdo = $db->getPdo();
            if ($db->getDriverName() === 'pgsql') {
                $pdo->exec('SET default_transaction_read_only = on; SET statement_timeout = 2000; SET lock_timeout = 100; SET idle_in_transaction_session_timeout = 2000');
            } elseif ($db->getDriverName() === 'sqlite') {
                $pdo->exec('PRAGMA query_only = ON');
            } else {
                throw new RuntimeException('unsupported_database');
            }
            $reader = new RetentionReader($db, $window, $deadline);
            while (true) {
                $db->beginTransaction();
                if ($db->getDriverName() === 'pgsql') {
                    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
                }
                $reader->check();
                if ($stop === null) {
                    $last = $db->table($window->table)->orderByDesc('id')->limit(1)->value('id');
                    $stop = $last === null ? null : (string) $last;
                    $reader->check();
                }
                $remaining = $window->budgets['roots'] - $report['roots_evaluated'];
                $size = min($remaining, $window->budgets['page']);
                $query = $db->table($window->table)->orderBy('id')->limit($size);
                if ($after !== null) {
                    $query->where('id', '>', $after);
                }
                if ($stop !== null) {
                    $query->where('id', '<=', $stop);
                }
                $rows = $query->get($this->classifier->columns($window->family))->all();
                $report['roots_visited'] += count($rows);
                $page = [];
                foreach ($rows as $row) {
                    $reader->root();
                    $result = $this->classifier->evaluate($window->family, $row, $window, $reader);
                    $result['correlation'] = substr(hash_hmac('sha256', $window->family.':'.$row->id, $salt), 0, 24);
                    $page[] = [(string) $row->id, $result];
                    if ($reader->truncated) {
                        break;
                    }
                }
                $reader->check();
                $db->commit();
                // Commit the report cursor only after the complete page transaction.
                foreach ($page as [$id, $result]) {
                    $after = $id;
                    $report['roots_evaluated']++;
                    $report['protected']++;
                    $report['state_only_terminal'] += (int) $result['state_only_terminal'];
                    foreach ($result['reasons'] as $reason) {
                        $report['reason_counts'][$reason] = ($report['reason_counts'][$reason] ?? 0) + 1;
                    }
                    if (count($report['details']) < $window->budgets['details']) {
                        $report['details'][] = $result;
                    }
                }
                if ($reader->truncated) {
                    $report['partial'] = true;
                    $report['stop_reason'] = 'dependency_budget_exceeded';
                    $exit = 2;
                    break;
                }
                if (count($rows) < $size || $after === $stop || $stop === null) {
                    break;
                }
                if ($report['roots_evaluated'] >= $window->budgets['roots'] || $reader->dependencies >= $window->budgets['dependencies']) {
                    $report['partial'] = true;
                    $report['stop_reason'] = $report['roots_evaluated'] >= $window->budgets['roots'] ? 'root_budget' : 'dependency_budget_exceeded';
                    $exit = 2;
                    break;
                }
            }
        } catch (Throwable $error) {
            $code = $this->safeError($error);
            $exit = in_array($code, ['query_timeout', 'lock_timeout', 'wall_clock_budget', 'session_timeout'], true) ? 2 : 1;
            $report['partial'] = true;
            $report['stop_reason'] = $code;
            $report['error_code'] = $code;
        } finally {
            if ($db instanceof Connection) {
                try {
                    if ($db->transactionLevel() > 0) {
                        $db->rollBack();
                    }
                } catch (Throwable) {
                    // A server-side idle timeout may already have closed the session.
                }
                $db->disconnect();
            }
        }
        $report['dependency_rows'] = $reader?->dependencies ?? 0;
        $report['duration_ms'] = round((microtime(true) - $start) * 1000, 3);
        $report['operator_artifact'] = [
            'restricted' => true, 'cursor_before' => $window->after, 'cursor_after' => $after, 'observed_stop_key' => $stop,
            'resume' => base64_encode(json_encode($window->manifest($after, $stop), JSON_THROW_ON_ERROR)),
        ];

        return [$report, $exit];
    }

    private function safeError(Throwable $error): string
    {
        $cause = $error;
        while ($cause->getPrevious() !== null) {
            $cause = $cause->getPrevious();
        }
        $state = $cause instanceof PDOException ? (string) ($cause->errorInfo[0] ?? $cause->getCode()) : '';

        return match ($state) {
            '57014' => 'query_timeout', '55P03' => 'lock_timeout', '25P03', '08006' => 'session_timeout',
            default => $error instanceof RuntimeException && in_array($error->getMessage(), ['wall_clock_budget', 'unsupported_database'], true) ? $error->getMessage() : 'database_or_runtime_error',
        };
    }
}
