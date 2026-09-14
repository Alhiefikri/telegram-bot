<?php

namespace App\Storefront;

use App\Models\Product;
use App\Models\ProductFamily;

final class StorefrontSitemap
{
    public function __construct(private readonly StorefrontUrl $urls) {}

    public const MAX_URLS = 1_000;

    /**
     * @return list<array{loc:string,lastmod:?string}>
     */
    public function entries(): array
    {
        $entries = array_map(
            static fn (string $routeName): array => [
                'loc' => route($routeName),
                'lastmod' => null,
            ],
            [
                'storefront.home',
                'storefront.shop.index',
                'storefront.guides.index',
                'storefront.login-guide',
                'storefront.policies.privacy',
                'storefront.policies.terms',
                'storefront.policies.refund',
                'storefront.policies.recovery',
            ],
        );
        $familyLimit = max(0, $this->maxUrls(count($entries)) - count($entries));
        foreach (ProductFamily::query()
            ->publicVisible()
            ->select(['id', 'slug', 'updated_at'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($familyLimit)
            ->get() as $family) {
            $entries[] = [
                'loc' => $this->urls->familyUrl($family->slug),
                'lastmod' => $family->updated_at?->toAtomString(),
            ];
        }

        $productLimit = max(0, $this->maxUrls(count($entries)) - count($entries));
        foreach (Product::query()
            ->publicVisible()
            ->select(['id', 'updated_at'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($productLimit)
            ->get() as $product) {
            $entries[] = [
                'loc' => route('storefront.products.show', ['product' => $product->getKey()]),
                'lastmod' => $product->updated_at?->toAtomString(),
            ];
        }

        return $entries;
    }

    public function render(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($this->entries() as $entry) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>'.$this->escape($entry['loc'])."</loc>\n";
            if ($entry['lastmod'] !== null) {
                $xml .= '    <lastmod>'.$this->escape($entry['lastmod'])."</lastmod>\n";
            }
            $xml .= "  </url>\n";
        }

        return $xml."</urlset>\n";
    }

    private function maxUrls(int $minimum): int
    {
        $configured = (int) config('storefront.sitemap.max_urls', self::MAX_URLS);

        return max($minimum, min(self::MAX_URLS, max(1, $configured)));
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
