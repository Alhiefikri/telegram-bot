<?php

namespace App\Services\StoreBalance;

use App\Models\StoreBalanceTopUp;
use App\Services\Telegram\TelegramBotApiRequestFactory;
use finfo;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

final class StoreBalanceTopUpProofFetcher
{
    private const MAX_BYTES = 4_194_304;

    private const MAX_PIXELS = 12_000_000;

    public function __construct(private readonly TelegramBotApiRequestFactory $telegram) {}

    public function fetch(StoreBalanceTopUp $topUp): StoreBalanceTopUpProofFile
    {
        $fileId = $topUp->payment_proof_file_id;
        if (! is_string($fileId) || $fileId === '' || $topUp->payment_proof_submitted_at === null) {
            throw $this->unavailable();
        }

        try {
            $metadata = $this->telegram->request()
                ->acceptJson()
                ->withOptions(['allow_redirects' => false])
                ->post($this->telegram->url('getFile'), ['file_id' => $fileId]);
        } catch (Throwable) {
            throw $this->unavailable();
        }

        if ($metadata->status() !== 200 || $metadata->json('ok') !== true) {
            throw $this->unavailable();
        }

        $result = $metadata->json('result');
        $path = is_array($result) ? ($result['file_path'] ?? null) : null;
        $size = is_array($result) ? ($result['file_size'] ?? null) : null;
        if (! is_string($path) || $path === ''
            || ($size !== null && (! $this->isUnsignedInteger($size) || (int) $size > self::MAX_BYTES))) {
            throw $this->unavailable();
        }

        try {
            $url = $this->telegram->fileUrl($path);
        } catch (InvalidArgumentException) {
            throw $this->unavailable();
        }

        $sink = fopen('php://temp/maxmemory:'.(self::MAX_BYTES + 1), 'w+b');
        if ($sink === false) {
            throw $this->unavailable();
        }

        try {
            $response = $this->telegram->request()
                ->sink($sink)
                ->withOptions([
                    'allow_redirects' => false,
                    'on_headers' => function (ResponseInterface $response): void {
                        $contentLength = $response->getHeaderLine('Content-Length');
                        if ($contentLength !== '' && ctype_digit($contentLength) && (int) $contentLength > self::MAX_BYTES) {
                            throw $this->unavailable();
                        }
                    },
                    'progress' => function (int|float $downloadTotal, int|float $downloadedBytes): void {
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
            $bytes = stream_get_contents($sink, self::MAX_BYTES + 1);
            if (! is_string($bytes) || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
                throw $this->unavailable();
            }

            return $this->validatedImage($bytes, (string) $response->header('Content-Type'));
        } finally {
            fclose($sink);
        }
    }

    private function validatedImage(string $bytes, string $contentType): StoreBalanceTopUpProofFile
    {
        $declaredMime = strtolower(trim(explode(';', $contentType, 2)[0]));
        if (! in_array($declaredMime, ['image/jpeg', 'image/png'], true)) {
            throw $this->unavailable();
        }

        $image = @getimagesizefromstring($bytes);
        if (! is_array($image)
            || ! in_array($image[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)
            || $image[0] < 1 || $image[1] < 1 || $image[0] > 12_000 || $image[1] > 12_000
            || $image[0] * $image[1] > self::MAX_PIXELS) {
            throw $this->unavailable();
        }

        $mime = $image[2] === IMAGETYPE_JPEG ? 'image/jpeg' : 'image/png';
        if ($declaredMime !== $mime || (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== $mime) {
            throw $this->unavailable();
        }
        $decoded = @imagecreatefromstring($bytes);
        if ($decoded === false) {
            throw $this->unavailable();
        }
        unset($decoded);

        return new StoreBalanceTopUpProofFile($bytes, $mime);
    }

    private function isUnsignedInteger(mixed $value): bool
    {
        return (is_int($value) && $value >= 0)
            || (is_string($value) && $value !== '' && ctype_digit($value));
    }

    private function unavailable(): RuntimeException
    {
        return new RuntimeException('Payment proof is unavailable.');
    }
}
