<?php

namespace App\Services\Sakurupiah;

use App\Console\Commands\CheckSakurupiahReadiness;
use App\Console\Commands\HealthCheck;
use App\Console\Commands\ReconcileSakurupiahBalanceTopUps;
use App\Console\Commands\ReconcileSakurupiahPayments;
use App\Enums\SakurupiahEnvironment;
use App\Http\Controllers\SakurupiahWebhookController;
use App\Jobs\VerifySakurupiahWebhookPayment;
use App\Services\Admin\AdminPaymentOperations;
use App\Services\Admin\SakurupiahChannelSettings;
use App\Services\ProviderVerification\ProviderVerificationBudgetExhausted;
use App\Services\StoreBalance\TopUp\SakurupiahStoreBalanceTopUpLifecycle;
use App\Services\Telegram\TelegramCheckoutIntents;
use App\Services\Telegram\TelegramOrderIntents;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SakurupiahRequestEvidence
{
    public static function caller(): string
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 48);
        foreach ($frames as $frame) {
            $origin = match ($frame['class'] ?? '') {
                ReconcileSakurupiahPayments::class => 'reconciliation_command',
                ReconcileSakurupiahBalanceTopUps::class => 'top_up_reconciliation_command',
                CheckSakurupiahReadiness::class,
                HealthCheck::class => 'provider_readiness',
                AdminPaymentOperations::class => 'admin_manual_reconciliation',
                SakurupiahChannelSettings::class => 'admin_channel_settings',
                TelegramCheckoutIntents::class => 'telegram_checkout',
                TelegramOrderIntents::class => 'telegram_customer',
                VerifySakurupiahWebhookPayment::class,
                SakurupiahWebhookController::class => 'webhook_verification',
                default => null,
            };
            if ($origin !== null) {
                return $origin;
            }
        }

        $caller = 'unknown';
        foreach ($frames as $frame) {
            $class = $frame['class'] ?? '';
            $method = $frame['function'] ?? '';
            if ($class === SakurupiahCallbackProcessor::class) {
                if ($method === 'reconcilePayment') {
                    return 'payment_reconciliation';
                }
                if ($method === 'processQueuedEvent' || $method === 'processCallback') {
                    return 'callback_verification';
                }
                $caller = 'callback_processor';
            } elseif ($caller === 'unknown') {
                $caller = match ($class) {
                    SakurupiahInvoiceLifecycleService::class => 'invoice_lifecycle',
                    SakurupiahProviderReadiness::class => 'provider_readiness',
                    SakurupiahChannelSettings::class => 'admin_channel_settings',
                    SakurupiahStoreBalanceTopUpLifecycle::class => 'store_balance_top_up',
                    default => 'unknown',
                };
            }
        }

        return $caller;
    }

    public static function merchantFingerprint(SakurupiahEnvironment $environment, string $apiId): ?string
    {
        $key = config('app.key');

        return is_string($key) && $key !== ''
            ? substr(hash_hmac('sha256', $environment->value.':'.$apiId, $key), 0, 16)
            : null;
    }

    /** @param array<string, mixed> $context */
    public static function record(array $context, int $startedAt, ?Response $response = null, ?Throwable $exception = null): void
    {
        try {
            $outcome = $response === null
                ? ['outcome' => self::exceptionOutcome($exception), 'provider_status' => null]
                : self::responseOutcome($response);
            Log::info('sakurupiah.request_attempt', array_merge($context, $outcome, [
                'finished_at' => now()->format('Y-m-d\TH:i:s.uP'),
                'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 3),
                'http_status' => $response?->status(),
            ]));
        } catch (Throwable) {
            // Evidence must not change create ambiguity or lookup retry behavior.
        }
    }

    private static function exceptionOutcome(?Throwable $exception): string
    {
        for ($depth = 0; $exception !== null && $depth < 8; $depth++, $exception = $exception->getPrevious()) {
            if ($exception instanceof ProviderVerificationBudgetExhausted) {
                return 'budget_exhausted';
            }
        }

        return 'transport_unknown';
    }

    /** @return array{outcome: string, provider_status: ?string} */
    private static function responseOutcome(Response $response): array
    {
        $httpStatus = $response->status();
        $payload = json_decode($response->body(), true, 32, JSON_BIGINT_AS_STRING);
        $rawStatus = is_array($payload) ? ($payload['status'] ?? null) : null;
        $status = is_int($rawStatus) ? (string) $rawStatus : null;
        $maximumStatus = (string) PHP_INT_MAX;
        if (is_string($rawStatus)
            && preg_match('/^(0|[1-9][0-9]*)$/D', $rawStatus) === 1
            && (strlen($rawStatus) < strlen($maximumStatus)
                || (strlen($rawStatus) === strlen($maximumStatus) && strcmp($rawStatus, $maximumStatus) <= 0))
        ) {
            $status = $rawStatus;
        }

        $message = is_array($payload) && is_string($payload['message'] ?? null)
            ? strtolower(trim($payload['message']))
            : null;
        $outcome = match (true) {
            $httpStatus === 429 => 'http_rate_rejected',
            $httpStatus >= 500 => 'http_unavailable',
            $status !== null && $status !== '200'
                && $message === 'permintaan terlalu cepat silahkan coba lagi nanti.' => 'application_rate_rejected',
            $status === '404' => 'application_not_found',
            $status !== null && (int) $status >= 500 => 'application_unavailable',
            $status !== null && $status !== '200' => 'application_rejected_unknown',
            $httpStatus === 404 => 'http_not_found',
            ! $response->successful() => 'http_rejected_unknown',
            json_last_error() !== JSON_ERROR_NONE => 'invalid_json',
            $status === null => 'invalid_envelope',
            default => 'application_accepted',
        };

        return ['outcome' => $outcome, 'provider_status' => $status];
    }
}
