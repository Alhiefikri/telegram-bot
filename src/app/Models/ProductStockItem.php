<?php

namespace App\Models;

use App\Enums\CredentialFormat;
use App\Services\Credentials\StructuredCredentialPayload;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

#[Fillable(['product_id', 'order_item_id', 'retired_at', 'retired_reason', 'secret', 'credential_format', 'credential_payload'])]
class ProductStockItem extends Model
{
    protected static function booted(): void
    {
        static::saving(function (ProductStockItem $stockItem): void {
            $format = $stockItem->getAttribute('credential_format');
            if ($format === null) {
                $format = CredentialFormat::Legacy;
            }
            if (! $format instanceof CredentialFormat) {
                try {
                    $format = CredentialFormat::from((string) $format);
                } catch (\ValueError) {
                    throw new InvalidArgumentException('Product Stock Item credential format is invalid.');
                }
            }

            if ($format === CredentialFormat::Legacy) {
                if ($stockItem->getAttribute('credential_payload') !== null) {
                    throw new InvalidArgumentException('Legacy Product Stock Item cannot have a structured payload.');
                }
                if ($stockItem->getAttribute('secret') === null) {
                    throw new InvalidArgumentException('Legacy Product Stock Item requires a secret.');
                }

                return;
            }

            if ($stockItem->getAttribute('secret') !== null) {
                throw new InvalidArgumentException('Structured Product Stock Item cannot have a legacy secret.');
            }

            $payload = $stockItem->getAttribute('credential_payload');
            if (! is_array($payload)) {
                throw new InvalidArgumentException('Structured Product Stock Item requires a valid payload.');
            }

            $stockItem->setAttribute(
                'credential_payload',
                StructuredCredentialPayload::fromArray($payload)->toArray(),
            );
        });
    }

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'order_item_id' => 'integer',
            'retired_at' => 'datetime',
            'credential_format' => CredentialFormat::class,
            'credential_payload' => 'encrypted:array',
            'secret' => 'encrypted',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function credentialIdentities()
    {
        return $this->hasMany(CredentialIdentity::class, 'product_stock_item_id');
    }
}
