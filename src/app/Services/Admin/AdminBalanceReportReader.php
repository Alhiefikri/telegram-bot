<?php

namespace App\Services\Admin;

use App\Enums\StoreBalanceEntryKind;
use App\Enums\StoreBalanceTopUpStatus;
use App\Enums\UsdtBalanceEntryKind;
use App\Enums\UserRole;
use App\Models\BalanceAdjustmentRequest;
use App\Models\CustomerUsdtTopUp;
use App\Models\StoreBalanceTopUp;
use App\Models\User;
use App\Services\UsdtTopUps\UsdtTopUpStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

final class AdminBalanceReportReader
{
    private const SECTIONS = ['accounts', 'entries', 'topups', 'reviews', 'treasury', 'audit'];

    private const USDT_TOP_UP_STATES = [
        UsdtTopUpStatus::STATE_PREPARING,
        UsdtTopUpStatus::STATE_AWAITING_PAYMENT,
        UsdtTopUpStatus::STATE_EXPIRED_UNRESOLVED,
        UsdtTopUpStatus::STATE_REVIEW,
        UsdtTopUpStatus::STATE_SETTLED,
        UsdtTopUpStatus::STATE_COMPENSATED,
        UsdtTopUpStatus::STATE_EXTERNALLY_REFUNDED,
        UsdtTopUpStatus::STATE_REJECTED,
    ];

    private const IDR_AUDIT_ACTIONS = [
        'balance.adjustment.applied', 'balance.adjustment.approved', 'balance.adjustment.rejected',
        'balance.adjustment.requested', 'balance.adjustment.reversal_review', 'balance.adjustment.lockout',
        'store_balance.top_up.compensated', 'store_balance.top_up.compensation_provider_evidence',
        'store_balance.top_up.late_settled', 'store_balance.top_up.settled',
        'store_balance.top_up.manually_confirmed', 'store_balance.top_up.manually_rejected',
    ];

    private const USDT_AUDIT_ACTIONS = [
        'usdt_top_up.review_resolved', 'usdt_top_up.settled', 'usdt_reorg.detected', 'usdt_reorg.recovery_authorized',
    ];

    public function __construct(private readonly AdminBalanceReportCursor $cursors) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function page(User $actor, array $input): array
    {
        if (! $actor->role instanceof UserRole || ! $actor->role->canReconcilePayments()) {
            abort(403);
        }

        $section = (string) ($input['section'] ?? 'accounts');
        $currency = (string) ($input['currency'] ?? 'IDR');
        if (! in_array($section, self::SECTIONS, true) || ! in_array($currency, ['IDR', 'USDT'], true)) {
            throw ValidationException::withMessages(['section' => 'Filter laporan tidak valid.']);
        }
        if ($section === 'treasury') {
            $currency = 'USDT';
        }

        $telegramId = $section === 'treasury' || ! isset($input['telegram_id']) || $input['telegram_id'] === ''
            ? null
            : (string) $input['telegram_id'];
        $state = ! isset($input['state']) || $input['state'] === '' ? null : (string) $input['state'];
        $filters = [
            'section' => $section,
            'currency' => $currency,
            'telegram_id' => $telegramId,
            'state' => $state,
            'limit' => (int) ($input['limit'] ?? 25),
        ];
        if ($filters['limit'] < 1 || $filters['limit'] > 100) {
            throw ValidationException::withMessages(['limit' => 'Batas baris laporan harus antara 1 dan 100.']);
        }

        $stateOptions = $this->stateOptions($section, $currency);
        if ($state !== null && ! in_array($state, $stateOptions, true)) {
            throw ValidationException::withMessages(['state' => 'Filter status laporan tidak valid.']);
        }

        $cursorToken = isset($input['cursor']) && is_string($input['cursor']) ? $input['cursor'] : null;
        $cursor = $cursorToken === null ? null : $this->cursors->decode($actor, $filters, $cursorToken);
        if ($cursorToken !== null && $cursor === null) {
            abort(404);
        }

        [$query, $timeColumn, $idColumn] = $this->query($section, $currency);
        $this->applyTelegramFilter($query, $section, $currency, $telegramId);
        $this->applyStateFilter($query, $section, $currency, $state);
        if ($cursor !== null) {
            $query->where(function (Builder $after) use ($cursor, $timeColumn, $idColumn): void {
                $after->where($timeColumn, '<', $cursor['occurred_at'])
                    ->orWhere(function (Builder $sameTime) use ($cursor, $timeColumn, $idColumn): void {
                        $sameTime->where($timeColumn, '=', $cursor['occurred_at'])
                            ->where($idColumn, '<', $cursor['id']);
                    });
            });
        }

        $records = $query
            ->orderByDesc($timeColumn)
            ->orderByDesc($idColumn)
            ->limit($filters['limit'] + 1)
            ->get();
        $hasMore = $records->count() > $filters['limit'];
        $visible = $records->take($filters['limit']);
        $last = $visible->last();
        $nextCursor = $hasMore && $last !== null
            ? $this->cursors->encode($actor, $filters, $this->cursorTimestamp($last->report_time), (string) $last->row_id)
            : null;

        return [
            'filters' => $filters,
            'stateOptions' => $stateOptions,
            'rows' => $visible->map(fn (object $row): array => $this->present($row, $section, $currency, $actor))->all(),
            'nextCursor' => $nextCursor,
            'summary' => $this->summary(),
        ];
    }

