<?php

namespace Tests\Unit\Services\Binance;

use App\Services\Binance\BinanceOrderId;
use App\Services\Binance\BinancePayTransaction;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('invalidTransactions')]
    public function test_malformed_history_cannot_be_used_as_a_transaction(mixed $payload): void
    {
        $this->assertNull(BinancePayTransaction::fromPayload($payload));
    }

    public static function invalidTransactions(): iterable
    {
        yield 'null history' => [null];
        yield 'string history' => ['transaction'];
        $valid = ['transactionId' => 'TX12345678', 'amount' => '1.5', 'currency' => 'USDT', 'transactionTime' => 1700000000000];
        foreach (['transactionId', 'amount', 'currency', 'transactionTime'] as $field) {
            $payload = $valid;
            unset($payload[$field]);
            yield 'missing '.$field => [$payload];
            yield 'nested '.$field => [array_replace($valid, [$field => ['invalid']])];
        }
        foreach (['1.0000001', '0.0000001', '9223372036854.775808', '1e3', 'NaN', '+1'] as $amount) {
            yield 'invalid amount '.$amount => [array_replace($valid, ['amount' => $amount])];
        }
        yield 'invalid timestamp' => [array_replace($valid, ['transactionTime' => 'yesterday'])];
    }

    #[DataProvider('unsuccessfulStatuses')]
    public function test_pending_failed_or_unknown_status_is_not_successful(string $status): void
    {
        $transaction = BinancePayTransaction::fromPayload($this->payload(['status' => $status]));
        $this->assertNotNull($transaction);
        $this->assertFalse($transaction->successful());
    }

    public static function unsuccessfulStatuses(): iterable
    {
        foreach (['PENDING', 'FAILED', 'CANCELLED', 'REFUNDED', 'PROCESSING', 'UNKNOWN'] as $status) {
            yield $status => [$status];
        }
    }

    public function test_debit_zero_and_missing_receiver_never_match_the_receiving_account(): void
    {
        foreach ([
            ['amount' => '-1.5'],
            ['amount' => '0'],
            ['receiverInfo' => []],
            ['receiverInfo' => ['accountId' => 'other', 'binanceId' => 'other']],
        ] as $override) {
            $transaction = BinancePayTransaction::fromPayload($this->payload($override));
            $this->assertNotNull($transaction);
            $this->assertFalse($transaction->isIncomingToConfiguredReceiver('987654321', '123456789'));
        }
    }

    public function test_explicit_account_id_mismatch_does_not_fall_back_to_uid_as_pay_id(): void
    {
        $transaction = BinancePayTransaction::fromPayload($this->payload([
            'receiverInfo' => ['accountId' => 'wrong-pay-id', 'binanceId' => '987654321'],
        ]));
        $this->assertFalse($transaction->isIncomingToConfiguredReceiver('987654321', null));
    }

    public function test_provider_transaction_status_is_used_when_primary_status_is_absent(): void
    {
        $failed = BinancePayTransaction::fromPayload($this->payload(['transactionStatus' => 'FAILED']));
        $paid = BinancePayTransaction::fromPayload($this->payload(['transactionStatus' => 'completed']));
        $this->assertFalse($failed->successful());
        $this->assertTrue($paid->successful());
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
