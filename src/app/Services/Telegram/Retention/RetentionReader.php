<?php

namespace App\Services\Telegram\Retention;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use RuntimeException;

final class RetentionReader
{
    public int $dependencies = 0;

    public int $rootDependencies = 0;

    public bool $truncated = false;

    public function __construct(public readonly Connection $db, private readonly RetentionWindow $window, private readonly float $deadline) {}

    public function check(): void
    {
        $remaining = (int) floor(($this->deadline - microtime(true)) * 1000);
        if ($remaining <= 0) {
            throw new RuntimeException('wall_clock_budget');
        }
        if ($this->db->getDriverName() === 'pgsql') {
            $this->db->getPdo()->exec('SET LOCAL statement_timeout = '.min(2000, $remaining));
        }
    }

    public function rows(Builder $query, array $columns): array
    {
        $this->check();
        $remaining = min($this->window->budgets['dependencies'] - $this->dependencies, $this->window->budgets['per_root'] - $this->rootDependencies);
        if ($remaining < 1) {
            $this->truncated = true;

            return [];
        }
        // Reserve one row within the hard budget to detect an incomplete closure.
        if ($columns === NotificationRetentionClassifier::EFFECT_COLUMNS) {
            $columns = NotificationRetentionClassifier::effectColumns();
        }
        $rows = $query->limit($remaining)->get($columns)->all();
        $this->dependencies += count($rows);
        $this->rootDependencies += count($rows);
        if (count($rows) === $remaining) {
            $this->truncated = true;
            array_pop($rows);
        }

        return $rows;
    }

    public function root(): void
    {
        $this->rootDependencies = 0;
        $this->truncated = false;
        $this->check();
    }
}
