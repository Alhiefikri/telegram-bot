<?php

namespace Tests\Unit\Services\Telegram;

use App\Models\Customer;
use App\Services\Telegram\TelegramUsdtFundingSession;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class TelegramUsdtFundingSessionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::flush();
    }

    public function test_switching_rail_replaces_old_amount_context(): void
    {
        $customer = $this->customer(991001);
        $session = new TelegramUsdtFundingSession;
        $session->promptAmount($customer, '991001', 10, 20, 'bsc_usdt');
        $session->promptAmount($customer, '991001', 11, 21, 'binance_pay');
        $this->assertSame(['chat_id' => '991001', 'root_id' => 11, 'message_id' => 21, 'rail' => 'binance_pay'], $session->promptedAmount($customer));
    }

    public function test_customer_prompts_are_isolated_and_navigation_clears_only_its_owner(): void
    {
        $one = $this->customer(991001);
        $two = $this->customer(991002);
        $session = new TelegramUsdtFundingSession;
        $session->promptAmount($one, '991001', 10, 20, 'bsc_usdt');
        $session->promptHash($one, 'target-one');
        $session->promptAmount($two, '991002', 11, 21, 'binance_pay');
        $session->promptHash($two, 'target-two');
        $session->forget($one);
        $this->assertNull($session->promptedAmount($one));
        $this->assertNull($session->promptedTopUpId($one));
        $this->assertSame('binance_pay', $session->promptedAmount($two)['rail']);
        $this->assertSame('target-two', $session->promptedTopUpId($two));
    }

    public function test_expired_amount_and_transaction_prompts_cannot_consume_later_messages(): void
    {
        $customer = $this->customer(991003);
        $session = new TelegramUsdtFundingSession;
        $session->promptAmount($customer, '991003', 10, 20, 'binance_pay');
        $session->promptHash($customer, 'old-target');
        $this->travel(9)->minutes();
        $this->assertSame('old-target', $session->promptedTopUpId($customer));
        $this->assertSame('binance_pay', $session->promptedAmount($customer)['rail']);
        $this->travel(2)->minutes();
        $this->assertNull($session->promptedAmount($customer));
        $this->assertNull($session->promptedTopUpId($customer));
    }

    public function test_new_transaction_prompt_replaces_old_target(): void
    {
        $customer = $this->customer(991004);
        $session = new TelegramUsdtFundingSession;
        $session->promptHash($customer, 'old-target');
        $session->promptHash($customer, 'new-target');
        $this->assertSame('new-target', $session->promptedTopUpId($customer));
    }

    private function customer(int $telegramId): Customer
    {
        return (new Customer)->forceFill(['telegram_id' => $telegramId]);
    }
}
