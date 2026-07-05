<?php

namespace App\Services\Telegram;

class TelegramWebhookIngressResult
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public readonly array $body,
        public readonly int $status = 200,
    ) {}
}
