<?php

namespace App\Enums;

enum CredentialAccessMethod: string
{
    case None = 'none';
    case OtpMailLink = 'otp_mail_link';
    case AuthToken = 'auth_token';
}
