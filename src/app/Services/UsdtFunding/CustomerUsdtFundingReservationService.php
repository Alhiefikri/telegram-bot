<?php

namespace App\Services\UsdtFunding;

use App\Enums\CustomerUsdtFundingAvailabilityReason;
use App\Enums\CustomerUsdtFundingRail;
use App\Enums\CustomerUsdtFundingReservationOutcome;
use App\Enums\CustomerUsdtFundingReservationState;
use App\Enums\UsdtBalanceEntryKind;
use App\Exceptions\CustomerUsdtFundingReservationConflict;
use App\Exceptions\CustomerUsdtFundingUnavailable;
use App\Models\Customer;
use App\Models\CustomerUsdtBalanceEntry;
use App\Models\CustomerUsdtFundingPolicy;
use App\Models\CustomerUsdtFundingReservation;
use App\Models\CustomerUsdtTopUp;
use App\Services\UsdtBalance\UsdtMicroAmount;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Owner of the durable Customer USDT funding reservation: the single open
 * funding target per Customer across rails. It never moves money; it owns the
 * exposure slot and its lifecycle:
 *
 * - reserve() claims the slot under the policy row lock plus a partial unique
 *   index, so concurrent confirmations across rails can never open two open
 *   targets, and a missing/disabled policy or a pause fail-closes the gate.
 * - Expiry never releases a slot: holdExpired() (and every read that locks the
 *   row) moves due rows to expired_unresolved, which still occupies the slot
 *   until the money is genuinely resolved.
 * - markSettled() resolves a reservation only together with a real funding
 *   ledger entry whose source claim, Customer and exact amount match, so a
 *   pending reservation becomes liability atomically with the posting.
 * - release() requires a typed CustomerUsdtFundingRelease resolution; there is
 *   no generic cancel that could drop exposure while money is still possible.
 */
final class CustomerUsdtFundingReservationService
{
    private const FINGERPRINT_VERSION = 1;

