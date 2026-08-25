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

        $lines = [
            "email : {$parts['email']}",
            "password : {$parts['password']}",
        ];

        if ($parts['mfa'] !== null) {
            $lines[] = "MFA : {$parts['mfa']}";
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<array{type: 'code', offset: int, length: int}>
     */
    public function codeEntities(string $text): array
    {
        preg_match_all('/(?:email|password|MFA) : ([^\r\n]+)/', $text, $matches, PREG_OFFSET_CAPTURE);

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
     * Accept the legacy email/password shape and its existing
     * email/password/MFA extension. Values after the first separator remain
     * copyable exactly as supplied; only the email envelope is normalized for
     * validation and display.
     *
     * @return array{email: string, password: string, mfa: ?string}|null
     */
    public function parts(string $credential): ?array
    {
        $parts = explode('|', $credential);
        $email = trim((string) ($parts[0] ?? ''));
        $password = (string) ($parts[1] ?? '');
        $mfa = count($parts) === 3 ? (string) ($parts[2] ?? '') : null;

        if (! in_array(count($parts), [2, 3], true)
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || trim($password) === ''
            || ($mfa !== null && trim($mfa) === '')
            || strpbrk($email.$password.($mfa ?? ''), "\r\n") !== false) {
            return null;
        }

        return ['email' => $email, 'password' => $password, 'mfa' => $mfa];
    }

    private function utf16Length(string $value): int
    {
        return intdiv(strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
    }
}
