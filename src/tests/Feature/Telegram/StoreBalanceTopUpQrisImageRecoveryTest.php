<?php

namespace Tests\Feature\Telegram;

use App\Jobs\ProcessTelegramUpdate;
use App\Models\Customer;
use App\Models\PaymentQrTemplateSetting;
use App\Models\StoreBalanceFundingIntent;
use App\Models\StoreBalanceTopUp;
use App\Models\TelegramNavigationRoot;
use App\Models\TelegramOutboundEffect;
use App\Models\TelegramUpdate;
use App\Services\Telegram\BalanceTopUpInstructionDeliveryPipeline;
use App\Services\Telegram\TelegramUpdateProcessor;
use chillerlan\QRCode\QRCode;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\Support\TelegramBotTestSupport;
use Throwable;

/**
 * A KlikQRIS top-up whose trusted qris_url cannot be rendered must fall back
 * to the provider image supplied by the authoritative status endpoint, exactly
 * like the order checkout rail. When no provider image can be recovered the
 * delivery must stay a retryable failure instead of silently ending.
 */
final class StoreBalanceTopUpQrisImageRecoveryTest extends TelegramBotTestSupport
{
    private const QRIS_URL = 'https://api.klikqris.example/storage/qris_api/qris_balance_top_up.png';

    private const PROVIDER_EXPIRES_AT = '2026-07-28T10:25:00Z';

    public function test_delivery_stays_retryable_when_status_recovery_has_no_provider_image(): void
    {
        $this->travelTo('2026-07-28 10:10:00 UTC');
        $statusCalls = 0;

        [$topUp, $effect] = $this->openConfirmedTopUp(46911, function (Request $request) use (&$statusCalls) {
            $statusCalls++;

            return Http::response(['data' => [
                'order_id' => basename($request->url()),
                'amount' => 5000,
                'total_amount' => 5163,
                'status' => 'PENDING',
                'qris_url' => self::QRIS_URL,
                'signature' => 'top-up-signature',
                'expired_at' => self::PROVIDER_EXPIRES_AT,
            ]]);
        });

        $thrown = $this->deliverTopUp($topUp, $effect);

        $this->assertInstanceOf(InvalidArgumentException::class, $thrown);
        $this->assertStringContainsString('QR image is unavailable', (string) $thrown?->getMessage());
        $this->assertSame(1, $statusCalls);
        $this->assertSame(0, $this->topUpQrPhotos());

        $effect->refresh();
        $this->assertSame('failed', $effect->state->value);
        $this->assertNotNull($effect->next_attempt_at);
        $this->assertTrue($effect->next_attempt_at->isFuture());
        $this->assertSame(1, StoreBalanceTopUp::query()->count());
    }

    public function test_delivery_stays_retryable_when_status_recovery_is_unavailable(): void
    {
        $this->travelTo('2026-07-28 10:10:00 UTC');
        $statusCalls = 0;

        [$topUp, $effect] = $this->openConfirmedTopUp(46921, function () use (&$statusCalls) {
            $statusCalls++;

            return Http::response(['error' => 'provider unavailable'], 503);
        });

        $thrown = $this->deliverTopUp($topUp, $effect);

        $this->assertInstanceOf(InvalidArgumentException::class, $thrown);
        $this->assertGreaterThanOrEqual(1, $statusCalls);
        $this->assertSame(0, $this->topUpQrPhotos());

        $effect->refresh();
        $this->assertSame('failed', $effect->state->value);
        $this->assertNotNull($effect->next_attempt_at);
        $this->assertTrue($effect->next_attempt_at->isFuture());
    }

