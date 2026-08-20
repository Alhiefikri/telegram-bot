<?php

namespace App\Console\Commands;

use App\Services\Credentials\CredentialIdentityBackfillReadinessService;
use Illuminate\Console\Command;
use Throwable;

final class CheckCredentialIdentityReadiness extends Command
{
    protected $signature = 'credentials:check-identity-readiness
        {--json : Print a sanitized JSON readiness report}';

    protected $description = 'Verify the persisted Credential Identity backfill readiness used by Account Input runtime gating.';

    public function handle(CredentialIdentityBackfillReadinessService $readiness): int
    {
        try {
            $state = $readiness->current();
            $payload = [
                'ready' => $readiness->isReady(),
                'status' => $state?->status ?? 'missing',
                'scanned' => (int) ($state?->scanned_count ?? 0),
                'registered' => (int) ($state?->registered_count ?? 0),
                'already_registered' => (int) ($state?->already_registered_count ?? 0),
                'skipped_unrecognized' => (int) ($state?->skipped_unrecognized_count ?? 0),
                'conflicts' => (int) ($state?->conflict_count ?? 0),
                'unresolved' => (int) ($state?->unresolved_count ?? 0),
                'last_id' => (int) ($state?->last_processed_id ?? 0),
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($payload, JSON_THROW_ON_ERROR));
            } else {
                $this->line(sprintf(
                    'SUMMARY status=%s ready=%s scanned=%d registered=%d already_registered=%d skipped_unrecognized=%d conflicts=%d unresolved=%d last_id=%d',
                    $payload['status'],
                    $payload['ready'] ? 'true' : 'false',
                    $payload['scanned'],
                    $payload['registered'],
                    $payload['already_registered'],
                    $payload['skipped_unrecognized'],
                    $payload['conflicts'],
                    $payload['unresolved'],
                    $payload['last_id'],
                ));
            }

            if ($payload['ready'] === true) {
                return self::SUCCESS;
            }
        } catch (Throwable) {
            $this->error('Credential identity readiness check failed safely.');

            return self::FAILURE;
        }

        $this->error('Credential identity backfill readiness is not established; Account Input remains disabled.');

        return self::FAILURE;
    }
}
