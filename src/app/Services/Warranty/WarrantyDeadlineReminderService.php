<?php

namespace App\Services\Warranty;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Models\SupplierAccountUnit;
use App\Models\TelegramOutboundEffect;
use App\Models\WarrantyClaim;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectLedgerProtocol;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Durable PostgreSQL due-work for the pre-deadline buyer reminder. Redis only
 * wakes the delivery job; the unit marker and outbound effect survive loss.
 */
final class WarrantyDeadlineReminderService
{
    public const WINDOW_MINUTES = 30;

    public function __construct(
        private readonly TelegramOutboundEffectService $effects,
        private readonly TelegramOutboundEffectDispatcher $dispatcher,
        private readonly TelegramOutboundEffectLedgerProtocol $ledger,
    ) {}

    /** @return array{materialized: int, dispatched: int} */
    public function run(int $limit = 25): array
    {
        return [
            'materialized' => $this->materializeDue($limit),
            'dispatched' => $this->dispatchDue($limit),
        ];
    }

    public function materializeDue(int $limit = 25): int
    {
        $materialized = 0;
        foreach ($this->dueCandidates($limit) as $candidate) {
            if ($this->materialize($candidate->id) !== null) {
                $materialized++;
            }
        }

        return $materialized;
    }

    public function dispatchDue(int $limit = 25): int
    {
        $dispatched = 0;
        $effects = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectType::WarrantyDeadlineReminder->value)
            ->whereIn('state', [TelegramOutboundEffectState::Pending->value, TelegramOutboundEffectState::Failed->value])
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')->limit($limit)->get();
        foreach ($effects as $effect) {
            if (! $this->ledger->isDispatchable($effect)) {
                continue;
            }
            $this->dispatcher->dispatch($effect);
            $dispatched++;
        }

        return $dispatched;
    }

    /** @return Collection<int, SupplierAccountUnit> */
    private function dueCandidates(int $limit): Collection
    {
        $now = now();

        return SupplierAccountUnit::query()
            ->whereNull('reminder_materialized_at')
            ->where('provider_state', 'active')
            ->whereNull('confirmation_state')
            ->whereBetween('refund_deadline', [$now, $now->copy()->addMinutes(self::WINDOW_MINUTES)])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('warranty_claims')
                ->whereColumn('warranty_claims.supplier_account_unit_id', 'supplier_account_units.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('product_stock_items')
                ->whereColumn('product_stock_items.id', 'supplier_account_units.product_stock_item_id')
                ->whereNotNull('product_stock_items.retired_at'))
            ->orderBy('refund_deadline')->orderBy('id')->limit($limit)->get(['id']);
    }

    private function materialize(int $unitId): ?TelegramOutboundEffect
    {
        $effect = null;
        DB::transaction(function () use ($unitId, &$effect): void {
            $unit = SupplierAccountUnit::query()->whereKey($unitId)->lockForUpdate()->first();
            if ($unit === null || $unit->reminder_materialized_at !== null) {
                return;
            }
            $unit->loadMissing(['order.customer', 'stockItem']);
            if (! $this->isEligible($unit)) {
                return;
            }
            $effect = $this->effects->recordWarrantyDeadlineReminder($unit);
            if ($effect === null) {
                return;
            }
            $unit->forceFill(['reminder_materialized_at' => now()])->save();
        }, 5);

        return $effect;
    }

    private function isEligible(SupplierAccountUnit $unit): bool
    {
        $order = $unit->order;
        if ($order === null || $unit->refund_deadline === null || ! $unit->refund_deadline->isFuture()
            || $unit->refund_deadline->gt(now()->addMinutes(self::WINDOW_MINUTES))
            || $unit->provider_state !== 'active' || $unit->confirmation_state !== null
            || $unit->stockItem?->retired_at !== null
            || $order->payment_status !== PaymentStatus::Paid
            || ! in_array($order->order_status, [OrderStatus::Processing, OrderStatus::Completed], true)
            || $order->hasOpenLatePaymentReview()
            || $order->customer?->telegram_id === null) {
            return false;
        }

        return ! WarrantyClaim::query()->where('supplier_account_unit_id', $unit->id)->exists();
    }
}
