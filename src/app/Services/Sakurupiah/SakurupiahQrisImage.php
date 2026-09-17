<?php

namespace App\Services\Sakurupiah;

use App\Enums\SakurupiahEnvironment;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * Downloads the provider-hosted QR image for an image presentation. The URL is
 * re-checked against the environment host allowlist, redirects are refused,
 * and the payload must be a bounded PNG or JPEG before it reaches a customer
 * surface.
 */
class SakurupiahQrisImage
{
    private const MAX_BYTES = 2_000_000;

    private readonly SakurupiahEnvironment $environment;

    public function __construct(?SakurupiahEnvironment $environment = null)
    {
        $this->environment = $environment ?? SakurupiahEnvironment::runtime();
    }

    public function contents(SakurupiahPayableInvoice $payable): string
    {
        $url = $payable->qrisImageUrl();
        if ($url === null || ! $payable->isQrisImage()) {
            throw $this->unavailable();
        }

        return $this->download($url);
    }

    private function download(string $url): string
    {
        if (! SakurupiahPayableInvoice::isAllowedProviderUrl($url, $this->environment)) {
            throw $this->unavailable();
        }

        $sink = fopen('php://temp/maxmemory:'.(self::MAX_BYTES + 1), 'w+b');
        if ($sink === false) {
            throw $this->unavailable();
        }

        try {
            $response = Http::timeout(4)
                ->connectTimeout(2)
                ->sink($sink)
                ->withOptions([
                    'allow_redirects' => false,
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

            $contentLength = (string) $response->header('Content-Length');
            if ($contentLength !== '' && ctype_digit($contentLength) && (int) $contentLength > self::MAX_BYTES) {
                throw $this->unavailable();
            }

            rewind($sink);
            $body = stream_get_contents($sink, self::MAX_BYTES + 1);
            if (! is_string($body)
                || strlen($body) > self::MAX_BYTES
                || ! $this->isImage($body)) {
                throw $this->unavailable();
            }

            return $body;
        } finally {
            fclose($sink);
        }
    }

    private function isImage(string $contents): bool
    {
        return str_starts_with($contents, "\x89PNG\r\n\x1A\n")
            || str_starts_with($contents, "\xFF\xD8\xFF");
    }

    private function unavailable(): InvalidArgumentException
    {
        return new InvalidArgumentException('Sakurupiah QR image is unavailable.');
    }
}