    public function test_delivered_top_up_photo_is_composited_into_the_template(): void
    {
        $this->travelTo('2026-07-28 10:10:00 UTC');
        Storage::fake('public');
        Storage::disk('public')->put(
            'payment-qr-templates/template.webp',
            $this->qrFixture('template-square-box.webp'),
        );
        PaymentQrTemplateSetting::query()->updateOrCreate(
            ['singleton_key' => PaymentQrTemplateSetting::SINGLETON_KEY],
            [
                'enabled' => true,
                'image_path' => 'payment-qr-templates/template.webp',
                'qr_x' => 250,
                'qr_y' => 250,
                'qr_width' => 520,
                'qr_height' => 520,
                'version' => 1,
            ],
        );

        [$topUp, $effect] = $this->openConfirmedTopUp(
            46931,
            fn (Request $request) => Http::response(['data' => [
                'order_id' => basename($request->url()),
                'amount' => 5000,
                'total_amount' => 5163,
                'status' => 'PENDING',
                'qris_url' => self::QRIS_URL,
                'signature' => 'top-up-signature',
                'expired_at' => self::PROVIDER_EXPIRES_AT,
            ]]),
            $this->qrFixture('provider-qr-285.png'),
        );

        $thrown = $this->deliverTopUp($topUp, $effect);

        $this->assertNull($thrown, 'Compositing must not break top-up delivery.');
        $photos = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto')
            && str_contains((string) $this->telegramRequestField($request, 'caption'), 'Balance credit after payment confirmation'));
        $this->assertCount(1, $photos);

        $photo = $this->telegramRequestField($photos->first()[0], 'photo');
        $this->assertIsString($photo);
        $size = getimagesizefromstring($photo);
        $this->assertIsArray($size);
        $this->assertSame([1000, 1000], [(int) $size[0], (int) $size[1]]);
        $this->assertSame(
            $this->qrFixture('provider-payload.txt'),
            (new QRCode)->readFromBlob($photo)->data,
        );
    }

    private function qrFixture(string $name): string
    {
        $contents = file_get_contents(base_path('tests/Fixtures/qr/'.$name));
        $this->assertIsString($contents);

        return $contents;
    }

    private function deliverTopUp(StoreBalanceTopUp $topUp, TelegramOutboundEffect $effect): ?Throwable
    {
        try {
            app(BalanceTopUpInstructionDeliveryPipeline::class)
                ->deliver((string) $topUp->getKey(), (string) $effect->getKey());
        } catch (Throwable $exception) {
            return $exception;
        }

        return null;
    }

    private function topUpQrPhotos(): int
    {
        return Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto')
            && str_contains((string) $this->telegramRequestField($request, 'caption'), 'Balance credit after payment confirmation'))
            ->count();
    }

    /**
     * @return array{0: StoreBalanceTopUp, 1: TelegramOutboundEffect}
     */
    private function openConfirmedTopUp(int $chatId, callable $statusResponder, ?string $qrisImageContents = null): array
    {
        KlikQrisSandboxScenarios::configureReadyProduction();
        KlikQrisSandboxScenarios::selectKlikQrisProvider();

        $this->fakeTelegramHttp([
            'https://api.klikqris.example/qris/create' => fn (Request $request) => Http::response(['data' => [
                'order_id' => $request['order_id'],
                'amount' => 5000,
                'total_amount' => 5163,
                'fee' => 163,
                'status' => 'PENDING',
                'qris_url' => self::QRIS_URL,
                'signature' => 'top-up-signature',
                'expired_at' => self::PROVIDER_EXPIRES_AT,
            ]]),
            'https://api.klikqris.example/qris/status/*' => $statusResponder,
            self::QRIS_URL => Http::response($qrisImageContents ?? 'not-a-png-body', 200, ['Content-Type' => 'image/png']),
        ]);

        Customer::factory()->create(['telegram_id' => $chatId, 'locale' => 'en']);
        $this->postTelegramUpdate($this->messageUpdate($chatId, '/start', [], $chatId));
        $root = TelegramNavigationRoot::query()->sole();
        $this->postTelegramUpdate($this->callbackUpdate(
            $chatId + 1,
            'funding-open',
            'balance:add',
            [],
            $chatId,
            'private',
            $root->message_id,
        ));
        $this->postTelegramUpdate($this->messageUpdate($chatId + 2, '/deposit 5000', [], $chatId));
        $intent = StoreBalanceFundingIntent::query()->sole();
        $this->postTelegramUpdate($this->callbackUpdate(
            $chatId + 3,
            'top-up-confirm',
            'balance:confirm:'.$intent->nonce,
            [],
            $chatId,
            'private',
            $root->message_id,
        ));

        return [StoreBalanceTopUp::query()->sole(), TelegramOutboundEffect::query()->sole()];
    }

    private function postTelegramUpdate(array $payload): void
    {
        $this->postTelegramWebhook($payload)->assertOk();
        $update = TelegramUpdate::query()->where('update_id', $payload['update_id'])->sole();
        (new ProcessTelegramUpdate($update->id))->handle(app(TelegramUpdateProcessor::class));
    }
}
