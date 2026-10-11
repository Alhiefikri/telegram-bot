<?php

namespace App\Enums;

/**
 * Durable order-email handoff types. Each type is owned by exactly one
 * delivery service: the milestone mailer owns the informational events and
 * OrderCredentialMailService owns CredentialsReady, which carries the paid
 * account data and therefore has its own retry/lease semantics.
 */
enum GuestOrderNotificationEvent: string
{
    case OrderCreated = 'order_created';
    case PaymentConfirmed = 'payment_confirmed';
    case FulfillmentCompleted = 'fulfillment_completed';
    case OrderExpired = 'order_expired';
    case OrderCancelled = 'order_cancelled';
    case CredentialsReady = 'credentials_ready';
}
