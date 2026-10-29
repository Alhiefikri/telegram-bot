<?php

namespace Tests\Feature;

use App\Enums\BalanceAlertState;
use App\Enums\CustomerUsdtFundingPauseScope;
use App\Enums\ExternalMoneyReceiptStatus;
use App\Enums\TelegramOutboundEffectType;
use App\Enums\UsdtReconciliationVerdict;
use App\Enums\UserRole;
use App\Models\BalanceOperationsAlertEpisode;
use App\Models\Customer;
use App\Models\CustomerUsdtBalanceAccount;
use App\Models\ExternalMoneyReceipt;
use App\Models\TelegramOutboundEffect;
use App\Models\UsdtBalanceReconciliationRun;
use App\Models\User;
use App\Services\Operations\BalanceOperationsAlertService;
use App\Services\UsdtFunding\CustomerUsdtFundingPolicyManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class BalanceOperationsAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()->set('services.telegram.admin_telegram_chat_id', '900100200');
    }

    public function test_drift_finding_opens_one_episode_and_one_sanitized_effect(): void
    {
        $run = $this->fullRun(['verdict' => UsdtReconciliationVerdict::Findings, 'account_finding_count' => 2]);

        $evaluation = $this->service()->evaluate();

        $this->assertSame(1, $evaluation->opened);
        $this->assertSame(0, $evaluation->updated);
        $this->assertSame(0, $evaluation->resolved);
        $this->assertSame(1, $evaluation->notified);
        $this->assertSame(0, $evaluation->deliveryPending);

        $episode = BalanceOperationsAlertEpisode::query()->sole();
        $this->assertSame(BalanceOperationsAlertService::ALERT_RECONCILIATION_DRIFT, $episode->alert_key);
        $this->assertSame(BalanceAlertState::Open, $episode->state);
        $this->assertSame(1, $episode->occurrences);
        $this->assertNotNull($episode->last_notified_at);
        $this->assertNull($episode->resolved_at);

        $effects = $this->alertEffects();
        $this->assertCount(1, $effects);
        $message = $this->messageOf($effects->sole());
        $this->assertStringStartsWith('[KRITIS]', $message);
        $this->assertStringContainsString('run #'.$run->id, $message);
        $this->assertStringNotContainsString('{', $message);
    }

    public function test_repeated_evaluation_keeps_one_effect_and_increments_occurrences(): void
    {
        $this->fullRun(['verdict' => UsdtReconciliationVerdict::Findings, 'account_finding_count' => 1]);

        $first = $this->service()->evaluate();
        $second = $this->service()->evaluate();

        $this->assertSame(1, $first->notified);
        $this->assertSame(0, $second->notified);
        $this->assertSame(1, $second->updated);

        $episode = BalanceOperationsAlertEpisode::query()->sole();
        $this->assertSame(2, $episode->occurrences);

        $effects = $this->alertEffects();
        $this->assertCount(1, $effects);
    }

    public function test_new_reconciliation_run_does_not_repeat_an_unchanged_treasury_incident(): void
    {
        $condition = [
            'verdict' => UsdtReconciliationVerdict::Findings,
            'liability_micro' => 2000000,
            'custody_state' => 'missing',
            'findings' => ['treasury' => ['findings' => ['missing_custody_input']]],
        ];
        $this->fullRun($condition);
        $this->assertSame(1, $this->service()->evaluate()->notified);

        $this->travel(5)->minutes();
        $latest = $this->fullRun($condition);
        $this->assertSame(0, $this->service()->evaluate()->notified);
        $this->assertCount(1, $this->alertEffects());
        $episode = BalanceOperationsAlertEpisode::query()
            ->where('alert_key', BalanceOperationsAlertService::ALERT_TREASURY_COVERAGE)->sole();
        $this->assertSame($latest->id, $episode->evidence['run_id']);
        $this->assertSame(2, $episode->occurrences);
    }

    public function test_changed_treasury_risk_notifies_and_resolution_can_reopen(): void
    {
        $this->fullRun(['liability_micro' => 2000000, 'custody_state' => 'missing']);
        $this->assertSame(1, $this->service()->evaluate()->notified);

        $this->fullRun(['liability_micro' => 4000000, 'custody_state' => 'missing']);
        $this->assertSame(1, $this->service()->evaluate()->notified);

        $this->fullRun(['liability_micro' => 4000000, 'custody_state' => 'available']);
        $this->assertSame(1, $this->service()->evaluate()->resolved);

        $this->fullRun(['liability_micro' => 4000000, 'custody_state' => 'missing']);
        $this->assertSame(1, $this->service()->evaluate()->notified);
        $this->assertSame(4, $this->alertEffects()->count());
    }

    public function test_runtime_failure_notifies_once_per_episode_and_resolution(): void
    {
        $first = $this->service()->reconcileSchedulerRuntime('outbound-recovery', false, 'recovery_timeout');
        $repeat = $this->service()->reconcileSchedulerRuntime('outbound-recovery', false, 'recovery_exit');

        $this->assertSame(1, $first->opened);
        $this->assertSame(1, $first->notified);
        $this->assertSame(1, $repeat->updated);
        $this->assertSame(0, $repeat->notified);
        $this->assertCount(1, $this->alertEffects());

        $episode = BalanceOperationsAlertEpisode::query()->sole();
        $this->assertSame(BalanceOperationsAlertService::ALERT_TELEGRAM_RECOVERY_RUNTIME_FAILURE, $episode->alert_key);
        $this->assertSame(BalanceAlertState::Open, $episode->state);
        $this->assertSame(2, $episode->occurrences);

        $resolved = $this->service()->reconcileSchedulerRuntime('outbound-recovery', true);

        $this->assertSame(1, $resolved->resolved);
        $this->assertSame(1, $resolved->notified);
        $this->assertSame(BalanceAlertState::Resolved, $episode->fresh()->state);
        $this->assertCount(2, $this->alertEffects());
        $resolution = $this->alertEffects()->first(
            fn (TelegramOutboundEffect $effect): bool => ($effect->outcome_metadata['alert_state'] ?? null) === 'resolved',
        );
        $this->assertNotNull($resolution);
        $this->assertStringStartsWith('[SELESAI]', $this->messageOf($resolution));
    }

    public function test_scheduler_runtime_alert_command_opens_alert_for_scheduler_oom(): void
    {
        $this->artisan('ops:scheduler-runtime-alert', [
            'component' => 'scheduler',
            'state' => 'failed',
            '--reason' => 'oom_kill',
        ])->assertSuccessful();

        $episode = BalanceOperationsAlertEpisode::query()->sole();
        $this->assertSame(BalanceOperationsAlertService::ALERT_SCHEDULER_RUNTIME_FAILURE, $episode->alert_key);
        $this->assertSame(BalanceAlertState::Open, $episode->state);
        $this->assertSame(1, $this->alertEffects()->count());
    }

    public function test_changed_fingerprint_materializes_a_second_effect(): void
    {
        $this->reviewReceipt();

        $first = $this->service()->evaluate();
        $this->assertSame(1, $first->opened);
        $this->assertSame(1, $first->notified);

        $this->reviewReceipt();

        $second = $this->service()->evaluate();
        $this->assertSame(1, $second->notified);
        $this->assertSame(1, $second->updated);

        $this->assertCount(2, $this->alertEffects());
        $this->assertSame(2, BalanceOperationsAlertEpisode::query()->sole()->occurrences);
    }

    public function test_cleared_condition_resolves_episode_and_materializes_resolution_effect(): void
    {
        $this->fullRun(['verdict' => UsdtReconciliationVerdict::Findings, 'account_finding_count' => 1]);
        $this->service()->evaluate();

        $this->fullRun(['verdict' => UsdtReconciliationVerdict::Consistent, 'account_finding_count' => 0]);

        $evaluation = $this->service()->evaluate();

        $this->assertSame(1, $evaluation->resolved);
        $this->assertSame(1, $evaluation->notified);

        $episode = BalanceOperationsAlertEpisode::query()->sole();
        $this->assertSame(BalanceAlertState::Resolved, $episode->state);
        $this->assertNotNull($episode->resolved_at);

        $effects = $this->alertEffects();
        $this->assertCount(2, $effects);

        $resolution = $effects->first(
            fn (TelegramOutboundEffect $effect): bool => ($effect->outcome_metadata['alert_state'] ?? null) === 'resolved',
        );
        $this->assertNotNull($resolution, 'A cleared condition must materialize one resolution notice.');
        $this->assertSame('resolved', $resolution->outcome_metadata['alert_state']);
        $this->assertStringStartsWith('[SELESAI]', $this->messageOf($resolution));
    }

    public function test_all_clear_database_reports_zeros_and_opens_nothing(): void
    {
        $evaluation = $this->service()->evaluate();

        $this->assertSame(
            ['opened' => 0, 'updated' => 0, 'resolved' => 0, 'notified' => 0, 'delivery_pending' => 0],
            $evaluation->toArray(),
        );
        $this->assertSame(0, BalanceOperationsAlertEpisode::query()->count());
        $this->assertSame(0, TelegramOutboundEffect::query()->count());
    }

    public function test_command_reports_json_counts_shape(): void
    {
        $exit = Artisan::call('ops:balance-alerts', ['--json' => true]);
        $this->assertSame(0, $exit);

        $decoded = json_decode(trim(Artisan::output()), true);
        $this->assertSame(
            ['opened' => 0, 'updated' => 0, 'resolved' => 0, 'notified' => 0, 'delivery_pending' => 0],
            $decoded,
        );

        $exit = Artisan::call('ops:balance-alerts');
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('opened: 0', Artisan::output());
    }

    public function test_missing_admin_chat_id_keeps_episode_open_without_throwing(): void
    {
        config()->set('services.telegram.admin_telegram_chat_id', '');

        $this->fullRun(['verdict' => UsdtReconciliationVerdict::Findings, 'account_finding_count' => 1]);

        $evaluation = $this->service()->evaluate();

        $this->assertSame(1, $evaluation->opened);
        $this->assertSame(0, $evaluation->notified);
        $this->assertSame(1, $evaluation->deliveryPending);

        $this->assertSame(0, TelegramOutboundEffect::query()->count());

        $episode = BalanceOperationsAlertEpisode::query()->sole();
        $this->assertSame(BalanceAlertState::Open, $episode->state);
        $this->assertNull($episode->last_notified_at);
    }

    public function test_alert_messages_never_include_identifiers(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 918273645]);
        CustomerUsdtBalanceAccount::query()->create(['customer_id' => $customer->id])
            ->forceFill(['frozen_reason' => 'reorg'])
            ->save();

        $txHash = '0x'.str_repeat('cd', 32);
        $this->reviewReceipt($txHash);

        $this->fullRun(['verdict' => UsdtReconciliationVerdict::Findings, 'account_finding_count' => 1]);

        $this->service()->evaluate();

        $messages = $this->alertEffects()
            ->map(fn (TelegramOutboundEffect $effect): string => $this->messageOf($effect))
            ->implode("\n");

        $this->assertNotSame('', $messages);
        $this->assertStringNotContainsString('918273645', $messages);
        $this->assertStringNotContainsString($txHash, $messages);
        $this->assertStringNotContainsString('customer_id', $messages);
        $this->assertStringNotContainsString('evidence', $messages);
    }

    public function test_a_reopened_episode_with_an_unchanged_fingerprint_is_announced_again(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $manager = app(CustomerUsdtFundingPolicyManager::class);

        // A stable signal: the delivery pause fingerprint does not change.
        $manager->pause(CustomerUsdtFundingPauseScope::Delivery, 'delivery drill', $owner, null);
        $this->assertSame(1, $this->service()->evaluate()->notified);

        // Resume resolves the episode and announces the completion.
        $manager->resume(CustomerUsdtFundingPauseScope::Delivery, $owner, null);
        $this->assertSame(1, $this->service()->evaluate()->resolved);

        // The same condition returns with the same fingerprint; it must still
        // reach the administrator instead of silently reusing a sent effect.
        $manager->pause(CustomerUsdtFundingPauseScope::Delivery, 'delivery drill again', $owner, null);
        $evaluation = $this->service()->evaluate();

        $this->assertSame(1, $evaluation->opened);
        $this->assertSame(1, $evaluation->notified);
        $this->assertSame(3, $this->alertEffects()->count());

        $episode = BalanceOperationsAlertEpisode::query()->sole();
        $this->assertSame(BalanceAlertState::Open, $episode->state);
        $this->assertNotNull($episode->last_notified_at);
        $states = $this->alertEffects()
            ->map(fn (TelegramOutboundEffect $effect): string => (string) ($effect->outcome_metadata['alert_state'] ?? ''))
            ->all();
        sort($states);
        $this->assertSame(['open', 'open', 'resolved'], $states, 'The reopened condition and its earlier resolution must both be announced once.');

        // Re-evaluating the same transition still dedupes.
        $this->assertSame(0, $this->service()->evaluate()->notified);
        $this->assertSame(3, $this->alertEffects()->count());
    }

    private function service(): BalanceOperationsAlertService
    {
        return app(BalanceOperationsAlertService::class);
    }

    /** @return Collection<int, TelegramOutboundEffect> */
    private function alertEffects()
    {
        return TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectType::BalanceOperationsAlert->value)
            ->orderBy('created_at')
            ->orderBy('effect_key')
            ->get();
    }

    private function messageOf(TelegramOutboundEffect $effect): string
    {
        $metadata = is_array($effect->outcome_metadata) ? $effect->outcome_metadata : [];

        return (string) ($metadata['message'] ?? '');
    }

    /** @param  array<string, mixed>  $overrides */
    private function fullRun(array $overrides = []): UsdtBalanceReconciliationRun
    {
        return UsdtBalanceReconciliationRun::query()->create(array_merge([
            'scope' => 'full',
            'customer_id' => null,
            'cursor_after' => null,
            'receipt_cursor_after' => null,
            'credit_cursor_after' => null,
            'limit' => 100,
            'checked_at' => now(),
            'created_at' => now(),
            'verdict' => UsdtReconciliationVerdict::Consistent,
            'accounts_inspected' => 0,
            'account_finding_count' => 0,
            'drift_frozen_count' => 0,
            'receipt_finding_count' => 0,
            'liability_micro' => 0,
            'unresolved_deposit_micro' => 0,
            'custody_state' => 'available',
            'custody_assets_micro' => null,
            'movement_in_micro' => 0,
            'movement_out_micro' => 0,
            'movement_count' => 0,
            'aggregate_pending_events' => 0,
            'actor' => null,
            'findings' => [],
        ], $overrides));
    }

    private function reviewReceipt(string $txHash = ''): ExternalMoneyReceipt
    {
        $suffix = bin2hex(random_bytes(8));

        return ExternalMoneyReceipt::query()->create([
            'canonical_key' => 'test:review:'.$suffix,
            'provider' => 'test',
            'identity_kind' => 'transaction',
            'account_namespace' => 'test',
            'reference' => null,
            'chain_id' => 56,
            'tx_hash' => $txHash === '' ? '0x'.bin2hex(random_bytes(32)) : $txHash,
            'log_index' => null,
            'beneficiary' => 'customer:test',
            'currency' => 'USDT',
            'amount_units' => '1000000',
            'decimals' => 6,
            'status' => ExternalMoneyReceiptStatus::Review->value,
            'target_type' => 'customer_usdt_top_up',
            'target_id' => $suffix,
            'fingerprint' => hash('sha256', 'review:'.$suffix),
        ]);
    }
}
