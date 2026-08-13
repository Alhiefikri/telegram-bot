<?php

namespace App\Services\KlikQris;

use App\Enums\KlikQrisEnvironment;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

class KlikQrisQrisImage
{
    private const MAX_BYTES = 2_000_000;

    public function contents(KlikQrisPayableNativeQris $payable, bool $preferFallback = false): string
    {
        $fallback = $payable->fallbackImageContents();
        if ($preferFallback && $fallback !== null) {
            return $fallback;
        }

        try {
            return $this->download($payable->qrisUrl(), KlikQrisEnvironment::runtime());
        } catch (InvalidArgumentException $exception) {
            if ($fallback !== null) {
                return $fallback;
            }

            throw $exception;
        }
    }

    private function download(string $url, KlikQrisEnvironment $environment): string
    {
        if (! KlikQrisTransaction::isAllowedQrisUrl($url, environment: $environment)) {
            throw $this->unavailable();
        }

        $sink = fopen('php://temp/maxmemory:'.(self::MAX_BYTES + 1), 'w+b');
        if ($sink === false) {
            throw $this->unavailable();
        }

        try {
            $response = Http::timeout(4)
                ->connectTimeout(2)
                ->withOptions([
                    'allow_redirects' => false,
                    'stream' => true,
                    'sink' => $sink,
                    'on_headers' => function ($response): void {
                        $contentLength = $response->getHeaderLine('Content-Length');
                        if ($contentLength !== '' && ctype_digit($contentLength) && (int) $contentLength > self::MAX_BYTES) {
                            throw $this->unavailable();
                        }
                    },
                    'progress' => function (int $downloadTotal, int $downloadedBytes): void {
                        if ($downloadTotal > self::MAX_BYTES || $downloadedBytes > self::MAX_BYTES) {
                            throw $this->unavailable();
                        }
                    },
                ])
                ->get($url);
        } catch (Throwable) {
            fclose($sink);

            throw $this->unavailable();
        }

        try {
            if ($response->status() !== 200) {
                throw $this->unavailable();
            }

            $contentType = strtolower((string) $response->header('Content-Type'));
            $contentLength = (string) $response->header('Content-Length');
            if (($contentLength !== '' && ctype_digit($contentLength) && (int) $contentLength > self::MAX_BYTES)
                || ! str_contains($contentType, 'image/png')) {
                throw $this->unavailable();
            }

            rewind($sink);
            $body = stream_get_contents($sink, self::MAX_BYTES + 1);
            if (! is_string($body)
                || strlen($body) > self::MAX_BYTES
                || ! $this->isPng($body)) {
                throw $this->unavailable();
            }

            return $body;
        } finally {
            fclose($sink);
        }
    }

    private function isPng(string $contents): bool
    {
        return str_starts_with($contents, "\x89PNG\r\n\x1A\n");
    }

    private function unavailable(): InvalidArgumentException
    {
        return new InvalidArgumentException('KlikQRIS QR image is unavailable.');
    }
}
