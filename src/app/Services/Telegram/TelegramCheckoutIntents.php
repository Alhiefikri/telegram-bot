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
use App\Support\PakasirCheckoutFlow;
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
        $order->loadMissing('customer');
        $instruction = $this->paymentInstructions->manual(
            $order,
            $this->manualPaymentOption(),
            ltrim((string) (config('services.telegram.admin_username') ?: 'Alhie02'), '@'),
            $order->customer?->locale === 'en' ? 'en' : 'id',
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

    public function binancePayEnabled(): bool
    {
        return (bool) ManualPaymentSetting::current()?->binance_pay_enabled && filled(config('services.binance.pay_id'));
    }

    public function formatUsdt(int $amount): string
    {
        return number_format($amount / 1_000_000, 6, '.', '');
    }

    public function sendBinancePaymentInstructions(string $chatId, Order $order, Payment $payment, \App\Models\Customer $customer): void
    {
        $this->bot->sendMessage($chatId, $this->paymentInstructions->binancePay($order, $payment, (string) config('services.binance.pay_id'), $customer->locale), [
            'inline_keyboard' => [[['text' => '📸 Send Payment Proof', 'callback_data' => 'payment-proof:'.$order->id]]],
        ]);
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

    public function pakasirPaymentMethod(): string
    {
        return $this->pakasirCheckoutFlow() === PakasirCheckoutFlow::API_QRIS
            ? PakasirCheckoutFlow::API_QRIS
            : PakasirCheckoutFlow::HOSTED;
    }

    private function pakasirCheckoutFlow(): string
    {
        return $this->pakasirReadiness->checkout($this->requestedPakasirCheckoutFlow())->checkoutFlow();
    }

    public function paymentMode(): string
    {
        return $this->pakasirReadiness->effectivePaymentMode(
            $this->requestedPaymentMode(),
            $this->requestedPakasirCheckoutFlow(),
        );
    }

    public function requestedPaymentMode(): string
    {
        $settings = ManualPaymentSetting::current();
        $mode = (string) ($settings?->payment_mode ?: 'manual');

        return $mode === 'pakasir' ? 'pakasir' : 'manual';
    }

    private function requestedPakasirCheckoutFlow(): string
    {
        $settings = ManualPaymentSetting::current();

        return (string) ($settings?->pakasir_checkout_flow ?: PakasirCheckoutFlow::HOSTED);
    }

    public function pakasirUnavailableCustomerMessage(): string
    {
        return "Pembayaran otomatis sedang tidak tersedia, jadi checkout ini dialihkan ke QR manual.\n\nAdmin toko perlu mengecek konfigurasi pembayaran otomatis sebelum fitur ini dipakai lagi.";
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
