<?php

namespace App\Console\Commands;

use App\Enums\CredentialFormat;
use App\Models\CredentialIdentity;
use App\Models\CredentialIdentityBackfillReadiness;
use App\Models\ProductStockItem;
use App\Services\Credentials\CredentialIdentityBackfillReadinessService;
use App\Services\Credentials\CredentialIdentityConflict;
use App\Services\Credentials\CredentialIdentityFingerprint;
use App\Services\Credentials\LegacyCredentialIdentityParser;
use App\Services\Credentials\StructuredCredentialPayload;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

final class BackfillCredentialIdentityRegistry extends Command
{
    protected $signature = 'credentials:backfill-identity-registry
        {--chunk=100 : Number of retained Product Stock Items to process per batch}
        {--after-id=0 : Resume after this Product Stock Item ID}
        {--dry-run : Inspect retained rows without writing registry identities}';

    protected $description = 'Backfill keyed credential identities from retained Product Stock Items. Credentials hard-deleted before the registry existed cannot be reconstructed.';

    /** @var array<string, int> */
    private array $dryRunClaims = [];

    public function handle(
        CredentialIdentityBackfillReadinessService $readiness,
        CredentialIdentityFingerprint $fingerprints,
        LegacyCredentialIdentityParser $legacyParser,
    ): int {
        $chunk = $this->positiveOption('chunk');
        $afterId = $this->nonNegativeOption('after-id');

        if ($chunk === null || $chunk > 1000) {
            $this->error('Credential identity backfill failed: chunk must be between 1 and 1000.');

            return self::FAILURE;
        }
        if ($afterId === null) {
            $this->error('Credential identity backfill failed: after-id must be a non-negative integer.');

            return self::FAILURE;
        }

        try {
            $fingerprints->assertReady();
        } catch (Throwable) {
            $this->error('Credential identity backfill failed: fingerprint readiness is not configured.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $scanned = 0;
        $registered = 0;
        $alreadyRegistered = 0;
        $skippedUnrecognized = 0;
        $unresolved = 0;
        $conflicts = 0;
        $lastId = $afterId;
        $unresolvedIds = [];
        $conflictIds = [];
        $scanUpperBoundId = (int) (ProductStockItem::query()->max('id') ?? 0);
        $trackedRun = false;
        $baseCounts = [
            'scanned' => 0,
            'registered' => 0,
            'already_registered' => 0,
            'skipped_unrecognized' => 0,
            'conflicts' => 0,
            'unresolved' => 0,
        ];

        if (! $dryRun) {
            try {
                $state = $readiness->begin($afterId, $scanUpperBoundId);
                $scanUpperBoundId = (int) ($state->scan_upper_bound_id ?? $scanUpperBoundId);
                $trackedRun = $state->status === CredentialIdentityBackfillReadiness::STATUS_PENDING
                    && $state->fingerprint_key_digest === $fingerprints->keyDigest();

                if ($trackedRun && $afterId > 0) {
                    $baseCounts = [
                        'scanned' => (int) $state->scanned_count,
                        'registered' => (int) $state->registered_count,
                        'already_registered' => (int) $state->already_registered_count,
                        'skipped_unrecognized' => (int) $state->skipped_unrecognized_count,
                        'conflicts' => (int) $state->conflict_count,
                        'unresolved' => (int) $state->unresolved_count,
                    ];
                }
            } catch (Throwable) {
                $this->error('Credential identity backfill failed: readiness state could not be started safely.');

                return self::FAILURE;
            }
        }

        try {
            ProductStockItem::query()
                ->where('id', '>', $afterId)
                ->where('id', '<=', $scanUpperBoundId)
                ->orderBy('id')
                ->chunkById(
                    $chunk,
                    function (Collection $items) use (
                        &$scanned,
                        &$registered,
                        &$alreadyRegistered,
                        &$skippedUnrecognized,
                        &$unresolved,
                        &$conflicts,
                        &$lastId,
                        &$unresolvedIds,
                        &$conflictIds,
                        $dryRun,
                        $trackedRun,
                        $baseCounts,
                        $readiness,
                        $fingerprints,
                        $legacyParser,
                    ): void {
                        foreach ($items as $stockItem) {
                            /** @var ProductStockItem $stockItem */
                            $scanned++;
                            $lastId = max($lastId, (int) $stockItem->getKey());

                            try {
                                $identities = $this->identitiesFor($stockItem, $legacyParser);
                            } catch (Throwable) {
                                $unresolved++;
                                $unresolvedIds[] = (int) $stockItem->getKey();

                                continue;
                            }

                            if ($identities === []) {
                                $skippedUnrecognized++;

                                continue;
                            }

                            $result = $this->registerIdentities($stockItem, $identities, $dryRun, $fingerprints);
                            if ($result['status'] === 'conflict') {
                                $conflicts++;
                                $unresolved++;
                                $unresolvedIds[] = (int) $stockItem->getKey();
                                $conflictIds[] = (int) $stockItem->getKey();
                                if ($result['existing_stock_item_id'] !== null) {
                                    $conflictIds[] = $result['existing_stock_item_id'];
                                }

                                continue;
                            }
                            if ($result['status'] === 'unresolved') {
                                $unresolved++;
                                $unresolvedIds[] = (int) $stockItem->getKey();

                                continue;
                            }

                            $registered += $result['registered'];
                            $alreadyRegistered += $result['already_registered'];
                        }

                        if (! $dryRun && $trackedRun) {
                            $readiness->recordProgress($lastId, [
                                'scanned' => $baseCounts['scanned'] + $scanned,
                                'registered' => $baseCounts['registered'] + $registered,
                                'already_registered' => $baseCounts['already_registered'] + $alreadyRegistered,
                                'skipped_unrecognized' => $baseCounts['skipped_unrecognized'] + $skippedUnrecognized,
                                'conflicts' => $baseCounts['conflicts'] + $conflicts,
                                'unresolved' => $baseCounts['unresolved'] + $unresolved,
                            ]);
                        }
                    },
                    'id',
                    'id',
                );
        } catch (Throwable) {
            $this->error('Credential identity backfill failed while processing retained rows; readiness remains disabled.');

            return self::FAILURE;
        }

        $unresolvedIds = array_values(array_unique($unresolvedIds));
        sort($unresolvedIds);
        $conflictIds = array_values(array_unique($conflictIds));
        sort($conflictIds);
        $totals = [
            'scanned' => $baseCounts['scanned'] + $scanned,
            'registered' => $baseCounts['registered'] + $registered,
            'already_registered' => $baseCounts['already_registered'] + $alreadyRegistered,
            'skipped_unrecognized' => $baseCounts['skipped_unrecognized'] + $skippedUnrecognized,
            'conflicts' => $baseCounts['conflicts'] + $conflicts,
            'unresolved' => $baseCounts['unresolved'] + $unresolved,
        ];
        $ready = false;

        if (! $dryRun) {
            try {
                if ($trackedRun) {
                    $ready = $readiness->finish(
                        eligibleForReady: true,
                        lastProcessedId: $lastId,
                        scanUpperBoundId: $scanUpperBoundId,
                        counts: $totals,
                    );
                } else {
                    $ready = $readiness->isReady();
                }
            } catch (Throwable) {
                $this->error('Credential identity backfill failed: readiness state could not be finalized safely.');

                return self::FAILURE;
            }
        }

        $this->line(sprintf(
            'SUMMARY mode=%s scanned=%d registered=%d already_registered=%d skipped_unrecognized=%d conflicts=%d unresolved=%d last_id=%d ready=%s',
            $dryRun ? 'dry_run' : 'commit',
            $scanned,
            $registered,
            $alreadyRegistered,
            $skippedUnrecognized,
            $conflicts,
            $unresolved,
            $lastId,
            $ready ? 'true' : 'false',
        ));
        $this->line('UNRESOLVED_STOCK_ITEM_IDS '.($unresolvedIds === [] ? 'none' : implode(',', $unresolvedIds)));
        $this->line('CONFLICT_STOCK_ITEM_IDS '.($conflictIds === [] ? 'none' : implode(',', $conflictIds)));

        if ($unresolved > 0) {
            $this->error('Credential identity backfill found unresolved retained rows; resolve the reported row IDs before enabling structured intake.');

            return self::FAILURE;
        }

        if (! $dryRun && ! $ready) {
            $this->error('Credential identity backfill did not establish complete readiness; run a full commit after resolving the reported rows.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return list<array{kind: string, value: string}>
     */
    private function identitiesFor(ProductStockItem $stockItem, LegacyCredentialIdentityParser $legacyParser): array
    {
        if ($stockItem->credential_format === CredentialFormat::StructuredAccount) {
            $payload = $stockItem->credential_payload;
            if (! is_array($payload)) {
                throw new \UnexpectedValueException('Structured credential payload is missing.');
            }

            return StructuredCredentialPayload::fromArray($payload)->identityValues();
        }

        $secret = $stockItem->secret;
        if (! is_string($secret)) {
            return [];
        }

        return $legacyParser->extract($secret);
    }

    /**
     * @param  list<array{kind: string, value: string}>  $identities
     * @return array{status: 'ok'|'conflict'|'unresolved', registered: int, already_registered: int, existing_stock_item_id: ?int}
     */
    private function registerIdentities(
        ProductStockItem $stockItem,
        array $identities,
        bool $dryRun,
        CredentialIdentityFingerprint $fingerprints,
    ): array {
        try {
            return DB::transaction(function () use ($stockItem, $identities, $dryRun, $fingerprints): array {
                $registered = 0;
                $alreadyRegistered = 0;

                foreach ($identities as $identity) {
                    $fingerprint = $fingerprints->forIdentity($identity['kind'], $identity['value']);
                    $claimKey = $identity['kind'].':'.$fingerprint;

                    if ($dryRun && isset($this->dryRunClaims[$claimKey]) && $this->dryRunClaims[$claimKey] !== (int) $stockItem->getKey()) {
                        throw new CredentialIdentityConflict(
                            (int) $stockItem->getKey(),
                            $identity['kind'],
                            $this->dryRunClaims[$claimKey],
                        );
                    }

                    $existing = CredentialIdentity::query()
                        ->where('identity_kind', $identity['kind'])
                        ->where('fingerprint', $fingerprint)
                        ->lockForUpdate()
                        ->first();

                    if ($existing !== null) {
                        $existingStockItemId = $existing->product_stock_item_id === null
                            ? null
                            : (int) $existing->product_stock_item_id;
                        if ($existingStockItemId !== (int) $stockItem->getKey()) {
                            throw new CredentialIdentityConflict(
                                (int) $stockItem->getKey(),
                                $identity['kind'],
                                $existingStockItemId,
                            );
                        }

                        $alreadyRegistered++;

                        continue;
                    }

                    if (! $dryRun) {
                        CredentialIdentity::query()->create([
                            'identity_kind' => $identity['kind'],
                            'fingerprint' => $fingerprint,
                            'product_id' => $stockItem->product_id,
                            'product_stock_item_id' => $stockItem->getKey(),
                            'first_seen_at' => now(),
                        ]);
                    }

                    $this->dryRunClaims[$claimKey] = (int) $stockItem->getKey();
                    $registered++;
                }

                return [
                    'status' => 'ok',
                    'registered' => $registered,
                    'already_registered' => $alreadyRegistered,
                    'existing_stock_item_id' => null,
                ];
            });
        } catch (CredentialIdentityConflict $conflict) {
            return [
                'status' => 'conflict',
                'registered' => 0,
                'already_registered' => 0,
                'existing_stock_item_id' => $conflict->existingStockItemId,
            ];
        } catch (QueryException) {
            $conflict = $this->findConflict($stockItem, $identities, $fingerprints);
            if ($conflict !== null) {
                return [
                    'status' => 'conflict',
                    'registered' => 0,
                    'already_registered' => 0,
                    'existing_stock_item_id' => $conflict->existingStockItemId,
                ];
            }

            return [
                'status' => 'unresolved',
                'registered' => 0,
                'already_registered' => 0,
                'existing_stock_item_id' => null,
            ];
        } catch (Throwable) {
            return [
                'status' => 'unresolved',
                'registered' => 0,
                'already_registered' => 0,
                'existing_stock_item_id' => null,
            ];
        }
    }

    /**
     * @param  list<array{kind: string, value: string}>  $identities
     */
    private function findConflict(
        ProductStockItem $stockItem,
        array $identities,
        CredentialIdentityFingerprint $fingerprints,
    ): ?CredentialIdentityConflict {
        foreach ($identities as $identity) {
            try {
                $fingerprint = $fingerprints->forIdentity($identity['kind'], $identity['value']);
            } catch (Throwable) {
                return null;
            }

            $existing = CredentialIdentity::query()
                ->where('identity_kind', $identity['kind'])
                ->where('fingerprint', $fingerprint)
                ->first();
            if ($existing === null) {
                continue;
            }

            $existingStockItemId = $existing->product_stock_item_id === null
                ? null
                : (int) $existing->product_stock_item_id;
            if ($existingStockItemId !== (int) $stockItem->getKey()) {
                return new CredentialIdentityConflict(
                    (int) $stockItem->getKey(),
                    $identity['kind'],
                    $existingStockItemId,
                );
            }
        }

        return null;
    }

    private function positiveOption(string $name): ?int
    {
        $value = filter_var($this->option($name), FILTER_VALIDATE_INT);

        return $value === false || $value < 1 ? null : $value;
    }

    private function nonNegativeOption(string $name): ?int
    {
        $value = filter_var($this->option($name), FILTER_VALIDATE_INT);

        return $value === false || $value < 0 ? null : $value;
    }
}
