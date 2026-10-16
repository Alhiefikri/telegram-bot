<?php

namespace App\Storefront;

use App\Catalog\CatalogFamilyReadModel;
use App\Catalog\CatalogProductReadModel;
use App\Models\Product;
use Illuminate\Support\Facades\Vite;

final class StorefrontSeo
{
    public const INDEX_FOLLOW = 'index, follow';

    public const NOINDEX_FOLLOW = 'noindex, follow';

    public const NOINDEX_NOFOLLOW_NOARCHIVE = 'noindex, nofollow, noarchive';

    public function home(string $canonicalUrl, bool $hasSearch = false): StorefrontPageMetadata
    {
        $copy = config('storefront');
        $brand = $this->brand();
        $faq = $this->faqJsonLd($copy['faq'] ?? []);

        return $this->publicPage(
            title: str_replace(':brand', $brand, (string) ($copy['meta']['title'] ?? $brand)),
            description: (string) ($copy['meta']['description'] ?? ''),
            canonicalUrl: $canonicalUrl,
            robots: $hasSearch ? self::NOINDEX_FOLLOW : self::INDEX_FOLLOW,
            jsonLd: $faq === null ? [] : [$faq],
        );
    }

    public function catalog(StorefrontCatalogQuery $query, string $canonicalUrl): StorefrontPageMetadata
    {
        $brand = $this->brand();
        $breadcrumbs = $this->catalogBreadcrumbs();

        return $this->publicPage(
            title: 'Belanja Produk Digital | '.$brand,
            description: 'Katalog produk digital '.$brand.' untuk membeli produk digital dan akun premium langsung dari web dengan QRIS, invoice, dan akses pesanan mandiri.',
            canonicalUrl: $canonicalUrl,
            robots: $query->search !== '' ? self::NOINDEX_FOLLOW : self::INDEX_FOLLOW,
            jsonLd: [$this->breadcrumbJsonLd($breadcrumbs)],
            breadcrumbs: $breadcrumbs,
        );
    }

    public function family(CatalogFamilyReadModel $family, string $canonicalUrl): StorefrontPageMetadata
    {
        $brand = $this->brand();
        $description = trim((string) $family->description);
        if ($description === '') {
            $description = $family->name.' menawarkan '.$family->optionCount.' pilihan produk di '.$brand.'.';
        }
        $breadcrumbs = $this->familyBreadcrumbs($family->name, $canonicalUrl);
        $items = [];
        foreach ($family->members as $index => $member) {
            if (! $member instanceof CatalogProductReadModel) {
                continue;
            }
            $items[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $member->label,
                'url' => route('storefront.products.show', ['product' => $member->id]),
            ];
        }
        $jsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $family->name.' — Pilihan Produk',
            'description' => $description,
            'url' => $canonicalUrl,
            'mainEntity' => [
                '@type' => 'ItemList',
                'numberOfItems' => count($items),
                'itemListElement' => $items,
            ],
        ];

