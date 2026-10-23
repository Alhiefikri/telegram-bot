<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\OrderItem;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierCurrencyRate;
use App\Models\SupplierFulfillment;
use App\Models\SupplierPurchase;
use App\Models\User;
use App\Services\Suppliers\SupplierClient;
use App\Services\Suppliers\SupplierManager;
use App\Services\Suppliers\SupplierUnavailable;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\NonTransactionalDatabaseTestCase;

final class CanbosoSecurityTest extends NonTransactionalDatabaseTestCase
{
    private const KEY = 'canboso-synthetic-secret-canary-719b';

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'cache.limiter' => 'array']);
        Cache::flush();
        \Illuminate\Support\Facades\RateLimiter::swap(new RateLimiter(Cache::store('array')));
        Http::swap(new Factory);
        Http::preventStrayRequests();
        foreach (['USD' => '16000.000000', 'VND' => '0.650001'] as $currency => $rate) {
            SupplierCurrencyRate::query()->create([
                'currency' => $currency,
                'exchange_rate_idr' => $rate,
                'version' => 1,
            ]);
        }
    }

    #[DataProvider('duplicateAccounts')]
    public function test_same_account_cannot_authorize_multiple_units(array $second): void
    {
        $payload = $this->purchasePayload(2);
        $payload['delivery']['accounts'][] = array_replace($payload['delivery']['accounts'][0], $second);
        Http::fake(fn () => Http::response($payload));

        $result = app(SupplierClient::class)->purchase($this->supplier(), $this->item(), 2, 'security-checkout');

        $this->assertSame('ambiguous', $result->outcome);
        $this->assertFalse($result->deliveryTrusted);
        Http::assertSentCount(1);
    }

    public static function duplicateAccounts(): array
    {
        return [
            'identical account' => [[]],
            'changed password' => [['password' => 'different-synthetic-password']],
            'changed metadata' => [['otherInfo' => 'different recovery metadata']],
            'changed password and metadata' => [['password' => 'different-synthetic-password', 'verifyEmail' => 'second@example.test']],
        ];
    }

    #[DataProvider('secretEchoLocations')]
    public function test_secret_echo_anywhere_in_purchase_body_is_not_delivery_evidence(string $location): void
    {
        $payload = $this->purchasePayload();
        if ($location === 'value') {
            $payload['message'] = 'provider echoed '.self::KEY;
        } elseif ($location === 'leaf key') {
            $payload[self::KEY] = 'echo';
        } else {
            $payload['metadata'] = [self::KEY => []];
        }
        Http::fake(fn () => Http::response($payload));

        $result = app(SupplierClient::class)->purchase($this->supplier(), $this->item(), 1, 'security-checkout');

        $this->assertSame('ambiguous', $result->outcome);
        $this->assertSame([], $result->deliveries);
        $this->assertFalse($result->identityTrusted);
        $this->assertFalse($result->deliveryTrusted);
        $this->assertStringNotContainsString(self::KEY, json_encode($result));
        Http::assertSentCount(1);
    }

    public static function secretEchoLocations(): array
    {
        return [['value'], ['leaf key'], ['container key']];
    }

    public function test_distinct_accounts_authorize_exactly_two_units(): void
    {
        $payload = $this->purchasePayload(2);
        $payload['delivery']['accounts'][] = array_replace($payload['delivery']['accounts'][0], ['user' => 'second@example.test']);
        Http::fake(fn () => Http::response($payload));

        $result = app(SupplierClient::class)->purchase($this->supplier(), $this->item(), 2, 'security-checkout');

        $this->assertSame('delivered', $result->outcome);
        $this->assertTrue($result->deliveryTrusted);
        $this->assertCount(2, $result->deliveries);
        Http::assertSentCount(1);
    }

    public function test_cooldown_cache_failure_after_dispatch_is_ambiguous_not_a_retryable_rejection(): void
    {
        Http::fake(function () {
            Cache::shouldReceive('lock')->once()->andThrow(new \RuntimeException('cache leaked '.self::KEY));

            return Http::response(['success' => false], 429, ['Retry-After' => '21600']);
        });

        $result = app(SupplierClient::class)->purchase($this->supplier(), $this->item(), 1, 'security-checkout');

        $this->assertSame('ambiguous', $result->outcome);
        $this->assertSame('transport', $result->errorCode);
        $this->assertFalse($result->deliveryTrusted);
        $this->assertStringNotContainsString(self::KEY, json_encode($result));
        Http::assertSentCount(1);
    }

    public function test_budget_cache_failure_before_dispatch_rejects_without_http(): void
    {
        Cache::shouldReceive('lock')->once()->andThrow(new \RuntimeException('cache leaked '.self::KEY));
        Http::fake();

        $result = app(SupplierClient::class)->purchase($this->supplier(), $this->item(), 1, 'security-checkout');

        $this->assertSame('rejected', $result->outcome);
        $this->assertSame('rate_limited', $result->errorCode);
        Http::assertNothingSent();
    }

    #[DataProvider('invalidIdentifiers')]
    public function test_invalid_product_or_reference_is_rejected_before_http(string $field, string $value): void
    {
        $item = $this->item();
        $reference = 'security-checkout';
        if ($field === 'product') {
            $item->external_id = $value;
        } else {
            $reference = $value;
        }
        Http::fake();

        $result = app(SupplierClient::class)->purchase($this->supplier(), $item, 1, $reference);

        $this->assertSame('rejected', $result->outcome);
        $this->assertSame('invalid_identifier', $result->errorCode);
        $this->assertFalse($result->deliveryTrusted);
        Http::assertNothingSent();
    }

    public static function invalidIdentifiers(): array
    {
        $cases = [];
        foreach (['product', 'reference'] as $field) {
            foreach (['empty' => '', 'dot' => '.', 'dot dot' => '..', 'path traversal' => '../sku', 'query injection' => 'sku?key=attacker', 'fragment' => 'sku#fragment', 'header injection' => "sku\r\nX-Key: leaked", 'null byte' => "sku\0tail", 'unicode' => 'sku-é', 'whitespace' => ' sku ', 'overlong' => str_repeat('a', $field === 'product' ? 161 : 101)] as $name => $value) {
                $cases[$field.' '.$name] = [$field, $value];
            }
        }

        return $cases;
    }

    #[DataProvider('unsupportedPurchases')]
    public function test_unsupported_purchase_is_rejected_without_dispatch(array $changes, int $quantity): void
    {
        Http::fake();
        $item = $this->item()->forceFill($changes);

        $result = app(SupplierClient::class)->purchase($this->supplier(), $item, $quantity, 'security-checkout');

        $this->assertSame('rejected', $result->outcome);
        $this->assertFalse($result->deliveryTrusted);
        Http::assertNothingSent();
    }

    public static function unsupportedPurchases(): array
    {
        return [
            'foreign supplier' => [['supplier_id' => 999], 1],
            'requires input' => [['requires_input' => true], 1],
            'nonorderable' => [['orderable' => false], 1],
            'currency mismatch' => [['currency' => 'USDT'], 1],
            'zero quantity' => [[], 0],
            'negative quantity' => [[], -1],
            'overlong quantity' => [[], 10001],
        ];
    }

    #[DataProvider('spoofedPurchaseFields')]
    public function test_spoofed_success_order_or_payment_never_authorizes_delivery(string $section, string $field, mixed $value): void
    {
        $payload = $this->purchasePayload();
        if ($section === '') {
            $payload[$field] = $value;
        } else {
            $payload[$section][$field] = $value;
        }
        Http::fake(fn () => Http::response($payload));

        $result = app(SupplierClient::class)->purchase($this->supplier(), $this->item(), 1, 'security-checkout');

        $this->assertSame('ambiguous', $result->outcome);
        $this->assertFalse($result->deliveryTrusted);
        Http::assertSentCount(1);
    }

    public static function spoofedPurchaseFields(): array
    {
        return [
            'string success' => ['', 'success', 'true'],
            'integer success' => ['', 'success', 1],
            'failure with credentials' => ['', 'success', false],
            'foreign product' => ['order', 'productId', 'foreign-sku'],
            'numeric product' => ['order', 'productId', 1],
            'wrong quantity' => ['order', 'quantity', 2],
            'string quantity' => ['order', 'quantity', '1'],
            'unknown product type' => ['order', 'productType', 'unknown'],
            'input product type' => ['order', 'productType', 'upgrade_account'],
            'pending status' => ['order', 'status', 'pending'],
            'failed status' => ['order', 'status', 'failed'],
            'case spoofed status' => ['order', 'status', 'COMPLETED'],
            'missing status' => ['order', 'status', null],
            'bonus quantity' => ['order', 'bonusQuantity', 1],
            'wrong final quantity' => ['order', 'finalQuantity', 2],
            'invalid order identity' => ['order', 'orderCode', '../foreign'],
            'missing payment currency' => ['payment', 'currency', null],
            'foreign payment currency' => ['payment', 'currency', 'VND'],
            'negative payment' => ['payment', 'amount', '-0.05'],
            'exponent payment' => ['payment', 'amount', '5e-2'],
            'nonmoney payment' => ['payment', 'amount', ['amount' => '0.05']],
            'missing payment' => ['', 'payment', null],
        ];
    }

    #[DataProvider('malformedAccounts')]
    public function test_malformed_credentials_cannot_authorize_delivery(array $accounts): void
    {
        $payload = $this->purchasePayload();
        $payload['delivery']['accounts'] = $accounts;
        Http::fake(fn () => Http::response($payload));

        $result = app(SupplierClient::class)->purchase($this->supplier(), $this->item(), 1, 'security-checkout');

        $this->assertSame('ambiguous', $result->outcome);
        $this->assertFalse($result->deliveryTrusted);
        Http::assertSentCount(1);
    }

    public static function malformedAccounts(): array
    {
        return [
            'missing credentials' => [[]],
            'nonlist accounts' => [['account' => ['user' => 'user', 'password' => 'password']]],
            'scalar account' => [[true]],
            'missing username' => [[['password' => 'password']]],
            'missing password' => [[['user' => 'user']]],
            'blank username' => [[['user' => ' ', 'password' => 'password']]],
            'overlong username' => [[['user' => str_repeat('u', 1001), 'password' => 'password']]],
            'overlong password' => [[['user' => 'user', 'password' => str_repeat('p', 4001)]]],
            'control in username' => [[['user' => "user\0injected", 'password' => 'password']]],
            'control in password' => [[['user' => 'user', 'password' => "password\x7f"]]],
            'structured password' => [[['user' => 'user', 'password' => ['secret' => 'password']]]],
            'excessive account count' => [array_fill(0, 10001, ['user' => 'user', 'password' => 'password'])],
        ];
    }

    #[DataProvider('oversizeTransfers')]
    public function test_oversize_transfer_abort_is_ambiguous_without_retry(string $case): void
    {
        $calls = 0;
        Http::fake(function ($request, $options) use ($case, &$calls) {
            $calls++;
            if ($case === 'stream progress') {
                $options['progress'](0, 2097153);
            } else {
                $options['on_headers'](new Response(200, ['Content-Length' => $case === 'invalid content length' ? 'invalid' : '2097153']));
            }

            return Http::response($this->purchasePayload());
        });

        $result = app(SupplierClient::class)->purchase($this->supplier(), $this->item(), 1, 'security-checkout');

        $this->assertSame(1, $calls);
        $this->assertSame('ambiguous', $result->outcome);
        $this->assertSame('transport', $result->errorCode);
        $this->assertFalse($result->deliveryTrusted);
    }

    public static function oversizeTransfers(): array
    {
        return [['content length'], ['invalid content length'], ['stream progress']];
    }

    #[DataProvider('unsafeBodies')]
    public function test_unsafe_purchase_response_is_ambiguous_and_not_retried(string $body): void
    {
        Http::fake(fn () => Http::response($body));

        $result = app(SupplierClient::class)->purchase($this->supplier(), $this->item(), 1, 'security-checkout');

        $this->assertSame('ambiguous', $result->outcome);
        $this->assertFalse($result->deliveryTrusted);
        $this->assertSame([], $result->deliveries);
        Http::assertSentCount(1);
    }

    public static function unsafeBodies(): array
    {
        return [
            'malformed JSON' => ['{"success":true,'],
            'HTML error' => ['<html>provider failure</html>'],
            'scalar JSON' => ['true'],
            'list JSON' => ['[]'],
            'invalid UTF8' => ["{\"message\":\"\xff\"}"],
            'oversize body' => [str_repeat('x', 2097153)],
            'excessive nesting' => [str_repeat('{"nested":', 33).'0'.str_repeat('}', 33)],
        ];
    }

    #[DataProvider('httpOutcomes')]
    public function test_http_failures_preserve_documented_no_purchase_boundary(int $status, string $outcome): void
    {
        $redirectsAllowed = null;
        Http::fake(function ($request, $options) use ($status, &$redirectsAllowed) {
            $redirectsAllowed = $options['allow_redirects'];

            return Http::response(['message' => self::KEY], $status, ['Location' => 'https://attacker.example.test/'.self::KEY]);
        });

        $result = app(SupplierClient::class)->purchase($this->supplier(), $this->item(), 1, 'security-checkout');

        $this->assertSame($outcome, $result->outcome);
        $this->assertFalse($redirectsAllowed);
        $this->assertFalse($result->deliveryTrusted);
        $this->assertStringNotContainsString(self::KEY, json_encode($result));
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'attacker.example.test'));
    }

    public static function httpOutcomes(): array
    {
        return [
            [301, 'ambiguous'], [302, 'ambiguous'], [307, 'ambiguous'], [308, 'ambiguous'],
            [400, 'rejected'], [401, 'rejected'], [404, 'rejected'], [429, 'rejected'],
            [403, 'ambiguous'], [408, 'ambiguous'], [409, 'ambiguous'], [422, 'ambiguous'],
            [500, 'ambiguous'], [502, 'ambiguous'], [503, 'ambiguous'], [504, 'ambiguous'],
        ];
    }

    public function test_transport_exception_does_not_escape_or_trigger_a_second_purchase(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('POST https://canboso.com/purchase?key='.self::KEY.' provider-body-secret');
        });

        $result = app(SupplierClient::class)->purchase($this->supplier(), $this->item(), 1, 'security-checkout');

        $this->assertSame(1, $calls);
        $this->assertSame('ambiguous', $result->outcome);
        $this->assertSame('transport', $result->errorCode);
        $this->assertStringNotContainsString(self::KEY, json_encode($result));
        $this->assertStringNotContainsString('provider-body-secret', json_encode($result));
    }

    public function test_read_transport_exception_discards_url_body_key_and_previous_chain(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('https://canboso.com/balance?key='.self::KEY.' provider-body-secret', 0, new \RuntimeException(self::KEY));
        });

        try {
            app(SupplierClient::class)->snapshot($this->supplier());
            $this->fail('Transport failure must not return a catalog.');
        } catch (SupplierUnavailable $exception) {
            $this->assertSame('transport', $exception->errorCode);
            $this->assertNull($exception->getPrevious());
            $this->assertSame('Supplier unavailable: transport.', $exception->getMessage());
        }
        $this->assertSame(1, $calls);
    }

    #[DataProvider('malformedCatalogs')]
    public function test_malformed_catalog_cannot_publish_orderable_products(string $case): void
    {
        $catalog = $this->catalogPayload();
        switch ($case) {
            case 'duplicate IDs':
                $catalog['products'][] = $catalog['products'][0];
                break;
            case 'too many products':
                $catalog['products'] = array_fill(0, 501, $catalog['products'][0]);
                break;
            case 'associative products':
                $catalog['products'] = ['sku-1' => $catalog['products'][0]];
                break;
            case 'nonobject product':
                $catalog['products'] = [true];
                break;
            case 'missing wallet currency':
                unset($catalog['walletCurrency']);
                break;
            case 'missing price currency':
                unset($catalog['products'][0]['price']['currency']);
                break;
            case 'foreign price currency':
                $catalog['products'][0]['price']['currency'] = 'VND';
                break;
            case 'overlong name':
                $catalog['products'][0]['name'] = str_repeat('n', 501);
                break;
            case 'overlong description':
                $catalog['products'][0]['description'] = str_repeat('d', 16001);
                break;
            case 'negative stock':
                $catalog['products'][0]['availability']['available'] = -1;
                break;
            case 'unbounded stock':
                $catalog['products'][0]['availability']['available'] = 1000000001;
                break;
            case 'string stock':
                $catalog['products'][0]['availability']['available'] = '3';
                break;
            case 'too many values':
                $catalog['metadata'] = array_fill(0, 50001, 0);
                break;
            case 'too many empty containers':
                $catalog['metadata'] = array_fill(0, 50001, []);
                break;
            case 'secret in catalog key':
                $catalog['metadata'] = [self::KEY => []];
                break;
            case 'invalid product ID':
                $catalog['products'][0]['productId'] = '../sku';
                break;
        }
        Http::fake(fn ($request) => Http::response(str_contains($request->url(), '/balance?') ? ['success' => true, 'walletCurrency' => 'USD', 'balance' => '10.00'] : $catalog));

        try {
            app(SupplierClient::class)->snapshot($this->supplier());
            $this->fail('Malformed catalog must be unavailable.');
        } catch (SupplierUnavailable $exception) {
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString(self::KEY, $exception->getMessage());
        }
        Http::assertSentCount(2);
    }

    public static function malformedCatalogs(): array
    {
        return array_map(fn ($case) => [$case], [
            'duplicate IDs', 'too many products', 'associative products', 'nonobject product',
            'missing wallet currency', 'missing price currency', 'foreign price currency',
            'overlong name', 'overlong description', 'negative stock', 'unbounded stock',
            'string stock', 'too many values', 'too many empty containers', 'secret in catalog key', 'invalid product ID',
        ]);
    }

    #[DataProvider('inputProductTypes')]
    public function test_unknown_or_input_catalog_products_cannot_be_purchased(mixed $type, mixed $requirements): void
    {
        $catalog = $this->catalogPayload();
        $catalog['products'][0]['productType'] = $type;
        $catalog['products'][0]['purchaseRequirements'] = $requirements;
        Http::fake(fn ($request) => Http::response(str_contains($request->url(), '/balance?') ? ['success' => true, 'walletCurrency' => 'USD', 'balance' => '10.00'] : $catalog));

        $product = app(SupplierClient::class)->snapshot($this->supplier())->products[0];
        $this->assertFalse($product->orderable);
        $this->assertTrue($product->requiresInput);
        $item = $this->item()->forceFill(['orderable' => $product->orderable, 'requires_input' => $product->requiresInput]);
        $result = app(SupplierClient::class)->purchase($this->supplier(), $item, 1, 'security-checkout');
        $this->assertSame('rejected', $result->outcome);
        Http::assertSentCount(2);
    }

    public static function inputProductTypes(): array
    {
        return [['slot', []], ['upgrade_account', []], ['unknown', []], [null, []], [true, []], ['account', ['email' => 'required']]];
    }

    public function test_financial_transaction_denies_purchase_snapshot_and_recovery_before_http(): void
    {
        Http::fake();
        DB::transaction(function () {
            $client = app(SupplierClient::class);
            $result = $client->purchase($this->supplier(), $this->item(), 1, 'security-checkout');
            $this->assertSame('rejected', $result->outcome);
            $this->assertSame('financial_lock', $result->errorCode);
            foreach (['snapshot', 'order'] as $operation) {
                try {
                    $operation === 'snapshot' ? $client->snapshot($this->supplier()) : $client->order($this->supplier(), 'ORDER1');
                    $this->fail('HTTP cannot occur inside financial locks.');
                } catch (SupplierUnavailable $exception) {
                    $this->assertSame('financial_lock', $exception->errorCode);
                    $this->assertNull($exception->getPrevious());
                }
            }
        });
        Http::assertNothingSent();
    }

    public function test_canboso_key_is_encrypted_hidden_and_absent_from_admin_session_body_and_audit(): void
    {
        $this->owner();
        Http::fake();
        $this->withSession(['auth.password_confirmed_at' => time()])->post(route('admin.suppliers.store'), $this->connectionInput())
            ->assertRedirect()->assertDontSee(self::KEY);
        $supplier = Supplier::query()->sole();
        $this->assertSame(self::KEY, $supplier->api_key);
        $encrypted = DB::table('suppliers')->value('api_key');
        $this->assertStringNotContainsString(self::KEY, $encrypted);
        $this->assertSame(self::KEY, app(Encrypter::class)->decrypt($encrypted, false));
        $this->assertArrayNotHasKey('api_key', $supplier->toArray());
        $this->assertStringNotContainsString(self::KEY, $supplier->toJson());
        $this->get(route('admin.dashboard', ['tab' => 'suppliers']))->assertOk()->assertDontSee(self::KEY);
        $this->get(route('admin.suppliers.catalog', $supplier))->assertOk()->assertDontSee(self::KEY);
        $this->assertPrivateAdminState();
        Http::assertNothingSent();
    }

    public function test_owner_and_admin_can_manage_canboso_without_exposing_credentials(): void
    {
        Http::fake();
        $supplier = $this->storedSupplier();
        foreach ([UserRole::Owner, UserRole::Admin] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->get(route('admin.dashboard', ['tab' => 'suppliers']))->assertOk()->assertDontSee(self::KEY);
            $this->get(route('admin.suppliers.catalog', $supplier))->assertOk()->assertDontSee(self::KEY);
            $this->get(route('admin.suppliers.purchases'))->assertOk()->assertDontSee(self::KEY);
            $this->withSession(['auth.password_confirmed_at' => time()])
                ->patch(route('admin.suppliers.update', $supplier), $this->connectionInput(['api_key' => '', 'name' => 'Allowed metadata edit', 'version' => $supplier->fresh()->version]))
                ->assertRedirect()->assertDontSee(self::KEY);
        }
        $this->assertSame(self::KEY, $supplier->fresh()->api_key);
        $this->assertPrivateAdminState();
        Http::assertNothingSent();
    }

    public function test_confirmed_rotation_without_commitments_encrypts_new_key_and_invalidates_freshness(): void
    {
        $this->owner();
        Http::fake();
        $supplier = $this->storedSupplier(['balance_micros' => 1000000, 'synced_at' => now(), 'sync_expires_at' => now()->addMinutes(5)]);
        $replacement = 'canboso-synthetic-replacement-canary-8b21';

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('admin.suppliers.update', $supplier), $this->connectionInput(['api_key' => $replacement, 'version' => 1]))
            ->assertRedirect()->assertDontSee($replacement);

        $supplier->refresh();
        $this->assertSame($replacement, $supplier->api_key);
        $this->assertSame(2, $supplier->version);
        $this->assertSame(1000000, $supplier->balance_micros);
        $this->assertNull($supplier->sync_expires_at);
        $this->assertNull($supplier->synced_at);
        $this->assertStringNotContainsString($replacement, DB::table('suppliers')->value('api_key'));
        $this->assertStringNotContainsString($replacement, $supplier->toJson());
        $this->get(route('admin.dashboard', ['tab' => 'suppliers']))->assertOk()->assertDontSee($replacement);
        $this->assertStringNotContainsString($replacement, json_encode(session()->all()));
        $this->assertStringNotContainsString($replacement, json_encode(AuditLog::query()->get()->toArray()));
        $this->assertPrivateAdminState();
        Http::assertNothingSent();
    }

    public function test_unauthorized_roles_cannot_read_or_mutate_canboso_at_http_or_manager_boundary(): void
    {
        Http::fake();
        $supplier = $this->storedSupplier();
        foreach ([UserRole::Finance, UserRole::Support] as $role) {
            $actor = User::factory()->create(['role' => $role]);
            $this->actingAs($actor);
            $this->get(route('admin.suppliers.catalog', $supplier))->assertForbidden()->assertDontSee(self::KEY);
            $this->get(route('admin.suppliers.purchases'))->assertForbidden();
            $this->post(route('admin.suppliers.store'), $this->connectionInput())->assertForbidden();
            $this->patch(route('admin.suppliers.update', $supplier), $this->connectionInput(['version' => 1]))->assertForbidden();
            $this->post(route('admin.suppliers.sync', $supplier))->assertForbidden();
            try {
                app(SupplierManager::class)->save($supplier, $this->connectionInput(['version' => 1]), $actor, null);
                $this->fail('Manager must deny unauthorized credential access.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $this->assertSame(1, Supplier::query()->count());
        $this->assertSame(1, $supplier->fresh()->version);
        $this->assertSame(0, AuditLog::query()->count());
        Http::assertNothingSent();
    }

    public function test_missing_stale_or_future_password_confirmation_cannot_create_or_rotate_canboso_key(): void
    {
        $this->owner();
        Http::fake();
        $supplier = $this->storedSupplier();
        foreach ([null, time() - 901, time() + 60] as $confirmedAt) {
            $this->withSession(['auth.password_confirmed_at' => $confirmedAt])->post(route('admin.suppliers.store'), $this->connectionInput())->assertStatus(428)->assertDontSee(self::KEY);
            $this->patch(route('admin.suppliers.update', $supplier), $this->connectionInput(['api_key' => 'synthetic-replacement-key', 'version' => 1]))->assertStatus(428)->assertDontSee('synthetic-replacement-key');
        }
        $this->assertSame(self::KEY, $supplier->fresh()->api_key);
        $this->assertSame(1, Supplier::query()->count());
        $this->assertPrivateAdminState();
        $this->assertStringNotContainsString('synthetic-replacement-key', json_encode(session()->all()));
        Http::assertNothingSent();
    }

    public function test_production_csrf_guards_canboso_creation_rotation_and_sync(): void
    {
        $this->owner();
        Http::fake();
        $supplier = $this->storedSupplier();
        $this->app->instance(PreventRequestForgery::class, new class($this->app, app(Encrypter::class)) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->withSession(['auth.password_confirmed_at' => time()])->post(route('admin.suppliers.store'), $this->connectionInput())->assertStatus(419);
        $this->patch(route('admin.suppliers.update', $supplier), $this->connectionInput(['version' => 1]))->assertStatus(419);
        $this->post(route('admin.suppliers.sync', $supplier))->assertStatus(419);
        $this->assertSame(1, Supplier::query()->count());
        $this->assertSame(1, $supplier->fresh()->version);
        Http::assertNothingSent();
    }

    public function test_validation_cannot_flash_key_or_accept_custom_url_and_currency(): void
    {
        $this->owner();
        Http::fake();
        foreach ([['base_url' => 'https://attacker.example.test'], ['currency' => 'VND'], ['api_key' => self::KEY."\r\nInjected: value"], ['provider' => self::KEY]] as $changes) {
            $this->withSession(['auth.password_confirmed_at' => time()])->post(route('admin.suppliers.store'), $this->connectionInput($changes))->assertStatus(422)->assertDontSee(self::KEY);
            $this->assertPrivateAdminState();
        }
        $this->assertSame(0, Supplier::query()->count());
        Http::assertNothingSent();
    }

    #[DataProvider('unresolvedStates')]
    public function test_unresolved_purchase_blocks_rotation_even_without_wallet_reservation(string $state): void
    {
        $this->owner();
        Http::fake();
        $supplier = $this->storedSupplier();
        $catalog = $this->item()->forceFill(['supplier_id' => $supplier->id]);
        $catalog->save();
        $orderItem = OrderItem::factory()->create();
        $fulfillment = SupplierFulfillment::create(['order_id' => $orderItem->order_id, 'order_item_id' => $orderItem->id, 'quantity' => 1, 'state' => 'review', 'maximum_cost_idr' => 20000, 'sources_snapshot' => []]);
        $purchase = SupplierPurchase::create(['supplier_fulfillment_id' => $fulfillment->id, 'supplier_id' => $supplier->id, 'supplier_catalog_item_id' => $catalog->id, 'reference' => 'security-checkout', 'quantity' => 1, 'expected_cost_micros' => 50000, 'exchange_rate_idr' => 16000, 'state' => $state]);

        $this->withSession(['auth.password_confirmed_at' => time()])->patch(route('admin.suppliers.update', $supplier), $this->connectionInput(['api_key' => 'synthetic-replacement-key', 'version' => 1]))->assertStatus(409)->assertDontSee(self::KEY)->assertDontSee('synthetic-replacement-key');

        $this->assertSame(self::KEY, $supplier->fresh()->api_key);
        $this->assertSame(1, $supplier->version);
        $this->assertSame($state, $purchase->fresh()->state);
        $this->assertSame(0, $supplier->reserved_balance_micros);
        $this->assertSame(0, AuditLog::query()->where('action', 'supplier.updated')->count());
        $this->assertPrivateAdminState();
        Http::assertNothingSent();
    }

    public static function unresolvedStates(): array
    {
        return [['reserved'], ['sending'], ['ambiguous'], ['awaiting_delivery'], ['review']];
    }

    public function test_wallet_commitment_blocks_rotation_but_blank_secret_preserves_connection(): void
    {
        $this->owner();
        Http::fake();
        $supplier = $this->storedSupplier(['balance_micros' => 1000000, 'reserved_balance_micros' => 50000]);
        $this->withSession(['auth.password_confirmed_at' => time()])->patch(route('admin.suppliers.update', $supplier), $this->connectionInput(['api_key' => 'synthetic-replacement-key', 'version' => 1]))->assertStatus(409);
        $this->withSession(['auth.password_confirmed_at' => null])->patch(route('admin.suppliers.update', $supplier), $this->connectionInput(['api_key' => '', 'name' => 'Metadata only', 'version' => 1]))->assertRedirect();
        $supplier->refresh();
        $this->assertSame(self::KEY, $supplier->api_key);
        $this->assertSame(50000, $supplier->reserved_balance_micros);
        $this->assertSame('Metadata only', $supplier->name);
        $this->assertPrivateAdminState();
        Http::assertNothingSent();
    }

    private function supplier(): Supplier
    {
        return (new Supplier)->forceFill($this->connectionInput(['id' => 123, 'version' => 1, 'exchange_rate_idr' => '16000.000000']));
    }

    private function storedSupplier(array $changes = []): Supplier
    {
        $supplier = (new Supplier)->forceFill($this->connectionInput(array_replace(['version' => 1, 'reserved_balance_micros' => 0, 'exchange_rate_idr' => '16000.000000'], $changes)));
        $supplier->save();

        return $supplier;
    }

    private function item(): SupplierCatalogItem
    {
        return (new SupplierCatalogItem)->forceFill(['supplier_id' => 123, 'external_id' => 'sku-1', 'name' => 'Synthetic private account', 'unit_price_micros' => 50000, 'stock' => 10, 'reserved_quantity' => 0, 'currency' => 'USD', 'orderable' => true, 'requires_input' => false, 'price_tiers' => [], 'bulk_discounts' => []]);
    }

    private function purchasePayload(int $quantity = 1): array
    {
        return ['success' => true, 'order' => ['orderCode' => 'ORDER1', 'status' => 'completed', 'productId' => 'sku-1', 'productType' => 'account', 'quantity' => $quantity, 'bonusQuantity' => 0, 'finalQuantity' => $quantity], 'payment' => ['amount' => '0.05', 'currency' => 'USD'], 'delivery' => ['accounts' => [['user' => 'synthetic@example.test', 'password' => 'synthetic-password', 'verifyEmail' => 'recovery@example.test', 'otherInfo' => 'synthetic-token']]]];
    }

    private function catalogPayload(): array
    {
        return ['success' => true, 'walletCurrency' => 'USD', 'products' => [['productId' => 'sku-1', 'name' => 'Synthetic private account', 'productType' => 'account', 'price' => ['amount' => '0.05', 'currency' => 'USD'], 'availability' => ['available' => 10]]]];
    }

    private function connectionInput(array $changes = []): array
    {
        return array_replace(['name' => 'Canboso synthetic connection', 'provider' => 'canboso', 'api_key' => self::KEY, 'currency' => 'USD', 'currency_confirmed' => true, 'is_active' => true, 'low_balance_threshold_micros' => 0], $changes);
    }

    private function owner(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
    }

    private function assertPrivateAdminState(): void
    {
        $this->assertStringNotContainsString(self::KEY, json_encode(session()->all()));
        $this->assertStringNotContainsString(self::KEY, json_encode(AuditLog::query()->get()->toArray()));
    }
}
