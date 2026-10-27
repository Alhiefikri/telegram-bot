<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('orders')
            ->where('order_status', 'pending_payment')
            ->where('payment_status', 'pending')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('payments')
                ->whereColumn('payments.order_id', 'orders.id')
                ->where('provider', 'sakurupiah')->where('status', 'pending'))
            ->select('id')->orderBy('id')
            ->chunkById(100, function ($orders): void {
                foreach ($orders as $candidate) {
                    DB::transaction(function () use ($candidate): void {
                        $order = DB::table('orders')->where('id', $candidate->id)->lockForUpdate()->first();
                        if ($order === null || $order->order_status !== 'pending_payment' || $order->payment_status !== 'pending') {
                            return;
                        }

                        $payments = DB::table('payments')->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
                        if (! $payments->contains(fn ($payment): bool => $payment->provider === 'sakurupiah' && $payment->status === 'pending')
                            || $payments->contains(fn ($payment): bool => $payment->status === 'paid')) {
                            return;
                        }

                        $deadline = CarbonImmutable::parse($order->created_at)->addMinutes(15);
                        if ($order->expires_at !== null) {
                            $deadline = $deadline->min(CarbonImmutable::parse($order->expires_at));
                        }
                        DB::table('orders')->where('id', $order->id)->update(['expires_at' => $deadline]);

                        foreach ($payments as $payment) {
                            if ($payment->provider !== 'sakurupiah' || $payment->status !== 'pending') {
                                continue;
                            }
                            $paymentDeadline = $payment->expired_at === null
                                ? $deadline : $deadline->min(CarbonImmutable::parse($payment->expired_at));
                            $updates = ['expired_at' => $paymentDeadline];
                            $presentation = $payment->provider_presentation === null
                                ? null : json_decode(Crypt::decryptString($payment->provider_presentation), true, flags: JSON_THROW_ON_ERROR);
                            if (is_array($presentation) && isset($presentation['effective_expires_at'])) {
                                $presentation['effective_expires_at'] = $paymentDeadline
                                    ->min(CarbonImmutable::parse($presentation['effective_expires_at']))->toIso8601String();
                                $updates['provider_presentation'] = Crypt::encryptString(json_encode($presentation, JSON_THROW_ON_ERROR));
                            }
                            DB::table('payments')->where('id', $payment->id)->update($updates);
                        }
                    });
                }
            });
    }

    public function down(): void
    {
        // A rollback must not extend a checkout deadline or reserve released stock again.
    }
};
