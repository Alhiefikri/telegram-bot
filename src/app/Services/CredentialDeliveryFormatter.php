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
     * @return list<array{type: 'code', offset: int, length: int}>
     */
    public function codeEntities(string $text): array
    {
        preg_match_all('/(?:email|password|MFA|Auth Token) : ([^\r\n]+)/', $text, $matches, PREG_OFFSET_CAPTURE);

        return array_map(function (array $match) use ($text): array {
            [$value, $offset] = $match;
            $prefixLength = strlen($match[0]) - strlen($value);
            $valueOffset = $offset + $prefixLength;

            return [
                'type' => 'code',
                'offset' => $this->utf16Length(substr($text, 0, $valueOffset)),
                'length' => $this->utf16Length($value),
            ];
        }, $matches[1]);
    }

    /**
     * Build entities from the fields the renderer actually appended. This
     * avoids scanning tutorial/copy text and supports values containing
     * Unicode or line breaks.
     *
     * @param  list<array{label: string, value: string}>  $fields
     * @return list<array{type: 'code', offset: int, length: int}>
     */
    public function codeEntitiesForFields(string $text, array $fields): array
    {
        $searchOffset = 0;
        $entities = [];

        foreach ($fields as $field) {
            $label = $field['label'] ?? null;
            $value = $field['value'] ?? null;
            if (! is_string($label) || ! is_string($value) || $value === '') {
                continue;
            }

            $prefix = $label.' : ';
            $prefixOffset = strpos($text, $prefix, $searchOffset);
            if ($prefixOffset === false) {
                continue;
            }

            $valueOffset = $prefixOffset + strlen($prefix);
            if (substr($text, $valueOffset, strlen($value)) !== $value) {
                continue;
            }

            $entities[] = [
                'type' => 'code',
                'offset' => $this->utf16Length(substr($text, 0, $valueOffset)),
                'length' => $this->utf16Length($value),
            ];
            $searchOffset = $valueOffset + strlen($value);
        }

        return $entities;
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

        if (count($parts) !== 3
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || trim($password) === ''
            || trim($mfa) === ''
            || strpbrk($email.$password.$mfa, "\r\n") !== false) {
            return null;
        }

        return ['email' => $email, 'password' => $password, 'mfa' => $mfa];
    }

    private function utf16Length(string $value): int
    {
        return intdiv(strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
    }
}
