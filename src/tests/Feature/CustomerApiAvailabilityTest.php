<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\CustomerApiSetting;
use App\Models\User;
use App\Services\CustomerApi\CustomerApiAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CustomerApiAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_seeds_the_gate_off_and_a_missing_row_stays_closed(): void
    {
        $availability = app(CustomerApiAvailability::class);

        $state = $availability->current();
        $this->assertFalse($state->enabled);
        $this->assertSame(0, $state->version);
        $this->assertNull($state->changedAt);
        $this->assertDatabaseHas('customer_api_settings', [
            'singleton_key' => CustomerApiSetting::SINGLETON_KEY,
            'enabled' => false,
            'version' => 0,
        ]);

        DB::table('customer_api_settings')->delete();

        $this->assertFalse($availability->isEnabled());
        $this->assertFalse($availability->check()->enabled);
        $this->assertFalse($availability->check()->unavailable);
    }

    public function test_a_database_read_failure_is_reported_as_unavailable_for_the_existing_503_path(): void
    {
        Schema::rename('customer_api_settings', 'customer_api_settings_unreadable');

        try {
            $check = app(CustomerApiAvailability::class)->check();
            $this->assertTrue($check->unavailable);
            $this->assertFalse($check->enabled);
            $this->assertFalse(app(CustomerApiAvailability::class)->isEnabled());
        } finally {
            Schema::rename('customer_api_settings_unreadable', 'customer_api_settings');
        }
    }

    public function test_owner_toggle_is_versioned_audited_and_a_noop_writes_nothing(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->actingAs($owner)
            ->post(route('admin.customer-api-availability.update'), [
                'enabled' => '1',
                'version' => 0,
                'confirmed' => '1',
            ])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'settings', 'section' => 'pembayaran']).'#admin-customer-api-availability')
            ->assertSessionHas('status');

        $state = app(CustomerApiAvailability::class)->current();
        $this->assertTrue($state->enabled);
        $this->assertSame(1, $state->version);
        $this->assertNotNull($state->changedAt);

        $audit = AuditLog::query()->where('action', 'customer_api.availability.enabled')->sole();
        $this->assertSame($owner->id, $audit->user_id);
        $this->assertSame('203.0.113.9', $audit->ip_address);
        $this->assertFalse((bool) $audit->old_values['enabled']);
        $this->assertTrue((bool) $audit->new_values['enabled']);
        $this->assertSame(0, (int) $audit->old_values['version']);
        $this->assertSame(1, (int) $audit->new_values['version']);

        $this->actingAs($owner)
            ->post(route('admin.customer-api-availability.update'), [
                'enabled' => '1',
                'version' => 1,
                'confirmed' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Customer API sudah aktif. Tidak ada perubahan yang disimpan.');

        $this->assertSame(1, app(CustomerApiAvailability::class)->current()->version);
        $this->assertSame(1, AuditLog::query()->count());

        $this->actingAs($owner)
            ->post(route('admin.customer-api-availability.update'), [
                'enabled' => '0',
                'version' => 0,
                'confirmed' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['version'], null, 'customerApiAvailability');

        $this->assertTrue(app(CustomerApiAvailability::class)->current()->enabled);
        $this->assertSame(1, AuditLog::query()->count());
    }

    public function test_only_the_owner_may_change_the_gate(): void
    {
        foreach ([UserRole::Admin, UserRole::Finance, UserRole::Support] as $role) {
            auth()->logout();
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->post(route('admin.customer-api-availability.update'), [
                    'enabled' => '1',
                    'version' => 0,
                    'confirmed' => '1',
                ])
                ->assertForbidden();
        }

        $this->assertFalse(app(CustomerApiAvailability::class)->current()->enabled);
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_missing_row_is_recovered_fail_closed_on_the_first_owner_change(): void
    {
        DB::table('customer_api_settings')->delete();
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $this->actingAs($owner)
            ->post(route('admin.customer-api-availability.update'), [
                'enabled' => '1',
                'version' => 0,
                'confirmed' => '1',
            ])
            ->assertRedirect();

        $state = app(CustomerApiAvailability::class)->current();
        $this->assertTrue($state->enabled);
        $this->assertSame(1, $state->version);
        $this->assertDatabaseCount('customer_api_settings', 1);
    }

    public function test_settings_card_shows_state_version_warning_and_owner_only_copy(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($owner)
            ->get(route('admin.dashboard', ['tab' => 'settings', 'section' => 'pembayaran']))
            ->assertOk()
            ->assertSee('id="admin-customer-api-availability"', false)
            ->assertSee('Customer API maintenance')
            ->assertSee('API OFF')
            ->assertSee('Versi:')
            ->assertSee('Aktifkan Customer API')
            ->assertSee('503 api_maintenance');

        auth()->logout();

        $this->actingAs($admin)
            ->get(route('admin.dashboard', ['tab' => 'settings', 'section' => 'pembayaran']))
            ->assertOk()
            ->assertSee('id="admin-customer-api-availability"', false)
            ->assertSee('Hanya Owner yang dapat mengaktifkan atau mematikan Customer API.')
            ->assertDontSee('Aktifkan Customer API');
    }
}
