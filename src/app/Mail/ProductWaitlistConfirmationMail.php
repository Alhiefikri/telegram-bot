<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class ProductWaitlistConfirmationMail extends Mailable
{
    public function __construct(
        private readonly string $productName,
        private readonly string $confirmationUrl,
    ) {}

    public function build(): static
    {
        return $this
            ->subject('Konfirmasi notifikasi stok | '.config('app.name'))
            ->view('emails.product-waitlist-confirmation', $this->viewData())
            ->text('emails.product-waitlist-confirmation-text', $this->viewData());
    }

    /** @return array<string, string> */
    private function viewData(): array
    {
        return [
            'productName' => $this->productName,
            'confirmationUrl' => $this->confirmationUrl,
        ];
    }
}
