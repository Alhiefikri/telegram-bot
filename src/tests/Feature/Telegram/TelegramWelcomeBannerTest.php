<?php

namespace Tests\Feature\Telegram;

use App\Enums\TelegramNavigationMediaKind;
use App\Enums\TelegramNavigationScreen;
use App\Models\Customer;
use App\Models\StorefrontSetting;
use App\Models\TelegramNavigationRoot;
use App\Services\Telegram\TelegramWelcomeBanner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TelegramBotTestSupport;

final class TelegramWelcomeBannerTest extends TelegramBotTestSupport
{
    public function test_private_start_uploads_the_active_banner_once_then_reuses_the_cached_file_id(): void
    {
        Storage::fake('public');
        $path = 'telegram/welcome-banners/v7.webp';
        $contents = $this->storeBanner($path);
        StorefrontSetting::query()->firstOrFail()->update([
            'telegram_welcome_banner_path' => $path,
            'telegram_welcome_banner_file_id' => null,
            'telegram_welcome_banner_version' => 7,
        ]);
        Customer::factory()->create(['telegram_id' => 45201, 'locale' => 'id']);

        $nextMessageId = 600;
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => function (Request $_request) use (&$nextMessageId) {
                $nextMessageId++;

                return Http::response([
                    'ok' => true,
                    'result' => [
                        'message_id' => $nextMessageId,
                        'photo' => [['file_id' => 'dynamic-banner-'.$nextMessageId]],
                    ],
                ]);
            },
            'https://api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 699],
            ]),
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $this->postTelegramWebhook($this->messageUpdate(45201, '/start', [], 45201))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(45202, '/start', [], 45201))->assertOk();

        $photoRequests = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'));
        $this->assertCount(2, $photoRequests);
        $this->assertSame($contents, $this->telegramRequestField($photoRequests->first()[0], 'photo'));
        $this->assertSame('dynamic-banner-601', $this->telegramRequestField($photoRequests->last()[0], 'photo'));

        $setting = StorefrontSetting::query()->firstOrFail();
        $this->assertSame('dynamic-banner-601', $setting->telegram_welcome_banner_file_id);
        $this->assertSame(7, $setting->telegram_welcome_banner_version);
        $this->assertSame(7, TelegramNavigationRoot::query()->sole()->banner_version);
    }

    public function test_same_version_root_reference_is_reused_when_file_id_cache_is_empty(): void
    {
        Storage::fake('public');
        $path = 'telegram/welcome-banners/root-reference-v8.webp';
        $this->storeBanner($path);
        StorefrontSetting::query()->firstOrFail()->update([
            'telegram_welcome_banner_path' => $path,
            'telegram_welcome_banner_file_id' => null,
            'telegram_welcome_banner_version' => 8,
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 45241, 'locale' => 'id']);
        TelegramNavigationRoot::query()->create([
            'customer_id' => $customer->id,
            'chat_id' => '45241',
            'message_id' => 555,
            'media_kind' => TelegramNavigationMediaKind::Photo,
            'screen' => TelegramNavigationScreen::Home,
            'locale' => 'id',
            'banner_reference' => 'same-version-file-id',
            'banner_version' => 8,
            'generation' => 1,
            'rendered_at' => now(),
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 556,
                    'photo' => [['file_id' => 'same-version-file-id']],
                ],
            ]),
            'https://api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 557],
            ]),
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $this->postTelegramWebhook($this->messageUpdate(45242, '/start', [], 45241))->assertOk();

        $request = Http::recorded(fn (Request $recorded): bool => str_ends_with($recorded->url(), '/sendPhoto'))->sole()[0];
        $this->assertSame('same-version-file-id', $this->telegramRequestField($request, 'photo'));
        $this->assertNull(StorefrontSetting::query()->firstOrFail()->telegram_welcome_banner_file_id);
    }

    public function test_group_start_uses_the_active_banner_without_creating_a_private_root(): void
    {
        Storage::fake('public');
        $path = 'telegram/welcome-banners/group-v3.webp';
        $contents = $this->storeBanner($path);
        StorefrontSetting::query()->firstOrFail()->update([
            'telegram_welcome_banner_path' => $path,
            'telegram_welcome_banner_file_id' => null,
            'telegram_welcome_banner_version' => 3,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 801,
                    'photo' => [['file_id' => 'group-banner-file-id']],
                ],
            ]),
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $this->postTelegramWebhook($this->messageUpdate(
            45221,
            '/start',
            ['id' => 45221, 'first_name' => 'Group User'],
            -10045221,
            'group',
        ))->assertOk();

        $request = Http::recorded(fn (Request $recorded): bool => str_ends_with($recorded->url(), '/sendPhoto'))->sole()[0];
        $this->assertSame($contents, $this->telegramRequestField($request, 'photo'));
        $this->assertSame(0, TelegramNavigationRoot::query()->count());
        $this->assertSame('group-banner-file-id', StorefrontSetting::query()->firstOrFail()->telegram_welcome_banner_file_id);
    }

    public function test_replacing_the_active_banner_does_not_reuse_a_stale_root_reference(): void
    {
        Storage::fake('public');
        $oldPath = 'telegram/welcome-banners/v1.webp';
        $newPath = 'telegram/welcome-banners/v2.webp';
        $this->storeBanner($oldPath);
        $newContents = $this->storeBanner($newPath);
        StorefrontSetting::query()->firstOrFail()->update([
            'telegram_welcome_banner_path' => $oldPath,
            'telegram_welcome_banner_file_id' => 'old-banner-file-id',
            'telegram_welcome_banner_version' => 1,
        ]);
        Customer::factory()->create(['telegram_id' => 45211, 'locale' => 'id']);

        $nextMessageId = 700;
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => function (Request $_request) use (&$nextMessageId) {
                $nextMessageId++;

                return Http::response([
                    'ok' => true,
                    'result' => [
                        'message_id' => $nextMessageId,
                        'photo' => [['file_id' => $nextMessageId === 701 ? 'old-banner-file-id' : 'new-banner-file-id']],
                    ],
                ]);
            },
            'https://api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 799],
            ]),
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $this->postTelegramWebhook($this->messageUpdate(45211, '/start', [], 45211))->assertOk();
        $this->assertSame('old-banner-file-id', TelegramNavigationRoot::query()->sole()->banner_reference);

        StorefrontSetting::query()->firstOrFail()->update([
            'telegram_welcome_banner_path' => $newPath,
            'telegram_welcome_banner_file_id' => null,
            'telegram_welcome_banner_version' => 2,
        ]);

        $this->postTelegramWebhook($this->messageUpdate(45212, '/start', [], 45211))->assertOk();

        $photoRequests = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'));
        $this->assertCount(2, $photoRequests);
        $this->assertSame($newContents, $this->telegramRequestField($photoRequests->last()[0], 'photo'));
        $this->assertNotSame('old-banner-file-id', $this->telegramRequestField($photoRequests->last()[0], 'photo'));

        $setting = StorefrontSetting::query()->firstOrFail();
        $root = TelegramNavigationRoot::query()->sole();
        $this->assertSame('new-banner-file-id', $setting->telegram_welcome_banner_file_id);
        $this->assertSame(2, $setting->telegram_welcome_banner_version);
        $this->assertSame(2, $root->banner_version);
        $this->assertSame('new-banner-file-id', $root->banner_reference);
    }

    public function test_active_banner_uses_text_when_caption_exceeds_the_media_limit(): void
    {
        Storage::fake('public');
        $path = 'telegram/welcome-banners/long-caption-v4.webp';
        $this->storeBanner($path);
        StorefrontSetting::query()->firstOrFail()->update([
            'telegram_welcome_banner_path' => $path,
            'telegram_welcome_banner_file_id' => null,
            'telegram_welcome_banner_version' => 4,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 902],
            ]),
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $sent = app(TelegramWelcomeBanner::class)->send(
            '45231',
            str_repeat('A', 1025),
            [],
            options: ['parse_mode' => 'HTML'],
        );

        $this->assertSame(TelegramNavigationMediaKind::Text, $sent->mediaKind);
        $this->assertSame(4, $sent->bannerVersion);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'));
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'));
        $this->assertNull(StorefrontSetting::query()->firstOrFail()->telegram_welcome_banner_file_id);
    }

    public function test_missing_active_banner_file_falls_back_to_the_legacy_reference(): void
    {
        Storage::fake('public');
        StorefrontSetting::query()->firstOrFail()->update([
            'telegram_welcome_banner_path' => 'telegram/welcome-banners/missing.webp',
            'telegram_welcome_banner_file_id' => null,
            'telegram_welcome_banner_version' => 9,
        ]);
        config()->set('services.telegram.welcome_banner_url', 'legacy-fallback-file-id');

        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 903,
                    'photo' => [['file_id' => 'legacy-fallback-file-id']],
                ],
            ]),
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $sent = app(TelegramWelcomeBanner::class)->send(
            '45232',
            'Welcome',
            [],
            options: ['parse_mode' => 'HTML'],
        );

        $this->assertSame(TelegramNavigationMediaKind::Photo, $sent->mediaKind);
        $this->assertNull($sent->bannerVersion);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto')
            && ($request['photo'] ?? null) === 'legacy-fallback-file-id');
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'));
    }

    public function test_banner_path_traversal_is_ignored_in_favor_of_the_safe_fallback(): void
    {
        Storage::fake('public');
        StorefrontSetting::query()->firstOrFail()->update([
            'telegram_welcome_banner_path' => 'telegram/welcome-banners/../outside.webp',
            'telegram_welcome_banner_file_id' => 'cached-but-invalid',
            'telegram_welcome_banner_version' => 10,
        ]);
        config()->set('services.telegram.welcome_banner_url', 'safe-fallback-file-id');

        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 904,
                    'photo' => [['file_id' => 'safe-fallback-file-id']],
                ],
            ]),
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        app(TelegramWelcomeBanner::class)->send(
            '45233',
            'Welcome',
            [],
            options: ['parse_mode' => 'HTML'],
        );

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto')
            && ($request['photo'] ?? null) === 'safe-fallback-file-id');
    }

    private function storeBanner(string $path): string
    {
        $contents = (string) file_get_contents(resource_path('images/banner-welcome.png'));
        Storage::disk('public')->put($path, $contents);

        return $contents;
    }
}
