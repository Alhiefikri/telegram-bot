<?php

namespace App\Services\Suppliers;

use App\Enums\TelegramOutboundEffectType;
use App\Models\Supplier;
use App\Models\SupplierFulfillment;
use App\Models\SupplierHealthEvent;
use App\Models\SupplierPurchase;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectLedgerProtocol;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Durable alert episodes; Redis is only an after-commit delivery wakeup. */
final class SupplierHealthService
{
    public const OBSERVATION_ALERT_SECONDS = 900;

    public const HOLD_ALERT_SECONDS = 900;

    private const ERRORS = [
        'authentication' => 'Autentikasi gagal. Verifikasi akses akun supplier.',
        'invalid_key' => 'API key tidak valid. Verifikasi atau rotasi key melalui Admin.',
        'credential_unreadable' => 'Credential tidak dapat dibaca. Pulihkan pasangan APP_KEY/backup atau lakukan re-entry terkontrol.',
        'rate_limited' => 'Batas request tercapai. Tunggu lalu sinkronkan kembali.',
        'transport' => 'Koneksi supplier terganggu. Tunggu lalu sinkronkan kembali.',
    ];

    public function __construct(
        private readonly TelegramOutboundEffectService $effects,
        private readonly TelegramOutboundEffectDispatcher $dispatcher,
        private readonly TelegramOutboundEffectLedgerProtocol $ledger,
    ) {}

    public static function adminTarget(): ?string
    {
        $target = trim((string) config('services.telegram.admin_telegram_chat_id'));

        return preg_match('/^[1-9][0-9]*$/D', $target) === 1 ? $target : null;
    }

    /** @return array{materialized: int, dispatched: int, missing_target: bool} */
    public function run(int $limit = 25): array
    {
        if (self::adminTarget() === null) {
            return ['materialized' => 0, 'dispatched' => 0, 'missing_target' => true];
        }

        $dispatched = $this->dispatchDue($limit);
        $materialized = $this->materialize($limit);

        return ['materialized' => $materialized, 'dispatched' => $dispatched + $materialized, 'missing_target' => false];
    }

