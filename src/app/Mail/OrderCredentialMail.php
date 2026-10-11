<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

/**
 * Paid account data addressed to the checkout email.
 *
 * Deliberately not queueable: routing this mailable through the queue would
 * serialize credential plaintext into the queue payload and failed-job store.
 * The owning job already runs on the notifications queue and holds only the
 * durable event ID.
 */
class OrderCredentialMail extends Mailable
{
    /**
     * @param  list<array{product_name: string, accounts: list<array<string, mixed>>, delivery_note: ?string, usage_guide: null|array{title: string, url: string}}>  $groups
     * @param  list<array{title: string, url: string}>  $usageGuides
     */
    public function __construct(
        private readonly string $invoiceNumber,
        private readonly array $groups,
        private readonly array $usageGuides,
        private readonly string $invoiceUrl,
        private readonly string $orderAccessUrl,
        private readonly string $defaultInstruction,
    ) {}

    public function build(): static
    {
        return $this
            ->subject('Data akun pesanan '.$this->invoiceNumber.' | '.config('app.name'))
            ->view('emails.order-credential', $this->viewData())
            ->text('emails.order-credential-text', $this->viewData());
    }

    /** @return array<string, mixed> */
    private function viewData(): array
    {
        return [
            'invoiceNumber' => $this->invoiceNumber,
            'groups' => $this->groups,
            'usageGuides' => $this->usageGuides,
            'invoiceUrl' => $this->invoiceUrl,
            'orderAccessUrl' => $this->orderAccessUrl,
            'defaultInstruction' => $this->defaultInstruction,
        ];
    }
}
