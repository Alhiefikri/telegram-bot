<?php

namespace Tests\Unit\Services\Telegram;

use Tests\TestCase;

class TelegramBinancePhotoAssetTest extends TestCase
{
    public function test_binance_checkout_photo_asset_stays_within_telegram_upload_limits(): void
    {
        $path = public_path('binance.jpeg');
        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        $this->assertIsString($contents);

        $image = getimagesizefromstring($contents);
        $this->assertIsArray($image);
        $this->assertSame(IMAGETYPE_JPEG, $image[2] ?? null);

        $width = (int) ($image[0] ?? 0);
        $height = (int) ($image[1] ?? 0);
        $this->assertGreaterThan(0, $width);
        $this->assertGreaterThan(0, $height);
        $this->assertLessThanOrEqual(10 * 1024 * 1024, strlen($contents));
        $this->assertLessThanOrEqual(10_000, $width + $height);
        $this->assertLessThanOrEqual(20, max($width, $height) / min($width, $height));
    }
}
