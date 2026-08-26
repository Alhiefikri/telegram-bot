<?php

namespace App\Services\Pakasir;

use RuntimeException;

final readonly class PakasirProviderReadinessReport
{
    /**
     * @param  list<string>  $missing
     * @param  array<string, string>  $invalid
     */
    public function __construct(
        private string $checkoutFlow,
        private array $missing,
        private array $invalid,
    ) {}

    public function isReady(): bool
    {
        return $this->missing === [] && $this->invalid === [];
    }

    public function checkoutFlow(): string
    {
        return $this->checkoutFlow;
    }

    /**
     * @return list<string>
     */
    public function missing(): array
    {
        return $this->missing;
    }

    /**
     * @return array<string, string>
     */
    public function invalid(): array
    {
        return $this->invalid;
    }

    public function isMissing(string $label): bool
    {
        return in_array($label, $this->missing, true);
    }

    public function invalidMessage(string $label): ?string
    {
        return $this->invalid[$label] ?? null;
    }

    /**
     * @return list<string>
     */
    public function problemLabels(): array
    {
        return array_values(array_merge($this->missing, array_keys($this->invalid)));
    }

    public function configurationMessage(): string
    {
        $messages = [];

        if ($this->missing !== []) {
            $messages[] = 'Lengkapi '.$this->joinLabels($this->missing).' di environment.';
        }

        foreach ($this->invalid as $message) {
            $messages[] = $message;
        }

        return implode(' ', $messages);
    }

    public function assertReady(string $message = 'Pakasir provider is not ready.'): void
    {
        if ($this->isReady()) {
            return;
        }

        throw new RuntimeException(trim($message.' '.$this->configurationMessage()));
    }

    /**
     * @param  list<string>  $labels
     */
    private function joinLabels(array $labels): string
    {
        $count = count($labels);

        return match ($count) {
            0 => '',
            1 => $labels[0],
            2 => $labels[0].' dan '.$labels[1],
            default => implode(', ', array_slice($labels, 0, -1)).', dan '.$labels[$count - 1],
        };
    }
}
