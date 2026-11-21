<?php

namespace Tests\Concerns;

use App\Enums\TelegramOutboundEffectState;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentTelegramStarsDetail;
use App\Models\TelegramOutboundEffect;
use App\Services\OrderCheckoutService;

trait BuildsStarsTransport
{
    use BuildsStarsCheckouts;

    private function configureStarsTransport(): void
    {
        $this->enableStars();
        config([
            'services.telegram.token' => 'fixture-token',
            'services.telegram.webhook_secret' => 'fixture-secret',
            'services.telegram.api_base_url' => 'https://api.telegram.org',
        ]);
    }

    private function starsOrder(): Order
    {
        $buyer = Customer::factory()->create();
        $product = $this->starsProduct();

        return app(OrderCheckoutService::class)->checkoutTelegramStarsDirect($product, 1, $this->starsIntent($buyer, $product, 'transport-fixture', 1));
    }

    private function acknowledgeStarsInvoiceFixture(): void
    {
        TelegramOutboundEffect::sole()->forceFill([
            'state' => TelegramOutboundEffectState::Sent,
            'telegram_message_id' => '701',
            'sent_at' => now(),
        ])->save();
    }

    private function starsQuery(PaymentTelegramStarsDetail $target, int $id = 101, array $overrides = []): array
    {
        return [
            'update_id' => $id,
            'pre_checkout_query' => $overrides + [
                'id' => "query-$id",
                'from' => ['id' => $target->telegram_user_id, 'username' => 'not-authority'],
                'currency' => 'XTR',
                'total_amount' => $target->total_xtr,
                'invoice_payload' => $target->invoice_payload,
            ],
        ];
    }
}
