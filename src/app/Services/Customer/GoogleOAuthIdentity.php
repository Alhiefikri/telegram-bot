<?php

namespace App\Services\Customer;

use Illuminate\Support\Str;
use UnexpectedValueException;

final readonly class GoogleOAuthIdentity
{
    public string $subject;

    public string $email;

    public function __construct(#[\SensitiveParameter] string $subject, #[\SensitiveParameter] string $email)
    {
        $email = Str::lower(trim($email));
        if (preg_match('/\A[\x21-\x7e]{1,255}\z/', $subject) !== 1
            || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new UnexpectedValueException('Invalid Google identity evidence.');
        }
        $this->subject = $subject;
        $this->email = $email;
    }
}
