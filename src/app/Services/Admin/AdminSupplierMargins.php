<?php

namespace App\Services\Admin;

use App\Enums\SupplierProvider;
use App\Jobs\SendProductPriceChangeNotifications;
use App\Models\Product;
use App\Models\ProductSupplierSource;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Pricing\ProductQuantityPricing;
use App\Services\Suppliers\SupplierAvailability;
use App\Services\Suppliers\SupplierExchangeRate;
use App\Services\Suppliers\SupplierMoney;
use App\Services\Suppliers\SupplierPricing;
use App\Services\Telegram\ProductPriceChangeNotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class AdminSupplierMargins
{
    public const PAGE_SIZE = 25;

    private const MAX_SOURCES = 100;

    private const ITEMS = 'id,supplier_id,currency,unit_price_micros,price_tiers,bulk_discounts,stock,reserved_quantity,orderable,requires_input,synced_at';

    private const SUPPLIERS = 'id,name,provider,currency,currency_confirmed,exchange_rate_idr,is_active,balance_micros,reserved_balance_micros,synced_at,sync_expires_at';

    private const TIERS = 'quantityPriceTiers:id,product_id,minimum_quantity,price_amount,usdt_price_amount';

    public function __construct(
        private readonly SupplierPricing $supplierPricing,
        private readonly ProductQuantityPricing $retailPricing,
        private readonly SupplierAvailability $availability,
        private readonly AuditLogger $audit,
        private readonly ProductPriceChangeNotificationService $notifications,
    ) {}

    public function page(array $filters): array
    {
        $products = Product::query()
            ->select(['id', 'name', 'price_amount', 'usdt_price_amount', 'is_active'])
            ->whereHas('supplierSources', function (Builder $query) use ($filters): void {
                if (isset($filters['supplier'])) {
                    $query->whereHas('catalogItem', fn (Builder $items) => $items->where('supplier_id', $filters['supplier']));
                }
            })
            ->with($this->relations())
            ->with(['supplierSources.catalogItem:'.self::ITEMS, 'supplierSources.catalogItem.supplier:'.self::SUPPLIERS])
            ->orderBy('id')
            ->cursorPaginate(self::PAGE_SIZE)
            ->appends($filters);

        $rows = $products->getCollection()
            ->map(fn (Product $product) => $this->row($product, $filters['target'], $filters['rounding']))
            ->filter(fn (array $row) => ! ($filters['action'] ?? false) || $row['needs_action'])
            ->sortBy(fn (array $row) => [$row['rank'], $row['delta'] ?? 0, $row['id']])
            ->values();

        return [$products, $rows];
    }

    /** $edits contain only the base IDR amount and its observed row fingerprint. */
    public function review(array $edits, int $target, int $rounding): array
    {
        return $this->changes($edits, $target, $rounding, false);
    }

    public function save(array $edits, int $target, int $rounding, User $actor, ?string $ip): int
    {
        abort_unless($actor->role?->canManageStoreOperations() === true, 403);

        [$count, $eventIds] = DB::transaction(function () use ($edits, $target, $rounding, $actor, $ip): array {
            [$changes, $products, $suppliers, $items, $sources] = $this->changes($edits, $target, $rounding, true);
            $products = $products->only(array_keys($changes));
            $oldPrices = [];
            foreach ($products as $product) {
                $oldPrices[$product->id] = $product->only(['price_amount', 'usdt_price_amount']);
                $product->price_amount = $changes[$product->id]['new_price'];
                $product->save();
            }

            $this->availability->refreshLocked($products, $suppliers, $items, $sources);
            $events = [];
            foreach ($products as $product) {
                $this->audit->log('product.updated', $product, ['price_amount' => $oldPrices[$product->id]['price_amount']], ['price_amount' => $product->price_amount], $actor, $ip);
                $event = $this->notifications->createEventForCommittedUpdate($product, $oldPrices[$product->id], $product->only(['price_amount', 'usdt_price_amount']));
                if ($event !== null) {
                    $events[] = $event->id;
                }
            }

            return [count($changes), $events];
        }, 5);

        foreach ($eventIds as $id) {
            SendProductPriceChangeNotifications::dispatch($id, now()->toISOString())
                ->onQueue('telegram-broadcast')->afterCommit();
        }

        return $count;
    }

    private function relations(): array
    {
        return [
            'supplierSources' => fn ($query) => $query
                ->select(['id', 'product_id', 'supplier_catalog_item_id', 'priority', 'is_active', 'version'])
                ->orderBy('priority')->orderBy('id')->limit(self::MAX_SOURCES + 1),
            'supplierPricePolicy:id,product_id,mode,markup_basis_points,profit_idr,minimum_profit_idr,rounding_idr,version',
            self::TIERS,
        ];
    }

    private function changes(array $edits, int $target, int $rounding, bool $lock): array
    {
        $query = Product::query()->whereKey(array_column($edits, 'id'))->orderBy('id');
        if ($lock) {
            // Same order as checkout, currency updates and SupplierAvailability.
            $query->lock(DB::getDriverName() === 'pgsql' ? 'for no key update' : true);
        }
        $products = $query->get()->keyBy('id');
        abort_unless($products->count() === count($edits), 409, 'Produk sudah berubah. Muat ulang daftar margin.');
        $products->load($this->relations());
        abort_if($products->contains(fn (Product $product) => $product->supplierSources->count() > self::MAX_SOURCES), 409, 'Jumlah sumber melebihi batas tinjauan.');
        $sources = new Collection($products->flatMap(fn (Product $product) => $product->supplierSources)->all());
        $catalogIds = $sources->pluck('supplier_catalog_item_id')->unique();
        $itemQuery = SupplierCatalogItem::query()->whereIn('id', $catalogIds)->orderBy('id');
        $supplierQuery = Supplier::query()->whereIn('id', (clone $itemQuery)->reorder()->select('supplier_id'))->orderBy('id');
        $suppliers = ($lock ? $supplierQuery->lockForUpdate() : $supplierQuery)->get(explode(',', self::SUPPLIERS))->keyBy('id');
        $items = ($lock ? $itemQuery->lockForUpdate() : $itemQuery)->get(explode(',', self::ITEMS))->keyBy('id');
        foreach ($items as $item) {
            $item->setRelation('supplier', $suppliers->get($item->supplier_id));
        }
        foreach ($sources as $source) {
            $source->setRelation('catalogItem', $items->get($source->supplier_catalog_item_id));
        }
        if ($lock) {
            $products->load('family:id,icon,custom_emoji_id');
        }

        $changes = [];
        foreach ($edits as $edit) {
            $product = $products->get((int) $edit['id']);
            $row = $this->row($product, $target, $rounding);
            abort_unless($row['editable'] && hash_equals($row['version'], $edit['version']), 409, 'Harga, mapping, atau data supplier berubah. Muat ulang daftar dan tinjau lagi.');
            $newPrice = (int) $edit['price'];
            if ($row['price'] === $newPrice) {
                continue;
            }
            try {
                $this->retailPricing->validateSchedule($newPrice, $product->usdt_price_amount, $this->tiers($product));
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['edits' => 'Harga dasar '.$product->name.' tidak kompatibel dengan tier grosir yang sekarang. Tier tidak diubah.']);
            }
            $candidate = clone $product;
            $candidate->price_amount = $newPrice;
            $after = $this->row($candidate, $target, $rounding);
            $changes[$product->id] = $row + [
                'new_price' => $newPrice,
                'new_reference' => $after['reference'],
                'new_delta' => $after['delta'],
                'new_fallback_delta' => $after['fallback_delta'],
            ];
        }

        if ($changes === []) {
            throw ValidationException::withMessages(['edits' => 'Belum ada harga yang diubah.']);
        }

        return $lock ? [$changes, $products, $suppliers, $items, $sources] : $changes;
    }

    private function row(Product $product, int $target, int $rounding): array
    {
        $price = (int) $product->price_amount;
        $policy = $product->supplierPricePolicy;
        $target = max($target, (int) $policy?->minimum_profit_idr);
        $truncated = $product->supplierSources->count() > self::MAX_SOURCES;
        $sources = $product->supplierSources->take(self::MAX_SOURCES)
            ->map(fn (ProductSupplierSource $source) => $this->source($source, $price, (int) $policy?->minimum_profit_idr));
        $reference = $sources->firstWhere('eligible', true) ?? $sources->firstWhere('acquirable', true)
            ?? $sources->firstWhere('cost', '!==', null) ?? $sources->firstWhere('stale', true);
        $cost = $reference['cost'] ?? null;
        $delta = $cost === null ? null : $price - $cost;
        $fallback = $sources->skipUntil(fn (array $source) => $source['id'] === ($reference['id'] ?? null))
            ->skip(1)->where('acquirable', true)
            ->max('cost');
        $editable = ! $truncated && $policy?->mode === 'manual' && $sources->isNotEmpty();
        $tiers = $this->tiers($product);
        $suggestion = null;
        $priceLimit = intdiv(PHP_INT_MAX, 100);
        // A raised price can make an earlier source eligible. Cover its cost
        // as well, instead of recommending a figure that misses its own target.
        $higherPriorityCost = $sources->takeUntil(fn (array $source) => $source['id'] === ($reference['id'] ?? null))
            ->where('acquirable', true)->max('cost');
        $suggestionCost = max($cost ?? 0, $higherPriorityCost ?? 0);
        if ($editable && $delta !== null && $delta < $target && $suggestionCost <= $priceLimit - $target) {
            $tierFloor = max([0, ...array_column($tiers, 'price_amount')]);
            $floor = max(1, $suggestionCost + $target, min($tierFloor, $priceLimit) + 1);
            $rounded = $floor + ($rounding - ($floor % $rounding)) % $rounding;
            if ($rounded <= $priceLimit && $rounded > $price) {
                $suggestion = $rounded;
            }
        }
        $stale = $sources->contains('stale', true);
        $backupRisk = $fallback !== null && $price - $fallback < $target;

        return [
            'id' => $product->id, 'name' => $product->name, 'active' => $product->is_active,
            'price' => $price, 'cost' => $cost, 'delta' => $delta, 'target' => $target, 'suggestion' => $suggestion,
            'reference' => $reference, 'stale' => $stale, 'fallback_delta' => $fallback === null ? null : $price - $fallback,
            'editable' => $editable, 'automatic' => $policy !== null && $policy->mode !== 'manual', 'truncated' => $truncated,
            'needs_action' => $delta === null || $delta < $target || $stale || $backupRisk || $truncated || $policy === null || ! ($reference['acquirable'] ?? false),
            'rank' => $delta === null ? 2 : ($delta < 0 ? 0 : ($delta < $target ? 1 : ($backupRisk || $stale || $truncated || $policy === null || ! ($reference['acquirable'] ?? false) ? 2 : 3))),
            'version' => hash('sha256', serialize([
                $price, $product->usdt_price_amount, $product->is_active, $product->name, $policy?->mode, $policy?->version, $target, $rounding, $tiers,
                $sources->map(fn (array $row) => [$row['id'], $row['version'], $row['cost'], $row['stale'], $row['acquirable'], $row['eligible']])->all(),
            ])),
        ];
    }

    private function source(ProductSupplierSource $source, int $price, int $minimum): array
    {
        $item = $source->catalogItem;
        $supplier = $item?->supplier;
        $valid = $source->is_active && $supplier?->is_active && $supplier->currency_confirmed
            && SupplierProvider::tryFrom($supplier->provider) !== null
            && SupplierExchangeRate::isStorable($supplier->currency, $supplier->exchange_rate_idr)
            && ($item->currency === null || $item->currency === $supplier->currency);
        $stale = $valid && ($item->synced_at === null || $supplier->synced_at === null || ! $supplier->sync_expires_at?->isFuture());
        $cost = null;
        $acquirable = false;
        try {
            if ($valid && ! $stale && $item->orderable && ! $item->requires_input) {
                $micros = $this->supplierPricing->costMicros($item, 1);
                $cost = $micros === null ? null : SupplierMoney::costIdr($micros, $supplier->exchange_rate_idr);
                $acquirable = $cost !== null && $this->supplierPricing->acquirableCostMicros($supplier, $item, 1) !== null;
            }
        } catch (InvalidArgumentException) {
            // Never substitute zero for an unknown or unrepresentable cost.
        }

        return [
            'id' => $source->id, 'version' => $source->version,
            'name' => $supplier?->name, 'cost' => $cost, 'stale' => $stale, 'acquirable' => $acquirable,
            'eligible' => $acquirable && $price - $cost >= $minimum,
            'synced_at' => $item?->synced_at,
        ];
    }

    private function tiers(Product $product): array
    {
        return $product->quantityPriceTiers->map(fn ($tier) => $tier->only(['minimum_quantity', 'price_amount', 'usdt_price_amount']))->all();
    }
}
