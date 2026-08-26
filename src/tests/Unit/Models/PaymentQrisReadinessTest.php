<?php

namespace Tests\Unit\Models;

use App\Models\Payment;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaymentQrisReadinessTest extends TestCase
{

    #[DataProvider('klikQrisProviderAmountDisplays')]
    public function test_klikqris_total_amount_display_uses_exact_validated_provider_representation(mixed $providerValue, string $expectedDisplay): void
    {
        $payment = new Payment([
            'provider' => 'klikqris',
            'provider_total_payment' => 1016,
            'provider_payload' => ['data' => ['total_amount' => $providerValue]],
        ]);

        $this->assertSame($expectedDisplay, $payment->klikQrisTotalAmountDisplay());
    }

    public static function klikQrisProviderAmountDisplays(): array
    {
        return [
            'integer' => [1016, 'IDR 1016'],
            'integer string' => ['1016', 'IDR 1016'],
            'one zero decimal' => ['1016.0', 'IDR 1016.0'],
            'two zero decimals' => ['1016.00', 'IDR 1016.00'],
        ];
    }

    #[DataProvider('unavailableKlikQrisProviderAmounts')]
    public function test_klikqris_total_amount_display_is_unavailable_for_missing_invalid_or_mismatched_persisted_data(array $attributes): void
    {
        $rawAttributes = array_merge([
            'provider' => 'klikqris',
            'provider_total_payment' => 1016,
        ], $attributes);
        if (is_array($rawAttributes['provider_payload'] ?? null)) {
            $rawAttributes['provider_payload'] = json_encode(
                $rawAttributes['provider_payload'],
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
            );
        }

        $payment = new Payment;
        $payment->setRawAttributes($rawAttributes);

        $this->assertNull($payment->klikQrisTotalAmountDisplay());
    }

    public static function unavailableKlikQrisProviderAmounts(): array
    {
        return [
            'missing provider total' => [[
                'provider_total_payment' => null,
                'provider_payload' => ['data' => ['total_amount' => '1016.00']],
            ]],
            'malformed data envelope' => [['provider_payload' => [
                'data' => 'invalid',
                'total_amount' => '1016.00',
            ]]],
            'missing payload' => [['provider_payload' => null]],
            'missing persisted total amount' => [['provider_payload' => ['data' => []]]],
            'nonzero decimal' => [['provider_payload' => ['data' => ['total_amount' => '1016.01']]]],
            'float' => [['provider_payload' => ['data' => ['total_amount' => 1016.0]]]],
            'overflow' => [['provider_payload' => ['data' => ['total_amount' => (string) PHP_INT_MAX.'0']]]],
            'rupiah mismatch' => [['provider_payload' => ['data' => ['total_amount' => '1017.00']]]],
        ];
    }

}
