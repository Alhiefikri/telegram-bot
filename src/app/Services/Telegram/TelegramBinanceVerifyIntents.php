<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Binance\BinanceAutoVerification;
use App\Services\Binance\BinanceVerificationOutcome;
use App\Services\Binance\BinanceVerificationResult;
use App\Jobs\VerifyBinanceOrderId;
use Illuminate\Support\Facades\DB;

class TelegramBinanceVerifyIntents
{
    private const MAX_ORDER_ID_ATTEMPTS = 3;

    private const VERIFICATION_GRACE_MINUTES = 3;

    private const RETRY_DELAY_SECONDS = 30;

    public function __construct(
        private TelegramBotClient $bot,
        private TelegramLocale $locale,
        private TelegramBinanceVerifySession $session,
        private BinanceAutoVerification $verification,
        private TelegramBinanceVerificationEscalator $escalator,
    ) {}

    public function handleVerifyTap(Customer $customer, string $chatId, string $callbackQueryId, string $orderId): void
    {
        $this->handleOrderIdPromptTap($customer, $chatId, $callbackQueryId, $orderId);
    }

    public function handleOrderIdPromptTap(Customer $customer, string $chatId, string $callbackQueryId, string $orderId): void
    {
        [$order, $payment] = $this->findVerifiableOrder($customer, $orderId);

        if (! $order || ! $payment) {
            $this->bot->answerCallbackQuery($callbackQueryId, $this->text($customer, 'Pesanan tidak ditemukan.', 'Order not found.'));

            return;
        }

        if ($guardMessage = $this->guardMessage($customer, $order, $payment)) {
            $this->bot->answerCallbackQuery($callbackQueryId);
            $this->bot->sendMessage($chatId, $guardMessage);

            return;
        }

        if ((int) $payment->binance_order_id_attempts >= self::MAX_ORDER_ID_ATTEMPTS) {
            $this->bot->answerCallbackQuery($callbackQueryId);
            $this->bot->sendMessage($chatId, $this->text(
                $customer,
                'Batas pengiriman Order ID sudah tercapai (maksimal 3 ID). Hubungi admin dengan invoice '.$order->invoice_number.' ya.',
                'You have reached the maximum of 3 Order IDs. Please contact the admin with invoice '.$order->invoice_number.'.',
            ));

            return;
        }

        $this->session->promptOrder($customer, $order->id);
        $this->bot->answerCallbackQuery($callbackQueryId);
        $this->bot->sendMessage($chatId, $this->text(
            $customer,
            "🧾 Kirim Binance Order ID\n━━━━━━━━━━━━━━\nBalas chat ini dengan Order ID dari bukti transfer kamu (hanya angka). ID ini wajib supaya pembayaran bisa diverifikasi otomatis.\n\nCara menemukannya:\nBuka aplikasi Binance → Pay → pilih transfer kamu → Order Details → salin Order ID.",
            "🧾 Send your Binance Order ID\n━━━━━━━━━━━━━━\nReply to this chat with the Order ID from your payment receipt (digits only). This ID is required for automatic verification.\n\nWhere to find it:\nOpen the Binance app → Pay → select your transfer → Order Details → copy the Order ID.",
        ));
    }

