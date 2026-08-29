<?php

namespace App\Storefront;

final readonly class StorefrontProductCheckoutReadiness
{
    public function __construct(
        public ?string $provider,
        public bool $ready,
        public string $label,
        public string $notice,
    ) {}
}
