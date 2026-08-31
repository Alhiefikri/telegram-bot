<?php

namespace Tests\Feature;

use App\Models\ManualPaymentSetting;
use App\Models\ProductStockItem;
use App\Storefront\StorefrontProductPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StorefrontProductScenarios;
use Tests\TestCase;

class StorefrontProductModalTest extends TestCase
{
    use RefreshDatabase;
    use StorefrontProductScenarios;

    protected function setUp(): void
    {
        parent::setUp();

        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'is_active' => true,
        ]);
    }

    public function test_public_modal_endpoint_returns_the_existing_safe_payload_when_ready(): void
    {
        $product = $this->availableProduct([
            'name' => 'Lazy Modal Product',
            'description' => "Akun siap pakai.\nTanpa rahasia di katalog.",
            'price_amount' => 42000,
        ]);
        ProductStockItem::create([
            'product_id' => $product->id,
            'secret' => 'private-account@example.test|private-password',
        ]);
        $product = $product->fresh();
        $expectedPayload = app(StorefrontProductPresenter::class)->present($product)->modalPayload();

        $response = $this->getJson(route('storefront.products.modal', $product));

        $response
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('product', $expectedPayload)
            ->assertJsonPath('fallback_url', route('storefront.products.show', $product));

        $serialized = $response->getContent();
        $this->assertStringNotContainsString('private-account@example.test', $serialized);
        $this->assertStringNotContainsString('private-password', $serialized);
        $this->assertStringNotContainsString('delivery_note', $serialized);
        $this->assertStringNotContainsString('usage_guide', $serialized);
    }

    public function test_public_modal_endpoint_returns_safe_fallback_when_checkout_is_unavailable(): void
    {
        ManualPaymentSetting::query()->delete();
        $product = $this->availableProduct(['name' => 'Fallback Modal Product']);

        $this->getJson(route('storefront.products.modal', $product))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertJson([
                'status' => 'fallback',
                'product' => null,
                'reason' => 'checkout_unavailable',
                'fallback_url' => route('storefront.products.show', $product),
            ]);
    }

    public function test_hidden_product_cannot_use_the_public_modal_endpoint(): void
    {
        $product = $this->availableProduct([
            'name' => 'Hidden Modal Product',
            'is_active' => false,
        ]);

        $this->getJson(route('storefront.products.modal', $product))
            ->assertNotFound();
    }
}
