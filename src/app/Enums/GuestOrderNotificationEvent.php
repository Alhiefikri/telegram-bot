<?php

namespace App\Enums;

enum GuestOrderNotificationEvent: string
{
    case OrderCreated = 'order_created';
    case PaymentConfirmed = 'payment_confirmed';
    case FulfillmentCompleted = 'fulfillment_completed';
    case OrderExpired = 'order_expired';
    case OrderCancelled = 'order_cancelled';
}
