<?php

namespace Tests\Unit\Catalog;

use App\Catalog\CatalogBrowseEntry;
use App\Catalog\CatalogBrowseResult;
use App\Catalog\CatalogCategoryOptions;
use App\Catalog\CatalogCategoryReadModel;
use App\Catalog\CatalogFamilyReadModel;
use App\Catalog\CatalogPagination;
use App\Catalog\CatalogProductReadModel;
use App\Catalog\CatalogProjectionPayload;
use App\Catalog\LocalizedProductCopy;
use InvalidArgumentException;
use Tests\TestCase;

class CatalogProjectionPayloadTest extends TestCase
{
    public function test_a_browse_projection_round_trips_through_the_payload(): void
    {
        $result = $this->browseResult();

        $payload = CatalogProjectionPayload::pack($result);

        $this->assertEquals($result, CatalogProjectionPayload::unpack(CatalogBrowseResult::class, $payload));
    }

    public function test_a_category_projection_round_trips_through_the_payload(): void
    {
        $options = new CatalogCategoryOptions(
            categories: [
                new CatalogCategoryReadModel(id: 4, name: 'AI Tools', slug: 'ai-tools', sortOrder: 2, description: 'Akun premium'),
                new CatalogCategoryReadModel(id: 5, name: 'Streaming', slug: 'streaming', sortOrder: 3),
            ],
            hasUncategorized: true,
        );

        $payload = CatalogProjectionPayload::pack($options);

        $this->assertEquals($options, CatalogProjectionPayload::unpack(CatalogCategoryOptions::class, $payload));
    }

    public function test_the_payload_is_unserialized_without_its_classes(): void
    {
        $payload = CatalogProjectionPayload::pack($this->browseResult());

        $this->assertSame(
            $payload,
            unserialize(serialize($payload), ['allowed_classes' => false]),
            'A cached projection may only hold scalars and arrays.',
        );
    }

    public function test_a_model_outside_the_projection_allowlist_cannot_be_packed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CatalogProjectionPayload::pack(new \stdClass);
    }

    public function test_a_payload_of_another_projection_cannot_be_unpacked(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CatalogProjectionPayload::unpack(
            CatalogCategoryOptions::class,
            CatalogProjectionPayload::pack($this->browseResult()),
        );
    }

    private function browseResult(): CatalogBrowseResult
    {
        $member = new CatalogProductReadModel(
            id: 11,
            name: 'ChatGPT Plus',
            label: '1 Bulan',
            description: 'Akun privat bergaransi',
            copy: LocalizedProductCopy::extract([
                'description' => 'Akun privat bergaransi',
                'description_en' => 'Private account with warranty',
                'warranty_policy' => 'Garansi 7 hari',
            ]),
            merchandising: [
                'product_type' => 'Akun',
                'access_duration' => '1 Bulan',
                'region' => 'Indonesia',
                'account_type' => null,
                'device_limit' => null,
                'included_items' => null,
                'limitations' => null,
                'fulfillment_expectation' => null,
                'warranty_policy' => null,
                'support_policy' => null,
            ],
            categoryId: 4,
            categoryName: 'AI Tools',
            categorySlug: 'ai-tools',
            priceAmount: 35000,
            usdtPriceAmount: 2500000,
            currency: 'IDR',
            stock: 3,
            reservedStock: 1,
            availableStock: 2,
            soldQuantity: 7,
            isActive: true,
            isPublicVisible: true,
            isAvailable: true,
            familyId: 21,
            familyName: 'ChatGPT',
            familySortOrder: 2,
            optionLabel: '1 Bulan',
            imagePath: 'products/chatgpt.png',
            imageUrl: '/storage/products/chatgpt.png',
            createdAt: '2026-09-01T00:00:00.000000Z',
            icon: '🤖',
            telegramImagePath: 'telegram/products/chatgpt.png',
            matchesSearch: true,
        );
        $standalone = new CatalogProductReadModel(
            id: 12,
            name: 'Gmail Fresh',
            label: 'Gmail Fresh',
            description: 'Email baru',
            copy: LocalizedProductCopy::extract(['description' => 'Email baru']),
            merchandising: array_fill_keys([
                'product_type', 'access_duration', 'region', 'account_type', 'device_limit',
                'included_items', 'limitations', 'fulfillment_expectation', 'warranty_policy', 'support_policy',
            ], null),
            categoryId: null,
            categoryName: null,
            categorySlug: null,
            priceAmount: 10000,
            usdtPriceAmount: null,
            currency: 'IDR',
            stock: 0,
            reservedStock: 0,
            availableStock: 0,
            soldQuantity: 0,
            isActive: true,
            isPublicVisible: true,
            isAvailable: false,
            familyId: null,
            familyName: null,
            familySortOrder: 0,
            optionLabel: null,
            imagePath: null,
            imageUrl: null,
            createdAt: null,
            icon: '📩',
        );
        $family = new CatalogFamilyReadModel(
            id: 21,
            name: 'ChatGPT',
            slug: 'chatgpt',
            description: 'Keluarga akun ChatGPT',
            imagePath: 'families/chatgpt.png',
            imageUrl: '/storage/families/chatgpt.png',
            sortOrder: 2,
            categoryId: 4,
            categoryName: 'AI Tools',
            categorySlug: 'ai-tools',
            optionCount: 1,
            availableOptionCount: 1,
            soldOutOptionCount: 0,
            minimumPriceAmount: 35000,
            minimumUsdtPriceAmount: 2500000,
            isAvailable: true,
            createdAt: '2026-09-01T00:00:00.000000Z',
            members: [$member],
            telegramImagePath: 'telegram/families/chatgpt.png',
            matchesSearch: true,
        );

        return new CatalogBrowseResult(
            entries: [CatalogBrowseEntry::family($family), CatalogBrowseEntry::product($standalone)],
            pagination: new CatalogPagination(currentPage: 2, lastPage: 3, total: 41, perPage: 15, from: 16, to: 30),
            families: [$family],
            standaloneProducts: [$standalone],
            search: '',
            category: 'ai-tools',
        );
    }
}
