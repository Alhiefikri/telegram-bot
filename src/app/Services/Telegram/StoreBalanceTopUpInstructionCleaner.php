<?php

namespace App\Services\Telegram;

use App\Enums\StoreBalanceTopUpStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Models\StoreBalanceTopUp;
use App\Models\TelegramOutboundEffect;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class StoreBalanceTopUpInstructionCleaner
{
    public function __construct(
        private readonly TelegramOutboundEffectService $outboundEffects,
        private readonly TelegramBotClient $bot,
    ) {}

    public function deleteInstruction(StoreBalanceTopUp $topUp): void
    {
        $source = $topUp->fresh();
        if ($source === null || $source->status !== StoreBalanceTopUpStatus::Paid) {
            return;
        }

        $effect = $this->outboundEffects->forBalanceTopUp((string) $source->getKey());
        if ($effect === null) {
            return;
        }

        $token = (string) Str::uuid();
        $target = DB::transaction(function () use ($effect, $token): ?TelegramOutboundEffect {
            $target = TelegramOutboundEffect::query()
                ->whereKey($effect->getKey())
                ->lockForUpdate()
                ->first();

            if (! $target
                || $target->state !== TelegramOutboundEffectState::Sent
                || trim((string) $target->telegram_chat_id) === ''
                || preg_match('/\A[1-9][0-9]{0,18}\z/D', (string) $target->telegram_message_id) !== 1
                || $target->telegram_message_deleted_at !== null
                || $this->hasActiveDeletionClaim($target->telegram_message_deletion_started_at)) {
                return null;
            }

            $target->forceFill([
                'telegram_message_deletion_started_at' => now(),
                'telegram_message_deletion_token' => $token,
            ])->save();

            return $target;
        }, 5);

        if ($target === null) {
            return;
        }

        try {
            $this->bot->deleteMessage($target->telegram_chat_id, $target->telegram_message_id);
        } catch (RequestException $exception) {
            if (! $this->isAlreadyDeletedTelegramMessage($exception)) {
                $this->releaseDeletionClaim($target, $token);

                throw $exception;
            }
        } catch (Throwable $exception) {
            $this->releaseDeletionClaim($target, $token);

            throw $exception;
        }

        $this->markDeleted($target, $token);
    }

    private function markDeleted(TelegramOutboundEffect $target, string $token): void
    {
        TelegramOutboundEffect::query()
            ->whereKey($target->getKey())
            ->where('telegram_message_id', $target->telegram_message_id)
            ->where('telegram_message_deletion_token', $token)
            ->whereNull('telegram_message_deleted_at')
            ->update([
                'telegram_message_deleted_at' => now(),
                'telegram_message_deletion_started_at' => null,
                'telegram_message_deletion_token' => null,
                'updated_at' => now(),
            ]);
    }

    private function releaseDeletionClaim(TelegramOutboundEffect $target, string $token): void
    {
        TelegramOutboundEffect::query()
            ->whereKey($target->getKey())
            ->where('telegram_message_id', $target->telegram_message_id)
            ->where('telegram_message_deletion_token', $token)
            ->whereNull('telegram_message_deleted_at')
            ->update([
                'telegram_message_deletion_started_at' => null,
                'telegram_message_deletion_token' => null,
                'updated_at' => now(),
            ]);
    }

    private function hasActiveDeletionClaim($startedAt): bool
    {
        return $startedAt !== null
            && $startedAt->gt(now()->subSeconds(max(60, (int) config('services.telegram.claim_lease_seconds', 600))));
    }

    private function isAlreadyDeletedTelegramMessage(RequestException $exception): bool
    {
        if ($exception->response?->status() !== 400) {
            return false;
        }

        $description = Str::lower((string) data_get($exception->response->json(), 'description'));

        return str_contains($description, 'message to delete not found')
            || str_contains($description, "message can't be deleted");
    }
}
