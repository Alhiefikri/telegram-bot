<?php

namespace App\Services\StoreBalance;

use App\Models\StoreBalanceFundingPolicy;
use App\Services\KlikQris\KlikQrisAmount;
use InvalidArgumentException;
use UnexpectedValueException;

final readonly class StoreBalanceFundingPolicyDefinition
{
    /** @param list<int> $presetAmounts */
    private function __construct(
        public int $minimumAmount,
        public array $presetAmounts,
    ) {}

    public static function from(mixed $minimumAmount, mixed $presetAmounts): self
    {
        $errors = self::validationErrors($minimumAmount, $presetAmounts);
        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', array_values($errors)));
        }

        $minimum = self::parseAmount($minimumAmount);
        $presets = array_map(
            static fn (mixed $amount): int => self::parseAmount($amount) ?? 0,
            $presetAmounts,
        );

        return new self($minimum ?? 0, array_values($presets));
    }

    /**
     * Parse the strict whole-IDR amount accepted by both Admin policy updates
     * and future customer funding commands.
     */
    public static function parseAmount(mixed $value): ?int
    {
        if (is_int($value)) {
            $raw = (string) $value;
        } elseif (is_string($value)) {
            $raw = trim($value);
        } else {
            return null;
        }

        if (preg_match('/\A[1-9][0-9]*\z/D', $raw) !== 1) {
            return null;
        }

        try {
            return KlikQrisAmount::from($raw)->rupiah();
        } catch (UnexpectedValueException) {
            return null;
        }
    }

    /** @return array<string, string> */
    public static function validationErrors(mixed $minimumAmount, mixed $presetAmounts): array
    {
        $errors = [];
        $minimum = self::parseAmount($minimumAmount);
        if ($minimum === null) {
            $errors['minimum_amount'] = 'Minimum top-up harus berupa bilangan bulat rupiah positif dalam batas KlikQRIS.';
        }

        if (! is_array($presetAmounts) || ! array_is_list($presetAmounts)) {
            $errors['preset_amounts'] = 'Daftar preset top-up harus berupa daftar nominal.';

            return $errors;
        }

        if (count($presetAmounts) < 1) {
            $errors['preset_amounts'] = 'Daftar preset top-up harus memiliki sedikitnya satu nominal.';
        } elseif (count($presetAmounts) > StoreBalanceFundingPolicy::MAX_PRESETS) {
            $errors['preset_amounts'] = 'Daftar preset top-up maksimal '.StoreBalanceFundingPolicy::MAX_PRESETS.' nominal.';
        }

        $parsedPresets = [];
        foreach ($presetAmounts as $index => $amount) {
            $parsed = self::parseAmount($amount);
            if ($parsed === null) {
                $message = 'Setiap preset top-up harus berupa bilangan bulat rupiah positif dalam batas KlikQRIS.';
                $errors['preset_amounts.'.$index] = $message;
                $errors['preset_amounts'] = $message;

                continue;
            }

            $parsedPresets[] = $parsed;
        }

        if (count($parsedPresets) !== count($presetAmounts)) {
            return $errors;
        }

        for ($index = 1, $count = count($parsedPresets); $index < $count; $index++) {
            if ($parsedPresets[$index] <= $parsedPresets[$index - 1]) {
                $errors['preset_amounts'] = 'Preset top-up harus unik dan diurutkan dari nominal terkecil ke terbesar.';
                break;
            }
        }

        if ($minimum !== null && ($parsedPresets[0] ?? 0) < $minimum) {
            $errors['preset_amounts'] = 'Setiap preset top-up harus sama dengan atau di atas minimum top-up.';
        }

        return $errors;
    }
}
