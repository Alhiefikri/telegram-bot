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
        private readonly TelegramReceiptObservability $observability,
        private readonly TelegramLocale $locale,
        private readonly TelegramSupportContact $supportContact,
    ) {}

    public function deliver(TelegramOutboundEffect $effect, ?string $jobEnqueuedAt = null): void
    {
        if ($effect->effect_type !== TelegramOutboundEffectType::ReceiptTxtDownload->value
            || $effect->source_type !== Order::class) {
            throw new InvalidArgumentException('Unsupported Telegram TXT download effect type.');
        }

        $claimed = $this->ledger->claim((string) $effect->getKey());
        if ($claimed === null) {
            return;
        }

        $deliveryStartedAt = DogfoodTiming::now();
        $payload = null;
        $customerCorrelationId = null;

        try {
            $order = Order::query()
                ->with(['customer', 'payments', 'orderItems.stockItems', 'orderItems.product'])
                ->findOrFail($claimed->source_id);
            $customer = $order->customer;
            $customerCorrelationId = DogfoodTiming::correlation($customer?->telegram_id);

            if (! $this->eligible($order, $customer, $claimed)) {
                $finalized = $this->finalizeNotDeliverable(
                    $claimed,
                    'receipt_txt_not_eligible',
                    'TXT download was no longer eligible for delivery.',
                );
                $this->recordOutcome($finalized, 'failed', $deliveryStartedAt);

                return;
            }

            $renderStartedAt = DogfoodTiming::now();
            $renderQuerySnapshot = DogfoodTiming::querySnapshot();
            $renderRssBeforeBytes = DogfoodTiming::residentMemoryBytes();

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
                $this->observability->txtRender(
                    $claimed,
                    DogfoodTiming::elapsedMs($renderStartedAt),
                    outputBytes: null,
                    workerRssBeforeBytes: $renderRssBeforeBytes,
                    workerRssAfterBytes: DogfoodTiming::residentMemoryBytes(),
                    queryStats: DogfoodTiming::queryDelta($renderQuerySnapshot),
                    outcome: 'failed',
                );
                $finalized = $this->finalizeNotDeliverable(
                    $claimed,
                    'receipt_txt_render_failed',
                    'TXT file could not be generated.',
                );
                $this->recordOutcome($finalized, 'failed', $deliveryStartedAt);
                if ($finalized !== null) {
                    $this->sendGenerationFailureNotice($claimed, $customer);
                }

                return;
            }

            $renderDurationMs = DogfoodTiming::elapsedMs($renderStartedAt);
            $this->observability->txtRender(
                $claimed,
                $renderDurationMs,
                outputBytes: $payload?->byteLength(),
                workerRssBeforeBytes: $renderRssBeforeBytes,
                workerRssAfterBytes: DogfoodTiming::residentMemoryBytes(),
                queryStats: DogfoodTiming::queryDelta($renderQuerySnapshot),
                outcome: $payload === null ? 'failed' : 'ok',
            );

            if ($payload === null) {
                $finalized = $this->finalizeNotDeliverable(
                    $claimed,
                    'receipt_txt_credentials_missing',
                    'No downloadable product credentials are available.',
                );
                $this->recordOutcome($finalized, 'failed', $deliveryStartedAt);
                if ($finalized !== null) {
                    $this->sendGenerationFailureNotice($claimed, $customer);
                }

                return;
            }

            $byteLength = $payload->byteLength();
            $sendStartedAt = DogfoodTiming::now();
            try {
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
                $this->observability->txtDocumentAttempt(
                    $claimed,
                    DogfoodTiming::elapsedMs($sendStartedAt),
                    'ok',
                );
            } catch (Throwable $exception) {
                $this->observability->txtDocumentAttempt(
                    $claimed,
                    DogfoodTiming::elapsedMs($sendStartedAt),
                    $this->ledger->isAmbiguous($exception) ? 'ambiguous' : 'failed',
                );

                throw $exception;
            }

            $finalized = $this->ledger->finalizeSent(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                $messageId,
                [
                    'byte_size_bucket' => $this->observability->byteSizeBucket($byteLength),
                    'output_size_bucket' => $this->observability->byteSizeBucket($byteLength),
                    'render_duration_bucket' => $this->observability->durationBucket($renderDurationMs),
                    'telegram_document_duration_bucket' => $this->observability->durationBucket(DogfoodTiming::elapsedMs($sendStartedAt)),
                    'queue_wait_bucket' => $this->observability->queueWaitBucket(DogfoodTiming::queueWaitMs($jobEnqueuedAt)),
                    'delivery_method' => 'sendDocument',
                ],
            );
            $this->recordOutcome($finalized, 'sent', $deliveryStartedAt);
            if ($finalized !== null) {
                $this->cleanupConfirmationButtons($claimed);
            }
        } catch (Throwable $exception) {
            if ($this->ledger->isAmbiguous($exception)) {
                $finalized = $this->ledger->finalizeUnknown(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    $exception,
                );
                $this->recordOutcome($finalized, 'unknown', $deliveryStartedAt);

                return;
            }

            $finalized = $this->ledger->finalizeFailed(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                $exception,
                $this->ledger->retryAfterSeconds($exception),
            );
            $this->recordOutcome($finalized, 'failed', $deliveryStartedAt);
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
    ): ?TelegramOutboundEffect {
        return $this->ledger->finalizeNotDeliverable(
            (string) $effect->getKey(),
            (string) $effect->claim_token,
            $reason,
            $message,
        );
    }

    private function sendGenerationFailureNotice(
        TelegramOutboundEffect $effect,
        ?Customer $customer,
    ): void {
        if ($customer === null) {
            return;
        }

        $context = [
            'effect_correlation_id' => DogfoodTiming::correlation($effect->getKey()),
            'order_correlation_id' => DogfoodTiming::correlation($effect->source_id),
            'customer_correlation_id' => DogfoodTiming::correlation($customer->getKey()),
        ];
        $supportAvailable = false;

        try {
            $locale = (string) ($effect->locale ?: $customer->locale);
            $supportUrl = trim((string) ($this->supportContact->resolve()['url'] ?? ''));
            $supportAvailable = $supportUrl !== '';
            $replyMarkup = $supportAvailable
                ? [
                    'inline_keyboard' => [[[
                        'text' => $this->locale->translateLocale($locale, 'receipt.actions.support_button'),
                        'url' => $supportUrl,
                    ]]],
                ]
                : [];

            $response = $this->bot->sendMessage(
                (string) $effect->telegram_chat_id,
                $this->locale->translateLocale($locale, 'receipt.txt.generation_failed'),
                $replyMarkup,
            );
            $this->responseValidator->messageIdFromResponse($response, 'Telegram TXT failure notice');

            DogfoodTiming::log('dogfood.telegram.receipt_txt_failure_notice', $context + [
                'outcome' => 'sent',
                'support_available' => $supportAvailable,
            ]);
        } catch (Throwable $exception) {
            DogfoodTiming::log('dogfood.telegram.receipt_txt_failure_notice', $context + [
                'outcome' => 'failed',
                'support_available' => $supportAvailable,
                'exception' => $exception::class,
            ]);
        }
    }

    private function cleanupConfirmationButtons(TelegramOutboundEffect $effect): void
    {
        $metadata = is_array($effect->outcome_metadata) ? $effect->outcome_metadata : [];
        $originMessageId = $metadata['origin_message_id'] ?? null;
        if (is_string($originMessageId) && preg_match('/\A[1-9][0-9]{0,17}\z/D', $originMessageId) === 1) {
            $originMessageId = (int) $originMessageId;
        }
        if (! is_int($originMessageId) || $originMessageId < 1) {
            return;
        }

        $context = [
            'effect_correlation_id' => DogfoodTiming::correlation($effect->getKey()),
            'order_correlation_id' => DogfoodTiming::correlation($effect->source_id),
        ];

        try {
            $this->bot->editMessageReplyMarkup(
                (string) $effect->telegram_chat_id,
                $originMessageId,
                ['inline_keyboard' => []],
            );

            DogfoodTiming::log('dogfood.telegram.receipt_txt_confirmation_cleanup', $context + [
                'outcome' => 'sent',
            ]);
        } catch (Throwable $exception) {
            DogfoodTiming::log('dogfood.telegram.receipt_txt_confirmation_cleanup', $context + [
                'outcome' => 'failed',
                'exception' => $exception::class,
            ]);
        }
    }

    private function recordOutcome(
        ?TelegramOutboundEffect $effect,
        string $outcome,
        int $deliveryStartedAt,
    ): void {
        if ($effect === null) {
            return;
        }

        $this->observability->txtOutcome(
            $effect,
            $outcome,
            DogfoodTiming::elapsedMs($deliveryStartedAt),
            $effect->last_error_code,
        );
    }
}
