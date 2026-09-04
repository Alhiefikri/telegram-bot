<?php

namespace App\Services\Sakurupiah;

use App\Enums\SakurupiahEnvironment;
use App\Exceptions\InvalidCheckout;
use App\Models\ManualPaymentSetting;
use App\Support\PaymentProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class SakurupiahProviderReadiness
{
    public const QRIS_METHOD = 'QRIS';

    private const CACHE_VERSION_KEY = 'sakurupiah.readiness.version';

    private const CACHE_KEY_PREFIX = 'sakurupiah.readiness';

    private const DEFAULT_CACHE_SECONDS = 60;

    private const MAX_CACHE_SECONDS = 300;

    private const CALLBACK_ROUTE_NAME = 'sakurupiah.webhook';

    private const CALLBACK_ROUTE_URI = 'api/webhook/sakurupiah';

    private const RECONCILIATION_COMMAND = 'sakurupiah:reconcile --chunk=100';

    private const SUPERVISED_VERIFICATION_QUEUE = 'default';

    public function __construct(private readonly ?SakurupiahEnvironment $environment = null) {}

    /**
     * Return the complete report for the production environment.
     *
     * Production readiness performs one bounded channel observation after the
     * local configuration and operational prerequisites pass. Sandbox remains
     * configuration-only through this compatibility method; callers that
     * qualify sandbox traffic explicitly should use observe().
     */
    public function report(?SakurupiahEnvironment $environment = null): SakurupiahProviderReadinessReport
    {
        $environment = $this->resolveEnvironment($environment);
        $configuration = $this->evaluateConfiguration(
            $environment,
            includeOperationalPrerequisites: $environment === SakurupiahEnvironment::Production,
        );

        if ($environment !== SakurupiahEnvironment::Production) {
            return $configuration;
        }

        if (! $this->evaluateConfiguration($environment)->isReady()) {
            return $configuration;
        }

        return $this->observeEnvironment($environment, $configuration);
    }

    /**
     * Validate only local request prerequisites.
     *
     * list-payment.php is the channel observation itself, so the HTTP client
     * must not require callback routes, queue workers, or a cached channel
     * result before it can perform that bounded lookup.
     */
    public function configurationReport(?SakurupiahEnvironment $environment = null): SakurupiahProviderReadinessReport
    {
        return $this->evaluateConfiguration(
            $this->resolveEnvironment($environment),
        );
    }

    /**
     * Observe runtime QRIS metadata and cache the immutable result.
     *
     * The optional reader keeps the dependency direction explicit and makes
     * the method usable by preparation tests without constructing an HTTP
     * client. When omitted, the registered provider adapter is resolved
     * lazily. No provider call is made when static configuration is unsafe.
     */
    public function observe(
        ?SakurupiahEnvironment $environment = null,
        ?callable $channelReader = null,
    ): SakurupiahProviderReadinessReport {
        $environment = $this->resolveEnvironment($environment);
        $configuration = $this->evaluateConfiguration(
            $environment,
            includeOperationalPrerequisites: $environment === SakurupiahEnvironment::Production,
            includeQrisContract: true,
        );
        $localConfiguration = $this->evaluateConfiguration($environment);

        if (! $localConfiguration->isReady()) {
            return $configuration;
        }

        return $this->observeEnvironment($environment, $configuration, $channelReader);
    }

    public function check(
        ?SakurupiahEnvironment $environment = null,
        ?callable $channelReader = null,
    ): SakurupiahProviderReadinessReport {
        return $this->observe($environment, $channelReader);
    }

    public function sandbox(): SakurupiahProviderReadinessReport
    {
        return $this->report(SakurupiahEnvironment::Sandbox);
    }

    public function production(): SakurupiahProviderReadinessReport
    {
        return $this->report(SakurupiahEnvironment::Production);
    }

    public function checkout(): SakurupiahProviderReadinessReport
    {
        return $this->report(SakurupiahEnvironment::runtime());
    }

    public function active(): SakurupiahProviderReadinessReport
    {
        return $this->checkout();
    }

    public function forEnvironment(SakurupiahEnvironment $environment): SakurupiahProviderReadinessReport
    {
        return $this->report($environment);
    }

    public function sandboxSchedulingEnabled(): bool
    {
        return $this->schedulingEnabledFor(SakurupiahEnvironment::Sandbox);
    }

    public function productionSchedulingEnabled(): bool
    {
        return $this->schedulingEnabledFor(SakurupiahEnvironment::Production);
    }

    public function schedulingEnabled(): bool
    {
        return $this->schedulingEnabledFor(SakurupiahEnvironment::runtime());
    }

    public function schedulingEnabledFor(SakurupiahEnvironment $environment): bool
    {
        $reconciliationCommand = config('services.sakurupiah.reconciliation_command');
        if ($this->value($environment, 'enabled', false) !== true
            || ! $environment->isRuntimeAllowed()
            || ! is_string($reconciliationCommand)
            || trim($reconciliationCommand) !== self::RECONCILIATION_COMMAND
        ) {
            return false;
        }

        $report = $this->evaluateConfiguration($environment, includeOperationalPrerequisites: true);
        $invalid = $report->invalid();
        unset($invalid['SAKURUPIAH_RECONCILIATION_SCHEDULE']);

        return $report->missing() === [] && $invalid === [];
    }

    public function assertReady(
        string $message = 'Sakurupiah provider is not ready.',
        ?SakurupiahEnvironment $environment = null,
    ): void {
        $this->report($environment)->assertReady($message);
    }

    /**
     * Re-check only local configuration and the immutable channel observation
     * inside the checkout transaction. No provider HTTP is allowed here.
     */
    public function assertCheckoutSnapshot(
        ?SakurupiahProviderReadinessReport $report,
        ?ManualPaymentSetting $setting,
    ): void {
        if ($report === null || ! $report->isReady()) {
            $message = trim((string) $report?->configurationMessage());
            throw new InvalidCheckout(
                'Sakurupiah QRIS checkout is not ready.'
                .($message === '' ? '' : ' '.$message),
            );
        }

        $environment = SakurupiahEnvironment::runtime();
        if ($report->environment() !== $environment
            || $report->configurationFingerprint() !== $this->configurationFingerprint(
                $environment,
                includeOperational: true,
            )
        ) {
            throw new InvalidCheckout('Sakurupiah QRIS checkout readiness changed. Try again.');
        }

        if ($setting === null
            || ! $setting->is_active
            || $setting->paymentProvider() !== PaymentProvider::SAKURUPIAH
        ) {
            throw new InvalidCheckout('Sakurupiah QRIS provider selection changed. Try again.');
        }

        if (! $report->channelObserved()
            || $report->qrisChannel() === null
            || ! $report->qrisChannel()->isQris()
            || ! $report->qrisChannel()->isActive()
        ) {
            throw new InvalidCheckout('Sakurupiah QRIS channel is no longer ready. Try again.');
        }
    }

    /**
     * Invalidate all cached observations without depending on cache deletion.
     */
    public function invalidate(): void
    {
        try {
            Cache::add(self::CACHE_VERSION_KEY, 1, now()->addYears(5));
            Cache::increment(self::CACHE_VERSION_KEY);
        } catch (Throwable) {
            // A cache outage must not make a configuration mutation fail.
            // The fingerprint still forces fresh work after a config change.
        }
    }

    /**
     * @return array<string, string>
     */
    private function invalidConfiguration(SakurupiahEnvironment $environment): array
    {
        $invalid = [];

        if (! $environment->isRuntimeAllowed()) {
            $invalid['APP_ENV'] = $environment === SakurupiahEnvironment::Production
                ? 'Sakurupiah production is allowed only when APP_ENV is production.'
                : 'Sakurupiah sandbox is allowed only when APP_ENV is local or testing.';
        }

        $baseUrl = $this->value($environment, 'base_url');
        $baseHosts = $this->hosts($environment, 'allowed_hosts');
        if ($environment === SakurupiahEnvironment::Production && $baseHosts === []) {
            $invalid[$this->label($environment, 'ALLOWED_HOSTS')] = 'Sakurupiah production API host policy must be configured.';
        }
        $baseInvalid = $this->validateUrl(
            $baseUrl,
            $environment === SakurupiahEnvironment::Sandbox ? 'base URL' : $this->label($environment, 'BASE_URL'),
            $baseHosts !== [] ? $baseHosts : ['sakurupiah.id'],
            $environment === SakurupiahEnvironment::Sandbox ? '/api-sanbox' : '/api',
        );
        if ($environment === SakurupiahEnvironment::Sandbox && $baseInvalid !== []) {
            $invalid['base URL'] = (string) reset($baseInvalid);
        } else {
            $invalid += $baseInvalid;
        }

        foreach (['callback_url' => 'CALLBACK_URL', 'return_url' => 'RETURN_URL'] as $field => $label) {
            $value = $this->value($environment, $field);
            if ($value === null || blank($value)) {
                continue;
            }

            $allowedHosts = $this->hosts($environment, $field === 'callback_url'
                ? 'callback_allowed_hosts'
                : 'return_allowed_hosts');
            $invalid += $this->validateUrl(
                $value,
                $this->label($environment, $label),
                $allowedHosts,
                $field === 'callback_url' ? '/api/webhook/sakurupiah' : null,
                requireHostPolicy: true,
            );
        }

        $invalid += $this->invalidCrossEnvironmentConfiguration($environment);
        $invalid += $this->invalidTransportConfiguration();
        if ($environment === SakurupiahEnvironment::Production) {
            foreach ($this->hosts($environment, 'allowed_ips') as $ip) {
                if (! $this->validPublicIp($ip)) {
                    $invalid[$this->label($environment, 'ALLOWED_IPS')] = 'Sakurupiah production allowed IPs must be public IP addresses or CIDR ranges.';
                    break;
                }
            }
        }

        return $invalid;
    }

    /**
     * @return list<string>
     */
    private function missingConfiguration(
        SakurupiahEnvironment $environment,
        bool $includeOperationalPrerequisites,
    ): array {
        $missing = [];

        if ($this->value($environment, 'enabled', false) !== true) {
            $missing[] = 'provider enabled flag';
        }

        foreach (['api_id' => 'API ID', 'api_key' => 'API key', 'base_url' => 'base URL'] as $field => $label) {
            $value = $this->value($environment, $field);
            if (! is_string($value) || trim($value) === '') {
                $missing[] = $label;
            }
        }

        if (! $includeOperationalPrerequisites || $this->value($environment, 'enabled', false) !== true) {
            return $missing;
        }

        foreach ([
            'callback_url' => 'CALLBACK_URL',
            'return_url' => 'RETURN_URL',
        ] as $field => $label) {
            $value = $this->value($environment, $field);
            if (! is_string($value) || trim($value) === '') {
                $missing[] = $this->label($environment, $label);
            }
        }

        foreach ([
            'callback_allowed_hosts' => 'CALLBACK_ALLOWED_HOSTS',
            'return_allowed_hosts' => 'RETURN_ALLOWED_HOSTS',
            'allowed_ips' => 'ALLOWED_IPS',
        ] as $field => $label) {
            if ($this->hosts($environment, $field) === []) {
                $missing[] = $this->label($environment, $label);
            }
        }

        return $missing;
    }

    private function evaluateConfiguration(
        SakurupiahEnvironment $environment,
        bool $includeOperationalPrerequisites = false,
        bool $includeQrisContract = false,
    ): SakurupiahProviderReadinessReport {
        $missing = $this->missingConfiguration($environment, $includeOperationalPrerequisites);
        $invalid = $this->invalidConfiguration($environment);

        $apiId = $this->value($environment, 'api_id');
        $apiKey = $this->value($environment, 'api_key');
        if (is_string($apiId) && $apiId !== '' && trim($apiId) !== $apiId) {
            $invalid[$this->label($environment, 'API_ID')] = 'Sakurupiah API ID must not contain surrounding whitespace.';
        }
        if (is_string($apiKey) && $apiKey !== '' && trim($apiKey) !== $apiKey) {
            $invalid[$this->label($environment, 'API_KEY')] = 'Sakurupiah API key must not contain surrounding whitespace.';
        }

        if ($this->value($environment, 'enabled', false) === true
            && ($includeOperationalPrerequisites || $includeQrisContract)
        ) {
            $invalid += $this->invalidConfiguredQrisContract();
        }

        if ($this->value($environment, 'enabled', false) === true
            && $environment === SakurupiahEnvironment::Production
            && $includeOperationalPrerequisites
        ) {
            $invalid += $this->invalidOperationalConfiguration($environment);
        }

        return new SakurupiahProviderReadinessReport(
            missing: array_values(array_unique($missing)),
            invalid: $invalid,
            environment: $environment,
            enabled: $this->value($environment, 'enabled', false) === true,
            checkedAt: CarbonImmutable::now(),
            configurationFingerprint: $this->configurationFingerprint($environment, $includeOperationalPrerequisites),
        );
    }

    /**
     * @return array<string, string>
     */
    private function invalidTransportConfiguration(): array
    {
        $invalid = [];
        $timeout = config('services.sakurupiah.http_timeout_seconds', 10);
        $connectTimeout = config('services.sakurupiah.http_connect_timeout_seconds', 3);
        if (! $this->validNumber($timeout, 0.001, 60)) {
            $invalid['SAKURUPIAH_HTTP_TIMEOUT_SECONDS'] = 'SAKURUPIAH_HTTP_TIMEOUT_SECONDS must be between 0 and 60 seconds.';
        }
        if (! $this->validNumber($connectTimeout, 0.001, is_numeric($timeout) ? (float) $timeout : 60)) {
            $invalid['SAKURUPIAH_HTTP_CONNECT_TIMEOUT_SECONDS'] = 'SAKURUPIAH_HTTP_CONNECT_TIMEOUT_SECONDS must be positive and no greater than the HTTP timeout.';
        }

        $attempts = config('services.sakurupiah.lookup_max_attempts', 3);
        if (! is_int($attempts) || $attempts < 1 || $attempts > 5) {
            $invalid['SAKURUPIAH_LOOKUP_MAX_ATTEMPTS'] = 'SAKURUPIAH_LOOKUP_MAX_ATTEMPTS must be an integer between 1 and 5.';
        }

        $retryDelay = config('services.sakurupiah.lookup_retry_delay_ms', 250);
        $retryMaxDelay = config('services.sakurupiah.lookup_retry_max_delay_ms', 1000);
        if (! $this->validInteger($retryDelay, 0, 60_000)) {
            $invalid['SAKURUPIAH_LOOKUP_RETRY_DELAY_MS'] = 'SAKURUPIAH_LOOKUP_RETRY_DELAY_MS must be an integer between 0 and 60000.';
        }
        if (! $this->validInteger($retryMaxDelay, 0, 60_000)
            || (int) $retryMaxDelay < (int) $retryDelay
        ) {
            $invalid['SAKURUPIAH_LOOKUP_RETRY_MAX_DELAY_MS'] = 'SAKURUPIAH_LOOKUP_RETRY_MAX_DELAY_MS must be no less than the retry delay and no greater than 60000.';
        }

        $lockLease = config('services.sakurupiah.lock_lease_seconds', 90);
        if (! $this->validInteger($lockLease, 1, 600)) {
            $invalid['SAKURUPIAH_LOCK_LEASE_SECONDS'] = 'SAKURUPIAH_LOCK_LEASE_SECONDS must be an integer between 1 and 600.';
        }
        foreach ([
            'webhook_rate_limit_per_minute' => 'SAKURUPIAH_WEBHOOK_RATE_LIMIT_PER_MINUTE',
            'status_refresh_rate_limit_per_minute' => 'SAKURUPIAH_STATUS_REFRESH_RATE_LIMIT_PER_MINUTE',
        ] as $field => $label) {
            if (! $this->validInteger(config('services.sakurupiah.'.$field, 30), 1, 10_000)) {
                $invalid[$label] = $label.' must be an integer between 1 and 10000.';
            }
        }

        $cacheSeconds = config('services.sakurupiah.readiness_cache_seconds', self::DEFAULT_CACHE_SECONDS);
        if (! $this->validInteger($cacheSeconds, 1, self::MAX_CACHE_SECONDS)) {
            $invalid['SAKURUPIAH_READINESS_CACHE_SECONDS'] = 'SAKURUPIAH_READINESS_CACHE_SECONDS must be an integer between 1 and 300.';
        }

        $lookbackHours = config('services.sakurupiah.reconcile_terminal_lookback_hours', 72);
        if (! $this->validInteger($lookbackHours, 1, 24 * 365)) {
            $invalid['SAKURUPIAH_RECONCILE_TERMINAL_LOOKBACK_HOURS'] = 'SAKURUPIAH_RECONCILE_TERMINAL_LOOKBACK_HOURS must be an integer between 1 and 8760.';
        }
        $executionSeconds = config('services.sakurupiah.reconcile_max_execution_seconds', 45);
        if (! is_numeric($executionSeconds) || (float) $executionSeconds < 1 || (float) $executionSeconds > 300) {
            $invalid['SAKURUPIAH_RECONCILE_MAX_EXECUTION_SECONDS'] = 'SAKURUPIAH_RECONCILE_MAX_EXECUTION_SECONDS must be between 1 and 300 seconds.';
        }

        $timezone = config('services.sakurupiah.timezone', 'Asia/Jakarta');
        try {
            if (! is_string($timezone) || trim($timezone) === '') {
                throw new \InvalidArgumentException('Invalid timezone.');
            }
            new \DateTimeZone($timezone);
        } catch (Throwable) {
            $invalid['SAKURUPIAH_TIMEZONE'] = 'SAKURUPIAH_TIMEZONE must be a valid timezone identifier.';
        }

        return $invalid;
    }

    /**
     * @return array<string, string>
     */
    private function invalidOperationalConfiguration(SakurupiahEnvironment $environment): array
    {
        $invalid = [];
        try {
            $route = Route::getRoutes()->getByName(self::CALLBACK_ROUTE_NAME);
            if ($route === null) {
                $invalid[$this->label($environment, 'CALLBACK_ROUTE')] = 'Sakurupiah callback route must be registered.';
            } elseif (! in_array('POST', $route->methods(), true)
                || trim($route->uri(), '/') !== self::CALLBACK_ROUTE_URI
            ) {
                $invalid[$this->label($environment, 'CALLBACK_ROUTE')] = 'Sakurupiah callback route must be POST /api/webhook/sakurupiah.';
            }
        } catch (Throwable) {
            $invalid[$this->label($environment, 'CALLBACK_ROUTE')] = 'Sakurupiah callback route could not be verified.';
        }
        $queueConnection = config('queue.default');
        $queueConnection = is_string($queueConnection) ? trim($queueConnection) : '';
        $queueDriver = $queueConnection === '' ? '' : config("queue.connections.{$queueConnection}.driver");
        $queueDriver = is_string($queueDriver) ? trim($queueDriver) : '';
        if ($queueConnection === '' || $queueDriver === '' || in_array($queueDriver, ['sync', 'null'], true)) {
            $invalid['QUEUE_CONNECTION'] = 'QUEUE_CONNECTION must use an asynchronous operational queue driver for Sakurupiah operations.';
        }

        foreach ([
            'jobs' => 'QUEUE_JOBS_TABLE',
            'failed_jobs' => 'QUEUE_FAILED_JOBS_TABLE',
            'job_batches' => 'QUEUE_JOB_BATCHES_TABLE',
            'payments' => 'PAYMENT_SCHEMA',
            'payment_events' => 'PAYMENT_SCHEMA',
        ] as $table => $label) {
            try {
                if (! Schema::hasTable($table)) {
                    $invalid[$label] = $table === 'payments' || $table === 'payment_events'
                        ? 'Payment and payment event schema must exist for Sakurupiah operations.'
                        : "Queue schema table {$table} must exist for Sakurupiah operations.";
                }
            } catch (Throwable) {
                $invalid[$label] = 'Required Sakurupiah operational schema could not be verified.';
            }
        }

        $reconciliationCommand = config('services.sakurupiah.reconciliation_command');
        $reconciliationCommand = is_string($reconciliationCommand) ? trim($reconciliationCommand) : '';
        if (! class_exists('App\\Console\\Commands\\ReconcileSakurupiahPayments')) {
            $invalid['SAKURUPIAH_RECONCILIATION_COMMAND'] = 'Sakurupiah reconciliation command must be registered.';
        }
        if ($reconciliationCommand !== self::RECONCILIATION_COMMAND
            || ! $this->scheduledCommand($reconciliationCommand)
        ) {
            $invalid['SAKURUPIAH_RECONCILIATION_SCHEDULE'] = 'Sakurupiah reconciliation must be scheduled by the Laravel scheduler.';
        }
        $verificationQueue = config('services.sakurupiah.verification_queue', self::SUPERVISED_VERIFICATION_QUEUE);
        if (! is_string($verificationQueue)
            || trim($verificationQueue) !== self::SUPERVISED_VERIFICATION_QUEUE
        ) {
            $invalid['SAKURUPIAH_VERIFICATION_QUEUE'] = 'Sakurupiah verification must use the supervised default queue.';
        }

        return $invalid;
    }

    private function scheduledCommand(string $command): bool
    {
        if ($command === '') {
            return false;
        }

        try {
            foreach ((array) Schedule::events() as $event) {
                if (! is_object($event)) {
                    continue;
                }
                $eventCommand = trim((string) ($event->command ?? ''));
                if ($eventCommand === $command || str_ends_with($eventCommand, ' '.$command)) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function invalidCrossEnvironmentConfiguration(SakurupiahEnvironment $environment): array
    {
        $other = $environment === SakurupiahEnvironment::Production
            ? SakurupiahEnvironment::Sandbox
            : SakurupiahEnvironment::Production;
        $invalid = [];

        $baseUrl = $this->normalizedUrl($this->value($environment, 'base_url'));
        $otherBaseUrl = $this->normalizedUrl($this->value($other, 'base_url'));
        if ($baseUrl !== null && $baseUrl === $otherBaseUrl) {
            $invalid[$this->label($environment, 'BASE_URL_'.strtoupper($other->value))] = 'Sakurupiah environments must use different API endpoints.';
        }

        foreach (['api_id' => 'API_ID', 'api_key' => 'API_KEY'] as $field => $label) {
            $value = $this->value($environment, $field);
            $otherValue = $this->value($other, $field);
            if (is_string($value)
                && is_string($otherValue)
                && filled($value)
                && filled($otherValue)
                && hash_equals($value, $otherValue)
            ) {
                $invalid[$this->label($environment, $label.'_'.strtoupper($other->value))] = 'Sakurupiah sandbox and production credentials must be different.';
            }
        }

        foreach (['callback_url' => 'CALLBACK_URL', 'return_url' => 'RETURN_URL'] as $field => $label) {
            $value = $this->normalizedUrl($this->value($environment, $field));
            $otherValue = $this->normalizedUrl($this->value($other, $field));
            if ($value !== null && $value === $otherValue) {
                $invalid[$this->label($environment, $label.'_'.strtoupper($other->value))] = 'Sakurupiah environments must use different '.strtolower(str_replace('_', ' ', $label)).'s.';
            }
        }

        return $invalid;
    }

    /**
     * @param  array<string, string>  $invalid
     */
    private function observeEnvironment(
        SakurupiahEnvironment $environment,
        SakurupiahProviderReadinessReport $configuration,
        ?callable $channelReader = null,
    ): SakurupiahProviderReadinessReport {
        $fingerprint = $this->configurationFingerprint($environment, includeOperational: true);
        $cacheKey = $this->cacheKey($environment, $fingerprint);
        $reader = $channelReader ?? $this->defaultChannelReader();
        if ($reader === null) {
            return $this->channelObservationFailure($configuration, $fingerprint);
        }

        $factory = fn (): SakurupiahProviderReadinessReport => $this->evaluateChannelObservation(
            $environment,
            $configuration,
            $fingerprint,
            $reader,
        );

        try {
            $cached = Cache::remember(
                $cacheKey,
                now()->addSeconds($this->cacheSeconds()),
                $factory,
            );
            if ($cached instanceof SakurupiahProviderReadinessReport
                && $cached->environment() === $environment
                && $cached->configurationFingerprint() === $fingerprint
            ) {
                return $cached;
            }
        } catch (Throwable) {
            // A cache outage must not turn a fresh failed observation into a
            // potentially stale success. Evaluate the provider once instead.
        }

        return $factory();
    }

    private function evaluateChannelObservation(
        SakurupiahEnvironment $environment,
        SakurupiahProviderReadinessReport $configuration,
        string $fingerprint,
        callable $channelReader,
    ): SakurupiahProviderReadinessReport {
        $invalid = $configuration->invalid();
        $channel = null;

        try {
            $channels = $channelReader();
            if ($channels instanceof SakurupiahChannel) {
                $channels = [$channels];
            }
            if (! is_array($channels)) {
                throw new \UnexpectedValueException('Invalid channel observation.');
            }

            $qrisChannels = array_values(array_filter(
                $channels,
                static fn (mixed $candidate): bool => $candidate instanceof SakurupiahChannel && $candidate->isQris(),
            ));
            if ($qrisChannels === []) {
                $invalid['SAKURUPIAH_QRIS_CHANNEL'] = 'Sakurupiah QRIS channel was not returned for the target merchant.';
            } elseif (count($qrisChannels) > 1) {
                $invalid['SAKURUPIAH_QRIS_CHANNEL'] = 'Sakurupiah returned an ambiguous QRIS channel set.';
            } else {
                $channel = $qrisChannels[0];
                if (! $channel->isActive()) {
                    $invalid['SAKURUPIAH_QRIS_CHANNEL'] = 'Sakurupiah QRIS channel is not active for the target merchant.';
                }
                if (! in_array(strtoupper($channel->type), ['DIRECT', 'REDIRECT'], true)) {
                    $invalid['SAKURUPIAH_QRIS_CHANNEL_TYPE'] = 'Sakurupiah QRIS channel type is not qualified.';
                }
                if ($channel->minimumAmount() < 1 || $channel->maximumAmount() < $channel->minimumAmount()) {
                    $invalid['SAKURUPIAH_QRIS_CHANNEL_LIMITS'] = 'Sakurupiah QRIS channel limits are invalid.';
                }
            }
        } catch (Throwable) {
            $invalid['SAKURUPIAH_QRIS_CHANNEL_LOOKUP'] = 'Sakurupiah QRIS channel metadata could not be observed safely.';
        }

        if ($channel !== null) {
            $invalid += $this->invalidQrisContract($channel);
        }

        return new SakurupiahProviderReadinessReport(
            missing: $configuration->missing(),
            invalid: $invalid,
            environment: $environment,
            enabled: $configuration->enabled(),
            qrisChannel: $channel,
            channelObserved: true,
            checkedAt: CarbonImmutable::now(),
            configurationFingerprint: $fingerprint,
        );
    }

    /**
     * @return array<string, string>
     */
    private function invalidConfiguredQrisContract(): array
    {
        $contract = (array) config('services.sakurupiah.qris_contract', []);
        $invalid = [];

        if (($contract['currency'] ?? null) !== 'IDR' || ($contract['currency_confirmed'] ?? false) !== true) {
            $invalid['SAKURUPIAH_QRIS_CURRENCY_CONTRACT'] = 'Sakurupiah QRIS currency contract is not qualified.';
        }
        $feePayer = $contract['fee_payer'] ?? null;
        $merchantFee = $contract['merchant_fee'] ?? null;
        if (! in_array($feePayer, ['merchant', 'customer'], true)
            || ($feePayer === 'merchant' && $merchantFee !== 1)
            || ($feePayer === 'customer' && $merchantFee !== 2)
            || ($contract['fee_confirmed'] ?? false) !== true
        ) {
            $invalid['SAKURUPIAH_QRIS_FEE_CONTRACT'] = 'Sakurupiah QRIS fee contract is not qualified.';
        }
        if (($contract['expiry_confirmed'] ?? false) !== true
            || ! is_int($contract['expiry_hours'] ?? null)
            || $contract['expiry_hours'] < 1
        ) {
            $invalid['SAKURUPIAH_QRIS_EXPIRY_CONTRACT'] = 'Sakurupiah QRIS expiry contract is not qualified.';
        }

        return $invalid;
    }

    private function invalidQrisContract(SakurupiahChannel $channel): array
    {
        $invalid = $this->invalidConfiguredQrisContract();
        $expiryHours = config('services.sakurupiah.qris_contract.expiry_hours');
        if ($channel->addition?->defaultExpiredHours() !== null
            && $channel->addition->defaultExpiredHours() !== $expiryHours
        ) {
            $invalid['SAKURUPIAH_QRIS_EXPIRY_CONTRACT'] = 'Sakurupiah QRIS expiry contract is not qualified.';
        }

        return $invalid;
    }

    private function channelObservationFailure(
        SakurupiahProviderReadinessReport $configuration,
        string $fingerprint,
    ): SakurupiahProviderReadinessReport {
        $invalid = $configuration->invalid();
        $invalid['SAKURUPIAH_QRIS_CHANNEL_LOOKUP'] = 'Sakurupiah QRIS channel metadata could not be observed safely.';

        return new SakurupiahProviderReadinessReport(
            missing: $configuration->missing(),
            invalid: $invalid,
            environment: $configuration->environment(),
            enabled: $configuration->enabled(),
            channelObserved: true,
            checkedAt: CarbonImmutable::now(),
            configurationFingerprint: $fingerprint,
        );
    }

    private function defaultChannelReader(): ?callable
    {
        if (! function_exists('app')) {
            return null;
        }

        try {
            $container = app();
            if (! $container->bound(SakurupiahProviderAdapter::class)) {
                return null;
            }
            $adapter = $container->make(SakurupiahProviderAdapter::class);

            return static fn (): array => $adapter->listChannels();
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveEnvironment(?SakurupiahEnvironment $environment): SakurupiahEnvironment
    {
        return $environment ?? $this->environment ?? SakurupiahEnvironment::runtime();
    }

    private function validNumber(mixed $value, float $minimum, float $maximum): bool
    {
        return is_numeric($value)
            && is_finite((float) $value)
            && (float) $value >= $minimum
            && (float) $value <= $maximum;
    }

    private function validInteger(mixed $value, int $minimum, int $maximum): bool
    {
        return is_int($value)
            && $value >= $minimum
            && $value <= $maximum;
    }

    /**
     * @return array<string, string>
     */
    private function validateUrl(
        mixed $value,
        string $label,
        array $allowedHosts,
        ?string $requiredPath = null,
        bool $requireHostPolicy = true,
    ): array {
        if ($value === null || (is_string($value) && blank($value))) {
            return [];
        }
        if (! is_string($value)) {
            return [$label.'_FORMAT' => "{$label} must be a valid HTTPS URL."];
        }

        $parts = parse_url($value);
        if (! is_array($parts)) {
            return [$label.'_FORMAT' => "{$label} must be a valid HTTPS URL."];
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $path = '/'.trim((string) ($parts['path'] ?? ''), '/');
        $invalid = [];

        if ($scheme !== 'https') {
            $invalid[$label.'_SCHEME'] = "{$label} must use HTTPS.";
        }
        if ($host === '' || self::unsafeHost($host)) {
            $invalid[$label.'_HOST'] = "{$label} must use a safe allowed host.";
        } elseif ($requireHostPolicy && ($allowedHosts === [] || ! in_array($host, $allowedHosts, true))) {
            $invalid[$label.'_HOST'] = "{$label} must use a safe allowed host.";
        }
        if ($requiredPath !== null && $path !== $requiredPath) {
            $invalid[$label.'_PATH'] = "{$label} must use the {$requiredPath} path.";
        }
        if (array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('port', $parts)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
        ) {
            $invalid[$label.'_FORMAT'] = "{$label} must not contain credentials, ports, query parameters, or fragments.";
        }

        return $invalid;
    }

    private static function unsafeHost(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/', $host) !== 1;
    }

    private function validPublicIp(string $value): bool
    {
        $parts = explode('/', $value, 2);
        $ip = $parts[0];
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        if (! isset($parts[1])) {
            return true;
        }

        $maximumPrefix = str_contains($ip, ':') ? 128 : 32;

        return ctype_digit($parts[1])
            && (int) $parts[1] > 0
            && (int) $parts[1] <= $maximumPrefix;
    }

    /**
     * @return list<string>
     */
    private function hosts(SakurupiahEnvironment $environment, string $field): array
    {
        $value = $this->value($environment, $field, []);
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        return array_values(array_filter(array_map(
            static fn (mixed $host): ?string => is_string($host)
                ? strtolower(rtrim(trim($host), '.'))
                : null,
            (array) $value,
        ), static fn (mixed $host): bool => is_string($host) && $host !== ''));
    }

    private function value(SakurupiahEnvironment $environment, string $field, mixed $default = null): mixed
    {
        return config($environment->configKey($field), $default);
    }

    private function label(SakurupiahEnvironment $environment, string $field): string
    {
        return 'SAKURUPIAH_'.strtoupper($environment->value).'_'.strtoupper($field);
    }

    private function normalizedUrl(mixed $value): ?string
    {
        if (! is_string($value) || blank($value)) {
            return null;
        }

        return rtrim(strtolower(trim($value)), '/');
    }

    private function cacheSeconds(): int
    {
        $value = config('services.sakurupiah.readiness_cache_seconds', self::DEFAULT_CACHE_SECONDS);
        if (! is_int($value)) {
            return self::DEFAULT_CACHE_SECONDS;
        }

        return min(self::MAX_CACHE_SECONDS, max(1, $value));
    }

    private function cacheKey(SakurupiahEnvironment $environment, string $fingerprint): string
    {
        return self::CACHE_KEY_PREFIX.'.v'.$this->cacheVersion().'.'.$environment->value.'.'.$fingerprint;
    }

    private function cacheVersion(): int
    {
        try {
            Cache::add(self::CACHE_VERSION_KEY, 1, now()->addYears(5));

            return max(1, (int) Cache::get(self::CACHE_VERSION_KEY, 1));
        } catch (Throwable) {
            return 1;
        }
    }

    private function configurationFingerprint(
        SakurupiahEnvironment $environment,
        bool $includeOperational = false,
    ): string {
        $identity = [
            'environment' => $environment->value,
            'enabled' => $this->value($environment, 'enabled', false),
            'api_id' => $this->secretDigest($this->value($environment, 'api_id')),
            'api_key' => $this->secretDigest($this->value($environment, 'api_key')),
            'base_url' => $this->normalizedUrl($this->value($environment, 'base_url')),
            'callback_url' => $this->normalizedUrl($this->value($environment, 'callback_url')),
            'return_url' => $this->normalizedUrl($this->value($environment, 'return_url')),
            'allowed_hosts' => $this->hosts($environment, 'allowed_hosts'),
            'callback_allowed_hosts' => $this->hosts($environment, 'callback_allowed_hosts'),
            'return_allowed_hosts' => $this->hosts($environment, 'return_allowed_hosts'),
            'allowed_ips' => $this->hosts($environment, 'allowed_ips'),
            'http_timeout_seconds' => config('services.sakurupiah.http_timeout_seconds', 10),
            'http_connect_timeout_seconds' => config('services.sakurupiah.http_connect_timeout_seconds', 3),
            'lookup_max_attempts' => config('services.sakurupiah.lookup_max_attempts', 3),
            'lookup_retry_delay_ms' => config('services.sakurupiah.lookup_retry_delay_ms', 250),
            'lookup_retry_max_delay_ms' => config('services.sakurupiah.lookup_retry_max_delay_ms', 1000),
            'lock_lease_seconds' => config('services.sakurupiah.lock_lease_seconds', 90),
            'webhook_rate_limit_per_minute' => config('services.sakurupiah.webhook_rate_limit_per_minute', 30),
            'status_refresh_rate_limit_per_minute' => config('services.sakurupiah.status_refresh_rate_limit_per_minute', 3),
            'readiness_cache_seconds' => config('services.sakurupiah.readiness_cache_seconds', self::DEFAULT_CACHE_SECONDS),
            'reconcile_terminal_lookback_hours' => config('services.sakurupiah.reconcile_terminal_lookback_hours', 72),
            'reconcile_max_execution_seconds' => config('services.sakurupiah.reconcile_max_execution_seconds', 45),
            'timezone' => config('services.sakurupiah.timezone', 'Asia/Jakarta'),
            'qris_contract' => config('services.sakurupiah.qris_contract', []),
        ];

        if ($includeOperational) {
            $identity['callback_route'] = $this->callbackRouteIdentity();
            $identity['queue'] = [
                'default' => config('queue.default'),
                'driver' => config('queue.connections.'.config('queue.default').'.driver'),
                'verification' => config('services.sakurupiah.verification_queue', self::SUPERVISED_VERIFICATION_QUEUE),
            ];
            $identity['schema'] = $this->schemaIdentity();
            $identity['reconciliation_command'] = config('services.sakurupiah.reconciliation_command');
            $identity['reconciliation_class'] = class_exists('App\\Console\\Commands\\ReconcileSakurupiahPayments');
        }

        try {
            return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            return hash('sha256', serialize($identity));
        }
    }

    private function secretDigest(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return hash('sha256', $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function callbackRouteIdentity(): array
    {
        try {
            $route = Route::getRoutes()->getByName(self::CALLBACK_ROUTE_NAME);
            if ($route === null) {
                return ['registered' => false];
            }

            return [
                'registered' => true,
                'methods' => $route->methods(),
                'uri' => $route->uri(),
            ];
        } catch (Throwable) {
            return ['registered' => null];
        }
    }

    /**
     * @return array<string, bool>
     */
    private function schemaIdentity(): array
    {
        $tables = [];
        foreach (['jobs', 'failed_jobs', 'job_batches', 'payments', 'payment_events'] as $table) {
            try {
                $tables[$table] = Schema::hasTable($table);
            } catch (Throwable) {
                $tables[$table] = false;
            }
        }

        return $tables;
    }
}
