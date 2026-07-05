<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\StoreChannelSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminStoreChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config()->set('services.telegram.token', 'bot-token');
        config()->set('services.telegram.api_base_url', 'https://api.telegram.org');
    }

    public function test_owner_can_render_and_update_store_channel_settings(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));

        $this->get(route('admin.dashboard', ['tab' => 'telegram-channel']))
            ->assertOk()
            ->assertSee('Telegram Channel')
            ->assertSee('Channel')
            ->assertSee('Not configured');

        $this->post(route('admin.store-channel.update'), [
            'channel_username' => '@lhie_store',
            'channel_link' => 'https://t.me/lhie_store',
            'community_gate_enabled' => '1',
        ])->assertRedirect(route('admin.dashboard', ['tab' => 'telegram-channel']));

        $this->assertDatabaseHas('store_channel_settings', [
            'channel_username' => null,
            'channel_link' => 'https://t.me/lhie_store',
            'community_gate_enabled' => true,
            'sales_proof_enabled' => false,
        ]);
    }

    public function test_test_channel_success_persists_chat_and_message_without_enabling_toggles(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        StoreChannelSetting::create(['channel_username' => '@lhie_store']);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/botbot-token/getChat' => Http::response(['ok' => true, 'result' => ['id' => -100123, 'title' => 'LHIE']]),
            'https://api.telegram.org/botbot-token/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 77]]),
        ]);

        $this->post(route('admin.store-channel.test'))
            ->assertRedirect(route('admin.dashboard', ['tab' => 'telegram-channel']));

        $this->assertDatabaseHas('store_channel_settings', [
            'telegram_chat_id' => '-100123',
            'last_test_success' => true,
            'last_test_message_id' => '77',
            'community_gate_enabled' => false,
            'sales_proof_enabled' => false,
        ]);
    }

    public function test_test_channel_failure_records_actionable_reason(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        StoreChannelSetting::create(['channel_username' => '@lhie_store']);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/botbot-token/getChat' => Http::response(['ok' => false, 'description' => 'Forbidden: bot is not a member'], 403),
        ]);

        $this->post(route('admin.store-channel.test'));

        $this->assertDatabaseHas('store_channel_settings', [
            'last_test_success' => false,
        ]);
        $this->assertStringContainsString('Bot cannot access channel', StoreChannelSetting::first()->last_test_error);
    }

    public function test_test_channel_failure_redacts_telegram_bot_token_from_session_database_and_audit_log(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        StoreChannelSetting::create(['channel_username' => '@lhie_store']);
        config()->set('services.telegram.token', 'SECRET-issue-109');

        $botApiUrl = 'https://api.telegram.org/botSECRET-issue-109/getChat';
        $rawToken = 'SECRET-issue-109';
        $rawBotSegment = '/botSECRET-issue-109/';

        Http::preventStrayRequests();
        Http::fake([
            $botApiUrl => Http::response([
                'ok' => false,
                'description' => "Forbidden: {$botApiUrl} leaked from /botSECRET-issue-109/getChat",
            ], 403),
        ]);

        $this->post(route('admin.store-channel.test'))
            ->assertRedirect(route('admin.dashboard', ['tab' => 'telegram-channel']))
            ->assertSessionHas('error');

        $sessionError = (string) session('error');
        $storedError = (string) StoreChannelSetting::firstOrFail()->last_test_error;
        $auditPayload = json_encode(
            AuditLog::query()->where('action', 'store_channel.tested')->firstOrFail()->new_values,
            JSON_THROW_ON_ERROR
        );

        foreach ([
            'session error' => $sessionError,
            'stored error' => $storedError,
            'audit payload' => $auditPayload,
        ] as $surface => $value) {
            $this->assertStringContainsString('Bot cannot access channel', $value, "{$surface} dropped the actionable Telegram failure.");
            $this->assertStringNotContainsString($rawToken, $value, "{$surface} leaked the raw Telegram bot token.");
            $this->assertStringNotContainsString($rawBotSegment, $value, "{$surface} leaked the raw Telegram bot URL segment.");
            $this->assertStringNotContainsString($botApiUrl, $value, "{$surface} leaked the full Telegram Bot API URL.");
        }

        $dashboardContent = $this->get(route('admin.dashboard', ['tab' => 'telegram-channel']))
            ->assertOk()
            ->assertSee('Bot cannot access channel')
            ->getContent();

        $this->assertStringNotContainsString($rawToken, $dashboardContent, 'dashboard leaked the raw Telegram bot token.');
        $this->assertStringNotContainsString($rawBotSegment, $dashboardContent, 'dashboard leaked the raw Telegram bot URL segment.');
        $this->assertStringNotContainsString($botApiUrl, $dashboardContent, 'dashboard leaked the full Telegram Bot API URL.');
    }

    public function test_configured_channel_shows_needs_test_before_first_test(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        StoreChannelSetting::create(['channel_username' => '@lhie_store']);

        $this->get(route('admin.dashboard', ['tab' => 'telegram-channel']))
            ->assertOk()
            ->assertSee('Needs test')
            ->assertSee('Jalankan Test Channel');
    }

    public function test_updating_channel_target_clears_previous_test_result(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        StoreChannelSetting::create([
            'channel_username' => '@old_store',
            'telegram_chat_id' => '-100123',
            'last_test_success' => true,
            'last_test_message_id' => '77',
        ]);

        $this->post(route('admin.store-channel.update'), [
            'channel_username' => '@new_store',
        ])->assertRedirect(route('admin.dashboard', ['tab' => 'telegram-channel']));

        $setting = StoreChannelSetting::first();
        $this->assertSame('@new_store', $setting->channel_username);
        $this->assertNull($setting->telegram_chat_id);
        $this->assertNull($setting->last_test_success);
        $this->assertNull($setting->last_test_message_id);
    }

    public function test_store_channel_validation_uses_store_channel_error_bag_and_channel_tab(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->from('/admin?tab=telegram-channel')
            ->post(route('admin.store-channel.update'), [
                'channel_username' => str_repeat('x', 256),
            ])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'telegram-channel']))
            ->assertSessionHasErrors(['channel_username'], null, 'storeChannel');

        $this->assertDatabaseCount('store_channel_settings', 0);
    }

    public function test_non_manager_cannot_update_store_channel(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Finance]));

        $this->post(route('admin.store-channel.update'), ['channel_username' => '@x'])->assertForbidden();
    }

    public function test_non_manager_cannot_test_store_channel(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Finance]));
        StoreChannelSetting::create(['channel_username' => '@lhie_store']);

        $this->post(route('admin.store-channel.test'))->assertForbidden();
    }
}
