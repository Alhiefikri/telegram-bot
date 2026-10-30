<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\ManualFulfillmentReview;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\Payment;
use App\Services\InventoryLedger;
use App\Services\LocalCredentialCompletion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class RecoverLocalOrderCompletion extends Command
{
    protected $signature = 'orders:recover-local-completion {--limit=100}';

    protected $description = 'Repair paid local-credential orders whose completion status was not recorded.';

    public function handle(InventoryLedger $inventory, LocalCredentialCompletion $completion): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 1000) {
            $this->error('Limit must be between 1 and 1000.');

            return self::FAILURE;
        }

        $cursorKey = 'orders:recover-local-completion:cursor';
        $cursor = Cache::get($cursorKey);
        $orders = Order::query()->where('order_status', OrderStatus::Processing->value)
            ->where('payment_status', PaymentStatus::Paid->value)
            ->whereHas('orderItems.stockItems')
            ->when(is_string($cursor) && $cursor !== '', fn ($query) => $query->where('id', '>', $cursor))
            ->orderBy('id')->limit($limit)->pluck('id');
        $recovered = 0;
        foreach ($orders as $id) {
            $changed = DB::transaction(function () use ($id, $inventory, $completion): bool {
                $order = Order::query()->whereKey($id)->lockForUpdate()->first();
                if ($order === null || $order->order_status !== OrderStatus::Processing || $order->payment_status !== PaymentStatus::Paid) {
                    return false;
                }
                $payments = Payment::query()->where('order_id', $id)->orderBy('id')->lockForUpdate()->get();
                if (! $payments->contains(fn (Payment $payment): bool => $payment->status === PaymentStatus::Paid && $payment->provider !== 'manual')
                    || ManualFulfillmentReview::query()->where('order_id', $id)->open()->exists()
                    || OrderRefund::query()->where('order_id', $id)->exists()
                    || ! $inventory->hasSaleProofForOrder($order)
                    || ! $completion->isComplete($order)) {
                    return false;
                }

                $order->update(['order_status' => OrderStatus::Completed]);

                return true;
            }, 5);
            $recovered += (int) $changed;
        }
        if ($orders->count() < $limit) {
            Cache::forget($cursorKey);
        } elseif ($orders->isNotEmpty()) {
            Cache::put($cursorKey, (string) $orders->last(), now()->addDay());
        }

        $this->info('Local order completion: examined='.$orders->count().' recovered='.$recovered);

        return self::SUCCESS;
    }
}
