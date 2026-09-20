<?php

namespace App\Services\Telegram;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use App\Services\CartService;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use App\Services\Pricing\ProductPriceQuote;
use App\Services\Pricing\ProductQuantityPricing;
use Throwable;

class TelegramCartIntents
{
    public function __construct(
        private TelegramBotClient $bot,
        private CartService $carts,
        private TelegramLocale $locale,
        private CheckoutMaintenance $maintenance,
        private ProductQuantityPricing $pricing,
    ) {}

    public function addProductToCart(Customer $customer, string $chatId, int $productId, int $quantity, bool $forceConfirmation = false, bool $sendConfirmation = true): bool
    {
        if ($quantity < 1) {
            $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'cart.quantity_invalid'));

            return false;
        }

        $cart = null;

        try {
            $product = Product::query()
                ->withAvailability()
                ->publicVisible()
                ->whereKey($productId)
                ->firstOrFail();
            if ($product->availableStock() < $quantity) {
                $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'cart.stock_conflict'));

                return false;
            }

            $quote = $this->pricing->quote($product, $quantity);
            $cart = $this->carts->activeCartFor($customer);
            $existingItem = $cart->cartItems()->where('product_id', $product->id)->first();
            if (! $forceConfirmation && $existingItem && (int) $existingItem->quantity === $quantity) {
                return true;
            }

            $this->carts->setItemQuantity($cart, $product, $quantity);
            $maintenance = $this->maintenance->current();
            $checkoutMaintenanceActive = $maintenance->active;
            if (! $sendConfirmation) {
                return true;
            }

            $message = $checkoutMaintenanceActive
                ? $this->locale->translate($customer, 'cart.added_maintenance', [
                    'product' => $product->name,
                    'quantity' => $quantity,
                    'subtotal' => number_format($quote->idrSubtotal, 0, ',', '.'),
                ]).$this->locale->checkoutMaintenanceNote($customer, $maintenance->publicNote)
                : $this->locale->translate($customer, 'cart.added', [
                    'product' => $product->name,
                    'quantity' => $quantity,
                    'subtotal' => number_format($quote->idrSubtotal, 0, ',', '.'),
                ]);
            $message .= "\n\n".$this->pricingSummary($customer, $product, $quote);
            $this->bot->sendMessage($chatId, $message, [
                'inline_keyboard' => [[
                    [
                        'text' => $checkoutMaintenanceActive
                            ? $this->locale->translate($customer, 'cart.status_button')
                            : $this->locale->translate($customer, 'cart.checkout_button'),
                        'callback_data' => $checkoutMaintenanceActive ? 'checkout-maintenance:status' : 'checkout',
                    ],
                ]],
            ]);

            return true;
        } catch (Throwable) {
            $this->deleteFreshEmptyCart($cart);
            $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'cart.stock_conflict'));

            return false;
        }
    }

    private function pricingSummary(Customer $customer, Product $product, ProductPriceQuote $quote): string
    {
        $availableStock = max(0, $product->availableStock());
        $showUsdtPricing = $quote->usdtSubtotal !== null;
        $lines = [
            $this->locale->translate(
                $customer,
                $showUsdtPricing ? 'cart.price_summary_dual' : 'cart.price_summary',
                [
                    'base' => number_format($quote->baseIdrUnitAmount, 0, ',', '.'),
                    'unit' => number_format($quote->effectiveIdrUnitAmount, 0, ',', '.'),
                    ...($showUsdtPricing ? [
                        'base_usdt' => $this->locale->usdt((int) $quote->baseUsdtUnitAmount),
                        'unit_usdt' => $this->locale->usdt((int) $quote->effectiveUsdtUnitAmount),
                    ] : []),
                ],
            ),
        ];
        foreach ($quote->tiers() as $tier) {
            if ($tier->minimumQuantity > $availableStock) {
                continue;
            }
            $active = $quote->appliedTierMinimum === $tier->minimumQuantity;
            $key = match ([$showUsdtPricing, $active]) {
                [true, true] => 'cart.tier_applied_dual',
                [true, false] => 'cart.tier_dual',
                [false, true] => 'cart.tier_applied',
                default => 'cart.tier',
            };
            $lines[] = $this->locale->translate($customer, $key, [
                'minimum' => $tier->minimumQuantity,
                'amount' => number_format($tier->priceAmount, 0, ',', '.'),
                ...($showUsdtPricing ? ['usdt' => $this->locale->usdt((int) $tier->usdtPriceAmount)] : []),
            ]);
        }
        if ($quote->nextTier !== null
            && $quote->nextTier->minimumQuantity <= $availableStock) {
            $nextTierQuantity = $quote->nextTier->minimumQuantity;
            try {
                $nextTierQuote = $this->pricing->quote($product, $nextTierQuantity);
            } catch (Throwable) {
                $nextTierQuote = null;
            }
            if ($nextTierQuote !== null) {
                $lines[] = $this->locale->translate($customer, 'cart.next_tier', [
                    'quantity' => $nextTierQuantity - $quote->quantity,
                ]);
                $lines[] = $this->locale->translate(
                    $customer,
                    $showUsdtPricing ? 'cart.next_tier_total_dual' : 'cart.next_tier_total',
                    [
                        'quantity' => $nextTierQuantity,
                        'amount' => number_format($nextTierQuote->idrSubtotal, 0, ',', '.'),
                        ...($showUsdtPricing ? ['usdt' => $this->locale->usdt((int) $nextTierQuote->usdtSubtotal)] : []),
                    ],
                );
            }
        }
        $usdtSavings = $quote->usdtSavings();
        if ($quote->idrSavings() > 0 && $usdtSavings !== null && $usdtSavings > 0) {
            $lines[] = $this->locale->translate($customer, 'cart.savings_dual', [
                'idr' => number_format($quote->idrSavings(), 0, ',', '.'),
                'usdt' => $this->locale->usdt($usdtSavings),
            ]);
        } elseif ($quote->idrSavings() > 0) {
            $lines[] = $this->locale->translate($customer, 'cart.savings', [
                'amount' => number_format($quote->idrSavings(), 0, ',', '.'),
            ]);
        } elseif ($usdtSavings !== null && $usdtSavings > 0) {
            $lines[] = $this->locale->translate($customer, 'cart.savings_usdt', [
                'amount' => $this->locale->usdt($usdtSavings),
            ]);
        }

        return implode("\n", $lines);
    }

    private function deleteFreshEmptyCart(?Cart $cart): void
    {
        if ($cart?->wasRecentlyCreated && ! $cart->cartItems()->exists()) {
            $cart->delete();
        }
    }
}
