<?php

namespace App\Services\StoreBalance;

final class StoreBalanceFundingAmountValidator
{
    public const MAX_COMMAND_LENGTH = 128;

    public function validate(mixed $rawAmount, StoreBalanceFundingPolicyState $policy): StoreBalanceFundingAmountValidation
    {
        if (is_string($rawAmount) && strlen($rawAmount) > self::MAX_COMMAND_LENGTH) {
            return new StoreBalanceFundingAmountValidation(null, 'invalid');
        }

        $amount = StoreBalanceFundingPolicyDefinition::parseAmount($rawAmount);
        if ($amount === null) {
            return new StoreBalanceFundingAmountValidation(null, 'invalid');
        }

        if ($amount < $policy->minimumAmount) {
            return new StoreBalanceFundingAmountValidation(null, 'below_minimum');
        }

        return new StoreBalanceFundingAmountValidation($amount, null);
    }

    public function isDepositCommand(string $text): bool
    {
        if (strlen($text) > self::MAX_COMMAND_LENGTH) {
            return preg_match('/\A\/deposit[ \t]/D', substr($text, 0, 10)) === 1;
        }

        return preg_match('/\A\/deposit(?:[ \t]|\z)/D', trim($text)) === 1;
    }

    public function customAmountToken(string $text): ?string
    {
        if (! $this->isDepositCommand($text)) {
            return null;
        }

        if (strlen($text) > self::MAX_COMMAND_LENGTH) {
            return '';
        }

        $normalized = trim($text);
        if ($normalized === '/deposit') {
            return '';
        }

        $token = preg_replace('/\A\/deposit[ \t]+/D', '', $normalized);

        return is_string($token) ? trim($token) : '';
    }
}
