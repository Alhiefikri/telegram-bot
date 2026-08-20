<?php

namespace App\Services\Credentials;

use App\Enums\CredentialAccessMethod;
use App\Enums\CredentialFormat;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ProductStockItem;
use App\Services\CredentialDeliveryFormatter;
use App\Services\OrderLifecycleProjection;
use App\Services\ProductUsageGuide;
use Throwable;

/**
 * The single paid credential delivery seam shared by Store Bot and Web
 * Storefront. Persistence formats and lifecycle checks do not leak into the
 * channel adapters.
 */
final class CredentialDeliveryPresentation
{
    public function __construct(
        private readonly CredentialDeliveryFormatter $legacyFormatter,
        private readonly ProductUsageGuide $usageGuides,
    ) {}

    public function forOrder(Order $order, ?Payment $payment = null): CredentialDelivery
    {
        $order->loadMissing(['customer', 'payments', 'orderItems.stockItems', 'orderItems.product']);
        $payment ??= $this->latestPayment($order);

        if (! $this->isEligible($order, $payment)) {
            return CredentialDelivery::empty();
        }

        try {
            $groups = [];
            foreach ($order->orderItems->sortBy('id')->values() as $item) {
                $accounts = [];
                foreach ($item->stockItems->sortBy('id')->values() as $index => $stockItem) {
                    $accounts[] = $this->account($stockItem, $index + 1, $this->defaultTutorial($item));
                }

                if ($accounts === []) {
                    continue;
                }

                $groups[] = new CredentialDeliveryProductGroup(
                    productName: (string) $item->product_name,
                    accounts: $accounts,
                    defaultTutorial: $this->defaultTutorial($item),
                    usageGuide: $this->usageGuides->forItem($item),
                );
            }

            return new CredentialDelivery(
                groups: $groups,
                usageGuides: $this->usageGuides->forOrder($order),
            );
        } catch (Throwable) {
            // A malformed persisted payload must never produce a partial
            // credential response. The caller can render its safe fallback.
            return CredentialDelivery::empty();
        }
    }

    public function present(Order $order, ?Payment $payment = null): CredentialDelivery
    {
        return $this->forOrder($order, $payment);
    }

    public function forCustomer(Customer $customer, Order $order, ?Payment $payment = null): CredentialDelivery
    {
        if ((string) $customer->getKey() !== (string) $order->customer_id) {
            return CredentialDelivery::empty();
        }

        return $this->forOrder($order, $payment);
    }

    private function latestPayment(Order $order): ?Payment
    {
        return $order->payments
            ->sortByDesc(fn (Payment $payment): array => [
                $payment->created_at?->getTimestamp() ?? 0,
                (string) $payment->getKey(),
            ])
            ->first();
    }

    private function isEligible(Order $order, ?Payment $payment): bool
    {
        if ($payment === null
            || (string) $payment->order_id !== (string) $order->getKey()
            || $payment->status !== PaymentStatus::Paid
            || $order->payment_status !== PaymentStatus::Paid
            || $order->hasOpenLatePaymentReview()) {
            return false;
        }

        return OrderLifecycleProjection::from($order, $payment)->isPaid;
    }

    private function account(ProductStockItem $stockItem, int $number, ?string $defaultTutorial): CredentialDeliveryAccount
    {
        $format = $this->format($stockItem);
        if ($format === CredentialFormat::StructuredAccount) {
            $payload = $stockItem->credential_payload;
            if (! is_array($payload)) {
                throw new \InvalidArgumentException('Structured credential payload is invalid.');
            }

            $structured = StructuredCredentialPayload::fromArray($payload);
            $tutorial = $structured->tutorialOverride !== null && trim($structured->tutorialOverride) !== ''
                ? $structured->tutorialOverride
                : $defaultTutorial;

            return new CredentialDeliveryAccount(
                number: $number,
                legacy: false,
                text: $this->structuredText($structured),
                legacyParts: null,
                email: $structured->email,
                password: $structured->password,
                accessMethod: $structured->accessMethod,
                accessValue: $structured->accessValue,
                tutorial: $tutorial,
            );
        }

        $secret = $stockItem->secret;
        if (! is_string($secret) || $secret === '') {
            throw new \InvalidArgumentException('Legacy credential secret is invalid.');
        }

        $parts = $this->legacyFormatter->parts($secret);

        return new CredentialDeliveryAccount(
            number: $number,
            legacy: true,
            text: $this->legacyFormatter->format($secret),
            legacyParts: $parts,
            email: $parts['email'] ?? null,
            password: $parts['password'] ?? null,
            accessMethod: null,
            accessValue: null,
            tutorial: $defaultTutorial,
        );
    }

    private function format(ProductStockItem $stockItem): CredentialFormat
    {
        $format = $stockItem->getAttribute('credential_format');
        if ($format === null) {
            return CredentialFormat::Legacy;
        }

        return $format instanceof CredentialFormat
            ? $format
            : CredentialFormat::from((string) $format);
    }

    private function defaultTutorial(OrderItem $item): ?string
    {
        $tutorial = trim((string) ($item->delivery_note_snapshotted
            ? $item->delivery_note
            : $item->product?->delivery_note));

        return $tutorial === '' ? null : $tutorial;
    }

    private function structuredText(StructuredCredentialPayload $payload): string
    {
        $lines = [
            'email : '.$payload->email,
            'password : '.$payload->password,
        ];

        if ($payload->accessMethod === CredentialAccessMethod::AuthToken && $payload->accessValue !== null) {
            $lines[] = 'Auth Token : '.$payload->accessValue;
        }

        return implode("\n", $lines);
    }
}
