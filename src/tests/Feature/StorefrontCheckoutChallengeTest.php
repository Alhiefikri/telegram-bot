<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Storefront\StorefrontCheckoutChallenge;
use App\Storefront\StorefrontShopCheckout;
use App\Storefront\StorefrontShopCheckoutInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\Support\StorefrontProductScenarios;
use Tests\TestCase;

class StorefrontCheckoutChallengeTest extends TestCase
{
    use RefreshDatabase;
    use StorefrontProductScenarios;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Queue::fake();
        config([
            'services.storefront.telegram_reservation_floor' => 0,
            'services.turnstile.site_key' => 'fixture-site-key',
            'services.turnstile.secret_key' => 'fixture-secret-key',
            'app.url' => 'http://localhost:8000/store',
        ]);
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'is_active' => true]);
    }

    #[DataProvider('missingConfiguration')]
    public function test_missing_configuration_fails_closed(string $key, mixed $value): void
    {
        config([$key => $value]);
        Http::fake();
        $product = $this->availableProduct(['stock' => 2]);

        $this->post(route('storefront.shop.checkout'), $this->payload($product))
            ->assertRedirect(route('storefront.shop.checkout.product', $product))
            ->assertSessionHas('storefront_checkout_error', StorefrontCheckoutChallenge::UNAVAILABLE_MESSAGE);

        $this->assertNoPurchase($product);
        Http::assertNothingSent();
    }

    public static function missingConfiguration(): array
    {
        return [
            'site key' => ['services.turnstile.site_key', null],
            'secret key' => ['services.turnstile.secret_key', ''],
            'invalid URL' => ['app.url', 'not-a-url'],
        ];
    }

    #[DataProvider('invalidProof')]
    public function test_missing_or_malformed_proof_never_calls_siteverify(mixed $proof): void
    {
        Http::fake();
        $product = $this->availableProduct(['stock' => 2]);

        $this->post(route('storefront.shop.checkout'), $this->payload($product, $proof))
            ->assertSessionHas('storefront_checkout_error', StorefrontCheckoutChallenge::FAILED_MESSAGE)
            ->assertSessionMissing('_old_input.cf-turnstile-response');

        $this->assertNoPurchase($product);
        Http::assertNothingSent();
    }

    public static function invalidProof(): array
    {
        return [
            'missing' => [null],
            'blank' => [''],
            'array' => [['untrusted-token']],
            'too long' => [str_repeat('a', 2049)],
        ];
    }

    #[DataProvider('rejectedResponses')]
    public function test_failed_or_malformed_siteverify_never_creates_a_purchase(mixed $body, int $status): void
    {
        Http::fake(['challenges.cloudflare.com/*' => Http::response($body, $status)]);
        $product = $this->availableProduct(['stock' => 2]);

        $this->post(route('storefront.shop.checkout'), $this->payload($product))
            ->assertSessionHas('storefront_checkout_error', StorefrontCheckoutChallenge::FAILED_MESSAGE)
            ->assertSessionMissing('_old_input.cf-turnstile-response');

        $this->assertNoPurchase($product);
        Http::assertSentCount(1);
    }

    public static function rejectedResponses(): array
    {
        $success = ['success' => true, 'action' => 'checkout', 'hostname' => 'localhost'];

        return [
            'failed' => [['success' => false], 200],
            'expired or duplicate' => [['success' => false, 'error-codes' => ['timeout-or-duplicate']], 200],
            'JSON syntax' => ['not-json', 200],
            'JSON scalar' => ['true', 200],
            'missing fields' => [['success' => true], 200],
            'truthy success' => [array_replace($success, ['success' => 'true']), 200],
            'wrong action' => [array_replace($success, ['action' => 'login']), 200],
            'wrong hostname' => [array_replace($success, ['hostname' => 'attacker.test']), 200],
            'outage' => [$success, 503],
        ];
    }

    public function test_timeout_is_fail_closed_without_exposing_transport_secrets(): void
    {
        Http::fake(fn () => throw new ConnectionException('fixture-secret-key untrusted-token'));
        $product = $this->availableProduct(['stock' => 2]);

        $this->post(route('storefront.shop.checkout'), $this->payload($product))
            ->assertSessionHas('storefront_checkout_error', StorefrontCheckoutChallenge::FAILED_MESSAGE)
            ->assertSessionMissing('_old_input.cf-turnstile-response');

        $this->assertNoPurchase($product);
        $this->assertStringNotContainsString('fixture-secret-key', json_encode(session()->all()));
        $this->assertStringNotContainsString('untrusted-token', json_encode(session()->all()));
    }

    public function test_valid_proof_is_checked_outside_purchase_transaction_and_replay_skips_verification(): void
    {
        $transactionLevel = DB::transactionLevel();
        Http::fake(function (Request $request) use ($transactionLevel) {
            $this->assertSame($transactionLevel, DB::transactionLevel());
            $this->assertSame(0, Order::count());
            $this->assertSame(0, Payment::count());
            $this->assertSame(0, InventoryMovement::count());
            $this->assertSame('POST', $request->method());
            $this->assertSame('https://challenges.cloudflare.com/turnstile/v0/siteverify', $request->url());
            $this->assertSame('fixture-secret-key', $request['secret']);
            $this->assertSame('untrusted-token', $request['response']);
            $this->assertSame('127.0.0.1', $request['remoteip']);

            return Http::response(['success' => true, 'action' => 'checkout', 'hostname' => 'localhost']);
        });
        $product = $this->availableProduct(['stock' => 2]);
        $session = ['storefront.checkout_client_key' => 'turnstile-replay-browser'];

        $first = $this->withSession($session)->post(route('storefront.shop.checkout'), $this->payload($product));
        $first->assertRedirect();
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Payment::count());
        config(['services.turnstile.secret_key' => null]);
        $this->withSession($session)->post(route('storefront.shop.checkout'), $this->payload($product, null))
            ->assertRedirect($first->headers->get('Location'));
        $this->get($first->headers->get('Location'))->assertOk();

        $this->assertSame(1, Order::count());
        $this->assertSame(1, Payment::count());
        Http::assertSentCount(1);
    }

    public function test_review_never_discloses_secret_or_previous_proof(): void
    {
        $product = $this->availableProduct(['stock' => 2]);

        $this->withSession(['_old_input' => ['cf-turnstile-response' => 'old-sensitive-proof']])
            ->get(route('storefront.shop.checkout.product', $product))
            ->assertOk()
            ->assertDontSee('fixture-secret-key')
            ->assertDontSee('old-sensitive-proof');
    }

    public function test_validation_failure_does_not_flash_submitted_proof(): void
    {
        Http::fake();
        $product = $this->availableProduct(['stock' => 2]);
        $payload = $this->payload($product);
        $payload['buyer_name'] = '';

        $this->post(route('storefront.shop.checkout'), $payload)
            ->assertSessionHasErrors('buyer_name')
            ->assertSessionHas('_old_input.checkout_attempt', $payload['checkout_attempt'])
            ->assertSessionMissing('_old_input.cf-turnstile-response');
        Http::assertNothingSent();
    }

    public function test_new_review_issues_a_uuid_and_validation_recovery_keeps_the_same_attempt(): void
    {
        $product = $this->availableProduct(['stock' => 2]);
        $html = $this->get(route('storefront.shop.checkout.product', $product))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/name="checkout_attempt" value="([^"]+)"/', $html, $matches));
        $this->assertTrue(Str::isUuid($matches[1]));

        $this->withSession(['_old_input' => ['checkout_attempt' => $matches[1]]])
            ->get(route('storefront.shop.checkout.product', $product))
            ->assertSee('name="checkout_attempt" value="'.$matches[1].'"', false);
    }

    public function test_missing_purchase_attempt_is_rejected_without_verification(): void
    {
        Http::fake();
        $product = $this->availableProduct(['stock' => 2]);
        $payload = $this->payload($product);
        unset($payload['checkout_attempt']);

        $this->post(route('storefront.shop.checkout'), $payload)->assertSessionHasErrors('checkout_attempt');

        $this->assertNoPurchase($product);
        Http::assertNothingSent();
    }

    public function test_malformed_purchase_attempt_recovers_to_a_usable_review_without_creating_a_purchase(): void
    {
        Http::fake();
        $product = $this->availableProduct(['stock' => 2]);
        foreach (['invalid-uuid', ['not-a-scalar']] as $attempt) {
            $payload = $this->payload($product);
            $payload['checkout_attempt'] = $attempt;
            $this->post(route('storefront.shop.checkout'), $payload)
                ->assertSessionHasErrors('checkout_attempt')
                ->assertSessionMissing('_old_input.checkout_attempt');
            $this->get(route('storefront.shop.checkout.product', $product))->assertOk();
        }
        $this->assertNoPurchase($product);
        Http::assertNothingSent();
    }

    public function test_internal_checkout_callers_do_not_receive_the_web_challenge(): void
    {
        Http::fake();
        config(['services.turnstile.secret_key' => null]);
        $product = $this->availableProduct(['stock' => 2]);

        app(StorefrontShopCheckout::class)->start(new StorefrontShopCheckoutInput(
            productId: $product->id,
            quantity: 1,
            buyerName: 'Internal Buyer',
            termsAccepted: true,
        ), 'internal-checkout-without-web-proof');

        $this->assertSame(1, Order::count());
        Http::assertNothingSent();
    }

    public function test_valid_challenge_creates_one_provider_invoice_and_replay_does_not_create_another(): void
    {
        KlikQrisSandboxScenarios::configureReadySandbox();
        ManualPaymentSetting::current()->update(['payment_mode' => 'klikqris']);
        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response([
                'success' => true,
                'action' => 'checkout',
                'hostname' => 'localhost',
            ]),
            'https://klikqris.com/api/sandbox/qris/create' => function (Request $request) {
                $payment = new Payment([
                    'merchant_ref' => (string) $request['order_id'],
                    'amount' => (int) $request['amount'],
                ]);

                return Http::response(KlikQrisSandboxScenarios::createPayload($payment, [
                    'expired_at' => now()->addMinutes(15)->toISOString(),
                ]));
            },
        ]);
        $product = $this->availableProduct(['stock' => 2]);
        $session = ['storefront.checkout_client_key' => 'verified-provider-browser'];

        $first = $this->withSession($session)->post(route('storefront.shop.checkout'), $this->payload($product));
        $first->assertRedirect();
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Payment::count());
        $this->assertSame('klikqris', Payment::sole()->provider);
        $this->withSession($session)->post(route('storefront.shop.checkout'), $this->payload($product, null))
            ->assertRedirect($first->headers->get('Location'));

        $this->assertSame(1, Order::count());
        $this->assertSame(1, Payment::count());
        Http::assertSentCount(2);
    }

    private function payload(Product $product, mixed $proof = 'untrusted-token'): array
    {
        return [
            'checkout_attempt' => '73000000-0000-4000-8000-000000000002',
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'Challenge Buyer',
            'terms_accepted' => '1',
            'cf-turnstile-response' => $proof,
        ];
    }

    private function assertNoPurchase(Product $product): void
    {
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, Customer::count());
        $this->assertSame(0, Cart::count());
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(0, $product->fresh()->reserved_stock);
    }
}
