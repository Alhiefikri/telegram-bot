<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectType;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TelegramOutboundEffect;
use App\Support\DogfoodTiming;
use InvalidArgumentException;
use Throwable;

/**
 * Delivers an explicitly confirmed paid-order TXT export through the shared
 * Telegram Outbound Effect Ledger. Credential values exist only during the
 * current render and upload attempt.
 */
final class ReceiptTxtDeliveryPipeline
{
    public function __construct(
        private readonly TelegramOutboundEffectLedgerProtocol $ledger,
        private readonly TelegramBotClient $bot,
        private readonly TelegramOutboundResponseValidator $responseValidator,
        private readonly TelegramPaidOrderTxtExporter $exporter,
    ) {}

    public function deliver(TelegramOutboundEffect $effect): void
    {
        if ($effect->effect_type !== TelegramOutboundEffectType::ReceiptTxtDownload->value
            || $effect->source_type !== Order::class) {
            throw new InvalidArgumentException('Unsupported Telegram TXT download effect type.');
        }

        $claimed = $this->ledger->claim((string) $effect->getKey());
        if ($claimed === null) {
            return;
        }

        $payload = null;
        $customerCorrelationId = null;

        try {
            $order = Order::query()
                ->with(['customer', 'payments', 'orderItems.stockItems', 'orderItems.product'])
                ->findOrFail($claimed->source_id);
            $customer = $order->customer;
            $customerCorrelationId = DogfoodTiming::correlation($customer?->telegram_id);

            if (! $this->eligible($order, $customer, $claimed)) {
                $this->finalizeNotDeliverable(
                    $claimed,
                    'receipt_txt_not_eligible',
                    'TXT download was no longer eligible for delivery.',
                );

                return;
            }

            try {
                $payload = DogfoodTiming::time(
                    'dogfood.telegram.receipt_txt_render',
                    [
                        'step' => 'document_render',
                        'effect_correlation_id' => DogfoodTiming::correlation($claimed->getKey()),
                        'order_correlation_id' => DogfoodTiming::correlation($order->getKey()),
                        'customer_correlation_id' => $customerCorrelationId,
                    ],
                    fn (): ?TelegramDocumentPayload => $this->exporter->export($order, $customer),
                );
            } catch (Throwable) {
                $this->finalizeNotDeliverable(
                    $claimed,
                    'receipt_txt_render_failed',
                    'TXT file could not be generated.',
                );

                return;
            }

            if ($payload === null) {
                $this->finalizeNotDeliverable(
                    $claimed,
                    'receipt_txt_credentials_missing',
                    'No downloadable product credentials are available.',
                );

                return;
            }

            $byteLength = $payload->byteLength();
            $response = DogfoodTiming::time(
                'dogfood.telegram.receipt_txt_send',
                [
                    'step' => 'document_send',
                    'effect_correlation_id' => DogfoodTiming::correlation($claimed->getKey()),
                    'order_correlation_id' => DogfoodTiming::correlation($order->getKey()),
                    'customer_correlation_id' => $customerCorrelationId,
                ],
                fn (): array => $this->bot->sendDocument(
                    (string) $claimed->telegram_chat_id,
                    $payload->documentContents(),
                    $payload->filename(),
                    null,
                    [],
                    ['protect_content' => false],
                ),
            );
            $messageId = $this->responseValidator->messageIdFromResponse($response, 'Telegram TXT download');

            $this->ledger->finalizeSent(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                $messageId,
                [
                    'byte_size_bucket' => $this->byteSizeBucket($byteLength),
                    'delivery_method' => 'sendDocument',
                ],
            );
        } catch (Throwable $exception) {
            if ($this->ledger->isAmbiguous($exception)) {
                $this->ledger->finalizeUnknown(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    $exception,
                );

                return;
            }

            $this->ledger->finalizeFailed(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                $exception,
                $this->ledger->retryAfterSeconds($exception),
            );
        } finally {
            unset($payload);
        }
    }

    private function eligible(
        Order $order,
        ?Customer $customer,
        TelegramOutboundEffect $effect,
    ): bool {
        if ($customer === null
            || (string) $customer->getKey() !== (string) $order->customer_id
            || preg_match('/\A[1-9][0-9]*\z/D', trim((string) $customer->telegram_id)) !== 1
            || (string) $effect->telegram_chat_id !== (string) $customer->telegram_id
            || $effect->target_type !== 'customer'
            || $order->payment_status !== PaymentStatus::Paid
            || ! in_array($order->order_status, [OrderStatus::PendingPayment, OrderStatus::Processing, OrderStatus::Completed], true)
            || $order->hasOpenLatePaymentReview()) {
            return false;
        }

        $payment = $order->payments
            ->sortByDesc(fn (Payment $candidate): array => [
                $candidate->created_at?->getTimestamp() ?? 0,
                (string) $candidate->getKey(),
            ])
            ->first();

        return $payment instanceof Payment
            && (string) $payment->order_id === (string) $order->getKey()
            && $payment->status === PaymentStatus::Paid
            && $payment->paid_at !== null;
    }

    private function finalizeNotDeliverable(
        TelegramOutboundEffect $effect,
        string $reason,
        string $message,
    ): void {
        $this->ledger->finalizeNotDeliverable(
            (string) $effect->getKey(),
            (string) $effect->claim_token,
            $reason,
            $message,
        );
    }

    private function byteSizeBucket(int $bytes): string
    {
        return match (true) {
            $bytes <= 1024 => '0-1024',
            $bytes <= 16_384 => '1025-16384',
            $bytes <= 65_536 => '16385-65536',
            $bytes <= 262_144 => '65537-262144',
            default => '262145-1048576',
        };
    }
}
