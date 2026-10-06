<?php

namespace App\Enums;

/** Severity of one durable balance operations alert episode. */
enum BalanceAlertSeverity: string
{
    case Warning = 'warning';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Warning => 'Peringatan',
            self::Critical => 'Kritis',
        };
    }
}
