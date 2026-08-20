<?php

namespace App\Services\Credentials;

use App\Models\CredentialIdentity;
use App\Models\CredentialIdentityBackfillReadiness;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class CredentialIdentityBackfillReadinessService
{
    public function __construct(private readonly CredentialIdentityFingerprint $fingerprints) {}

    public function isReady(): bool
    {
        try {
            $state = $this->current();
            $digest = $this->fingerprints->keyDigest();

            return $state !== null
                && $state->status === CredentialIdentityBackfillReadiness::STATUS_READY
                && is_string($digest)
                && is_string($state->fingerprint_key_digest)
                && hash_equals($state->fingerprint_key_digest, $digest)
                && $state->scan_upper_bound_id !== null
                && $state->completed_at !== null
                && $state->ready_at !== null
                && (int) $state->conflict_count === 0
                && (int) $state->unresolved_count === 0;
        } catch (Throwable) {
            // A missing/unavailable readiness table must never enable a
            // credential-bearing mutation.
            return false;
        }
    }

    public function current(): ?CredentialIdentityBackfillReadiness
    {
        return CredentialIdentityBackfillReadiness::query()
            ->where('singleton_key', CredentialIdentityBackfillReadiness::SINGLETON_KEY)
            ->first();
    }

    /**
     * Start a complete run or return the existing state for a safe resume.
     *
     * A resume is only tracked when the previous commit was left pending and
     * the caller supplies the exact last processed row ID. A diagnostic run
     * after a failed backfill can still inspect rows, but it cannot promote
     * the failed state to ready without a fresh full scan.
     */
    public function begin(int $afterId, int $scanUpperBoundId): CredentialIdentityBackfillReadiness
    {
        $digest = $this->fingerprints->keyDigest();
        if ($digest === null) {
            throw new RuntimeException('Credential identity fingerprint key is not configured.');
        }

        return DB::transaction(function () use ($afterId, $scanUpperBoundId, $digest): CredentialIdentityBackfillReadiness {
            $state = $this->lockedState();
            $this->assertKeyStable($state, $digest);

            if ($afterId > 0
                && $state->status === CredentialIdentityBackfillReadiness::STATUS_PENDING
                && $state->fingerprint_key_digest === $digest
            ) {
                if ((int) $state->last_processed_id !== $afterId) {
                    throw new RuntimeException('Credential identity backfill resume must use the recorded last processed row ID.');
                }

                return $state;
            }

            if ($afterId > 0) {
                return $state;
            }

            $state->forceFill([
                'status' => CredentialIdentityBackfillReadiness::STATUS_PENDING,
                'fingerprint_key_digest' => $digest,
                'scan_upper_bound_id' => $scanUpperBoundId,
                'last_processed_id' => 0,
                'scanned_count' => 0,
                'registered_count' => 0,
                'already_registered_count' => 0,
                'skipped_unrecognized_count' => 0,
                'conflict_count' => 0,
                'unresolved_count' => 0,
                'started_at' => now(),
                'completed_at' => null,
                'ready_at' => null,
            ])->save();

            return $state->refresh();
        }, attempts: 5);
    }

    /**
     * Persist only safe cumulative progress so an interrupted commit can be
     * resumed without treating an incomplete scan as ready.
     *
     * @param  array{scanned:int,registered:int,already_registered:int,skipped_unrecognized:int,conflicts:int,unresolved:int}  $counts
     */
    public function recordProgress(int $lastProcessedId, array $counts): void
    {
        $digest = $this->fingerprints->keyDigest();
        if ($digest === null) {
            throw new RuntimeException('Credential identity fingerprint key is not configured.');
        }

        DB::transaction(function () use ($lastProcessedId, $counts, $digest): void {
            $state = $this->lockedState();
            if ($state->status !== CredentialIdentityBackfillReadiness::STATUS_PENDING
                || $state->fingerprint_key_digest !== $digest
            ) {
                return;
            }

            $state->forceFill($this->countAttributes($counts) + [
                'last_processed_id' => max((int) $state->last_processed_id, $lastProcessedId),
            ])->save();
        }, attempts: 5);
    }

    /**
     * Finish a tracked run. Only a complete, conflict-free scan can publish
     * the durable ready state consumed by runtime.
     *
     * @param  array{scanned:int,registered:int,already_registered:int,skipped_unrecognized:int,conflicts:int,unresolved:int}  $counts
     */
    public function finish(
        bool $eligibleForReady,
        int $lastProcessedId,
        int $scanUpperBoundId,
        array $counts,
    ): bool {
        $digest = $this->fingerprints->keyDigest();
        if ($digest === null) {
            return false;
        }

        return DB::transaction(function () use ($eligibleForReady, $lastProcessedId, $scanUpperBoundId, $counts, $digest): bool {
            $state = $this->lockedState();
            if ($state->status !== CredentialIdentityBackfillReadiness::STATUS_PENDING
                || $state->fingerprint_key_digest !== $digest
            ) {
                return $state->status === CredentialIdentityBackfillReadiness::STATUS_READY
                    && $this->stateMatchesDigest($state, $digest);
            }

            $unresolved = max(0, (int) ($counts['unresolved'] ?? 0));
            $conflicts = max(0, (int) ($counts['conflicts'] ?? 0));
            $coverageComplete = $lastProcessedId >= $scanUpperBoundId;
            $ready = $eligibleForReady && $coverageComplete && $unresolved === 0 && $conflicts === 0;

            $state->forceFill($this->countAttributes($counts) + [
                'last_processed_id' => max((int) $state->last_processed_id, $lastProcessedId),
                'status' => $ready
                    ? CredentialIdentityBackfillReadiness::STATUS_READY
                    : ($unresolved > 0 || $conflicts > 0
                        ? CredentialIdentityBackfillReadiness::STATUS_FAILED
                        : CredentialIdentityBackfillReadiness::STATUS_PENDING),
                'completed_at' => $ready || $unresolved > 0 || $conflicts > 0 ? now() : null,
                'ready_at' => $ready ? now() : null,
            ])->save();

            return $ready;
        }, attempts: 5);
    }

    /** @return array<string, int> */
    private function countAttributes(array $counts): array
    {
        return [
            'scanned_count' => max(0, (int) ($counts['scanned'] ?? 0)),
            'registered_count' => max(0, (int) ($counts['registered'] ?? 0)),
            'already_registered_count' => max(0, (int) ($counts['already_registered'] ?? 0)),
            'skipped_unrecognized_count' => max(0, (int) ($counts['skipped_unrecognized'] ?? 0)),
            'conflict_count' => max(0, (int) ($counts['conflicts'] ?? 0)),
            'unresolved_count' => max(0, (int) ($counts['unresolved'] ?? 0)),
        ];
    }

    private function stateMatchesDigest(CredentialIdentityBackfillReadiness $state, string $digest): bool
    {
        return is_string($state->fingerprint_key_digest)
            && hash_equals($state->fingerprint_key_digest, $digest);
    }

    private function assertKeyStable(CredentialIdentityBackfillReadiness $state, string $digest): void
    {
        if (! is_string($state->fingerprint_key_digest)
            || $state->fingerprint_key_digest === $digest
            || ! CredentialIdentity::query()->exists()
        ) {
            return;
        }

        throw new RuntimeException('Credential identity fingerprint key changed while registry rows exist; restore the original key before backfill.');
    }

    private function lockedState(): CredentialIdentityBackfillReadiness
    {
        $state = CredentialIdentityBackfillReadiness::query()
            ->where('singleton_key', CredentialIdentityBackfillReadiness::SINGLETON_KEY)
            ->lockForUpdate()
            ->first();

        if ($state !== null) {
            return $state;
        }

        return CredentialIdentityBackfillReadiness::query()->create([
            'singleton_key' => CredentialIdentityBackfillReadiness::SINGLETON_KEY,
            'status' => CredentialIdentityBackfillReadiness::STATUS_PENDING,
            'last_processed_id' => 0,
            'scanned_count' => 0,
            'registered_count' => 0,
            'already_registered_count' => 0,
            'skipped_unrecognized_count' => 0,
            'conflict_count' => 0,
            'unresolved_count' => 0,
        ]);
    }
}
