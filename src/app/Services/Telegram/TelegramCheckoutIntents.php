<?php

namespace App\Services\Telegram;

use App\Exceptions\TelegramOutboundPreSendException;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentBscDetail;
use App\Services\Bsc\BscReadiness;
use App\Services\Checkout\PaymentProviderResolver;
use App\Services\Checkout\PaymentProviderSelection;
use App\Services\KlikQris\KlikQrisNativeQrisOutcome;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\KlikQris\KlikQrisQrisImage;
use App\Services\Pakasir\PakasirNativeQrisService;
use App\Services\Pakasir\PakasirProviderAdapter;
use App\Services\Pakasir\PakasirQrisTransaction;
use App\Services\Sakurupiah\SakurupiahInvoiceLifecycleService;
use App\Services\Sakurupiah\SakurupiahInvoiceOutcome;
use App\Services\Sakurupiah\SakurupiahQrisImage;
use App\Support\DogfoodTiming;
use App\Support\PakasirCheckoutFlow;
use InvalidArgumentException;
use Throwable;

class TelegramCheckoutIntents
{
    public function __construct(
        private TelegramBotClient $bot,
        private PakasirProviderAdapter $pakasir,
        private PakasirNativeQrisService $nativeQris,
        private TelegramPaymentInstructionRenderer $paymentInstructions,
        private TelegramLocale $locale,
        private KlikQrisNativeQrisService $klikQris,
        private TelegramKlikQrisRecoveryEscalator $klikQrisAlerts,
        private KlikQrisQrisImage $klikQrisImages,
        private SakurupiahInvoiceLifecycleService $sakurupiah,
        private SakurupiahQrisImage $sakurupiahImages,
        private PaymentProviderResolver $providerResolver,
        private BscReadiness $bscReadiness,
    ) {}

    public function sendPaymentInstructions(string $chatId, Order $order): array
    {
        $order->loadMissing('customer');
        $instruction = $this->paymentInstructions->manual(
            $order,
            $this->manualPaymentOption($order->customer),
            ltrim((string) (config('services.telegram.admin_username') ?: 'Alhie02'), '@'),
            $this->locale->code($order->customer),
        );

        return $this->sendPaymentInstruction($chatId, $instruction, [
            'step' => 'manual_customer_send_photo',
            'payment_mode' => 'manual',
            'order_correlation_id' => DogfoodTiming::correlation($order->id),
            'qr_image_bytes' => strlen($instruction->photoContents),
        ]);
    }

    public function manualPaymentProofInstructions(Order $order): string
    {
        $order->loadMissing('customer');

        return $this->paymentInstructions->manualProof(
            $order,
            ltrim((string) (config('services.telegram.admin_username') ?: 'Alhie02'), '@'),
            $this->locale->code($order->customer),
        );
    }

    public function sendPakasirPaymentInstructions(string $chatId, Order $order, Payment $payment): array
    {
        $order->loadMissing('customer');
        $locale = $this->locale->code($order->customer);
        if ($payment->method === PakasirCheckoutFlow::API_QRIS) {
            try {
                $transaction = $this->nativeQris->ensureCreated($payment);
            } catch (Throwable $e) {
                report($e);

                return $this->sendHostedPakasirPaymentInstructions($chatId, $order, $payment, $locale, true);

            }

            $payment->refresh();

            return $this->sendNativePakasirPaymentInstructions($chatId, $order, $payment, $transaction, $locale);
        }

        return $this->sendHostedPakasirPaymentInstructions($chatId, $order, $payment, $locale);
    }

