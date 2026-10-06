<?php

namespace Tests\Feature;

use App\Enums\BalanceAlertSeverity;
use App\Enums\BalanceAlertState;
use App\Enums\CustomerUsdtFundingPauseScope;
use App\Enums\UserRole;
use App\Models\BalanceOperationsAlertEpisode;
use App\Models\BalanceRestoreGate;
use App\Models\CustomerUsdtFundingPause;
use App\Models\User;
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

    public function test_stats_tab_renders_read_only_operations_card(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        CustomerUsdtFundingPause::query()->create([
            'scope' => CustomerUsdtFundingPauseScope::Delivery->value,
            'reason' => 'Bukti saldo ditahan sementara',
            'paused_at' => now(),
        ]);
        BalanceOperationsAlertEpisode::query()->create([
            'alert_key' => 'restore_gate_open',
            'severity' => BalanceAlertSeverity::Critical->value,
            'state' => BalanceAlertState::Open->value,
            'summary' => 'Gerbang pemulihan saldo terbuka.',
            'fingerprint' => str_repeat('b', 64),
            'evidence' => ['gate_state' => 'open'],
            'occurrences' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $response = $this->actingAs($owner)->get('/admin?tab=stats');

        $this->assertCount(1, app(AdminBalanceOperationsData::class)->summary()['alerts']);
        $response->assertOk();
        $response->assertSee('Kontrol Operasional');
        $response->assertSee('Pengiriman bukti saldo');
        $response->assertSee('DIJEDA');
        $response->assertSee('restore_gate_open');
        $response->assertSee('Gerbang pemulihan saldo terbuka.');
    }

    public function test_stats_tab_renders_without_alerts_or_pauses(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $response = $this->actingAs($owner)->get('/admin?tab=stats');

        $response->assertOk();
        $response->assertSee('Kontrol Operasional');
        $response->assertSee('Tidak ada episode alert terbuka.');
    }
}
