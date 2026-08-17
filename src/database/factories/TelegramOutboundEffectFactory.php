<?php

namespace Database\Factories;

use App\Enums\TelegramOutboundEffectState;
use App\Models\Order;
use App\Models\TelegramOutboundEffect;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TelegramOutboundEffect> */
class TelegramOutboundEffectFactory extends Factory
{
    protected $model = TelegramOutboundEffect::class;

    public function definition(): array
    {
        $sourceId = (string) $this->faker->uuid();

        return [
            'effect_key' => 'test:telegram-outbound:'.$this->faker->unique()->uuid(),
            'effect_group_key' => null,
            'sequence' => 1,
            'effect_type' => 'test.effect',
            'source_type' => Order::class,
            'source_id' => $sourceId,
            'target_type' => 'customer',
            'telegram_chat_id' => (string) $this->faker->numberBetween(1000, 999999999),
            'queue' => TelegramOutboundEffect::CHECKOUT_QUEUE,
            'telegram_method' => 'sendMessage',
            'locale' => 'en',
            'template_version' => 'test-v1',
            'state' => TelegramOutboundEffectState::Pending,
            'attempts' => 0,
            'next_attempt_at' => now(),
        ];
    }
}