    /** @return list<string> */
    private function stateOptions(string $section, string $currency): array
    {
        return match ($section) {
            'entries' => array_map(static fn (StoreBalanceEntryKind|UsdtBalanceEntryKind $kind): string => $kind->value,
                $currency === 'IDR' ? StoreBalanceEntryKind::cases() : UsdtBalanceEntryKind::cases()),
            'topups' => $currency === 'IDR'
                ? array_map(static fn (StoreBalanceTopUpStatus $status): string => $status->value, StoreBalanceTopUpStatus::cases())
                : self::USDT_TOP_UP_STATES,
            'reviews' => $currency === 'IDR'
                ? ['pending', 'approved', 'rejected', 'cancelled', 'expired']
                : ['settled', 'resolved_by_compensation', 'externally_refunded'],
            'treasury' => ['in', 'out'],
            'audit' => $currency === 'IDR' ? self::IDR_AUDIT_ACTIONS : self::USDT_AUDIT_ACTIONS,
            default => [],
        };
    }

    /** @return array{Builder, string, string} */
    private function query(string $section, string $currency): array
    {
        return match ($section) {
            'accounts' => $this->accountsQuery($currency),
            'entries' => $this->entriesQuery($currency),
            'topups' => $this->topUpsQuery($currency),
            'reviews' => $this->reviewsQuery($currency),
            'treasury' => $this->treasuryQuery(),
            'audit' => $this->auditQuery($currency),
        };
    }

    /** @return array{Builder, string, string} */
    private function accountsQuery(string $currency): array
    {
        if ($currency === 'IDR') {
            $query = DB::table('customer_balance_accounts as accounts')
                ->join('customers', 'customers.id', '=', 'accounts.customer_id')
                ->select([
                    'accounts.id as row_id', 'accounts.customer_id', 'accounts.balance as amount',
                    'accounts.created_at as report_time', 'customers.first_name', 'customers.telegram_id',
                ])
                ->selectSub(function ($latest): void {
                    $latest->from('store_balance_entries')
                        ->select('balance_after')
                        ->whereColumn('store_balance_entries.customer_balance_account_id', 'accounts.id')
                        ->orderByDesc('id')->limit(1);
                }, 'latest_balance_after');

            return [$query, 'accounts.created_at', 'accounts.id'];
        }

        $query = DB::table('customer_usdt_balance_accounts as accounts')
            ->join('customers', 'customers.id', '=', 'accounts.customer_id')
            ->select([
                'accounts.id as row_id', 'accounts.customer_id', 'accounts.balance_micro as amount',
                'accounts.version', 'accounts.frozen_reason', 'accounts.created_at as report_time',
                'customers.first_name', 'customers.telegram_id',
            ])
            ->selectSub(function ($latest): void {
                $latest->from('customer_usdt_balance_entries')
                    ->select('balance_after_micro')
                    ->whereColumn('customer_usdt_balance_entries.customer_usdt_balance_account_id', 'accounts.id')
                    ->orderByDesc('id')->limit(1);
            }, 'latest_balance_after')
            ->selectSub(function ($latest): void {
                $latest->from('customer_usdt_balance_entries')
                    ->select('account_sequence')
                    ->whereColumn('customer_usdt_balance_entries.customer_usdt_balance_account_id', 'accounts.id')
                    ->orderByDesc('id')->limit(1);
            }, 'latest_account_sequence');

        return [$query, 'accounts.created_at', 'accounts.id'];
    }

