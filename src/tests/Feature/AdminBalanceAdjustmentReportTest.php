<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\User;
use App\Services\Admin\AdminBalanceReportReader;
use App\Services\StoreBalance\BalanceAdjustmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\NonTransactionalDatabaseTestCase;

#[Group('postgres-release')]
final class AdminBalanceAdjustmentReportTest extends NonTransactionalDatabaseTestCase
{
    public function test_adjustment_reports_keep_currencies_and_audit_subjects_separate(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner, 'password' => 'owner-password']);
        $this->actingAs($owner);
        $session = app('session')->driver();
        $session->start();
        request()->setLaravelSession($session);
        config(['services.telegram.admin_telegram_chat_id' => '998877']);
        Queue::fake();
        Customer::factory()->create(['telegram_id' => 940001]);
        Customer::factory()->create(['telegram_id' => 940002]);
        $service = app(BalanceAdjustmentService::class);
        $idr = $service->request($owner, '940001', 12345, 'IDR adjustment');
        $usdt = $service->request($owner, '940002', '1', 'USDT adjustment', currency: 'USDT');
        $service->confirm($owner, $idr->id, $idr->fingerprint, 1, 'owner-password');
        $service->confirm($owner, $usdt->id, $usdt->fingerprint, 1, 'owner-password');

        DB::table('audit_logs')->insert([
            ['action' => 'balance.adjustment.lockout', 'subject_type' => null, 'subject_id' => null, 'created_at' => now()],
            ['action' => 'balance.adjustment.reversal_review', 'subject_type' => null, 'subject_id' => null, 'created_at' => now()],
        ]);
        $reader = app(AdminBalanceReportReader::class);
        $reviews = $reader->page($owner, ['section' => 'reviews', 'currency' => 'IDR']);
        $this->assertCount(1, $reviews['rows']);
        $this->assertSame('Rp 12.345', $reviews['rows'][0]['amount']);
        $this->assertSame([], $reader->page($owner, [
            'section' => 'reviews', 'currency' => 'IDR', 'telegram_id' => '940002',
        ])['rows']);

        foreach ([
            ['IDR', '940001', '+Rp 12.345'],
            ['USDT', '940002', '+1 USDT'],
        ] as [$currency, $telegramId, $amount]) {
            $entries = $reader->page($owner, [
                'section' => 'entries', 'currency' => $currency, 'telegram_id' => $telegramId,
            ]);
            $this->assertCount(1, $entries['rows']);
            $this->assertSame($amount, $entries['rows'][0]['amount']);
            $audit = $reader->page($owner, [
                'section' => 'audit', 'currency' => $currency, 'telegram_id' => $telegramId,
            ]);
            $this->assertEqualsCanonicalizing(
                ['balance.adjustment.requested', 'balance.adjustment.applied'],
                array_column($audit['rows'], 'status'),
            );
            $requested = $reader->page($owner, [
                'section' => 'audit', 'currency' => $currency, 'telegram_id' => $telegramId,
                'state' => 'balance.adjustment.requested',
            ]);
            $this->assertCount(1, $requested['rows']);
        }

        foreach ([['IDR', '940002'], ['USDT', '940001']] as [$currency, $telegramId]) {
            $this->assertSame([], $reader->page($owner, [
                'section' => 'audit', 'currency' => $currency, 'telegram_id' => $telegramId,
            ])['rows']);
        }
        $idrAudit = $reader->page($owner, ['section' => 'audit', 'currency' => 'IDR']);
        $this->assertEqualsCanonicalizing(
            ['balance.adjustment.requested', 'balance.adjustment.applied', 'balance.adjustment.lockout', 'balance.adjustment.reversal_review'],
            array_column($idrAudit['rows'], 'status'),
        );
        $usdtAudit = $reader->page($owner, ['section' => 'audit', 'currency' => 'USDT']);
        $this->assertEqualsCanonicalizing(
            ['balance.adjustment.requested', 'balance.adjustment.applied'],
            array_column($usdtAudit['rows'], 'status'),
        );
    }
}
