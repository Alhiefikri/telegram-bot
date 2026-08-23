<?php

namespace Tests\Feature;

use App\Enums\CredentialAccessMethod;
use App\Enums\CredentialFormat;
use App\Models\CredentialIdentity;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Services\Credentials\CredentialIdentityNormalizer;
use App\Services\Credentials\StructuredCredentialPayload;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class CredentialIdentityFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.credentials.identity_fingerprint_key', 'credential-test-fingerprint-key');
    }

    public function test_legacy_secret_survives_the_additive_schema_and_structured_payload_is_encrypted(): void
    {
        $product = Product::factory()->create();
        $legacy = ProductStockItem::create([
            'product_id' => $product->id,
            'secret' => 'legacy-account@example.test|legacy-password|legacy-mfa',
        ]);

        $structured = ProductStockItem::create([
            'product_id' => $product->id,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => StructuredCredentialPayload::fromValues(
                '  Structured.Account@Example.test ',
                'structured-password',
                CredentialAccessMethod::AuthToken,
                'exact-auth-token',
                'Use the provider guide.',
            )->toArray(),
        ]);

        $this->assertSame('legacy-account@example.test|legacy-password|legacy-mfa', $legacy->fresh()->secret);
        $this->assertSame(CredentialFormat::Legacy, $legacy->fresh()->credential_format);
        $this->assertNull($legacy->fresh()->credential_payload);
        $this->assertNull($structured->fresh()->secret);
        $this->assertSame(CredentialFormat::StructuredAccount, $structured->fresh()->credential_format);
        $this->assertSame('structured.account@example.test', $structured->fresh()->credential_payload['email']);

        $rawPayload = (string) DB::table('product_stock_items')->where('id', $structured->id)->value('credential_payload');
        $this->assertNotSame('', $rawPayload);
        $this->assertStringNotContainsString('structured.account@example.test', $rawPayload);
        $this->assertStringNotContainsString('structured-password', $rawPayload);
        $this->assertStringNotContainsString('exact-auth-token', $rawPayload);
    }

    public function test_structured_payload_requires_version_and_consistent_access_fields(): void
    {
        $cases = [
            ['version' => 2, 'email' => 'account@example.test', 'password' => 'password', 'access_method' => 'none'],
            ['version' => 1, 'email' => 'account@example.test', 'password' => '', 'access_method' => 'none'],
            ['version' => 1, 'email' => 'account@example.test', 'password' => 'password', 'access_method' => 'none', 'access_value' => 'unexpected'],
            ['version' => 1, 'email' => 'account@example.test', 'password' => 'password', 'access_method' => 'auth_token'],
            ['version' => 1, 'email' => 'account@example.test', 'password' => 'password', 'access_method' => 'otp_mail_link', 'access_value' => 'http://mail.example.test/otp'],
        ];

        foreach ($cases as $payload) {
            try {
                StructuredCredentialPayload::fromArray($payload);
                $this->fail('Invalid structured credential payload was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringNotContainsString('account@example.test', $exception->getMessage());
                $this->assertStringNotContainsString('password-not-an-identity', $exception->getMessage());
            }
        }
    }

    public function test_email_normalization_is_only_trim_and_lowercase(): void
    {
        $normalizer = new CredentialIdentityNormalizer;

        $this->assertSame('mixed.case+tag@example.test', $normalizer->normalizeEmail('  MIXED.Case+Tag@Example.test  '));
    }

    public function test_registry_is_globally_unique_and_survives_stock_item_deletion(): void
    {
        $product = Product::factory()->create();
        $stockItem = ProductStockItem::create([
            'product_id' => $product->id,
            'secret' => 'retained@example.test|password|mfa',
        ]);
        $identity = CredentialIdentity::create([
            'identity_kind' => CredentialIdentity::EMAIL,
            'fingerprint' => hash_hmac('sha256', 'retained@example.test', 'credential-test-fingerprint-key'),
            'product_id' => $product->id,
            'product_stock_item_id' => $stockItem->id,
            'first_seen_at' => now(),
        ]);

        $stockItem->delete();

        $this->assertDatabaseHas('credential_identities', ['id' => $identity->id]);
        $this->assertNull($identity->fresh()->product_stock_item_id);

        $this->expectException(QueryException::class);
        CredentialIdentity::create([
            'identity_kind' => CredentialIdentity::EMAIL,
            'fingerprint' => $identity->fingerprint,
        ]);
    }

    public function test_only_email_and_exact_auth_token_are_identity_values(): void
    {
        $otp = StructuredCredentialPayload::fromValues(
            'otp@example.test',
            'password-not-an-identity',
            CredentialAccessMethod::OtpMailLink,
            'https://mail.example.test/otp?opaque=fake',
        );
        $token = StructuredCredentialPayload::fromValues(
            'token@example.test',
            'another-password-not-an-identity',
            CredentialAccessMethod::AuthToken,
            'auth-token-is-an-identity',
        );

        $this->assertSame([
            ['kind' => CredentialIdentity::EMAIL, 'value' => 'otp@example.test'],
        ], $otp->identityValues());
        $this->assertSame([
            ['kind' => CredentialIdentity::EMAIL, 'value' => 'token@example.test'],
            ['kind' => CredentialIdentity::AUTH_TOKEN, 'value' => 'auth-token-is-an-identity'],
        ], $token->identityValues());
    }

    public function test_legacy_third_field_is_not_claimed_as_an_auth_token(): void
    {
        $product = Product::factory()->create();
        ProductStockItem::create([
            'product_id' => $product->id,
            'secret' => 'legacy-token@example.test|legacy-password|looks-like-an-auth-token',
        ]);

        $exitCode = Artisan::call('credentials:backfill-identity-registry');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, CredentialIdentity::where('identity_kind', CredentialIdentity::EMAIL)->count());
        $this->assertSame(0, CredentialIdentity::where('identity_kind', CredentialIdentity::AUTH_TOKEN)->count());
        $this->assertStringNotContainsString('looks-like-an-auth-token', $output);
    }

    public function test_backfill_is_idempotent_and_reports_conflicting_row_ids_without_plaintext(): void
    {
        $product = Product::factory()->create();
        $first = ProductStockItem::create(['product_id' => $product->id, 'secret' => 'duplicate@example.test|password-a|mfa-a']);
        $second = ProductStockItem::create(['product_id' => $product->id, 'secret' => 'duplicate@example.test|password-b|mfa-b']);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'not-a-confident-legacy-identity']);

        $exitCode = Artisan::call('credentials:backfill-identity-registry');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('CONFLICT_STOCK_ITEM_IDS '.$first->id.','.$second->id, $output);
        $this->assertStringContainsString('UNRESOLVED_STOCK_ITEM_IDS '.$second->id, $output);
        $this->assertStringNotContainsString('duplicate@example.test', $output);
        $this->assertStringNotContainsString('password-a', $output);
        $this->assertStringNotContainsString('password-b', $output);
        $this->assertSame(1, CredentialIdentity::where('identity_kind', CredentialIdentity::EMAIL)->count());

        $firstRerun = Artisan::call('credentials:backfill-identity-registry', ['--after-id' => $first->id - 1]);
        $rerunOutput = Artisan::output();

        $this->assertSame(1, $firstRerun);
        $this->assertStringContainsString('already_registered=1', $rerunOutput);
        $this->assertSame(1, CredentialIdentity::where('identity_kind', CredentialIdentity::EMAIL)->count());
    }

    public function test_backfill_dry_run_writes_no_registry_rows(): void
    {
        $product = Product::factory()->create();
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'dry-run@example.test|password|mfa']);

        $exitCode = Artisan::call('credentials:backfill-identity-registry', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('mode=dry_run', $output);
        $this->assertStringContainsString('registered=1', $output);
        $this->assertSame(0, CredentialIdentity::count());
    }
}
