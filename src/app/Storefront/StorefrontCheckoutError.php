<?php

namespace App\Storefront;

use App\Exceptions\CheckoutPaymentAmountOutsideLimits;
use App\Exceptions\CheckoutPaymentUnavailable;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidCartState;
use App\Exceptions\InvalidCheckout;
use App\Exceptions\TelegramStockReserved;
use Throwable;

/** A customer-facing reason raised by the storefront, never raw domain copy. */
final class StorefrontCheckoutError extends InvalidCheckout
{
    public function __construct(private readonly string $copyKey, ?Throwable $previous = null)
    {
        parent::__construct(StorefrontLocale::text($copyKey), previous: $previous);
    }

    public static function message(InvalidCheckout|InvalidCartState|InsufficientStock $exception): string
    {
        return StorefrontLocale::text(match (true) {
            $exception instanceof self => $exception->copyKey,
            $exception instanceof TelegramStockReserved => 'telegram_stock_reserved',
            $exception instanceof InsufficientStock => 'insufficient_stock',
            $exception instanceof InvalidCartState => 'cart_changed',
            $exception instanceof CheckoutPaymentAmountOutsideLimits => 'qris_amount_outside_limits',
            $exception instanceof CheckoutPaymentUnavailable => 'payment_unavailable',
            default => 'checkout_retry',
        });
    }
}
