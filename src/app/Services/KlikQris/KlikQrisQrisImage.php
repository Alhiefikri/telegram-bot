<?php

namespace App\Services\KlikQris;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

class KlikQrisQrisImage
{
    private const MAX_BYTES = 2_000_000;

    public function contents(KlikQrisTransaction $transaction): string
    {
        if ($transaction->qrisUrl() !== null) {
            try {
                return $this->download($transaction->qrisUrl());
            } catch (InvalidArgumentException) {
                // Fall back to the create-response image held in memory only.
            }
        }

        return $this->decodeBase64($transaction->qrisImageBase64());
    }

    private function download(string $url): string
    {
        if (! KlikQrisTransaction::isAllowedSandboxQrisUrl($url)) {
            throw $this->unavailable();
        }

        try {
            $response = Http::timeout(4)
                ->connectTimeout(2)
                ->withOptions(['allow_redirects' => false])
                ->get($url);
        } catch (Throwable) {
            throw $this->unavailable();
        }

        if ($response->status() !== 200) {
            throw $this->unavailable();
        }

        $contentType = strtolower((string) $response->header('Content-Type'));
        $body = $response->body();
        if (strlen($body) > self::MAX_BYTES
            || ! str_contains($contentType, 'image/png')
            || ! $this->isPng($body)) {
            throw $this->unavailable();
        }

        return $body;
    }

    private function decodeBase64(?string $value): string
    {
        if (! is_string($value) || ! preg_match('/^data:image\/png;base64,([A-Za-z0-9+\/]+=*)$/', $value, $matches)) {
            throw $this->unavailable();
        }

        $decoded = base64_decode($matches[1], true);
        if (! is_string($decoded) || strlen($decoded) > self::MAX_BYTES || ! $this->isPng($decoded)) {
            throw $this->unavailable();
        }

        return $decoded;
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
