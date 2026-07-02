<?php

namespace App\Support;

class AdminDogfoodHttpResult
{
    public function __construct(
        public readonly array $statuses,
        public readonly array $headers,
        public readonly string $body,
    ) {}

    public function statusSummary(): string
    {
        return implode('->', $this->statuses);
    }

    public function finalStatus(): ?int
    {
        $status = $this->statuses[array_key_last($this->statuses)] ?? null;

        return is_int($status) ? $status : null;
    }

    public function toScenarioResult(): array
    {
        return [
            'status' => $this->statusSummary(),
            'final_status' => $this->finalStatus(),
        ];
    }
}
