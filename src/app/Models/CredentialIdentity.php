<?php

namespace App\Models;

use App\Services\Credentials\CredentialIdentityKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['identity_kind', 'fingerprint', 'product_id', 'product_stock_item_id', 'first_seen_at'])]
class CredentialIdentity extends Model
{
    public const EMAIL = CredentialIdentityKind::Email;

    public const AUTH_TOKEN = CredentialIdentityKind::AuthToken;

    public const KIND_EMAIL = self::EMAIL;

    public const KIND_AUTH_TOKEN = self::AUTH_TOKEN;

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'product_stock_item_id' => 'integer',
            'first_seen_at' => 'datetime',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function productStockItem()
    {
        return $this->belongsTo(ProductStockItem::class);
    }
}
