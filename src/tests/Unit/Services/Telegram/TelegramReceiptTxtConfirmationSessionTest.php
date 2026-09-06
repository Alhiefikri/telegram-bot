<?php

namespace Tests\Unit\Services\Telegram;

use App\Models\Customer;
use App\Services\Telegram\TelegramReceiptTxtConfirmationSession;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class TelegramReceiptTxtConfirmationSessionTest extends TestCase
{
    public function test_confirmation_is_bound_to_customer_chat_and_order_and_is_consumed_once(): void
    {
        $customer = $this->customer(426001);
        $session = app(TelegramReceiptTxtConfirmationSession::class);

        $confirmation = $session->start($customer, '426001', 'order-426', 'en');

        $this->assertSame('order-426', $session->orderIdForNonce($customer, '426001', $confirmation['nonce']));
        $this->assertSame($confirmation, $session->boundConfirmation($customer, '426001', 'order-426', $confirmation['nonce']));
        $this->assertSame($confirmation, $session->consume($customer, '426001', 'order-426', $confirmation['nonce']));
        $this->assertNull($session->orderIdForNonce($customer, '426001', $confirmation['nonce']));
        $this->assertNull($session->consume($customer, '426001', 'order-426', $confirmation['nonce']));
        $this->assertNull(Cache::get('telegram:receipt-txt-confirmation:426001:order-426'));
    }

    public function test_cross_customer_and_cross_chat_validation_fails_closed_without_revealing_state(): void
    {
        $owner = $this->customer(426002);
        $other = $this->customer(426003);
        $session = app(TelegramReceiptTxtConfirmationSession::class);
        $confirmation = $session->start($owner, '426002', 'order-426', 'ms');

        $this->assertNull($session->boundConfirmation($other, '426002', 'order-426', $confirmation['nonce']));
        $this->assertNull($session->consume($owner, '426003', 'order-426', $confirmation['nonce']));
        $this->assertNull(Cache::get('telegram:receipt-txt-confirmation:426002:order-426'));
    }

    public function test_malformed_cached_state_and_expired_state_are_not_accepted(): void
    {
        $customer = $this->customer(426004);
        $session = app(TelegramReceiptTxtConfirmationSession::class);
        $key = 'telegram:receipt-txt-confirmation:426004:order-426';

        Cache::put($key, [
            'nonce' => 'not-a-nonce',
            'customerId' => (int) $customer->id,
            'telegramChatId' => '426004',
            'orderId' => 'order-426',
            'locale' => 'en',
        ], now()->addMinutes(10));
        $this->assertNull($session->consume($customer, '426004', 'order-426', 'not-a-nonce'));

        $confirmation = $session->start($customer, '426004', 'order-426', 'en');
        Cache::put($key, $confirmation, now()->subMinute());
        $this->assertNull($session->consume($customer, '426004', 'order-426', $confirmation['nonce']));
    }

    public function test_ttl_is_short_and_confirmation_contains_only_binding_metadata(): void
    {
        $customer = $this->customer(426005);
        $confirmation = app(TelegramReceiptTxtConfirmationSession::class)->start(
            $customer,
            '426005',
            'order-426',
            'id',
        );

        $this->assertLessThanOrEqual(10, TelegramReceiptTxtConfirmationSession::TTL_MINUTES);
        $this->assertSame(['nonce', 'customerId', 'telegramChatId', 'orderId', 'locale'], array_keys($confirmation));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $confirmation['nonce']);
        $this->assertSame((int) $customer->id, $confirmation['customerId']);
        $this->assertSame('426005', $confirmation['telegramChatId']);
        $this->assertSame('order-426', $confirmation['orderId']);
        $this->assertSame('id', $confirmation['locale']);
    }

    private function customer(int $telegramId): Customer
    {
        $customer = new Customer;
        $customer->forceFill(['id' => $telegramId, 'telegram_id' => $telegramId]);

        return $customer;
    }
}
