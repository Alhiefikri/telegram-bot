<?php

namespace App\Services\Telegram;

use App\Models\SalesProofPost;

class SalesProofPostNumbering
{
    public function nextNumber(): int
    {
        return $this->sentCount() + 1;
    }

    public function sentCount(): int
    {
        return (int) SalesProofPost::query()->where('status', SalesProofPost::STATUS_SENT)->count();
    }
}