    /**
     * Consume free-text input while the customer is being prompted for a
     * Binance order id. Returns true when the message was handled here.
     */
    public function handleOrderIdInput(Customer $customer, string $chatId, string $text): bool
    {
        $orderId = $this->session->promptedOrderId($customer);
        if ($orderId === null) {
            return false;
        }

        [$order, $payment] = $this->findVerifiableOrder($customer, $orderId);

        if (! $order || ! $payment) {
            $this->session->forget($customer);

            return false;
        }

        if ($guardMessage = $this->guardMessage($customer, $order, $payment)) {
            $this->session->forget($customer);
            $this->bot->sendMessage($chatId, $guardMessage);

            return true;
        }

        $candidate = preg_replace('/\s+/', '', $text) ?? '';
        if (preg_match('/^\d{10,30}$/', $candidate) !== 1) {
            $this->bot->sendMessage($chatId, $this->text(
                $customer,
                'Order ID Binance hanya berisi angka (contoh: 445011487581044736). Coba kirim ulang ya, atau ketik /batal untuk berhenti.',
                'A Binance Order ID contains digits only (example: 445011487581044736). Please send it again, or type /batal to stop.',
            ));

            return true;
        }

        if (! $this->session->withinAttemptLimit($order->id)) {
            $this->bot->sendMessage($chatId, $this->text($customer, 'Terlalu banyak percobaan. Tunggu beberapa menit lalu coba lagi ya.', 'Too many attempts. Please wait a few minutes and try again.'));

            return true;
        }

        $submission = $this->persistOrderId($order->id, $candidate);
        if ($submission['status'] === 'limit') {
            $this->bot->sendMessage($chatId, $this->text(
                $customer,
                'Batas pengiriman Order ID sudah tercapai (maksimal 3 ID). Hubungi admin dengan invoice '.$order->invoice_number.' ya.',
                'You have reached the maximum of 3 Order IDs. Please contact the admin with invoice '.$order->invoice_number.'.',
            ));

            return true;
        }

        if ($submission['status'] === 'expired') {
            $this->session->forget($customer);
            $this->bot->sendMessage($chatId, $this->text(
                $customer,
                "⏳ Batas pembayaran sudah lewat. Order {$order->invoice_number} akan ditangani sebagai pembayaran terlambat. Kirim screenshot dengan caption /payment {$order->invoice_number} agar admin bisa mengeceknya.",
                "⏳ The payment deadline has passed. Order {$order->invoice_number} will be handled as a late payment. Send a screenshot with the caption /payment {$order->invoice_number} so the admin can review it.",
            ));

            return true;
        }

        if ($submission['status'] === 'manual') {
            $this->session->forget($customer);
            $this->bot->sendMessage($chatId, $this->text(
                $customer,
                "Verifikasi otomatis tidak tersedia untuk order {$order->invoice_number}. Kirim screenshot pembayaran sebagai foto dengan caption /payment {$order->invoice_number}.",
                "Automatic verification is not available for order {$order->invoice_number}. Send a payment screenshot as a photo with the caption /payment {$order->invoice_number}.",
            ));

            return true;
        }

        /** @var Payment $payment */
        $payment = $submission['payment'];
        $attemptNumber = (int) $payment->binance_order_id_attempts;
        $result = $this->verification->verifyPaymentByProviderOrderId($payment, $candidate);

        if (in_array($result->outcome, [BinanceVerificationOutcome::NotFound, BinanceVerificationOutcome::ProviderError], true)) {
            $this->scheduleRetry($payment, $candidate, $attemptNumber);
        }

        if ($result->confirmed() || ! in_array($result->outcome, [BinanceVerificationOutcome::ProviderError, BinanceVerificationOutcome::NotFound], true)) {
            $this->session->forget($customer);
        }

        $this->respondToResult($customer, $chatId, $order, $payment, $result, allowRetryButtons: true, submittedOrderId: $candidate);

        return true;
    }

    public function retryOrderId(string $paymentId, string $orderId, int $attemptNumber, int $retryNumber): void
    {
        $payment = Payment::query()->with('order.customer')->find($paymentId);
        $order = $payment?->order;
        $customer = $order?->customer;

        if (! $payment || ! $order || ! $customer || ! $this->retryEligible($payment, $order, $orderId, $attemptNumber)) {
            return;
        }

        $result = $this->verification->verifyPaymentByProviderOrderId($payment, $orderId);
        if ($result->outcome === BinanceVerificationOutcome::NotFound || $result->outcome === BinanceVerificationOutcome::ProviderError) {
            if ($payment->binance_verification_grace_until?->isFuture() && config('queue.default') !== 'sync') {
                VerifyBinanceOrderId::dispatch($payment->id, $orderId, $attemptNumber, $retryNumber + 1)
                    ->delay(now()->addSeconds(self::RETRY_DELAY_SECONDS))
                    ->onQueue('default');
            }

            return;
        }

        if ($result->outcome === BinanceVerificationOutcome::OrderNotPending) {
            $payment->refresh();
            $order->refresh();
            if (! $this->retryEligible($payment, $order, $orderId, $attemptNumber)) {
                return;
            }
        }

        $this->session->forget($customer);
        $this->respondToResult($customer, (string) $customer->telegram_id, $order, $payment, $result, allowRetryButtons: false, submittedOrderId: $orderId);
    }

    public function cancelPrompt(Customer $customer): void
    {
        $this->session->forget($customer);
    }

