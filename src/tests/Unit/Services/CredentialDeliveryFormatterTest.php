<?php

namespace Tests\Unit\Services;

use App\Services\CredentialDeliveryFormatter;
use PHPUnit\Framework\TestCase;

class CredentialDeliveryFormatterTest extends TestCase
{
    public function test_it_formats_an_email_password_and_mfa_triplet(): void
    {
        $credential = 'premium@example.test|Password-123|ABCD EFGH';

        $this->assertSame("email : premium@example.test\npassword : Password-123\nMFA : ABCD EFGH", (new CredentialDeliveryFormatter)->format($credential));
    }

    public function test_it_leaves_non_matching_credentials_unchanged(): void
    {
        $formatter = new CredentialDeliveryFormatter;

        $this->assertSame('premium@example.test|Password-123', $formatter->format('premium@example.test|Password-123'));
        $this->assertSame('username|Password-123|MFA|extra', $formatter->format('username|Password-123|MFA|extra'));
        $this->assertSame('not-an-email|Password-123|MFA', $formatter->format('not-an-email|Password-123|MFA'));
        $this->assertSame('premium@example.test||MFA', $formatter->format('premium@example.test||MFA'));
        $this->assertSame("premium@example.test|Password-123\nsecond-line|MFA", $formatter->format("premium@example.test|Password-123\nsecond-line|MFA"));
    }

    public function test_it_does_not_change_or_log_the_stored_credential(): void
    {
        $credential = 'premium@example.test|Password-123|MFA-SECRET';
        $formatted = (new CredentialDeliveryFormatter)->format($credential);

        $this->assertStringNotContainsString('|', $formatted);
        $this->assertStringContainsString('Password-123', $formatted);
        $this->assertStringContainsString('MFA-SECRET', $formatted);
    }

    public function test_it_exposes_copyable_credential_parts(): void
    {
        $this->assertSame([
            'email' => 'premium@example.test',
            'password' => ' Password-123 ',
            'mfa' => ' MFA-SECRET ',
        ], (new CredentialDeliveryFormatter)->parts(' premium@example.test | Password-123 | MFA-SECRET '));
    }

    public function test_it_preserves_password_and_mfa_whitespace(): void
    {
        $parts = (new CredentialDeliveryFormatter)->parts('premium@example.test| Password-123 | MFA-SECRET ');

        $this->assertSame(' Password-123 ', $parts['password']);
        $this->assertSame(' MFA-SECRET ', $parts['mfa']);
    }

    public function test_it_marks_credential_values_as_utf16_code_entities(): void
    {
        $text = "1. email : premium@example.test\npassword : Password-123\nMFA : ABCD EFGH";

        $this->assertSame([
            ['type' => 'code', 'offset' => 11, 'length' => 20],
            ['type' => 'code', 'offset' => 43, 'length' => 12],
            ['type' => 'code', 'offset' => 62, 'length' => 9],
        ], (new CredentialDeliveryFormatter)->codeEntities($text));
    }

    public function test_it_marks_structured_auth_tokens_as_copyable_code_entities(): void
    {
        $text = "email : premium@example.test\npassword : Password-123\nAuth Token : token-value";

        $this->assertSame([
            ['type' => 'code', 'offset' => 8, 'length' => 20],
            ['type' => 'code', 'offset' => 40, 'length' => 12],
            ['type' => 'code', 'offset' => 66, 'length' => 11],
        ], (new CredentialDeliveryFormatter)->codeEntities($text));
    }
}
