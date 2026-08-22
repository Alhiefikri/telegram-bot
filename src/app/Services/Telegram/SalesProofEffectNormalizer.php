<?php

namespace App\Services\Telegram;

use App\Enums\TelegramOutboundEffectState;
use App\Models\TelegramOutboundEffect;
use Illuminate\Support\Facades\DB;

/**
 * Repairs the representation of pre-cutover Sales Proof effects without
 * changing delivery outcomes or ambiguous/terminal records.
 */
final class SalesProofEffectNormalizer
{
    public function normalize(TelegramOutboundEffect $effect): TelegramOutboundEffect
    {
        if (! $this->needsNormalization($effect)) {
            return $effect;
        }

        return DB::transaction(function () use ($effect): TelegramOutboundEffect {
            $locked = TelegramOutboundEffect::query()->lockForUpdate()->find($effect->getKey());
            if ($locked === null || ! $this->needsNormalization($locked)) {
                return $locked ?? $effect;
            }

            $locked->forceFill([
                'telegram_method' => 'sendPhoto',
                'template_version' => TelegramOutboundEffectService::TEMPLATE_SALES_PROOF,
                'updated_at' => now(),
            ])->save();

            return $locked->fresh();
        });
    }

    private function needsNormalization(TelegramOutboundEffect $effect): bool
    {
        $state = $effect->state instanceof TelegramOutboundEffectState
            ? $effect->state->value
            : (string) $effect->getRawOriginal('state');

        return $effect->effect_type === TelegramOutboundEffectService::TYPE_SALES_PROOF
            && in_array($state, [
                TelegramOutboundEffectState::Pending->value,
                TelegramOutboundEffectState::Failed->value,
            ], true)
            && $effect->template_version === TelegramOutboundEffectService::TEMPLATE_SALES_PROOF
            && $effect->telegram_method !== 'sendPhoto';
    }
}