    public function respondToResult(Customer $customer, string $chatId, Order $order, Payment $payment, BinanceVerificationResult $result, bool $allowRetryButtons, ?string $submittedOrderId = null): void
    {
        $amount = $this->formatUsdt((int) $payment->amount);

        switch ($result->outcome) {
            case BinanceVerificationOutcome::Confirmed:
                $this->session->forget($customer);
                $this->bot->sendMessage($chatId, $this->text(
                    $customer,
                    "✅ Pembayaran terverifikasi!\n━━━━━━━━━━━━━━\n🧾 Invoice: {$order->invoice_number}\n💵 {$amount} USDT diterima.\n\nProduk kamu sedang dikirim ke chat ini sekarang. Terima kasih! 🎉",
                    "✅ Payment confirmed!\n━━━━━━━━━━━━━━\n🧾 Invoice: {$order->invoice_number}\n💵 {$amount} USDT received.\n\nYour product is being delivered to this chat right now. Thank you! 🎉",
                ));
                break;

            case BinanceVerificationOutcome::AlreadyPaid:
                $this->bot->sendMessage($chatId, $this->text(
                    $customer,
                    "Pesanan {$order->invoice_number} sudah lunas. Cek /status kalau produk belum sampai ya.",
                    "Order {$order->invoice_number} is already paid. Check /status if the product has not arrived yet.",
                ));
                break;

            case BinanceVerificationOutcome::NotFound:
                $this->bot->sendMessage($chatId, $this->text(
                    $customer,
                    "🔎 Order ID sudah diterima\n━━━━━━━━━━━━━━\n🧾 Invoice: {$order->invoice_number}\n\nPembayaran belum terlihat di riwayat Binance. Sistem akan mengecek ulang otomatis setiap 30 detik selama masa verifikasi. Kamu tidak perlu mengirim ulang ID yang sama.",
                    "🔎 Order ID received\n━━━━━━━━━━━━━━\n🧾 Invoice: {$order->invoice_number}\n\nThe payment is not visible in Binance history yet. We will check automatically every 30 seconds during the verification window. You do not need to resend the same ID.",
                ), $allowRetryButtons ? [
                    'inline_keyboard' => [
                        [[
                            'text' => $this->text($customer, '🧾 Ganti Order ID', '🧾 Change Order ID'),
                            'callback_data' => 'binance:orderid:'.$order->id,
                        ]],
                    ],
                ] : []);
                break;

            case BinanceVerificationOutcome::Mismatch:
            case BinanceVerificationOutcome::TransactionAlreadyUsed:
            case BinanceVerificationOutcome::OrderNotPending:
                $this->escalator->notify($order, $payment, $result, $submittedOrderId);
                $this->bot->sendMessage($chatId, $this->text(
                    $customer,
                    "⚠️ Pembayaran kamu butuh pengecekan manual\n━━━━━━━━━━━━━━\n🧾 Invoice: {$order->invoice_number}\n\nDetail pembayaran tidak sepenuhnya cocok dengan pesanan ini, jadi admin sudah kami beri tahu dan akan menyelesaikannya segera. Kamu tidak perlu melakukan apa-apa lagi.",
                    "⚠️ Your payment needs a manual check\n━━━━━━━━━━━━━━\n🧾 Invoice: {$order->invoice_number}\n\nThe payment details do not fully match this order, so the store admin has been notified and will resolve it shortly. No further action is needed from you.",
                ));
                break;

            case BinanceVerificationOutcome::NotConfigured:
                $this->escalator->notify($order, $payment, $result, $submittedOrderId);
                $this->bot->sendMessage($chatId, $this->text(
                    $customer,
                    'Verifikasi otomatis sedang tidak tersedia. Admin sudah diberi tahu — kirim screenshot pembayaran dengan caption /payment sebagai cadangan ya.',
                    'Automatic verification is currently unavailable. The admin has been notified — as a backup, please send your payment screenshot with the caption /payment.',
                ));
                break;

            case BinanceVerificationOutcome::ProviderError:
                $this->bot->sendMessage($chatId, $this->text(
                    $customer,
                    'Verifikasi Binance sedang gangguan sebentar. Order ID kamu sudah tersimpan dan sistem akan mencoba lagi otomatis — pembayaran kamu aman.',
                    'Binance verification is temporarily unavailable. Your Order ID is saved and the system will retry automatically — your payment is safe.',
                ));
                break;

            case BinanceVerificationOutcome::StaleSubmission:
                break;
        }
    }

