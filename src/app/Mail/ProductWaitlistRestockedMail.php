<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class ProductWaitlistRestockedMail extends Mailable
{
    public function __construct(
        private readonly string $productName,
        private readonly int $availableStock,
        private readonly string $productUrl,
        private readonly string $unsubscribeUrl,
    ) {}

    public function build(): static
    {
        return $this
            ->subject('Produk tersedia kembali | '.config('app.name'))
            ->view('emails.product-waitlist-restocked', $this->viewData())
            ->text('emails.product-waitlist-restocked-text', $this->viewData());
    }

    /** @return array<string, int|string> */
    private function viewData(): array
    {
        return [
            'productName' => $this->productName,
            'availableStock' => $this->availableStock,
            'productUrl' => $this->productUrl,
            'unsubscribeUrl' => $this->unsubscribeUrl,
        ];
    }
}
