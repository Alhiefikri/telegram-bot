<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

final class StoreBalanceTopUpSettled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $topUpId,
    ) {}
}
