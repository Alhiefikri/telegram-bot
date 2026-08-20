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

    public function test_it_does_not_infer_auth_token_entities_from_legacy_or_tutorial_text(): void
    {
        $text = "email : premium@example.test\npassword : Password-123\nMFA : ABCD EFGH\nAuth Token : tutorial text";

        $this->assertSame([
            ['type' => 'code', 'offset' => 8, 'length' => 20],
            ['type' => 'code', 'offset' => 40, 'length' => 12],
            ['type' => 'code', 'offset' => 59, 'length' => 9],
        ], (new CredentialDeliveryFormatter)->codeEntities($text));
    }

    public function test_it_marks_explicit_structured_auth_token_fields_as_copyable_code_entities(): void
    {
        $text = "email : premium@example.test\npassword : Password-123\nAuth Token : token-value";

        $this->assertSame([
            ['type' => 'code', 'offset' => 8, 'length' => 20],
            ['type' => 'code', 'offset' => 40, 'length' => 12],
            ['type' => 'code', 'offset' => 66, 'length' => 11],
        ], (new CredentialDeliveryFormatter)->codeEntitiesForFields($text, [
            ['label' => 'email', 'value' => 'premium@example.test'],
            ['label' => 'password', 'value' => 'Password-123'],
            ['label' => 'Auth Token', 'value' => 'token-value'],
        ]));
    }

    public function test_it_marks_explicit_unicode_fields_using_utf16_offsets(): void
    {
        $text = "🔐 Data akun #1\nemail : user@example.test\npassword : Päss😀word";

        $this->assertSame([
            ['type' => 'code', 'offset' => 24, 'length' => 17],
            ['type' => 'code', 'offset' => 53, 'length' => 10],
        ], (new CredentialDeliveryFormatter)->codeEntitiesForFields($text, [
            ['label' => 'email', 'value' => 'user@example.test'],
            ['label' => 'password', 'value' => 'Päss😀word'],
        ]));
    }
}