        return $this->publicPage(
            title: $family->name.' — Pilihan Produk | '.$brand,
            description: $description,
            canonicalUrl: $canonicalUrl,
            imageUrl: $family->imageUrl,
            imageAlt: $family->name.' | '.$brand,
            jsonLd: [$jsonLd, $this->breadcrumbJsonLd($breadcrumbs)],
            breadcrumbs: $breadcrumbs,
        );
    }

    public function product(
        Product $product,
        StorefrontProductPresentation $presentation,
        string $canonicalUrl,
    ): StorefrontPageMetadata {
        $brand = $this->brand();
        $breadcrumbs = $this->productBreadcrumbs($product->name, $canonicalUrl);
        $currency = strtoupper(trim((string) $product->currency));
        if (preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
            $currency = 'IDR';
        }

        $productJsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => (string) $product->name,
            'description' => $presentation->descriptionSnippet,
            'image' => [$this->imageUrl($presentation->cover->imageUrl) ?? $this->logoImageUrl()],
            'url' => $canonicalUrl,
            'offers' => [
                '@type' => 'Offer',
                'url' => $canonicalUrl,
                'priceCurrency' => $currency,
                'price' => max(0, (int) $product->price_amount),
                'availability' => $presentation->isAvailable
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
            ],
        ];

        return $this->publicPage(
            title: $product->name.' | '.$brand,
            description: $presentation->descriptionSnippet,
            canonicalUrl: $canonicalUrl,
            robots: self::INDEX_FOLLOW,
            ogType: 'product',
            imageUrl: $presentation->cover->imageUrl,
            imageAlt: $product->name.' | '.$brand,
            jsonLd: [$productJsonLd, $this->breadcrumbJsonLd($breadcrumbs)],
            breadcrumbs: $breadcrumbs,
        );
    }

    public function guides(string $canonicalUrl): StorefrontPageMetadata
    {
        $brand = $this->brand();

        return $this->publicPage(
            title: 'Pusat Panduan | '.$brand,
            description: 'Pilih panduan penggunaan produk '.$brand.', termasuk login ChatGPT melalui Outlook, iCloud, dan MFA.',
            canonicalUrl: $canonicalUrl,
        );
    }

    public function loginGuide(string $canonicalUrl, bool $privateContext = false): StorefrontPageMetadata
    {
        $brand = $this->brand();

        return $this->publicPage(
            title: 'Panduan Login ChatGPT | '.$brand,
            description: 'Panduan masuk dan mengamankan akun ChatGPT dari '.$brand.' memakai Outlook, iCloud, dan Google Authenticator.',
            canonicalUrl: $canonicalUrl,
            robots: $privateContext ? self::NOINDEX_NOFOLLOW_NOARCHIVE : self::INDEX_FOLLOW,
        );
    }

    public function apiDocs(string $canonicalUrl): StorefrontPageMetadata
    {
        $brand = $this->brand();

        return $this->publicPage(
            title: 'Dokumentasi API Supplier | '.$brand,
            description: 'Dokumentasi API supplier '.$brand.': endpoint saldo, katalog, dan pesanan dengan kutipan harga, idempotensi, contoh cURL, Python, JavaScript, dan PHP, serta berkas OpenAPI dan Markdown untuk mesin.',
            canonicalUrl: $canonicalUrl,
        );
    }

    /**
     * @param  array<string, mixed>  $policy
     */
    public function policy(string $key, array $policy, string $canonicalUrl): StorefrontPageMetadata
    {
        return $this->publicPage(
            title: (string) ($policy['title'] ?? $key).' | '.$this->brand(),
            description: (string) ($policy['description'] ?? ''),
            canonicalUrl: $canonicalUrl,
        );
    }

    public function privatePage(string $title, string $description): StorefrontPageMetadata
    {
        return new StorefrontPageMetadata(
            title: $title,
            description: $description,
            robots: self::NOINDEX_NOFOLLOW_NOARCHIVE,
            canonicalUrl: null,
            ogType: 'website',
            ogImage: $this->logoImageUrl(),
            ogImageAlt: 'Logo '.$this->brand(),
            locale: $this->locale(),
        );
    }

    public function brand(): string
    {
        return 'Lhie Store';
    }

    /**
     * @param  list<array<string, mixed>>  $jsonLd
     * @param  list<array{name:string,url:string}>  $breadcrumbs
     */
    private function publicPage(
        string $title,
        string $description,
        string $canonicalUrl,
        string $robots = self::INDEX_FOLLOW,
        string $ogType = 'website',
        ?string $imageUrl = null,
        ?string $imageAlt = null,
        array $jsonLd = [],
        array $breadcrumbs = [],
    ): StorefrontPageMetadata {
        return new StorefrontPageMetadata(
            title: $title,
            description: $description,
            robots: $robots,
            canonicalUrl: $canonicalUrl,
            ogType: $ogType,
            ogImage: $this->imageUrl($imageUrl) ?? $this->logoImageUrl(),
            ogImageAlt: $imageAlt ?: 'Logo '.$this->brand(),
            locale: $this->locale(),
            jsonLd: $jsonLd,
            breadcrumbs: $breadcrumbs,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $faqItems
     * @return array<string, mixed>|null
     */
    private function faqJsonLd(array $faqItems): ?array
    {
        $entities = [];
        foreach ($faqItems as $faq) {
            $question = is_array($faq) ? trim((string) ($faq['question'] ?? '')) : '';
            $answer = is_array($faq) ? trim((string) ($faq['answer'] ?? '')) : '';
            if ($question === '' || $answer === '') {
                continue;
            }

            $entities[] = [
                '@type' => 'Question',
                'name' => $question,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $answer,
                ],
            ];
        }

        return $entities === [] ? null : [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $entities,
        ];
    }

    /**
     * @return list<array{name:string,url:string}>
     */
    private function catalogBreadcrumbs(): array
    {
        return [
            ['name' => 'Beranda', 'url' => route('storefront.home')],
            ['name' => 'Katalog', 'url' => route('storefront.shop.index')],
        ];
    }

    /**
     * @return list<array{name:string,url:string}>
     */
    private function familyBreadcrumbs(string $name, string $canonicalUrl): array
    {
        return [
            ...$this->catalogBreadcrumbs(),
            ['name' => $name, 'url' => $canonicalUrl],
        ];
    }

    /**
     * @return list<array{name:string,url:string}>
     */
    private function productBreadcrumbs(string $name, string $canonicalUrl): array
    {
        return [
            ...$this->catalogBreadcrumbs(),
            ['name' => $name, 'url' => $canonicalUrl],
        ];
    }

    /**
     * @param  list<array{name:string,url:string}>  $breadcrumbs
     * @return array<string, mixed>
     */
    private function breadcrumbJsonLd(array $breadcrumbs): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(
                static fn (array $breadcrumb, int $index): array => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $breadcrumb['name'],
                    'item' => $breadcrumb['url'],
                ],
                $breadcrumbs,
                array_keys($breadcrumbs),
            ),
        ];
    }

    private function logoImageUrl(): string
    {
        return $this->imageUrl(Vite::asset('resources/images/lhie-store-logo-512.png'))
            ?? url('/assets/lhie-store-logo-512.png');
    }

    private function imageUrl(?string $candidate): ?string
    {
        $candidate = trim((string) $candidate);
        if ($candidate === '' || str_starts_with($candidate, '//')) {
            return null;
        }

        if (str_starts_with($candidate, '/')) {
            $path = parse_url($candidate, PHP_URL_PATH);
            if (! is_string($path) || $path === '' || str_starts_with($path, '//')) {
                return null;
            }

            return url($path);
        }

        if (preg_match('/\Ahttps:\/\//i', $candidate) !== 1) {
            return null;
        }

        $parts = parse_url($candidate);
        if (! is_array($parts) || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = (string) $parts['host'];
        if ($host === '') {
            return null;
        }

        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';
        $path = isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : '/';

        return 'https://'.$host.$port.($path === '' ? '/' : $path);
    }

    private function locale(): string
    {
        $locale = str_replace('-', '_', trim((string) config('storefront.locale', app()->getLocale())));
        if ($locale === '') {
            $locale = 'id';
        }

        [$language, $country] = array_pad(explode('_', $locale, 2), 2, null);
        $language = strtolower($language);
        $country = $country !== null && $country !== '' ? strtoupper($country) : strtoupper($language);

        return $language.'_'.$country;
    }
}
