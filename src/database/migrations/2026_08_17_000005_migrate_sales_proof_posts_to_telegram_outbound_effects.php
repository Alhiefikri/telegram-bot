<?php

use App\Enums\TelegramOutboundEffectState;
use App\Models\Order;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $channelTarget = DB::table('store_channel_settings')
            ->where('singleton_key', 'store_channel')
            ->value('telegram_chat_id');
        $channelTarget = $channelTarget ?: DB::table('store_channel_settings')
            ->where('singleton_key', 'store_channel')
            ->value('channel_username');
        $channelTarget = (string) ($channelTarget ?: 'unconfigured');

        DB::table('sales_proof_posts')
            ->orderBy('id')
            ->chunkById(100, function ($posts) use ($channelTarget): void {
                foreach ($posts as $post) {
                    $status = (string) $post->status;
                    $state = match ($status) {
                        'sent' => TelegramOutboundEffectState::Sent->value,
                        'failed' => TelegramOutboundEffectState::Failed->value,
                        'sending' => TelegramOutboundEffectState::Unknown->value,
                        'unknown' => TelegramOutboundEffectState::Unknown->value,
                        'manual_review' => TelegramOutboundEffectState::ManualReview->value,
                        default => TelegramOutboundEffectState::Pending->value,
                    };
                    $effectKey = 'order:'.$post->order_id.':'.TelegramOutboundEffectService::TYPE_SALES_PROOF.':v1';
                    $now = now();

                    DB::table('telegram_outbound_effects')->insertOrIgnore([
                        'id' => (string) Str::uuid(),
                        'effect_key' => $effectKey,
                        'effect_group_key' => $effectKey,
                        'sequence' => 1,
                        'effect_type' => TelegramOutboundEffectService::TYPE_SALES_PROOF,
                        'source_type' => Order::class,
                        'source_id' => (string) $post->order_id,
                        'target_type' => 'store_channel',
                        'telegram_chat_id' => $channelTarget,
                        'queue' => 'notifications',
                        'telegram_method' => 'sendMessage',
                        'locale' => 'en',
                        'template_version' => TelegramOutboundEffectService::TEMPLATE_SALES_PROOF,
                        'state' => $state,
                        'attempts' => (int) $post->attempts,
                        'next_attempt_at' => in_array($state, [TelegramOutboundEffectState::Pending->value, TelegramOutboundEffectState::Failed->value], true) ? $now : null,
                        'lease_until' => null,
                        'claim_token' => null,
                        'telegram_message_id' => $post->telegram_message_id,
                        'sent_at' => $post->sent_at,
                        'unknown_at' => in_array($state, [TelegramOutboundEffectState::Unknown->value, TelegramOutboundEffectState::ManualReview->value], true) ? $now : null,
                        'failed_at' => $state === TelegramOutboundEffectState::Failed->value ? $now : null,
                        'manual_review_at' => $state === TelegramOutboundEffectState::ManualReview->value ? $now : null,
                        'last_error_code' => match ($state) {
                            TelegramOutboundEffectState::Unknown->value => 'legacy_sending_ambiguous',
                            TelegramOutboundEffectState::ManualReview->value => 'legacy_sending_ambiguous',
                            TelegramOutboundEffectState::Failed->value => 'legacy_sales_proof_failure',
                            default => null,
                        },
                        'last_error' => match ($state) {
                            TelegramOutboundEffectState::Unknown->value, TelegramOutboundEffectState::ManualReview->value => 'Legacy Sales Proof delivery outcome is ambiguous and requires review.',
                            TelegramOutboundEffectState::Failed->value => 'Legacy Sales Proof delivery failed before completion.',
                            default => null,
                        },
                        'outcome_metadata' => json_encode([
                            'delivery_context' => 'sales_proof',
                            'reason' => $status === 'sending' ? 'legacy_sending_ambiguous' : 'legacy_projection_migrated',
                        ], JSON_THROW_ON_ERROR),
                        'created_at' => $post->created_at ?: $now,
                        'updated_at' => $now,
                    ]);

                    if ($status === 'sending') {
                        DB::table('sales_proof_posts')
                            ->where('id', $post->id)
                            ->where('status', 'sending')
                            ->update([
                                'status' => 'unknown',
                                'last_error' => 'Legacy Sales Proof delivery outcome is ambiguous and requires review.',
                                'updated_at' => $now,
                            ]);
                    }
                }
            });
    }

    public function down(): void
    {
        DB::table('telegram_outbound_effects')
            ->where('effect_type', TelegramOutboundEffectService::TYPE_SALES_PROOF)
            ->delete();
    }
};
