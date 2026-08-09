<?php

namespace Tests\Unit\Services\KlikQris;

use App\Services\KlikQris\KlikQrisPayableNativeQris;
use App\Services\KlikQris\KlikQrisQrisImage;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Mockery;
use RuntimeException;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\TestCase;

class KlikQrisQrisImageTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_BYTES = "\x89PNG\r\n\x1A\n".'downloaded-qris-png';

    public function test_downloads_allowlisted_https_klikqris_png(): void
    {
        $this->fakeStreamedDownload(self::PNG_BYTES);

        $this->assertSame(self::PNG_BYTES, app(KlikQrisQrisImage::class)->contents($this->payable()));
    }

    public function test_download_configures_bounded_streaming_sink_headers_progress_and_redirect_controls(): void
    {
        $this->fakeStreamedDownload(self::PNG_BYTES, assertOptions: true);

        $this->assertSame(self::PNG_BYTES, app(KlikQrisQrisImage::class)->contents($this->payable()));
    }

    public function test_rejects_oversized_content_length_before_accepting_remote_body(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://klikqris.com/storage/sandbox/qris_sandbox_transaction.png' => Http::response('', 200, [
                'Content-Type' => 'image/png',
                'Content-Length' => '2000001',
            ]),
        ]);

        $this->assertUnavailable(fn () => app(KlikQrisQrisImage::class)->contents($this->payable()));
    }

    public function test_rejects_redirects_non_images_and_oversized_bodies_without_leaking_response(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://klikqris.com/storage/sandbox/qris_redirect.png' => Http::response('', 302, ['Location' => 'https://evil.test/qris.png']),
            'https://klikqris.com/storage/sandbox/qris_html.png' => Http::response('<html>secret-api-key</html>', 200, ['Content-Type' => 'text/html']),
            'https://klikqris.com/storage/sandbox/qris_big.png' => Http::response(str_repeat('A', 2_100_000), 200, ['Content-Type' => 'image/png']),
        ]);

        foreach ([
            'https://klikqris.com/storage/sandbox/qris_redirect.png',
            'https://klikqris.com/storage/sandbox/qris_html.png',
            'https://klikqris.com/storage/sandbox/qris_big.png',
        ] as $url) {
            try {
                app(KlikQrisQrisImage::class)->contents($this->payable($url));
                $this->fail('Unsafe KlikQRIS QR image should be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('KlikQRIS QR image is unavailable.', $exception->getMessage());
                $this->assertStringNotContainsString('secret-api-key', $exception->getMessage());
                $this->assertStringNotContainsString('evil.test', $exception->getMessage());
            }
        }
    }

    public function test_payable_rejects_untrusted_non_https_or_missing_qris_media(): void
    {
        foreach ([
            'untrusted host' => ['https://evil.test/storage/sandbox/qris_bad.png'],
            'non https' => ['http://klikqris.com/storage/sandbox/qris_bad.png'],
            'missing qris_url' => [null],
        ] as $label => $parameters) {
            try {
                $this->payable(...$parameters);
                $this->fail('Untrusted KlikQRIS QR media must never become payable ('.$label.').');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function payable(?string $qrisUrl = 'https://klikqris.com/storage/sandbox/qris_sandbox_transaction.png'): KlikQrisPayableNativeQris
    {
        $expiresAt = now()->addMinutes(15);
        $payment = KlikQrisSandboxScenarios::klikQrisPayment([
            'merchant_ref' => 'INV-KLIK-IMAGE-'.bin2hex(random_bytes(4)),
            'amount' => 1000,
            'provider_total_payment' => 1016,
            'provider_fee' => 16,
            'provider_payment_method' => 'qris',
            'provider_expires_at' => $expiresAt,
            'provider_create_state' => 'created',
        ]);
        $payment->update([
            'provider_payload' => KlikQrisSandboxScenarios::createPayload($payment, [
                'qris_url' => $qrisUrl,
                'expired_at' => $expiresAt->toAtomString(),
            ]),
        ]);

        return KlikQrisPayableNativeQris::fromPayment($payment->fresh());
    }

    private function assertUnavailable(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Unsafe KlikQRIS QR image should be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('KlikQRIS QR image is unavailable.', $exception->getMessage());
        }
    }

    private function fakeStreamedDownload(string $contents, bool $assertOptions = false): void
    {
        $pending = Mockery::mock(PendingRequest::class);
        $sink = null;
        Http::shouldReceive('timeout')->once()->with(4)->andReturn($pending);
        $pending->shouldReceive('connectTimeout')->once()->with(2)->andReturnSelf();
        $pending->shouldReceive('withOptions')
            ->once()
            ->with(Mockery::on(function (array $options) use (&$sink, $assertOptions): bool {
                $sink = $options['sink'] ?? null;

                if ($assertOptions) {
                    $this->assertFalse($options['allow_redirects'] ?? true);
                    $this->assertTrue($options['stream'] ?? false);
                    $this->assertIsResource($sink);
                    $this->assertIsCallable($options['on_headers'] ?? null);
                    $this->assertIsCallable($options['progress'] ?? null);
                }

                return true;
            }))
            ->andReturnSelf();
        $pending->shouldReceive('get')
            ->once()
            ->with('https://klikqris.com/storage/sandbox/qris_sandbox_transaction.png')
            ->andReturnUsing(function () use (&$sink, $contents): Response {
                fwrite($sink, $contents);

                return new Response(new Psr7Response(200, ['Content-Type' => 'image/png'], ''));
            });
    }
}
