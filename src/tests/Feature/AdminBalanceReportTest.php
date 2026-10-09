<?php

namespace Tests\Feature;

use App\Enums\StoreBalanceFundingIntentStatus;
use App\Enums\StoreBalanceTopUpStatus;
use App\Enums\TelegramNavigationMediaKind;
use App\Enums\TelegramNavigationScreen;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\CustomerUsdtFundingPolicy;
use App\Models\ManualPaymentSetting;
use App\Models\StoreBalanceFundingIntent;
use App\Models\StoreBalanceTopUp;
use App\Models\TelegramNavigationRoot;
use App\Models\User;
use App\Services\Admin\AdminBalanceReportReader;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtFunding\UsdtFundingIntentService;
use App\Services\UsdtReceivers\UsdtDepositReceiverRegistry;
use Carbon\CarbonInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\BscUsdtRailRpcFixture;
use Tests\TestCase;

final class AdminBalanceReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.admin_allowed_logins' => '']);
    }

    public function test_balance_report_uses_exact_telegram_id_masks_results_and_keeps_currency_totals_separate(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $target = Customer::factory()->create([
            'first_name' => 'Exact Customer',
            'username' => 'same-user-name',
            'telegram_id' => 9007199254740993,
        ]);
        $other = Customer::factory()->create([
            'first_name' => 'Other Customer',
            'username' => 'same-user-name',
            'telegram_id' => 9007199254740994,
        ]);
        app(StoreBalanceLedger::class)->creditTopUp($target, 12_345, 'report-idr-target', 'report-idr-target:credit');
        app(StoreBalanceLedger::class)->creditTopUp($other, 54_321, 'report-idr-other', 'report-idr-other:credit');
        $this->createUsdtAccount($target, 2_500_000);

        $response = $this->actingAs($owner)
            ->get(route('admin.store-balance.reports.index', [
                'section' => 'accounts',
                'currency' => 'IDR',
                'telegram_id' => '9007199254740993',
            ]))
            ->assertOk()
            ->assertSee('Exact Customer')
            ->assertSee('••••0993')
            ->assertSee('Rp 12.345')
            ->assertDontSee('Other Customer')
            ->assertDontSee('Rp 54.321')
            ->assertSee('Total proyeksi IDR')
            ->assertSee('Total proyeksi USDT')
            ->assertSee('2.5 USDT');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    public function test_account_reports_hide_drifted_amounts_but_keep_frozen_usdt_explicit(): void
    {
        $finance = User::factory()->create(['role' => UserRole::Finance]);
        $idrCustomer = Customer::factory()->create(['telegram_id' => 920001]);
        $usdtCustomer = Customer::factory()->create(['telegram_id' => 920002]);
        DB::table('customer_balance_accounts')->insert([
            'customer_id' => $idrCustomer->getKey(),
            'balance' => 12_345,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createUsdtAccount($usdtCustomer, 2_500_000, 'incident:fixture');

        $idr = app(AdminBalanceReportReader::class)->page($finance, [
            'section' => 'accounts',
            'currency' => 'IDR',
            'telegram_id' => (string) $idrCustomer->telegram_id,
        ]);
        $this->assertSame('unavailable', $idr['rows'][0]['status']);
        $this->assertSame('—', $idr['rows'][0]['amount']);

        $usdt = app(AdminBalanceReportReader::class)->page($finance, [
            'section' => 'accounts',
            'currency' => 'USDT',
            'telegram_id' => (string) $usdtCustomer->telegram_id,
        ]);
        $this->assertSame('frozen', $usdt['rows'][0]['status']);
        $this->assertSame('2.5 USDT', $usdt['rows'][0]['amount']);
    }

    public function test_reports_are_role_checked_for_each_section_and_support_is_denied(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $support = User::factory()->create(['role' => UserRole::Support]);

        foreach ([
            ['accounts', 'IDR'], ['entries', 'IDR'], ['topups', 'IDR'], ['reviews', 'IDR'],
            ['accounts', 'USDT'], ['entries', 'USDT'], ['topups', 'USDT'], ['reviews', 'USDT'],
            ['treasury', 'USDT'], ['audit', 'IDR'], ['audit', 'USDT'],
        ] as [$section, $currency]) {
            $this->actingAs($admin)->get(route('admin.store-balance.reports.index', [
                'section' => $section,
                'currency' => $currency,
            ]))->assertOk();
        }

        $this->actingAs($support)->get(route('admin.store-balance.reports.index'))->assertForbidden();
        $this->actingAs($support)->get(route('admin.store-balance.reports.proof', ['topUp' => 1]))->assertForbidden();
    }

    public function test_usdt_top_up_report_filters_the_authoritative_preparation_state(): void
    {
        $finance = User::factory()->create(['role' => UserRole::Finance]);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $customer = Customer::factory()->create(['telegram_id' => 930001]);
        $root = TelegramNavigationRoot::query()->create([
            'customer_id' => $customer->getKey(),
            'chat_id' => (string) $customer->telegram_id,
            'message_id' => 100,
            'media_kind' => TelegramNavigationMediaKind::Text,
            'screen' => TelegramNavigationScreen::Home,
            'locale' => 'id',
            'generation' => 1,
        ]);
        ManualPaymentSetting::create([
            'is_active' => true,
            'payment_mode' => 'manual',
            'bsc_min_confirmations' => 3,
            'bsc_payment_ttl_minutes' => 15,
        ]);
        CustomerUsdtFundingPolicy::current()->update(['funding_enabled' => true, 'version' => 1]);
        $registry = app(UsdtDepositReceiverRegistry::class);
        $registry->manualVerify(
            $registry->register($customer, BscUsdtRailRpcFixture::ADDRESS, $owner)->receiver,
            true,
            $owner,
        );

        $intents = app(UsdtFundingIntentService::class);
        $opened = $intents->open(
            $customer,
            (string) $customer->telegram_id,
            (int) $root->getKey(),
            100,
            UsdtMicroAmount::fromUnits(1_000_000),
        );
        $this->assertSame('opened', $opened->outcome);
        $confirmed = $intents->claim(
            $customer,
            (string) $customer->telegram_id,
            (int) $root->getKey(),
            100,
            $opened->intent->nonce,
            'confirm',
        );
        $this->assertSame('confirmed', $confirmed->outcome);

        $reader = app(AdminBalanceReportReader::class);
        $baseFilters = [
            'section' => 'topups',
            'currency' => 'USDT',
            'telegram_id' => (string) $customer->telegram_id,
        ];
        $all = $reader->page($finance, $baseFilters);
        $this->assertCount(1, $all['rows']);
        $this->assertSame('preparing', $all['rows'][0]['status']);

        $report = $reader->page($finance, $baseFilters + ['state' => 'preparing']);
        $this->assertCount(1, $report['rows']);
        $this->assertSame('preparing', $report['rows'][0]['status']);
        $this->assertSame('1 USDT', $report['rows'][0]['amount']);
    }

    public function test_report_queries_are_fixed_count_and_do_not_load_rows_one_at_a_time(): void
    {
        $finance = User::factory()->create(['role' => UserRole::Finance]);
        for ($index = 1; $index <= 4; $index++) {
            $customer = Customer::factory()->create(['telegram_id' => 900000 + $index]);
            app(StoreBalanceLedger::class)->creditTopUp($customer, 1000 * $index, 'query-count-'.$index, 'query-count:'.$index);
        }

        $recording = true;
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$recording, &$queries): void {
            if ($recording && str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                $queries[] = $query->sql;
            }
        });

        app(AdminBalanceReportReader::class)->page($finance, [
            'section' => 'entries',
            'currency' => 'IDR',
            'limit' => 25,
        ]);
        $recording = false;

        $this->assertCount(3, $queries, 'One bounded report page, one currency totals query, and one latest reconciliation query are expected.');
    }

    public function test_admin_report_cursor_is_keyset_and_bound_to_actor_and_filters(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $otherAdmin = User::factory()->create(['role' => UserRole::Owner]);
        for ($index = 1; $index <= 2; $index++) {
            $customer = Customer::factory()->create(['telegram_id' => 910000 + $index]);
            DB::table('customer_balance_accounts')->insert([
                'customer_id' => $customer->getKey(),
                'balance' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $response = $this->actingAs($admin)->get(route('admin.store-balance.reports.index', [
            'section' => 'accounts', 'currency' => 'IDR', 'limit' => 1,
        ]))->assertOk();
        $cursor = $this->cursorFromOlderLink($response->getContent());

        $this->actingAs($admin)->get(route('admin.store-balance.reports.index', [
            'section' => 'accounts', 'currency' => 'IDR', 'limit' => 1, 'cursor' => $cursor,
        ]))->assertOk();
        $this->actingAs($otherAdmin)->get(route('admin.store-balance.reports.index', [
            'section' => 'accounts', 'currency' => 'IDR', 'limit' => 1, 'cursor' => $cursor,
        ]))->assertNotFound();
        $this->actingAs($admin)->get(route('admin.store-balance.reports.index', [
            'section' => 'accounts', 'currency' => 'USDT', 'limit' => 1, 'cursor' => $cursor,
        ]))->assertNotFound();
    }

    public function test_latest_reconciliation_distinguishes_missing_custody_from_zero_assets(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->insertRun(custodyState: null, custodyAmount: null, checkedAt: now());

        $this->actingAs($owner)->get(route('admin.store-balance.reports.index', ['section' => 'treasury', 'currency' => 'USDT']))
            ->assertOk()
            ->assertSee('Custody missing · nilai tidak tersedia');

        $this->insertRun(custodyState: 'available', custodyAmount: 0, checkedAt: now()->addSecond());
        $this->actingAs($owner)->get(route('admin.store-balance.reports.index', ['section' => 'treasury', 'currency' => 'USDT']))
            ->assertOk()
            ->assertSee('Custody tersedia · 0 USDT');
    }

    public function test_balance_proof_link_is_short_lived_private_masked_and_downloads_only_valid_image_bytes(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Owner]);
        $customer = Customer::factory()->create(['telegram_id' => 99001122]);
        $fileId = 'AgAC-protected-telegram-file-id';
        $topUp = $this->makeTopUp($customer, $fileId);
        config(['services.telegram.token' => '123456:fixture-token']);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgQAYAAA4AAbGa6gYAAAAASUVORK5CYII=', true);
        $this->assertIsString($png);
        Http::fake([
            'https://api.telegram.org/bot123456:fixture-token/getFile' => Http::response([
                'ok' => true,
                'result' => ['file_path' => 'photos/proof.png', 'file_size' => strlen($png)],
            ], 200),
            'https://api.telegram.org/file/bot123456:fixture-token/photos/proof.png' => Http::response(
                $png,
                200,
                ['Content-Type' => 'image/png', 'Content-Length' => (string) strlen($png)],
            ),
        ]);

        $report = $this->actingAs($admin)->get(route('admin.store-balance.reports.index', ['section' => 'topups', 'currency' => 'IDR']))
            ->assertOk()
            ->assertSee('Unduh bukti pembayaran')
            ->assertDontSee($fileId)
            ->assertDontSee($topUp->merchant_ref);
        $proofUrl = $this->proofUrlFromLink($report->getContent());

        $download = $this->actingAs($admin)->get($proofUrl)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $this->assertStringContainsString('no-store', (string) $download->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $download->headers->get('Cache-Control'));
        $this->assertSame($png, $download->getContent());
        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://api.telegram.org/bot123456:fixture-token/getFile'
            && $request->method() === 'POST'
            && $request['file_id'] === $fileId);
        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://api.telegram.org/file/bot123456:fixture-token/photos/proof.png');

        $otherAdmin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($otherAdmin)->get($proofUrl)->assertNotFound();
        $this->travel(6)->minutes();
        $this->actingAs($admin)->get($proofUrl)->assertForbidden();
        $this->travelBack();
        Http::assertSentCount(2);
    }

    public function test_proof_fetch_rejects_arbitrary_paths_and_oversized_files_before_download(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Owner]);
        $customer = Customer::factory()->create(['telegram_id' => 99001123]);
        $topUp = $this->makeTopUp($customer, 'protected-file-id');
        config(['services.telegram.token' => '123456:fixture-token']);

        Http::fake([
            'https://api.telegram.org/bot123456:fixture-token/getFile' => Http::response([
                'ok' => true,
                'result' => ['file_path' => 'https://attacker.example/private.png', 'file_size' => 64],
            ], 200),
        ]);
        $report = $this->actingAs($admin)->get(route('admin.store-balance.reports.index', ['section' => 'topups', 'currency' => 'IDR']))
            ->assertOk();
        $this->actingAs($admin)->get($this->proofUrlFromLink($report->getContent()))->assertNotFound();
        Http::assertSentCount(1);
        Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'attacker.example'));

        Http::fake([
            'https://api.telegram.org/bot123456:fixture-token/getFile' => Http::response([
                'ok' => true,
                'result' => ['file_path' => 'photos/too-large.png', 'file_size' => 4_194_305],
            ], 200),
        ]);
        $report = $this->actingAs($admin)->get(route('admin.store-balance.reports.index', ['section' => 'topups', 'currency' => 'IDR']))
            ->assertOk();
        $this->actingAs($admin)->get($this->proofUrlFromLink($report->getContent()))->assertNotFound();
        Http::assertSentCount(1);

    }

    public function test_proof_fetch_rejects_mismatched_declared_image_mime(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Owner]);
        $customer = Customer::factory()->create(['telegram_id' => 99001124]);
        $this->makeTopUp($customer, 'protected-mime-file-id');
        config(['services.telegram.token' => '123456:fixture-token']);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgQAYAAA4AAbGa6gYAAAAASUVORK5CYII=', true);
        $this->assertIsString($png);

        Http::fake([
            'https://api.telegram.org/bot123456:fixture-token/getFile' => Http::response([
                'ok' => true,
                'result' => ['file_path' => 'photos/proof.png', 'file_size' => strlen($png)],
            ], 200),
            'https://api.telegram.org/file/bot123456:fixture-token/photos/proof.png' => Http::response(
                $png,
                200,
                ['Content-Type' => 'image/jpeg', 'Content-Length' => (string) strlen($png)],
            ),
        ]);

        $report = $this->actingAs($admin)->get(route('admin.store-balance.reports.index', ['section' => 'topups', 'currency' => 'IDR']))
            ->assertOk();
        $this->actingAs($admin)->get($this->proofUrlFromLink($report->getContent()))->assertNotFound();
        Http::assertSentCount(2);
    }

    public function test_proof_download_never_follows_redirects_to_another_host(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Owner]);
        $customer = Customer::factory()->create(['telegram_id' => 99001125]);
        $this->makeTopUp($customer, 'protected-redirect-file-id');
        config(['services.telegram.token' => '123456:fixture-token']);
        Http::fake([
            'https://api.telegram.org/bot123456:fixture-token/getFile' => Http::response([
                'ok' => true,
                'result' => ['file_path' => 'photos/redirect.png', 'file_size' => 64],
            ], 200),
            'https://api.telegram.org/file/bot123456:fixture-token/photos/redirect.png' => Http::response(
                '',
                302,
                ['Location' => 'https://attacker.example/redirected.png'],
            ),
        ]);

        $report = $this->actingAs($admin)->get(route('admin.store-balance.reports.index', ['section' => 'topups', 'currency' => 'IDR']))
            ->assertOk();
        $this->actingAs($admin)->get($this->proofUrlFromLink($report->getContent()))->assertNotFound();
        Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'attacker.example'));
    }

    private function createUsdtAccount(Customer $customer, int $balanceMicro, ?string $frozenReason = null): void
    {
        $accountId = DB::table('customer_usdt_balance_accounts')->insertGetId([
            'customer_id' => $customer->getKey(),
            'currency' => 'USDT',
            'balance_micro' => $balanceMicro,
            'version' => 1,
            'frozen_reason' => $frozenReason,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $entryId = DB::table('customer_usdt_balance_entries')->insertGetId([
            'customer_usdt_balance_account_id' => $accountId,
            'account_sequence' => 1,
            'kind' => 'funding_credit',
            'amount_micro' => $balanceMicro,
            'balance_after_micro' => $balanceMicro,
            'source_type' => 'report_fixture',
            'source_id' => 'report-account:'.$customer->getKey(),
            'idempotency_key' => 'report-account:'.$customer->getKey(),
            'request_fingerprint' => hash('sha256', 'report-account:'.$customer->getKey()),
            'created_at' => now(),
        ]);
        DB::table('customer_usdt_balance_accounts')->where('id', $accountId)->update(['last_entry_id' => $entryId]);
    }

    private function insertRun(?string $custodyState, ?int $custodyAmount, CarbonInterface $checkedAt): void
    {
        DB::table('usdt_balance_reconciliation_runs')->insert([
            'scope' => 'full',
            'limit' => 200,
            'checked_at' => $checkedAt,
            'created_at' => $checkedAt,
            'verdict' => 'clean',
            'custody_state' => $custodyState,
            'custody_assets_micro' => $custodyAmount,
        ]);
    }

    private function makeTopUp(Customer $customer, string $fileId): StoreBalanceTopUp
    {
        $merchantRef = 'REPORT-'.Str::upper(Str::random(12));
        $expiresAt = now()->addMinutes(15)->startOfSecond();
        $intent = StoreBalanceFundingIntent::query()->create([
            'customer_id' => $customer->getKey(),
            'telegram_navigation_root_id' => null,
            'chat_id' => (string) $customer->telegram_id,
            'canonical_message_id' => 1,
            'policy_version' => 1,
            'amount' => 5000,
            'nonce' => Str::random(32),
            'status' => StoreBalanceFundingIntentStatus::Confirmed,
            'expires_at' => $expiresAt,
            'claimed_at' => now(),
        ]);
        $topUp = StoreBalanceTopUp::query()->create([
            'customer_id' => $customer->getKey(),
            'store_balance_funding_intent_id' => $intent->getKey(),
            'telegram_navigation_root_id' => null,
            'chat_id' => (string) $customer->telegram_id,
            'canonical_message_id' => 1,
            'locale' => 'id',
            'provider' => 'klikqris',
            'merchant_ref' => $merchantRef,
            'idempotency_key' => 'report:'.$merchantRef,
            'currency' => 'IDR',
            'amount' => 5000,
            'status' => StoreBalanceTopUpStatus::AwaitingConfirmation,
            'provider_create_state' => 'awaiting_confirmation',
            'expires_at' => $expiresAt,
            'payment_proof_file_id' => $fileId,
            'payment_proof_submitted_at' => now(),
        ]);

        return $topUp->fresh();
    }

    private function cursorFromOlderLink(string $html): string
    {
        $this->assertSame(1, preg_match('/href="([^"]*cursor=[^"]*)"/', $html, $matches));
        parse_str((string) parse_url(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5), PHP_URL_QUERY), $query);
        $this->assertIsString($query['cursor'] ?? null);

        return $query['cursor'];
    }

    private function proofUrlFromLink(string $html): string
    {
        $this->assertSame(1, preg_match('/<a href="([^"]+)"[^>]*>Unduh bukti pembayaran<\/a>/', $html, $matches));

        return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
    }
}
