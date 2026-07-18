<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
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
            'stock' => 8,
            'reserved_stock' => 1,
        ]);

        $presentation = app(StorefrontProductPresenter::class)->present($product);

        $this->assertTrue($presentation->isAvailable);
        $this->assertTrue($presentation->isBuyable);
        $this->assertTrue($presentation->canOpenModal);
        $this->assertSame(7, $presentation->stockAvailable);
        $this->assertSame('Stok: 7', $presentation->stockLabel);
        $this->assertSame(0, $presentation->soldQuantity);
        $this->assertSame('Terjual: 0', $presentation->soldLabel);
        $this->assertSame('Akun private siap pakai.', $presentation->descriptionSnippet);
        $this->assertSame('Pilih Checkout', $presentation->cardActionLabel);
        $this->assertSame(route('storefront.products.show', $product), $presentation->productUrl);

        $modalPayload = $presentation->modalPayload();

        $this->assertIsArray($modalPayload);
        $this->assertSame($product->id, $modalPayload['id']);
        $this->assertSame('Claude Pro Ready', $modalPayload['name']);
        $this->assertSame("Akun private siap pakai.\nGaransi login.", $modalPayload['description']);
        $this->assertSame(['amount' => 60000], $modalPayload['price']);
        $this->assertSame(7, $modalPayload['stock']['available']);
        $this->assertSame('Stok: 7', $modalPayload['stock']['label']);
        $this->assertSame(['quantity' => 0, 'label' => 'Terjual: 0'], $modalPayload['sold']);
        $this->assertTrue($modalPayload['availability']['isAvailable']);
        $this->assertTrue($modalPayload['availability']['canOpenModal']);
    }

    public function test_product_presentation_counts_paid_completed_sales_only(): void
    {
        $product = $this->availableProduct(['name' => 'ChatGPT Plus Private']);
        $paidOrder = Order::factory()->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $pendingOrder = Order::factory()->create([
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
        ]);

        OrderItem::factory()->create(['order_id' => $paidOrder->id, 'product_id' => $product->id, 'quantity' => 3]);
        OrderItem::factory()->create(['order_id' => $pendingOrder->id, 'product_id' => $product->id, 'quantity' => 5]);

        $presentation = app(StorefrontProductPresenter::class)->present($product);

        $this->assertSame(3, $presentation->soldQuantity);
        $this->assertSame('Terjual: 3', $presentation->soldLabel);
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

    public function test_available_product_exposes_only_the_website_qris_checkout_choice(): void
    {
        $product = $this->availableProduct([
            'name' => 'CapCut Pro Web',
            'stock' => 3,
        ]);

        $presentation = app(StorefrontProductPresenter::class)->present($product);

        $websiteQris = $presentation->checkoutChoice('website_qris');

        $this->assertNotNull($websiteQris);
        $this->assertSame('Bayar QRIS di Website', $websiteQris->label);
        $this->assertSame('POST', $websiteQris->method);
        $this->assertSame(route('storefront.shop.checkout'), $websiteQris->url);
        $this->assertSame($product->id, $websiteQris->productId);
        $this->assertTrue($websiteQris->enabled);

        $this->assertNull($presentation->checkoutChoice('telegram_admin'));
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
            'sold',
            'availability',
            'cover',
            'checkout',
        ], array_keys($payload));
        $this->assertSame('Deskripsi produk segera dilengkapi.', $payload['descriptionSnippet']);
        $this->assertSame('', $payload['description']);
        $this->assertSame(['amount' => 42000], $payload['price']);
        $this->assertSame([
            'available' => 2,
            'label' => 'Stok: 2',
            'emptyLabel' => 'Stok kosong',
        ], $payload['stock']);
        $this->assertSame(['quantity' => 0, 'label' => 'Terjual: 0'], $payload['sold']);
        $this->assertSame(route('storefront.shop.checkout'), $payload['checkout']['websiteQris']['url']);
        $this->assertArrayNotHasKey('telegram', $payload['checkout']);
    }
}
