<?php

namespace App\Services;

enum CustomerCancellationOutcome
{
    case Cancelled;
    case Paid;
    case VerificationUnavailable;
    case NotCancellable;
}