    /** @return array{Builder, string, string} */
    private function entriesQuery(string $currency): array
    {
        if ($currency === 'IDR') {
            return [DB::table('store_balance_entries as entries')
                ->join('customer_balance_accounts as accounts', 'accounts.id', '=', 'entries.customer_balance_account_id')
                ->join('customers', 'customers.id', '=', 'accounts.customer_id')
                ->select([
                    'entries.id as row_id', 'entries.kind', 'entries.amount', 'entries.source_type', 'entries.source_id',
                    'entries.created_at as report_time', 'customers.id as customer_id', 'customers.first_name', 'customers.telegram_id',
                ]), 'entries.created_at', 'entries.id'];
        }

        return [DB::table('customer_usdt_balance_entries as entries')
            ->join('customer_usdt_balance_accounts as accounts', 'accounts.id', '=', 'entries.customer_usdt_balance_account_id')
            ->join('customers', 'customers.id', '=', 'accounts.customer_id')
            ->select([
                'entries.id as row_id', 'entries.kind', 'entries.amount_micro as amount', 'entries.source_type', 'entries.source_id',
                'entries.created_at as report_time', 'customers.id as customer_id', 'customers.first_name', 'customers.telegram_id',
            ]), 'entries.created_at', 'entries.id'];
    }

    /** @return array{Builder, string, string} */
    private function topUpsQuery(string $currency): array
    {
        if ($currency === 'IDR') {
            $query = DB::table('store_balance_top_ups as topups')
                ->join('customers', 'customers.id', '=', 'topups.customer_id')
                ->select([
                    'topups.id as row_id', 'topups.customer_id', 'topups.amount', 'topups.status', 'topups.provider',
                    'topups.provider_payment_method', 'topups.merchant_ref', 'topups.provider_reference',
                    'topups.created_at as report_time', 'customers.first_name', 'customers.telegram_id',
                ])
                ->selectRaw('CASE WHEN topups.payment_proof_file_id IS NOT NULL AND topups.payment_proof_submitted_at IS NOT NULL THEN 1 ELSE 0 END AS proof_available');

            return [$query, 'topups.created_at', 'topups.id'];
        }

        [$statusSql, $statusBindings] = $this->usdtTopUpStatusExpression();
        $query = DB::table('customer_usdt_top_ups as topups')
            ->join('customers', 'customers.id', '=', 'topups.customer_id')
            ->leftJoin('customer_usdt_top_up_work as work', 'work.top_up_id', '=', 'topups.id')
            ->leftJoin('customer_usdt_top_up_settlement_work as settlement', 'settlement.top_up_id', '=', 'topups.id')
            ->leftJoin('customer_usdt_top_up_resolutions as resolutions', 'resolutions.top_up_id', '=', 'topups.id')
            ->leftJoin('customer_usdt_balance_entries as settled_entries', 'settled_entries.id', '=', 'resolutions.entry_id')
            ->select([
                'topups.id as row_id', 'topups.customer_id', 'topups.amount_micro as amount', 'topups.fee_micro',
                'topups.payable_micro', 'topups.reference', 'topups.rail', 'topups.created_at as report_time',
                'customers.first_name', 'customers.telegram_id',
            ])
            ->selectRaw($statusSql.' AS report_state', $statusBindings);

        return [$query, 'topups.created_at', 'topups.id'];
    }

