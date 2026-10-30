<?php

namespace App\Services\Sakurupiah;

use App\Enums\OrderStatus;
use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Support\PaymentProvider;
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
        'provider_presentation',
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
    public function eligible(): Builder
    {
        return Payment::query()
            ->select(self::PAYMENT_COLUMNS)
            ->where('provider', PaymentProvider::SAKURUPIAH)
            ->whereNotNull('merchant_ref')
            ->where('method', SakurupiahCreateInvoice::PAYMENT_METHOD_QRIS)
            ->where('currency', 'IDR')
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Expired->value, PaymentStatus::Failed->value])
            ->where(function (Builder $query): void {
                $query->where(function (Builder $active): void {
                    $active->where('status', PaymentStatus::Pending->value)
                        ->where(fn (Builder $deadline) => $deadline->whereNull('expired_at')->orWhere('expired_at', '>', now()))
                        ->where(fn (Builder $deadline) => $deadline->whereNull('provider_expires_at')->orWhere('provider_expires_at', '>', now()))
                        ->whereHas('order', fn (Builder $order) => $order
                            ->where('order_status', OrderStatus::PendingPayment->value)
                            ->where(fn (Builder $deadline) => $deadline->whereNull('expires_at')->orWhere('expires_at', '>', now())))
                        ->where(fn (Builder $attempt) => $attempt->whereNotNull('provider_reference')
                            ->orWhereIn('provider_create_state', [
                                SakurupiahInvoiceLifecycleService::STATE_OUTCOME_UNKNOWN,
                                SakurupiahInvoiceLifecycleService::STATE_RECOVERY_UNCONFIRMED,
                                SakurupiahInvoiceLifecycleService::STATE_CREATE_FAILED,
                            ]));
                });
                $this->orWithSuccessIndication($query);
            });
    }

    /** Include payment signals without treating them as authorization to fulfill. */
    public function orWithSuccessIndication(Builder $query): void
    {
        $query->orWhere('provider_create_state', SakurupiahInvoiceLifecycleService::STATE_PROVIDER_BERHASIL)
            ->orWhereHas('paymentEvents', function (Builder $events): void {
                $events->where('provider', PaymentProvider::SAKURUPIAH)
                    ->where('provider_status', SakurupiahTransactionStatus::Successful->value)
                    ->where(fn (Builder $ref) => $ref->whereNull('payments.provider_reference')
                        ->orWhereColumn('payment_events.provider_reference', 'payments.provider_reference'))
                    ->where(fn (Builder $type) => $type
                        ->whereIn('event_type', [PaymentEventType::ProviderCreate->value, PaymentEventType::Reconciliation->value])
                        ->orWhere(fn (Builder $callback) => $callback
                            ->where('event_type', PaymentEventType::Callback->value)
                            ->whereColumn('payment_events.merchant_ref', 'payments.merchant_ref')
                            ->whereNull('processed_at')));
            });
    }

    public function hasSuccessIndication(Payment $payment): bool
    {
        return Payment::query()->whereKey($payment->getKey())
            ->where(fn (Builder $query) => $this->orWithSuccessIndication($query))
            ->exists();
    }
}
