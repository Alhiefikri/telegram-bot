<?php

namespace Tests\Feature\Telegram;

use App\Models\TelegramOutboundEffect;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\Telegram\TelegramReceiptObservability;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Tests\Support\TelegramBotTestSupport;

final class TelegramReceiptObservabilityTest extends TelegramBotTestSupport
{
    public function test_synthetic_benchmark_covers_all_sizes_and_rolls_back_fixture_data(): void
    {
        $exitCode = Artisan::call('telegram:benchmark-receipt-txt', ['--json' => true]);

        $output = Artisan::output();
        $this->assertSame(0, $exitCode, $output);
        $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($report['pass']);
        $this->assertSame([1, 5, 20, 50], array_column($report['samples'], 'item_size'));
        $this->assertSame(
            [1, 5, 20, 50],
            array_values(array_filter(
                $report['fixture']['item_sizes'],
                static fn (int $size): bool => in_array($size, [1, 5, 20, 50], true),
            )),
        );
        $this->assertSame(
            1,
            count(array_unique(array_column($report['samples'], 'db_query_count'))),
            'The exporter query count must remain bounded as item count grows.',
        );
        $this->assertTrue(collect($report['samples'])->every(
            static fn (array $sample): bool => $sample['effect_state'] === 'sent'
                && $sample['output_bytes'] <= $report['thresholds']['max_output_bytes']
                && $sample['violations'] === [],
        ));

        $this->assertStringNotContainsString('benchmark-password', $output);
        $this->assertStringNotContainsString('@example.test', $output);
        $this->assertStringNotContainsString('documentContents', $output);
        $this->assertStringNotContainsString('otp_url', $output);
        $this->assertSame(0, TelegramOutboundEffect::query()->count());
        $this->assertDatabaseCount('product_stock_items', 0);
    }

    public function test_counter_and_review_projection_allowlists_never_emit_raw_credential_metadata(): void
    {
        config()->set('services.dogfood_timing.enabled', true);
        $logs = [];
        Log::shouldReceive('info')->zeroOrMoreTimes()->withArgs(function (string $event, array $context = []) use (&$logs): bool {
            $logs[] = [$event, $context];

            return true;
        });
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        $secret = 'benchmark-password@example.test|auth-token|https://otp.example.test/full-secret';
        $effect = TelegramOutboundEffect::factory()->create([
            'effect_type' => TelegramOutboundEffectService::TYPE_RECEIPT_TXT_DOWNLOAD,
            'outcome_metadata' => [
                'delivery_context' => 'explicit_txt_download',
                'format' => 'txt',
                'product_count' => 1,
                'item_count' => 1,
                'txt_available' => true,
                'render_duration_bucket' => '0-10',
                'output_size_bucket' => '0-1024',
                'secret_field' => $secret,
                'document_text' => $secret,
            ],
        ]);

        $observability = app(TelegramReceiptObservability::class);
        $observability->actionPanelMaterialized($effect);
        $observability->txtRequestRejected('download_window', kind: 'rate_limit');
        $observability->txtRequestStale();
        $observability->txtOutcome($effect, 'failed', 12.4, $secret);
        $observability->txtRender($effect, 12.4, 128, 1024, 2048, [
            'db_query_count' => 7,
            'db_query_total_ms' => 1.2,
            'db_query_slowest_ms' => 0.4,
        ]);
        $observability->txtDocumentAttempt($effect, 4.2, 'ok');

        $summary = app(TelegramOutboundEffectService::class)->reviewSummary($effect->fresh());
        $serialized = json_encode([$logs, $summary], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($secret, $serialized);
        $this->assertStringNotContainsString('secret_field', $serialized);
        $this->assertStringNotContainsString('document_text', $serialized);
        $this->assertSame('0-10', $summary['metadata']['render_duration_bucket'] ?? null);
        $this->assertSame('0-1024', $summary['metadata']['output_size_bucket'] ?? null);

        $metrics = collect($logs)
            ->filter(static fn (array $entry): bool => $entry[0] === 'dogfood.telegram.receipt_counter')
            ->map(static fn (array $entry): string => (string) ($entry[1]['metric'] ?? ''))
            ->all();
        $this->assertContains('receipt_actions_materialized', $metrics);
        $this->assertContains('receipt_txt_request_rejected', $metrics);
        $this->assertContains('receipt_txt_request_stale', $metrics);
        $this->assertContains('receipt_txt_failed', $metrics);
    }
}
