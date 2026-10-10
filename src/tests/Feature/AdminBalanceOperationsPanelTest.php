<?php

namespace Tests\Feature;

use App\Enums\BalanceAlertSeverity;
use App\Enums\BalanceAlertState;
use App\Enums\CustomerUsdtFundingPauseScope;
use App\Models\BalanceOperationsAlertEpisode;
use App\Models\BalanceRestoreGate;
use App\Models\CustomerUsdtFundingPause;
use App\Services\Admin\AdminBalanceOperationsData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminBalanceOperationsPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_summary_reports_pauses_gate_and_open_alerts(): void
    {
        CustomerUsdtFundingPause::query()->create([
            'scope' => CustomerUsdtFundingPauseScope::Delivery->value,
            'reason' => 'Bukti saldo ditahan sementara',
            'paused_at' => now(),
        ]);
        BalanceRestoreGate::current()->forceFill([
            'state' => 'open',
            'window_start' => now()->subHour(),
            'window_end' => now(),
            'opened_at' => now(),
            'reason' => 'Restore uji',
        ])->save();
        BalanceOperationsAlertEpisode::query()->create([
            'alert_key' => 'reconciliation_drift',
            'severity' => BalanceAlertSeverity::Critical->value,
            'state' => BalanceAlertState::Open->value,
            'summary' => 'Temuan drift saldo USDT pada rekonsiliasi penuh terakhir (run #7).',
            'fingerprint' => str_repeat('a', 64),
            'evidence' => ['run_id' => 7],
            'occurrences' => 3,
            'first_seen_at' => now()->subMinutes(30),
            'last_seen_at' => now(),
        ]);

        $summary = app(AdminBalanceOperationsData::class)->summary();

        $this->assertCount(count(CustomerUsdtFundingPauseScope::cases()), $summary['pauses']);
        $delivery = collect($summary['pauses'])->firstWhere('scope', 'delivery');
        $this->assertTrue($delivery['paused']);

        $this->assertTrue($summary['restoreGate']['open']);
        $this->assertSame('Terbuka', $summary['restoreGate']['state_label']);

        $this->assertCount(1, $summary['alerts']);
        $this->assertSame('reconciliation_drift', $summary['alerts'][0]['alert_key']);
        $this->assertSame('Kritis', $summary['alerts'][0]['severity_label']);
        $this->assertSame(3, $summary['alerts'][0]['occurrences']);

        $this->assertSame(1, $summary['alertCounts']['open']);
        $this->assertSame(1, $summary['alertCounts']['critical']);
        $this->assertSame(0, $summary['alertCounts']['warning']);
    }
}
