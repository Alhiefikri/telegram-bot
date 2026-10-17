<?php

namespace App\Services\UsdtTopUps;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerUsdtBalanceEntry;
use App\Models\CustomerUsdtTopUp;
use App\Services\Binance\BinancePayTransaction;
use App\Services\Binance\BinanceTransactionHistoryReader;
use App\Services\MoneyReceipts\ExternalMoneyReceiptClaims;
use App\Services\MoneyReceipts\MoneyReceiptAlias;
use App\Services\MoneyReceipts\MoneyReceiptIdentity;
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

/** Durable Binance Pay order-id submission and authoritative history settlement. */
final class BinanceUsdtTopUpSettlement
{
    private const TERMINAL = ['settled', 'externally_refunded', 'resolved_by_compensation', 'rejected'];

    private const MAX_INSPECTIONS = 100;

    public function __construct(
        private readonly BinanceTransactionHistoryReader $history,
        private readonly CustomerUsdtBalanceLedger $ledger,
        private readonly ExternalMoneyReceiptClaims $receipts,
        private readonly CustomerUsdtFundingReservationService $reservations,
        private readonly UsdtTopUpOutbound $outbound,
    ) {}

    public function submit(Customer $customer, string $topUpId, string $orderId): void
    {
        $orderId = trim($orderId);
        if ($orderId === '' || strlen($orderId) > 191) {
            throw new InvalidArgumentException('Invalid Binance Order ID.');
        }
        DB::transaction(function () use ($customer, $topUpId, $orderId): void {
            $target = CustomerUsdtTopUp::query()->whereKey($topUpId)->where('customer_id', $customer->id)->lockForUpdate()->first();
            if ($target === null || $target->rail !== 'binance_pay') {
                throw new AuthorizationException('Binance USDT funding target unavailable.');
            }
            if (DB::table('customer_usdt_top_up_inbox')->where('top_up_id', $topUpId)->where('provider_reference', $orderId)->exists()) {
                return;
            }
            if (DB::table('customer_usdt_top_up_inbox')->where('top_up_id', $topUpId)->count() >= 100) {
                throw new InvalidArgumentException('Binance lookup hint budget exhausted.');
            }
            DB::table('customer_usdt_top_up_inbox')->insert([
                'top_up_id' => $topUpId, 'tx_hash' => null, 'provider_reference' => $orderId,
                'state' => 'pending', 'attempts' => 0, 'next_check_at' => now(), 'created_at' => now(),
            ]);
        }, 5);
    }

