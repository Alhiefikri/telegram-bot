<?php

namespace App\Services\Sakurupiah;

/**
 * The single source of truth for the Sakurupiah QRIS fee contract.
 *
 * The configured `fee_payer` and the provider request field `merchant_fee` are
 * one decision expressed twice: `merchant` means the store absorbs the channel
 * fee (the customer pays the order amount), `customer` means the provider adds
 * the fee on top of the order amount and the customer pays the final total.
 * Every gate that qualifies the contract reads that rule from here so the
 * readiness check, the production configuration validator, and the create
 * request can never disagree.
 *
 * The rule is code configuration, not ENV: it is pinned in
 * `services.sakurupiah.qris_contract` and reviewed like any other release
 * constant.
 */
final class SakurupiahQrisContract
{
    public const FEE_PAYER_MERCHANT = 'merchant';

    public const FEE_PAYER_CUSTOMER = 'customer';

    /**
     * @param  array<mixed>  $contract
     */
    public static function currencyIsQualified(array $contract): bool
    {
        return ($contract['currency'] ?? null) === 'IDR'
            && ($contract['currency_confirmed'] ?? false) === true;
    }

    /**
     * @param  array<mixed>  $contract
     */
    public static function feeRuleIsQualified(array $contract): bool
    {
        $feePayer = $contract['fee_payer'] ?? null;
        $merchantFee = $contract['merchant_fee'] ?? null;

        if (! in_array($feePayer, [self::FEE_PAYER_MERCHANT, self::FEE_PAYER_CUSTOMER], true)) {
            return false;
        }

        if ($feePayer === self::FEE_PAYER_MERCHANT && $merchantFee !== 1) {
            return false;
        }

        if ($feePayer === self::FEE_PAYER_CUSTOMER && $merchantFee !== 2) {
            return false;
        }

        return ($contract['fee_confirmed'] ?? false) === true;
    }

    /**
     * @param  array<mixed>  $contract
     */
    public static function expiryIsQualified(array $contract): bool
    {
        $expiryHours = $contract['expiry_hours'] ?? null;

        return ($contract['expiry_confirmed'] ?? false) === true
            && is_int($expiryHours)
            && $expiryHours >= 1;
    }

    /**
     * Whether the configured contract puts the channel fee on the customer.
     *
     * @param  array<mixed>  $contract
     */
    public static function customerPaysFee(array $contract): bool
    {
        return ($contract['fee_payer'] ?? null) === self::FEE_PAYER_CUSTOMER;
    }

    /**
     * The configured QRIS contract.
     *
     * @return array<mixed>
     */
    public static function configured(): array
    {
        $contract = config('services.sakurupiah.qris_contract', []);

        return is_array($contract) ? $contract : [];
    }
}