    /** @return array{string, list<mixed>} */
    private function usdtTopUpStatusExpression(): array
    {
        $sql = <<<'SQL'
CASE
    WHEN resolutions.top_up_id IS NOT NULL THEN
        CASE
            WHEN resolutions.kind = 'settled' AND settled_entries.id IS NOT NULL THEN ?
            WHEN resolutions.kind IN ('resolved_by_compensation', 'externally_refunded', 'rejected') THEN resolutions.kind
            ELSE ? END
    WHEN settlement.state IN ('settled', 'externally_refunded', 'resolved_by_compensation', 'rejected') THEN
        CASE WHEN settlement.state = 'settled' THEN ? ELSE settlement.state END
    WHEN work.state IS NULL OR work.state IN ('pending', 'creating', 'creation_unknown') THEN ?
    WHEN settlement.state = 'manual_review' OR work.state = 'manual_review' THEN ?
    WHEN work.state = 'expired_unresolved' OR settlement.state = 'expired_unresolved' OR topups.expires_at <= ? THEN ?
    ELSE ?
END
SQL;

        return [$sql, [
            UsdtTopUpStatus::STATE_SETTLED,
            UsdtTopUpStatus::STATE_REVIEW,
            UsdtTopUpStatus::STATE_REVIEW,
            UsdtTopUpStatus::STATE_PREPARING,
            UsdtTopUpStatus::STATE_REVIEW,
            now()->toDateTimeString(),
            UsdtTopUpStatus::STATE_EXPIRED_UNRESOLVED,
            UsdtTopUpStatus::STATE_AWAITING_PAYMENT,
        ]];
    }

    private function applyUsdtTopUpStateFilter(Builder $query, string $state): Builder
    {
        [$statusSql, $statusBindings] = $this->usdtTopUpStatusExpression();
        $statusBindings[] = $state;

        return $query->whereRaw($statusSql.' = ?', $statusBindings);
    }

    /** @return array{Builder, string, string} */
    private function reviewsQuery(string $currency): array
    {
        if ($currency === 'IDR') {
            $query = DB::table('balance_adjustment_requests as reviews')
                ->join('customers', 'customers.id', '=', 'reviews.customer_id')
                ->leftJoin('users', 'users.id', '=', 'reviews.owner_user_id')
                ->select([
                    'reviews.id as row_id', 'reviews.customer_id', 'reviews.amount', 'reviews.category', 'reviews.state',
                    'reviews.reason', 'reviews.created_at as report_time', 'customers.first_name', 'customers.telegram_id',
                    'users.name as actor',
                ]);

            return [$query, 'reviews.created_at', 'reviews.id'];
        }

        $query = DB::table('usdt_top_up_review_requests as reviews')
            ->join('customer_usdt_top_ups as topups', 'topups.id', '=', 'reviews.top_up_id')
            ->join('customers', 'customers.id', '=', 'topups.customer_id')
            ->leftJoin('users', 'users.id', '=', 'reviews.owner_user_id')
            ->select([
                'reviews.id as row_id', 'reviews.top_up_id', 'reviews.decision as state', 'reviews.amount_micro as amount',
                'reviews.transaction_hash', 'reviews.reason', 'reviews.created_at as report_time',
                'customers.id as customer_id', 'customers.first_name', 'customers.telegram_id', 'users.name as actor',
            ]);

        return [$query, 'reviews.created_at', 'reviews.id'];
    }

    /** @return array{Builder, string, string} */
    private function treasuryQuery(): array
    {
        $query = DB::table('usdt_treasury_movements as movements')
            ->where('movements.currency', 'USDT')
            ->select([
                'movements.id as row_id', 'movements.direction as state', 'movements.amount_micro as amount',
                'movements.reference', 'movements.reason', 'movements.actor', 'movements.occurred_at as report_time',
            ]);

        return [$query, 'movements.occurred_at', 'movements.id'];
    }

    /** @return array{Builder, string, string} */
    private function auditQuery(string $currency): array
    {
        $actions = $currency === 'IDR' ? self::IDR_AUDIT_ACTIONS : self::USDT_AUDIT_ACTIONS;
        $query = DB::table('audit_logs as audit')
            ->leftJoin('users', 'users.id', '=', 'audit.user_id')
            ->whereIn('audit.action', $actions)
            ->select([
                'audit.id as row_id', 'audit.action as state', 'audit.subject_type', 'audit.subject_id',
                'audit.created_at as report_time', 'users.name as actor',
            ]);

        return [$query, 'audit.created_at', 'audit.id'];
    }

