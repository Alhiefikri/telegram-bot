<?php

namespace Tests\Feature;

use App\Models\CustomerTelegramAuthClaim;
use App\Services\Telegram\TelegramBotApiRequestFactory;
use App\Services\Telegram\TelegramBotIdentity;
use App\Storefront\StorefrontUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TelegramBotIdentityTest extends TestCase
{
    use RefreshDatabase;

    private TelegramBotIdentity $identity;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'services.telegram.api_base_url' => 'https://api.telegram.org']);
        $this->identity = new TelegramBotIdentity(app(TelegramBotApiRequestFactory::class));
        $this->app->instance(TelegramBotIdentity::class, $this->identity);
    }

    public function test_token_rotation_changes_login_and_product_destinations_without_reusing_another_bots_cache(): void
    {
        Http::fake([
            'https://api.telegram.org/bot101:fixture-one/getMe' => Http::response($this->bot('FirstTestBot', 101)),
            'https://api.telegram.org/bot202:fixture-two/getMe' => Http::response($this->bot('SecondTestBot', 202)),
        ]);
        config(['services.telegram.token' => '101:fixture-one']);
        $first = $this->postJson(route('customer.telegram.login'))->assertCreated();
        $this->assertSame('/FirstTestBot', parse_url($first->json('deep_link'), PHP_URL_PATH));
        $this->assertSame('https://t.me/FirstTestBot?start=product_17', app(StorefrontUrl::class)->telegramProductStartUrlForId(17));

        config(['services.telegram.token' => '202:fixture-two']);
        $second = $this->postJson(route('customer.telegram.login'))->assertCreated();
        $this->assertSame('/SecondTestBot', parse_url($second->json('deep_link'), PHP_URL_PATH));
        $this->assertSame('https://t.me/SecondTestBot?start=product_17', app(StorefrontUrl::class)->telegramProductStartUrlForId(17));

        config(['services.telegram.token' => '101:fixture-one']);
        $this->assertSame('https://t.me/FirstTestBot?start=landing', $this->identity->startUrl());
        Http::assertSentCount(2);
    }

    public function test_username_rename_is_refreshed_after_the_cached_identity_expires(): void
    {
        config(['services.telegram.token' => '101:fixture-one']);
        Http::fake(['https://api.telegram.org/*/getMe' => Http::sequence()
            ->push($this->bot('OldTestBot', 101))
            ->push($this->bot('RenamedTestBot', 101))]);
        $this->assertSame('https://t.me/OldTestBot?start=landing', $this->identity->startUrl());
        $this->travel(3601)->seconds();
        $this->assertSame('https://t.me/RenamedTestBot?start=landing', $this->identity->startUrl());
    }

    #[DataProvider('unusableIdentities')]
    public function test_unusable_identity_blocks_login_without_creating_a_claim_or_falling_back_to_another_bot(array $response, int $status): void
    {
        config(['services.telegram.token' => '101:fixture-one']);
        Http::fake(['https://api.telegram.org/*/getMe' => Http::sequence()
            ->push($response, $status)
            ->push($this->bot('RecoveredTestBot', 101))]);
        $this->postJson(route('customer.telegram.login'))->assertStatus(422)->assertJsonMissingPath('deep_link');
        $this->assertSame(0, CustomerTelegramAuthClaim::count());
        $this->assertNull($this->identity->startUrl());
        Http::assertSentCount(1);
        $this->travel(31)->seconds();
        $this->assertSame('https://t.me/RecoveredTestBot?start=landing', $this->identity->startUrl());
    }

    public static function unusableIdentities(): array
    {
        return [
            'rejected token' => [['ok' => false], 401],
            'missing username' => [['ok' => true, 'result' => ['id' => 101, 'is_bot' => true]], 200],
            'not a bot' => [['ok' => true, 'result' => ['id' => 101, 'is_bot' => false, 'username' => 'OtherUser']], 200],
            'unsafe username' => [['ok' => true, 'result' => ['id' => 101, 'is_bot' => true, 'username' => 'OtherBot/redirect']], 200],
            'malformed envelope' => [['ok' => true, 'result' => 'not-an-identity'], 200],
        ];
    }

    public function test_transport_failure_is_short_cached_and_never_exposes_the_token_bearing_error(): void
    {
        config(['services.telegram.token' => '101:fixture-secret']);
        $calls = 0;
        Http::fake(['https://api.telegram.org/*/getMe' => function () use (&$calls) {
            $calls++;
            throw new ConnectionException('https://api.telegram.org/bot101:fixture-secret/getMe unavailable');
        }]);
        $response = $this->postJson(route('customer.telegram.login'))->assertStatus(422);
        $this->assertStringNotContainsString('fixture-secret', $response->getContent());
        $this->assertNull($this->identity->startUrl());
        $this->assertSame(0, CustomerTelegramAuthClaim::count());
        $this->assertSame(1, $calls);
        $this->travel(31)->seconds();
        $this->assertNull($this->identity->startUrl());
        $this->assertSame(2, $calls);
    }

    public function test_missing_token_never_requests_an_identity_or_returns_a_bot_link(): void
    {
        config(['services.telegram.token' => '']);
        $this->assertNull($this->identity->startUrl());
        $this->postJson(route('customer.telegram.login'))->assertStatus(422);
        Http::assertNothingSent();
    }

    private function bot(string $username, int $id): array
    {
        return ['ok' => true, 'result' => ['id' => $id, 'is_bot' => true, 'username' => $username]];
    }
}
