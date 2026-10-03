<?php

namespace App\Services\Warranty;

use InvalidArgumentException;

/** Original screenshot bytes; validating evidence never rewrites its content. */
final readonly class RefundProof
{
    public const MAX_BYTES = 4_194_304;

    private function __construct(public string $bytes, public string $mime, public string $filename) {}

    public static function fromBytes(string $bytes): self
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Invalid refund proof.');
        }
        $image = @getimagesizefromstring($bytes);
        if (! is_array($image) || ! in_array($image[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)
            || $image[0] < 1 || $image[1] < 1 || $image[0] > 12000 || $image[1] > 12000
            || $image[0] * $image[1] > 12_000_000) {
            throw new InvalidArgumentException('Invalid refund proof.');
        }
        $decoded = @imagecreatefromstring($bytes);
        if ($decoded === false) {
            throw new InvalidArgumentException('Invalid refund proof.');
        }
        unset($decoded);

        return new self($bytes, $image[2] === IMAGETYPE_JPEG ? 'image/jpeg' : 'image/png', $image[2] === IMAGETYPE_JPEG ? 'proof.jpg' : 'proof.png');
    }

    public function __debugInfo(): array
    {
        return ['mime' => $this->mime, 'size' => strlen($this->bytes)];
    }
}
