<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerLoginLinkNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Link login '.config('app.name'))
            ->greeting('Login ke '.config('app.name'))
            ->line('Gunakan tombol di bawah untuk masuk ke akun pelanggan Anda. Link berlaku selama 15 menit dan hanya dapat digunakan sekali.')
            ->action('Masuk ke akun', $this->url)
            ->line('Jika Anda tidak meminta link ini, abaikan email ini.');
    }
}
