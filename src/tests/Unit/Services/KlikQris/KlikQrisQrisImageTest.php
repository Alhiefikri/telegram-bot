<?php

namespace Tests\Unit\Services\KlikQris;

use App\Services\KlikQris\KlikQrisQrisImage;
use App\Services\KlikQris\KlikQrisTransaction;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

class KlikQrisQrisImageTest extends TestCase
{
    private const PNG_BYTES = "\x89PNG\r\n\x1A\n".'downloaded-qris-png';

    private const JPEG_BYTES = "\xFF\xD8\xFF\xE0".'downloaded-qris-jpeg';

    public function test_downloads_allowlisted_https_klikqris_png_before_using_base64_fallback(): void
    {
        $this->fakeStreamedDownload(self::PNG_BYTES);
        $transaction = $this->transaction([
            'qris_url' => 'https://klikqris.com/storage/sandbox/qris_sandbox_transaction.png',
            'qris_image' => 'data:image/png;base64,'.base64_encode("\x89PNG\r\n\x1A\n".'fallback-png'),
        ]);

        $this->assertSame(self::PNG_BYTES, app(KlikQrisQrisImage::class)->contents($transaction));
    }

    public function test_uses_strict_png_data_uri_fallback_when_download_fails(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://klikqris.com/storage/sandbox/qris_sandbox_transaction.png' => Http::response('server down', 503, ['Content-Type' => 'text/plain']),
        ]);
        $fallback = "\x89PNG\r\n\x1A\n".'fallback-png';
        $transaction = $this->transaction([
            'qris_url' => 'https://klikqris.com/storage/sandbox/qris_sandbox_transaction.png',
            'qris_image' => 'data:image/png;base64,'.base64_encode($fallback),
        ]);

        $this->assertSame($fallback, app(KlikQrisQrisImage::class)->contents($transaction));
    }

    public function test_download_configures_bounded_streaming_sink_headers_progress_and_redirect_controls(): void
    {
        $this->fakeStreamedDownload(self::PNG_BYTES, assertOptions: true);

        $this->assertSame(self::PNG_BYTES, app(KlikQrisQrisImage::class)->contents($this->transaction([])));
    }

    public function test_rejects_oversized_content_length_before_accepting_remote_body_and_uses_base64_fallback(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://klikqris.com/storage/sandbox/qris_sandbox_transaction.png' => Http::response('', 200, [
                'Content-Type' => 'image/png',
                'Content-Length' => '2000001',
            ]),
        ]);
        $fallback = "\x89PNG\r\n\x1A\n".'fallback-after-content-length';

        $this->assertSame($fallback, app(KlikQrisQrisImage::class)->contents($this->transaction([
            'qris_image' => 'data:image/png;base64,'.base64_encode($fallback),
        ])));
    }

    public function test_rejects_untrusted_urls_redirects_non_images_and_oversized_bodies_without_leaking_response(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://klikqris.com/storage/sandbox/qris_redirect.png' => Http::response('', 302, ['Location' => 'https://evil.test/qris.png']),
            'https://klikqris.com/storage/sandbox/qris_html.png' => Http::response('<html>secret-api-key</html>', 200, ['Content-Type' => 'text/html']),
            'https://klikqris.com/storage/sandbox/qris_big.png' => Http::response(str_repeat('A', 2_100_000), 200, ['Content-Type' => 'image/png']),
        ]);

        foreach ([
            ['qris_url' => 'https://evil.test/storage/sandbox/qris_bad.png'],
            ['qris_url' => 'http://klikqris.com/storage/sandbox/qris_bad.png'],
            ['qris_url' => 'https://klikqris.com/storage/sandbox/qris_redirect.png'],
            ['qris_url' => 'https://klikqris.com/storage/sandbox/qris_html.png'],
            ['qris_url' => 'https://klikqris.com/storage/sandbox/qris_big.png'],
        ] as $overrides) {
            try {
                app(KlikQrisQrisImage::class)->contents($this->transaction($overrides));
                $this->fail('Unsafe KlikQRIS QR image should be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('KlikQRIS QR image is unavailable.', $exception->getMessage());
                $this->assertStringNotContainsString('secret-api-key', $exception->getMessage());
                $this->assertStringNotContainsString('evil.test', $exception->getMessage());
            }
        }
    }

    public function test_rejects_malformed_base64_oversize_base64_and_non_png_data_uri_fallbacks(): void
    {
        foreach ([
            ['qris_url' => null, 'qris_image' => 'not-a-data-uri'],
            ['qris_url' => null, 'qris_image' => 'data:image/png;base64,@@not-base64@@'],
            ['qris_url' => null, 'qris_image' => 'data:image/png;base64,'.base64_encode('<svg></svg>')],
            ['qris_url' => null, 'qris_image' => 'data:image/jpeg;base64,'.base64_encode(self::JPEG_BYTES)],
            ['qris_url' => null, 'qris_image' => 'data:image/png;base64,'.base64_encode("\x89PNG\r\n\x1A\n".str_repeat('A', 2_100_000))],
        ] as $overrides) {
            try {
                app(KlikQrisQrisImage::class)->contents($this->transaction($overrides));
                $this->fail('Malformed KlikQRIS QR image should be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('KlikQRIS QR image is unavailable.', $exception->getMessage());
            }
        }
    }

    /** @param array<string, mixed> $overrides */
    private function transaction(array $overrides): KlikQrisTransaction
    {
        return KlikQrisTransaction::fromCreatePayload(['data' => array_replace([
            'order_id' => 'INV-KLIK-IMAGE',
            'amount' => 1000,
            'total_amount' => 1016,
            'status' => 'PENDING',
            'qris_url' => 'https://klikqris.com/storage/sandbox/qris_sandbox_transaction.png',
            'expired_at' => '2026-07-28T10:24:00+00:00',
            'signature' => 'opaque-test-signature',
        ], $overrides)]);
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
