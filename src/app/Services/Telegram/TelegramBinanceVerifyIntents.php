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

class TelegramBinanceVerifyIntents
{
    public function __construct(
        private TelegramBotClient $bot,
        private TelegramLocale $locale,
        private TelegramBinanceVerifySession $session,
        private BinanceAutoVerification $verification,
        private TelegramBinanceVerificationEscalator $escalator,
    ) {}

    public function handleVerifyTap(Customer $customer, string $chatId, string $callbackQueryId, string $orderId): void
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

        if (! $this->session->withinAttemptLimit($order->id)) {
            $this->bot->answerCallbackQuery($callbackQueryId, $this->text($customer, 'Terlalu banyak percobaan. Tunggu beberapa menit lalu coba lagi ya.', 'Too many attempts. Please wait a few minutes and try again.'));

            return;
        }

        $this->bot->answerCallbackQuery($callbackQueryId, $this->text($customer, '🔎 Memeriksa pembayaran kamu…', '🔎 Checking your payment…'));

        $result = $this->verification->verifyPayment($payment);
        $this->respondToResult($customer, $chatId, $order, $payment, $result, allowRetryButtons: true);
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

        $this->session->promptOrder($customer, $order->id);
        $this->bot->answerCallbackQuery($callbackQueryId);
        $this->bot->sendMessage($chatId, $this->text(
            $customer,
            "🧾 Kirim Binance Order ID\n━━━━━━━━━━━━━━\nBalas chat ini dengan Order ID dari bukti transfer kamu (hanya angka).\n\nCara menemukannya:\nBuka aplikasi Binance → Pay → pilih transfer kamu → Order Details → salin Order ID.",
            "🧾 Send your Binance Order ID\n━━━━━━━━━━━━━━\nReply to this chat with the Order ID from your payment receipt (digits only).\n\nWhere to find it:\nOpen the Binance app → Pay → select your transfer → Order Details → copy the Order ID.",
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

        $result = $this->verification->verifyPaymentByProviderOrderId($payment, $candidate);

        if ($result->outcome === BinanceVerificationOutcome::NotFound) {
            $this->bot->sendMessage($chatId, $this->text(
                $customer,
                "❓ Order ID itu belum ketemu di pembayaran masuk kami.\n\nPastikan transfer kamu sudah sukses di Binance dan Order ID disalin lengkap, lalu kirim ulang. Kalau tetap gagal, hubungi admin dengan invoice {$order->invoice_number}.",
                "❓ We could not find that Order ID among our incoming payments.\n\nPlease make sure the transfer succeeded in Binance and the Order ID was copied completely, then send it again. If it keeps failing, contact the admin with invoice {$order->invoice_number}.",
            ));

            return true;
        }

        if ($result->confirmed() || $result->outcome !== BinanceVerificationOutcome::ProviderError) {
            $this->session->forget($customer);
        }

        $this->respondToResult($customer, $chatId, $order, $payment, $result, allowRetryButtons: false, submittedOrderId: $candidate);

        return true;
    }

    public function cancelPrompt(Customer $customer): void
    {
        $this->session->forget($customer);
    }

    private function respondToResult(Customer $customer, string $chatId, Order $order, Payment $payment, BinanceVerificationResult $result, bool $allowRetryButtons, ?string $submittedOrderId = null): void
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
                    "🔎 Pembayaran belum terdeteksi\n━━━━━━━━━━━━━━\n🧾 Invoice: {$order->invoice_number}\n\nTransfer biasanya muncul dalam satu menit. Pastikan kamu mengirim TEPAT {$amount} USDT (jangan dibulatkan), lalu coba lagi.\n\nKalau masih belum ketemu, kirim Binance Order ID dari bukti transfermu sebagai cadangan.",
                    "🔎 Payment not detected yet\n━━━━━━━━━━━━━━\n🧾 Invoice: {$order->invoice_number}\n\nTransfers usually appear within a minute. Make sure you sent EXACTLY {$amount} USDT (do not round it), then try again.\n\nIf it still cannot be found, submit the Binance Order ID from your payment receipt as a backup.",
                ), $allowRetryButtons ? [
                    'inline_keyboard' => [
                        [[
                            'text' => $this->text($customer, '🔁 Cek Lagi', '🔁 Check Again'),
                            'callback_data' => 'binance:verify:'.$order->id,
                        ]],
                        [[
                            'text' => $this->text($customer, '🧾 Kirim Binance Order ID', '🧾 Submit Binance Order ID'),
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
                    'Verifikasi sedang gangguan sebentar. Coba lagi satu menit lagi ya — pembayaran kamu aman.',
                    'Verification is temporarily unavailable. Please try again in a minute — your payment is safe.',
                ));
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

    private function formatUsdt(int $amountMicro): string
    {
        return rtrim(rtrim(number_format($amountMicro / 1_000_000, 6, '.', ''), '0'), '.');
    }

    private function text(Customer $customer, string $id, string $en): string
    {
        return $this->locale->text($customer, $id, $en);
    }
}
