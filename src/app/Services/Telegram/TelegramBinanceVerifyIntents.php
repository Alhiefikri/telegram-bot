<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\VerifyBinanceAmountUnique;
use App\Jobs\VerifyBinanceOrderId;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Binance\BinanceAutoVerification;
use App\Services\Binance\BinanceOrderId;
use App\Services\Binance\BinanceVerificationOutcome;
use App\Services\Binance\BinanceVerificationResult;
use App\Services\Binance\BinanceVerificationSubmissionService;
use Throwable;

class TelegramBinanceVerifyIntents
{
    private const MAX_ORDER_ID_ATTEMPTS = 3;

    private const RETRY_DELAY_SECONDS = 30;

    public function __construct(
        private TelegramBotClient $bot,
        private TelegramLocale $locale,
        private TelegramBinanceVerifySession $session,
        private BinanceAutoVerification $verification,
        private BinanceVerificationSubmissionService $submissions,
        private TelegramBinanceVerificationEscalator $escalator,
    ) {}

    public function handleVerifyTap(Customer $customer, string $chatId, string $callbackQueryId, string $orderId): void
    {
        [, $payment] = $this->findVerifiableOrder($customer, $orderId);

        if ($payment?->binance_verification_mode === 'amount_unique') {
            $this->handleAmountUniqueTap($customer, $chatId, $callbackQueryId, $orderId);

            return;
        }

        $this->handleOrderIdPromptTap($customer, $chatId, $callbackQueryId, $orderId);
    }

    public function handleAmountUniqueTap(Customer $customer, string $chatId, string $callbackQueryId, string $orderId): void
    {
        [$order, $payment] = $this->findVerifiableOrder($customer, $orderId);

        if (! $order || ! $payment) {
            $this->bot->answerCallbackQuery($callbackQueryId, $this->text($customer, 'Pesanan tidak ditemukan.', 'Order not found.'));

            return;
        }

        if ($guardMessage = $this->amountUniqueGuardMessage($customer, $order, $payment)) {
            $this->bot->answerCallbackQuery($callbackQueryId);
            $this->bot->sendMessage($chatId, $guardMessage);

            return;
        }

        try {
            VerifyBinanceAmountUnique::dispatch($payment->id)
                ->onQueue((string) config('services.binance.verification_queue', 'binance-verification'));
        } catch (Throwable $exception) {
            report($exception);
            $this->bot->answerCallbackQuery($callbackQueryId);
            $this->respondToResult(
                $customer,
                $chatId,
                $order,
                $payment->fresh(),
                BinanceVerificationResult::of(BinanceVerificationOutcome::ProviderError, retryable: false),
                allowRetryButtons: false,
            );

            return;
        }

        $this->bot->answerCallbackQuery($callbackQueryId, $this->text(
            $customer,
            'Permintaan cek diterima. Pembayaran sedang diperiksa.',
            'Check requested. Your payment is being verified.',
        ));
        $this->bot->sendMessage($chatId, $this->text(
            $customer,
            "🔎 Sedang mengecek pembayaran\n━━━━━━━━━━━━━━\n🧾 Invoice: {$order->invoice_number}\n\nSistem sedang memeriksa nominal persis dan penerima Binance. Kamu akan diberi kabar jika pembayaran cocok.",
            "🔎 Checking your payment\n━━━━━━━━━━━━━━\n🧾 Invoice: {$order->invoice_number}\n\nWe are checking the exact amount and Binance receiver. You will be notified if the payment matches.",
        ));
    }

