<?php

namespace App\Services\Sakurupiah;

use App\Models\ManualPaymentSetting;
use App\Models\Payment;
use App\Models\StoreBalanceTopUp;
use InvalidArgumentException;

final class SakurupiahChannelPolicy
{
    public const CHANNELS = ['QRIS', 'QRISC', 'QRISMU', 'QRIS2'];

    public static function supports(string $channel): bool
    {
        return in_array($channel, self::CHANNELS, true);
    }

    public static function selected(?ManualPaymentSetting $setting = null): string
    {
        $setting ??= ManualPaymentSetting::current();

        return self::validated((string) ($setting?->sakurupiah_channel ?? 'QRIS'));
    }

    public static function forPayment(Payment $payment): string
    {
        if ($payment->sakurupiah_channel !== null) {
            return self::validated((string) $payment->sakurupiah_channel);
        }

        $method = strtoupper(trim((string) $payment->provider_payment_method));

        return self::validated($method === '' ? 'QRISC' : $method);
    }

    public static function forTopUp(StoreBalanceTopUp $topUp): string
    {
        // Historical top-ups stored the local QRIS method, not the QRISC channel.
        return self::validated((string) ($topUp->sakurupiah_channel ?? 'QRISC'));
    }

    public static function expiryHours(string $channel): int
    {
        self::validated($channel);
        $hours = $channel === 'QRISMU'
            ? 12
            : config('services.sakurupiah.qris_contract.expiry_hours', 24);

        if (! is_int($hours) || $hours < 1) {
            throw new InvalidArgumentException('Sakurupiah channel expiry is not qualified.');
        }

        return $hours;
    }

    private static function validated(string $channel): string
    {
        if (! self::supports($channel)) {
            throw new InvalidArgumentException('Unsupported Sakurupiah QRIS channel.');
        }

        return $channel;
    }
}
