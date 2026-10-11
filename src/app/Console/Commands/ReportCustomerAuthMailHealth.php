<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ReportCustomerAuthMailHealth extends Command
{
    protected $signature = 'customer-auth:mail-health {--limit=1000 : Maximum counted rows per category, from 1 to 10000}';

    protected $description = 'Report bounded customer auth mail counts without decrypting payloads or querying Resend.';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 10000) {
            $this->error('Health limit must be between 1 and 10000.');

            return self::FAILURE;
        }
        try {
            $now = now();
            $pending = DB::table('customer_auth_mail_works')->where('status', 'pending');
            $counts = [
                'due' => $this->boundedCount((clone $pending)->where('expires_at', '>', $now)->where('next_attempt_at', '<=', $now), $limit),
                'deferred' => $this->boundedCount((clone $pending)->where('expires_at', '>', $now)->where('next_attempt_at', '>', $now), $limit),
                'expired_pending' => $this->boundedCount((clone $pending)->where('expires_at', '<=', $now), $limit),
                'retrying_failed' => $this->boundedCount((clone $pending)->whereNotNull('last_error_code'), $limit),
            ];
            foreach (['sent', 'cancelled', 'failed'] as $status) {
                $counts[$status] = $this->boundedCount(DB::table('customer_auth_mail_works')->where('status', $status), $limit);
            }
            $counts['notifications_failed_jobs'] = $this->boundedCount(DB::table('failed_jobs')->where('queue', 'notifications'), $limit);
            $report = [
                'queue' => 'notifications',
                'counts' => $counts,
                'count_limit' => $limit,
                'smtp_selected' => config('mail.default') === 'smtp',
                'credential_present' => filled(config('mail.mailers.smtp.password')),
                'tls_required' => config('mail.mailers.smtp.require_tls') === true,
                'provider_delivery' => 'unverified',
                'sent_means' => 'transport_accepted_not_inbox_delivery',
            ];
            $this->line(json_encode($report, JSON_THROW_ON_ERROR));

            return $counts['failed']['count'] > 0 || $counts['retrying_failed']['count'] > 0 || $counts['expired_pending']['count'] > 0 || $counts['notifications_failed_jobs']['count'] > 0
                ? self::FAILURE : self::SUCCESS;
        } catch (Throwable) {
            $this->error('Customer authentication mail health temporarily unavailable.');

            return self::FAILURE;
        }
    }

    private function boundedCount(Builder $query, int $limit): array
    {
        $count = DB::query()->fromSub($query->select('id')->limit($limit + 1), 'bounded_work')->count();

        return ['count' => min($limit, $count), 'capped' => $count > $limit];
    }
}
