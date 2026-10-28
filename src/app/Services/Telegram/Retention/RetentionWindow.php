<?php

namespace App\Services\Telegram\Retention;

use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class RetentionWindow
{
    public const POLICY = 'notification-retention-s09-v1';

    public const TABLES = [
        'effects' => 'telegram_outbound_effects',
        'supplier-health' => 'supplier_health_events',
        'stock-events' => 'stock_notification_events',
        'stock-recipients' => 'stock_notification_recipients',
        'price-events' => 'product_price_change_events',
        'price-recipients' => 'product_price_change_notification_recipients',
        'broadcasts' => 'telegram_broadcasts',
        'broadcast-recipients' => 'telegram_broadcast_recipients',
    ];

    public const CAPS = ['page' => 100, 'roots' => 1000, 'dependencies' => 1000, 'per_root' => 200, 'seconds' => 10, 'details' => 20];

    public string $family;

    public string $table;

    public CarbonImmutable $asOf;

    public string $timezone;

    public int $horizon;

    public array $budgets;

    public ?string $after;

    public ?string $stop;

    public function __construct(array $input)
    {
        $this->family = (string) ($input['family'] ?? '');
        $this->table = self::TABLES[$this->family] ?? throw new InvalidArgumentException;
        if (($input['policy-version'] ?? '') !== self::POLICY) {
            throw new InvalidArgumentException;
        }
        $this->timezone = (string) ($input['timezone'] ?? '');
        if (! in_array($this->timezone, DateTimeZone::listIdentifiers(), true) || $this->timezone !== config('app.timezone')) {
            throw new InvalidArgumentException;
        }
        $asOf = (string) ($input['as-of'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D', $asOf)) {
            throw new InvalidArgumentException;
        }
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:sP', $asOf);
        if (! $parsed || $parsed->format('Y-m-d\TH:i:sP') !== $asOf || $parsed->setTimezone($this->timezone)->format('P') !== $parsed->format('P')) {
            throw new InvalidArgumentException;
        }
        $this->asOf = $parsed->setTimezone($this->timezone);
        $this->horizon = (int) ($input['horizon'] ?? 0);
        if (! in_array((string) ($input['horizon'] ?? ''), ['30', '90', '180'], true)) {
            throw new InvalidArgumentException;
        }
        $budgets = [];
        foreach (self::CAPS as $key => $cap) {
            $value = (string) ($input[$key] ?? $cap);
            if (! ctype_digit($value) || (int) $value < 1 || (int) $value > $cap) {
                throw new InvalidArgumentException;
            }
            $budgets[$key] = (int) $value;
        }
        if ($budgets['per_root'] < 2 || $budgets['dependencies'] < 2) {
            throw new InvalidArgumentException;
        }
        $this->budgets = $budgets;
        $this->after = $this->key($input['after'] ?? null);
        $this->stop = $this->key($input['stop'] ?? null);
        if (! empty($input['resume'])) {
            if (strlen((string) $input['resume']) > 2048) {
                throw new InvalidArgumentException;
            }
            $resume = json_decode(base64_decode((string) $input['resume'], true) ?: '', true, 16, JSON_THROW_ON_ERROR);
            if ($resume !== $this->manifest($resume['after'] ?? null, $resume['stop'] ?? null) || $this->after !== null || $this->stop !== null) {
                throw new InvalidArgumentException;
            }
            $this->after = $this->key($resume['after']);
            $this->stop = $this->key($resume['stop']);
        }
        if ($this->after !== null && $this->stop !== null && $this->compare($this->after, $this->stop) > 0) {
            throw new InvalidArgumentException;
        }
    }

    public function key(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = (string) $value;
        $uuid = in_array($this->family, ['effects', 'supplier-health'], true);
        if ($uuid ? ! preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D', $value) : (! preg_match('/^[1-9][0-9]{0,18}$/D', $value) || $this->compare($value, (string) PHP_INT_MAX) > 0)) {
            throw new InvalidArgumentException;
        }

        return $value;
    }

    public function compare(string $a, string $b): int
    {
        return strlen($a) <=> strlen($b) ?: strcmp($a, $b);
    }

    public function manifest(?string $after, ?string $stop): array
    {
        return ['schema' => 1, 'family' => $this->family, 'policy' => self::POLICY, 'as_of' => $this->asOf->toIso8601String(), 'timezone' => $this->timezone, 'horizon' => $this->horizon, 'after' => $after, 'stop' => $stop];
    }
}
