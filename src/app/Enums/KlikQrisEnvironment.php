<?php

namespace App\Enums;

enum KlikQrisEnvironment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    public static function runtime(): self
    {
        return app()->environment('production') ? self::Production : self::Sandbox;
    }

    public function isRuntimeAllowed(): bool
    {
        return match ($this) {
            self::Sandbox => app()->environment(['local', 'testing']),
            self::Production => app()->environment('production'),
        };
    }

    public function configKey(string $field): string
    {
        return 'services.klikqris.'.$this->value.'_'.$field;
    }

    public function routeName(): string
    {
        return 'klikqris.'.$this->value.'.webhook';
    }

    public function callbackPath(): string
    {
        return match ($this) {
            self::Sandbox => '/api/webhook/klikqris/sandbox',
            self::Production => '/api/webhook/klikqris',
        };
    }

    public function label(): string
    {
        return $this === self::Production ? 'production' : 'sandbox';
    }
}
