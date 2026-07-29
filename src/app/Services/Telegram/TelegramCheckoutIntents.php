<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Binance\BinanceAutoVerification;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\KlikQris\KlikQrisQrisImage;
use App\Services\KlikQris\KlikQrisSandboxCheckoutSelector;
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
        private TelegramLocale $locale,
        private BinanceAutoVerification $binanceVerification,
        private KlikQrisSandboxCheckoutSelector $klikQrisSelector,
        private KlikQrisNativeQrisService $klikQris,
        private KlikQrisQrisImage $klikQrisImages,
    ) {}

    public function sendPaymentInstructions(string $chatId, Order $order): void
    {
        $order->loadMissing('customer');
        $instruction = $this->paymentInstructions->manual(
            $order,
            $this->manualPaymentOption($order->customer),
            ltrim((string) (config('services.telegram.admin_username') ?: 'Alhie02'), '@'),
            $this->locale->code($order->customer),
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
        $order->loadMissing('customer');
        $locale = $this->locale->code($order->customer);
        if ($this->pakasirCheckoutFlow() === 'api_qris') {
            try {
                $transaction = $this->nativeQris->ensureCreated($payment);
            } catch (Throwable $e) {
                report($e);
                $this->sendHostedPakasirPaymentInstructions($chatId, $order, $payment, $locale, true);

                return;
            }

            $payment->refresh();
            $this->sendNativePakasirPaymentInstructions($chatId, $order, $payment, $transaction, $locale);

            return;
        }

        $this->sendHostedPakasirPaymentInstructions($chatId, $order, $payment, $locale);
    }

    public function sendKlikQrisPaymentInstructions(string $chatId, Order $order, Payment $payment): array
    {
        $order->loadMissing('customer');
        $transaction = $this->klikQris->ensureCreated($payment);
        $payment->refresh();

        return $this->sendPaymentInstruction(
            $chatId,
            $this->paymentInstructions->klikQris(
                $order,
                $payment,
                $this->klikQrisImages->contents($transaction),
                $this->locale->code($order->customer),
            ),
        );
    }

    public function binancePayEnabled(): bool
    {
        return (bool) ManualPaymentSetting::current()?->binance_pay_enabled && filled(config('services.binance.pay_id'));
    }

    public function formatUsdt(int $amount): string
    {
        return rtrim(rtrim(number_format($amount / 1_000_000, 6, '.', ''), '0'), '.');
    }

    public function sendBinancePaymentInstructions(string $chatId, Order $order, Payment $payment, Customer $customer): array
    {
        // With API credentials configured, payments are verified automatically;
        // otherwise they are confirmed from a photo sent with /payment.
        $autoVerify = $this->binanceVerification->enabled();
        $payId = (string) config('services.binance.pay_id');
        $text = $this->paymentInstructions->binancePay($order, $payment, $payId, $this->locale->code($customer), $autoVerify);
        $amount = $this->formatUsdt($payment->amount);

        $photoPath = public_path('binance.jpeg');
        $photoContents = is_readable($photoPath) ? file_get_contents($photoPath) : false;
        if ($photoContents === false) {
            throw new \RuntimeException('Binance Pay image is not readable: '.$photoPath);
        }

        $keyboard = [];
        if ($autoVerify) {
            $keyboard[] = [[
                'text' => $this->locale->text($customer, '✅ Saya Sudah Bayar', "✅ I've Paid"),
                'callback_data' => 'binance:verify:'.$order->id,
            ]];
        }
        $keyboard[] = [[
            'text' => $this->locale->text($customer, '❌ Batalkan Order', '❌ Cancel Order'),
            'callback_data' => 'buyer:cancel-pending:'.$order->id,
        ]];

        return $this->bot->sendPhoto($chatId, $photoContents, 'binance.jpeg', $text, [
            'inline_keyboard' => $keyboard,
        ], [
            'caption_entities' => [
                $this->codeEntityForLine($text, $amount.' USDT', $amount),
                $this->codeEntityForLine($text, $payId, $payId),
            ],
        ]);
    }

    /** @return array{type: 'code', offset: int, length: int} */
    private function codeEntityForLine(string $text, string $line, string $value): array
    {
        $lineOffset = strpos($text, "\n{$line}\n");
        if ($lineOffset === false) {
            throw new \LogicException('Binance payment value was not found in the instruction.');
        }

        $valueOffset = $lineOffset + 1;

        return [
            'type' => 'code',
            'offset' => $this->utf16Length(substr($text, 0, $valueOffset)),
            'length' => $this->utf16Length($value),
        ];
    }

    private function utf16Length(string $value): int
    {
        return intdiv(strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
    }

    private function sendHostedPakasirPaymentInstructions(string $chatId, Order $order, Payment $payment, string $locale, bool $fallback = false): void
    {
        $this->sendPaymentInstruction(
            $chatId,
            $this->paymentInstructions->hostedPakasir(
                $order,
                $payment,
                $this->pakasir->hostedPaymentUrl($payment),
                $fallback,
                $locale,
            ),
        );
    }

    private function sendNativePakasirPaymentInstructions(string $chatId, Order $order, Payment $payment, PakasirQrisTransaction $transaction, string $locale): void
    {
        $this->sendPaymentInstruction(
            $chatId,
            $this->paymentInstructions->nativePakasir(
                $order,
                $payment,
                $transaction,
                $this->pakasir->hostedPaymentUrl($payment),
                $locale,
            ),
        );
    }

    private function sendPaymentInstruction(string $chatId, TelegramPaymentInstruction $instruction, array $timingContext = []): array
    {
        $send = fn () => $this->bot->sendPhoto(
            $chatId,
            $instruction->photoContents,
            $instruction->filename,
            $instruction->caption(),
            $instruction->replyMarkup,
        );

        if ($timingContext === []) {
            return $send();
        }

        return DogfoodTiming::time('dogfood.telegram.checkout_step', $timingContext, $send);
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
        if ($this->requestedPaymentMode() === 'klikqris') {
            return 'klikqris';
        }

        return $this->pakasirReadiness->effectivePaymentMode(
            $this->requestedPaymentMode(),
            $this->requestedPakasirCheckoutFlow(),
        );
    }

    public function requestedPaymentMode(): string
    {
        $settings = ManualPaymentSetting::current();
        $mode = (string) ($settings?->payment_mode ?: 'manual');

        if ($this->klikQrisSelector->enabled()) {
            return 'klikqris';
        }

        return $mode === 'pakasir' ? 'pakasir' : 'manual';
    }

    private function requestedPakasirCheckoutFlow(): string
    {
        $settings = ManualPaymentSetting::current();

        return (string) ($settings?->pakasir_checkout_flow ?: PakasirCheckoutFlow::HOSTED);
    }

    public function pakasirUnavailableCustomerMessage(Customer $customer): string
    {
        return $this->locale->text(
            $customer,
            "Pembayaran otomatis sedang tidak tersedia, jadi checkout ini dialihkan ke QR manual.\n\nAdmin toko perlu mengecek konfigurasi pembayaran otomatis sebelum fitur ini dipakai lagi.",
            "Automatic payment is currently unavailable, so this checkout has been redirected to a manual QR payment.\n\nThe store admin needs to check the automatic payment configuration before this feature can be used again.",
        );
    }

    public function manualPaymentMethod(): string
    {
        return 'QR_MANUAL';
    }

    public function manualPaymentOption(Customer $customer): array
    {
        return [
            'label' => $this->locale->text($customer, 'QR Manual', 'Manual QR'),
            'name' => 'LHIE STORE',
        ];
    }
}
