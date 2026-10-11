<?php

namespace App\Console\Commands;

use App\Services\OrderCredentialMailService;
use Illuminate\Console\Command;
use Throwable;

final class RecoverOrderCredentialMails extends Command
{
    protected $signature = 'orders:recover-credential-mails
        {--limit=100 : Maximum rows per phase, from 1 to 1000}
        {--since-hours=72 : Look-back window for paid orders without a handoff row, 1 to 720}
        {--json : Print a sanitized JSON report}';

    protected $description = 'Redispatch and materialize durable credential email delivery for paid web storefront orders.';

    public function handle(OrderCredentialMailService $mails): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 1000) {
            $this->error('Recovery limit must be between 1 and 1000.');

            return self::FAILURE;
        }

        $sinceHours = filter_var($this->option('since-hours'), FILTER_VALIDATE_INT);
        if ($sinceHours === false || $sinceHours < 1 || $sinceHours > 720) {
            $this->error('Look-back hours must be between 1 and 720.');

            return self::FAILURE;
        }

        try {
            $report = $mails->recover($limit, $sinceHours) + ['mailer_state' => $mails->mailerState()];
        } catch (Throwable) {
            $this->error('Credential email recovery temporarily unavailable.');

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Mailer %s · scanned %d paid order(s) · created %d handoff row(s) · dispatched %d pending delivery(ies).',
            $report['mailer_state'],
            $report['scanned'],
            $report['created'],
            $report['dispatched'],
        ));

        if ($report['mailer_state'] !== 'ready') {
            $this->warn('The configured mailer is not a ready SMTP transport; credential delivery stays queued without sending.');
        }

        return self::SUCCESS;
    }
}
