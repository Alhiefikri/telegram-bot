<?php

namespace App\Services\StoreBalance;

use App\Enums\StoreBalanceEntryKind;
use App\Enums\StoreBalanceTopUpStatus;
use App\Enums\UserRole;
use App\Events\StoreBalanceOwnerCompensated;
use App\Exceptions\StoreBalanceCompensationRejected;
use App\Models\Customer;
use App\Models\StoreBalanceCompensation;
use App\Models\StoreBalanceTopUp;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Telegram\TelegramBotApiRedactor;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\UnauthorizedException;

final class StoreBalanceOwnerCompensationService
{
    public const TAB = 'balance-issues';

    public const PER_PAGE = 20;

    public const RESOLUTION_TYPE = 'owner_compensation';

    private const SOURCE_TYPE = 'owner_compensation';

    /** @var list<StoreBalanceTopUpStatus> */
    private const QUEUE_STATUSES = [
        StoreBalanceTopUpStatus::Failed,
        StoreBalanceTopUpStatus::RecoveryPending,
        StoreBalanceTopUpStatus::InvalidProviderResult,
        StoreBalanceTopUpStatus::Expired,
    ];

    /** @var array<string, string> */
    private const STATUS_LABELS = [
        'failed' => 'Gagal',
        'recovery_pending' => 'Recovery pending',
        'invalid_provider_result' => 'Hasil provider invalid',
        'expired' => 'Kedaluwarsa',
        'paid' => 'Paid',
        'compensated' => 'Sudah dikompensasi',
    ];

    /** @var array<string, string> */
    private const STATUS_TONES = [
        'failed' => 'danger',
        'recovery_pending' => 'warning',
        'invalid_provider_result' => 'danger',
        'expired' => 'warning',
        'paid' => 'success',
        'compensated' => 'success',
    ];

    /** @var array<string, string> */
    private const PROVIDER_STATE_LABELS = [
        'create_failed' => 'Create gagal',
        'creating' => 'Sedang membuat',
        'outcome_unknown' => 'Outcome tidak diketahui',
        'recovery_unconfirmed' => 'Recovery belum terkonfirmasi',
        'provider_expired' => 'Provider expired',
        'created' => 'QRIS dibuat',
        'provider_SUCCESS' => 'Provider sukses',
    ];

