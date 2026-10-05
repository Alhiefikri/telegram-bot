<?php

namespace App\Console\Commands;

use App\Models\ExternalMoneyReceiptReservation;
use App\Services\AuditLogger;
use App\Services\MoneyReceipts\ExternalMoneyReceiptReservations;
use Illuminate\Console\Command;

final class ResolveExternalMoneyReceiptReservation extends Command
{
    protected $signature = 'balance:resolve-money-receipt-reservation
        {id : External money receipt reservation ID}
        {--note= : Operator review note recorded with the resolution (required)}
        {--actor= : Operator label recorded in the audit trail}';

    protected $description = 'Resolve one external money receipt reservation after operator review so it no longer blocks the cutover gate.';

    public function handle(ExternalMoneyReceiptReservations $reservations, AuditLogger $audit): int
    {
        $id = trim((string) $this->argument('id'));
        $note = trim((string) $this->option('note'));
        $actor = trim((string) $this->option('actor'));

        if (ctype_digit($id) !== true || (int) $id < 1) {
            $this->error('Reservation resolution failed: id must be a positive integer.');

            return self::FAILURE;
        }
        if ($note === '') {
            $this->error('Reservation resolution failed: --note is required.');

            return self::FAILURE;
        }

        $reservation = ExternalMoneyReceiptReservation::query()->whereKey((int) $id)->first();
        if ($reservation === null) {
            $this->error('Reservation resolution failed: reservation not found.');

            return self::FAILURE;
        }

        if ($reservation->resolved_at !== null) {
            $this->line('Reservation '.$reservation->getKey().' was already resolved.');

            return self::SUCCESS;
        }

        $resolved = $reservations->resolveById((int) $reservation->getKey());
        if ($resolved === null || $resolved->resolved_at === null) {
            $this->error('Reservation resolution failed: reservation could not be resolved.');

            return self::FAILURE;
        }

        $audit->logWithResolvedContext(
            'money_receipt.reservation_resolved',
            $resolved,
            old: ['resolved_at' => null],
            new: [
                'resolved_at' => $resolved->resolved_at->toIso8601String(),
                'reason' => (string) $resolved->reason,
                'note' => $note,
                'actor' => $actor === '' ? null : $actor,
            ],
        );

        $this->line(sprintf(
            'Resolved reservation %d source=%s legacy=%s:%s reason=%s',
            $resolved->getKey(),
            $resolved->source,
            $resolved->legacy_type,
            $resolved->legacy_id,
            $resolved->reason,
        ));

        return self::SUCCESS;
    }
}
