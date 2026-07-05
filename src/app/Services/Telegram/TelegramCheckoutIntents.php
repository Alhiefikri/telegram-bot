<?php

namespace App\Services\Telegram;

use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Pakasir\PakasirNativeQrisService;
use App\Services\Pakasir\PakasirProviderAdapter;
use App\Services\Pakasir\PakasirProviderReadiness;
use App\Services\Pakasir\PakasirQrisTransaction;
use App\Support\DogfoodTiming;
use Throwable;

class TelegramCheckoutIntents
{
    public function __construct(
        private TelegramBotClient $bot,
        private PakasirProviderAdapter $pakasir,
        private PakasirNativeQrisService $nativeQris,
        private PakasirProviderReadiness $pakasirReadiness,
        private TelegramPaymentInstructionRenderer $paymentInstructions,
    ) {}

    public function sendPaymentInstructions(string $chatId, Order $order): void
    {
        $instruction = $this->paymentInstructions->manual(
            $order,
            $this->manualPaymentOption(),
            ltrim((string) (config('services.telegram.admin_username') ?: 'Alhie02'), '@'),
        );

        $this->sendPaymentInstruction($chatId, $instruction, [
            'step' => 'manual_customer_send_photo',
            'payment_mode' => 'manual',
            'order_correlation_id' => DogfoodTiming::correlation($order->id),
            'qr_image_bytes' => strlen($instruction->photoContents),
        ]);
    }

    public function sendPakasirPaymentInstructions(string $chatId, Order $order, Payment $payment): void
    {
        if ($this->pakasirCheckoutFlow() === 'api_qris') {
            try {
                $transaction = $this->nativeQris->ensureCreated($payment);
            } catch (Throwable $e) {
                report($e);
                $this->sendHostedPakasirPaymentInstructions($chatId, $order, $payment, fallback: true);

                return;
            }

            $payment->refresh();
            $this->sendNativePakasirPaymentInstructions($chatId, $order, $payment, $transaction);

            return;
        }

        $this->sendHostedPakasirPaymentInstructions($chatId, $order, $payment);
    }

    private function sendHostedPakasirPaymentInstructions(string $chatId, Order $order, Payment $payment, bool $fallback = false): void
    {
        $this->sendPaymentInstruction(
            $chatId,
            $this->paymentInstructions->hostedPakasir(
                $order,
                $payment,
                $this->pakasir->hostedPaymentUrl($payment),
                $fallback,
            ),
        );
    }

    private function sendNativePakasirPaymentInstructions(string $chatId, Order $order, Payment $payment, PakasirQrisTransaction $transaction): void
    {
        $this->sendPaymentInstruction(
            $chatId,
            $this->paymentInstructions->nativePakasir(
                $order,
                $payment,
                $transaction,
                $this->pakasir->hostedPaymentUrl($payment),
            ),
        );
    }

    private function sendPaymentInstruction(string $chatId, TelegramPaymentInstruction $instruction, array $timingContext = []): void
    {
        $send = fn () => $this->bot->sendPhoto(
            $chatId,
            $instruction->photoContents,
            $instruction->filename,
            $instruction->caption(),
            $instruction->replyMarkup,
        );

        if ($timingContext === []) {
            $send();

            return;
        }

        DogfoodTiming::time('dogfood.telegram.checkout_step', $timingContext, $send);
    }

    private function pakasirCheckoutFlow(): string
    {
        return $this->pakasirReadiness->checkout()->checkoutFlow();
    }

    public function notifyAdminAboutManualOrder(Order $order): void
    {
        $settings = ManualPaymentSetting::current();
        $adminTelegramChatId = $settings?->admin_telegram_chat_id ?: config('services.telegram.admin_telegram_chat_id');
        if (! $adminTelegramChatId) {
            return;
        }

        $order->loadMissing(['customer', 'orderItems']);
        $method = $this->manualPaymentOption();
        $lines = [
            '🧾 Checkout manual baru',
            '━━━━━━━━━━━━━━',
            'Invoice: '.$order->invoice_number,
            'Customer: @'.($order->customer?->username ?: '-').' ('.$order->customer?->telegram_id.')',
            'Metode: '.$method['label'],
            'Total: Rp '.number_format($order->total_amount, 0, ',', '.'),
            '',
            'Item:',
        ];
        foreach ($order->orderItems as $item) {
            $lines[] = '- '.$item->product_name.' x '.$item->quantity;
        }
        $lines[] = 'Tunggu bukti pembayaran dengan caption /payment.';
        $lines[] = 'Approve hanya jika pembayaran QR manual masuk, nama QR LHIE STORE benar, dan nominal sama persis.';

        DogfoodTiming::time('dogfood.telegram.checkout_step', [
            'step' => 'manual_admin_send_message',
            'payment_mode' => 'manual',
            'order_correlation_id' => DogfoodTiming::correlation($order->id),
        ], fn () => $this->bot->sendMessage($adminTelegramChatId, implode("\n", $lines)));
    }

    public function paymentMode(): string
    {
        return $this->pakasirReadiness->effectivePaymentMode($this->requestedPaymentMode());
    }

    public function requestedPaymentMode(): string
    {
        $settings = ManualPaymentSetting::current();
        $mode = (string) ($settings?->payment_mode ?: config('services.telegram.payment_mode', 'manual'));

        return $mode === 'pakasir' ? 'pakasir' : 'manual';
    }

    public function pakasirUnavailableCustomerMessage(): string
    {
        return "Pakasir otomatis sedang tidak tersedia, jadi checkout ini dialihkan ke QR manual.\n\nAdmin toko perlu mengecek konfigurasi Pakasir sebelum pembayaran otomatis dipakai lagi.";
    }

    public function manualPaymentMethod(): string
    {
        return 'QR_MANUAL';
    }

    public function manualPaymentOption(): array
    {
        return [
            'label' => 'QR Manual',
            'name' => 'LHIE STORE',
        ];
    }
}
