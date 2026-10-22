<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramLatencyBaselineCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_runner_requires_explicit_disposable_acknowledgement(): void
    {
        $this->artisan('telegram:latency-baseline', [
            '--scenario' => 'idle',
            '--batches' => 1,
            '--samples' => 1,
            '--warmup-batches' => 0,
        ])
            ->expectsOutputToContain('Refusing to run without --confirm-disposable')
            ->assertFailed();
    }

    public function test_runner_refuses_non_postgresql_connections(): void
    {
        $this->artisan('telegram:latency-baseline', [
            '--scenario' => 'idle',
            '--batches' => 1,
            '--samples' => 1,
            '--warmup-batches' => 0,
            '--confirm-disposable' => true,
        ])
            ->expectsOutputToContain('requires a disposable PostgreSQL connection')
            ->assertFailed();
    }

    public function test_runner_rejects_an_unknown_worker_topology(): void
    {
        $this->artisan('telegram:latency-baseline', [
            '--scenario' => 'idle',
            '--worker-topology' => 'one-big-worker',
            '--confirm-disposable' => true,
        ])
            ->expectsOutputToContain('--worker-topology must be split or combined')
            ->assertFailed();
    }

    public function test_runner_rejects_unknown_scenarios_and_out_of_bound_options(): void
    {
        $this->artisan('telegram:latency-baseline', [
            '--scenario' => 'observe-everything',
            '--confirm-disposable' => true,
        ])
            ->expectsOutputToContain('Unknown scenario: observe-everything')
            ->assertFailed();

        $this->artisan('telegram:latency-baseline', [
            '--scenario' => 'idle',
            '--batches' => 11,
            '--confirm-disposable' => true,
        ])
            ->expectsOutputToContain('--batches must be between 1 and 10')
            ->assertFailed();
    }
}
