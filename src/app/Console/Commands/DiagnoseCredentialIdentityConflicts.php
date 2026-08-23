<?php

namespace App\Console\Commands;

use App\Enums\CredentialFormat;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\CredentialIdentity;
use App\Models\ProductStockItem;
use App\Services\CredentialDeliveryFormatter;
use App\Services\Credentials\CredentialIdentityFingerprint;
use App\Services\Credentials\LegacyCredentialIdentityParser;
use App\Services\Credentials\StructuredCredentialPayload;
use BackedEnum;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

final class DiagnoseCredentialIdentityConflicts extends Command
{
    protected $signature = 'credentials:diagnose-identity-conflicts
        {stock-item-ids : Comma-separated Product Stock Item IDs to inspect}
        {--json : Emit one sanitized JSON document instead of text}';

    protected $description = 'Inspect credential identity conflicts using only non-secret stock and registry metadata.';

    public function handle(
        CredentialIdentityFingerprint $fingerprints,
        LegacyCredentialIdentityParser $legacyParser,
    ): int {
        $ids = $this->parseStockItemIds($this->argument('stock-item-ids'));
        if ($ids === null) {
            $this->error('Credential identity diagnostic failed: stock-item-ids must contain 1 to 100 positive IDs.');

            return self::FAILURE;
        }

        try {
            // The diagnostic must remain read-only. In particular, do not call
            // ensureKey(), which may create the persistent fingerprint key.
            $fingerprints->assertReady();
            $report = $this->buildReport($ids, $fingerprints, $legacyParser);
        } catch (Throwable) {
            $this->error('Credential identity diagnostic failed safely; no credential values were reported.');

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            try {
                $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } catch (Throwable) {
                $this->error('Credential identity diagnostic failed while encoding safe output.');

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        $this->writeTextReport($report);

        return self::SUCCESS;
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    private function buildReport(
        array $ids,
        CredentialIdentityFingerprint $fingerprints,
        LegacyCredentialIdentityParser $legacyParser,
    ): array {
        /** @var Collection<int, ProductStockItem> $items */
        $items = ProductStockItem::query()
            ->with(['orderItem.order.payments'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $reportItems = [];
        $claims = [];
        $summary = [
            'inspected_item_count' => 0,
            'missing_item_count' => 0,
            'conflict_identity_count' => 0,
            'registry_identity_count' => 0,
            'unresolved_item_count' => 0,
        ];

        foreach ($ids as $id) {
            $stockItem = $items->get($id);
            if (! $stockItem instanceof ProductStockItem) {
                $reportItems[] = [
                    'stock_item_id' => $id,
                    'credential_format' => 'missing',
                    'retired' => false,
                    'order_status' => null,
                    'payment_status' => null,
                    'availability_status' => 'missing',
                    'identity_status' => 'missing',
                    'identities' => [],
                ];
                $summary['missing_item_count']++;

                continue;
            }

            $summary['inspected_item_count']++;
            $format = $stockItem->safeCredentialFormat();
            $order = $stockItem->orderItem?->order;
            $orderStatus = $this->enumValue($order?->order_status);
            $paymentStatus = $this->enumValue($order?->payment_status);
            $source = $this->extractIdentities($stockItem, $format, $legacyParser);
            if ($source['status'] !== 'recognized') {
                $summary['unresolved_item_count']++;
            }

            $identityRows = [];
            foreach ($source['identities'] as $identity) {
                $fingerprint = $fingerprints->forIdentity($identity['kind'], $identity['value']);
                $registry = CredentialIdentity::query()
                    ->where('identity_kind', $identity['kind'])
                    ->where('fingerprint', $fingerprint)
                    ->first();
                $ownerId = $registry?->product_stock_item_id === null
                    ? null
                    : (int) $registry->product_stock_item_id;
                $registryState = $registry === null
                    ? 'not_registered'
                    : ($ownerId === null
                        ? 'registered_without_stock_owner'
                        : ($ownerId === $id ? 'registered_to_this' : 'registered_to_other'));
                $identityIndex = count($identityRows);
                $claimKey = $identity['kind'].':'.$fingerprint;

                $identityRows[] = [
                    'identity_kind' => $identity['kind'],
                    'registry_state' => $registryState,
                    'registry_owner_stock_item_id' => $ownerId,
                    'conflict_with_stock_item_ids' => [],
                ];
                $claims[$claimKey][] = [$id, $identityIndex];
                if ($registry !== null) {
                    $summary['registry_identity_count']++;
                }
            }

            $reportItems[] = [
                'stock_item_id' => $id,
                'credential_format' => $format?->value ?? 'repair_required',
                'retired' => $stockItem->retired_at !== null,
                'order_status' => $orderStatus,
                'payment_status' => $paymentStatus,
                'availability_status' => $this->availabilityStatus($stockItem, $order, $paymentStatus),
                'identity_status' => $source['status'],
                'identities' => $identityRows,
            ];
        }

        $reportItemsById = [];
        foreach ($reportItems as $reportItem) {
            $reportItemsById[$reportItem['stock_item_id']] = $reportItem;
        }
        foreach ($claims as $claim) {
            $claimItemIds = array_values(array_unique(array_map(
                static fn (array $entry): int => $entry[0],
                $claim,
            )));
            foreach ($claim as [$itemId, $identityIndex]) {
                $identity = $reportItemsById[$itemId]['identities'][$identityIndex];
                $conflicts = array_values(array_filter(
                    $claimItemIds,
                    static fn (int $otherId): bool => $otherId !== $itemId,
                ));
                if ($identity['registry_owner_stock_item_id'] !== null
                    && $identity['registry_owner_stock_item_id'] !== $itemId
                ) {
                    $conflicts[] = $identity['registry_owner_stock_item_id'];
                }
                $conflicts = array_values(array_unique(array_map('intval', $conflicts)));
                sort($conflicts);
                $reportItem = $reportItemsById[$itemId];
                $reportItem['identities'][$identityIndex]['conflict_with_stock_item_ids'] = $conflicts;
                $reportItemsById[$itemId] = $reportItem;
                if ($conflicts !== []) {
                    $summary['conflict_identity_count']++;
                }
            }
        }

        $reportItems = array_values($reportItemsById);
        $statusCounts = [];
        foreach ($reportItems as $item) {
            $status = (string) $item['availability_status'];
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
        }
        ksort($statusCounts);

        return [
            'requested_stock_item_ids' => $ids,
            'summary' => $summary + ['availability_status_counts' => $statusCounts],
            'items' => $reportItems,
        ];
    }

    /**
     * @return array{status: string, identities: list<array{kind: string, value: string}>}
     */
    private function extractIdentities(
        ProductStockItem $stockItem,
        ?CredentialFormat $format,
        LegacyCredentialIdentityParser $legacyParser,
    ): array {
        if ($format === CredentialFormat::StructuredAccount) {
            try {
                $payload = $stockItem->credential_payload;
                if (! is_array($payload)) {
                    return ['status' => 'malformed', 'identities' => []];
                }

                return [
                    'status' => 'recognized',
                    'identities' => StructuredCredentialPayload::fromArray($payload)->identityValues(),
                ];
            } catch (Throwable) {
                return ['status' => 'malformed', 'identities' => []];
            }
        }

        if ($format !== CredentialFormat::Legacy) {
            return ['status' => 'malformed', 'identities' => []];
        }

        try {
            $secret = $stockItem->secret;
            if (! is_string($secret) || trim($secret) === '') {
                return ['status' => 'unreadable', 'identities' => []];
            }
            if ((new CredentialDeliveryFormatter)->parts($secret) === null) {
                return ['status' => 'unrecognized', 'identities' => []];
            }

            return ['status' => 'recognized', 'identities' => $legacyParser->extract($secret)];
        } catch (Throwable) {
            return ['status' => 'unreadable', 'identities' => []];
        }
    }

    private function availabilityStatus(ProductStockItem $stockItem, mixed $order, ?string $paymentStatus): string
    {
        if ($stockItem->retired_at !== null) {
            return 'retired';
        }
        if ($stockItem->order_item_id === null) {
            return 'available';
        }
        if ($order === null) {
            return 'allocated_order_missing';
        }
        if ($paymentStatus === PaymentStatus::Paid->value
            || $order->payments->contains(fn ($payment): bool => $this->enumValue($payment->status) === PaymentStatus::Paid->value)
        ) {
            return 'paid';
        }

        return match ($this->enumValue($order->order_status)) {
            OrderStatus::PendingPayment->value => 'reserved',
            OrderStatus::Expired->value => 'expired',
            OrderStatus::Cancelled->value => 'cancelled',
            default => 'allocated',
        };
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) ? $value : null;
    }

    /**
     * @return list<int>|null
     */
    private function parseStockItemIds(mixed $argument): ?array
    {
        if (! is_string($argument)) {
            return null;
        }

        $parts = preg_split('/\s*,\s*/', trim($argument), -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($parts) || $parts === [] || count($parts) > 100) {
            return null;
        }

        $ids = [];
        foreach ($parts as $part) {
            if (! preg_match('/^[1-9][0-9]*$/D', $part)) {
                return null;
            }
            $id = filter_var($part, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1) {
                return null;
            }
            $ids[] = $id;
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids === [] ? null : $ids;
    }

    /** @param array<string, mixed> $report */
    private function writeTextReport(array $report): void
    {
        $summary = $report['summary'];
        $this->line(sprintf(
            'SUMMARY inspected=%d missing=%d conflict_identities=%d registry_identities=%d unresolved_items=%d',
            $summary['inspected_item_count'],
            $summary['missing_item_count'],
            $summary['conflict_identity_count'],
            $summary['registry_identity_count'],
            $summary['unresolved_item_count'],
        ));

        foreach ($report['items'] as $item) {
            $this->line(sprintf(
                'ITEM id=%d format=%s retired=%s order_status=%s payment_status=%s availability=%s identity_status=%s',
                $item['stock_item_id'],
                $item['credential_format'],
                $item['retired'] ? 'true' : 'false',
                $item['order_status'] ?? 'none',
                $item['payment_status'] ?? 'none',
                $item['availability_status'],
                $item['identity_status'],
            ));
            foreach ($item['identities'] as $identity) {
                $this->line(sprintf(
                    'IDENTITY item=%d kind=%s registry_state=%s registry_owner_stock_item_id=%s conflict_with_stock_item_ids=%s',
                    $item['stock_item_id'],
                    $identity['identity_kind'],
                    $identity['registry_state'],
                    $identity['registry_owner_stock_item_id'] ?? 'none',
                    $identity['conflict_with_stock_item_ids'] === []
                        ? 'none'
                        : implode(',', $identity['conflict_with_stock_item_ids']),
                ));
            }
        }
    }
}
