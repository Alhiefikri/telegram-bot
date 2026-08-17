<?php

namespace App\Enums;

enum TelegramOutboundEffectState: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Unknown = 'unknown';
    case ManualReview = 'manual_review';
}
