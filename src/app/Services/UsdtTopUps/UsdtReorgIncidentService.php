<?php

namespace App\Services\UsdtTopUps;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerUsdtBalanceAccount;
use App\Models\CustomerUsdtDepositReceiver;
use App\Models\CustomerUsdtTopUp;
use App\Models\UsdtReorgIncident;
use App\Models\UsdtReorgRecoveryCase;
use App\Models\UsdtReorgRecoveryRequest;
use App\Models\User;
use App\Services\Bsc\BscChainFacts;
use App\Services\Bsc\BscVerificationRpcClientInterface;
use App\Services\StoreBalance\BalanceAdjustmentPasswordConfirmation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Reorgs preserve money history. Recovery authority is a case, never a debit or asset. */
final class UsdtReorgIncidentService
{
    private const REORG_REASONS = ['checkpoint_reorg', 'log_block_reorg', 'page_boundary_reorg', 'finalized_block_changed'];

    public function __construct(
        private readonly BscVerificationRpcClientInterface $rpc,
        private readonly BalanceAdjustmentPasswordConfirmation $passwords,
    ) {}

    /** Durable entry-id keyset rotates all credited targets, including retired receivers. */
    public function recover(int $limit = 25): int
    {
        $this->outsideTransaction();
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Reorg monitor batch must be between 1 and 100.');
        }
        $monitor = DB::table('usdt_reorg_monitor')->where('id', 1)->firstOrFail();
        $rows = DB::table('customer_usdt_top_up_resolutions')
            ->whereNotNull('entry_id')->where('entry_id', '>', $monitor->cursor)
            ->orderBy('entry_id')->limit($limit)->get(['top_up_id', 'entry_id']);
        $processed = 0;
        $cursor = (int) $monitor->cursor;
        foreach ($rows as $row) {
            try {
                $processed += $this->check($row->top_up_id) ? 1 : 0;
            } catch (Throwable) {
                // Unavailable RPC is not reorg evidence. The next durable cycle
                // revisits this entry; one failure cannot starve later targets.
            }
            $cursor = (int) $row->entry_id;
        }
        DB::table('usdt_reorg_monitor')->where('id', 1)->where('version', $monitor->version)->update([
            'cursor' => $rows->isEmpty() ? 0 : $cursor,
            'version' => (int) $monitor->version + 1, 'updated_at' => now(),
        ]);