    private function applyTelegramFilter(Builder $query, string $section, string $currency, ?string $telegramId): void
    {
        if ($telegramId === null) {
            return;
        }
        if ($section !== 'audit') {
            $query->where('customers.telegram_id', '=', $telegramId);

            return;
        }

        $query->where(function (Builder $subjects) use ($currency, $telegramId): void {
            if ($currency === 'IDR') {
                $subjects->where(function (Builder $adjustments) use ($telegramId): void {
                    $adjustments->where('audit.subject_type', BalanceAdjustmentRequest::class)
                        ->whereExists(function (Builder $exists) use ($telegramId): void {
                            $exists->selectRaw('1')->from('balance_adjustment_requests as requests')
                                ->join('customers as report_customers', 'report_customers.id', '=', 'requests.customer_id')
                                ->whereColumn('requests.id', 'audit.subject_id')
                                ->where('report_customers.telegram_id', '=', $telegramId);
                        });
                })->orWhere(function (Builder $topUps) use ($telegramId): void {
                    $topUps->where('audit.subject_type', StoreBalanceTopUp::class)
                        ->whereExists(function (Builder $exists) use ($telegramId): void {
                            $exists->selectRaw('1')->from('store_balance_top_ups as topups')
                                ->join('customers as report_customers', 'report_customers.id', '=', 'topups.customer_id')
                                ->whereColumn('topups.id', 'audit.subject_id')
                                ->where('report_customers.telegram_id', '=', $telegramId);
                        });
                });

                return;
            }

            $subjects->where('audit.subject_type', CustomerUsdtTopUp::class)
                ->whereExists(function (Builder $exists) use ($telegramId): void {
                    $exists->selectRaw('1')->from('customer_usdt_top_ups as topups')
                        ->join('customers as report_customers', 'report_customers.id', '=', 'topups.customer_id')
                        ->whereColumn('topups.id', 'audit.subject_id')
                        ->where('report_customers.telegram_id', '=', $telegramId);
                });
        });
    }

