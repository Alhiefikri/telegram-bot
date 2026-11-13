<?php

namespace Tests\Unit\Services;

use App\Models\ProductStockItem;
use App\Services\Credentials\UniqueCredentialEmailPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class UniqueCredentialEmailPolicyTest extends TestCase
{
    public static function legacyEmails(): array
    {
        return [
            'pipe case and whitespace' => ['  Account@Example.test |password', 'account@example.test'],
            'pipe MFA' => ['Account@Example.test|password|mfa-token', 'account@example.test'],
            'dash MFA' => ['Account@Example.test----password----mfa-token', 'account@example.test'],
            'plus alias remains distinct' => ['account+family@example.test|password', 'account+family@example.test'],
            'dots remain distinct' => ['first.last@example.test|password', 'first.last@example.test'],
            'subdomain remains distinct' => ['account@mail.example.test|password', 'account@mail.example.test'],
            'missing email' => ['|password', null],
            'invalid email' => ['account|password', null],
            'missing password' => ['account@example.test| ', null],
            'empty MFA' => ['account@example.test|password|', null],
            'ambiguous extra field' => ['account@example.test|password|mfa|extra', null],
            'multiline' => ["account@example.test|password\nsecond@example.test|other", null],
            'display name not email' => ['Buyer <account@example.test>|password', null],
            'dash without MFA' => ['account@example.test----password', null],
            'arbitrary legacy text' => ['contact admin for access', null],
        ];
    }

    #[DataProvider('legacyEmails')]
    public function test_extracts_only_an_unambiguous_email_without_merging_distinct_accounts(string $secret, ?string $expected): void
    {
        $stock = new ProductStockItem(['secret' => $secret]);

        $this->assertSame($expected, app(UniqueCredentialEmailPolicy::class)->email($stock));
        $this->assertSame($secret, $stock->secret);
    }

    public static function structuredPayloads(): array
    {
        $valid = ['version' => 1, 'email' => ' Account@Example.test ', 'password' => 'secret', 'access_method' => 'none'];

        return [
            'normalized structured account' => [$valid, 'account@example.test'],
            'unsupported version' => [array_replace($valid, ['version' => 2]), null],
            'nonstring email' => [array_replace($valid, ['email' => ['account@example.test']]), null],
            'nonstring password' => [array_replace($valid, ['password' => 123]), null],
            'invalid access method' => [array_replace($valid, ['access_method' => 'unknown']), null],
            'invalid OTP URL' => [array_replace($valid, ['access_method' => 'otp_mail_link', 'access_value' => 'javascript:alert(1)']), null],
            'missing auth token' => [array_replace($valid, ['access_method' => 'auth_token']), null],
        ];
    }

    #[DataProvider('structuredPayloads')]
    public function test_unreadable_structured_accounts_fail_closed(array $payload, ?string $expected): void
    {
        $stock = new ProductStockItem(['credential_format' => 'structured_account', 'credential_payload' => $payload]);

        $this->assertSame($expected, app(UniqueCredentialEmailPolicy::class)->email($stock));
    }

    public static function corruptAttributes(): array
    {
        return [
            'unknown credential format' => [['credential_format' => 'unsupported', 'secret' => 'unreadable']],
            'corrupt encrypted legacy' => [['credential_format' => 'legacy', 'secret' => 'unreadable']],
            'corrupt encrypted payload' => [['credential_format' => 'structured_account', 'credential_payload' => 'unreadable']],
        ];
    }

    #[DataProvider('corruptAttributes')]
    public function test_corrupt_storage_never_becomes_an_eligible_email(array $attributes): void
    {
        $stock = new ProductStockItem;
        $stock->setRawAttributes($attributes);

        $this->assertNull(app(UniqueCredentialEmailPolicy::class)->email($stock));
    }
}