    public function materialize(int $limit = 25): int
    {
        $target = self::adminTarget();
        if ($target === null) {
            return 0;
        }
        $limit = max(1, min(100, $limit));

        $deadline = microtime(true) + 60;

        return DB::transaction(function () use ($target, $limit, $deadline): int {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("SET LOCAL lock_timeout = '5s'");
                DB::statement("SET LOCAL statement_timeout = '10s'");
            }
            // A durable cursor serializes bounded scans, not network delivery.
            // Supplier locks precede only health state rows: never Order locks.
            $scan = DB::table('supplier_health_scans')->where('id', 1)->lockForUpdate()->first();
            $supplierIds = $this->nextIds('suppliers', (int) $scan->supplier_cursor, $limit);
            $fulfillmentIds = $this->nextIds('supplier_fulfillments', (int) $scan->fulfillment_cursor, $limit);
            $groups = [];
            $reviewUpdates = [];
            $lockSupplierIds = $supplierIds;
            foreach (SupplierFulfillment::with([
                'order:id,invoice_number',
                'reservedSupplier:id,currency',
                'purchases.supplier:id,currency',
                'purchases' => fn ($query) => $query
                    ->where(fn ($query) => $query->whereNotNull('health_review_episode')
                        ->orWhere(fn ($query) => $query->whereNull('committed_at')->whereIn('state', ['sending', 'ambiguous', 'awaiting_delivery', 'review'])))
                    ->select(['id', 'supplier_fulfillment_id', 'supplier_id', 'supplier_catalog_item_id', 'quantity', 'expected_cost_micros', 'state', 'health_review_episode', 'last_error', 'created_at', 'committed_at']),
            ])->whereIn('id', $fulfillmentIds)->orderBy('id')
                ->get(['id', 'order_id', 'quantity', 'state', 'reserved_supplier_id', 'reserved_catalog_item_id', 'reserved_cost_micros', 'reserved_quantity', 'sources_snapshot', 'health_review_episode', 'created_at']) as $fulfillment) {
                $this->checkDeadline($deadline);
                if ($fulfillment->reserved_supplier_id !== null) {
                    $lockSupplierIds[] = $fulfillment->reserved_supplier_id;
                }
                foreach ($fulfillment->purchases as $purchase) {
                    $lockSupplierIds[] = $purchase->supplier_id;
                }
                $current = $this->reviewIdentities($fulfillment);
                $previous = DB::table('supplier_health_reviews')->where('supplier_fulfillment_id', $fulfillment->id)->first();
                $known = $previous === null ? [] : json_decode($previous->active_identities, true, 512, JSON_THROW_ON_ERROR);
                $episodes = $this->activeReviewEpisodes($fulfillment);
                $known = array_filter($known, function (string $identity) use ($episodes): bool {
                    $parts = explode(':', $identity, 4);

                    return isset($episodes[implode(':', array_slice($parts, 0, 3))]);
                }, ARRAY_FILTER_USE_KEY);
                foreach (array_diff_key($current, $known) as $identity => $supplierId) {
                    $key = $supplierId ?? 0;
                    $kind = str_starts_with($identity, 'hold:') ? 'holds' : 'reviews';
                    $groups[$key][$kind][$fulfillment->id] = $this->reviewContext($fulfillment, $supplierId);
                }
                if ($previous !== null || $current !== []) {
                    $reviewUpdates[$fulfillment->id] = json_encode($current + $known, JSON_THROW_ON_ERROR);
                }
            }
            $allIds = array_values(array_unique(array_merge($lockSupplierIds, array_filter(array_keys($groups)))));
            $suppliers = Supplier::whereIn('id', $allIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            // First-time review inserts acquire an implicit FK key-share lock on
            // Fulfillment. Acquire Supplier first, matching economic finalization.
            foreach ($reviewUpdates as $fulfillmentId => $identities) {
                $this->checkDeadline($deadline);
                DB::table('supplier_health_reviews')->updateOrInsert(['supplier_fulfillment_id' => $fulfillmentId], ['active_identities' => $identities]);
            }
            foreach ($supplierIds as $id) {
                $this->checkDeadline($deadline);
                $supplier = $suppliers->get($id);
                if ($supplier === null) {
                    continue;
                }
                $state = DB::table('supplier_health_states')->where('supplier_id', $id)->first();
                $lowActive = (bool) ($state?->low_active ?? false);
                $codes = $state === null ? [] : json_decode($state->error_codes, true, 512, JSON_THROW_ON_ERROR);
                $fresh = $supplier->synced_at !== null && $supplier->sync_expires_at?->isFuture() === true;
                $balanceFresh = $supplier->balance_observed_at?->gt(now()->subSeconds(SupplierCatalogSync::FRESHNESS_SECONDS)) === true;
                $observed = $supplier->balance_observed_at ?? $supplier->created_at;
                $stale = $observed?->lte(now()->subSeconds(self::OBSERVATION_ALERT_SECONDS)) === true
                    && ($supplier->is_active || $supplier->reserved_balance_micros > 0
                        || SupplierPurchase::where('supplier_id', $id)->whereNull('committed_at')
                            ->whereIn('state', ['sending', 'ambiguous', 'awaiting_delivery', 'review'])->exists());
                if ($stale && !(bool) ($state?->balance_stale_active ?? false)) {
                    // Wallet observation is its own alert, never bundled as a transaction failure.
                    $groups['balance:'.$id] = ['balance_stale' => true, 'supplier_id' => $id];
                }
                if ($supplier->low_balance_threshold_micros <= 0) {
                    $lowActive = false;
                    $supplier->forceFill(['low_balance_alerted_at' => null])->save();
                } elseif ($balanceFresh && $supplier->balance_micros !== null) {
                    $available = $this->available($supplier);
                    if ($available <= $supplier->low_balance_threshold_micros) {
                        if (! $lowActive) {
                            $groups[$id]['low'] = true;
                            $supplier->forceFill(['low_balance_alerted_at' => now()])->save();
                        }
                        $lowActive = true;
                    } elseif ($supplier->last_error === null) {
                        $lowActive = false;
                        $supplier->forceFill(['low_balance_alerted_at' => null])->save();
                    }
                }
                $error = $supplier->last_error;
                if (isset(self::ERRORS[$error]) && ! in_array($error, $codes, true)) {
                    $groups[$id]['errors'][] = $error;
                    $codes[] = $error;
                } elseif ($error === null && $fresh) {
                    $codes = [];
                }
                DB::table('supplier_health_states')->updateOrInsert(['supplier_id' => $id], [
                    'low_active' => $lowActive, 'error_codes' => json_encode($codes, JSON_THROW_ON_ERROR),
                    'balance_stale_active' => $stale,
                ]);
            }
            $count = 0;
            foreach ($groups as $id => $conditions) {
                $this->checkDeadline($deadline);
                $supplier = $suppliers->get($conditions['supplier_id'] ?? $id);
                $event = SupplierHealthEvent::create(['supplier_id' => $supplier?->id, 'message' => $this->message($supplier, $conditions), 'created_at' => now()]);
                $effect = $this->effects->recordSupplierHealth($event, $target);
                DB::afterCommit(fn () => $this->dispatcher->dispatch($effect));
                $count++;
            }
            DB::table('supplier_health_scans')->where('id', 1)->update([
                'supplier_cursor' => $supplierIds === [] ? 0 : max($supplierIds),
                'fulfillment_cursor' => $fulfillmentIds === [] ? 0 : max($fulfillmentIds),
            ]);

            return $count;
        }, 5);
    }

