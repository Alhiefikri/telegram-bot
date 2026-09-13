<?php

namespace App\Services\Admin;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class ProductImageOptimizer
{
    private const DIRECTORY = 'products';

    private const FAMILY_DIRECTORY = 'product-families';

    private const PLACEHOLDER_DIRECTORY = 'storefront/placeholders';

    private const TELEGRAM_DIRECTORY = 'telegram-products';

    private const TELEGRAM_FAMILY_DIRECTORY = 'telegram-product-families';

    private const WELCOME_BANNER_DIRECTORY = 'telegram/welcome-banners';

    private const MAX_DIMENSION = 1600;

    private const WEBP_QUALITY = 84;

    public function store(UploadedFile $image): string
    {
        return $this->storeInDirectory($image, self::DIRECTORY);
    }

    public function storeTelegram(UploadedFile $image): string
    {
        return $this->storeInDirectory($image, self::TELEGRAM_DIRECTORY);
    }

    public function storeTelegramFamily(UploadedFile $image): string
    {
        return $this->storeInDirectory($image, self::TELEGRAM_FAMILY_DIRECTORY);
    }

    public function storeFamily(UploadedFile $image): string
    {
        return $this->storeInDirectory($image, self::FAMILY_DIRECTORY);
    }

    public function storePlaceholder(UploadedFile $image): string
    {
        return $this->storeInDirectory($image, self::PLACEHOLDER_DIRECTORY);
    }

    public function storeWelcomeBanner(UploadedFile $image): string
    {
        return $this->storeInDirectory($image, self::WELCOME_BANNER_DIRECTORY);
    }

    private function storeInDirectory(UploadedFile $image, string $directory): string
    {
        $source = $this->createImageResource($image);
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $targetWidth = $sourceWidth;
        $targetHeight = $sourceHeight;

        $largestSide = max($sourceWidth, $sourceHeight);
        if ($largestSide > self::MAX_DIMENSION) {
            $scale = self::MAX_DIMENSION / $largestSide;
            $targetWidth = max(1, (int) round($sourceWidth * $scale));
            $targetHeight = max(1, (int) round($sourceHeight * $scale));
        }

        $target = $this->resizePreservingTransparency($source, $targetWidth, $targetHeight);
        imagedestroy($source);

        $path = $directory.'/'.Str::uuid()->toString().'.webp';
        $disk = Storage::disk('public');
        $disk->makeDirectory($directory);

        $absolutePath = $disk->path($path);
        if (! imagewebp($target, $absolutePath, self::WEBP_QUALITY)) {
            imagedestroy($target);
            throw new RuntimeException('Failed to encode product image as WebP.');
        }

        imagedestroy($target);

        return $path;
    }

    public function replace(?string $oldPath, UploadedFile $image): string
    {
        $newPath = $this->store($image);
        $this->deleteManagedImage($oldPath);

        return $newPath;
    }

    public function replaceFamily(?string $oldPath, UploadedFile $image): string
    {
        $newPath = $this->storeFamily($image);
        $this->deleteManagedImage($oldPath);

        return $newPath;
    }

    public function publicUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return '/storage/'.ltrim($path, '/');
    }

    public function telegramUrl(?string $path): ?string
    {
        if ($path === null
            || (! str_starts_with($path, self::TELEGRAM_DIRECTORY.'/')
                && ! str_starts_with($path, self::TELEGRAM_FAMILY_DIRECTORY.'/'))
            || str_contains($path, '..')
            || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $url = url($this->publicUrl($path));
        $parts = parse_url($url);
        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || ! is_string($parts['host'] ?? null)
            || $parts['host'] === '') {
            return null;
        }

        return $url;
    }

    /**
     * @return array{contents: string, filename: string}|null
     */
    public function telegramPhoto(?string $path): ?array
    {
        if ($path === null
            || (! str_starts_with($path, self::TELEGRAM_DIRECTORY.'/')
                && ! str_starts_with($path, self::TELEGRAM_FAMILY_DIRECTORY.'/')
                && ! str_starts_with($path, self::DIRECTORY.'/')
                && ! str_starts_with($path, self::FAMILY_DIRECTORY.'/'))
            || str_contains($path, '..')
            || ! Storage::disk('public')->exists($path)) {
            return null;
        }
        $contents = Storage::disk('public')->get($path);

        return is_string($contents) && $contents !== ''
            ? ['contents' => $contents, 'filename' => basename($path)]
            : null;
    }

    /**
     * @return array{contents: string, filename: string}|null
     */
    public function welcomeBannerPhoto(?string $path): ?array
    {
        if ($path === null
            || ! str_starts_with($path, self::WELCOME_BANNER_DIRECTORY.'/')
            || str_contains($path, '..')
            || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $contents = Storage::disk('public')->get($path);

        return is_string($contents) && $contents !== ''
            ? ['contents' => $contents, 'filename' => basename($path)]
            : null;
    }

    private function createImageResource(UploadedFile $image): \GdImage
    {
        $contents = file_get_contents($image->getRealPath());
        $source = $contents === false ? false : imagecreatefromstring($contents);

        if (! $source instanceof \GdImage) {
            throw new RuntimeException('Uploaded product image could not be decoded.');
        }

        if (! imageistruecolor($source)) {
            imagepalettetotruecolor($source);
        }

        imagesavealpha($source, true);

        return $source;
    }

    private function resizePreservingTransparency(\GdImage $source, int $targetWidth, int $targetHeight): \GdImage
    {
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($target, false);
        imagesavealpha($target, true);

        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
        imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);

        imagecopyresampled(
            $target,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            imagesx($source),
            imagesy($source),
        );

        return $target;
    }

    public function deleteManagedImage(?string $path): void
    {
        if (! $path
            || (! str_starts_with($path, self::DIRECTORY.'/')
                && ! str_starts_with($path, self::FAMILY_DIRECTORY.'/')
                && ! str_starts_with($path, self::PLACEHOLDER_DIRECTORY.'/')
                && ! str_starts_with($path, self::TELEGRAM_DIRECTORY.'/')
                && ! str_starts_with($path, self::TELEGRAM_FAMILY_DIRECTORY.'/')
                && ! str_starts_with($path, self::WELCOME_BANNER_DIRECTORY.'/'))) {
            return;
        }

        Storage::disk('public')->delete($path);
    }
}
