<?php

namespace Tests\Concerns;

use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentTelegramStarsDetail;
use App\Models\TelegramOutboundEffect;
use App\Services\OrderCheckoutService;
use Illuminate\Testing\TestResponse;

trait BuildsStarsTransport
{
    use BuildsStarsCheckouts;

    private function configureStarsTransport(): void
    {
        $this->enableStars();
        config([
            'services.telegram.token' => '123456:fixture-token',
            'services.telegram.webhook_secret' => 'fixture-secret',
            'services.telegram.api_base_url' => 'https://api.telegram.org',
        ]);
    }

    private function starsOrder(string $key = 'transport-fixture'): Order
    {
        $buyer = Customer::factory()->create();
        $product = $this->starsProduct();

        return app(OrderCheckoutService::class)->checkoutTelegramStarsDirect($product, 1, $this->starsIntent($buyer, $product, $key, 1));
    }

    private function acknowledgeStarsInvoiceFixture(?PaymentTelegramStarsDetail $target = null): void
    {
        TelegramOutboundEffect::where('effect_type', TelegramOutboundEffectType::TelegramStarsInvoice->value)
            ->when($target !== null, fn ($query) => $query->where('source_id', $target->payment_id))->sole()->forceFill([
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

    private function starsSuccess(PaymentTelegramStarsDetail $target, int $id, array $overrides = [], array $message = []): array
    {
        return ['update_id' => $id, 'message' => $message + [
            'message_id' => $id,
            'from' => ['id' => $target->telegram_user_id],
            'chat' => ['id' => $target->chat_id, 'type' => 'private'],
            'successful_payment' => $overrides + [
                'currency' => 'XTR',
                'total_amount' => $target->total_xtr,
                'invoice_payload' => $target->invoice_payload,
                'telegram_payment_charge_id' => 'fixture-charge',
            ],
        ]];
    }

    private function starsWebhook(array $payload, string $secret = 'fixture-secret'): TestResponse
    {
        return $this->postJson('/api/webhook/telegram', $payload, ['X-Telegram-Bot-Api-Secret-Token' => $secret]);
    }
}
