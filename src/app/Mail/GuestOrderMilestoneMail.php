<?php

namespace App\Mail;

use App\Enums\GuestOrderNotificationEvent;
use Illuminate\Mail\Mailable;

class GuestOrderMilestoneMail extends Mailable
{
    /**
     * @param  list<array{name: string, quantity: int}>  $items
     */
    public function __construct(
        private readonly GuestOrderNotificationEvent $eventType,
        private readonly string $invoiceNumber,
        private readonly string $orderStatus,
        private readonly string $statusLabel,
        private readonly array $items,
        private readonly ?string $paymentDeadline,
        private readonly string $invoiceUrl,
        private readonly string $orderAccessUrl,
    ) {}

    public function build(): static
    {
        return $this
            ->subject($this->subjectLine())
            ->view('emails.guest-order-milestone', $this->viewData())
            ->text('emails.guest-order-milestone-text', $this->viewData());
    }

    /** @return array<string, mixed> */
    private function viewData(): array
    {
        return [
            'eventType' => $this->eventType->value,
            'headline' => $this->headline(),
            'intro' => $this->intro(),
            'invoiceNumber' => $this->invoiceNumber,
            'orderStatus' => $this->orderStatus,
            'statusLabel' => $this->statusLabel,
            'items' => $this->items,
            'paymentDeadline' => $this->paymentDeadline,
            'invoiceUrl' => $this->invoiceUrl,
            'orderAccessUrl' => $this->orderAccessUrl,
        ];
    }

    private function subjectLine(): string
    {
        return match ($this->eventType) {
            GuestOrderNotificationEvent::OrderCreated => 'Pesanan dibuat',
            GuestOrderNotificationEvent::PaymentConfirmed => 'Pembayaran dikonfirmasi',
            GuestOrderNotificationEvent::FulfillmentCompleted => 'Fulfillment selesai',
            GuestOrderNotificationEvent::OrderExpired => 'Pesanan kedaluwarsa',
            GuestOrderNotificationEvent::OrderCancelled => 'Pesanan dibatalkan',
        }.' '.$this->invoiceNumber.' | '.config('app.name');
    }

    private function headline(): string
    {
        return match ($this->eventType) {
            GuestOrderNotificationEvent::OrderCreated => 'Pesanan website berhasil dibuat',
            GuestOrderNotificationEvent::PaymentConfirmed => 'Pembayaran pesanan sudah dikonfirmasi',
            GuestOrderNotificationEvent::FulfillmentCompleted => 'Fulfillment pesanan sudah selesai',
            GuestOrderNotificationEvent::OrderExpired => 'Pesanan website sudah kedaluwarsa',
            GuestOrderNotificationEvent::OrderCancelled => 'Pesanan website sudah dibatalkan',
        };
    }

    private function intro(): string
    {
        return match ($this->eventType) {
            GuestOrderNotificationEvent::OrderCreated => 'Pesanan Anda tercatat. Gunakan invoice untuk melihat instruksi dan status terbaru.',
            GuestOrderNotificationEvent::PaymentConfirmed => 'Pembayaran Anda sudah diterima dan pesanan masuk ke tahap pemrosesan.',
            GuestOrderNotificationEvent::FulfillmentCompleted => 'Pesanan Anda ditandai selesai oleh sistem atau admin.',
            GuestOrderNotificationEvent::OrderExpired => 'Batas pembayaran pesanan telah lewat dan pesanan tidak lagi menunggu pembayaran.',
            GuestOrderNotificationEvent::OrderCancelled => 'Pesanan Anda tidak lagi aktif dan tidak akan diproses sebagai pesanan baru.',
        };
    }
}
