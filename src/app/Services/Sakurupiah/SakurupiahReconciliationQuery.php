<?php

namespace App\Services\Sakurupiah;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Support\PaymentProvider;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

final class SakurupiahReconciliationQuery
{
    public const PAYMENT_COLUMNS = [
        'id',
        'order_id',
        'provider',
        'merchant_ref',
        'provider_reference',
        'provider_total_payment',
        'provider_fee',
        'provider_payment_method',
        'sakurupiah_channel',
        'provider_create_state',
        'provider_create_last_error',
        'provider_created_at',
        'provider_expires_at',
        'method',
        'amount',
        'currency',
        'status',
        'paid_at',
        'expired_at',
        'created_at',
        'updated_at',
    ];

    /**
     * Build the bounded local candidate query used by reconciliation and
     * operational backlog metrics.
     *
     * @return Builder<Payment>
     */
    public function eligible(CarbonInterface $releasedCutoff): Builder
    {
        return Payment::query()
            ->select(self::PAYMENT_COLUMNS)
            ->where('provider', PaymentProvider::SAKURUPIAH)
            ->whereNotNull('merchant_ref')
            ->where('method', SakurupiahCreateInvoice::PAYMENT_METHOD_QRIS)
            ->where('currency', 'IDR')
            ->where('status', '<>', PaymentStatus::Paid->value)
            ->where(function (Builder $query) use ($releasedCutoff): void {
                $query->where(function (Builder $referenced) use ($releasedCutoff): void {
                    $referenced->whereNotNull('provider_reference')
                        ->where(function (Builder $status) use ($releasedCutoff): void {
                            $status->where('status', PaymentStatus::Pending->value)
                                ->orWhere(function (Builder $terminal) use ($releasedCutoff): void {
                                    $terminal->whereIn('status', [PaymentStatus::Expired->value, PaymentStatus::Failed->value])
                                        ->where(function (Builder $deadline) use ($releasedCutoff): void {
                                            // Lookup writes must not restart the late-money window.
                                            $deadline->where('expired_at', '>=', $releasedCutoff)
                                                ->orWhere(fn (Builder $legacy) => $legacy
                                                    ->whereNull('expired_at')
                                                    ->where('created_at', '>=', $releasedCutoff));
                                        });
                                });
                        });
                })->orWhereIn('provider_create_state', [
                    // Uncertain money keeps its existing discovery path even
                    // after local expiry or the ordinary terminal lookback.
                    SakurupiahInvoiceLifecycleService::STATE_OUTCOME_UNKNOWN,
                    SakurupiahInvoiceLifecycleService::STATE_RECOVERY_UNCONFIRMED,
                ])->orWhere(fn (Builder $retry) => $retry
                    ->where('status', PaymentStatus::Pending->value)
                    ->where('provider_create_state', SakurupiahInvoiceLifecycleService::STATE_CREATE_FAILED));
            });
    }
}
