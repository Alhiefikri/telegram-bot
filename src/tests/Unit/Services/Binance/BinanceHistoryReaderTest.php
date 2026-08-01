<?php

namespace Tests\Unit\Services\Binance;

use App\Services\Binance\BinancePayClient;
use App\Services\Binance\BinanceTransactionHistoryReader;
use Mockery;
use Tests\TestCase;

class BinanceHistoryReaderTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_saturated_history_windows_are_split_until_each_window_is_bounded(): void
    {
        $client = Mockery::mock(BinancePayClient::class);
        $client->shouldReceive('payTransactions')->times(3)->andReturnUsing(function (int $start, ?int $end, int $limit): array {
            if ($start === 0 && $end === 100) {
                return array_fill(0, 100, ['transactionId' => 'root']);
            }

            return [['transactionId' => $start === 0 ? 'left' : 'right']];
        });

        $result = (new BinanceTransactionHistoryReader($client, maxPages: 8, maxWindowSplits: 4))->read(0, 100);

        $this->assertTrue($result->complete);
        $this->assertCount(3, $result->transactions);
        $this->assertSame(3, $result->requests);
    }

    public function test_budget_exhaustion_is_inconclusive_instead_of_becoming_a_false_negative(): void
    {
        $client = Mockery::mock(BinancePayClient::class);
        $client->shouldReceive('payTransactions')->once()->andReturn(array_fill(0, 100, ['transactionId' => 'root']));

        $result = (new BinanceTransactionHistoryReader($client, maxPages: 1, maxWindowSplits: 1))->read(0, 100);

        $this->assertFalse($result->complete);
        $this->assertSame(1, $result->requests);
    }
}