    public function verifyAmountUnique(string $paymentId): void
    {
        $payment = Payment::query()->with('order.customer')->find($paymentId);
        $order = $payment?->order;
        $customer = $order?->customer;

        if (! $payment || ! $order || ! $customer
            || $payment->status !== PaymentStatus::Pending
            || $order->order_status !== OrderStatus::PendingPayment
            || $payment->binance_verification_mode !== 'amount_unique') {
            return;
        }

        $result = $this->verification->verifyPayment($payment);
        $this->recordAmountUniqueVerificationStatus($payment, $result->outcome);
        $payment->refresh();

        $this->respondToResult(
            $customer,
            (string) $customer->telegram_id,
            $order,
            $payment,
            $result,
            allowRetryButtons: true,
        );
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
            "🧾 Kirim Binance Order ID\n━━━━━━━━━━━━━━\nBalas chat ini dengan Order ID dari bukti transfer kamu (10–128 karakter ASCII, tanpa spasi). ID ini wajib supaya pembayaran bisa diverifikasi otomatis.\n\nCara menemukannya:\nBuka aplikasi Binance → Pay → pilih transfer kamu → Order Details → salin Order ID.",
            'Please send the Binance Pay Order ID for this payment. Open the completed payment in Binance Pay, view Order Details, and copy the Order ID exactly. Do not send your Binance Pay ID or a screenshot here.',
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

        $candidate = BinanceOrderId::normalize($text);
        if ($candidate === null) {
            $this->bot->sendMessage($chatId, $this->text(
                $customer,
                'Kirim Binance Order ID persis seperti di Order Details (10–128 karakter ASCII, tanpa spasi). Coba kirim ulang ya, atau ketik /batal untuk berhenti.',
                'Please send the Binance Order ID exactly as shown in Order Details (10–128 ASCII characters, no spaces). Try again, or type /batal to stop.',
            ));

            return true;
        }

        if (! $this->session->withinAttemptLimit($order->id)) {
            $this->bot->sendMessage($chatId, $this->text($customer, 'Terlalu banyak percobaan. Tunggu beberapa menit lalu coba lagi ya.', 'Too many attempts. Please wait a few minutes and try again.'));

            return true;
        }

        $submission = $this->submissions->submit($order->id, $candidate);
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

        if ($submission['status'] === 'grace_expired') {
            $this->session->forget($customer);
            $this->bot->sendMessage($chatId, $this->text(
                $customer,
                "Verifikasi otomatis untuk order {$order->invoice_number} sudah ditutup setelah pemeriksaan sebelumnya. Admin sudah diberi tahu; kirim screenshot pembayaran dengan caption /payment {$order->invoice_number} bila perlu.",
                "Automatic verification for order {$order->invoice_number} has closed after the previous check. The admin has been notified; send a payment screenshot with the caption /payment {$order->invoice_number} if needed.",
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
        if ($submission['dispatch_failed'] ?? false) {
            $this->respondToResult(
                $customer,
                $chatId,
                $order,
                $payment->fresh(),
                BinanceVerificationResult::of(BinanceVerificationOutcome::ProviderError, retryable: true),
                allowRetryButtons: false,
                submittedOrderId: $candidate,
            );

            return true;
        }

        $this->respondIfStillChecking($customer, $chatId, $order, $payment, $candidate);

        return true;
    }

    public function retryOrderId(string $paymentId, string $orderId, int $attemptNumber, int $retryNumber, int $submissionVersion = 0): void
    {
        $payment = Payment::query()->with('order.customer')->find($paymentId);
        $order = $payment?->order;
        $customer = $order?->customer;

        $storedOrderId = trim((string) ($payment?->binance_order_id ?? ''));
        if ($orderId !== '' && $storedOrderId !== $orderId) {
            return;
        }
        $orderId = $storedOrderId;

        if (! $payment || ! $order || ! $customer) {
            return;
        }

        if (! $this->retryEligible($payment, $order, $orderId, $attemptNumber, $submissionVersion)) {
            $this->notifyRetryClosedWindow($payment, $order, $customer, $orderId, $attemptNumber, $submissionVersion);

            return;
        }

        $result = $this->verification->verifyPaymentByProviderOrderId($payment, $orderId, $submissionVersion);
        $this->recordVerificationStatus($payment, $orderId, $submissionVersion, $result->outcome);

        if (in_array($result->outcome, [BinanceVerificationOutcome::NotConfigured, BinanceVerificationOutcome::OrderNotPending], true)
            || ($result->outcome === BinanceVerificationOutcome::ProviderError && ! $result->retryable)) {
            Payment::query()
                ->whereKey($payment->id)
                ->where('binance_order_id', $orderId)
                ->where('binance_order_id_submission_version', $submissionVersion)
                ->update([
                    'binance_verification_grace_until' => now(),
                    'binance_verification_status' => 'provider_error',
                    'binance_last_verified_at' => now(),
                ]);
            $payment->refresh();
        }

        $terminalRetry = false;
        if ((in_array($result->outcome, [BinanceVerificationOutcome::NotFound, BinanceVerificationOutcome::IncompleteWindow], true)
            || ($result->outcome === BinanceVerificationOutcome::ProviderError && $result->retryable))) {
            $delaySeconds = self::RETRY_DELAY_SECONDS * (2 ** max(0, $retryNumber - 1)) + random_int(0, 10);
            $remainingGraceSeconds = $payment->binance_verification_grace_until === null
                ? 0
                : max(0, $payment->binance_verification_grace_until->getTimestamp() - now()->getTimestamp());
            if ($payment->binance_verification_grace_until?->isFuture()
                && $retryNumber < 3
                && $delaySeconds + 5 <= $remainingGraceSeconds) {
                VerifyBinanceOrderId::dispatch($payment->id, $orderId, $attemptNumber, $retryNumber + 1, $submissionVersion)
                    ->delay(now()->addSeconds($delaySeconds))
                    ->onQueue((string) config('services.binance.verification_queue', 'binance-verification'));

                return;
            }

            // The final queued attempt must still tell the buyer what to do
            // next. Previously async workers returned here, leaving the
            // storefront/Telegram status stuck on checking forever.
            $terminalRetry = true;
            $terminalStatus = $result->outcome === BinanceVerificationOutcome::NotFound
                ? 'not_found'
                : 'provider_error';
            Payment::query()
                ->whereKey($payment->id)
                ->where('binance_order_id', $orderId)
                ->where('binance_order_id_submission_version', $submissionVersion)
                ->update([
                    'binance_verification_grace_until' => now(),
                    'binance_verification_status' => $terminalStatus,
                    'binance_last_verified_at' => now(),
                ]);
            $payment->refresh();
        }

        if ($result->outcome === BinanceVerificationOutcome::OrderNotPending) {
            $payment->refresh();
            $order->refresh();
            if (! $this->retryEligible($payment, $order, $orderId, $attemptNumber, $submissionVersion)) {
                return;
            }
        }

        $this->session->forget($customer);
        $this->respondToResult($customer, (string) $customer->telegram_id, $order, $payment, $result, allowRetryButtons: false, submittedOrderId: $orderId, finalRetry: $terminalRetry);
    }

    public function cancelPrompt(Customer $customer): void
    {
        $this->session->forget($customer);
    }

    public function respondToResult(Customer $customer, string $chatId, Order $order, Payment $payment, BinanceVerificationResult $result, bool $allowRetryButtons, ?string $submittedOrderId = null, bool $finalRetry = false): void
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
                if ($payment->binance_verification_mode === 'amount_unique') {
                    $this->bot->sendMessage($chatId, $this->text(
                        $customer,
                        "🔎 Pembayaran belum ditemukan\n━━━━━━━━━━━━━━\n🧾 Invoice: {$order->invoice_number}\n\nTransfer dengan nominal persis {$amount} USDT belum terlihat di riwayat Binance. Setelah transfer selesai, tekan tombol Saya Sudah Bayar untuk mengecek lagi.",
                        "🔎 Payment not found yet\n━━━━━━━━━━━━━━\n🧾 Invoice: {$order->invoice_number}\n\nA transfer for the exact amount of {$amount} USDT is not visible in Binance history yet. After completing the transfer, tap I've Paid to check again.",
                    ), $allowRetryButtons ? [
                        'inline_keyboard' => [[
                            [
                                'text' => $this->text($customer, '✅ Saya Sudah Bayar', "✅ I've Paid"),
                                'callback_data' => 'binance:verify:'.$order->id,
                            ],
                        ]],
                    ] : []);

                    break;
                }

                if ($finalRetry) {
                    $this->escalator->notify($order, $payment, $result, $submittedOrderId);
                    $this->bot->sendMessage($chatId, $this->text(
                        $customer,
                        "⏳ Order ID sudah diterima, tetapi pembayaran belum terlihat setelah beberapa pemeriksaan otomatis. Admin sudah diberi tahu; kirim screenshot pembayaran dengan caption /payment {$order->invoice_number} agar bisa dicek manual.",
                        "⏳ Order ID received, but the payment was not visible after several automatic checks. The admin has been notified; send a payment screenshot with the caption /payment {$order->invoice_number} for a manual review.",
                    ));

                    break;
                }

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

            case BinanceVerificationOutcome::IncompleteWindow:
                $this->bot->sendMessage($chatId, $this->text(
                    $customer,
                    $payment->binance_verification_mode === 'amount_unique'
                        ? 'Riwayat Binance belum selesai diperiksa. Tekan Saya Sudah Bayar lagi setelah beberapa saat.'
                        : 'Order ID kamu sudah diterima. Sistem sedang memeriksa pembayaran dengan aman dan akan memberi kabar setelah hasilnya tersedia.',
                    $payment->binance_verification_mode === 'amount_unique'
                        ? "Binance history could not be checked completely yet. Tap I've Paid again in a moment."
                        : 'Your Order ID was received. We are checking your payment securely and will update you when the result is available.',
                ));
                break;

            case BinanceVerificationOutcome::ProviderError:
                if ($result->retryable) {
                    if ($finalRetry) {
                        $this->escalator->notify($order, $payment, $result, $submittedOrderId);
                        $this->bot->sendMessage($chatId, $this->text(
                            $customer,
                            "Verifikasi otomatis belum berhasil diselesaikan. Admin sudah diberi tahu; kirim screenshot pembayaran dengan caption /payment {$order->invoice_number} sebagai cadangan.",
                            "Automatic verification could not be completed. The admin has been notified; send a payment screenshot with the caption /payment {$order->invoice_number} as a backup.",
                        ));

                        break;
                    }

                    $this->bot->sendMessage($chatId, $this->text(
                        $customer,
                        $payment->binance_verification_mode === 'amount_unique'
                            ? 'Verifikasi belum selesai. Tekan Saya Sudah Bayar lagi setelah beberapa saat.'
                            : 'Order ID kamu sudah diterima. Sistem sedang memeriksa pembayaran dengan aman dan akan memberi kabar setelah hasilnya tersedia.',
                        $payment->binance_verification_mode === 'amount_unique'
                            ? "The payment check could not finish. Tap I've Paid again in a moment."
                            : 'Your Order ID was received. We are checking your payment securely and will update you when the result is available.',
                    ));
                    break;
                }

                $this->escalator->notify($order, $payment, $result, $submittedOrderId);
                $this->bot->sendMessage($chatId, $this->text(
                    $customer,
                    'Verifikasi Binance tidak dapat dilanjutkan saat ini. Admin sudah diberi tahu — kirim screenshot pembayaran dengan caption /payment sebagai cadangan ya.',
                    'Automatic Binance verification could not continue. The admin has been notified — please send your payment screenshot with the caption /payment as a backup.',
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

    private function amountUniqueGuardMessage(Customer $customer, Order $order, Payment $payment): ?string
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

        if ($payment->binance_verification_mode !== 'amount_unique'
            || ! $payment->binance_auto_verification_enabled) {
            return $this->text(
                $customer,
                "Verifikasi otomatis tidak tersedia untuk order {$order->invoice_number}. Kirim screenshot pembayaran sebagai foto dengan caption /payment {$order->invoice_number} agar admin bisa mengeceknya.",
                "Automatic verification is not available for order {$order->invoice_number}. Send a payment screenshot as a photo with the caption /payment {$order->invoice_number} so the admin can review it.",
            );
        }

        if ($payment->binance_verification_grace_until?->isPast()) {
            return $this->text(
                $customer,
                "Verifikasi otomatis untuk order {$order->invoice_number} sudah ditutup setelah pemeriksaan sebelumnya. Admin sudah diberi tahu; kirim screenshot pembayaran dengan caption /payment {$order->invoice_number} bila perlu.",
                "Automatic verification for order {$order->invoice_number} has closed after the previous check. The admin has been notified; send a payment screenshot with the caption /payment {$order->invoice_number} if needed.",
            );
        }

        return null;
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

        if ($payment->binance_verification_mode !== 'order_id') {
            return $this->text(
                $customer,
                "Verifikasi Order ID belum tersedia untuk order {$order->invoice_number}. Kirim screenshot pembayaran sebagai foto dengan caption /payment {$order->invoice_number} agar admin bisa mengeceknya.",
                "Order ID verification is not available for order {$order->invoice_number}. Send a payment screenshot as a photo with the caption /payment {$order->invoice_number} so the admin can review it.",
            );
        }

        if ($payment->binance_verification_grace_until?->isPast()) {
            return $this->text(
                $customer,
                "Verifikasi otomatis untuk order {$order->invoice_number} sudah ditutup setelah pemeriksaan sebelumnya. Admin sudah diberi tahu; kirim screenshot pembayaran dengan caption /payment {$order->invoice_number} bila perlu.",
                "Automatic verification for order {$order->invoice_number} has closed after the previous check. The admin has been notified; send a payment screenshot with the caption /payment {$order->invoice_number} if needed.",
            );
        }

        return null;
    }

    private function retryEligible(Payment $payment, Order $order, string $orderId, int $attemptNumber, int $submissionVersion = 0): bool
    {
        return $payment->status === PaymentStatus::Pending
            && $order->order_status === OrderStatus::PendingPayment
            && $payment->provider === 'manual'
            && $payment->method === 'BINANCE_PAY_ID'
            && $payment->binance_auto_verification_enabled
            && $payment->binance_verification_mode === 'order_id'
            && $payment->binance_order_id === $orderId
            && (int) $payment->binance_order_id_attempts === $attemptNumber
            && $submissionVersion > 0
            && (int) $payment->binance_order_id_submission_version === $submissionVersion
            && $payment->binance_verification_grace_until?->isFuture();
    }

    private function notifyRetryClosedWindow(Payment $payment, Order $order, Customer $customer, string $orderId, int $attemptNumber, int $submissionVersion): void
    {
        if ($payment->status !== PaymentStatus::Pending
            || $order->order_status !== OrderStatus::PendingPayment
            || $payment->provider !== 'manual'
            || $payment->method !== 'BINANCE_PAY_ID'
            || ! $payment->binance_auto_verification_enabled
            || $payment->binance_verification_mode !== 'order_id'
            || $payment->binance_order_id !== $orderId
            || (int) $payment->binance_order_id_attempts !== $attemptNumber
            || (int) $payment->binance_order_id_submission_version !== $submissionVersion
            || $payment->binance_verification_grace_until === null
            || $payment->binance_verification_grace_until->isFuture()
            || ! in_array($payment->binance_verification_status, ['checking', 'not_found', 'provider_error'], true)) {
            return;
        }

        $outcome = $payment->binance_verification_status === 'not_found'
            ? BinanceVerificationOutcome::NotFound
            : BinanceVerificationOutcome::ProviderError;
        Payment::query()
            ->whereKey($payment->id)
            ->where('binance_order_id_submission_version', $submissionVersion)
            ->update([
                'binance_verification_status' => $outcome === BinanceVerificationOutcome::NotFound ? 'not_found' : 'provider_error',
                'binance_verification_grace_until' => now(),
                'binance_last_verified_at' => now(),
            ]);
        $payment->refresh();

        $this->respondToResult(
            $customer,
            (string) $customer->telegram_id,
            $order,
            $payment,
            BinanceVerificationResult::of($outcome, retryable: false),
            allowRetryButtons: false,
            submittedOrderId: $orderId,
            finalRetry: $outcome === BinanceVerificationOutcome::NotFound,
        );
    }

    private function respondIfStillChecking(Customer $customer, string $chatId, Order $order, Payment $payment, string $candidate): void
    {
        $payment->refresh();
        if ($payment->status === PaymentStatus::Paid) {
            return;
        }

        if (in_array($payment->binance_verification_status, ['checking', 'not_found', 'provider_error'], true)
            && $payment->binance_verification_grace_until?->isFuture()) {
            $this->respondToResult(
                $customer,
                $chatId,
                $order,
                $payment,
                BinanceVerificationResult::of(BinanceVerificationOutcome::ProviderError, retryable: true),
                allowRetryButtons: false,
                submittedOrderId: $candidate,
            );

            return;
        }

        if ($payment->binance_verification_status !== 'checking') {
            return;
        }

        $this->respondToResult(
            $customer,
            $chatId,
            $order,
            $payment,
            BinanceVerificationResult::of(BinanceVerificationOutcome::ProviderError),
            allowRetryButtons: false,
            submittedOrderId: $candidate,
        );
    }

    private function recordAmountUniqueVerificationStatus(Payment $payment, BinanceVerificationOutcome $outcome): void
    {
        $status = match ($outcome) {
            BinanceVerificationOutcome::Confirmed,
            BinanceVerificationOutcome::AlreadyPaid => 'confirmed',
            BinanceVerificationOutcome::NotFound => 'not_found',
            BinanceVerificationOutcome::IncompleteWindow => 'checking',
            BinanceVerificationOutcome::ProviderError => 'provider_error',
            BinanceVerificationOutcome::Mismatch,
            BinanceVerificationOutcome::TransactionAlreadyUsed => 'mismatch',
            default => 'checking',
        };

        Payment::query()
            ->whereKey($payment->id)
            ->update([
                'binance_verification_status' => $status,
                'binance_last_verified_at' => now(),
            ]);
    }

    private function recordVerificationStatus(Payment $payment, string $orderId, int $submissionVersion, BinanceVerificationOutcome $outcome): void
    {
        $status = match ($outcome) {
            BinanceVerificationOutcome::Confirmed => 'confirmed',
            BinanceVerificationOutcome::NotFound => 'not_found',
            BinanceVerificationOutcome::IncompleteWindow => 'checking',
            BinanceVerificationOutcome::ProviderError => 'provider_error',
            BinanceVerificationOutcome::Mismatch => 'mismatch',
            default => 'checking',
        };

        Payment::query()
            ->whereKey($payment->id)
            ->where('binance_order_id', $orderId)
            ->when($submissionVersion > 0, fn ($query) => $query->where('binance_order_id_submission_version', $submissionVersion))
            ->update([
                'binance_verification_status' => $status,
                'binance_last_verified_at' => now(),
            ]);
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
