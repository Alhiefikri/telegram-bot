<?php

namespace App\Console\Commands;

use App\Services\Customer\CustomerAuthAvailability;
use App\Services\Customer\CustomerTelegramAuthenticationService;
use App\Services\Customer\GoogleOAuthProvider;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ReportCustomerAuthReadiness extends Command
{
    protected $signature = 'customer-auth:readiness {--limit=1000 : Maximum counted rows per category, from 1 to 10000}';

    protected $description = 'Report bounded sanitized auth configuration and backlog; never certify live provider evidence.';

    public function handle(CustomerAuthAvailability $availability, GoogleOAuthProvider $google, CustomerTelegramAuthenticationService $telegram): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 10000) {
            $this->error('Readiness limit must be between 1 and 10000.');

            return self::FAILURE;
        }
        try {
            $now = now();
            $counts = [];
            foreach (['customer_auth_tokens' => 'consumed_at', 'customer_login_links' => 'used_at', 'customer_google_auth_claims' => 'consumed_at', 'customer_telegram_auth_claims' => 'consumed_at'] as $table => $terminal) {
                $query = DB::table($table)->whereNull($terminal)->where('expires_at', '>', $now);
                if ($table === 'customer_telegram_auth_claims') {
                    $query->whereNull('failure');
                }
                $counts[$table] = $this->boundedCount($query, $limit);
            }
            $counts['expired_pending_mail'] = $this->boundedCount(DB::table('customer_auth_mail_works')->where('status', 'pending')->where('expires_at', '<=', $now), $limit);
            $counts['retrying_failed_mail'] = $this->boundedCount(DB::table('customer_auth_mail_works')->where('status', 'pending')->whereNotNull('last_error_code'), $limit);
            $counts['failed_mail'] = $this->boundedCount(DB::table('customer_auth_mail_works')->where('status', 'failed'), $limit);
            $counts['notifications_failed_jobs'] = $this->boundedCount(DB::table('failed_jobs')->where('queue', 'notifications'), $limit);
            $this->line(json_encode([
                'new_methods_enabled' => $availability->enabled(),
                'magic_link_guest_recovery' => 'retained',
                'configuration' => [
                    'resend_smtp_safe' => $this->mailConfigured(),
                    'google' => $google->ready(),
                    'telegram' => $telegram->ready(),
                    'postgresql' => DB::getDriverName() === 'pgsql',
                    'redis_cache' => config('cache.stores.'.config('cache.default').'.driver') === 'redis',
                    'redis_session' => config('session.driver') === 'redis',
                    'redis_queue' => config('queue.connections.'.config('queue.default').'.driver') === 'redis',
                    'secure_session_cookie' => config('session.secure') === true,
                    'canonical_https_origin' => $google->callbackUrl() !== null,
                ],
                'counts' => $counts,
                'count_limit' => $limit,
                'live_evidence' => ['resend_inbox' => 'unverified', 'google_oauth' => 'unverified', 'private_store_bot' => 'unverified'],
                'release_ready' => false,
                'scope' => 'local_configuration_only_no_provider_or_worker_probe',
            ], JSON_THROW_ON_ERROR));

            // Configuration and capped counts cannot supply the operator-owned live release evidence.
            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Customer authentication readiness temporarily unavailable.');

            return self::FAILURE;
        }
    }

    private function mailConfigured(): bool
    {
        $smtp = config('mail.mailers.smtp', []);

        return config('mail.default') === 'smtp'
            && ($smtp['transport'] ?? null) === 'smtp'
            && ($smtp['scheme'] ?? null) === 'smtp'
            && blank($smtp['url'] ?? null)
            && ($smtp['host'] ?? null) === 'smtp.resend.com'
            && (int) ($smtp['port'] ?? 0) === 587
            && ($smtp['username'] ?? null) === 'resend'
            && is_string($smtp['password'] ?? null) && trim($smtp['password']) !== ''
            && ($smtp['auto_tls'] ?? null) === true
            && ($smtp['require_tls'] ?? null) === true
            && config('mail.from.address') === 'no-reply@notify.lhiestore.web.id';
    }

    private function boundedCount(Builder $query, int $limit): array
    {
        $count = DB::query()->fromSub($query->select('id')->limit($limit + 1), 'bounded_auth')->count();

        return ['count' => min($limit, $count), 'capped' => $count > $limit];
    }
}
