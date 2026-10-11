<?php

namespace App\Enums;

enum CustomerAuthTokenPurpose: string
{
    case EmailVerification = 'email_verification';
    case EmailOtp = 'email_otp';
    case PasswordReset = 'password_reset';
    case TelegramLink = 'telegram_link';

    public function ttlSeconds(): int
    {
        return match ($this) {
            self::EmailVerification, self::PasswordReset => 1800,
            self::EmailOtp => 600,
            self::TelegramLink => 300,
        };
    }

    public function maximumAttempts(): int
    {
        return $this === self::EmailOtp ? 5 : 0;
    }
}
