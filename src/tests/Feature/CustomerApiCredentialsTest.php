<?php

namespace Tests\Feature;

use App\Enums\TelegramNavigationMediaKind;
use App\Enums\TelegramNavigationScreen;
use App\Models\Customer;
use App\Models\CustomerApiCredential;
use App\Models\TelegramNavigationRoot;
use App\Services\CustomerApi\CustomerApiCredentials;
use App\Services\CustomerApi\CustomerApiFailure;
use App\Services\Telegram\TelegramCustomerApiActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerApiCredentialsTest extends TestCase
{
    use RefreshDatabase;

    public function test_secret_is_one_time_only_and_rotation_preserves_account_ownership_but_rejects_old_authority(): void
    {
        $customer = Customer::factory()->create();
        $other = Customer::factory()->create();
        $service = app(CustomerApiCredentials::class);
        $issued = $service->generate($customer);
        $token = $issued->takeSecret();
        $principal = $service->authenticate('Bearer '.$token);
        $this->assertSame($customer->id, $principal->customerId);
        $this->assertNotSame($other->id, $principal->customerId);
        $this->assertStringNotContainsString($token, json_encode(DB::table('customer_api_credentials')->get(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString(substr($token, -64), json_encode(CustomerApiCredential::query()->first(), JSON_THROW_ON_ERROR));
        try {
            serialize($issued);
            $this->fail('Secret results must not enter a durable payload.');
        } catch (\LogicException) {
        }
        try {
            $issued->takeSecret();
            $this->fail('A secret cannot be replayed.');
        } catch (\LogicException) {
        }
        $confirmation = $service->prepareRotation($customer, 100);
        $replacement = $service->rotate($customer, 100, $confirmation)->takeSecret();
        $this->assertNull($service->authenticate('Bearer '.$token));
        $this->assertSame($customer->id, $service->authenticate('Bearer '.$replacement)->customerId);
        try {
            DB::transaction(fn () => $principal->revalidateForMutation());
            $this->fail('A previously admitted credential must fail mutation revalidation after revocation.');
        } catch (CustomerApiFailure $failure) {
            $this->assertSame(401, $failure->status);
        }
        $service->disable($customer);
        $this->assertNull($service->authenticate('Bearer '.$replacement));
        $this->assertFalse($service->summary($customer)['active']);
        $this->assertSame(0, CustomerApiCredential::query()->whereNull('revoked_at')->count());
    }

    public function test_rotation_requires_unexpired_current_message_confirmation_and_replay_cannot_replace_new_key(): void
    {
        $customer = Customer::factory()->create();
        $service = app(CustomerApiCredentials::class);
        $original = $service->generate($customer)->takeSecret();
        $nonce = $service->prepareRotation($customer, 100);
        foreach ([[101, $nonce], [100, str_repeat('0', 32)]] as [$message, $confirmation]) {
            try {
                $service->rotate($customer, $message, $confirmation);
                $this->fail('Invalid rotation confirmation was accepted.');
            } catch (CustomerApiFailure $failure) {
                $this->assertSame(409, $failure->status);
            }
        }
        $this->travel(6)->minutes();
        try {
            $service->rotate($customer, 100, $nonce);
            $this->fail('Expired confirmation was accepted.');
        } catch (CustomerApiFailure $failure) {
            $this->assertSame(409, $failure->status);
        }
        $this->assertNotNull($service->authenticate('Bearer '.$original));
        $nonce = $service->prepareRotation($customer, 100);
        $new = $service->rotate($customer, 100, $nonce)->takeSecret();
        try {
            $service->rotate($customer, 100, $nonce);
            $this->fail('Rotation confirmation was replayed.');
        } catch (CustomerApiFailure $failure) {
            $this->assertSame(409, $failure->status);
        }
        $this->assertNotNull($service->authenticate('Bearer '.$new));
    }

    public function test_malformed_keys_and_non_telegram_customers_have_no_credential_authority(): void
    {
        $service = app(CustomerApiCredentials::class);
        foreach ([null, '', 'Bearer admin-secret', 'Bearer capi_'.str_repeat('a', 32).'.'.str_repeat('b', 64), 'Basic abc', 'Bearer '.str_repeat('x', 10000)] as $authorization) {
            $this->assertNull($service->authenticate($authorization));
        }
        $this->expectException(CustomerApiFailure::class);
        $service->generate(Customer::factory()->create(['telegram_id' => null]));
    }

    public function test_group_cross_actor_unknown_and_stale_private_actions_cannot_generate_or_disable(): void
    {
        $customer = Customer::factory()->create();
        $service = app(CustomerApiCredentials::class);
        $original = $service->generate($customer)->takeSecret();
        $actions = app(TelegramCustomerApiActions::class);
        TelegramNavigationRoot::query()->create(['customer_id' => $customer->id, 'chat_id' => (string) $customer->telegram_id,
            'message_id' => 100, 'media_kind' => TelegramNavigationMediaKind::Text,
            'screen' => TelegramNavigationScreen::Home, 'locale' => 'id', 'generation' => 1]);
        $this->assertTrue($actions->summary((string) $customer->telegram_id, (string) $customer->telegram_id, 'private', 100)['active']);
        foreach ([[(string) $customer->telegram_id, (string) $customer->telegram_id, 'group', 100], ['999', (string) $customer->telegram_id, 'private', 100], ['999', '999', 'private', 100], [(string) $customer->telegram_id, (string) $customer->telegram_id, 'private', 99]] as $facts) {
            foreach (['generate', 'disable'] as $operation) {
                try {
                    $actions->$operation(...$facts);
                    $this->fail('Untrusted private actor facts were admitted.');
                } catch (CustomerApiFailure $failure) {
                    $this->assertSame(403, $failure->status);
                }
            }
        }
        $this->assertNotNull($service->authenticate('Bearer '.$original));
        $this->assertSame(1, CustomerApiCredential::query()->count());
    }

    public function test_unshared_admission_store_is_unavailable_instead_of_failing_open(): void
    {
        $this->getJson('/api/v1/balance')->assertStatus(503)->assertJsonPath('error.code', 'temporarily_unavailable')
            ->assertHeader('Cache-Control', 'no-store, private');
    }
}