    /** Called under the sync owner's Supplier lock after a valid normal read. */
    public function observeRecovery(Supplier $supplier, bool $balanceObserved): void
    {
        $values = ['error_codes' => '[]'];
        if ($supplier->low_balance_threshold_micros <= 0
            || ($balanceObserved && $supplier->balance_micros !== null && $this->available($supplier) > $supplier->low_balance_threshold_micros)) {
            $values['low_active'] = false;
            $supplier->forceFill(['low_balance_alerted_at' => null])->save();
        }
        if ($balanceObserved) {
            $values['balance_stale_active'] = false;
        }
        DB::table('supplier_health_states')->where('supplier_id', $supplier->id)->update($values);
    }

    public function dispatchDue(int $limit = 25): int
    {
        $effects = $this->ledger->readyToDispatchQuery()
            ->where('effect_type', TelegramOutboundEffectType::SupplierHealth->value)
            ->orderBy('created_at')->orderBy('id')->limit($limit)->get();
        foreach ($effects as $effect) {
            $this->dispatcher->dispatch($effect);
        }

        return $effects->count();
    }

    private function checkDeadline(float $deadline): void
    {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Supplier health scan exceeded its runtime bound.');
        }
    }

    /** Round-robin IDs, including inactive connections and resolved reviews. */
    private function nextIds(string $table, int $cursor, int $limit): array
    {
        $ids = DB::table($table)->where('id', '>', $cursor)->orderBy('id')->limit($limit)->pluck('id')->all();
        if ($ids === []) {
            $ids = DB::table($table)->orderBy('id')->limit($limit)->pluck('id')->all();
        }

        return $ids;
    }

    /** Retain notified states across reads; only definitive resolution ends an episode. */
    private function activeReviewEpisodes(SupplierFulfillment $fulfillment): array
    {
        $episodes = [];
        foreach ($fulfillment->purchases as $purchase) {
            if ($purchase->health_review_episode !== null) {
                $episodes['purchase:'.$purchase->id.':'.$purchase->health_review_episode] = true;
            }
        }
        if ($fulfillment->health_review_episode !== null) {
            $episodes['fulfillment:'.$fulfillment->id.':'.$fulfillment->health_review_episode] = true;
        }
        foreach ($this->holdIdentities($fulfillment) as $identity => $supplierId) {
            $episodes[$identity] = true;
        }

        return $episodes;
    }

    /** @return array<string, int|null> */
    private function reviewIdentities(SupplierFulfillment $fulfillment): array
    {
        $identities = [];
        foreach ($fulfillment->purchases as $purchase) {
            if (in_array($purchase->state, ['ambiguous', 'review'], true)) {
                $identities['purchase:'.$purchase->id.':'.$purchase->health_review_episode] = $purchase->supplier_id;
            }
        }
        if ($fulfillment->state === 'review') {
            if ($identities !== []) {
                foreach (array_unique(array_values($identities)) as $supplierId) {
                    $identities['fulfillment:'.$fulfillment->id.':'.$fulfillment->health_review_episode] = $supplierId;
                }

                return $identities + $this->holdIdentities($fulfillment, true);
            }
            $supplierId = $fulfillment->reserved_supplier_id;
            if ($supplierId === null) {
                try {
                    $ids = array_unique(array_column($fulfillment->sources_snapshot ?? [], 'supplier_id'));
                    $supplierId = count($ids) === 1 ? (int) reset($ids) : null;
                } catch (Throwable) {
                    // Ciphertext is not evidence of a selected connection.
                }
            }
            $identities['fulfillment:'.$fulfillment->id.':'.$fulfillment->health_review_episode] = $supplierId;
        }

        return $identities + $this->holdIdentities($fulfillment, true);
    }

    /** Hold identity does not change when recovery changes purchase state. */
    private function holdIdentities(SupplierFulfillment $fulfillment, bool $agedOnly = false): array
    {
        $identities = [];
        foreach ($fulfillment->purchases as $purchase) {
            if ($purchase->committed_at === null
                && in_array($purchase->state, ['sending', 'ambiguous', 'awaiting_delivery', 'review'], true)
                && (!$agedOnly || $purchase->created_at->lte(now()->subSeconds(self::HOLD_ALERT_SECONDS)))) {
                $identities['hold:purchase:'.$purchase->id] = $purchase->supplier_id;
            }
        }
        if ($fulfillment->reserved_quantity > 0 && $fulfillment->reserved_supplier_id !== null
            && $fulfillment->purchases->isEmpty()
            && (!$agedOnly || $fulfillment->created_at->lte(now()->subSeconds(self::HOLD_ALERT_SECONDS)))) {
            $identities['hold:fulfillment:'.$fulfillment->id] = $fulfillment->reserved_supplier_id;
        }

        return $identities;
    }

    /** Explicit allowlist: never decrypt purchase payloads or customer attributes. */
    private function reviewContext(SupplierFulfillment $fulfillment, ?int $supplierId): string
    {
        $text = 'Invoice: '.$this->escape($fulfillment->order?->invoice_number ?? $fulfillment->order_id)."\n";
        foreach ($fulfillment->purchases as $purchase) {
            if ($purchase->supplier_id !== $supplierId) {
                continue;
            }
            $text .= 'Purchase #'.$purchase->id.'; SKU #'.$purchase->supplier_catalog_item_id
                .'; Jumlah: '.$purchase->quantity.'; Hold: '.SupplierMoney::decimal($purchase->expected_cost_micros)
                .' '.$this->escape($purchase->supplier?->currency ?? '').'; Waktu: '.$purchase->created_at->toIso8601String()."\n";
            if ($purchase->last_error === 'dispatch_outcome_unknown') {
                $text .= "dispatch_outcome_unknown: hasil pembelian belum terbukti.\n";
            }
        }
        if ($fulfillment->purchases->isEmpty()) {
            $text .= 'Fulfillment #'.$fulfillment->id.'; SKU #'.($fulfillment->reserved_catalog_item_id ?? '-')
                .'; Jumlah: '.$fulfillment->quantity.'; Hold: '.SupplierMoney::decimal($fulfillment->reserved_cost_micros ?? 0)
                .' '.$this->escape($fulfillment->reservedSupplier?->currency ?? '').'; Waktu: '.$fulfillment->created_at->toIso8601String()."\n";
        }

        return $text;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars(mb_substr($value, 0, 160), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function available(Supplier $supplier): int
    {
        // Compare first: a negative projection minus a hold could overflow.
        return $supplier->balance_micros <= $supplier->reserved_balance_micros ? 0 : $supplier->balance_micros - $supplier->reserved_balance_micros;
    }

    private function message(?Supplier $supplier, array $conditions): string
    {
        $name = $supplier === null ? 'belum ada koneksi terpilih' : htmlspecialchars(mb_substr($supplier->name, 0, 160), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = "<b>Health supplier</b>\nKoneksi: ".$name."\n";
        if ($supplier?->balance_micros !== null && $supplier->balance_observed_at?->gt(now()->subSeconds(SupplierCatalogSync::FRESHNESS_SECONDS)) === true) {
            $text .= 'Saldo tersedia: '.SupplierMoney::decimal($this->available($supplier)).' '.$this->escape($supplier->currency)."\n";
        } else {
            $text .= "Saldo tersedia: belum diketahui\n";
        }
        $text .= 'Observasi saldo: '.($supplier?->balance_observed_at?->toIso8601String() ?? 'belum diketahui');
        if ($supplier?->balance_observed_at?->gt(now()->subSeconds(SupplierCatalogSync::FRESHNESS_SECONDS)) !== true) {
            $text .= ' (stale; bukan bukti saldo nol)';
        }
        $text .= "\n";
        if ($conditions['balance_stale'] ?? false) {
            $text .= "Observasi saldo tertahan / tidak ter-observasi >= 15 menit; saldo lama bukan saldo terbaru.\n";
            $blockers = SupplierPurchase::where('supplier_id', $supplier->id)->whereNull('committed_at')
                ->whereIn('state', ['sending', 'ambiguous', 'awaiting_delivery', 'review'])
                ->orderBy('id')->limit(10)->get(['id', 'supplier_catalog_item_id', 'expected_cost_micros', 'created_at']);
            foreach ($blockers as $purchase) {
                $text .= 'Purchase #'.$purchase->id.'; SKU #'.$purchase->supplier_catalog_item_id
                    .'; Hold: '.SupplierMoney::decimal($purchase->expected_cost_micros).' '.$this->escape($supplier->currency)
                    .'; Sejak: '.$purchase->created_at->toIso8601String()."\n";
            }
            $text .= "Maksimal 10 blocker ditampilkan; daftar lengkap di monitor privat.\n";
        }
        if (($conditions['holds'] ?? []) !== []) {
            $text .= "Hold menua >= 15 menit; jangan lepaskan tanpa bukti.\n";
        }
        if ($conditions['low'] ?? false) {
            $text .= "Saldo tersedia <= ambang. Tinjau hold dan lakukan top-up sebelum menerima pesanan baru.\n";
        }
        foreach ($conditions['errors'] ?? [] as $code) {
            $text .= self::ERRORS[$code]."\n";
        }
        $count = count($conditions['reviews'] ?? []);
        $text .= 'Review/ambigu baru: '.$count." obligation (tanpa hitung ganda).\n";
        if ($count > 0) {
            $text .= "Cek exact order dan bukti debit/delivery; jangan beli ulang atau fallback. Daftar lengkap ada di monitor privat.\n";
        }
        foreach (array_merge($conditions['reviews'] ?? [], $conditions['holds'] ?? []) as $context) {
            // Keep whole context records and reserve room for the monitor link.
            if (mb_strlen($text) + mb_strlen($context) > 3500) {
                $text .= "Detail lainnya ada di monitor privat.\n";
                break;
            }
            $text .= $context;
        }
        $text .= 'Admin: '.htmlspecialchars(route('admin.suppliers.purchases'), ENT_QUOTES, 'UTF-8');

        return $text;
    }
}
