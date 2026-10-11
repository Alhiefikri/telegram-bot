<?php

namespace App\Notifications;

use App\Enums\CustomerAuthTokenPurpose;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use LogicException;

final class CustomerAuthMailNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly CustomerAuthTokenPurpose $purpose,
        #[\SensitiveParameter] private readonly string $token,
    ) {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->purpose === CustomerAuthTokenPurpose::EmailOtp) {
            return (new MailMessage)
                ->subject('Kode login '.config('app.name'))
                ->greeting('Login ke '.config('app.name'))
                ->line('Masukkan kode berikut pada halaman akun di browser tempat Anda meminta kode:')
                ->line($this->token)
                ->line('Kode ini berlaku selama 10 menit dan hanya dapat digunakan sekali.')
                ->line('Jika Anda tidak meminta kode ini, abaikan email ini.');
        }

        [$route, $subject, $action, $description] = match ($this->purpose) {
            CustomerAuthTokenPurpose::EmailVerification => [
                'customer.verification.show', 'Verifikasi email', 'Verifikasi email saya',
                'Konfirmasikan alamat email Anda untuk menyelesaikan pendaftaran akun pelanggan.',
            ],
            CustomerAuthTokenPurpose::PasswordReset => [
                'customer.password-reset.show', 'Atur ulang password', 'Atur ulang password saya',
                'Gunakan tombol berikut untuk memilih password baru akun pelanggan Anda.',
            ],
            default => throw new LogicException('Unsupported customer authentication mail purpose.'),
        };

        return (new MailMessage)
            ->subject($subject.' '.config('app.name'))
            ->greeting($subject)
            ->line($description)
            ->action($action, route($route).'#token='.$this->token)
            ->line('Link ini berlaku selama 30 menit dan hanya dapat digunakan sekali.')
            ->line('Jika Anda tidak meminta email ini, abaikan pesan ini.');
    }
}
