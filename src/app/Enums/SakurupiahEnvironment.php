<?php

namespace App\Enums;

enum SakurupiahEnvironment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    public static function runtime(): self
    {
        return app()->environment('production') ? self::Production : self::Sandbox;
    }

    public function configKey(string $field): string
    {
        return 'services.sakurupiah.'.$this->value.'_'.$field;
    }

    public function documentedBaseUrl(): string
    {
        return match ($this) {
            self::Sandbox => 'https://sakurupiah.id/api-sanbox/',
            self::Production => 'https://sakurupiah.id/api/',
        };
    }

    public function label(): string
    {
        return $this->value;
    }

    public function isRuntimeAllowed(): bool
    {
        return match ($this) {
            self::Sandbox => app()->environment(['local', 'testing']),
            self::Production => app()->environment('production'),
        };
    }
}
