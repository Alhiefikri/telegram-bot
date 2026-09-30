<?php

namespace App\Catalog;

/**
 * The stored icon values selected for one catalog presentation. Both values
 * stay nullable so a renderer can apply its normal Unicode default without
 * rewriting an Order snapshot.
 */
final readonly class CatalogIconSelection
{
    public function __construct(
        public ?string $icon,
        public ?string $customEmojiId,
    ) {}
}
