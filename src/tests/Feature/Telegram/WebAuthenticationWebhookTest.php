<?php

namespace Tests\Feature\Telegram;

use App\Jobs\ProcessTelegramUpdate;
use App\Models\Customer;
use App\Models\TelegramUpdate;
use App\Services\Customer\CustomerTelegramAuthenticationService;
use App\Services\Telegram\TelegramBotApiRedactor;
use App\Services\Telegram\TelegramUpdateProcessor;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Support\TelegramBotTestSupport;

class WebAuthenticationWebhookTest extends TelegramBotTestSupport
{
    private bool $botUnavailable = false;

    private bool $callbackUnavailable = false;

    private string $providerEcho = '';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) {
            if ($this->botUnavailable || ($this->callbackUnavailable && str_ends_with($request->url(), '/answerCallbackQuery'))) {
                $confirmation = $request['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? $this->providerEcho;

                return Http::response(['ok' => false, 'description' => 'Provider echoed '.$confirmation], 503);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 61000]]);
        });
    }

    public function test_unused_actor_is_not_registered_before_explicit_confirmation_and_browser_consumption(): void
    {
        $customer = $this->emailCustomer();
        [$browser, $payload] = $this->start($customer);

        $this->postTelegramWebhook($this->messageUpdate(61001, '/start '.$payload, ['is_bot' => false]))->assertOk();

        $confirmation = $this->confirmation();
        $this->assertMatchesRegularExpression('/\Awa_confirm:[A-Za-z0-9_-]{43}\z/', $confirmation);
        $this->assertNotSame(substr($payload, 3), substr($confirmation, 11));
        $this->assertSame('pending', $this->authentication()->status($browser));
        $this->assertFalse($this->authentication()->consume($browser));
        $this->assertSame(1, Customer::count());
        $this->assertNull($customer->fresh()->telegram_id);
        $this->assertNull(TelegramUpdate::firstOrFail()->customer_id);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'browser Anda sendiri')
            && str_contains((string) $request['text'], 'Jika bukan Anda')
            && $request['reply_markup']['inline_keyboard'][0][0]['style'] === 'primary');

        $this->postTelegramWebhook($this->callbackUpdate(61002, 'confirm-link', $confirmation, ['is_bot' => false]))->assertOk();
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && (string) $request['chat_id'] === '12345'
            && $request['text'] === 'Telegram dikonfirmasi. Kembali ke situs toko di browser yang memulai permintaan, lalu klik "Periksa konfirmasi Telegram" untuk menyelesaikannya.'
            && ! isset($request['reply_markup']));

        $this->assertSame('confirmed', $this->authentication()->status($browser));
        $this->assertNull($customer->fresh()->telegram_id);
        $this->assertTrue($this->authentication()->consume($browser));
        $this->assertSame('12345', (string) $customer->fresh()->telegram_id);
        $this->assertSame(1, Customer::count());
        $this->assertDatabaseHas('customer_identities', [
            'customer_id' => $customer->id,
            'provider' => 'telegram',
            'provider_subject' => '12345',
        ]);
        $this->assertEncryptedUpdates($payload, $confirmation);
    }

    public function test_anonymous_proof_signs_in_existing_canonical_customer_without_registering_another(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'email' => null]);
        [$browser, $payload] = $this->start();

        $this->postTelegramWebhook($this->messageUpdate(61003, '/start@StoreAuthenticationBot '.$payload, ['is_bot' => false]))->assertOk();
        $this->assertGuest('customer');
        $this->postTelegramWebhook($this->callbackUpdate(61004, 'confirm-login', $this->confirmation(), ['is_bot' => false]))->assertOk();
        $this->assertGuest('customer');
        $this->assertTrue($this->authentication()->consume($browser));

        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertSame(1, Customer::count());
        $this->assertNull(TelegramUpdate::firstOrFail()->customer_id);
    }

    public function test_unused_actor_cannot_create_a_customer_through_anonymous_sign_in(): void
    {
        [$browser, $payload] = $this->start();

        $this->postTelegramWebhook($this->messageUpdate(61005, '/start '.$payload, ['is_bot' => false]))->assertOk();

        $this->assertSame(0, Customer::count());
        $this->assertFalse($this->authentication()->consume($browser));
        $this->assertGuest('customer');
        Http::assertNotSent(fn (ClientRequest $request): bool => isset($request['reply_markup']));
    }

    public function test_foreign_actor_cannot_replace_prepared_actor_or_confirm_the_callback(): void
    {
        [$browser, $payload] = $this->start($this->emailCustomer());
        $this->postTelegramWebhook($this->messageUpdate(61006, '/start '.$payload, ['is_bot' => false]))->assertOk();
        $confirmation = $this->confirmation();

        $this->postTelegramWebhook($this->messageUpdate(61007, '/start '.$payload, ['id' => 56789, 'is_bot' => false], 56789))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(61008, 'foreign-confirm', $confirmation, ['id' => 56789, 'is_bot' => false], 56789))->assertOk();

        $this->assertSame('pending', $this->authentication()->status($browser));
        $this->assertSame(1, Customer::count());
        $this->assertSame('12345', DB::table('customer_telegram_auth_claims')->value('actor_id'));
        $this->assertFalse($this->authentication()->consume($browser));
        Http::assertNotSent(fn (ClientRequest $request): bool => str_contains((string) $request['text'], 'Telegram dikonfirmasi'));

        $this->postTelegramWebhook($this->callbackUpdate(61009, 'right-confirm', $confirmation, ['is_bot' => false]))->assertOk();
        $this->assertSame('confirmed', $this->authentication()->status($browser));
        $this->assertTrue($this->authentication()->consume($browser));
    }

    public function test_non_private_mismatched_and_bot_messages_never_prepare_or_resolve_a_customer(): void
    {
        [$browser, $payload] = $this->start($this->emailCustomer());
        $updates = [
            $this->messageUpdate(61010, '/start '.$payload, ['id' => 12345, 'is_bot' => false], -12345, 'group'),
            $this->messageUpdate(61011, '/start '.$payload, ['id' => 12345, 'is_bot' => false], -12345, 'supergroup'),
            $this->messageUpdate(61012, '/start '.$payload, ['id' => 12345, 'is_bot' => false], 56789),
            $this->messageUpdate(61013, '/start '.$payload, ['is_bot' => true]),
            $this->messageUpdate(61014, '/start '.$payload),
        ];
        $missingChatType = $this->messageUpdate(61015, '/start '.$payload, ['is_bot' => false]);
        unset($missingChatType['message']['chat']['type']);
        $updates[] = $missingChatType;
        foreach ($updates as $update) {
            $this->postTelegramWebhook($update)->assertOk();
        }

        Http::assertNothingSent();
        $this->assertSame('pending', $this->authentication()->status($browser));
        $this->assertNull(DB::table('customer_telegram_auth_claims')->value('prepared_at'));
        $this->assertSame(1, Customer::count());
        $this->assertSame(0, TelegramUpdate::whereNotNull('customer_id')->count());
    }

    public function test_non_private_mismatched_and_bot_callbacks_never_confirm_or_resolve_a_customer(): void
    {
        [$browser, $payload] = $this->start($this->emailCustomer());
        $this->postTelegramWebhook($this->messageUpdate(61016, '/start '.$payload, ['is_bot' => false]))->assertOk();
        $confirmation = $this->confirmation();
        $updates = [
            $this->callbackUpdate(61017, 'group', $confirmation, ['id' => 12345, 'is_bot' => false], -12345, 'group'),
            $this->callbackUpdate(61018, 'mismatch', $confirmation, ['id' => 12345, 'is_bot' => false], 56789),
            $this->callbackUpdate(61019, 'bot', $confirmation, ['is_bot' => true]),
            $this->callbackUpdate(61020, 'missing-bot-fact', $confirmation),
        ];
        $missingChatType = $this->callbackUpdate(61021, 'missing-chat-fact', $confirmation, ['is_bot' => false]);
        unset($missingChatType['callback_query']['message']['chat']['type']);
        $updates[] = $missingChatType;
        foreach ($updates as $update) {
            $this->postTelegramWebhook($update)->assertOk();
        }

        Http::assertSentCount(1);
        Http::assertNotSent(fn (ClientRequest $request): bool => str_contains((string) $request['text'], 'Telegram dikonfirmasi'));
        $this->assertSame('pending', $this->authentication()->status($browser));
        $this->assertFalse($this->authentication()->consume($browser));
        $this->assertSame(1, Customer::count());
        $this->assertSame(0, TelegramUpdate::whereNotNull('customer_id')->count());
    }

    public function test_webhook_and_confirmation_replays_cannot_repeat_the_grant(): void
    {
        [$browser, $payload] = $this->start($this->emailCustomer());
        $message = $this->messageUpdate(61022, '/start '.$payload, ['is_bot' => false]);
        $this->postTelegramWebhook($message)->assertOk();
        $confirmation = $this->confirmation();
        $this->postTelegramWebhook($message)->assertOk();
        Http::assertSentCount(1);

        $callback = $this->callbackUpdate(61023, 'first-confirm', $confirmation, ['is_bot' => false]);
        $this->postTelegramWebhook($callback)->assertOk();
        $this->postTelegramWebhook($callback)->assertOk();
        Http::assertSentCount(3);
        $this->assertFalse($this->authentication()->confirm($confirmation, '12345', '12345', 'private'));
        $this->assertTrue($this->authentication()->consume($browser));
        $this->assertFalse($this->authentication()->consume($browser));
        $this->postTelegramWebhook($this->callbackUpdate(61024, 'replayed-confirm', $confirmation, ['is_bot' => false]))->assertOk();
        Http::assertSentCount(4);
        $this->assertCount(1, Http::recorded(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'Telegram dikonfirmasi')));
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && $request['callback_query_id'] === 'replayed-confirm'
            && $request['text'] === 'Permintaan akun web tidak dapat digunakan. Mulai lagi dari situs toko.');
        $this->assertSame(1, DB::table('customer_identities')->where('provider', 'telegram')->count());
        $this->assertSame(1, Customer::count());
    }

    public function test_unknown_and_expired_confirmation_claims_never_send_success_feedback(): void
    {
        [$browser, $payload] = $this->start($this->emailCustomer());
        $this->postTelegramWebhook($this->messageUpdate(61035, '/start '.$payload, ['is_bot' => false]))->assertOk();
        $confirmation = $this->confirmation();

        $this->postTelegramWebhook($this->callbackUpdate(61036, 'unknown-confirm', 'wa_confirm:'.str_repeat('A', 43), ['is_bot' => false]))->assertOk();
        $this->assertSame('pending', $this->authentication()->status($browser));
        $this->travel(5)->minutes();
        $this->postTelegramWebhook($this->callbackUpdate(61037, 'expired-confirm', $confirmation, ['is_bot' => false]))->assertOk();

        $this->assertSame('expired', $this->authentication()->status($browser));
        $this->assertFalse($this->authentication()->consume($browser));
        $this->assertNull(DB::table('customer_telegram_auth_claims')->value('confirmed_at'));
        Http::assertNotSent(fn (ClientRequest $request): bool => str_contains((string) $request['text'], 'Telegram dikonfirmasi'));
        foreach (['unknown-confirm', 'expired-confirm'] as $callbackId) {
            Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
                && $request['callback_query_id'] === $callbackId
                && $request['text'] === 'Permintaan akun web tidak dapat digunakan. Mulai lagi dari situs toko.');
        }
    }

    public function test_wrong_webhook_secret_cannot_prepare_or_persist_a_proof(): void
    {
        [$browser, $payload] = $this->start($this->emailCustomer());
        $this->postTelegramWebhook($this->messageUpdate(61025, '/start '.$payload, ['is_bot' => false]), 'foreign-secret')->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(0, TelegramUpdate::count());
        $this->assertSame('pending', $this->authentication()->status($browser));
        $this->assertNull(DB::table('customer_telegram_auth_claims')->value('prepared_at'));
    }

    public function test_bot_outage_keeps_proof_pending_and_retry_rotates_confirmation_without_leaking_secrets(): void
    {
        [$browser, $payload] = $this->start($this->emailCustomer());
        Bus::fake();
        $this->botUnavailable = true;
        $this->postTelegramWebhook($this->messageUpdate(61026, '/start '.$payload, ['is_bot' => false]))->assertOk();
        $update = TelegramUpdate::firstOrFail();
        try {
            app(TelegramUpdateProcessor::class)->process($update);
            $this->fail('A bot outage must leave a retryable update, not complete the proof.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Telegram web authentication response is unavailable.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $oldConfirmation = $this->confirmation();
        $this->assertEncryptedUpdates($payload, $oldConfirmation);
        $this->assertSame('pending', $this->authentication()->status($browser));
        $this->assertFalse($this->authentication()->consume($browser));
        $this->assertNull($update->fresh()->processed_at);
        $this->assertNotNull($update->fresh()->next_retry_at);
        $this->assertSame(1, Customer::count());
        Bus::assertDispatched(ProcessTelegramUpdate::class);

        $this->botUnavailable = false;
        app(TelegramUpdateProcessor::class)->process($update->fresh());
        $newConfirmation = $this->confirmation();
        $this->assertNotSame($oldConfirmation, $newConfirmation);
        $this->assertFalse($this->authentication()->confirm($oldConfirmation, '12345', '12345', 'private'));
        $this->assertTrue($this->authentication()->confirm($newConfirmation, '12345', '12345', 'private'));
        $this->assertTrue($this->authentication()->consume($browser));
        $this->assertNotNull($update->fresh()->processed_at);
    }

    public function test_callback_provider_error_redacts_echoed_capability_without_reversing_confirmed_proof(): void
    {
        [$browser, $payload] = $this->start($this->emailCustomer());
        $this->postTelegramWebhook($this->messageUpdate(61028, '/start '.$payload, ['is_bot' => false]))->assertOk();
        $confirmation = $this->confirmation();
        Log::spy();
        $this->callbackUnavailable = true;
        $this->providerEcho = $confirmation;

        $this->postTelegramWebhook($this->callbackUpdate(61029, 'outage-confirm', $confirmation, ['is_bot' => false]))->assertOk();

        $this->assertSame('confirmed', $this->authentication()->status($browser));
        $this->assertTrue($this->authentication()->consume($browser));
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && (string) $request['chat_id'] === '12345'
            && str_contains((string) $request['text'], 'Periksa konfirmasi Telegram'));
        Log::shouldHaveReceived('warning')->with('telegram.answer_callback_query_failed', \Mockery::on(
            fn (array $context): bool => ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), $confirmation)
                && str_contains($context['description'], '[redacted-secret]'),
        ))->once();
        $this->assertEncryptedUpdates($payload, $confirmation);
    }

    public function test_persistent_confirmation_feedback_failure_is_secret_safe_without_reversing_proof(): void
    {
        [$browser, $payload] = $this->start($this->emailCustomer());
        $this->postTelegramWebhook($this->messageUpdate(61038, '/start '.$payload, ['is_bot' => false]))->assertOk();
        $confirmation = $this->confirmation();
        Bus::fake();
        $this->botUnavailable = true;
        $this->providerEcho = $confirmation;
        $this->postTelegramWebhook($this->callbackUpdate(61039, 'feedback-outage-confirm', $confirmation, ['is_bot' => false]))->assertOk();
        $update = TelegramUpdate::where('update_id', 61039)->firstOrFail();

        try {
            app(TelegramUpdateProcessor::class)->process($update);
            $this->fail('Persistent feedback delivery failure must remain retryable.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Telegram web authentication response is unavailable.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        $this->assertSame('confirmed', $this->authentication()->status($browser));
        $this->assertTrue($this->authentication()->consume($browser));
        $this->assertNull($update->fresh()->processed_at);
        $this->assertNotNull($update->fresh()->next_retry_at);
        $this->assertEncryptedUpdates($payload, $confirmation);
    }

    public function test_fast_webhook_callback_ack_preserves_explicit_confirmation_and_encrypted_storage(): void
    {
        config()->set('services.telegram.webhook_fast_callback_ack', true);
        [$browser, $payload] = $this->start($this->emailCustomer());
        $this->postTelegramWebhook($this->messageUpdate(61030, '/start '.$payload, ['is_bot' => false]))->assertOk();
        $confirmation = $this->confirmation();

        $this->postTelegramWebhook($this->callbackUpdate(61031, 'fast-auth-confirm', $confirmation, ['is_bot' => false]))
            ->assertOk()
            ->assertExactJson(['method' => 'answerCallbackQuery', 'callback_query_id' => 'fast-auth-confirm']);

        $this->assertSame('confirmed', $this->authentication()->status($browser));
        $this->assertTrue($this->authentication()->consume($browser));
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && is_string($request['text']) && $request['text'] !== '');
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && (string) $request['chat_id'] === '12345'
            && str_contains((string) $request['text'], 'Periksa konfirmasi Telegram'));
        $this->assertEncryptedUpdates($payload, $confirmation);
    }

    public function test_malformed_reserved_auth_payloads_are_not_normal_customer_commands(): void
    {
        $this->postTelegramWebhook($this->messageUpdate(61032, '/start wa_short', ['is_bot' => false]))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(61033, 'bad-auth-confirm', 'wa_confirm:short', ['is_bot' => false]))->assertOk();

        $this->assertSame(0, Customer::count());
        $this->assertSame(0, DB::table('customer_telegram_auth_claims')->count());
        $this->assertEncryptedUpdates('wa_short', 'wa_confirm:short');
        Http::assertNotSent(fn (ClientRequest $request): bool => str_contains((string) $request['text'], 'Telegram dikonfirmasi'));
    }

    public function test_unrelated_start_keeps_normal_customer_resolution_and_bot_routing(): void
    {
        $this->postTelegramWebhook($this->messageUpdate(61034, '/start', ['is_bot' => false]))->assertOk();

        $customer = Customer::where('telegram_id', 12345)->firstOrFail();
        $update = TelegramUpdate::firstOrFail();
        $this->assertSame($customer->id, $update->customer_id);
        $this->assertSame('/start', $update->payload['message']['text']);
        $this->assertArrayNotHasKey('_encrypted_web_auth', $update->payload);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            || str_ends_with($request->url(), '/sendPhoto'));
    }

    public function test_auth_capability_redactor_covers_deep_links_and_encoded_callbacks(): void
    {
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $redacted = TelegramBotApiRedactor::redactText('/start wa_'.$secret.' https://t.me/StoreAuthenticationBot?start=wa_'.$secret.' wa_confirm:'.$secret.' wa_confirm%3A'.$secret);

        $this->assertStringNotContainsString($secret, $redacted);
        $this->assertSame(4, substr_count($redacted, '[redacted-secret]'));
    }

    private function emailCustomer(): Customer
    {
        return Customer::factory()->create(['telegram_id' => null, 'email' => 'web-proof@example.test', 'email_verified_at' => now()])->fresh();
    }

    private function start(?Customer $customer = null): array
    {
        $browser = Request::create('/akun', 'POST', server: ['REMOTE_ADDR' => '192.0.2.61']);
        $browser->setLaravelSession(app('session')->driver());
        $browser->session()->start();
        if ($customer !== null) {
            $this->actingAs($customer, 'customer');
            $browser->session()->put('customer_auth_version', $customer->auth_version);
            $browser->session()->put('customer_authenticated_at', now()->timestamp);
        }
        $browser->setUserResolver(fn (?string $guard = null) => auth($guard)->user());
        $started = $this->authentication()->start($browser, $customer !== null);
        $this->assertNotNull($started);
        $this->assertSame(300, $started['expires_in']);
        parse_str((string) parse_url($started['deep_link'], PHP_URL_QUERY), $query);
        $this->assertMatchesRegularExpression('/\Awa_[A-Za-z0-9_-]{43}\z/', $query['start']);

        return [$browser, $query['start']];
    }

    private function authentication(): CustomerTelegramAuthenticationService
    {
        return app(CustomerTelegramAuthenticationService::class);
    }

    private function confirmation(): string
    {
        $request = Http::recorded(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && isset($request['reply_markup']['inline_keyboard'][0][0]['callback_data']))->last()[0] ?? null;
        $this->assertNotNull($request);

        return $request['reply_markup']['inline_keyboard'][0][0]['callback_data'];
    }

    private function assertEncryptedUpdates(string ...$capabilities): void
    {
        foreach (TelegramUpdate::all() as $update) {
            $stored = (string) DB::table('telegram_updates')->where('id', $update->id)->value('payload');
            $serialized = $update->toJson();
            foreach ($capabilities as $capability) {
                $this->assertStringNotContainsString($capability, $stored);
                $this->assertStringNotContainsString($capability, $serialized);
                $this->assertStringNotContainsString(explode(':', $capability)[1] ?? substr($capability, 3), $stored);
            }
            $this->assertArrayHasKey('_encrypted_web_auth', $update->payload);
            $this->assertNull($update->customer_id);
        }
    }
}
