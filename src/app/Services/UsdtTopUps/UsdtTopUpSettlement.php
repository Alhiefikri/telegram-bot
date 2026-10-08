<?php

namespace App\Services\UsdtTopUps;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerUsdtBalanceEntry;
use App\Models\CustomerUsdtDepositReceiver;
use App\Models\CustomerUsdtFundingReservation;
use App\Models\CustomerUsdtTopUp;
use App\Models\UsdtBscRailInvoice;
use App\Services\Bsc\BscFinalReceiptInspection;
use App\Services\Bsc\BscTransactionHash;
use App\Services\MoneyReceipts\ExternalMoneyReceiptClaims;
use App\Services\MoneyReceipts\MoneyReceiptIdentity;
use App\Services\MoneyReceipts\MoneyReceiptParentClaim;
use App\Services\MoneyReceipts\MoneyReceiptTarget;
use App\Services\MoneyReceipts\VerifiedMoneyReceipt;
use App\Services\Telegram\UsdtTopUpOutbound;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\VerifiedUsdtFunding;
use App\Services\UsdtFunding\CustomerUsdtFundingReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Durable hints are not money authority. Only fenced authoritative inspection is. */
final class UsdtTopUpSettlement
{
    private const TERMINAL = ['settled', 'externally_refunded', 'resolved_by_compensation', 'rejected'];

    public function __construct(
        private readonly UsdtTopUpRail $rail,
        private readonly CustomerUsdtBalanceLedger $ledger,
        private readonly ExternalMoneyReceiptClaims $receipts,
        private readonly CustomerUsdtFundingReservationService $reservations,
        private readonly UsdtTopUpOutbound $outbound,
    ) {}

    /** Trusted channel adapter supplies the authenticated Customer, never a client-selected owner. */
    public function submit(Customer $customer, string $topUpId, string $transactionHash): void
    {
        $hash = BscTransactionHash::normalize($transactionHash);
        if ($hash === null || strlen($transactionHash) !== 66) {
            throw new InvalidArgumentException('Invalid BSC transaction hash.');
        }
        DB::transaction(function () use ($customer, $topUpId, $hash): void {
            $target = CustomerUsdtTopUp::query()->whereKey($topUpId)->where('customer_id', $customer->id)->lockForUpdate()->first();
            if ($target === null) {
                throw new AuthorizationException('USDT funding target unavailable.');
            }
            $work = DB::table('customer_usdt_top_up_settlement_work')->where('top_up_id', $topUpId)->lockForUpdate()->firstOrFail();
            if (DB::table('customer_usdt_top_up_inbox')->where('top_up_id', $topUpId)->where('tx_hash', $hash)->exists()) {
                return;
            }
            // Persistent bounds supplement channel throttling. Ingestion is not
            // subject to this customer-hint budget, so spam cannot hide money.
            if (DB::table('customer_usdt_top_up_inbox')->where('top_up_id', $topUpId)->count() >= 100) {
                throw new InvalidArgumentException('USDT lookup hint budget exhausted.');
            }
            $this->insertHint($topUpId, $hash);
            DB::table('customer_usdt_top_up_settlement_work')->where('top_up_id', $topUpId)->update([
                'version' => (int) $work->version + 1, 'lease_token' => null, 'lease_until' => null,
                'next_check_at' => now(), 'updated_at' => now(),
            ]);
        }, 5);
    }

    public function recover(int $limit = 25): int
    {
        $this->outsideTransaction();
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('USDT settlement batch must be between 1 and 100.');
        }
        $ids = DB::table('customer_usdt_top_up_settlement_work as work')
            ->join('customer_usdt_top_ups as targets', 'targets.id', '=', 'work.top_up_id')
            ->where('targets.rail', 'bsc_usdt')
            ->where('work.next_check_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('work.lease_until')->orWhere('work.lease_until', '<=', now()))
            ->orderBy('work.next_check_at')->orderBy('work.top_up_id')->limit($limit)->pluck('work.top_up_id');
        $processed = 0;
        foreach ($ids as $id) {
            try {
                $processed += $this->check($id) ? 1 : 0;
            } catch (Throwable) {
                // Each failed target retains its DB obligation. Never log RPC
                // endpoints, hashes, payloads or exception messages here.
                $this->deferFailed($id);
            }
        }

        return $processed;
    }