    public function __construct(
        private readonly StoreBalanceLedger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function emptyQueue(User $user): array
    {
        return [
            'state' => $this->canCompensate($user) ? 'ready' : 'forbidden',
            'items' => [],
            'filters' => $this->emptyFilters(),
            'pagination' => $this->emptyPagination(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function queue(User $user, array $filters = []): array
    {
        if (! $this->canCompensate($user)) {
            return $this->emptyQueue($user);
        }

        $filters = $this->normalizeFilters($filters);
        $query = StoreBalanceTopUp::query()
            ->with(['customer.balanceAccount', 'compensation'])
            ->whereDoesntHave('creditEntry')
            ->whereDoesntHave('compensation')
            ->where(function (Builder $query): void {
                $query
                    ->whereIn('status', array_map(
                        static fn (StoreBalanceTopUpStatus $status): string => $status->value,
                        [
                            StoreBalanceTopUpStatus::RecoveryPending,
                            StoreBalanceTopUpStatus::InvalidProviderResult,
                            StoreBalanceTopUpStatus::Expired,
                        ],
                    ))
                    ->orWhere(function (Builder $query): void {
                        $query
                            ->where('status', StoreBalanceTopUpStatus::Failed->value)
                            ->where(function (Builder $query): void {
                                $query
                                    ->whereNotNull('provider_created_at')
                                    ->orWhereNotNull('provider_reference');
                            });
                    });
            })
            ->orderBy('created_at')
            ->orderBy('id');

        $this->applyFilters($query, $filters);

        /** @var LengthAwarePaginator<int, StoreBalanceTopUp> $paginator */
        $paginator = $query
            ->paginate(self::PER_PAGE, ['*'], 'balance_page', $filters['page'])
            ->withQueryString();

        return [
            'state' => 'ready',
            'items' => $paginator->getCollection()
                ->map(fn (StoreBalanceTopUp $topUp): array => $this->queueItem($topUp))
                ->values()
                ->all(),
            'filters' => $filters,
            'pagination' => $this->pagination($paginator),
        ];
    }

    public function release(
        int|string $topUpId,
        User $actor,
        string $providerReference,
        string $reason,
        ?string $ip = null,
    ): StoreBalanceOwnerCompensationResult {
        $this->assertCanCompensate($actor);
        $providerReference = $this->normalizeProviderReference($providerReference);
        $reason = $this->normalizeReason($reason);
        $topUpId = trim((string) $topUpId);

        if ($topUpId === '' || ! ctype_digit($topUpId)) {
            throw new StoreBalanceCompensationRejected('top_up_not_found', 'Top-up tidak ditemukan.');
        }

        return DB::transaction(function () use ($topUpId, $actor, $providerReference, $reason, $ip): StoreBalanceOwnerCompensationResult {
            $topUpIdentity = StoreBalanceTopUp::query()->whereKey($topUpId)->first();
            if ($topUpIdentity === null) {
                throw new StoreBalanceCompensationRejected('top_up_not_found', 'Top-up tidak ditemukan.');
            }

            $customer = Customer::query()
                ->whereKey($topUpIdentity->customer_id)
                ->lockForUpdate()
                ->first();
            if ($customer === null) {
                throw new StoreBalanceCompensationRejected('customer_not_found', 'Customer top-up tidak ditemukan.');
            }

            $account = $this->ledger->lockForTransaction($customer);
            $topUp = StoreBalanceTopUp::query()
                ->whereKey($topUpId)
                ->where('customer_id', $customer->getKey())
                ->lockForUpdate()
                ->first();
            if ($topUp === null) {
                throw new StoreBalanceCompensationRejected('top_up_not_found', 'Top-up tidak ditemukan.');
            }

            $compensation = StoreBalanceCompensation::query()
                ->where('store_balance_top_up_id', $topUp->getKey())
                ->lockForUpdate()
                ->with('ledgerEntry')
                ->first();

            if ($compensation !== null) {
                $ledgerEntry = $compensation->ledgerEntry;
                if ($topUp->status !== StoreBalanceTopUpStatus::Compensated
                    || $ledgerEntry === null
                    || $ledgerEntry->kind !== StoreBalanceEntryKind::OwnerCompensationCredit
                    || $ledgerEntry->amount !== (int) $topUp->amount
                    || $ledgerEntry->source_type !== self::SOURCE_TYPE
                    || $ledgerEntry->source_id !== self::compensationSourceId($topUp->getKey())
                    || $ledgerEntry->idempotency_key !== self::compensationIdempotencyKey($topUp->getKey())) {
                    throw new StoreBalanceCompensationRejected('integrity_failure', 'Resolusi kompensasi tidak konsisten.');
                }

                StoreBalanceOwnerCompensated::dispatch($compensation->getKey());

                return new StoreBalanceOwnerCompensationResult(
                    compensation: $compensation,
                    topUp: $topUp,
                    balanceBefore: (int) $ledgerEntry->balance_after - (int) $ledgerEntry->amount,
                    balanceAfter: (int) $ledgerEntry->balance_after,
                    applied: false,
                );
            }

            if ($topUp->status === StoreBalanceTopUpStatus::Paid || $topUp->creditEntry()->exists()) {
                throw new StoreBalanceCompensationRejected('already_paid', 'Top-up sudah mendapatkan kredit dan tidak dapat dikompensasi.');
            }

            if ($topUp->status === StoreBalanceTopUpStatus::Compensated) {
                throw new StoreBalanceCompensationRejected('integrity_failure', 'Top-up berstatus kompensasi tanpa resolusi immutable.');
            }

            if (! $topUp->isEligibleForOwnerCompensation()) {
                throw new StoreBalanceCompensationRejected('not_eligible', 'Top-up belum memenuhi syarat kompensasi owner.');
            }

            $balanceBefore = (int) $account->balance;
            $idempotencyKey = self::compensationIdempotencyKey($topUp->getKey());
            $sourceId = self::compensationSourceId($topUp->getKey());
            $mutation = $this->ledger->creditOwnerCompensation(
                customer: $customer,
                amount: (int) $topUp->amount,
                compensationId: $sourceId,
                idempotencyKey: $idempotencyKey,
            );

            $now = now();
            $compensation = StoreBalanceCompensation::query()->create([
                'store_balance_top_up_id' => $topUp->getKey(),
                'customer_id' => $customer->getKey(),
                'owner_user_id' => $actor->getKey(),
                'ledger_entry_id' => $mutation->entryId,
                'resolution_type' => self::RESOLUTION_TYPE,
                'idempotency_key' => $idempotencyKey,
                'provider_reference' => $providerReference,
                'reason' => $reason,
                'amount' => (int) $topUp->amount,
                'resolved_at' => $now,
                'created_at' => $now,
            ]);

            $oldStatus = $topUp->status?->value;
            $topUp->forceFill([
                'status' => StoreBalanceTopUpStatus::Compensated,
                'settled_at' => $topUp->settled_at ?? $now,
            ])->save();

            $this->audit->logWithResolvedContext(
                action: 'store_balance.top_up.compensated',
                subject: $topUp,
                old: [
                    'status' => $oldStatus,
                    'balance_before' => $balanceBefore,
                ],
                new: [
                    'status' => StoreBalanceTopUpStatus::Compensated->value,
                    'customer_id' => (int) $customer->getKey(),
                    'amount' => (int) $topUp->amount,
                    'provider_reference' => $providerReference,
                    'reason' => $reason,
                    'ledger_entry_id' => $mutation->entryId,
                    'compensation_id' => (int) $compensation->getKey(),
                    'balance_before' => $balanceBefore,
                    'balance_after' => $mutation->balanceAfter,
                ],
                user: $actor,
                ip: $ip,
            );

            StoreBalanceOwnerCompensated::dispatch($compensation->getKey());

            return new StoreBalanceOwnerCompensationResult(
                compensation: $compensation,
                topUp: $topUp->fresh(['customer', 'compensation']),
                balanceBefore: $balanceBefore,
                balanceAfter: $mutation->balanceAfter,
                applied: $mutation->applied,
            );
        }, 5);
    }

    public static function compensationSourceId(int|string $topUpId): string
    {
        return 'top_up:'.trim((string) $topUpId);
    }

    public static function compensationIdempotencyKey(int|string $topUpId): string
    {
        return 'owner-compensation:top-up:'.trim((string) $topUpId).':v1';
    }

    /**
     * @return array<string, mixed>
     */
    private function queueItem(StoreBalanceTopUp $topUp): array
    {
        $customer = $topUp->customer;
        $status = $topUp->status?->value ?? (string) $topUp->getRawOriginal('status');
        $createdAt = $topUp->created_at;
        $reviewReason = trim((string) $topUp->provider_create_last_error);
        if ($reviewReason === '') {
            $reviewReason = $this->statusLabel($status);
        }

        return [
            'id' => (int) $topUp->getKey(),
            'top_up_id' => (int) $topUp->getKey(),
            'telegram_id' => $customer?->telegram_id === null ? '—' : (string) $customer->telegram_id,
            'customer_name' => $this->customerName($customer),
            'username' => filled($customer?->username) ? '@'.ltrim((string) $customer->username, '@') : '—',
            'amount' => (int) $topUp->amount,
            'amount_label' => Money::idr($topUp->amount),
            'balance_current' => (int) ($customer?->balanceAccount?->balance ?? 0),
            'balance_current_label' => Money::idr($customer?->balanceAccount?->balance ?? 0),
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'status_tone' => self::STATUS_TONES[$status] ?? 'neutral',
            'provider_create_state' => $this->providerStateLabel((string) $topUp->provider_create_state),
            'merchant_ref' => filled($topUp->merchant_ref) ? (string) $topUp->merchant_ref : '—',
            'provider_reference' => filled($topUp->provider_reference) ? (string) $topUp->provider_reference : '—',
            'review_reason' => $this->redactDisplayText($reviewReason, 240),
            'created_at_label' => $this->timestampLabel($topUp->created_at),
            'provider_created_at_label' => $this->timestampLabel($topUp->provider_created_at),
            'provider_expires_at_label' => $this->timestampLabel($topUp->provider_expires_at),
            'expires_at_label' => $this->timestampLabel($topUp->expires_at),
            'provider_create_attempted_at_label' => $this->timestampLabel($topUp->provider_create_attempted_at),
            'age_label' => $this->ageLabel($createdAt),
            'eligible' => $topUp->isEligibleForOwnerCompensation(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if ($filters['telegram_id'] !== '') {
            $query->whereHas('customer', function (Builder $query) use ($filters): void {
                $query->where('telegram_id', $filters['telegram_id']);
            });
        }

        if ($filters['username'] !== '') {
            $query->whereHas('customer', function (Builder $query) use ($filters): void {
                $query->where('username', 'like', '%'.$filters['username'].'%');
            });
        }

        if ($filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if ($filters['provider_reference'] !== '') {
            $query->where('provider_reference', $filters['provider_reference']);
        }

        if ($filters['age'] !== '') {
            $query->where('created_at', '<=', now()->subHours((int) $filters['age']));
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{telegram_id: string, username: string, status: string, age: string, provider_reference: string, page: int}
     */
    private function normalizeFilters(array $filters): array
    {
        $status = trim((string) ($filters['balance_status'] ?? $filters['status'] ?? ''));
        $allowedStatuses = array_map(
            static fn (StoreBalanceTopUpStatus $status): string => $status->value,
            self::QUEUE_STATUSES,
        );

        return [
            'telegram_id' => $this->normalizeFilterText($filters['balance_telegram_id'] ?? $filters['telegram_id'] ?? null, 64),
            'username' => ltrim($this->normalizeFilterText($filters['balance_username'] ?? $filters['username'] ?? null, 64), '@'),
            'status' => in_array($status, $allowedStatuses, true) ? $status : '',
            'age' => in_array((string) ($filters['balance_age'] ?? $filters['age'] ?? ''), ['24', '168', '720'], true)
                ? (string) ($filters['balance_age'] ?? $filters['age'])
                : '',
            'provider_reference' => $this->normalizeFilterText($filters['balance_provider_reference'] ?? $filters['provider_reference'] ?? null, 191),
            'page' => max(1, (int) ($filters['balance_page'] ?? $filters['page'] ?? 1)),
        ];
    }

    private function normalizeProviderReference(string $providerReference): string
    {
        $providerReference = trim($providerReference);
        if ($providerReference === '' || mb_strlen($providerReference) > 191) {
            throw new StoreBalanceCompensationRejected('invalid_provider_reference', 'Referensi provider wajib diisi dan maksimal 191 karakter.');
        }

        return $providerReference;
    }

    private function normalizeReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new StoreBalanceCompensationRejected('invalid_reason', 'Alasan kompensasi wajib diisi.');
        }

        $reason = $this->redactDisplayText($reason, 500);
        if ($reason === '') {
            throw new StoreBalanceCompensationRejected('invalid_reason', 'Alasan kompensasi wajib diisi.');
        }

        return $reason;
    }

    private function assertCanCompensate(User $user): void
    {
        if (! $this->canCompensate($user)) {
            throw new UnauthorizedException;
        }
    }

    private function canCompensate(User $user): bool
    {
        return $user->role === UserRole::Owner || $user->role?->canCompensateStoreBalance() === true;
    }

    private function customerName(?Customer $customer): string
    {
        if ($customer === null) {
            return 'Customer tidak ditemukan';
        }

        return trim((string) ($customer->first_name ?: $customer->username ?: $customer->telegram_id ?: 'Customer'));
    }

    private function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? 'Status tidak dikenal';
    }

    private function providerStateLabel(string $state): string
    {
        if ($state === '') {
            return '—';
        }

        return self::PROVIDER_STATE_LABELS[$state] ?? $this->redactDisplayText($state, 80);
    }

    private function timestampLabel(?CarbonInterface $timestamp): string
    {
        return $timestamp === null
            ? '—'
            : $timestamp->copy()->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s').' WIB';
    }

    private function ageLabel(?CarbonInterface $createdAt): string
    {
        if ($createdAt === null) {
            return '—';
        }

        $seconds = max(0, now()->timestamp - $createdAt->timestamp);
        if ($seconds < 3600) {
            return max(1, intdiv($seconds, 60)).' menit';
        }
        if ($seconds < 86400) {
            return intdiv($seconds, 3600).' jam';
        }

        return intdiv($seconds, 86400).' hari';
    }

    private function redactDisplayText(string $text, int $width): string
    {
        $text = TelegramBotApiRedactor::redactText($text);
        $text = preg_replace('/[\\x00-\\x1F\\x7F]+/u', ' ', $text) ?? $text;

        return trim(mb_strimwidth($text, 0, $width, '...', 'UTF-8'));
    }

    /**
     * @param  LengthAwarePaginator<int, StoreBalanceTopUp>  $paginator
     * @return array<string, int|string|null|bool>
     */
    private function pagination(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'total' => $paginator->total(),
            'has_pages' => $paginator->hasPages(),
            'previous_url' => $paginator->previousPageUrl(),
            'next_url' => $paginator->nextPageUrl(),
        ];
    }

    /**
     * @return array{telegram_id: string, username: string, status: string, age: string, provider_reference: string, page: int}
     */
    private function emptyFilters(): array
    {
        return [
            'telegram_id' => '',
            'username' => '',
            'status' => '',
            'age' => '',
            'provider_reference' => '',
            'page' => 1,
        ];
    }

    /**
     * @return array{current_page: int, last_page: int, from: null, to: null, total: int, has_pages: false, previous_url: null, next_url: null}
     */
    private function emptyPagination(): array
    {
        return [
            'current_page' => 1,
            'last_page' => 1,
            'from' => null,
            'to' => null,
            'total' => 0,
            'has_pages' => false,
            'previous_url' => null,
            'next_url' => null,
        ];
    }

    private function normalizeFilterText(mixed $value, int $maxLength): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return mb_substr(trim((string) $value), 0, $maxLength);
    }
}
