<?php

namespace Tests\Feature\Telegram;

use App\Enums\TelegramNavigationMediaKind;
use App\Enums\TelegramNavigationScreen;
use App\Models\CheckoutMaintenanceSetting;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TelegramNavigationRoot;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\TelegramBotTestSupport;

final class TelegramNavigationRootTest extends TelegramBotTestSupport
{
    public function test_returning_start_supersedes_the_pointer_without_touching_durable_order_data(): void
    {
        config()->set('services.telegram.welcome_banner_url', 'configured-banner-file-id');
        $customer = Customer::factory()->create([
            'telegram_id' => 45101,
            'locale' => 'id',
            'first_name' => 'Returning Customer',
        ]);
        $order = Order::factory()->for($customer)->create(['invoice_number' => 'INV-NAV-45101']);
        $payment = Payment::factory()->for($order)->create([
            'provider' => 'manual',
            'method' => 'QR_MANUAL',
        ]);
        $nextMessageId = 500;
        $this->fakeBannerSequence($nextMessageId);

        $this->postTelegramWebhook($this->messageUpdate(45101, '/start', [], 45101))->assertOk();
        $firstRoot = TelegramNavigationRoot::query()->sole();

        $this->postTelegramWebhook($this->messageUpdate(45102, '/start', [], 45101))->assertOk();
        $secondRoot = TelegramNavigationRoot::query()->sole();

        $this->assertNotSame($firstRoot->message_id, $secondRoot->message_id);
        $this->assertSame(1, TelegramNavigationRoot::query()->where('customer_id', $customer->id)->count());
        $this->assertSame($secondRoot->message_id, TelegramNavigationRoot::query()->where('customer_id', $customer->id)->value('message_id'));
        $this->assertSame($order->id, $order->fresh()->id);
        $this->assertSame($payment->id, $payment->fresh()->id);
        $this->assertSame(2, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'))->count());
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/deleteMessage'));
    }

    public function test_stale_foreign_group_and_unsupported_locale_callbacks_fail_closed(): void
    {
        config()->set('services.telegram.welcome_banner_url', 'configured-banner-file-id');
        $customer = Customer::factory()->create(['telegram_id' => 45102, 'locale' => null]);
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(45103, '/start', [], 45102))->assertOk();
        $root = TelegramNavigationRoot::query()->sole();

        $this->postTelegramWebhook($this->callbackUpdate(45104, 'stale-message', 'locale:en', [], 45102, 'private', $root->message_id + 1))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(45105, 'foreign-chat', 'locale:en', [], 999999, 'private', $root->message_id))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(45106, 'group-message', 'locale:en', ['id' => 45102], -10045102, 'group', $root->message_id))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(45107, 'unsupported-locale', 'locale:xx', [], 45102, 'private', $root->message_id))->assertOk();

        $this->assertSame('id', $customer->fresh()->locale);
        $this->assertSame(TelegramNavigationScreen::Home, $root->fresh()->screen);
        $this->assertSame(0, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageCaption'))->count());
    }

    public function test_group_start_keeps_public_copy_and_does_not_create_a_private_root(): void
    {
        config()->set('services.telegram.welcome_banner_url', 'configured-banner-file-id');
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(
            45116,
            '/start',
            ['id' => 45107, 'first_name' => 'Group Name', 'username' => 'group_user'],
            -10045107,
            'group',
        ))->assertOk();

        $request = Http::recorded(fn (Request $recorded): bool => str_ends_with($recorded->url(), '/sendPhoto'))->first()[0];
        $caption = (string) $this->telegramRequestField($request, 'caption');
        $this->assertSame(0, TelegramNavigationRoot::query()->count());
        $this->assertStringNotContainsString('Group Name', $caption);
        $this->assertStringNotContainsString('@group_user', $caption);
    }

    public function test_repeated_locale_taps_and_not_modified_render_converge_without_replacing_the_root(): void
    {
        config()->set('services.telegram.welcome_banner_url', 'configured-banner-file-id');
        $customer = Customer::factory()->create(['telegram_id' => 45103, 'locale' => null]);
        $this->fakeBannerSequence(600, Http::response([
            'ok' => false,
            'description' => 'Bad Request: message is not modified',
        ], 400));

        $this->postTelegramWebhook($this->messageUpdate(45108, '/start', [], 45103))->assertOk();
        $root = TelegramNavigationRoot::query()->sole();
        $messageId = $root->message_id;

        $this->postTelegramWebhook($this->callbackUpdate(45109, 'not-modified', 'locale:en', [], 45103, 'private', $messageId))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(45115, 'not-modified-repeat', 'locale:en', [], 45103, 'private', $messageId))->assertOk();

        $stored = $root->fresh();
        $this->assertSame($messageId, $stored->message_id);
        $this->assertSame(TelegramNavigationScreen::Home, $stored->screen);
        $this->assertSame('en', $stored->locale);
        $this->assertSame(TelegramNavigationMediaKind::Photo, $stored->media_kind);
        $this->assertSame(1, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'))->count());
        $this->assertSame(2, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageCaption'))->count());
    }

    public function test_definite_edit_failure_sends_exactly_one_replacement_and_reuses_banner_reference(): void
    {
        config()->set('services.telegram.welcome_banner_url', 'configured-banner-file-id');
        $customer = Customer::factory()->create(['telegram_id' => 45104, 'locale' => null]);
        $this->fakeBannerSequence(700, Http::response([
            'ok' => false,
            'description' => 'Bad Request: message to edit not found',
        ], 400));

        $this->postTelegramWebhook($this->messageUpdate(45110, '/start', [], 45104))->assertOk();
        $root = TelegramNavigationRoot::query()->sole();
        $oldMessageId = $root->message_id;
        $oldReference = $root->banner_reference;

        $this->postTelegramWebhook($this->callbackUpdate(45111, 'definite-failure', 'locale:en', [], 45104, 'private', $oldMessageId))->assertOk();

        $stored = $root->fresh();
        $this->assertNotSame($oldMessageId, $stored->message_id);
        $this->assertSame(TelegramNavigationScreen::Home, $stored->screen);
        $this->assertSame('en', $stored->locale);
        $this->assertSame(2, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'))->count());
        $photoRequests = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'));
        $this->assertSame($oldReference, $this->telegramRequestField($photoRequests->last()[0], 'photo'));
    }

    public function test_ambiguous_edit_failure_does_not_blindly_send_a_replacement(): void
    {
        config()->set('services.telegram.welcome_banner_url', 'configured-banner-file-id');
        $customer = Customer::factory()->create(['telegram_id' => 45105, 'locale' => null]);
        $this->fakeBannerSequence(800, Http::response([
            'ok' => false,
            'description' => 'temporary Telegram failure',
        ], 500));

        $this->postTelegramWebhook($this->messageUpdate(45112, '/start', [], 45105))->assertOk();
        $root = TelegramNavigationRoot::query()->sole();
        $messageId = $root->message_id;

        $this->postTelegramWebhook($this->callbackUpdate(45113, 'ambiguous-failure', 'locale:en', [], 45105, 'private', $messageId))->assertOk();

        $stored = $root->fresh();
        $this->assertSame($messageId, $stored->message_id);
        // An unproven edit never advances the stored home projection; the
        // customer's locale choice is persisted independently.
        $this->assertSame(TelegramNavigationScreen::Home, $stored->screen);
        $this->assertSame('id', $stored->locale);
        $this->assertSame('en', $customer->fresh()->locale);
        $this->assertSame(1, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'))->count());
        $this->assertSame(1, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageCaption'))->count());
    }

    public function test_private_welcome_caption_escapes_profile_values_and_stays_within_media_limit(): void
    {
        config()->set('services.telegram.welcome_banner_url', 'configured-banner-file-id');
        CheckoutMaintenanceSetting::query()->firstOrFail()->update([
            'is_active' => true,
            'public_note' => str_repeat('<&', 900),
            'version' => 1,
            'activated_at' => now(),
        ]);
        Customer::factory()->create([
            'telegram_id' => 45106,
            'locale' => 'en',
            'first_name' => '<Alice & "quoted">',
            'username' => 'buyer<&',
        ]);
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(45114, '/start', ['id' => 45106, 'first_name' => '<Alice & \"quoted\">', 'username' => 'buyer<&'], 45106))->assertOk();
        $request = Http::recorded(fn (Request $recorded): bool => str_ends_with($recorded->url(), '/sendPhoto'))->first()[0];
        $caption = (string) $this->telegramRequestField($request, 'caption');
        $parsedCaption = html_entity_decode(strip_tags($caption), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $this->assertStringContainsString('&lt;Alice', $caption);
        $this->assertStringContainsString('buyer&lt;&amp;', $caption);
        $this->assertLessThanOrEqual(1024, mb_strlen($parsedCaption));
    }

    private function fakeBannerSequence(int $nextMessageId, mixed $editResponse = null): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => function (Request $_request) use (&$nextMessageId) {
                $nextMessageId++;

                return Http::response([
                    'ok' => true,
                    'result' => [
                        'message_id' => $nextMessageId,
                        'photo' => [['file_id' => 'banner-'.$nextMessageId]],
                    ],
                ]);
            },
            'https://api.telegram.org/*/editMessageCaption' => $editResponse ?? Http::response(['ok' => true]),
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);
    }
}