    public function check(string $topUpId): bool
    {
        $this->outsideTransaction();
        $claim = DB::transaction(function () use ($topUpId): ?array {
            // Hint insertion takes a target FK lock. Acquire it before work,
            // matching finalization instead of introducing work -> target.
            $targetQuery = CustomerUsdtTopUp::query()->whereKey($topUpId);
            $target = (DB::getDriverName() === 'pgsql' ? $targetQuery->lock('FOR UPDATE SKIP LOCKED') : $targetQuery->lockForUpdate())->first();
            if ($target === null) {
                return null;
            }
            $query = DB::table('customer_usdt_top_up_settlement_work')->where('top_up_id', $topUpId);
            $work = (DB::getDriverName() === 'pgsql' ? $query->lock('FOR UPDATE SKIP LOCKED') : $query->lockForUpdate())->first();
            if ($work === null || CarbonImmutable::parse($work->next_check_at)->isFuture()
                || ($work->lease_until !== null && CarbonImmutable::parse($work->lease_until)->isFuture())) {
                return null;
            }
            if ($target->rail !== 'bsc_usdt' || $target->receiver_id === null) {
                $this->updateWork($work, ['next_check_at' => now()->addMinute(), 'last_error' => 'wrong_rail']);

                return null;
            }
            $invoice = UsdtBscRailInvoice::query()->where('reference', $target->reference)->first();
            if ($invoice === null) {
                $this->updateWork($work, ['next_check_at' => now()->addMinute()]);

                return null;
            }
            $observations = DB::table('usdt_bsc_transfer_observations')->where('receiver_id', $target->receiver_id)
                ->where('id', '>', $work->observation_cursor)->orderBy('id')->limit(25)->get();
            $cursor = (int) $work->observation_cursor;
            foreach ($observations as $observation) {
                $this->insertHint($topUpId, $observation->tx_hash);
                $cursor = (int) $observation->id;
            }
            $inbox = DB::table('customer_usdt_top_up_inbox')->where('top_up_id', $topUpId)
                ->where('state', 'pending')->where('next_check_at', '<=', now())->orderBy('next_check_at')->orderBy('id')->first();
            $checkpoint = DB::table('usdt_bsc_scan_checkpoints')->where('receiver_id', $target->receiver_id)->first();
            $token = bin2hex(random_bytes(16));
            $this->updateWork($work, [
                'observation_cursor' => $cursor, 'lease_token' => $token, 'lease_until' => now()->addMinutes(2),
                'attempts' => (int) $work->attempts + 1,
            ]);

            return ['target' => $target, 'invoice' => $invoice, 'inbox' => $inbox, 'token' => $token,
                'version' => (int) $work->version + 1, 'checkpoint_version' => $checkpoint?->version];
        }, 5);
        if ($claim === null) {
            return false;
        }
        $inspection = $claim['inbox'] === null ? null
            : $this->rail->inspect(Customer::query()->findOrFail($claim['target']->customer_id),
                $claim['target']->reference, $claim['inbox']->tx_hash, allowLate: true);
        $this->apply($claim, $inspection);

        return true;
    }

