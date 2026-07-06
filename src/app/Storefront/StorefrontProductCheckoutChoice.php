<?php

namespace App\Storefront;

final readonly class StorefrontProductCheckoutChoice
{
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public string $url,
        public string $method,
        public bool $enabled,
        public ?string $browserAction = null,
        public ?int $productId = null,
    ) {}

    /**
     * @return array<string, bool|int|string>
     */
    public function toModalPayload(): array
    {
        $payload = [
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'url' => $this->url,
            'method' => $this->method,
            'enabled' => $this->enabled,
        ];

        if ($this->browserAction !== null) {
            $payload['browserAction'] = $this->browserAction;
        }

        if ($this->productId !== null) {
            $payload['productId'] = $this->productId;
        }

        return $payload;
    }
}