        return $processed;
    }

    public function check(string $topUpId): bool
    {
        $this->outsideTransaction();
        if (UsdtReorgIncident::query()->where('top_up_id', $topUpId)->exists()) {
            return false;
        }
        $target = CustomerUsdtTopUp::query()->find($topUpId);
        $resolution = DB::table('customer_usdt_top_up_resolutions')->where('top_up_id', $topUpId)->first();
        if ($target === null || $resolution?->entry_id === null || $resolution->receipt_id === null) {
            return false; // Before settlement there is no credited exposure.
        }
        $receipt = DB::table('external_money_receipts')->where('id', $resolution->receipt_id)->firstOrFail();
        $original = json_decode($receipt->evidence_payload, true, flags: JSON_THROW_ON_ERROR);
        $checkpoint = DB::table('usdt_bsc_scan_checkpoints')->where('receiver_id', $target->receiver_id)->first();
        $reason = in_array($checkpoint?->review_reason, self::REORG_REASONS, true) ? $checkpoint->review_reason : null;
        $canonical = null;
        if ($reason === null) {
            $number = $original['block_number'] ?? null;
            $hash = BscChainFacts::hash($original['block_hash'] ?? null);
            if (! is_int($number) || $number < 0 || $hash === null || $this->rpc->chainId() !== 56) {
                return false;
            }
            $canonical = BscChainFacts::block($this->rpc->blockByNumber($number));
            if ($canonical === null || $canonical['number'] !== $number || $canonical['hash'] === $hash) {
                return false;
            }
            // Require stable authoritative canonical evidence, not transient RPC
            // absence, malformed payload, or two different reads during a fork.
            $again = BscChainFacts::block($this->rpc->blockByNumber($number));
            if ($again !== $canonical) {
                return false;
            }
            $reason = 'settled_receipt_block_reorg';
        }

        return DB::transaction(function () use ($target, $resolution, $checkpoint, $reason, $original, $canonical): bool {
            Customer::query()->whereKey($target->customer_id)->lockForUpdate()->firstOrFail();
            $account = CustomerUsdtBalanceAccount::query()->where('customer_id', $target->customer_id)->lockForUpdate()->firstOrFail();
            CustomerUsdtTopUp::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            CustomerUsdtDepositReceiver::query()->whereKey($target->receiver_id)->lockForUpdate()->firstOrFail();
            $current = DB::table('usdt_bsc_scan_checkpoints')->where('receiver_id', $target->receiver_id)->lockForUpdate()->first();
            if ($current?->version !== $checkpoint?->version || $current?->review_reason !== $checkpoint?->review_reason) {
                return false;
            }
            $settled = DB::table('customer_usdt_top_up_resolutions')->where('top_up_id', $target->id)->firstOrFail();
            if ($settled->entry_id !== $resolution->entry_id || $settled->receipt_id !== $resolution->receipt_id
                || UsdtReorgIncident::query()->where('top_up_id', $target->id)->exists()) {
                return false;
            }
            $entry = DB::table('customer_usdt_balance_entries')->where('id', $settled->entry_id)->firstOrFail();
            $exposure = (int) $entry->amount_micro;
            // Available exposure is conservative account-level containment, not
            // invented tracing of fungible funds. Spent exposure remains a loss.
            $alreadyContained = (int) DB::table('usdt_reorg_incidents')->where('account_id', $account->id)->sum('available_exposure_micro');
            $available = min($exposure, max(0, (int) $account->balance_micro - $alreadyContained));
            $receiverEvidence = DB::table('usdt_reorg_receiver_evidence')->where('receiver_id', $target->receiver_id)
                ->where('checkpoint_version', $current?->version)->first();
            $incidentId = DB::table('usdt_reorg_incidents')->insertGetId([
                'top_up_id' => $target->id, 'customer_id' => $target->customer_id, 'account_id' => $account->id,
                'receipt_id' => $settled->receipt_id, 'entry_id' => $settled->entry_id, 'receiver_id' => $target->receiver_id,
                'reason' => $reason, 'exposure_micro' => $exposure, 'available_exposure_micro' => $available,
                'spent_exposure_micro' => $exposure - $available, 'account_version' => $account->version,
                'checkpoint_version' => $current?->version,
                'evidence' => json_encode(['original_receipt' => $original, 'canonical_block' => $canonical,
                    'receiver_detection' => $receiverEvidence === null ? null : json_decode($receiverEvidence->evidence, true, flags: JSON_THROW_ON_ERROR),
                    'checkpoint' => $current === null ? null : ['last_block' => $current->last_block,
                        'last_block_hash' => $current->last_block_hash, 'review_reason' => $current->review_reason]], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
            DB::table('customer_usdt_balance_accounts')->where('id', $account->id)->whereNull('frozen_reason')
                ->update(['frozen_reason' => 'usdt_reorg_incident:'.$incidentId, 'updated_at' => now()]);
            AuditLog::create(['action' => 'usdt_reorg.detected', 'subject_type' => UsdtReorgIncident::class,
                'subject_id' => $incidentId, 'new_values' => ['incident_id' => $incidentId,
                    'currency' => 'USDT', 'exposure_micro' => (string) $exposure,
                    'spent_exposure_micro' => (string) ($exposure - $available), 'reason' => $reason]]);

            return true;
        }, 5);
    }

    /** Immutable request binds one Owner, browser session, credentials and reason. */
    public function requestRecovery(User $actor, int $incidentId, string $nonce, string $reason): UsdtReorgRecoveryRequest
    {
        $this->outsideTransaction();
        $actor = $this->owner($actor);
        $reason = trim($reason);
        if (! Str::isUuid($nonce) || $reason === '' || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('Recovery needs a UUID nonce and a reason of at most 500 characters.');
        }
        $session = $this->sessionBinding();
        $credential = hash('sha256', $actor->password);
        $fingerprint = hash('sha256', json_encode([$incidentId, $actor->id, $nonce, $reason, $session, $credential], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $incidentId, $nonce, $reason, $session, $credential, $fingerprint): UsdtReorgRecoveryRequest {
            User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $this->owner($actor);
            UsdtReorgIncident::query()->whereKey($incidentId)->firstOrFail();
            $existing = UsdtReorgRecoveryRequest::query()->where('owner_user_id', $actor->id)->where('nonce', $nonce)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->fingerprint, $fingerprint)) {
                    throw new InvalidArgumentException('Recovery request nonce conflicts with immutable content.');
                }

                return $existing;
            }
            $id = (string) Str::uuid();
            DB::table('usdt_reorg_recovery_requests')->insert([
                'id' => $id, 'incident_id' => $incidentId, 'owner_user_id' => $actor->id, 'nonce' => $nonce,
                'reason' => $reason, 'fingerprint' => $fingerprint, 'credential_binding' => $credential,
                'session_binding' => $session, 'expires_at' => now()->addMinutes(10), 'created_at' => now(),
            ]);

            return UsdtReorgRecoveryRequest::query()->findOrFail($id);
        }, 5);
    }

    public function confirmRecovery(User $actor, string $requestId, string $password, ?string $fingerprint = null): UsdtReorgRecoveryCase
    {
        $this->outsideTransaction();
        $actor = $this->owner($actor);
        $request = UsdtReorgRecoveryRequest::query()->findOrFail($requestId);
        $this->assertRequest($actor, $request);
        if ($fingerprint !== null && ! hash_equals($request->fingerprint, $fingerprint)) {
            throw new InvalidArgumentException('Recovery preview fingerprint changed.');
        }
        $approval = $this->passwords->verify($actor, $password, $request);
        try {
            return DB::transaction(function () use ($actor, $request, $approval): UsdtReorgRecoveryCase {
                User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $actor = $this->owner($actor);
                $this->assertRequest($actor, $request);
                $approval->assertMatches($request);
                UsdtReorgIncident::query()->whereKey($request->incident_id)->lockForUpdate()->firstOrFail();
                $this->passwords->consume($actor, $approval->token);
                $existing = UsdtReorgRecoveryCase::query()->where('incident_id', $request->incident_id)->first();
                if ($existing !== null) {
                    if ($existing->request_id !== $request->id) {
                        throw new InvalidArgumentException('Incident already has an authorized recovery case.');
                    }

                    return $existing;
                }
                $id = DB::table('usdt_reorg_recovery_cases')->insertGetId([
                    'incident_id' => $request->incident_id, 'request_id' => $request->id,
                    'authorized_by' => $actor->id, 'status' => 'authorized_unresolved', 'created_at' => now(),
                ]);
                AuditLog::create(['user_id' => $actor->id, 'action' => 'usdt_reorg.recovery_authorized',
                    'subject_type' => UsdtReorgRecoveryCase::class, 'subject_id' => $id,
                    'new_values' => ['incident_id' => $request->incident_id, 'request_id' => $request->id,
                        'status' => 'authorized_unresolved', 'currency' => 'USDT']]);

                return UsdtReorgRecoveryCase::query()->findOrFail($id);
            }, 5);
        } finally {
            $this->passwords->release($actor, $approval->token);
        }
    }

    private function assertRequest(User $actor, UsdtReorgRecoveryRequest $request): void
    {
        if ((int) $request->owner_user_id !== (int) $actor->id || $request->expires_at->isPast()
            || ! hash_equals($request->session_binding, $this->sessionBinding())
            || ! hash_equals($request->credential_binding, hash('sha256', $actor->password))) {
            throw new AuthorizationException('Recovery request is stale or belongs to another Owner/session.');
        }
    }

    private function owner(User $actor): User
    {
        $fresh = $actor->fresh();
        if ($fresh?->role !== UserRole::Owner || ! $fresh->canAccessAdmin() || (int) Auth::id() !== (int) $fresh->id) {
            throw new AuthorizationException('Recovery authorization requires the authenticated Owner.');
        }

        return $fresh;
    }

    private function sessionBinding(): string
    {
        if (! request()->hasSession()) {
            throw new AuthorizationException('Recovery authorization requires an authenticated session.');
        }

        return hash('sha256', request()->session()->getId());
    }

    private function outsideTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Reorg inspection/authentication requires an independent transaction.');
        }
    }
}
