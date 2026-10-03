<?php

namespace App\Console\Commands;

use App\Jobs\ProcessBuyMailWebhook;
use App\Jobs\ProcessSupplierDeposit;
use App\Jobs\ProcessWarrantyClaim;
use App\Jobs\ProcessWarrantyConfirmation;
use App\Jobs\ProcessWarrantyReplacement;
use App\Models\SupplierAccountUnit;
use App\Models\SupplierDeposit;
use App\Models\SupplierWebhookEvent;
use App\Models\WarrantyClaim;
use App\Services\Warranty\WarrantyDeadlineReminderService;
use Illuminate\Console\Command;

final class RecoverSupplierWarranty extends Command
{
    protected $signature = 'suppliers:recover-warranty {--limit=25 : Maximum records per work type (1-100)}';

    protected $description = 'Rediscover durable warranty claims, replacements and signed inbox work without provider I/O';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 100) {
            $this->error('Use a limit between 1 and 100.');

            return self::INVALID;
        }
        $claims = WarrantyClaim::query()->whereIn('state', ['received', 'submitting', 'submission_unknown', 'pending', 'approved', 'review'])
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now()))
            ->orderByRaw("CASE WHEN state = 'received' THEN 0 WHEN state = 'approved' THEN 2 ELSE 1 END")
            ->orderBy('next_attempt_at')->orderBy('id')->limit($limit)->pluck('id');
        foreach ($claims as $id) {
            ProcessWarrantyClaim::dispatch($id);
        }
        $replacements = WarrantyClaim::query()->where('settlement_choice', 'replacement')
            ->whereIn('settlement_state', ['replacement_pending', 'replacement_sending', 'replacement_unknown'])
            ->where(fn ($q) => $q->whereNull('replacement_next_attempt_at')->orWhere('replacement_next_attempt_at', '<=', now()))
            ->orderBy('replacement_next_attempt_at')->orderBy('id')->limit($limit)->pluck('id');
        foreach ($replacements as $id) {
            ProcessWarrantyReplacement::dispatch($id);
        }
        $events = SupplierWebhookEvent::query()->whereIn('state', ['received', 'processing', 'waiting'])
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now()))
            ->orderBy('next_attempt_at')->orderBy('id')->limit($limit)->pluck('id');
        foreach ($events as $id) {
            ProcessBuyMailWebhook::dispatch($id);
        }
        $deposits = SupplierDeposit::query()->whereIn('state', ['requested', 'sending', 'unknown', 'pending', 'cancel_requested'])
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now()))
            ->orderBy('next_attempt_at')->orderBy('id')->limit($limit)->pluck('id');
        foreach ($deposits as $id) {
            ProcessSupplierDeposit::dispatch($id);
        }
        $confirmations = SupplierAccountUnit::query()->whereIn('confirmation_state', ['requested', 'sending', 'unknown'])
            ->where(fn ($q) => $q->whereNull('confirmation_next_attempt_at')->orWhere('confirmation_next_attempt_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('confirmation_lease_until')->orWhere('confirmation_lease_until', '<=', now()))
            ->orderBy('confirmation_next_attempt_at')->orderBy('id')->limit($limit)->pluck('id');
        foreach ($confirmations as $id) {
            ProcessWarrantyConfirmation::dispatch($id);
        }
        $reminders = app(WarrantyDeadlineReminderService::class)->run($limit);
        $this->info('Warranty wakeups: '.$claims->count().' claims, '.$replacements->count().' replacements, '.$events->count().' webhook events, '.$reminders['materialized'].' reminders materialized, '.$reminders['dispatched'].' reminders dispatched.');

        return self::SUCCESS;
    }
}
