<?php

namespace Tests\Feature;

use App\Catalog\CatalogProductReadModel;
use App\Models\Product;
use App\Services\Catalog\CatalogBrowse;
use App\Storefront\StorefrontCatalogProductPresenter;
use App\Storefront\StorefrontProductPresenter;
use App\Storefront\StorefrontUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontCatalogProductPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_presenter_maps_discovery_facts_without_loading_models_or_settings(): void
    {
        $product = $this->readModel([
            'name' => 'Canva Premium',
            'description' => "\n\nDesain premium siap pakai.\nDetail lain.",
            'availableStock' => 3,
            'soldQuantity' => 8,
            'imagePath' => 'products/canva.jpg',
            'imageUrl' => '/storage/products/canva.jpg',
            'optionLabel' => 'Canva 30 Hari',
        ]);

        $presentation = app(StorefrontCatalogProductPresenter::class)->present($product);

        $this->assertSame($product->id, $presentation->id);
        $this->assertSame('7', $presentation->categoryKey);
        $this->assertSame($product->name, $presentation->name);
        $this->assertSame($product->description, $presentation->description);
        $this->assertSame('Desain premium siap pakai.', $presentation->descriptionSnippet);
        $this->assertSame(42_000, $presentation->priceAmount);
        $this->assertSame(1_500, $presentation->usdtPriceAmount);
        $this->assertSame('IDR', $presentation->currency);
        $this->assertSame(3, $presentation->stockAvailable);
        $this->assertSame(8, $presentation->soldQuantity);
        $this->assertTrue($presentation->isAvailable);
        $this->assertSame('Tersedia', $presentation->availabilityLabel);
        $this->assertSame('Stok: 3', $presentation->stockLabel);
        $this->assertSame('Terjual: 8', $presentation->soldLabel);
        $this->assertSame('/storage/products/canva.jpg', $presentation->cover->imageUrl);
        $this->assertSame('CANVA PREMIUM', $presentation->cover->theme['desc']);
        $this->assertSame('Canva 30 Hari', $presentation->familyOptionLabel);
        $this->assertSame(route('storefront.products.show', ['product' => $product->id]), $presentation->productUrl);
        $this->assertSame(route('storefront.shop.checkout.product', ['product' => $product->id]), $presentation->checkoutUrl);
        $this->assertSame(route('storefront.waitlist.store', ['product' => $product->id]), $presentation->waitlistUrl);
        $this->assertFalse($presentation->canJoinWaitlist);
    }

    public function test_catalog_presenter_preserves_unavailable_labels_and_default_image(): void
    {
        $presentation = app(StorefrontCatalogProductPresenter::class)->present($this->readModel([
            'availableStock' => 0,
            'isAvailable' => false,
            'imagePath' => null,
            'imageUrl' => null,
        ]));

        $this->assertFalse($presentation->isAvailable);
        $this->assertSame('Habis', $presentation->availabilityLabel);
        $this->assertSame('Stok kosong', $presentation->stockLabel);
        $this->assertSame('Terjual: 2', $presentation->soldLabel);
        $this->assertSame(config('storefront.product_placeholder'), $presentation->cover->imageUrl);
        $this->assertTrue($presentation->canJoinWaitlist);
        $this->assertSame(config('storefront.waitlist.cta'), $presentation->waitlistCtaLabel);
    }

    public function test_catalog_and_full_presenters_share_deterministic_public_product_conversion(): void
    {
        $product = Product::factory()->create([
            'name' => 'Claude Pro',
            'description' => "\n\nAkun private siap pakai.\nGaransi login.",
            'product_type' => 'Akun premium',
            'access_duration' => '30 hari',
            'region' => 'Indonesia',
            'account_type' => 'Private',
            'device_limit' => '1 perangkat',
            'included_items' => "Akun siap pakai\nPanduan login",
            'limitations' => "Tidak untuk dijual kembali\nSatu perangkat",
            'fulfillment_expectation' => 'Dikirim setelah pembayaran terverifikasi.',
            'warranty_policy' => 'Garansi login 7 hari.',
            'support_policy' => 'Support pada jam operasional.',
            'image_path' => '/assets/claude.jpg',
            'stock' => 4,
            'reserved_stock' => 1,
            'usdt_price_amount' => 1_250,
        ]);
        $readModel = app(CatalogBrowse::class)->browsePublic()->standaloneProducts[0];

        $full = app(StorefrontProductPresenter::class)->present(
            $product,
            stockAvailable: $readModel->availableStock,
            soldQuantity: $readModel->soldQuantity,
        );
        $catalog = app(StorefrontCatalogProductPresenter::class)->present($readModel);

        $this->assertSame($full->descriptionSnippet, $catalog->descriptionSnippet);
        $this->assertSame($full->merchandising->toPublicPayload(), $catalog->merchandising->toPublicPayload());
        $this->assertSame($full->cover->theme, $catalog->cover->theme);
        $this->assertSame($full->cover->imageUrl, $catalog->cover->imageUrl);
        $this->assertSame($full->stockAvailable, $catalog->stockAvailable);
        $this->assertSame($full->soldQuantity, $catalog->soldQuantity);
        $this->assertSame($full->isAvailable, $catalog->isAvailable);
        $this->assertSame($full->availabilityLabel, $catalog->availabilityLabel);
        $this->assertSame($full->stockLabel, $catalog->stockLabel);
        $this->assertSame($full->soldLabel, $catalog->soldLabel);
        $this->assertSame($full->productUrl, $catalog->productUrl);
        $this->assertSame($product->id, $catalog->id);
    }

    public function test_id_url_helpers_match_existing_product_url_callers(): void
    {
        $product = Product::factory()->create();
        $urls = app(StorefrontUrl::class);
        $returnUrl = '/shop#products';

        $this->assertSame(
            $urls->productUrl($product, $returnUrl),
            $urls->productUrlForId($product->id, $returnUrl),
        );
        $this->assertSame(
            $urls->checkoutProductUrl($product, $returnUrl),
            $urls->checkoutProductUrlForId($product->id, $returnUrl),
        );
        $this->assertSame(
            route('storefront.shop.checkout.product', ['product' => $product->id]),
            $urls->checkoutProductUrlForId($product->id),
        );
        $this->assertSame(
            route('storefront.waitlist.store', ['product' => $product->id]),
            $urls->waitlistUrlForId($product->id),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function readModel(array $overrides = []): CatalogProductReadModel
    {
        $values = array_merge([
            'id' => 41,
            'name' => 'Catalog Product',
            'label' => 'Catalog Product',
            'description' => 'Product description.',
            'merchandising' => [
                'product_type' => 'Akun premium',
                'access_duration' => '30 hari',
                'region' => 'Indonesia',
                'account_type' => 'Private',
                'device_limit' => '1 perangkat',
                'included_items' => "Akun siap pakai\nPanduan login",
                'limitations' => 'Tidak untuk dijual kembali',
                'fulfillment_expectation' => 'Dikirim setelah pembayaran terverifikasi.',
                'warranty_policy' => 'Garansi login 7 hari.',
                'support_policy' => 'Support pada jam operasional.',
            ],
            'categoryId' => 7,
            'categoryName' => 'AI',
            'categorySlug' => 'ai',
            'priceAmount' => 42_000,
            'usdtPriceAmount' => 1_500,
            'currency' => 'IDR',
            'stock' => 4,
            'reservedStock' => 1,
            'availableStock' => 3,
            'soldQuantity' => 2,
            'isActive' => true,
            'isPublicVisible' => true,
            'isAvailable' => true,
            'familyId' => 3,
            'familyName' => 'AI Tools',
            'familySortOrder' => 1,
            'optionLabel' => null,
            'imagePath' => '/assets/catalog-product.jpg',
            'imageUrl' => '/assets/catalog-product.jpg',
            'createdAt' => null,
            'matchesSearch' => false,
        ], $overrides);

        return new CatalogProductReadModel(
            id: $values['id'],
            name: $values['name'],
            label: $values['label'],
            description: $values['description'],
            merchandising: $values['merchandising'],
            categoryId: $values['categoryId'],
            categoryName: $values['categoryName'],
            categorySlug: $values['categorySlug'],
            priceAmount: $values['priceAmount'],
            usdtPriceAmount: $values['usdtPriceAmount'],
            currency: $values['currency'],
            stock: $values['stock'],
            reservedStock: $values['reservedStock'],
            availableStock: $values['availableStock'],
            soldQuantity: $values['soldQuantity'],
            isActive: $values['isActive'],
            isPublicVisible: $values['isPublicVisible'],
            isAvailable: $values['isAvailable'],
            familyId: $values['familyId'],
            familyName: $values['familyName'],
            familySortOrder: $values['familySortOrder'],
            optionLabel: $values['optionLabel'],
            imagePath: $values['imagePath'],
            imageUrl: $values['imageUrl'],
            createdAt: $values['createdAt'],
            matchesSearch: $values['matchesSearch'],
        );
    }
}