    private const IDENTIFIER_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9_.:\-]{0,63}\z/D';

    private const MAX_EXPIRY_BATCH = 1000;

    public function __construct(private readonly CustomerUsdtFundingGate $gate) {}

    public function reserve(
        Customer $customer,
        CustomerUsdtFundingRail $rail,
        UsdtMicroAmount $amount,
        string $sourceType,
        string $sourceId,
        ?CarbonImmutable $expiresAt = null,
    ): CustomerUsdtFundingReservationResult {
        self::assertIdentifier('source type', $sourceType);
        self::assertIdentifier('source id', $sourceId);

        return DB::transaction(function () use ($customer, $rail, $amount, $sourceType, $sourceId, $expiresAt): CustomerUsdtFundingReservationResult {
            $policy = CustomerUsdtFundingPolicy::query()
                ->where('singleton_key', CustomerUsdtFundingPolicy::SINGLETON_KEY)
                ->lockForUpdate()
                ->first();
            if ($policy === null) {
                throw new CustomerUsdtFundingUnavailable(CustomerUsdtFundingAvailabilityReason::PolicyMissing);
            }

            // A repeated confirmation for the same economic source always
            // returns the recorded reservation, even after resolution.
            $existing = CustomerUsdtFundingReservation::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if ($expiresAt !== null && ! $expiresAt->equalTo($existing->expires_at)) {
                    throw new CustomerUsdtFundingReservationConflict('Funding reservation deadline replay differs.');
                }
                $this->assertFingerprint($existing, $this->fingerprint(
                    $customer,
                    $rail,
                    $amount,
                    $policy->applicationFeeMicro(),
                    $policy->policyVersion(),
                    $policy->termsVersion(),
                    $sourceType,
                    $sourceId,
                ));

                return new CustomerUsdtFundingReservationResult(
                    CustomerUsdtFundingReservationOutcome::Replayed,
                    $this->snapshot($existing),
                );
            }

            // One open target per Customer: a new confirmation while a target
            // is still open returns that target instead of opening a second one.
            $active = $this->lockOpenForCustomer((int) $customer->getKey());
            if ($active !== null) {
                $this->holdIfExpired($active);

                return new CustomerUsdtFundingReservationResult(
                    CustomerUsdtFundingReservationOutcome::ExistingActive,
                    $this->snapshot($active),
                );
            }

            $availability = $this->gate->inspectForReservation($rail);
            if (! $availability->available) {
                throw new CustomerUsdtFundingUnavailable($availability->reason, $availability->detail);
            }

            $now = now();
            if ($expiresAt !== null && ! $expiresAt->isFuture()) {
                throw new InvalidArgumentException('Funding reservation requires a future payment deadline.');
            }
            $attributes = [
                'customer_id' => $customer->getKey(),
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'rail' => $rail->value,
                'amount_micro' => $amount->units(),
                'fee_micro' => $policy->applicationFeeMicro(),
                'policy_version' => $policy->policyVersion(),
                'terms_version' => $policy->termsVersion(),
                'request_fingerprint' => $this->fingerprint(
                    $customer,
                    $rail,
                    $amount,
                    $policy->applicationFeeMicro(),
                    $policy->policyVersion(),
                    $policy->termsVersion(),
                    $sourceType,
                    $sourceId,
                ),
                'state' => CustomerUsdtFundingReservationState::Reserved->value,
                'reserved_at' => $now,
                'expires_at' => $expiresAt ?? $now->copy()->addSeconds($policy->confirmationTtlSeconds()),
            ];

            try {
                // Nested transaction = savepoint: a uniqueness race rolls back
                // only this insert, never the surrounding PostgreSQL transaction.
                $reservation = DB::transaction(
                    fn (): CustomerUsdtFundingReservation => CustomerUsdtFundingReservation::query()->create($attributes),
                    1,
                );
            } catch (UniqueConstraintViolationException $exception) {
                $recorded = CustomerUsdtFundingReservation::query()
                    ->where('source_type', $sourceType)
                    ->where('source_id', $sourceId)
                    ->first();
                if ($recorded !== null) {
                    $this->assertFingerprint($recorded, $attributes['request_fingerprint']);

                    return new CustomerUsdtFundingReservationResult(
                        CustomerUsdtFundingReservationOutcome::Replayed,
                        $this->snapshot($recorded),
                    );
                }

                $raced = $this->lockOpenForCustomer((int) $customer->getKey());
                if ($raced !== null) {
                    return new CustomerUsdtFundingReservationResult(
                        CustomerUsdtFundingReservationOutcome::ExistingActive,
                        $this->snapshot($raced),
                    );
                }

                throw $exception;
            }

            return new CustomerUsdtFundingReservationResult(
                CustomerUsdtFundingReservationOutcome::Created,
                $this->snapshot($reservation),
            );
        }, 5);
    }

    public function activeForCustomer(Customer $customer): ?CustomerUsdtFundingReservationResult
    {
        return DB::transaction(function () use ($customer): ?CustomerUsdtFundingReservationResult {
            $active = $this->lockOpenForCustomer((int) $customer->getKey());
            if ($active === null) {
                return null;
            }

            $this->holdIfExpired($active);

            return new CustomerUsdtFundingReservationResult(
                CustomerUsdtFundingReservationOutcome::ExistingActive,
                $this->snapshot($active),
            );
        }, 5);
    }

    public function findBySource(string $sourceType, string $sourceId): ?CustomerUsdtFundingReservationResult
    {
        self::assertIdentifier('source type', $sourceType);
        self::assertIdentifier('source id', $sourceId);

        $reservation = CustomerUsdtFundingReservation::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first();

        return $reservation === null
            ? null
            : new CustomerUsdtFundingReservationResult(
                CustomerUsdtFundingReservationOutcome::Replayed,
                $this->snapshot($reservation),
            );
    }

    /**
     * Resolve the reservation together with the funding ledger entry that
     * created the liability. Callers must invoke this inside the same database
     * transaction as the ledger posting: either both commit or neither does.
     */
    public function markSettled(
        Customer $customer,
        int $reservationId,
        int $entryId,
        string $topUpReference,
    ): CustomerUsdtFundingReservationResult {
        self::assertIdentifier('top-up reference', $topUpReference);

        return DB::transaction(function () use ($customer, $reservationId, $entryId, $topUpReference): CustomerUsdtFundingReservationResult {
            $reservation = $this->lockOwned($customer, $reservationId);

            $entry = CustomerUsdtBalanceEntry::query()
                ->whereKey($entryId)
                ->where('source_type', 'customer_usdt_top_up')
                ->where('source_id', $topUpReference)
                ->where('kind', UsdtBalanceEntryKind::FundingCredit->value)
                ->where('amount_micro', $reservation->amount_micro)
                ->first();
            if ($entry === null) {
                throw new CustomerUsdtFundingReservationConflict(
                    'Reservasi hanya dapat diselesaikan oleh entry kredit funding dengan amount dan source top-up yang sama.',
                );
            }

            $entryCustomerId = DB::table('customer_usdt_balance_accounts')
                ->where('id', $entry->customer_usdt_balance_account_id)
                ->value('customer_id');
            if ((int) $entryCustomerId !== (int) $customer->getKey()) {
                throw new CustomerUsdtFundingReservationConflict(
                    'Entry funding bukan milik Customer reservasi ini.',
                );
            }

            if ($reservation->state->isResolved()) {
                if ($reservation->state === CustomerUsdtFundingReservationState::Settled
                    && (int) $reservation->settled_entry_id === $entryId
                    && $reservation->resolution_reference === $topUpReference) {
                    return new CustomerUsdtFundingReservationResult(
                        CustomerUsdtFundingReservationOutcome::Replayed,
                        $this->snapshot($reservation),
                    );
                }

                throw new CustomerUsdtFundingReservationConflict('Reservasi sudah diselesaikan oleh efek ekonomi lain.');
            }

            $reservation->forceFill([
                'state' => CustomerUsdtFundingReservationState::Settled,
                'resolved_at' => now(),
                'resolution_kind' => 'settled',
                'resolution_reference' => $topUpReference,
                'resolution_actor' => null,
                'settled_entry_id' => $entryId,
            ])->save();

            return new CustomerUsdtFundingReservationResult(
                CustomerUsdtFundingReservationOutcome::Settled,
                $this->snapshot($reservation),
            );
        }, 5);
    }

    /** Reviewed entry must already be atomically linked to an immutable resolution. */
    public function markReviewedSettled(Customer $customer, int $reservationId, int $entryId, string $topUpId, string $reviewId): void
    {
        if (DB::transactionLevel() === 0) {
            throw new CustomerUsdtFundingReservationConflict('Reviewed settlement requires caller money transaction.');
        }
        $reservation = $this->lockOwned($customer, $reservationId);
        $resolution = DB::table('customer_usdt_top_up_resolutions')->where('top_up_id', $topUpId)
            ->where('review_request_id', $reviewId)->where('entry_id', $entryId)->first();
        $entry = CustomerUsdtBalanceEntry::query()->whereKey($entryId)->where('source_type', 'customer_usdt_top_up')
            ->where('source_id', $topUpId)->where('kind', UsdtBalanceEntryKind::FundingCredit->value)->first();
        $owner = $entry === null ? null : DB::table('customer_usdt_balance_accounts')
            ->where('id', $entry->customer_usdt_balance_account_id)->value('customer_id');
        $review = DB::table('usdt_top_up_review_requests')->where('id', $reviewId)->where('top_up_id', $topUpId)->first();
        $target = CustomerUsdtTopUp::query()->whereKey($topUpId)->where('reservation_id', $reservationId)->first();
        if ($resolution === null || $entry === null || $review === null || $target === null
            || ! in_array($resolution->kind, ['settled', 'resolved_by_compensation'], true)
            || (int) $owner !== (int) $customer->id || $reservation->resolved_at !== null
            || (int) $entry->amount_micro !== (int) $review->amount_micro - (int) $target->fee_micro
            || (int) $resolution->resolved_amount_micro !== (int) $review->amount_micro) {
            throw new CustomerUsdtFundingReservationConflict('Reviewed funding source does not match its evidence-backed resolution.');
        }
        $reservation->forceFill(['state' => CustomerUsdtFundingReservationState::Settled,
            'resolved_at' => now(), 'resolution_kind' => 'settled',
            'resolution_reference' => $reviewId, 'resolution_actor' => 'owner:'.$review->owner_user_id,
            'settled_entry_id' => $entryId])->save();
    }

    public function release(
        Customer $customer,
        int $reservationId,
        CustomerUsdtFundingRelease $release,
    ): CustomerUsdtFundingReservationResult {
        return DB::transaction(function () use ($customer, $reservationId, $release): CustomerUsdtFundingReservationResult {
            Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            DB::table('customer_usdt_balance_accounts')->where('customer_id', $customer->id)->lockForUpdate()->first();
            $target = CustomerUsdtTopUp::query()->where('reservation_id', $reservationId)
                ->where('customer_id', $customer->id)->lockForUpdate()->first();
            $reservation = $this->lockOwned($customer, $reservationId);
            if ($target !== null) {
                throw new CustomerUsdtFundingReservationConflict('Confirmed top-ups require authenticated evidence-backed UsdtTopUpReview resolution.');
            }

            if ($reservation->state->isResolved()) {
                if ($reservation->state === CustomerUsdtFundingReservationState::Released
                    && $reservation->resolution_kind === $release->kind->value
                    && $reservation->resolution_reference === $release->reference
                    && $reservation->resolution_actor === $release->actor) {
                    return new CustomerUsdtFundingReservationResult(
                        CustomerUsdtFundingReservationOutcome::Replayed,
                        $this->snapshot($reservation),
                    );
                }

                throw new CustomerUsdtFundingReservationConflict('Reservasi sudah diselesaikan oleh efek ekonomi lain.');
            }

            $reservation->forceFill([
                'state' => CustomerUsdtFundingReservationState::Released,
                'resolved_at' => now(),
                'resolution_kind' => $release->kind->value,
                'resolution_reference' => $release->reference,
                'resolution_actor' => $release->actor,
                'settled_entry_id' => null,
            ])->save();

            return new CustomerUsdtFundingReservationResult(
                CustomerUsdtFundingReservationOutcome::Released,
                $this->snapshot($reservation),
            );
        }, 5);
    }

    /**
     * Bounded sweep of due reservations into expired_unresolved. It never
     * releases a slot and never touches money: expiry is not proof that no
     * funds exist, so ambiguous money stays reserved until a resolution.
     */
    public function holdExpired(int $limit = 100, ?Customer $customer = null): int
    {
        if ($limit < 1 || $limit > self::MAX_EXPIRY_BATCH) {
            throw new InvalidArgumentException('USDT funding expiry batch must be between 1 and '.self::MAX_EXPIRY_BATCH.'.');
        }

        $query = CustomerUsdtFundingReservation::query()
            ->where('state', CustomerUsdtFundingReservationState::Reserved->value)
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit($limit);

        if ($customer !== null) {
            $query->where('customer_id', $customer->getKey());
        }

        $ids = $query->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        if ($ids === []) {
            return 0;
        }

        return DB::transaction(function () use ($ids): int {
            $held = 0;
            foreach ($ids as $id) {
                $reservation = CustomerUsdtFundingReservation::query()->whereKey($id)->lockForUpdate()->first();
                if ($reservation !== null && $this->holdIfExpired($reservation)) {
                    $held++;
                }
            }

            return $held;
        }, 5);
    }

    private function lockOpenForCustomer(int $customerId): ?CustomerUsdtFundingReservation
    {
        return CustomerUsdtFundingReservation::query()
            ->where('customer_id', $customerId)
            ->whereNull('resolved_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->first();
    }

    private function lockOwned(Customer $customer, int $reservationId): CustomerUsdtFundingReservation
    {
        $reservation = CustomerUsdtFundingReservation::query()
            ->whereKey($reservationId)
            ->where('customer_id', $customer->getKey())
            ->lockForUpdate()
            ->first();
        if ($reservation === null) {
            throw new CustomerUsdtFundingReservationConflict('Reservasi funding tidak ditemukan untuk Customer ini.');
        }

        return $reservation;
    }

    private function holdIfExpired(CustomerUsdtFundingReservation $reservation): bool
    {
        if ($reservation->state !== CustomerUsdtFundingReservationState::Reserved
            || $reservation->expires_at === null
            || $reservation->expires_at->isFuture()) {
            return false;
        }

        $reservation->forceFill(['state' => CustomerUsdtFundingReservationState::ExpiredUnresolved])->save();

        return true;
    }

    private function fingerprint(
        Customer $customer,
        CustomerUsdtFundingRail $rail,
        UsdtMicroAmount $amount,
        int $feeMicro,
        int $policyVersion,
        int $termsVersion,
        string $sourceType,
        string $sourceId,
    ): string {
        return hash('sha256', json_encode([
            'version' => self::FINGERPRINT_VERSION,
            'variant' => 'customer_usdt_funding_reservation',
            'customer_id' => (int) $customer->getKey(),
            'rail' => $rail->value,
            'amount_micro' => $amount->units(),
            'fee_micro' => $feeMicro,
            'policy_version' => $policyVersion,
            'terms_version' => $termsVersion,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ], JSON_THROW_ON_ERROR));
    }

    private function assertFingerprint(CustomerUsdtFundingReservation $reservation, string $expected): void
    {
        if (! hash_equals((string) $reservation->request_fingerprint, $expected)) {
            throw new CustomerUsdtFundingReservationConflict(
                'Replay reservasi funding membawa payload yang berbeda dari yang tercatat.',
            );
        }
    }

    private function snapshot(CustomerUsdtFundingReservation $reservation): CustomerUsdtFundingReservationSnapshot
    {
        return new CustomerUsdtFundingReservationSnapshot(
            reservationId: (int) $reservation->getKey(),
            customerId: (int) $reservation->customer_id,
            rail: $reservation->rail,
            amountMicro: (int) $reservation->amount_micro,
            feeMicro: (int) $reservation->fee_micro,
            policyVersion: (int) $reservation->policy_version,
            termsVersion: (int) $reservation->terms_version,
            state: $reservation->state,
            sourceType: (string) $reservation->source_type,
            sourceId: (string) $reservation->source_id,
            expiresAt: $reservation->expires_at?->toIso8601String() ?? '',
            resolvedAt: $reservation->resolved_at?->toIso8601String(),
            settledEntryId: $reservation->settled_entry_id === null ? null : (int) $reservation->settled_entry_id,
        );
    }

    private static function assertIdentifier(string $label, string $value): void
    {
        if (preg_match(self::IDENTIFIER_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException("USDT funding {$label} must be a bounded sanitized token.");
        }
    }
}