    private function applyStateFilter(Builder $query, string $section, string $currency, ?string $state): void
    {
        if ($state === null) {
            return;
        }

        match ($section) {
            'entries' => $query->where('entries.kind', '=', $state),
            'topups' => $currency === 'IDR'
                ? $query->where('topups.status', '=', $state)
                : $this->applyUsdtTopUpStateFilter($query, $state),
            'treasury' => $query->where('movements.direction', '=', $state),
            'audit' => $query->where('audit.action', '=', $state),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function present(object $row, string $section, string $currency, User $actor): array
    {
        $customerName = trim((string) ($row->first_name ?? ''));
        $customer = isset($row->customer_id)
            ? ($customerName !== '' ? $customerName : 'Customer ••••'.substr((string) $row->customer_id, -4))
            : 'Treasury';
        $telegram = isset($row->telegram_id) ? $this->maskReference((string) $row->telegram_id) : null;
        $result = [
            'title' => '',
            'customer' => $customer,
            'telegram_id' => $telegram,
            'reference' => '—',
            'status' => '—',
            'amount' => '—',
            'detail' => '',
            'actor' => isset($row->actor) && $row->actor !== null ? (string) $row->actor : null,
            'occurred_at' => $this->displayTime($row->report_time),
            'proof_url' => null,
        ];

        if ($section === 'accounts') {
            $result['title'] = 'Saldo rekening '.$currency;
            $result['reference'] = 'Akun '.$this->maskReference((string) $row->row_id);
            if ($currency === 'IDR') {
                $valid = $row->latest_balance_after === null
                    ? (string) $row->amount === '0'
                    : (string) $row->latest_balance_after === (string) $row->amount;
                $result['amount'] = $valid ? $this->formatIdr((string) $row->amount) : '—';
                $result['status'] = $valid ? 'available' : 'unavailable';
                $result['detail'] = 'Proyeksi Store Balance; saldo ini bukan hasil SUM ledger.';
            } else {
                $valid = $row->latest_balance_after === null
                    ? (int) $row->version === 0 && (string) $row->amount === '0'
                    : (string) $row->latest_balance_after === (string) $row->amount
                        && (string) $row->latest_account_sequence === (string) $row->version;
                $result['amount'] = $valid ? $this->formatUsdt((string) $row->amount).' USDT' : '—';
                $result['status'] = ! $valid ? 'unavailable' : ($row->frozen_reason !== null ? 'frozen' : 'available');
                $result['detail'] = 'Proyeksi Customer USDT; versi ledger '.$row->version.'.';
            }

            return $result;
        }

        if ($section === 'entries') {
            $kind = $currency === 'IDR'
                ? StoreBalanceEntryKind::tryFrom((string) $row->kind)
                : UsdtBalanceEntryKind::tryFrom((string) $row->kind);
            $label = match ((string) $row->source_type) {
                'order' => 'Order', 'order_refund' => 'Refund', 'balance_top_up' => 'Top-up',
                'customer_usdt_top_up' => 'USDT top-up', 'balance_adjustment' => 'Adjustment',
                default => 'Ledger',
            };
            $result['title'] = 'Ledger '.$currency.' · '.(string) $row->kind;
            $result['reference'] = $label.' '.$this->maskReference((string) $row->source_id);
            $result['status'] = $kind === null ? 'unknown' : ($kind->direction() > 0 ? 'credit' : 'debit');
            $amount = $currency === 'IDR' ? $this->formatIdr((string) $row->amount) : $this->formatUsdt((string) $row->amount).' USDT';
            $result['amount'] = $kind === null ? $amount : (($kind->direction() < 0 ? '−' : '+').$amount);

            return $result;
        }

        if ($section === 'topups') {
            $result['title'] = 'Top-up '.$currency;
            if ($currency === 'IDR') {
                $result['status'] = (string) $row->status;
                $result['amount'] = $this->formatIdr((string) $row->amount);
                $result['reference'] = 'Merchant '.$this->maskReference((string) $row->merchant_ref);
                $result['detail'] = 'Provider '.(string) $row->provider
                    .($row->provider_payment_method ? ' · '.(string) $row->provider_payment_method : '');
                if ((bool) $row->proof_available) {
                    $result['proof_url'] = URL::temporarySignedRoute(
                        'admin.store-balance.reports.proof',
                        now()->addMinutes(5),
                        ['topUp' => $row->row_id, 'admin' => $actor->getKey()],
                    );
                }
            } else {
                $result['status'] = (string) $row->report_state;
                $result['amount'] = $this->formatUsdt((string) $row->amount).' USDT';
                $result['reference'] = 'Intent '.$this->maskReference((string) $row->reference);
                $result['detail'] = 'Payable '.$this->formatUsdt((string) $row->payable_micro)
                    .' USDT · fee '.$this->formatUsdt((string) $row->fee_micro).' USDT · rail '.(string) $row->rail;
            }

            return $result;
        }

        if ($section === 'reviews') {
            $result['title'] = 'Review '.$currency;
            $result['status'] = (string) $row->state;
            $result['reference'] = 'Review '.$this->maskReference((string) $row->row_id);
            $result['amount'] = $currency === 'IDR' ? $this->formatIdr((string) $row->amount) : $this->formatUsdt((string) $row->amount).' USDT';
            $result['detail'] = ($currency === 'IDR' ? (string) $row->category : 'Tx ').' · '.$this->safeReason((string) $row->reason);
            if ($currency === 'USDT') {
                $result['detail'] = 'Tx '.$this->maskReference((string) $row->transaction_hash).' · '.$this->safeReason((string) $row->reason);
            }

            return $result;
        }

        if ($section === 'treasury') {
            $result['title'] = 'Pergerakan treasury USDT';
            $result['status'] = (string) $row->state;
            $result['reference'] = $this->maskReference((string) $row->reference);
            $result['amount'] = $this->formatUsdt((string) $row->amount).' USDT';
            $result['detail'] = $this->safeReason((string) $row->reason);

            return $result;
        }

        $result['title'] = 'Audit '.$currency;
        $result['status'] = (string) $row->state;
        $result['reference'] = $this->maskReference((string) $row->subject_id);

        return $result;
    }

    /** @return array<string, mixed> */
    private function summary(): array
    {
        $totals = DB::table('customer_balance_accounts')
            ->selectRaw('COALESCE(SUM(balance), 0) AS idr_total')
            ->selectSub(function ($query): void {
                $query->from('customer_usdt_balance_accounts')->selectRaw('COALESCE(SUM(balance_micro), 0)');
            }, 'usdt_total')
            ->first();
        $run = DB::table('usdt_balance_reconciliation_runs')
            ->where('scope', 'full')
            ->orderByDesc('checked_at')->orderByDesc('id')
            ->first([
                'checked_at', 'verdict', 'liability_micro', 'unresolved_deposit_micro', 'custody_state',
                'custody_assets_micro', 'movement_in_micro', 'movement_out_micro', 'movement_count',
                'aggregate_pending_events', 'account_finding_count', 'receipt_finding_count',
            ]);

        $custodyState = $run?->custody_state;
        $custodyAvailable = $custodyState === 'available' && $run?->custody_assets_micro !== null;

        return [
            'idr_total' => $this->formatIdr((string) ($totals->idr_total ?? '0')),
            'usdt_total' => $this->formatUsdt((string) ($totals->usdt_total ?? '0')).' USDT',
            'treasury' => $run === null ? null : [
                'checked_at' => $this->displayTime($run->checked_at),
                'verdict' => (string) $run->verdict,
                'liability' => $this->formatUsdt((string) $run->liability_micro).' USDT',
                'unresolved' => $this->formatUsdt((string) $run->unresolved_deposit_micro).' USDT',
                'custody_state' => $custodyState === null ? 'missing' : (string) $custodyState,
                'custody_amount' => $custodyAvailable ? $this->formatUsdt((string) $run->custody_assets_micro).' USDT' : null,
                'movement_in' => $this->formatUsdt((string) $run->movement_in_micro).' USDT',
                'movement_out' => $this->formatUsdt((string) $run->movement_out_micro).' USDT',
                'movement_count' => (int) $run->movement_count,
                'aggregate_pending_events' => (int) $run->aggregate_pending_events,
                'account_finding_count' => (int) $run->account_finding_count,
                'receipt_finding_count' => (int) $run->receipt_finding_count,
            ],
        ];
    }

    private function displayTime(mixed $value): string
    {
        return CarbonImmutable::parse((string) $value)->setTimezone((string) config('app.timezone'))->format('d M Y H:i');
    }

    private function cursorTimestamp(mixed $value): string
    {
        return CarbonImmutable::parse((string) $value)->format('Y-m-d H:i:s.u');
    }

    private function formatIdr(string $amount): string
    {
        if ($amount === '' || ! ctype_digit($amount)) {
            return 'Rp —';
        }
        $digits = ltrim($amount, '0');
        $digits = $digits === '' ? '0' : $digits;
        $grouped = preg_replace('/\B(?=(?:\d{3})+(?!\d))/', '.', $digits);

        return 'Rp '.($grouped ?? $digits);
    }

    private function formatUsdt(string $micro): string
    {
        if ($micro === '' || ! ctype_digit($micro)) {
            return '—';
        }
        $digits = ltrim($micro, '0');
        $digits = $digits === '' ? '0' : $digits;
        $padded = str_pad($digits, 7, '0', STR_PAD_LEFT);
        $whole = substr($padded, 0, -6);
        $fraction = rtrim(substr($padded, -6), '0');

        return $fraction === '' ? $whole : $whole.'.'.$fraction;
    }

    private function maskReference(string $reference): string
    {
        $safe = preg_replace('/[^A-Za-z0-9]/', '', $reference) ?? '';

        return '••••'.substr($safe, -4);
    }

    private function safeReason(string $reason): string
    {
        $safe = strip_tags($reason);
        $safe = preg_replace_callback(
            '/(?:0x[a-f0-9]{40,64}|\b[a-f0-9]{64}\b|\b[0-9]{8,20}\b)/i',
            fn (array $match): string => $this->maskReference($match[0]),
            $safe,
        ) ?? '';

        return mb_substr(trim($safe), 0, 240);
    }
}
