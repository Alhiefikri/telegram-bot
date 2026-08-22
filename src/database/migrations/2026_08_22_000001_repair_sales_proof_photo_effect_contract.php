<?php

use App\Enums\TelegramOutboundEffectState;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('telegram_outbound_effects')
            ->where('effect_type', TelegramOutboundEffectService::TYPE_SALES_PROOF)
            ->where('template_version', TelegramOutboundEffectService::TEMPLATE_SALES_PROOF)
            ->whereIn('state', [
                TelegramOutboundEffectState::Pending->value,
                TelegramOutboundEffectState::Failed->value,
            ])
            ->where(function ($query): void {
                $query
                    ->whereNull('telegram_method')
                    ->orWhere('telegram_method', '<>', 'sendPhoto');
            })
            ->update([
                'telegram_method' => 'sendPhoto',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // This is a one-way data repair; reverting would recreate the bug.
    }
};