    public function sendKlikQrisPaymentInstructions(string $chatId, Order $order, Payment $payment): array
    {
        $order->loadMissing('customer');
        $locale = $this->locale->code($order->customer);
        try {
            try {
                $result = $this->klikQris->lifecycle($payment);
            } catch (Throwable $exception) {
                throw new TelegramOutboundPreSendException('KlikQRIS checkout preparation failed before Telegram delivery.', $exception);
            }
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
            try {
                $recoveredPayable = $this->klikQris->recoverPresentationImage($payment);
            } catch (Throwable $recoveryException) {
                throw new TelegramOutboundPreSendException('KlikQRIS QR presentation recovery failed before Telegram delivery.', $recoveryException);
            }
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

    public function sendSakurupiahPaymentInstructions(string $chatId, Order $order, Payment $payment): array
    {
        $order->loadMissing('customer');
        $locale = $this->locale->code($order->customer);

        try {
            $result = $this->sakurupiah->lifecycle($payment);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendSakurupiahRetryState(
                $chatId,
                $order,
                SakurupiahInvoiceOutcome::InvalidProviderResult,
                $locale,
            );
        }

        $payable = $result->payable();
        if ($payable === null) {
            return $this->sendSakurupiahRetryState($chatId, $order, $result->outcome(), $locale);
        }

        if ($payable->isQrisImage()) {
            try {
                $imageContents = $this->sakurupiahImages->contents($payable);

                return $this->sendPaymentInstruction(
                    $chatId,
                    $this->paymentInstructions->sakurupiahQrisImage($order, $payable, $imageContents, $locale),
                );
            } catch (Throwable) {
                if (! $payable->isHosted()) {
                    return $this->sendSakurupiahRetryState(
                        $chatId,
                        $order,
                        SakurupiahInvoiceOutcome::ProviderPresentationPending,
                        $locale,
                    );
                }
            }
        }

        if ($payable->isHosted()) {
            $instruction = $this->paymentInstructions->sakurupiahHosted($order, $payable, $locale);

            return $this->bot->sendMessage(
                $chatId,
                $instruction['text'],
                $instruction['reply_markup'],
            );
        }

        return $this->sendPaymentInstruction(
            $chatId,
            $this->paymentInstructions->sakurupiah($order, $payable, $locale),
        );
    }

    private function sendSakurupiahRetryState(
        string $chatId,
        Order $order,
        SakurupiahInvoiceOutcome $outcome,
        string $locale,
    ): array {
        return $this->bot->sendMessage($chatId, $this->paymentInstructions->sakurupiahRetry($order, $outcome, $locale), [
            'inline_keyboard' => [
                [['text' => $this->locale->translate($order->customer, 'payment.action.check'), 'callback_data' => 'order:'.$order->invoice_number]],
                [['text' => $this->locale->translate($order->customer, 'payment.action.cancel'), 'callback_data' => 'buyer:cancel-pending:'.$order->id]],
            ],
        ]);
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

    public function bscRailSelectable(): bool
    {
        return $this->bscReadiness->check()->isReady();
    }

    public function binanceRailSelectable(): bool
    {
        return $this->binancePayEnabled() || $this->bscRailSelectable();
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
        $copyEntities = $this->copyableEntities($text, [
            [$amount.' USDT', $amount],
            [$receiver, $receiver],
        ]);
        $captionOptions = $copyEntities === [] ? [] : ['caption_entities' => $copyEntities];

        $photoPath = public_path('binance.jpeg');
        $photoContents = is_readable($photoPath) ? file_get_contents($photoPath) : false;

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

        $replyMarkup = ['inline_keyboard' => $keyboard];
        if ($photoContents === false) {
            return $this->sendBinancePaymentTextFallback($chatId, $text, $replyMarkup, $customer, $copyEntities);
        }

        try {
            return $this->bot->sendPhoto($chatId, $photoContents, 'binance.jpeg', $text, $replyMarkup, $captionOptions);
        } catch (Throwable) {
            // Prefer delivering the copyable payment details even if the photo
            // result is ambiguous; this can duplicate instructions if Telegram
            // accepted the photo before the connection failed.
            return $this->sendBinancePaymentTextFallback($chatId, $text, $replyMarkup, $customer, $copyEntities);
        }
    }

    /**
     * @param  array<string, mixed>  $replyMarkup
     * @param  list<array{type: 'code', offset: int, length: int}>  $copyEntities
     * @return array<string, mixed>
     */
    private function sendBinancePaymentTextFallback(
        string $chatId,
        string $text,
        array $replyMarkup,
        Customer $customer,
        array $copyEntities,
    ): array {
        $fallbackText = $text."\n\n".$this->locale->translate($customer, 'payment.binance.text_fallback');
        $options = $copyEntities === [] ? [] : ['entities' => $copyEntities];

        return $this->bot->sendMessage($chatId, $fallbackText, $replyMarkup, $options);
    }

    public function sendBscPaymentInstructions(string $chatId, Order $order, Payment $payment, Customer $customer): array
    {
        $instruction = $this->paymentInstructions->bsc(
            $order,
            $payment,
            $this->locale->code($customer),
        );
        $detail = $payment->bscDetail;
        if (! $detail instanceof PaymentBscDetail) {
            throw new \LogicException('BSC Payment detail snapshot is missing.');
        }
        $amount = $this->formatUsdt((int) $detail->payable_amount_micro);
        $copyEntities = $this->copyableEntities($instruction['text'], [
            [$amount.' USDT', $amount],
            [$detail->receiver_address, $detail->receiver_address],
        ]);

        return $this->bot->sendMessage(
            $chatId,
            $instruction['text'],
            $instruction['reply_markup'],
            $copyEntities === [] ? [] : ['entities' => $copyEntities],
        );
    }

    /**
     * Build the copyable `code` entities for the payment values that should be
     * tappable. A value that is not on its own line is skipped instead of
     * aborting the whole instruction: the customer must always receive the
     * receiver id and amount, even as plain text.
     *
     * @param  list<array{0: string, 1: string}>  $values
     * @return list<array{type: 'code', offset: int, length: int}>
     */
    private function copyableEntities(string $text, array $values): array
    {
        $entities = [];
        foreach ($values as [$line, $value]) {
            $entity = $this->codeEntityForLine($text, $line, $value);
            if ($entity !== null) {
                $entities[] = $entity;
            }
        }

        return $entities;
    }

    /** @return array{type: 'code', offset: int, length: int}|null */
    private function codeEntityForLine(string $text, string $line, string $value): ?array
    {
        if ($line === '' || $value === '') {
            return null;
        }

        $lineOffset = strpos($text, "\n{$line}\n");
        if ($lineOffset === false) {
            return null;
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

    private function sendHostedPakasirPaymentInstructions(string $chatId, Order $order, Payment $payment, string $locale, bool $fallback = false): array
    {
        return $this->sendPaymentInstruction(
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

    private function sendNativePakasirPaymentInstructions(string $chatId, Order $order, Payment $payment, PakasirQrisTransaction $transaction, string $locale): array
    {
        return $this->sendPaymentInstruction(
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

    /** @return array{requested: string, effective: string} */
    public function providerSelection(): array
    {
        $selection = $this->configuredProviderSelection();

        return [
            'requested' => $selection->requestedProvider,
            'effective' => $selection->provider,
        ];
    }

    public function configuredProviderSelection(string|bool|null $rail = null): PaymentProviderSelection
    {
        $paymentRail = is_bool($rail)
            ? ($rail ? 'binance_pay_id' : null)
            : $rail;

        return $this->providerResolver->resolve($paymentRail);
    }

    public function paymentMode(): string
    {
        return $this->providerSelection()['effective'];
    }

    public function requestedPaymentMode(): string
    {
        return $this->providerSelection()['requested'];
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
