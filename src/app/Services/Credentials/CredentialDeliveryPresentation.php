<?php

namespace App\Services\Credentials;

use App\Enums\CredentialAccessMethod;
use App\Enums\CredentialFormat;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ProductStockItem;
use App\Services\CredentialDeliveryFormatter;
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
        private readonly CredentialDeliveryEligibility $eligibility,
    ) {}

    public function forOrder(Order $order, ?Payment $payment = null, int $accountOffset = 0): CredentialDelivery
    {
        $order->loadMissing(['customer', 'payments', 'orderItems.stockItems.supplierAccountUnit', 'orderItems.product']);

        if ($this->eligibility->rejectionReason($order, payment: $payment) !== null) {
            return CredentialDelivery::empty();
        }

        return $this->project($order, $accountOffset);
    }

    /**
     * Project an eligible Order sequentially until the caller declines an account.
     * The declined account is the last one decrypted; later accounts are untouched.
     *
     * @param  callable(CredentialDeliveryAccount, ProductStockItem, array): bool  $include
     */
    public function forOrderUntil(Order $order, callable $include, int $accountOffset = 0): CredentialDelivery
    {
        $order->loadMissing(['customer', 'payments', 'orderItems.stockItems.supplierAccountUnit', 'orderItems.product']);

        if ($this->eligibility->rejectionReason($order) !== null) {
            return CredentialDelivery::empty();
        }

        return $this->project($order, $accountOffset, $include);
    }

    public function present(Order $order, ?Payment $payment = null): CredentialDelivery
    {
        return $this->forOrder($order, $payment);
    }

    public function forCustomer(Customer $customer, Order $order, ?Payment $payment = null, int $accountOffset = 0): CredentialDelivery
    {
        $order->loadMissing(['customer', 'payments', 'orderItems.stockItems.supplierAccountUnit', 'orderItems.product']);

        if ($this->eligibility->rejectionReason($order, $customer, $payment) !== null) {
            return CredentialDelivery::empty();
        }

        return $this->project($order, $accountOffset);
    }

    /**
     * Project one allocated account without exposing persistence parsing to
     * private per-account pages such as warranty replacement delivery.
     */
    public function forStockItem(Order $order, ProductStockItem $stockItem): ?CredentialDeliveryAccount
    {
        if ($this->eligibility->rejectionReason($order) !== null || $stockItem->retired_at !== null) {
            return null;
        }

        $order->loadMissing('orderItems.product');
        $item = $order->orderItems->firstWhere('id', $stockItem->order_item_id);
        if ($item === null) {
            return null;
        }

        try {
            return $this->account($stockItem, $stockItem->supplierAccountUnit?->unit_number ?? 1, $this->defaultTutorial($item));
        } catch (Throwable) {
            return null;
        }
    }

    private function project(Order $order, int $accountOffset = 0, ?callable $include = null): CredentialDelivery
    {
        try {
            $groups = [];
            $usageGuides = $this->usageGuides->forOrder($order);
            $stopped = false;
            foreach ($order->orderItems->sortBy('id')->values() as $item) {
                $accounts = [];
                foreach ($item->stockItems->sortBy('id')->values() as $index => $stockItem) {
                    if ($stockItem->retired_at !== null) {
                        continue;
                    }
                    $account = $this->account($stockItem, $stockItem->supplierAccountUnit?->unit_number ?? $accountOffset + $index + 1, $this->defaultTutorial($item));
                    if ($include !== null && ! $include($account, $stockItem, $usageGuides)) {
                        $stopped = true;
                        break;
                    }
                    $accounts[] = $account;
                }

                if ($accounts === []) {
                    if ($stopped) {
                        break;
                    }
                    continue;
                }

                $groups[] = new CredentialDeliveryProductGroup(
                    productName: (string) $item->product_name,
                    accounts: $accounts,
                    defaultTutorial: $this->defaultTutorial($item),
                    usageGuide: $this->usageGuides->forItem($item),
                    productIcon: $item->product_icon,
                    productCustomEmojiId: $item->product_custom_emoji_id,
                );
                if ($stopped) {
                    break;
                }
            }

            return new CredentialDelivery(
                groups: $groups,
                usageGuides: $usageGuides,
            );
        } catch (Throwable) {
            // A malformed persisted payload must never produce a partial
            // credential response. The caller can render its safe fallback.
            return CredentialDelivery::empty();
        }
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
                readerMailLink: $structured->readerMailLink,
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
            copyValuesIndividually: $parts !== null && $this->legacyFormatter->usesDashSeparator($secret),
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
