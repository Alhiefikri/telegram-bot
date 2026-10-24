<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'singleton_key',
    'enabled',
    'image_path',
    'qr_x',
    'qr_y',
    'qr_width',
    'qr_height',
    'version',
])]
class PaymentQrTemplateSetting extends Model
{
    public const SINGLETON_KEY = 'payment-qr-template';

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'qr_x' => 'integer',
            'qr_y' => 'integer',
            'qr_width' => 'integer',
            'qr_height' => 'integer',
            'version' => 'integer',
        ];
    }

    public static function current(): ?self
    {
        return self::query()
            ->where('singleton_key', self::SINGLETON_KEY)
            ->first();
    }

    public function isConfigured(): bool
    {
        return is_string($this->image_path)
            && trim($this->image_path) !== ''
            && (int) $this->qr_width > 0
            && (int) $this->qr_height > 0
            && (int) $this->qr_x >= 0
            && (int) $this->qr_y >= 0;
    }
}
