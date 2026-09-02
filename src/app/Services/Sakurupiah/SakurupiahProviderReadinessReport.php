<?php

namespace App\Services\Sakurupiah;

final readonly class SakurupiahProviderReadinessReport
{
    /**
     * @param  list<string>  $missing
     * @param  array<string, string>  $invalid
     */
    public function __construct(
        private array $missing,
        private array $invalid,
    ) {}

    public function isReady(): bool
    {
        return $this->missing === [] && $this->invalid === [];
    }

    /** @return list<string> */
    public function missing(): array
    {
        return $this->missing;
    }

    /** @return array<string, string> */
    public function invalid(): array
    {
        return $this->invalid;
    }

    /** @return list<string> */
    public function problemLabels(): array
    {
        return array_values(array_merge($this->missing, array_keys($this->invalid)));
    }

    public function configurationMessage(): string
    {
        $messages = [];
        if ($this->missing !== []) {
            $messages[] = 'Configure '.$this->joinLabels($this->missing).' for the provider environment.';
        }

        foreach ($this->invalid as $message) {
            $messages[] = $message;
        }

        return implode(' ', $messages);
    }

    public function assertReady(string $message = 'Sakurupiah provider is not ready.'): void
    {
        if ($this->isReady()) {
            return;
        }

        throw new SakurupiahReadinessException(trim($message.' '.$this->configurationMessage()));
    }

    /** @param list<string> $labels */
    private function joinLabels(array $labels): string
    {
        return match (count($labels)) {
            0 => '',
            1 => $labels[0],
            2 => $labels[0].' and '.$labels[1],
            default => implode(', ', array_slice($labels, 0, -1)).', and '.$labels[array_key_last($labels)],
        };
    }
}
