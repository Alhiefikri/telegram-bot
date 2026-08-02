<?php

namespace Tests\Unit\Services\Binance;

use App\Services\Binance\BinanceOrderId;
use App\Services\Binance\BinancePayTransaction;
use PHPUnit\Framework\TestCase;

class BinanceVerificationContractTest extends TestCase
{
    public function test_order_id_input_is_trimmed_and_accepts_provider_safe_identifiers(): void
    {
        $this->assertSame('445011487581044736', BinanceOrderId::normalize(' 445011487581044736 '));
        $this->assertNull(BinanceOrderId::normalize('445011 487581044736'));
        $this->assertNull(BinanceOrderId::normalize('445011487581044736<script>'));
        $this->assertNull(BinanceOrderId::normalize('123'));
        $this->assertSame('P_386362580992', BinanceOrderId::normalize('P_386362580992'));
        $this->assertNull(BinanceOrderId::normalize(str_repeat('4', 129)));
    }

    public function test_transaction_identifier_matches_order_id_first_and_transaction_id_as_compatibility_alias(): void
    {
        $transaction = BinancePayTransaction::fromPayload($this->payload([
            'orderId' => '445011487581044736',
            'transactionId' => 'P_TRANSACTION',
        ]));

        $this->assertNotNull($transaction);
        $this->assertSame('order_id', $transaction->matchesSubmittedOrderId('445011487581044736'));
        $this->assertSame('transaction_id_compatibility', $transaction->matchesSubmittedOrderId('P_TRANSACTION'));
        $this->assertFalse($transaction->matchesSubmittedOrderId('P_OTHER'));
    }

    public function test_receiver_account_id_and_binance_uid_are_separate_namespaces(): void
    {
        $account = BinancePayTransaction::fromPayload($this->payload([
            'receiverInfo' => ['accountId' => '987654321', 'binanceId' => 'other-uid'],
        ]));
        $uid = BinancePayTransaction::fromPayload($this->payload([
            'receiverInfo' => ['binanceId' => '123456789'],
        ]));

        $this->assertTrue($account?->isIncomingToConfiguredReceiver('987654321', null));
        $this->assertFalse($account?->isIncomingToConfiguredReceiver(null, '123456789'));
        $this->assertTrue($uid?->isIncomingToConfiguredReceiver(null, '123456789'));
        $this->assertFalse($uid?->isIncomingToConfiguredReceiver('987654321', null));

        $both = BinancePayTransaction::fromPayload($this->payload([
            'receiverInfo' => ['accountId' => 'wrong-account', 'binanceId' => '123456789'],
        ]));
        $this->assertTrue($both?->isIncomingToConfiguredReceiver('987654321', '123456789'));
    }

    public function test_pay_id_receiver_accepts_the_binance_id_shape_used_by_c2c_history(): void
    {
        $transaction = BinancePayTransaction::fromPayload($this->payload([
            'receiverInfo' => ['binanceId' => '987654321'],
        ]));

        $this->assertTrue($transaction?->isIncomingToConfiguredReceiver('987654321', null));
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        $payload = array_replace_recursive([
            'orderId' => '445011487581044736',
            'transactionId' => 'P_TRANSACTION',
            'amount' => '5',
            'currency' => 'USDT',
            'transactionTime' => 1_700_000_000_000,
            'receiverInfo' => ['accountId' => '987654321'],
        ], $overrides);

        if (array_key_exists('receiverInfo', $overrides)) {
            $payload['receiverInfo'] = $overrides['receiverInfo'];
        }

        return $payload;
    }
}
