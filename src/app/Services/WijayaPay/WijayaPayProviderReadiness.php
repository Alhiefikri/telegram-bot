<?php

namespace App\Services\WijayaPay;

use App\Exceptions\CheckoutPaymentUnavailable;
use App\Models\ManualPaymentSetting;
use App\Support\PaymentProvider;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Owns the WijayaPay readiness gate and the single cached channel
 * observation. No provider HTTP is made inside checkout preparation locks.
 */
final class WijayaPayProviderReadiness
{
    private const CACHE_KEY_PREFIX = 'wijayapay.readiness';

    private const CACHE_VERSION_KEY = 'wijayapay.readiness.version';

    private const DEFAULT_CACHE_SECONDS = 60;

    private const MAX_CACHE_SECONDS = 300;

    private const CALLBACK_ROUTE_NAME = 'wijayapay.webhook';

    private const CALLBACK_ROUTE_URI = 'api/webhook/wijayapay';

    private const PRODUCTION_BASE_HOST = 'gateway.wijayapay.com';

    public function configurationReport(): WijayaPayProviderReadinessReport
    {
        return $this->evaluateConfiguration(includeOperationalPrerequisites: false);
    }

    public function observe(): WijayaPayProviderReadinessReport
    {
        $configuration = $this->evaluateConfiguration(includeOperationalPrerequisites: true);
        if (! $configuration->isReady()) {
            return $configuration;
        }

        $fingerprint = $configuration->configurationFingerprint() ?? '';
        $cacheKey = $this->cacheKey($fingerprint);

        try {
            $cached = $this->cachedReport(Cache::get($cacheKey), $fingerprint);
        } catch (Throwable $exception) {
            return $this->notReady(
                configuration: $configuration,
                reason: 'cache_unavailable',
                retryAt: now()->getTimestamp() + 5,
                previous: $exception,
            );
        }

        if ($cached !== null) {
            return $cached;
        }

        $cachedFailure = $this->cachedFailure($cacheKey);
        if ($cachedFailure !== null) {
            return $this->notReady(
                configuration: $configuration,
                reason: $cachedFailure['reason'],
                retryAt: $cachedFailure['retry_at'],
            );
        }

        try {
            $lock = Cache::lock($cacheKey.':lock', 20);
            if (! $lock->block(3)) {
                return $this->notReady(
                    configuration: $configuration,
                    reason: 'observation_busy',
                    retryAt: now()->getTimestamp() + 2,
                );
            }
        } catch (LockTimeoutException) {
            return $this->notReady(
                configuration: $configuration,
                reason: 'observation_busy',
                retryAt: now()->getTimestamp() + 2,
            );
        } catch (Throwable $exception) {
            return $this->notReady(
                configuration: $configuration,
                reason: 'cache_unavailable',
                retryAt: now()->getTimestamp() + 5,
                previous: $exception,
            );
        }

        try {
            $cached = $this->cachedReport(Cache::get($cacheKey), $fingerprint);
            if ($cached !== null) {
                return $cached;
            }

            $cachedFailure = $this->cachedFailure($cacheKey);
            if ($cachedFailure !== null) {
                return $this->notReady(
                    configuration: $configuration,
                    reason: $cachedFailure['reason'],
                    retryAt: $cachedFailure['retry_at'],
                );
            }

            try {
                $channels = app(WijayaPayProviderAdapter::class)->listChannels();
            } catch (Throwable $exception) {
                return $this->rememberObservationFailure(
                    $cacheKey,
                    $this->notReady(
                        configuration: $configuration,
                        reason: $exception instanceof WijayaPayProviderException
                            ? $exception->reason
                            : 'provider_error',
                        retryAt: $this->retryAt(),
                        previous: $exception,
                    ),
                );
            }

            $qrisChannels = array_values(array_filter(
                $channels,
                static fn (WijayaPayChannel $channel): bool => $channel->isQris(),
            ));

            if (count($qrisChannels) !== 1 || ! $qrisChannels[0]->isActive()) {
                return $this->rememberObservationFailure(
                    $cacheKey,
                    $this->notReady(
                        configuration: $configuration,
                        reason: count($qrisChannels) === 0 ? 'channel_missing' : 'channel_inactive',
                        retryAt: $this->retryAt(),
                    ),
                );
            }

            $report = new WijayaPayProviderReadinessReport(
                missing: [],
                invalid: [],
                enabled: true,
                qrisChannel: $qrisChannels[0],
                channelObserved: true,
                checkedAt: CarbonImmutable::now(),
                configurationFingerprint: $fingerprint,
            );

            Cache::put($cacheKey, $this->reportToArray($report), now()->addSeconds($this->cacheSeconds()));

            return $report;
        } catch (Throwable $exception) {
            return $this->rememberObservationFailure(
                $cacheKey,
                $this->notReady(
                    configuration: $configuration,
                    reason: 'observation_failed',
                    retryAt: $this->retryAt(),
                    previous: $exception,
                ),
            );
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // The lease expires safely.
            }
        }
    }

    public function checkout(): WijayaPayProviderReadinessReport
    {
        return $this->observe();
    }

    public function active(): WijayaPayProviderReadinessReport
    {
        return $this->checkout();
    }

    public function isReady(): bool
    {
        return $this->observe()->isReady();
    }

    public function configurationMessage(): string
    {
        return $this->observe()->configurationMessage();
    }

    public function assertReady(string $message = 'WijayaPay provider is not ready.'): void
    {
        $this->observe()->assertReady($message);
    }

    public function invalidate(): void
    {
        try {
            Cache::add(self::CACHE_VERSION_KEY, 1, now()->addYears(5));
            Cache::increment(self::CACHE_VERSION_KEY);
        } catch (Throwable) {
            // A cache outage must not make a settings mutation fail.
        }
    }

    /**
     * Re-check only local configuration and the immutable channel observation
     * inside the checkout transaction. No provider HTTP is allowed here.
     */
    public function assertCheckoutSnapshot(
        ?WijayaPayProviderReadinessReport $report,
        ?ManualPaymentSetting $setting,
    ): void {
        if ($report === null || ! $report->isReady()) {
            throw new CheckoutPaymentUnavailable(
                'WijayaPay QRIS checkout is not ready. '.trim((string) $report?->configurationMessage()),
                reason: $report?->failureReason() ?? 'configuration_invalid',
                retryAt: $report?->retryAt(),
                observationAgeSeconds: $report?->checkedAt() === null
                    ? null
                    : max(0, now()->getTimestamp() - $report->checkedAt()->getTimestamp()),
            );
        }

        if ($report->checkedAt() === null
            || $report->checkedAt()->isFuture()
            || $report->checkedAt()->getTimestamp() + $this->cacheSeconds() <= now()->getTimestamp()
            || $report->configurationFingerprint() !== $this->configurationFingerprint()
        ) {
            throw new CheckoutPaymentUnavailable('WijayaPay QRIS checkout readiness changed. Try again.', reason: 'snapshot_changed');
        }

        if ($setting === null
            || ! $setting->is_active
            || $setting->paymentProvider() !== PaymentProvider::WIJAYAPAY
        ) {
            throw new CheckoutPaymentUnavailable('WijayaPay QRIS provider selection changed. Try again.', reason: 'snapshot_changed');
        }

        $channel = $report->qrisChannel();
        if (! $report->channelObserved()
            || $channel === null
            || ! $channel->isQris()
            || ! $channel->isActive()
        ) {
            throw new CheckoutPaymentUnavailable('WijayaPay QRIS channel is no longer ready. Try again.', reason: 'channel_inactive');
        }
    }

    private function evaluateConfiguration(bool $includeOperationalPrerequisites = false): WijayaPayProviderReadinessReport
    {
        $missing = [];
        $invalid = [];

        $codeMerchant = $this->textConfig('code_merchant');
        $apiKey = $this->textConfig('api_key');
        if ($codeMerchant === null) {
            $missing[] = 'WIJAYAPAY_CODE_MERCHANT';
        } elseif (trim($codeMerchant) !== $codeMerchant) {
            $invalid['WIJAYAPAY_CODE_MERCHANT'] = 'WIJAYAPAY_CODE_MERCHANT must not contain surrounding whitespace.';
        }

        if ($apiKey === null) {
            $missing[] = 'WIJAYAPAY_API_KEY';
        } elseif (trim($apiKey) !== $apiKey) {
            $invalid['WIJAYAPAY_API_KEY'] = 'WIJAYAPAY_API_KEY must not contain surrounding whitespace.';
        }

        $baseUrl = (string) config('services.wijayapay.base_url', 'https://gateway.wijayapay.com');
        if (! $this->isExpectedBaseUrl($baseUrl)) {
            $invalid['WIJAYAPAY_BASE_URL'] = 'WIJAYAPAY_BASE_URL must be HTTPS on gateway.wijayapay.com.';
        }

        foreach (['callback_url', 'return_url'] as $field) {
            $value = $this->textConfig($field);
            if ($value === null) {
                $missing[] = strtoupper($field);
            } elseif (! $this->validApplicationUrl($value, $field === 'callback_url' ? '/api/webhook/wijayapay' : null)) {
                $invalid[strtoupper($field)] = strtoupper($field).' must be an allowlisted application URL.';
            }
        }

        $contract = (array) config('services.wijayapay.qris_contract', []);
        if (($contract['currency'] ?? null) !== 'IDR' || ($contract['currency_confirmed'] ?? false) !== true) {
            $invalid['WIJAYAPAY_QRIS_CURRENCY'] = 'WijayaPay QRIS currency must be confirmed as IDR.';
        }
        if (($contract['expiry_confirmed'] ?? false) !== true) {
            $invalid['WIJAYAPAY_QRIS_EXPIRY'] = 'WijayaPay QRIS expiry parsing must be confirmed.';
        }
        $maxFeeBps = $contract['max_fee_bps'] ?? null;
        if (! is_int($maxFeeBps) || $maxFeeBps < 0 || $maxFeeBps > 10_000) {
            $invalid['WIJAYAPAY_QRIS_MAX_FEE_BPS'] = 'WijayaPay QRIS max fee must be an integer between 0 and 10000 basis points.';
        }

        $invalid += $this->invalidTransportConfiguration();
        if ($includeOperationalPrerequisites) {
            $invalid += $this->invalidOperationalConfiguration();
        }

        return new WijayaPayProviderReadinessReport(
            missing: array_values(array_unique($missing)),
            invalid: $invalid,
            enabled: $codeMerchant !== null && $apiKey !== null,
            checkedAt: CarbonImmutable::now(),
            configurationFingerprint: $this->configurationFingerprint(),
        );
    }

    /** @return array<string, string> */
    private function invalidTransportConfiguration(): array
    {
        $invalid = [];
        $timeout = config('services.wijayapay.http_timeout_seconds', 10);
        $connectTimeout = config('services.wijayapay.http_connect_timeout_seconds', 3);
        if (! $this->validNumber($timeout, 0.001, 60)) {
            $invalid['WIJAYAPAY_HTTP_TIMEOUT_SECONDS'] = 'WIJAYAPAY_HTTP_TIMEOUT_SECONDS must be between 0 and 60 seconds.';
        }
        if (! $this->validNumber($connectTimeout, 0.001, is_numeric($timeout) ? (float) $timeout : 60)) {
            $invalid['WIJAYAPAY_HTTP_CONNECT_TIMEOUT_SECONDS'] = 'WIJAYAPAY_HTTP_CONNECT_TIMEOUT_SECONDS must be positive and no greater than the HTTP timeout.';
        }

        $attempts = config('services.wijayapay.lookup_max_attempts', 3);
        if (! is_int($attempts) || $attempts < 1 || $attempts > 5) {
            $invalid['WIJAYAPAY_LOOKUP_MAX_ATTEMPTS'] = 'WIJAYAPAY_LOOKUP_MAX_ATTEMPTS must be an integer between 1 and 5.';
        }

        $retryDelay = config('services.wijayapay.lookup_retry_delay_ms', 250);
        $retryMaxDelay = config('services.wijayapay.lookup_retry_max_delay_ms', 1000);
        if (! $this->validInteger($retryDelay, 0, 60_000)) {
            $invalid['WIJAYAPAY_LOOKUP_RETRY_DELAY_MS'] = 'WIJAYAPAY_LOOKUP_RETRY_DELAY_MS must be an integer between 0 and 60000.';
        }
        if (! $this->validInteger($retryMaxDelay, 0, 60_000)
            || (int) $retryMaxDelay < (int) $retryDelay
        ) {
            $invalid['WIJAYAPAY_LOOKUP_RETRY_MAX_DELAY_MS'] = 'WIJAYAPAY_LOOKUP_RETRY_MAX_DELAY_MS must be no less than the retry delay and no greater than 60000.';
        }

        $cacheSeconds = config('services.wijayapay.readiness_cache_seconds', self::DEFAULT_CACHE_SECONDS);
        if (! $this->validInteger($cacheSeconds, 1, self::MAX_CACHE_SECONDS)) {
            $invalid['WIJAYAPAY_READINESS_CACHE_SECONDS'] = 'WIJAYAPAY_READINESS_CACHE_SECONDS must be an integer between 1 and 300.';
        }

        $timezone = config('services.wijayapay.timezone', 'Asia/Jakarta');
        try {
            if (! is_string($timezone) || trim($timezone) === '') {
                throw new \InvalidArgumentException('Invalid timezone.');
            }
            new \DateTimeZone($timezone);
        } catch (Throwable) {
            $invalid['WIJAYAPAY_TIMEZONE'] = 'WIJAYAPAY_TIMEZONE must be a valid timezone identifier.';
        }

        return $invalid;
    }

    /** @return array<string, string> */
    private function invalidOperationalConfiguration(): array
    {
        $invalid = [];

        try {
            $route = Route::getRoutes()->getByName(self::CALLBACK_ROUTE_NAME);
            if ($route === null
                || ! in_array('POST', $route->methods(), true)
                || trim($route->uri(), '/') !== self::CALLBACK_ROUTE_URI
            ) {
                $invalid['WIJAYAPAY_CALLBACK_ROUTE'] = 'WijayaPay callback route must be POST /api/webhook/wijayapay.';
            }
        } catch (Throwable) {
            $invalid['WIJAYAPAY_CALLBACK_ROUTE'] = 'WijayaPay callback route could not be verified.';
        }

        if (app()->environment('production')) {
            $queueConnection = config('queue.default');
            $queueConnection = is_string($queueConnection) ? trim($queueConnection) : '';
            $queueDriver = $queueConnection === '' ? '' : config("queue.connections.{$queueConnection}.driver");
            $queueDriver = is_string($queueDriver) ? trim($queueDriver) : '';
            if ($queueConnection === '' || $queueDriver === '' || in_array($queueDriver, ['sync', 'null'], true)) {
                $invalid['QUEUE_CONNECTION'] = 'QUEUE_CONNECTION must use an asynchronous operational queue driver for WijayaPay operations.';
            }

            foreach (['jobs', 'failed_jobs', 'payments', 'payment_events'] as $table) {
                try {
                    if (! Schema::hasTable($table)) {
                        $invalid['WIJAYAPAY_SCHEMA'] = 'Payment, payment event and queue schema must exist for WijayaPay operations.';

                        break;
                    }
                } catch (Throwable) {
                    $invalid['WIJAYAPAY_SCHEMA'] = 'Required WijayaPay operational schema could not be verified.';

                    break;
                }
            }
        }

        return $invalid;
    }

    private function isExpectedBaseUrl(string $baseUrl): bool
    {
        $parts = parse_url($baseUrl);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower(rtrim((string) ($parts['host'] ?? ''), '.')) !== self::PRODUCTION_BASE_HOST
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('port', $parts)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
        ) {
            return false;
        }

        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        return $path === '';
    }

    private function validApplicationUrl(string $url, ?string $requiredPath): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts)
            || ! isset($parts['host'])
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('port', $parts)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
            || ($requiredPath !== null && rtrim((string) ($parts['path'] ?? ''), '/') !== rtrim($requiredPath, '/'))
        ) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https' && ! app()->environment(['local', 'testing'])) {
            return false;
        }
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));
        $allowed = config('services.wijayapay.callback_allowed_hosts', []);
        if (is_string($allowed)) {
            $allowed = explode(',', $allowed);
        }

        $allowedHosts = array_values(array_filter(array_map(
            static fn (mixed $candidate): ?string => is_string($candidate)
                ? strtolower(rtrim(trim($candidate), '.'))
                : null,
            (array) $allowed,
        ), static fn (mixed $candidate): bool => is_string($candidate) && $candidate !== ''));

        return $host !== '' && in_array($host, $allowedHosts, true);
    }

    private function notReady(
        WijayaPayProviderReadinessReport $configuration,
        string $reason,
        ?int $retryAt,
        ?Throwable $previous = null,
    ): WijayaPayProviderReadinessReport {
        return new WijayaPayProviderReadinessReport(
            missing: $configuration->missing(),
            invalid: array_merge($configuration->invalid(), [
                'WIJAYAPAY_OBSERVATION' => 'WijayaPay channel observation failed safely.',
            ]),
            enabled: $configuration->enabled(),
            channelObserved: false,
            checkedAt: CarbonImmutable::now(),
            configurationFingerprint: $configuration->configurationFingerprint(),
            failureReason: $reason,
            retryAt: $retryAt,
        );
    }

    /**
     * @return array{reason: string, retry_at: int}|null
     */
    private function cachedFailure(string $cacheKey): ?array
    {
        try {
            $failure = Cache::get($cacheKey.':failure');
        } catch (Throwable) {
            return null;
        }

        if (! is_array($failure)
            || ! is_string($failure['reason'] ?? null)
            || ! is_int($failure['retry_at'] ?? null)
            || $failure['retry_at'] <= now()->getTimestamp()
        ) {
            return null;
        }

        return ['reason' => $failure['reason'], 'retry_at' => $failure['retry_at']];
    }

    private function rememberObservationFailure(
        string $cacheKey,
        WijayaPayProviderReadinessReport $report,
    ): WijayaPayProviderReadinessReport {
        $ttl = min(30, max(5, $this->cacheSeconds()));
        $retryAt = $report->retryAt() ?? now()->getTimestamp() + $ttl;

        try {
            Cache::put($cacheKey.':failure', [
                'reason' => $report->failureReason() ?? 'observation_failed',
                'retry_at' => $retryAt,
            ], now()->addSeconds($ttl));
        } catch (Throwable) {
            // A cache outage already fails closed through the returned report.
        }

        return $report;
    }

    private function cachedReport(mixed $cached, string $fingerprint): ?WijayaPayProviderReadinessReport
    {
        if (! is_array($cached)
                || ($cached['fingerprint'] ?? null) !== $fingerprint
                || ! isset($cached['checked_at'])
                || ! is_string($cached['checked_at'])
        ) {
            return null;
        }

        try {
            $checkedAt = CarbonImmutable::parse($cached['checked_at']);
        } catch (Throwable) {
            return null;
        }

        if ($checkedAt->getTimestamp() + $this->cacheSeconds() <= now()->getTimestamp()) {
            return null;
        }

        $channel = null;
        if (is_array($cached['channel'] ?? null)) {
            try {
                $channel = WijayaPayChannel::fromPayload($cached['channel']);
            } catch (Throwable) {
                return null;
            }
        }

        if ($channel === null || ! $channel->isQris() || ! $channel->isActive()) {
            return null;
        }

        return new WijayaPayProviderReadinessReport(
            missing: [],
            invalid: [],
            enabled: true,
            qrisChannel: $channel,
            channelObserved: true,
            checkedAt: $checkedAt,
            configurationFingerprint: $fingerprint,
        );
    }

    /** @return array<string, mixed> */
    private function reportToArray(WijayaPayProviderReadinessReport $report): array
    {
        $channel = $report->qrisChannel();

        return [
            'fingerprint' => $report->configurationFingerprint(),
            'checked_at' => $report->checkedAt()?->toIso8601String(),
            'channel' => $channel === null ? null : [
                'code' => $channel->code,
                'name' => $channel->name,
                'group' => $channel->group,
                'status' => $channel->status,
                'fee_amount' => $channel->feeAmount,
                'fee_percent' => (string) ($channel->feePercentBps / 100),
                'min_trx' => $channel->minimumAmount,
                'max_trx' => $channel->maximumAmount,
                'type_fee' => $channel->feePayer,
            ],
        ];
    }

    private function configurationFingerprint(): string
    {
        return hash('sha256', implode('|', [
            (string) config('services.wijayapay.base_url'),
            hash('sha256', (string) config('services.wijayapay.code_merchant')),
            hash('sha256', (string) config('services.wijayapay.api_key')),
            (string) config('services.wijayapay.callback_url'),
            (string) config('services.wijayapay.return_url'),
            (string) config('services.wijayapay.timezone'),
            json_encode((array) config('services.wijayapay.qris_contract', []), JSON_THROW_ON_ERROR),
        ]));
    }

    private function cacheKey(string $fingerprint): string
    {
        try {
            $version = (int) Cache::get(self::CACHE_VERSION_KEY, 0);
        } catch (Throwable) {
            $version = 0;
        }

        return self::CACHE_KEY_PREFIX.':'.$version.':'.hash('sha256', $fingerprint);
    }

    private function cacheSeconds(): int
    {
        $value = config('services.wijayapay.readiness_cache_seconds', self::DEFAULT_CACHE_SECONDS);

        return is_numeric($value) ? min(self::MAX_CACHE_SECONDS, max(1, (int) $value)) : self::DEFAULT_CACHE_SECONDS;
    }

    private function retryAt(): ?int
    {
        try {
            return (new WijayaPayRequestGate)->retryAt();
        } catch (Throwable) {
            return null;
        }
    }

    private function textConfig(string $field): ?string
    {
        $value = config('services.wijayapay.'.$field);
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function validNumber(mixed $value, float $minimum, float $maximum): bool
    {
        return is_numeric($value)
            && (float) $value >= $minimum
            && (float) $value <= $maximum;
    }

    private function validInteger(mixed $value, int $minimum, int $maximum): bool
    {
        return is_int($value) && $value >= $minimum && $value <= $maximum;
    }
}
