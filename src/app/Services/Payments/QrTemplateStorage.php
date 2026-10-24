<?php

namespace App\Services\Payments;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stores the owner-uploaded QR template verbatim.
 *
 * The bytes are never resized, so the coordinates configured in the Admin
 * Dashboard keep pointing at the same pixels. Size and dimension limits are
 * enforced by the request before this service runs.
 */
final class QrTemplateStorage
{
    public const DIRECTORY = 'payment-qr-templates';

    private const EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    public function store(UploadedFile $image): string
    {
        $mime = (string) $image->getMimeType();
        $extension = self::EXTENSIONS[$mime] ?? null;
        if ($extension === null) {
            throw new RuntimeException('Unsupported QR template image type.');
        }

        $path = self::DIRECTORY.'/'.Str::uuid()->toString().'.'.$extension;
        $disk = Storage::disk('public');
        $disk->makeDirectory(self::DIRECTORY);

        $contents = file_get_contents($image->getRealPath());
        if (! is_string($contents) || $contents === '' || ! $disk->put($path, $contents)) {
            throw new RuntimeException('Failed to store the QR template image.');
        }

        return $path;
    }

    public function delete(?string $path): void
    {
        if (! is_string($path)
            || $path === ''
            || str_contains($path, '..')
            || ! str_starts_with($path, self::DIRECTORY.'/')) {
            return;
        }

        Storage::disk('public')->delete($path);
    }
}
