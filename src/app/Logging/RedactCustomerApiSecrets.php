<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;

final class RedactCustomerApiSecrets
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(function (LogRecord $record): LogRecord {
            $privateRequest = app()->bound('request') && request()->is('api/v1', 'api/v1/*');
            if ($privateRequest) {
                return $record->with(message: 'Customer API event (details suppressed).', context: [], extra: []);
            }

            return $record->with(message: self::text($record->message), context: $this->scrub($record->context), extra: $this->scrub($record->extra));
        });
    }

    public static function text(string $text): string
    {
        return preg_replace('/capi_[a-f0-9]{32}(?:\.|%2e)[a-f0-9]{64}/i', '[redacted-customer-api-key]', $text) ?? '[redacted]';
    }

    private function scrub(array $values, int $depth = 0): array
    {
        if ($depth > 8) {
            return ['redacted' => true];
        }
        foreach ($values as $key => $value) {
            if (preg_match('/authorization|cookie|secret|password|credential|payload|request|response|body|token/i', (string) $key)) {
                $values[$key] = '[redacted]';
            } elseif (is_string($value)) {
                $values[$key] = self::text($value);
            } elseif (is_array($value)) {
                $values[$key] = $this->scrub($value, $depth + 1);
            } elseif (is_object($value)) {
                // Do not invoke arbitrary serializers or exception traces with secret-bearing arguments.
                $values[$key] = '['.$value::class.']';
            }
        }

        return $values;
    }
}
