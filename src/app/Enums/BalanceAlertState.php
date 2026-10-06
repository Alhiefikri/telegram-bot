<?php

namespace App\Enums;

/** Lifecycle of one durable balance operations alert episode. */
enum BalanceAlertState: string
{
    case Open = 'open';
    case Resolved = 'resolved';
}
