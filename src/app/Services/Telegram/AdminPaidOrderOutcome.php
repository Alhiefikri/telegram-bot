<?php

namespace App\Services\Telegram;

use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectType;
use App\Models\ManualFulfillmentReview;
use App\Models\Order;
use App\Models\SupplierFulfillment;
use App\Models\TelegramOutboundEffect;

final class AdminPaidOrderOutcome
{
    public const ESCALATION_SECONDS = 120;

    public function applies(Order $order): bool
    {
        return $order->api_order_claim_id === null && filled($order->customer?->telegram_id);
    }

    /** @return array{ready: bool, resolved: bool, lines: list<string>, action: string} */
    public function read(Order $order): array
    {
        $fulfillments = SupplierFulfillment::query()->with(['purchases', 'manualHandoffs'])->where('order_id', $order->id)->get()->keyBy('order_item_id');
        $effects = TelegramOutboundEffect::query()->where('source_type', Order::class)->where('source_id', (string) $order->id)
            ->where('effect_type', TelegramOutboundEffectType::ReceiptCredentials->value)->get();
        $complete = false;
        foreach ($effects->groupBy('effect_group_key') as $key => $group) {
            if (app(TelegramOutboundEffectLedgerProtocol::class)->isReceiptGroupComplete($key)
                && $group->every(fn ($effect) => app(TelegramOutboundEffectPolicy::class)->isReceiptEffectComplete($effect)
                    && ($effect->state->value !== 'sent' || app(TelegramOutboundResponseValidator::class)->isValidMessageId((string) $effect->telegram_message_id)))) {
                $complete = true;
            }
        }
        $credentialEffects = $effects->filter(fn ($effect) => ($effect->outcome_metadata['contains_credentials'] ?? null) !== false
            && ! ($effect->sequence === 1 && $effect->template_version === TelegramOutboundEffectService::TEMPLATE_RECEIPT_INVOICE));
        $acknowledgedChunks = $credentialEffects->filter(fn ($effect) => $effect->state->value === 'sent'
            && app(TelegramOutboundResponseValidator::class)->isValidMessageId((string) $effect->telegram_message_id))->count();
        $unknown = $credentialEffects->contains(fn ($effect) => in_array($effect->state->value, ['unknown', 'manual_review'], true));
        $failed = $credentialEffects->contains(fn ($effect) => app(TelegramOutboundEffectPolicy::class)->isTerminalFailure($effect));
        $failure = $credentialEffects->first(fn ($effect) => app(TelegramOutboundEffectPolicy::class)->isTerminalFailure($effect));
        $failureReason = match ($failure?->last_error_code) {
            'telegram_http_403' => 'Telegram menolak akses bot ke buyer',
            'telegram_http_400' => 'Telegram menolak permintaan pengiriman',
            default => 'pengiriman berhenti pada kegagalan pasti; periksa bukti effect',
        };
        $review = ManualFulfillmentReview::query()->where('order_id', $order->id)->open()->exists();
        $resolved = $order->orderItems->isNotEmpty();
        $intervention = $review || $unknown || $failed;
        $lines = [];
        foreach ($order->orderItems as $item) {
            $f = $fulfillments->get($item->id);
            $supplierResolved = true;
            $allRejected = $f !== null && $f->state === 'review' && $f->purchases->isNotEmpty()
                && $f->purchases->every(fn ($purchase) => $purchase->state === 'rejected' && $purchase->committed_at === null);
            $purchases = [];
            foreach ($f?->purchases ?? [] as $purchase) {
                $acquired = $purchase->committed_at !== null && filled($purchase->supplier_order_id)
                    && $purchase->actual_cost_micros !== null && $purchase->observed_quantity !== null;
                $rejected = $purchase->state === 'rejected' && $purchase->committed_at === null;
                $supplierResolved = $supplierResolved && (($acquired && ! in_array($purchase->state, ['ambiguous', 'review'], true)) || $rejected);
                $intervention = $intervention || in_array($purchase->state, ['ambiguous', 'review'], true) || $f->state === 'review';
                $purchases[] = 'purchase #'.$purchase->id.(filled($purchase->supplier_order_id) ? ' / supplier ID '.$purchase->supplier_order_id : '')
                    .': '.($acquired ? 'pembelian berhasil; '.$purchase->observed_quantity.'/'.$purchase->quantity.' unit terbukti' : ($rejected
                        ? 'pembelian ditolak / tidak terjadi sesuai bukti no-debit'
                        : 'hasil pembelian supplier belum dapat dipastikan; jangan beli ulang, periksa order supplier asli'));
            }
            if ($f !== null && $purchases === []) {
                $supplierResolved = false;
                $purchases[] = 'belum ada bukti pembelian supplier; periksa fulfillment, jangan beli ulang';
            }
            $handoff = (int) ($f?->manualHandoffs->sum('quantity') ?? 0);
            $allocated = (int) $item->stockItems()->count();
            $delivered = $complete ? $allocated : 0;
            $refunded = (int) ($f?->refunded_quantity ?? 0);
            $buyerResolved = ($complete && $delivered + $refunded === (int) $item->quantity)
                || $handoff + $refunded >= $item->quantity || $failed || ($allRejected && $allocated === 0);
            $buyer = $complete ? $delivered.'/'.$item->quantity.' unit terkirim ke buyer (receipt Telegram acknowledged)'
                : ($handoff > 0 ? $handoff.'/'.$item->quantity.' unit handoff luar bot dicatat; bukan receipt Telegram'
                : ($unknown ? 'hasil pengiriman Telegram belum dapat dipastikan; periksa Manual Delivery Review, jangan kirim ulang otomatis'
                : ($failed ? ($acknowledgedChunks > 0 ? 'pengiriman sebagian; jumlah unit diterima belum dapat dipastikan' : 'akun belum terkirim')
                    .'; '.$failureReason.', periksa Manual Delivery Review'
                : ($allRejected && $allocated === 0 ? '0/'.$item->quantity.' unit; buyer belum menerima, pembelian ditolak'
                : 'jumlah unit terkirim belum terkonfirmasi; receipt Telegram '.$acknowledgedChunks.'/'.$credentialEffects->count().' bagian acknowledged; periksa progres pengiriman, jangan kirim ulang otomatis'))));
            $resolved = $resolved && $supplierResolved && $buyerResolved && (! $unknown || $complete);
            $lines[] = $item->product_name.' x '.$item->quantity.': '.$buyer.($refunded > 0 ? '; '.$refunded.' unit refund tercatat' : '');
            $lines[] = 'Pembelian supplier: '.($purchases === [] ? 'tidak digunakan' : implode('; ', $purchases));
        }
        if ($complete && $review) {
            $resolved = false;
        }
        $paidAt = $order->payments->filter(fn ($payment) => $payment->status === PaymentStatus::Paid && $payment->paid_at !== null)->min('paid_at');
        $deadline = $paidAt !== null && $paidAt->copy()->addSeconds(self::ESCALATION_SECONDS)->lte(now());
        if (! $resolved) {
            $lines[] = 'Alasan: '.($paidAt === null ? 'waktu Paid authoritative belum tersedia; periksa bukti pembayaran'
                : ($deadline ? 'batas tunggu hasil otomatis awal 120 detik tercapai' : 'hasil memerlukan pemeriksaan operator'));
        }

        return ['ready' => $resolved || $intervention || $deadline || $paidAt === null, 'resolved' => $resolved, 'lines' => $lines,
            'action' => $resolved && $complete ? 'Order selesai; seluruh receipt buyer acknowledged. Tidak perlu proses ulang.'
                : 'Periksa dashboard / Manual Delivery Review untuk resolution sesuai bukti. Jangan beli ulang, refund, atau kirim ulang otomatis.'];
    }
}
