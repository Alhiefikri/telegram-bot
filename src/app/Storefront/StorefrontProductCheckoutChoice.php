<?php

namespace App\Storefront;

final readonly class StorefrontProductCheckoutChoice
{
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public bool $enabled,
        public ?string $provider = null,
    ) {}
}
