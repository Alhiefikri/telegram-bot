<?php

namespace Tests\Feature;

use App\Storefront\StorefrontProductPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StorefrontProductScenarios;
use Tests\TestCase;

class StorefrontProductPresentationTest extends TestCase
{
    use RefreshDatabase;
    use StorefrontProductScenarios;

    public function test_available_product_presentation_is_buyable_and_opens_modal(): void
    {
        $product = $this->availableProduct([
            'name' => 'Claude Pro Ready',
            'description' => "Akun private siap pakai.\nGaransi login.",
            'price_amount' => 60000,
            'fake_discount_price' => 85000,
            'stock' => 8,
            'reserved_stock' => 1,
        ]);

        $presentation = app(StorefrontProductPresenter::class)->present($product);

        $this->assertTrue($presentation->isAvailable);
        $this->assertTrue($presentation->isBuyable);
        $this->assertTrue($presentation->canOpenModal);
        $this->assertSame(7, $presentation->stockAvailable);
        $this->assertSame('Stok: 7', $presentation->stockLabel);
        $this->assertSame('Akun private siap pakai.', $presentation->descriptionSnippet);
        $this->assertSame('Pilih Checkout', $presentation->cardActionLabel);
        $this->assertSame(route('storefront.products.show', $product), $presentation->productUrl);

        $modalPayload = $presentation->modalPayload();

        $this->assertIsArray($modalPayload);
        $this->assertSame($product->id, $modalPayload['id']);
        $this->assertSame('Claude Pro Ready', $modalPayload['name']);
        $this->assertSame("Akun private siap pakai.\nGaransi login.", $modalPayload['description']);
        $this->assertSame(60000, $modalPayload['price']['amount']);
        $this->assertSame(85000, $modalPayload['price']['fakeDiscountAmount']);
        $this->assertSame(7, $modalPayload['stock']['available']);
        $this->assertSame('Stok: 7', $modalPayload['stock']['label']);
        $this->assertTrue($modalPayload['availability']['isAvailable']);
        $this->assertTrue($modalPayload['availability']['canOpenModal']);
    }

    public function test_unavailable_product_presentation_stays_visible_without_checkout_actions(): void
    {
        $product = $this->unavailableProduct([
            'name' => 'ChatGPT Habis',
        ]);

        $presentation = app(StorefrontProductPresenter::class)->present($product);

        $this->assertFalse($presentation->isAvailable);
        $this->assertFalse($presentation->isBuyable);
        $this->assertFalse($presentation->canOpenModal);
        $this->assertSame('Habis', $presentation->availabilityLabel);
        $this->assertSame('Stok kosong', $presentation->stockLabel);
        $this->assertSame('Restock segera', $presentation->statusLabel);
        $this->assertSame('Stok kosong', $presentation->cardActionLabel);
        $this->assertSame([], $presentation->checkoutChoices);
        $this->assertNull($presentation->checkoutChoice('website_qris'));
        $this->assertNull($presentation->checkoutChoice('telegram_admin'));
        $this->assertNull($presentation->modalPayload());
    }

    public function test_available_product_exposes_website_qris_and_telegram_checkout_choices(): void
    {
        $product = $this->availableProduct([
            'name' => 'CapCut Pro Web',
            'stock' => 3,
        ]);

        $presentation = app(StorefrontProductPresenter::class)->present($product);

        $websiteQris = $presentation->checkoutChoice('website_qris');
        $telegram = $presentation->checkoutChoice('telegram_admin');

        $this->assertNotNull($websiteQris);
        $this->assertSame('Bayar QRIS di Website', $websiteQris->label);
        $this->assertSame('POST', $websiteQris->method);
        $this->assertSame(route('storefront.shop.checkout'), $websiteQris->url);
        $this->assertSame($product->id, $websiteQris->productId);
        $this->assertTrue($websiteQris->enabled);

        $this->assertNotNull($telegram);
        $this->assertSame('Beli lewat Telegram', $telegram->label);
        $this->assertSame('GET', $telegram->method);
        $this->assertSame(route('storefront.products.payment', $product), $telegram->url);
        $this->assertSame('checkoutManual', $telegram->browserAction);
        $this->assertTrue($telegram->enabled);
    }

    public function test_stock_description_and_cover_image_state_are_prepared_consistently(): void
    {
        $product = $this->availableProduct([
            'name' => 'Canva Premium',
            'description' => "\n\nDesain premium siap pakai.\nDetail lain.",
            'image_path' => 'products/canva.jpg',
            'stock' => 5,
            'reserved_stock' => 2,
        ]);

        $presentation = app(StorefrontProductPresenter::class)->present($product);

        $this->assertSame(3, $presentation->stockAvailable);
        $this->assertSame('Desain premium siap pakai.', $presentation->descriptionSnippet);
        $this->assertSame('/storage/products/canva.jpg', $presentation->cover->imageUrl);
        $this->assertSame('CANVA PREMIUM', $presentation->cover->theme['desc']);
        $this->assertTrue($presentation->cover->hasImage());
    }

    public function test_modal_payload_contains_the_browser_rendering_state(): void
    {
        $product = $this->availableProduct([
            'name' => 'Gemini Web',
            'description' => '',
            'price_amount' => 42000,
            'stock' => 2,
        ]);

        $payload = app(StorefrontProductPresenter::class)->present($product)->modalPayload();

        $this->assertSame([
            'id',
            'name',
            'description',
            'descriptionSnippet',
            'price',
            'stock',
            'availability',
            'cover',
            'checkout',
        ], array_keys($payload));
        $this->assertSame('Deskripsi produk segera dilengkapi.', $payload['descriptionSnippet']);
        $this->assertSame('', $payload['description']);
        $this->assertSame(['amount' => 42000, 'fakeDiscountAmount' => null], $payload['price']);
        $this->assertSame([
            'available' => 2,
            'label' => 'Stok: 2',
            'emptyLabel' => 'Stok kosong',
        ], $payload['stock']);
        $this->assertSame(route('storefront.shop.checkout'), $payload['checkout']['websiteQris']['url']);
        $this->assertSame(route('storefront.products.payment', $product), $payload['checkout']['telegram']['url']);
    }
}
