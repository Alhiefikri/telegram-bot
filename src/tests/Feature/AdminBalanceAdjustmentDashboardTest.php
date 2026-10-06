<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BalanceAdjustmentRequest;
use App\Models\Customer;
use App\Models\CustomerBalanceAccount;
use App\Models\StoreBalanceEntry;
use App\Models\User;
use App\Services\StoreBalance\StoreBalanceLedger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\NonTransactionalDatabaseTestCase;

final class AdminBalanceAdjustmentDashboardTest extends NonTransactionalDatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createUsdtLedgerReadinessSchema();
    }

    public function test_owner_can_search_a_registered_customer_by_the_exact_telegram_id(): void
    {
        Queue::fake();
        config(['services.telegram.admin_telegram_chat_id' => '998877']);
        $owner = User::factory()->create(['role' => UserRole::Owner, 'password' => 'owner-password']);
        $customer = Customer::factory()->create([
            'telegram_id' => 9007199254740993,
            'first_name' => 'Exact Match',
            'username' => 'current_name',
        ]);

        $this->actingAs($owner)
            ->get(route('admin.store-balance.adjustments.index', ['telegram_id' => '9007199254740993']))
            ->assertOk()
            ->assertSee('9007199254740993')
            ->assertSee('@current_name')
            ->assertSee('Exact Match')
            ->assertSee('name="_token"', false);

        $this->actingAs($owner)
            ->get(route('admin.store-balance.adjustments.index', ['telegram_id' => '9007199254740994']))
            ->assertOk()
            ->assertSee('Customer tidak ditemukan');

        $this->assertSame(1, Customer::query()->count(), 'An unknown Telegram ID must not create a Customer.');
    }

    public function test_adjustment_dashboard_and_routes_stay_inactive_until_the_usdt_ledger_is_installed(): void
    {
        Schema::dropIfExists('customer_usdt_balance_accounting_events');
        Schema::dropIfExists('customer_usdt_balance_entries');
        Schema::dropIfExists('customer_usdt_balance_accounts');
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $this->actingAs($owner)
            ->get(route('admin.dashboard', ['tab' => 'stats']))
            ->assertOk()
            ->assertDontSee('Kelola saldo');

        $this->actingAs($owner)
            ->get(route('admin.store-balance.adjustments.index'))
            ->assertNotFound();

        $this->actingAs($owner)
            ->post(route('admin.store-balance.adjustments.previews.store'), [
                'telegram_id' => '901541',
                'amount' => '1000',
                'reason' => 'The route must remain closed before U1.',
            ])
            ->assertNotFound();
    }

    public function test_search_works_when_the_customer_has_no_username(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        Customer::factory()->create([
            'telegram_id' => 901542,
            'first_name' => 'No Username',
            'username' => null,
        ]);

        $this->actingAs($owner)
            ->get(route('admin.store-balance.adjustments.index', ['telegram_id' => '901542']))
            ->assertOk()
            ->assertSee('901542')
            ->assertSee('No Username')
            ->assertSee('Username belum tersedia');
    }

    public function test_preview_is_bound_to_the_customer_snapshot_and_does_not_change_the_balance(): void
    {
        Queue::fake();
        config(['services.telegram.admin_telegram_chat_id' => '998877']);
        $owner = User::factory()->create(['role' => UserRole::Owner, 'password' => 'owner-password']);
        $customer = Customer::factory()->create([
            'telegram_id' => 9007199254740993,
            'first_name' => 'Preview Customer',
            'username' => 'before_preview',
        ]);

        $response = $this->actingAs($owner)
            ->post(route('admin.store-balance.adjustments.previews.store'), [
                'telegram_id' => '9007199254740993',
                'amount' => '12345',
                'reason' => 'Owner received the funds.',
            ])
            ->assertRedirect();
        $this->carrySessionCookie($response);

        $preview = BalanceAdjustmentRequest::query()->sole();
        $customer->update(['username' => 'changed_after_preview']);

        $this->get(route('admin.store-balance.adjustments.index', [
            'telegram_id' => '9007199254740993',
            'preview' => $preview->id,
        ]))
            ->assertOk()
            ->assertSee('9007199254740993')
            ->assertSee('@before_preview')
            ->assertSee('@changed_after_preview')
            ->assertSee('Rp 12.345')
            ->assertSee('Owner received the funds.')
            ->assertSee('Password Owner untuk tindakan ini')
            ->assertSee('name="_token"', false)
            ->assertDontSee('name="category"', false)
            ->assertDontSee('name="evidence"', false);

        $this->assertSame('pending', $preview->state);
        $this->assertDatabaseCount('store_balance_entries', 0);
        $this->assertDatabaseCount('customer_balance_accounts', 0);
        $this->assertDatabaseCount('balance_adjustment_accounting_events', 0);
    }

    public function test_owner_password_confirmation_applies_once_and_duplicate_confirmation_replays(): void
    {
        Queue::fake();
        config(['services.telegram.admin_telegram_chat_id' => '998877']);
        $owner = User::factory()->create(['role' => UserRole::Owner, 'password' => 'owner-password']);
        $customer = Customer::factory()->create(['telegram_id' => 901543, 'first_name' => 'Confirmed Customer']);

        $response = $this->actingAs($owner)->post(route('admin.store-balance.adjustments.previews.store'), [
            'telegram_id' => '901543',
            'amount' => '25000',
            'reason' => 'Adjustment approved by the Owner.',
        ])->assertRedirect();
        $this->carrySessionCookie($response);

        $preview = BalanceAdjustmentRequest::query()->sole();
        $confirmationRoute = route('admin.store-balance.adjustments.previews.confirm', ['adjustment' => $preview->id]);
        $confirmation = [
            'fingerprint' => $preview->fingerprint,
            'version' => $preview->version,
            'password' => 'owner-password',
        ];

        $response = $this->actingAs($owner)
            ->post($confirmationRoute, $confirmation)
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'Penambahan saldo diterapkan'));
        $this->carrySessionCookie($response);

        $response = $this->actingAs($owner)
            ->post($confirmationRoute, $confirmation)
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'diterapkan sebelumnya'));
        $this->carrySessionCookie($response);

        $this->get(route('admin.store-balance.adjustments.index', [
            'telegram_id' => '901543',
            'preview' => $preview->id,
        ]))
            ->assertOk()
            ->assertSee('Riwayat penambahan dan reversal IDR')
            ->assertSee('Adjustment approved by the Owner.')
            ->assertSee('DITERAPKAN')
            ->assertSee('+Rp 25.000');

        $this->assertSame('applied', $preview->fresh()->state);
        $this->assertSame(25000, (int) CustomerBalanceAccount::query()->where('customer_id', $customer->id)->value('balance'));
        $this->assertSame(1, StoreBalanceEntry::query()->count());
        $this->assertSame(1, DB::table('balance_adjustment_accounting_events')->count());
        $this->assertSame(2, DB::table('telegram_outbound_effects')->count());
    }

    public function test_unknown_invalid_and_non_owner_submissions_never_create_or_credit_a_customer(): void
    {
        Queue::fake();
        config(['services.telegram.admin_telegram_chat_id' => '998877']);
        $owner = User::factory()->create(['role' => UserRole::Owner, 'password' => 'owner-password']);
        $finance = User::factory()->create(['role' => UserRole::Finance]);
        $customer = Customer::factory()->create(['telegram_id' => 901544]);

        $this->actingAs($owner)
            ->post(route('admin.store-balance.adjustments.previews.store'), [
                'telegram_id' => '901545',
                'amount' => '1000',
                'reason' => 'Unknown customer must stop here.',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors();

        $this->actingAs($owner)
            ->post(route('admin.store-balance.adjustments.previews.store'), [
                'telegram_id' => '9007199254740993123',
                'amount' => '1000',
                'reason' => 'Out of range must stop here.',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors();

        $this->actingAs($finance)
            ->get(route('admin.store-balance.adjustments.index'))
            ->assertForbidden();

        $this->actingAs($finance)
            ->get(route('admin.dashboard', ['tab' => 'stats']))
            ->assertOk()
            ->assertDontSee('Kelola saldo');

        $this->actingAs($finance)
            ->post(route('admin.store-balance.adjustments.previews.store'), [
                'telegram_id' => '901544',
                'amount' => '1000',
                'reason' => 'Non-owner must stop here.',
            ])
            ->assertForbidden();

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(0, BalanceAdjustmentRequest::query()->count());
        $this->assertSame(0, StoreBalanceEntry::query()->count());
        $this->assertSame(0, CustomerBalanceAccount::query()->count());
        $this->assertSame(901544, (int) $customer->telegram_id);
    }

    public function test_customer_adjustment_history_is_paginated_and_marks_reversal_direction(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $customer = Customer::factory()->create(['telegram_id' => 901548]);
        $now = now();

        for ($index = 0; $index < 21; $index++) {
            $reversal = $index === 20;
            DB::table('balance_adjustment_requests')->insert([
                'id' => (string) Str::uuid(),
                'customer_id' => $customer->id,
                'owner_user_id' => $owner->id,
                'telegram_id' => '901548',
                'username' => null,
                'session_binding' => hash('sha256', 'history-session-'.$index),
                'credential_binding' => hash('sha256', 'history-credential-'.$index),
                'mode' => 'single_owner',
                'currency' => 'IDR',
                'amount' => $reversal ? 30000 : 1000 + $index,
                'reason' => 'Dashboard history item '.$index,
                'category' => $reversal ? 'reversal' : 'owner_attested',
                'source_id' => null,
                'receipt_fingerprint' => null,
                'fingerprint' => hash('sha256', 'history-fingerprint-'.$index),
                'version' => 1,
                'state' => 'pending',
                'ledger_entry_id' => null,
                'expires_at' => $now->copy()->addMinutes(5),
                'resolved_at' => null,
                'created_at' => $now->copy()->addSeconds($index),
                'updated_at' => $now->copy()->addSeconds($index),
            ]);
        }

        $this->actingAs($owner)
            ->get(route('admin.store-balance.adjustments.index', ['telegram_id' => '901548']))
            ->assertOk()
            ->assertSee('−Rp 30.000')
            ->assertSee('history_page=2')
            ->assertDontSee('Dashboard history item 0');

        $this->actingAs($owner)
            ->get(route('admin.store-balance.adjustments.index', [
                'telegram_id' => '901548',
                'history_page' => 2,
            ]))
            ->assertOk()
            ->assertSee('Dashboard history item 0');
    }

    public function test_owner_creates_and_confirms_a_linked_reversal_from_the_customer_history(): void
    {
        Queue::fake();
        config(['services.telegram.admin_telegram_chat_id' => '998877']);
        $owner = User::factory()->create(['role' => UserRole::Owner, 'password' => 'owner-password']);
        $customer = Customer::factory()->create(['telegram_id' => 901550, 'first_name' => 'Reversal Customer']);

        $response = $this->actingAs($owner)->post(route('admin.store-balance.adjustments.previews.store'), [
            'telegram_id' => '901550',
            'amount' => '5000',
            'reason' => 'Owner received the funds.',
        ])->assertRedirect();
        $this->carrySessionCookie($response);
        $credit = BalanceAdjustmentRequest::query()->where('category', 'owner_attested')->sole();

        $response = $this->actingAs($owner)->post(
            route('admin.store-balance.adjustments.previews.confirm', ['adjustment' => $credit->id]),
            ['fingerprint' => $credit->fingerprint, 'version' => 1, 'password' => 'owner-password'],
        )->assertRedirect();
        $this->carrySessionCookie($response);

        $this->get(route('admin.store-balance.adjustments.index', ['telegram_id' => '901550']))
            ->assertOk()
            ->assertSee('aria-label="Reversal penuh penyesuaian ini"', false);

        $response = $this->actingAs($owner)->post(route('admin.store-balance.adjustments.reversals.store'), [
            'source_id' => $credit->id,
            'telegram_id' => '901550',
        ])->assertRedirect();
        $this->carrySessionCookie($response);

        $reversal = BalanceAdjustmentRequest::query()->where('category', 'reversal')->sole();
        $this->assertSame($credit->id, $reversal->source_id);
        $this->assertSame(5000, $reversal->amount);
        $this->assertSame('pending', $reversal->state);

        $this->get(route('admin.store-balance.adjustments.index', [
            'telegram_id' => '901550',
            'preview' => $reversal->id,
        ]))
            ->assertOk()
            ->assertSee('Preview reversal IDR')
            ->assertSee('Konfirmasi reversal')
            ->assertSee('Adjustment asal');

        $response = $this->actingAs($owner)->post(
            route('admin.store-balance.adjustments.previews.confirm', ['adjustment' => $reversal->id]),
            ['fingerprint' => $reversal->fingerprint, 'version' => 1, 'password' => 'owner-password'],
        )->assertRedirect()
            ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'Reversal saldo diterapkan'));
        $this->carrySessionCookie($response);

        $this->assertSame('applied', $reversal->fresh()->state);
        $this->assertSame(0, (int) CustomerBalanceAccount::query()->where('customer_id', $customer->id)->value('balance'));
        $this->assertSame(2, StoreBalanceEntry::query()->count());

        $this->get(route('admin.store-balance.adjustments.index', ['telegram_id' => '901550']))
            ->assertOk()
            ->assertSee('−Rp 5.000')
            ->assertSee('REVERSAL')
            ->assertDontSee('aria-label="Reversal penuh penyesuaian ini"', false);
    }

    public function test_wrong_owner_password_leaves_the_preview_pending_without_crediting_balance(): void
    {
        Queue::fake();
        config(['services.telegram.admin_telegram_chat_id' => '998877']);
        $owner = User::factory()->create(['role' => UserRole::Owner, 'password' => 'owner-password']);
        $customer = Customer::factory()->create(['telegram_id' => 901546]);

        $response = $this->actingAs($owner)->post(route('admin.store-balance.adjustments.previews.store'), [
            'telegram_id' => '901546',
            'amount' => '7000',
            'reason' => 'Wrong password keeps this pending.',
        ])->assertRedirect();
        $this->carrySessionCookie($response);

        $preview = BalanceAdjustmentRequest::query()->sole();
        $response = $this->actingAs($owner)->post(
            route('admin.store-balance.adjustments.previews.confirm', ['adjustment' => $preview->id]),
            [
                'fingerprint' => $preview->fingerprint,
                'version' => $preview->version,
                'password' => 'wrong-password',
            ],
        )->assertRedirect();
        $this->carrySessionCookie($response);

        $this->get(route('admin.store-balance.adjustments.index', ['preview' => $preview->id]))
            ->assertOk()
            ->assertSee('Password Owner tidak cocok')
            ->assertSee('Preview masih menunggu konfirmasi');

        $this->assertSame('pending', $preview->fresh()->state);
        $this->assertSame(0, StoreBalanceEntry::query()->count());
        $this->assertSame(0, (int) CustomerBalanceAccount::query()->where('customer_id', $customer->id)->value('balance'));
    }

    public function test_balance_tracking_only_lists_positive_idr_projections_and_is_paginated(): void
    {
        Queue::fake();
        config(['services.telegram.admin_telegram_chat_id' => '998877']);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $customers = Customer::factory()->count(22)->create();

        foreach ($customers as $index => $customer) {
            $balance = $index === 21 ? 0 : 20000 - $index;
            $accountId = DB::table('customer_balance_accounts')->insertGetId([
                'customer_id' => $customer->id,
                'balance' => $balance,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($balance > 0) {
                DB::table('store_balance_entries')->insert([
                    'customer_balance_account_id' => $accountId,
                    'kind' => 'top_up_credit',
                    'amount' => $balance,
                    'balance_after' => $balance,
                    'source_type' => 'balance_top_up',
                    'source_id' => 'dashboard-directory-'.$index,
                    'idempotency_key' => 'dashboard-directory-'.$index,
                    'created_at' => now(),
                ]);
            }
        }

        $response = $this->actingAs($owner)->get(route('admin.store-balance.adjustments.index'))->assertOk();
        $this->assertSame(20, substr_count($response->getContent(), 'aria-label="Lihat saldo'));
        $response->assertSee('Customer dengan saldo Rupiah positif')
            ->assertSee('balances_page=2')
            ->assertDontSee((string) $customers[21]->telegram_id);
    }

    public function test_projection_drift_hides_amounts_and_holds_an_open_confirmation(): void
    {
        Queue::fake();
        config(['services.telegram.admin_telegram_chat_id' => '998877']);
        $owner = User::factory()->create(['role' => UserRole::Owner, 'password' => 'owner-password']);
        $customer = Customer::factory()->create([
            'telegram_id' => 901549,
            'first_name' => 'Drifted Balance Customer',
        ]);

        $response = $this->actingAs($owner)->post(route('admin.store-balance.adjustments.previews.store'), [
            'telegram_id' => '901549',
            'amount' => '7000',
            'reason' => 'Projection must be checked before applying.',
        ])->assertRedirect();
        $this->carrySessionCookie($response);
        $preview = BalanceAdjustmentRequest::query()->sole();

        app(StoreBalanceLedger::class)->creditTopUp($customer, 5000, 'dashboard-drift-fixture', 'dashboard-drift-fixture');
        DB::table('customer_balance_accounts')->where('customer_id', $customer->id)->update(['balance' => 0]);

        $this->get(route('admin.store-balance.adjustments.index', [
            'telegram_id' => '901549',
            'preview' => $preview->id,
        ]))
            ->assertOk()
            ->assertSee('Saldo Rupiah tidak dapat ditampilkan')
            ->assertSee('Penambahan saldo dinonaktifkan')
            ->assertSee('Perlu rekonsiliasi')
            ->assertDontSee('Saldo Rupiah saat ini')
            ->assertDontSee('Rp 5.000')
            ->assertDontSee('Konfirmasi penambahan');

        $response = $this->actingAs($owner)->post(
            route('admin.store-balance.adjustments.previews.confirm', ['adjustment' => $preview->id]),
            [
                'fingerprint' => $preview->fingerprint,
                'version' => $preview->version,
                'password' => 'owner-password',
            ],
        )->assertRedirect();
        $this->carrySessionCookie($response);

        $this->get(route('admin.store-balance.adjustments.index', ['preview' => $preview->id]))
            ->assertOk()
            ->assertSee('Saldo tidak dapat diverifikasi dengan ledger');

        $this->assertSame('pending', $preview->fresh()->state);
        $this->assertSame(0, (int) CustomerBalanceAccount::query()->where('customer_id', $customer->id)->value('balance'));
        $this->assertSame(1, StoreBalanceEntry::query()->count());
        $this->assertSame(0, DB::table('balance_adjustment_accounting_events')->count());
    }

    private function carrySessionCookie(TestResponse $response): void
    {
        $cookie = $response->getCookie(config('session.cookie'));
        $this->assertNotNull($cookie, 'The browser session cookie should be returned after an admin request.');
        $this->withCookie(config('session.cookie'), $cookie->getValue());
    }

    private function createUsdtLedgerReadinessSchema(): void
    {
        foreach ([
            'customer_usdt_balance_accounts',
            'customer_usdt_balance_entries',
            'customer_usdt_balance_accounting_events',
        ] as $tableName) {
            if (Schema::hasTable($tableName)) {
                continue;
            }

            Schema::create($tableName, static function (Blueprint $table): void {
                $table->id();
            });
        }
    }
}
