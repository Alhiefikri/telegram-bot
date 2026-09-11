<?php

namespace App\Services\Telegram;

enum StoreBalanceTopUpOutboundOutcome: string
{
    case NotEligible = 'not_eligible';
    case Queued = 'queued';
    case Delivered = 'delivered';
    case InProgress = 'in_progress';
    case RetryScheduled = 'retry_scheduled';
    case ReviewRequired = 'review_required';
    case Failed = 'failed';
}