    public function recover(int $limit = 25): int
    {
        $this->outsideTransaction();
        $ids = DB::table('customer_usdt_top_up_settlement_work as work')
            ->join('customer_usdt_top_ups as targets', 'targets.id', '=', 'work.top_up_id')
            ->where('targets.rail', 'binance_pay')->where('work.next_check_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('work.lease_until')->orWhere('work.lease_until', '<=', now()))
            ->orderBy('work.next_check_at')->orderBy('work.top_up_id')->limit(max(1, min(100, $limit)))->pluck('work.top_up_id');
        $processed = 0;
        foreach ($ids as $id) {
            try {
                $processed += $this->check((string) $id) ? 1 : 0;
            } catch (Throwable) {
                $this->deferFailed((string) $id);
            }
        }

        return $processed;
    }

    public function check(string $topUpId): bool
    {
        $this->outsideTransaction();
        $claim = DB::transaction(function () use ($topUpId): ?array {
            $targetQuery = CustomerUsdtTopUp::query()->whereKey($topUpId);
            $target = (DB::getDriverName() === 'pgsql' ? $targetQuery->lock('FOR UPDATE SKIP LOCKED') : $targetQuery->lockForUpdate())->first();
            if ($target === null || $target->rail !== 'binance_pay') {
                return null;
            }
            $query = DB::table('customer_usdt_top_up_settlement_work')->where('top_up_id', $topUpId);
            $work = (DB::getDriverName() === 'pgsql' ? $query->lock('FOR UPDATE SKIP LOCKED') : $query->lockForUpdate())->first();
            if ($work === null || CarbonImmutable::parse($work->next_check_at)->isFuture()
                || ($work->lease_until !== null && CarbonImmutable::parse($work->lease_until)->isFuture())) {
                return null;
            }
            $inbox = DB::table('customer_usdt_top_up_inbox')->where('top_up_id', $topUpId)->where('state', 'pending')
                ->whereNotNull('provider_reference')->where('next_check_at', '<=', now())->orderBy('next_check_at')->orderBy('id')->first();
            if ($inbox === null) {
                $this->updateWork($work, ['next_check_at' => now()->addMinute()]);

                return null;
            }
            if ((int) $work->attempts >= self::MAX_INSPECTIONS) {
                $this->finish($work, [
                    'state' => $work->state === 'manual_review' ? 'manual_review' : ($target->expires_at->isPast() ? 'expired_unresolved' : 'manual_review'),
                    'next_check_at' => now()->addMinutes(5), 'last_error' => 'binance_inspection_budget_exhausted',
                ]);

                return null;
            }
            $token = bin2hex(random_bytes(16));
            $this->updateWork($work, ['lease_token' => $token, 'lease_until' => now()->addMinutes(2), 'attempts' => (int) $work->attempts + 1]);

            return ['target' => $target, 'work' => $work, 'inbox' => $inbox, 'token' => $token, 'version' => (int) $work->version + 1];
        }, 5);
        if ($claim === null) {
            return false;
        }

        $target = $claim['target'];
        $result = $this->history->read($target->created_at->getTimestampMs(), $target->expires_at->getTimestampMs());
        if (! $result->complete) {
            throw new RuntimeException('Binance history window incomplete.');
        }
        $transactions = array_values(array_filter(array_map(fn ($payload): ?BinancePayTransaction => BinancePayTransaction::fromPayload($payload), $result->transactions)));
        $transaction = null;
        foreach ($transactions as $candidate) {
            if (hash_equals((string) $candidate->orderId(), (string) $claim['inbox']->provider_reference)) {
                $transaction = $candidate;
                break;
            }
        }
        $this->apply($claim, $transaction);

        return true;
    }

    private function apply(array $claim, ?BinancePayTransaction $transaction): void
    {
        DB::transaction(function () use ($claim, $transaction): void {
            $target = $claim['target'];
            $customer = Customer::query()->whereKey($target->customer_id)->lockForUpdate()->firstOrFail();
            $this->ledger->lockForTransaction($customer);
            CustomerUsdtTopUp::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $work = DB::table('customer_usdt_top_up_settlement_work')->where('top_up_id', $target->id)->lockForUpdate()->firstOrFail();
            if ($work->lease_token !== $claim['token'] || (int) $work->version !== $claim['version']
                || $work->lease_until === null || CarbonImmutable::parse($work->lease_until)->isPast()) {
                return;
            }
            $resolution = DB::table('customer_usdt_top_up_resolutions')->where('top_up_id', $target->id)->first();
            $state = $resolution !== null ? 'manual_review' : $work->state;
            if ($transaction === null) {
                $this->deferHint($claim['inbox']);
                $this->finish($work, ['state' => $target->expires_at->isPast() ? 'expired_unresolved' : $state, 'next_check_at' => now()->addMinute(), 'last_error' => 'binance_order_not_found']);

                return;
            }
            $snapshot = is_array($target->payment_snapshot) ? $target->payment_snapshot : [];
            $receiverPayId = $snapshot['receiver_pay_id'] ?? null;
            $receiverUid = $snapshot['receiver_uid'] ?? null;
            $startMs = $target->created_at->getTimestampMs();
            $endMs = $target->expires_at->getTimestampMs();
            $valid = $transaction->successful()
                && $transaction->currency() === 'USDT'
                && $transaction->amountMicro() === (int) $target->payable_micro
                && $transaction->isIncomingToConfiguredReceiver($receiverPayId, $receiverUid)
                && $transaction->transactionTimeMs() >= $startMs
                && $transaction->transactionTimeMs() <= $endMs
                && $transaction->transactionTimeMs() <= now()->getTimestampMs();
            $evidenceVersion = (int) $work->evidence_version + 1;
            $evidenceId = DB::table('customer_usdt_top_up_evidence')->insertGetId([
                'top_up_id' => $target->id, 'inbox_id' => $claim['inbox']->id, 'version' => $evidenceVersion,
                'status' => $valid ? 'verified' : 'review', 'reason' => $valid ? 'verified' : 'binance_snapshot_or_amount_mismatch',
                'chain_evidence' => json_encode($transaction->sanitizedPayload(), JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
            if (! $valid) {
                DB::table('customer_usdt_top_up_inbox')->where('id', $claim['inbox']->id)->update([
                    'state' => 'review', 'attempts' => (int) $claim['inbox']->attempts + 1, 'next_check_at' => now()->addMinute(),
                ]);
                $this->finish($work, ['state' => 'manual_review', 'evidence_version' => $evidenceVersion, 'next_check_at' => now()->addMinute(), 'last_error' => 'binance_snapshot_or_amount_mismatch']);

                return;
            }
            $identity = MoneyReceiptIdentity::binancePay($receiverPayId !== null && $receiverPayId !== '' ? 'pay_id' : 'uid', (string) ($receiverPayId !== null && $receiverPayId !== '' ? $receiverPayId : $receiverUid), $transaction->transactionId());
            $aliases = $transaction->orderId() !== '' && $transaction->orderId() !== $transaction->transactionId()
                ? [new MoneyReceiptAlias('binance_order_id', MoneyReceiptIdentity::binancePay($receiverPayId !== null && $receiverPayId !== '' ? 'pay_id' : 'uid', (string) ($receiverPayId !== null && $receiverPayId !== '' ? $receiverPayId : $receiverUid), $transaction->orderId()))]
                : [];
            $receipt = new VerifiedMoneyReceipt($identity, 'customer:'.$customer->id, 'USDT', (string) $transaction->amountMicro(), 6,
                MoneyReceiptTarget::customerUsdtTopUp($target->id), $transaction->sanitizedPayload(), $aliases);
            $outcome = $this->receipts->claim($receipt);
            if (! $outcome->allowsMoneyEffect()) {
                $this->deferHint($claim['inbox']);
                $this->finish($work, ['state' => 'manual_review', 'evidence_version' => $evidenceVersion, 'next_check_at' => now()->addMinute(), 'last_error' => 'receipt_'.$outcome->value]);

                return;
            }
            if ($resolution !== null || in_array($state, self::TERMINAL, true)) {
                DB::table('customer_usdt_top_up_inbox')->where('id', $claim['inbox']->id)->update([
                    'state' => 'review', 'attempts' => (int) $claim['inbox']->attempts + 1, 'next_check_at' => now()->addMinute(),
                ]);
                $this->finish($work, ['state' => 'manual_review', 'evidence_version' => $evidenceVersion,
                    'next_check_at' => now()->addMinute(), 'last_error' => 'receipt_after_resolution']);

                return;
            }
            $receiptId = DB::table('external_money_receipts')->where('canonical_key', $receipt->canonicalKey())->value('id');
            $entry = $this->ledger->creditVerifiedTopUp($customer,
                new VerifiedUsdtFunding($customer->id, UsdtMicroAmount::fromUnits($target->amount_micro), $target->id, (string) $receiptId, (string) $evidenceId),
                'usdt-top-up:'.$target->id);
            $entry = CustomerUsdtBalanceEntry::query()->findOrFail($entry->entryId);
            DB::table('customer_usdt_top_up_resolutions')->insert([
                'top_up_id' => $target->id, 'kind' => 'settled', 'receipt_id' => $receiptId, 'entry_id' => $entry->id,
                'evidence_id' => $evidenceId, 'late' => $target->expires_at->isPast(), 'created_at' => now(),
            ]);
            if ($target->fee_micro > 0) {
                DB::table('customer_usdt_top_up_fee_events')->insert([
                    'top_up_id' => $target->id, 'receipt_id' => $receiptId, 'amount_micro' => $target->fee_micro, 'created_at' => now(),
                ]);
            }
            $this->reservations->markSettled($customer, $target->reservation_id, $entry->id, $target->id);
            AuditLog::create(['action' => 'usdt_top_up.settled', 'subject_type' => CustomerUsdtTopUp::class, 'subject_id' => $target->id,
                'new_values' => ['currency' => 'USDT', 'amount_micro' => (string) $target->amount_micro, 'entry_id' => $entry->id, 'receipt_id' => $receiptId, 'rail' => 'binance_pay']]);
            $this->outbound->recordReceipt($target, $entry, $target->expires_at->isPast());
            DB::table('customer_usdt_top_up_inbox')->where('id', $claim['inbox']->id)->update(['state' => 'settled', 'attempts' => (int) $claim['inbox']->attempts + 1, 'next_check_at' => now()->addMinute()]);
            $this->finish($work, ['state' => 'settled', 'evidence_version' => $evidenceVersion, 'next_check_at' => now()->addMinute(), 'last_error' => null]);
        }, 5);
    }

    private function deferHint(object $inbox): void
    {
        $attempts = (int) $inbox->attempts + 1;
        DB::table('customer_usdt_top_up_inbox')->where('id', $inbox->id)->update([
            'attempts' => $attempts,
            'next_check_at' => now()->addSeconds(min(300, 5 * (2 ** min(6, $attempts)))),
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
            if ($work !== null && ($work->lease_until === null || CarbonImmutable::parse($work->lease_until)->isPast())) {
                $this->finish($work, ['next_check_at' => now()->addMinute(), 'last_error' => 'settlement_unresolved']);
            }
        }, 5);
    }

    private function outsideTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Binance inspection cannot run inside a database transaction.');
        }
    }
}