    private function apply(array $claim, ?BscFinalReceiptInspection $inspection): void
    {
        DB::transaction(function () use ($claim, $inspection): void {
            $target = $claim['target'];
            // Compatible funding graph: Customer -> currency account -> immutable
            // target/receiver -> work -> reservation/resolution -> receipt.
            $customer = Customer::query()->whereKey($target->customer_id)->lockForUpdate()->firstOrFail();
            $this->ledger->lockForTransaction($customer);
            CustomerUsdtTopUp::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $receiver = CustomerUsdtDepositReceiver::query()->whereKey($target->receiver_id)->lockForUpdate()->firstOrFail();
            $work = DB::table('customer_usdt_top_up_settlement_work')->where('top_up_id', $target->id)->lockForUpdate()->firstOrFail();
            if ($work->lease_token !== $claim['token'] || (int) $work->version !== $claim['version']
                || $work->lease_until === null || CarbonImmutable::parse($work->lease_until)->isPast()) {
                return;
            }
            $reservation = CustomerUsdtFundingReservation::query()->whereKey($target->reservation_id)->lockForUpdate()->firstOrFail();
            $resolution = DB::table('customer_usdt_top_up_resolutions')->where('top_up_id', $target->id)->first();
            $checkpoint = DB::table('usdt_bsc_scan_checkpoints')->where('receiver_id', $target->receiver_id)->lockForUpdate()->first();
            if (($checkpoint?->version) !== $claim['checkpoint_version']) {
                $this->finish($work, ['next_check_at' => now()->addSeconds(5), 'last_error' => 'evidence_version_changed']);

                return;
            }
            $state = $resolution?->kind ?? $work->state;
            if ($reservation->resolved_at !== null && $reservation->state->value === 'released' && $resolution === null) {
                $state = match ($reservation->resolution_kind) {
                    'compensated' => 'resolved_by_compensation',
                    'externally_returned' => 'externally_refunded',
                    default => 'rejected',
                };
                DB::table('customer_usdt_top_up_resolutions')->insert([
                    'top_up_id' => $target->id, 'kind' => $state, 'created_at' => now(),
                ]);
            }
            if ($state === 'awaiting_payment' && $target->expires_at->isPast()) {
                $state = 'expired_unresolved';
            }
            if ($inspection === null) {
                $this->finish($work, ['state' => $state, 'next_check_at' => now()->addMinute()]);

                return;
            }
            $invoice = $claim['invoice'];
            if ($inspection?->evidence?->blockTimestamp !== null && CustomerUsdtTopUp::query()
                ->where('receiver_id', $target->receiver_id)->where('id', '<>', $target->id)
                ->where('intent_id', '>', $target->intent_id)
                ->where('created_at', '<=', $inspection->evidence->blockTimestamp->copy()->setTimezone(config('app.timezone')))->exists()) {
                $inspection = new BscFinalReceiptInspection('review', 'later_target_attribution_required');
            }
            if ((int) $receiver->customer_id !== (int) $customer->id || $receiver->address_normalized !== $invoice->receiver_address
                || $receiver->verified_at === null || $checkpoint?->review_reason !== null
                || $invoice->customer_id !== (int) $customer->id || $invoice->receiver_id !== (int) $target->receiver_id
                || $invoice->payable_micro !== (int) $target->payable_micro
                || $invoice->receiver_address !== $target->payment_snapshot['receiver_address']
                || $invoice->token_contract !== $target->payment_snapshot['token_contract']
                || $invoice->minimum_confirmations !== $target->payment_snapshot['minimum_confirmations']
                || $invoice->chain_id !== $target->payment_snapshot['chain_id']
                || $invoice->token_decimals !== $target->payment_snapshot['token_decimals']
                || $invoice->custody_namespace !== $target->payment_snapshot['custody_namespace']
                || ! $invoice->requires_finalized_block) {
                $inspection = new BscFinalReceiptInspection('review', 'snapshot_or_receiver_on_review');
            }
            $evidenceVersion = (int) $work->evidence_version + 1;
            $evidenceId = DB::table('customer_usdt_top_up_evidence')->insertGetId([
                'top_up_id' => $target->id, 'inbox_id' => $claim['inbox']->id, 'version' => $evidenceVersion,
                'status' => $inspection->status, 'reason' => $inspection->reason,
                'chain_evidence' => json_encode($inspection->evidence?->sanitizedPayload() ?? [], JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
            $inboxState = $inspection->status === 'pending' ? 'pending' : 'review';
            $error = $inspection->reason;
            if (($inspection->verified() || $inspection->reason === 'canonical_final_amount_mismatch') && $inspection->evidence !== null) {
                $evidence = $inspection->evidence;
                $amount = UsdtMicroAmount::fromRawTokenUnits($evidence->amountUnits, $invoice->token_decimals);
                if (($inspection->verified() && $amount->units() !== (int) $target->payable_micro) || $evidence->txHash !== $claim['inbox']->tx_hash
                    || $evidence->tokenContract !== $invoice->token_contract || $evidence->receiverAddress !== $invoice->receiver_address
                    || $evidence->blockTimestamp === null || $evidence->blockTimestamp->getTimestamp() < $invoice->starts_at_epoch
                    || $evidence->blockHash === null || ($evidence->confirmations ?? 0) < $invoice->minimum_confirmations) {
                    throw new RuntimeException('Verified USDT evidence does not match its immutable target.');
                }
                $receipt = new VerifiedMoneyReceipt(
                    MoneyReceiptIdentity::bscLog($invoice->chain_id, $evidence->txHash, $evidence->logIndex),
                    'customer:'.$customer->id, 'USDT', $evidence->amountUnits, $invoice->token_decimals,
                    MoneyReceiptTarget::customerUsdtTopUp($target->id), $evidence->sanitizedPayload(),
                    parentClaim: MoneyReceiptParentClaim::for($invoice->chain_id, $evidence->txHash),
                );
                $outcome = $this->receipts->claim($receipt);
                if ($outcome->allowsMoneyEffect()) {
                    $receiptId = DB::table('external_money_receipts')->where('canonical_key', $receipt->canonicalKey())->value('id');
                    if (! $inspection->verified()) {
                        // Claim actual funds once, but leave the invoice and
                        // balance untouched until an authorized resolution.
                        $error = $inspection->reason;
                    } elseif (! in_array($state, self::TERMINAL, true) && $reservation->resolved_at === null) {
                        $entry = $this->ledger->creditVerifiedTopUp($customer,
                            new VerifiedUsdtFunding($customer->id, UsdtMicroAmount::fromUnits($target->amount_micro),
                                $target->id, (string) $receiptId, (string) $evidenceId), 'usdt-top-up:'.$target->id);
                        $entry = CustomerUsdtBalanceEntry::query()->findOrFail($entry->entryId);
                        $late = $target->expires_at->isPast() || $evidence->blockTimestamp->getTimestamp() > $invoice->expires_at_epoch;
                        DB::table('customer_usdt_top_up_resolutions')->insert([
                            'top_up_id' => $target->id, 'kind' => 'settled', 'receipt_id' => $receiptId, 'entry_id' => $entry->id,
                            'evidence_id' => $evidenceId, 'late' => $late, 'created_at' => now(),
                        ]);
                        if ($target->fee_micro > 0) {
                            DB::table('customer_usdt_top_up_fee_events')->insert([
                                'top_up_id' => $target->id, 'receipt_id' => $receiptId, 'amount_micro' => $target->fee_micro, 'created_at' => now(),
                            ]);
                        }
                        $this->reservations->markSettled($customer, $target->reservation_id, $entry->id, $target->id);
                        AuditLog::create(['action' => 'usdt_top_up.settled', 'subject_type' => CustomerUsdtTopUp::class,
                            'subject_id' => $target->id, 'new_values' => ['currency' => 'USDT', 'amount_micro' => (string) $target->amount_micro,
                                'entry_id' => $entry->id, 'receipt_id' => $receiptId, 'late' => $late]]);
                        $this->outbound->recordReceipt($target, $entry, $late);
                        $state = 'settled';
                        $inboxState = 'settled';
                        $error = null;
                    } else {
                        // New money after resolution stays visible as a claimed,
                        // unresolved receipt for U2/U8. Never reopen or credit it.
                        $error = 'receipt_after_resolution';
                    }
                } else {
                    $error = 'receipt_'.$outcome->value;
                }
            }
            if ($inboxState === 'review' && ! in_array($state, self::TERMINAL, true)) {
                $state = 'manual_review';
            }
            $attempts = (int) $claim['inbox']->attempts + 1;
            DB::table('customer_usdt_top_up_inbox')->where('id', $claim['inbox']->id)->update([
                'state' => $inboxState, 'attempts' => $attempts,
                'next_check_at' => now()->addSeconds(min(300, 5 * (2 ** min(6, $attempts)))),
            ]);
            $this->finish($work, ['state' => $state, 'evidence_version' => $evidenceVersion,
                'next_check_at' => now()->addSeconds(5), 'last_error' => $error]);
        }, 5);
    }

    private function insertHint(string $id, string $hash): void
    {
        DB::table('customer_usdt_top_up_inbox')->insertOrIgnore([
            'top_up_id' => $id, 'tx_hash' => $hash, 'state' => 'pending', 'attempts' => 0,
            'next_check_at' => now(), 'created_at' => now(),
        ]);
    }

    private function finish(object $work, array $values): void
    {
        $this->updateWork($work, ['lease_token' => null, 'lease_until' => null, ...$values]);
    }

    private function updateWork(object $work, array $values): void
    {
        DB::table('customer_usdt_top_up_settlement_work')->where('top_up_id', $work->top_up_id)->update([
            'version' => (int) $work->version + 1, 'updated_at' => now(), ...$values,
        ]);
    }

    private function deferFailed(string $id): void
    {
        DB::transaction(function () use ($id): void {
            $work = DB::table('customer_usdt_top_up_settlement_work')->where('top_up_id', $id)->lockForUpdate()->first();
            // A live owner (possibly another worker) must never be unfenced.
            if ($work !== null && ($work->lease_until === null || CarbonImmutable::parse($work->lease_until)->isPast())) {
                $this->finish($work, ['next_check_at' => now()->addMinute(), 'last_error' => 'settlement_unresolved']);
            }
        }, 5);
    }

    private function outsideTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('USDT inspection cannot run inside a transaction.');
        }
    }
}
