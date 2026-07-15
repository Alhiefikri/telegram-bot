<?php

namespace App\Services;

class CredentialDeliveryFormatter
{
    public function format(string $credential): string
    {
        $parts = $this->parts($credential);

        if ($parts === null) {
            return $credential;
        }

        return "email : {$parts['email']}\npassword : {$parts['password']}\nMFA : {$parts['mfa']}";
    }

    /**
     * @return array{email: string, password: string, mfa: string}|null
     */
    public function parts(string $credential): ?array
    {
        $parts = explode('|', $credential);
        $email = trim((string) ($parts[0] ?? ''));
        $password = (string) ($parts[1] ?? '');
        $mfa = (string) ($parts[2] ?? '');

        if (count($parts) !== 3 || filter_var($email, FILTER_VALIDATE_EMAIL) === false || trim($password) === '' || trim($mfa) === '') {
            return null;
        }

        return ['email' => $email, 'password' => $password, 'mfa' => $mfa];
    }
}
