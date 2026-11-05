<?php

namespace Tests\Unit\Services\Binance;

use App\Services\Binance\BinancePayClient;
use App\Services\Binance\BinanceProviderException;
use App\Services\Binance\BinanceTransactionHistoryReader;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BinanceHistoryResponseIntegrityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.binance.api_key', 'test-key');
        config()->set('services.binance.api_secret', 'test-secret');
        config()->set('services.binance.base_url', 'https://api.binance.test');
        config()->set('services.binance.history_cache_seconds', 20);
        config()->set('cache.default', 'array');
    }

    public function test_a_valid_full_page_is_split_into_smaller_history_windows(): void
    {
        Http::fake(['*' => function ($request) {
            $page = (int) $request['startTime'] === 0 && (int) $request['endTime'] === 100
                ? array_fill(0, 100, ['transactionId' => 'root'])
                : [['transactionId' => (int) $request['startTime'] === 0 ? 'left' : 'right']];

            return Http::response(['code' => '000000', 'success' => true, 'data' => $page]);
        }]);

        $result = (new BinanceTransactionHistoryReader(new BinancePayClient))->read(0, 100);

        $this->assertTrue($result->complete);
        $this->assertSame(3, $result->requests);
        $this->assertSame(['root', 'left', 'right'], array_column($result->transactions, 'transactionId'));
    }

    public function test_a_full_page_with_a_missing_transaction_id_is_not_reported_complete(): void
    {
        $page = array_fill(0, 99, ['transactionId' => 'valid']);
        $page[] = ['orderId' => '463913879045013504'];
        Http::fake(['*' => Http::response(['code' => '000000', 'success' => true, 'data' => $page])]);

        $this->expectException(BinanceProviderException::class);

        (new BinanceTransactionHistoryReader(new BinancePayClient))->read(0, 100);
    }

    public function test_a_full_page_with_a_non_object_row_is_not_reported_complete(): void
    {
        $page = array_fill(0, 99, ['transactionId' => 'valid']);
        $page[] = 'malformed';
        Http::fake(['*' => Http::response(['code' => '000000', 'success' => true, 'data' => $page])]);

        $this->expectException(BinanceProviderException::class);

        (new BinanceTransactionHistoryReader(new BinancePayClient))->read(0, 100);
    }

    public function test_malformed_history_data_is_not_treated_as_an_empty_page(): void
    {
        Http::fake(['*' => Http::response(['code' => '000000', 'success' => true, 'data' => 'malformed'])]);

        $this->expectException(BinanceProviderException::class);

        (new BinanceTransactionHistoryReader(new BinancePayClient))->read(0, 100, fresh: true);
    }

    public function test_missing_history_data_is_not_treated_as_an_empty_page(): void
    {
        Http::fake(['*' => Http::response(['code' => '000000', 'success' => true])]);

        $this->expectException(BinanceProviderException::class);

        (new BinanceTransactionHistoryReader(new BinancePayClient))->read(0, 100);
    }
}
