<?php

namespace App\Storefront;

final readonly class StorefrontPageMetadata
{
    /**
     * @param  list<array<string, mixed>>  $jsonLd
     * @param  list<array{name:string,url:string}>  $breadcrumbs
     */
    public function __construct(
        public string $title,
        public string $description,
        public string $robots,
        public ?string $canonicalUrl,
        public string $ogType,
        public string $ogImage,
        public string $ogImageAlt,
        public string $locale,
        public array $jsonLd = [],
        public array $breadcrumbs = [],
    ) {}

    public function htmlLocale(): string
    {
        return strtolower(explode('_', $this->locale, 2)[0]);
    }
}
