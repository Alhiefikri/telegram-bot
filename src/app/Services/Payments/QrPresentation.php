<?php

namespace App\Services\Payments;

use App\Services\Telegram\TelegramQrCodeRenderer;

/**
 * Single seam for customer-facing payment QR images.
 *
 * Both provider-hosted images and locally rendered payloads pass through here,
 * so the decorative template applies once, no matter which rail produced the
 * QR. With no template configured the input bytes are returned untouched.
 */
final class QrPresentation
{
    public function __construct(
        private readonly QrTemplatePlacementStore $templates,
        private readonly QrTemplateCompositor $compositor,
        private readonly TelegramQrCodeRenderer $qrCodes,
    ) {}

    public function image(string $qrContents): string
    {
        $placement = $this->templates->current();

        return $placement === null
            ? $qrContents
            : $this->compositor->compose($qrContents, $placement);
    }

    public function payload(string $payload): string
    {
        $placement = $this->templates->current();
        if ($placement === null) {
            return $this->qrCodes->png($payload);
        }

        $scale = $this->scaleFor($placement, $payload);
        if ($scale < 1) {
            return $this->qrCodes->png($payload);
        }

        return $this->compositor->compose($this->qrCodes->png($payload, $scale), $placement);
    }

    /**
     * Render at scale 1 to learn the total module count (data plus quiet zone),
     * then enlarge the QR as far as the reserved square allows.
     */
    private function scaleFor(QrTemplatePlacement $placement, string $payload): int
    {
        $size = @getimagesizefromstring($this->qrCodes->png($payload, 1));
        if (! is_array($size) || (int) $size[0] < 1) {
            return 0;
        }

        return intdiv($placement->squareSide(), (int) $size[0]);
    }
}
