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
    }

    public function test_it_does_not_change_or_log_the_stored_credential(): void
    {
        $credential = 'premium@example.test|Password-123|MFA-SECRET';
        $formatted = (new CredentialDeliveryFormatter)->format($credential);

        $this->assertStringNotContainsString('|', $formatted);
        $this->assertStringContainsString('Password-123', $formatted);
        $this->assertStringContainsString('MFA-SECRET', $formatted);
    }
}