    /** @return array{0: ?Order, 1: ?Payment} */
    private function findVerifiableOrder(Customer $customer, string $orderId): array
    {
        $order = $customer->orders()->with('payments')->whereKey($orderId)->first();

        $payment = $order?->payments
            ->first(fn (Payment $payment): bool => $payment->provider === 'manual' && $payment->method === 'BINANCE_PAY_ID');

        return [$order, $payment];
    }

    private function guardMessage(Customer $customer, Order $order, Payment $payment): ?string
    {
        if ($payment->status === PaymentStatus::Paid) {
            return $this->text(
                $customer,
                "Pesanan {$order->invoice_number} sudah lunas. Cek /status kalau produk belum sampai ya.",
                "Order {$order->invoice_number} is already paid. Check /status if the product has not arrived yet.",
            );
        }

        if ($order->order_status !== OrderStatus::PendingPayment || $payment->status !== PaymentStatus::Pending) {
            return $this->text(
                $customer,
                "Pesanan {$order->invoice_number} sudah tidak menunggu pembayaran. Kalau kamu terlanjur transfer, hubungi admin dengan invoice ini ya.",
                "Order {$order->invoice_number} is no longer awaiting payment. If you already sent a transfer, please contact the admin with this invoice.",
            );
        }

        return null;
    }

    /** @return array{status: 'accepted'|'limit'|'expired'|'manual', payment?: Payment} */
    private function persistOrderId(string $orderId, string $candidate): array
    {
        return DB::transaction(function () use ($orderId, $candidate): array {
            $lockedOrder = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $payment = $lockedOrder->payments()
                ->where('provider', 'manual')
                ->where('method', 'BINANCE_PAY_ID')
                ->where('status', PaymentStatus::Pending)
                ->lockForUpdate()
                ->first();

            if (! $payment) {
                return ['status' => 'expired'];
            }

            if (! $payment->binance_auto_verification_enabled) {
                return ['status' => 'manual'];
            }

            $deadline = $payment->expired_at ?? $lockedOrder->expires_at;
            if ($deadline !== null && ! $deadline->isFuture()) {
                return ['status' => 'expired'];
            }

            if ((int) $payment->binance_order_id_attempts >= self::MAX_ORDER_ID_ATTEMPTS) {
                return ['status' => 'limit'];
            }

            $graceUntil = $payment->binance_verification_grace_until;
            if ($graceUntil === null && $deadline !== null) {
                $graceUntil = $deadline->copy()->addMinutes(self::VERIFICATION_GRACE_MINUTES);
            }

            $payment->update([
                'binance_order_id' => $candidate,
                'binance_order_id_attempts' => (int) $payment->binance_order_id_attempts + 1,
                'binance_order_id_submitted_at' => now(),
                'binance_verification_grace_until' => $graceUntil,
            ]);

            return ['status' => 'accepted', 'payment' => $payment->fresh()];
        }, 5);
    }

    private function scheduleRetry(Payment $payment, string $orderId, int $attemptNumber): void
    {
        VerifyBinanceOrderId::dispatch($payment->id, $orderId, $attemptNumber)
            ->delay(now()->addSeconds(self::RETRY_DELAY_SECONDS))
            ->onQueue('default');
    }

    private function retryEligible(Payment $payment, Order $order, string $orderId, int $attemptNumber): bool
    {
        return $payment->status === PaymentStatus::Pending
            && $order->order_status === OrderStatus::PendingPayment
            && $payment->provider === 'manual'
            && $payment->method === 'BINANCE_PAY_ID'
            && $payment->binance_auto_verification_enabled
            && $payment->binance_order_id === $orderId
            && (int) $payment->binance_order_id_attempts === $attemptNumber
            && $payment->binance_verification_grace_until?->isFuture();
    }

    private function formatUsdt(int $amountMicro): string
    {
        return rtrim(rtrim(number_format($amountMicro / 1_000_000, 6, '.', ''), '0'), '.');
    }

    private function text(Customer $customer, string $id, string $en): string
    {
        return $this->locale->text($customer, $id, $en);
    }
}
