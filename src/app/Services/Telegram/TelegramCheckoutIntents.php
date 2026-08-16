<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Checkout\PaymentProviderResolver;
use App\Services\Checkout\PaymentProviderSelection;
use App\Services\KlikQris\KlikQrisNativeQrisOutcome;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\KlikQris\KlikQrisQrisImage;
use App\Services\Pakasir\PakasirNativeQrisService;
use App\Services\Pakasir\PakasirProviderAdapter;
use App\Services\Pakasir\PakasirProviderReadiness;
use App\Services\Pakasir\PakasirQrisTransaction;
use App\Support\DogfoodTiming;
use App\Support\PakasirCheckoutFlow;
use InvalidArgumentException;
use RuntimeException;
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
        private KlikQrisNativeQrisService $klikQris,
        private TelegramKlikQrisRecoveryEscalator $klikQrisAlerts,
        private KlikQrisQrisImage $klikQrisImages,
        private PaymentProviderResolver $providerResolver,
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
        if ($payment->method === PakasirCheckoutFlow::API_QRIS) {
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
        $locale = $this->locale->code($order->customer);
        try {
            $result = $this->klikQris->lifecycle($payment);
        } finally {
            $this->klikQrisAlerts->notifyFor($payment);
        }
        $payable = $result->payable();
        if ($payable === null) {
            return $this->sendKlikQrisRetryState($chatId, $order, $result->outcome(), $locale);
        }

        $payment->refresh();
        try {
            $imageContents = $this->klikQrisImages->contents($payable);
        } catch (InvalidArgumentException $exception) {
            $recoveredPayable = $this->klikQris->recoverPresentationImage($payment);
            if ($recoveredPayable === null) {
                throw $exception;
            }

            $imageContents = $this->klikQrisImages->contents($recoveredPayable, preferFallback: true);
        }

        return $this->sendPaymentInstruction(
            $chatId,
            $this->paymentInstructions->klikQris(
                $order,
                $payable,
                $imageContents,
                $locale,
            ),
        );
    }

    private function sendKlikQrisRetryState(string $chatId, Order $order, KlikQrisNativeQrisOutcome $outcome, string $locale): array
    {
        return $this->bot->sendMessage($chatId, $this->paymentInstructions->klikQrisRetry($order, $outcome, $locale), [
            'inline_keyboard' => [
                [['text' => $this->locale->translate($order->customer, 'payment.action.check'), 'callback_data' => 'order:'.$order->invoice_number]],
                [['text' => $this->locale->translate($order->customer, 'payment.action.cancel'), 'callback_data' => 'buyer:cancel-pending:'.$order->id]],
            ],
        ]);
    }

    public function binancePayEnabled(): bool
    {
        return (bool) ManualPaymentSetting::current()?->binance_pay_enabled
            && (filled(config('services.binance.pay_id')) || filled(config('services.binance.pay_uid')));
    }

    public function formatUsdt(int $amount): string
    {
        return rtrim(rtrim(number_format($amount / 1_000_000, 6, '.', ''), '0'), '.');
    }

    public function sendBinancePaymentInstructions(string $chatId, Order $order, Payment $payment, Customer $customer): array
    {
        // The payment snapshots whether API verification was enabled at checkout;
        // changing credentials later must not change this order's flow.
        $autoVerify = (bool) $payment->binance_auto_verification_enabled
            && in_array($payment->binance_verification_mode, ['amount_unique', 'order_id'], true);
        $receiver = (string) ($payment->binance_receiver_value ?: (config('services.binance.pay_id') ?: config('services.binance.pay_uid')));
        $receiverLabel = $payment->binance_receiver_type === 'uid'
            ? 'BINANCE UID'
            : ($payment->binance_receiver_type === 'pay_id' ? 'BINANCE PAY ID' : (filled(config('services.binance.pay_id')) ? 'BINANCE PAY ID' : 'BINANCE UID'));
        $text = $this->paymentInstructions->binancePay($order, $payment, $receiver, $this->locale->code($customer), $autoVerify, $receiverLabel);
        $amount = $this->formatUsdt($payment->amount);

        $photoPath = public_path('binance.jpeg');
        $photoContents = is_readable($photoPath) ? file_get_contents($photoPath) : false;
        if ($photoContents === false) {
            throw new RuntimeException('Binance Pay image is not readable: '.$photoPath);
        }

        $keyboard = [];
        if ($autoVerify) {
            $keyboard[] = [[
                'text' => $this->locale->translate($customer, 'payment.action.paid'),
                'callback_data' => 'binance:verify:'.$order->id,
            ]];
        }
        $keyboard[] = [[
            'text' => $this->locale->translate($customer, 'payment.action.cancel'),
            'callback_data' => 'buyer:cancel-pending:'.$order->id,
        ]];

        return $this->bot->sendPhoto($chatId, $photoContents, 'binance.jpeg', $text, [
            'inline_keyboard' => $keyboard,
        ], [
            'caption_entities' => [
                $this->codeEntityForLine($text, $amount.' USDT', $amount),
                $this->codeEntityForLine($text, $receiver, $receiver),
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

    public function pakasirPaymentMethod(?string $checkoutFlow = null): string
    {
        return ($checkoutFlow ?? $this->pakasirCheckoutFlow()) === PakasirCheckoutFlow::API_QRIS
            ? PakasirCheckoutFlow::API_QRIS
            : PakasirCheckoutFlow::HOSTED;
    }

    /** @return array{requested: string, effective: string, pakasir_checkout_flow: string} */
    public function providerSelection(): array
    {
        $selection = $this->configuredProviderSelection();

        return [
            'requested' => $selection->requestedProvider,
            'effective' => $selection->provider,
            'pakasir_checkout_flow' => $selection->pakasirCheckoutFlow,
        ];
    }

    public function configuredProviderSelection(bool $binance = false): PaymentProviderSelection
    {
        return $this->providerResolver->resolve($binance ? 'binance_pay' : null);
    }

    public function paymentMode(): string
    {
        return $this->providerSelection()['effective'];
    }

    public function requestedPaymentMode(): string
    {
        return $this->providerSelection()['requested'];
    }

    private function pakasirCheckoutFlow(): string
    {
        return $this->pakasirReadiness->checkout($this->requestedPakasirCheckoutFlow())->checkoutFlow();
    }

    private function requestedPakasirCheckoutFlow(): string
    {
        $settings = ManualPaymentSetting::current();

        return (string) ($settings?->pakasir_checkout_flow ?: PakasirCheckoutFlow::HOSTED);
    }

    public function pakasirUnavailableCustomerMessage(Customer $customer): string
    {
        return $this->locale->translate($customer, 'payment.pakasir.unavailable_fallback');
    }

    public function manualPaymentMethod(): string
    {
        return 'QR_MANUAL';
    }

    public function manualPaymentOption(Customer $customer): array
    {
        return [
            'label' => $this->locale->translate($customer, 'payment.method.manual'),
            'name' => 'LHIE STORE',
        ];
    }
}
