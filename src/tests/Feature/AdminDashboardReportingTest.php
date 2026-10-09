<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Admin\AdminDashboardData;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\TestCase;

final class AdminDashboardReportingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        KlikQrisSandboxScenarios::configureReadySandbox();
        config()->set('services.provider_verification.reconciliation.retry_delay_ms', 0);
        config()->set('services.provider_verification.reconciliation.retry_max_delay_ms', 0);
        config()->set('services.klikqris.reporting_cache_seconds', 0);
        Http::preventStrayRequests();
    }

    public function test_stats_dashboard_does_not_request_reporting_data(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        Http::fake();

        $this->get(route('admin.dashboard', ['tab' => 'stats']))
            ->assertOk()
            ->assertSee('Ringkasan')
            ->assertDontSee('Tidak ada Order lokal yang cocok')
            ->assertDontSee('Data KlikQRIS belum dapat dimuat');

        Http::assertNothingSent();
    }

    public function test_stats_products_and_sales_tabs_do_not_request_reporting_data(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        Http::fake();

        foreach (['stats', 'produk', 'penjualan'] as $tab) {
            $this->get(route('admin.dashboard', ['tab' => $tab]))->assertOk();
        }

        Http::assertNothingSent();
    }

    public function test_history_dashboard_renders_local_matches_and_provider_mismatches_read_only(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        $order = Order::factory()->create([
            'invoice_number' => 'INV-247-LOCAL',
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Pending,
        ]);
        Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'klikqris',
            'merchant_ref' => 'KLIK-247-MATCH',
            'amount' => 125000,
            'status' => PaymentStatus::Pending,
        ]);
        Http::fake([
            'https://klikqris.com/api/sandbox/qris/history*' => Http::response($this->historyPayload([
                [
                    'order_id' => 'KLIK-247-MATCH',
                    'created_at' => '2026-08-14 10:15:00',
                    'total_amount' => '125000',
                    'status' => 'success',
                    'type' => 'qris',
                    'keterangan' => 'Payment KLIK-247-MATCH',
                    'via' => 'mobile',
                ],
                [
                    'order_id' => 'KLIK-247-UNKNOWN',
                    'created_at' => '2026-08-14 10:16:00',
                    'total_amount' => '99000',
                    'status' => 'pending',
                    'type' => null,
                    'keterangan' => 'api_key=provider-secret',
                    'via' => null,
                ],
            ], currentPage: 2, lastPage: 3), 200),
        ]);

        $this->get(route('admin.dashboard', ['tab' => 'riwayat', 'page' => 2]))
            ->assertOk()
            ->assertSee('Riwayat KlikQRIS')
            ->assertSee('KLIK-247-MATCH')
            ->assertSee('KLIK-247-UNKNOWN')
            ->assertSee('IDR 125.000')
            ->assertSee('Payment KLIK-247-MATCH')
            ->assertSee('Detail order')
            ->assertSee('Tidak ada Order lokal yang cocok')
            ->assertSee('[redacted-secret]')
            ->assertDontSee('provider-secret', false)
            ->assertSee('Sebelumnya')
            ->assertSee('Berikutnya')
            ->assertSee(route('admin.orders.show', ['order' => $order]));

        $freshOrder = $order->fresh();
        $this->assertSame(OrderStatus::Processing, $freshOrder->order_status);
        $this->assertSame(PaymentStatus::Pending, $freshOrder->payment_status);
    }

    public function test_reporting_cache_retains_provider_fetch_timestamp_across_dashboard_reads(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        config()->set('services.klikqris.reporting_cache_seconds', 60);
        Http::fake([
            'https://klikqris.com/api/sandbox/qris/history*' => Http::response($this->historyPayload([[
                'order_id' => 'KLIK-247-CACHED',
                'created_at' => '2026-08-14 10:15:00',
                'total_amount' => '125000',
                'status' => 'success',
                'type' => 'qris',
                'keterangan' => null,
                'via' => 'mobile',
            ]]), 200),
        ]);

        try {
            Carbon::setTestNow(Carbon::parse('2026-08-14 12:00:00'));
            $first = app(AdminDashboardData::class)->forActiveTab($owner, 'riwayat', null, 1, ['page' => 1]);

            Carbon::setTestNow(Carbon::parse('2026-08-14 12:00:30'));
            $second = app(AdminDashboardData::class)->forActiveTab($owner, 'riwayat', null, 1, ['page' => 1]);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertNotNull($first['historyTab']['last_fetched_at']);
        $this->assertSame($first['historyTab']['last_fetched_at'], $second['historyTab']['last_fetched_at']);
        $this->assertFalse($second['historyTab']['stale']);
        Http::assertSentCount(1);
    }

    public function test_withdraw_dashboard_validates_filters_and_renders_safe_proof_state(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        Http::fake([
            'https://klikqris.com/api/sandbox/withdraw/orders*' => Http::response($this->withdrawalPayload([
                [
                    'order_id' => 'KLIK-247-WITHDRAW',
                    'original_amount' => '100000',
                    'amount_paid_uniq' => '100016',
                    'mdr' => '700',
                    'net_amount_settled' => '99316',
                    'withdrawal_status' => 'success',
                    'transaction_paid_at' => '2026-08-14 03:15:00',
                    'withdrawal_requested_at' => '2026-08-14 11:20:00',
                    'withdrawal_processed_at' => null,
                    'withdrawal_code' => 'WD-247-1',
                    'withdrawal_proof_url' => 'http://evil.example/proof?token=secret',
                ],
            ], currentPage: 2, lastPage: 2), 200),
        ]);

        $this->get(route('admin.dashboard', [
            'tab' => 'withdraw',
            'status' => 'SUCCESS',
            'order_id' => ' KLIK-247-WITHDRAW ',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-14',
            'limit' => 25,
            'page' => 2,
        ]))
            ->assertOk()
            ->assertSee('Withdraw Order Status')
            ->assertSee('KLIK-247-WITHDRAW')
            ->assertSee('IDR 100.000')
            ->assertSee('IDR 100.016')
            ->assertSee('IDR 700')
            ->assertSee('IDR 99.316')
            ->assertSee('WD-247-1')
            ->assertSee('Tautan bukti transfer tidak tersedia sebagai tautan aman; ditampilkan sebagai teks')
            ->assertDontSee('href="http://evil.example/proof?token=secret"', false)
            ->assertDontSee('secret', false)
            ->assertSee('Berikutnya')
            ->assertSee(str_replace('&', '&amp;', route('admin.dashboard', [
                'tab' => 'withdraw',
                'status' => 'success',
                'order_id' => 'KLIK-247-WITHDRAW',
                'start_date' => '2026-08-01',
                'end_date' => '2026-08-14',
                'limit' => 25,
                'page' => 1,
            ])), false);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://klikqris.com/api/sandbox/withdraw/orders?status=success&order_id=KLIK-247-WITHDRAW&start_date=2026-08-01&end_date=2026-08-14&limit=25&page=2');
    }

    public function test_support_cannot_view_or_request_withdrawal_data(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Support]));
        Http::fake();

        $this->get(route('admin.dashboard', ['tab' => 'withdraw']))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_reporting_failure_renders_safe_actionable_notice(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        Http::fake([
            'https://klikqris.com/api/sandbox/qris/history*' => Http::response([
                'status' => false,
                'message' => 'api_key=provider-secret must never render',
            ], 200),
        ]);

        $this->get(route('admin.dashboard', ['tab' => 'riwayat']))
            ->assertOk()
            ->assertSee('Riwayat KlikQRIS')
            ->assertSee('Data KlikQRIS belum dapat dimuat')
            ->assertSee('Muat ulang halaman untuk mencoba lagi')
            ->assertDontSee('provider-secret', false)
            ->assertDontSee('api_key', false);
    }

    public function test_malformed_reporting_payload_renders_safe_notice(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        Http::fake([
            'https://klikqris.com/api/sandbox/qris/history*' => Http::response([
                'status' => true,
                'message' => 'api_key=provider-secret must never render',
                'data' => [
                    'current_page' => 1,
                    'data' => [['order_id' => 'KLIK-247-MALFORMED']],
                    'per_page' => 1,
                    'total' => 1,
                    'last_page' => 1,
                    'first_page_url' => 'https://klikqris.com/api/sandbox/qris/history?page=1',
                    'last_page_url' => 'https://klikqris.com/api/sandbox/qris/history?page=1',
                    'next_page_url' => null,
                    'prev_page_url' => null,
                ],
            ], 200),
        ]);

        $this->get(route('admin.dashboard', ['tab' => 'riwayat']))
            ->assertOk()
            ->assertSee('Data KlikQRIS belum dapat dimuat')
            ->assertSee('Provider gagal merespons atau mengirim data yang tidak dapat dibaca.')
            ->assertDontSee('provider-secret', false)
            ->assertDontSee('api_key', false);
    }

    public function test_invalid_withdrawal_filters_fail_closed_without_provider_request(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        Http::fake();

        $this->get(route('admin.dashboard', [
            'tab' => 'withdraw',
            'status' => 'failed',
            'start_date' => '2026-02-31',
            'end_date' => '2026-01-01',
            'limit' => 'not-a-number',
        ]))
            ->assertOk()
            ->assertSee('Filter withdraw tidak valid')
            ->assertSee('Periksa status, tanggal, limit, dan halaman lalu coba lagi.')
            ->assertDontSee('KLIK-247-WITHDRAW');

        Http::assertNothingSent();
    }

    public function test_full_dashboard_builder_contains_reporting_payloads_without_loading_inactive_tabs(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        Http::fake();

        $data = app(AdminDashboardData::class)->forAdmin($owner, 'stats');

        $this->assertSame('inactive', $data['historyTab']['state']);
        $this->assertSame('inactive', $data['withdrawTab']['state']);
        $this->assertSame([], $data['historyTab']['items']);
        $this->assertSame([], $data['withdrawTab']['items']);
        Http::assertNothingSent();
    }

    public function test_disabled_provider_renders_actionable_state_without_requesting_http(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        config()->set('services.klikqris.sandbox_enabled', false);
        Http::fake();

        $this->get(route('admin.dashboard', ['tab' => 'riwayat']))
            ->assertOk()
            ->assertSee('KlikQRIS reporting tidak aktif')
            ->assertSee('Periksa konfigurasi sebelum memuat data.')
            ->assertDontSee('Data KlikQRIS belum dapat dimuat');

        Http::assertNothingSent();
    }

    /** @param list<array<string, mixed>> $rows */
    private function historyPayload(array $rows, int $currentPage = 1, int $lastPage = 1): array
    {
        return [
            'status' => true,
            'data' => [
                'current_page' => $currentPage,
                'data' => $rows,
                'per_page' => count($rows),
                'total' => count($rows) * $lastPage,
                'last_page' => $lastPage,
                'first_page_url' => 'https://klikqris.com/api/sandbox/qris/history?page=1',
                'last_page_url' => 'https://klikqris.com/api/sandbox/qris/history?page='.$lastPage,
                'next_page_url' => $currentPage < $lastPage ? 'https://evil.example?page='.($currentPage + 1) : null,
                'prev_page_url' => $currentPage > 1 ? 'https://evil.example?page='.($currentPage - 1) : null,
            ],
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function withdrawalPayload(array $rows, int $currentPage = 1, int $lastPage = 1): array
    {
        return [
            'status' => true,
            'data' => [
                'current_page' => $currentPage,
                'data' => $rows,
                'per_page' => max(1, count($rows)),
                'total' => count($rows) * $lastPage,
                'last_page' => $lastPage,
                'first_page_url' => 'https://klikqris.com/api/sandbox/withdraw/orders?page=1',
                'last_page_url' => 'https://klikqris.com/api/sandbox/withdraw/orders?page='.$lastPage,
                'next_page_url' => $currentPage < $lastPage ? 'https://evil.example?page='.($currentPage + 1) : null,
                'prev_page_url' => $currentPage > 1 ? 'https://evil.example?page='.($currentPage - 1) : null,
            ],
        ];
    }
}
