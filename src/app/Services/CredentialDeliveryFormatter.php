<?php

namespace App\Services;

class CredentialDeliveryFormatter
{
    public function format(string $credential): string
    {
        $parts = explode('|', $credential);

        if (count($parts) !== 3 || filter_var(trim($parts[0]), FILTER_VALIDATE_EMAIL) === false || trim($parts[1]) === '' || trim($parts[2]) === '') {
            return $credential;
        }

        return "email : {$parts[0]}\npassword : {$parts[1]}\nMFA : {$parts[2]}";
    }
}
