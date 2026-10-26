<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\InvalidCheckout;
use App\Models\AuditLog;
use App\Models\ManualPaymentSetting;
use App\Models\Payment;
use App\Models\User;
use App\Services\Admin\SakurupiahChannelSettings;
use App\Services\AuditLogger;
use App\Services\Sakurupiah\SakurupiahProviderReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SakurupiahChannelSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.sakurupiah', array_replace(config('services.sakurupiah', []), [
            'sandbox_enabled' => true,
            'sandbox_base_url' => 'https://sakurupiah.id/api-sanbox/',
            'sandbox_api_id' => 'sandbox-id',
            'sandbox_api_key' => 'sandbox-key',
            'qris_contract' => [
                'currency' => 'IDR', 'currency_confirmed' => true,
                'fee_payer' => 'merchant', 'merchant_fee' => 1, 'fee_confirmed' => true,
                'expiry_hours' => 24, 'expiry_confirmed' => true,
            ],
        ]));
        app(SakurupiahProviderReadiness::class)->invalidate();
        $this->channels();
    }

    public function test_owner_can_change_channel_without_switching_provider_or_old_payments(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $setting = ManualPaymentSetting::create(['payment_mode' => 'manual', 'is_active' => true, 'payment_provider_version' => 4]);
        $payment = Payment::factory()->create(['provider' => 'sakurupiah', 'sakurupiah_channel' => 'QRISC']);
        $transactionLevel = DB::transactionLevel();
        $this->channels(function () use ($transactionLevel): void {
            $this->assertSame($transactionLevel, DB::transactionLevel(), 'Channel lookup must stay outside the mutation transaction.');
        });

        $this->actingAs($owner)->post(route('admin.sakurupiah-channel.update'), [
            'sakurupiah_channel' => 'QRISMU', 'payment_provider_version' => 4,
        ])->assertSessionHasNoErrors()->assertRedirect('/admin?tab=settings&section=pembayaran#admin-payment-settings');

        $this->assertSame('QRISMU', $setting->fresh()->sakurupiah_channel);
        $this->assertSame('manual', $setting->fresh()->payment_mode);
        $this->assertSame(5, $setting->fresh()->payment_provider_version);
        $this->assertSame('QRISC', $payment->fresh()->sakurupiah_channel);
        $audit = AuditLog::query()->where('action', 'sakurupiah_channel.updated')->sole();
        $this->assertSame('QRIS', $audit->old_values['sakurupiah_channel']);
        $this->assertSame('QRISMU', $audit->new_values['sakurupiah_channel']);
        $this->assertSame($owner->id, $audit->user_id);
    }

    public function test_admin_and_other_roles_cannot_mutate_channel(): void
    {
        $setting = ManualPaymentSetting::create(['payment_mode' => 'manual', 'is_active' => true]);
        foreach ([UserRole::Admin, UserRole::Finance, UserRole::Support] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->post(route('admin.sakurupiah-channel.update'), ['sakurupiah_channel' => 'QRISMU', 'payment_provider_version' => 0])
                ->assertForbidden();
        }
        $this->assertSame('QRIS', $setting->fresh()->sakurupiah_channel);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'sakurupiah_channel.updated']);
        Http::assertNothingSent();
    }

    public function test_required_version_stale_version_and_noop_do_not_write(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $setting = ManualPaymentSetting::create(['payment_mode' => 'manual', 'is_active' => true, 'payment_provider_version' => 3]);
        $this->actingAs($owner)->post(route('admin.sakurupiah-channel.update'), ['sakurupiah_channel' => 'QRISMU'])
            ->assertSessionHasErrors(['payment_provider_version'], null, 'sakurupiahChannel');
        $this->post(route('admin.sakurupiah-channel.update'), ['sakurupiah_channel' => 'QRISMU', 'payment_provider_version' => 2])
            ->assertSessionHasErrors(['sakurupiah_channel'], null, 'sakurupiahChannel');
        $this->flushSession();
        $this->actingAs($owner)->post(route('admin.sakurupiah-channel.update'), ['sakurupiah_channel' => 'QRIS', 'payment_provider_version' => 3])
            ->assertSessionHasNoErrors();
        $this->assertSame(3, $setting->fresh()->payment_provider_version);
        $this->assertSame('QRIS', $setting->fresh()->sakurupiah_channel);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'sakurupiah_channel.updated']);
    }

    public function test_only_active_supported_channels_with_supported_expiry_are_offered(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['status' => '200', 'data' => [
            $this->channel('QRIS'), $this->channel('QRISMU', 12),
            $this->channel('QRISC', 24, 'Offline'), $this->channel('QRIS2', 6), $this->channel('BRIVA'),
        ]]));
        $available = app(SakurupiahChannelSettings::class)->available();
        $this->assertNull($available['error']);
        $this->assertSame(['QRIS', 'QRISMU'], array_column($available['channels'], 'code'));
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $setting = ManualPaymentSetting::create(['payment_mode' => 'manual', 'is_active' => true]);
        foreach (['QRISC', 'QRIS2', 'BRIVA'] as $code) {
            $this->actingAs($owner)->post(route('admin.sakurupiah-channel.update'), ['sakurupiah_channel' => $code, 'payment_provider_version' => 0])
                ->assertSessionHasErrors(['sakurupiah_channel'], null, 'sakurupiahChannel');
        }
        $this->assertSame('QRIS', $setting->fresh()->sakurupiah_channel);
    }

    public function test_duplicate_offered_channel_is_not_selectable(): void
    {
        $this->channels(null, [$this->channel('QRIS'), $this->channel('QRIS'), $this->channel('QRISMU', 12)]);
        $available = app(SakurupiahChannelSettings::class)->available();
        $this->assertSame(['QRISMU'], array_column($available['channels'], 'code'));
    }

    public function test_configuration_change_during_lookup_rejects_save(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $setting = ManualPaymentSetting::create(['payment_mode' => 'manual', 'is_active' => true]);
        $this->channels(function (): void {
            config()->set('services.sakurupiah.sandbox_api_key', 'rotated-key');
        });
        $this->actingAs($owner)->post(route('admin.sakurupiah-channel.update'), ['sakurupiah_channel' => 'QRISMU', 'payment_provider_version' => 0])
            ->assertSessionHasErrors(['sakurupiah_channel'], null, 'sakurupiahChannel');
        $this->assertSame('QRIS', $setting->fresh()->sakurupiah_channel);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'sakurupiah_channel.updated']);
    }

    public function test_dashboard_exposes_live_fees_and_owner_only_save(): void
    {
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'is_active' => true]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]))->get('/admin?tab=settings&section=pembayaran')
            ->assertOk()->assertSee('Simpan channel Sakurupiah')->assertSee('0.7%')->assertSee('H+1')->assertSee('12 jam');
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->get('/admin?tab=settings&section=pembayaran')
            ->assertOk()->assertDontSee('Simpan channel Sakurupiah')->assertSee('Hanya Owner yang dapat menyimpan pilihan channel.');
    }

    public function test_channel_readiness_is_exact_cached_per_selection_and_candidate_override_does_not_persist(): void
    {
        $setting = ManualPaymentSetting::create(['payment_mode' => 'sakurupiah', 'is_active' => true]);
        $readiness = app(SakurupiahProviderReadiness::class);
        $first = $readiness->observe();
        $this->assertTrue($first->isReady());
        $this->assertSame('QRIS', $first->qrisChannel()->code);
        $candidate = $readiness->observe(channelOverride: 'QRISMU');
        $this->assertTrue($candidate->isReady());
        $this->assertSame('QRISMU', $candidate->qrisChannel()->code);
        $this->assertNotSame($first->configurationFingerprint(), $candidate->configurationFingerprint());
        $this->assertSame('QRIS', $setting->fresh()->sakurupiah_channel);
        $setting->update(['sakurupiah_channel' => 'QRISC']);
        $this->channels(null, [$this->channel('QRIS')]);
        $missing = $readiness->observe();
        $this->assertFalse($missing->isReady());
        $this->assertNull($missing->qrisChannel());
        $this->expectException(InvalidCheckout::class);
        $readiness->assertCheckoutSnapshot($first, $setting->fresh());
    }

    public function test_lookup_failure_renders_usable_dashboard_for_owner_and_admin(): void
    {
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'is_active' => true]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['status' => '500'], 500));
        foreach ([UserRole::Owner, UserRole::Admin] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get('/admin?tab=settings&section=pembayaran')
                ->assertOk()->assertSee('Daftar channel Sakurupiah tidak dapat dimuat dengan aman.')
                ->assertSee('Tidak ada fallback otomatis ke channel lain.')->assertSee('disabled', false);
        }
    }

    public function test_provider_outage_does_not_double_the_dashboard_lookup_retry_budget(): void
    {
        ManualPaymentSetting::create(['payment_mode' => 'sakurupiah', 'is_active' => true]);
        config()->set('services.sakurupiah.lookup_max_attempts', 3);
        config()->set('services.sakurupiah.lookup_retry_delay_ms', 0);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['status' => '500'], 503));

        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]))
            ->get('/admin?tab=settings&section=pembayaran')->assertOk();

        Http::assertSentCount(3);
    }

    public function test_audit_failure_rolls_back_channel_and_version(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $setting = ManualPaymentSetting::create(['payment_mode' => 'manual', 'is_active' => true]);
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new \RuntimeException('audit failed'));
        try {
            app(SakurupiahChannelSettings::class)->change('QRISMU', 0, $owner, null);
            $this->fail('Expected transactional audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('audit failed', $exception->getMessage());
        }
        $this->assertSame('QRIS', $setting->fresh()->sakurupiah_channel);
        $this->assertSame(0, $setting->fresh()->payment_provider_version);
    }

    private function channels(?callable $onLookup = null, ?array $channels = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function () use ($onLookup, $channels) {
            $onLookup?->__invoke();

            return Http::response(['status' => '200', 'data' => $channels ?? [
                $this->channel('QRIS'), $this->channel('QRISC'), $this->channel('QRISMU', 12),
            ]]);
        });
    }

    private function channel(string $code, int $expiry = 24, string $status = 'Aktif'): array
    {
        return [
            'kode' => $code, 'nama' => $code.' Merchant', 'minimal' => 200, 'maksimal' => 20_000_000,
            'tipe' => 'DIRECT', 'status' => $status, 'biaya' => '0.7', 'percent' => 'Percent',
            'addition' => ['default_expired' => $expiry, 'tambahan_biaya' => 100, 'settlement' => 'H+1'],
        ];
    }
}
