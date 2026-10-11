<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CleanupCustomerAuthTokens extends Command
{
    protected $signature = 'customer-auth:cleanup
        {--limit=500 : Maximum rows per token table, from 1 to 1000}
        {--legacy-only : Remove only eligible legacy magic links}';

    protected $description = 'Remove consumed or expired customer auth challenges without deleting retained mail work.';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 1000) {
            $this->error('Cleanup limit must be between 1 and 1000.');

            return self::FAILURE;
        }

        try {
            $cutoff = now();
            $counts = DB::transaction(function () use ($limit, $cutoff): array {
                $tokens = DB::table('customer_auth_tokens')->where(function (Builder $query) use ($cutoff): void {
                    $query->whereNotNull('consumed_at')->orWhere('expires_at', '<=', $cutoff);
                });
                $links = DB::table('customer_login_links')->where(function (Builder $query) use ($cutoff): void {
                    $query->whereNotNull('used_at')->orWhere('expires_at', '<=', $cutoff);
                });

                $claimIds = $this->option('legacy-only') ? collect() : DB::table('customer_telegram_auth_claims')
                    ->where(function (Builder $query) use ($cutoff): void {
                        $query->whereNotNull('consumed_at')->orWhereNotNull('failure')->orWhere('expires_at', '<=', $cutoff);
                    })->orderBy('id')->limit($limit)->lockForUpdate()->pluck('id');
                $googleClaimIds = $this->option('legacy-only') ? collect() : DB::table('customer_google_auth_claims')
                    ->where(function (Builder $query) use ($cutoff): void {
                        $query->whereNotNull('consumed_at')->orWhere('expires_at', '<=', $cutoff);
                    })->orderBy('id')->limit($limit)->lockForUpdate()->pluck('id');

                return [
                    'tokens' => $this->option('legacy-only') ? 0 : $this->deletePage($tokens, 'customer_auth_tokens', 'auth_token_id', $limit),
                    'links' => $this->deletePage($links, 'customer_login_links', 'login_link_id', $limit),
                    'telegram_claims' => $claimIds->isEmpty() ? 0 : DB::table('customer_telegram_auth_claims')->whereIn('id', $claimIds)->delete(),
                    'google_claims' => $googleClaimIds->isEmpty() ? 0 : DB::table('customer_google_auth_claims')->whereIn('id', $googleClaimIds)->delete(),
                ];
            });
            $this->info("Deleted customer auth challenges: tokens={$counts['tokens']} login_links={$counts['links']} telegram_claims={$counts['telegram_claims']} google_claims={$counts['google_claims']}.");

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Customer authentication cleanup temporarily unavailable.');

            return self::FAILURE;
        }
    }

    private function deletePage(Builder $query, string $table, string $foreignKey, int $limit): int
    {
        // Mail recovery owns ciphertext removal and retention. The FK keeps work
        // attached until that lifecycle finishes, including an in-flight lease.
        $query->whereNotExists(function (Builder $work) use ($table, $foreignKey): void {
            $work->selectRaw('1')->from('customer_auth_mail_works')
                ->whereColumn("customer_auth_mail_works.{$foreignKey}", "{$table}.id");
        });
        $ids = (clone $query)->orderBy('id')->limit($limit)->lockForUpdate()->pluck('id');

        return $ids->isEmpty() ? 0 : $query->whereIn("{$table}.id", $ids)->delete();
    }
}
