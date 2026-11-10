<?php

namespace App\Services\Telegram;

use App\Enums\StoreBalanceTopUpStatus;
use App\Models\Customer;
use App\Models\StoreBalanceTopUp;
use App\Support\PaymentProvider;
use Illuminate\Support\Facades\DB;

/** A submitted screenshot is review evidence, never authority to credit balance. */
final class TelegramManualTopUpIntents
{
    public function __construct(
        private readonly TelegramBotClient $bot,
        private readonly TelegramLocale $locale,
        private readonly TelegramAdminAccess $adminAccess,
        private readonly StoreBalanceTopUpOutbound $topUpOutbound,
        private readonly TelegramOutboundEffectService $outboundEffects,
    ) {}

    /**
     * Handle a manual top-up proof, scoped to the selected top-up when supplied.
     *
     * @param  array<string, mixed>  $message
     */
    public function submitTopUpProof(Customer $customer, string $chatId, array $message, ?int $topUpId = null): bool
    {
        if ((string) $customer->telegram_id !== $chatId) {
            return false;
        }
        $topUp = $topUpId === null
            ? $this->awaitingProofTopUp($customer)
            : StoreBalanceTopUp::query()->where('customer_id', $customer->getKey())
                ->where('chat_id', $chatId)->whereKey($topUpId)->first();
        if ($topUp === null) {
            return false;
        }
        if ($topUp->hasPaymentProof()) {
            $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'balance.topup.proof.duplicate'));

            return true;
        }
        if (! $topUp->usesManualPayment()
            || $topUp->status !== StoreBalanceTopUpStatus::AwaitingConfirmation
            || $topUp->expires_at?->isFuture() !== true) {
            $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'payment.proof.reply_unavailable'));

            return true;
        }

        $photoFileId = $this->largestPhotoFileId($message);
        if ($photoFileId === null) {
            $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'balance.topup.proof.photo_required'));

            return true;
        }

        $adminChatId = trim((string) $this->adminAccess->adminChatId());
        if (preg_match('/^[1-9][0-9]*$/D', $adminChatId) !== 1) {
            $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'balance.topup.proof.admin_unavailable'));

            return true;
        }

        $claimed = DB::transaction(function () use ($topUp, $photoFileId, $adminChatId): bool {
            $locked = StoreBalanceTopUp::query()
                ->whereKey($topUp->getKey())
                ->lockForUpdate()
                ->first();
            if ($locked === null
                || $locked->status !== StoreBalanceTopUpStatus::AwaitingConfirmation
                || ! $locked->usesManualPayment()
                || $locked->expires_at?->isFuture() !== true
                || $locked->payment_proof_submitted_at !== null) {
                return false;
            }

            $locked->forceFill([
                'payment_proof_file_id' => $photoFileId,
                'payment_proof_submitted_at' => now(),
                'payment_proof_admin_chat_id' => $adminChatId,
            ])->save();
            $effect = $this->outboundEffects->recordBalanceTopUpPaymentProof($locked->fresh());
            if ($effect === null) {
                throw new \RuntimeException('Manual top-up proof has no valid admin delivery target.');
            }

            return true;
        }, attempts: 5);

        if (! $claimed) {
            $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'balance.topup.proof.duplicate'));

            return true;
        }

        try {
            $this->topUpOutbound->handoffPaymentProof($topUp->fresh() ?? $topUp);
        } catch (\Throwable $exception) {
            report($exception);
        }
        $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'balance.topup.proof.received'));

        return true;
    }

    private function awaitingProofTopUp(Customer $customer): ?StoreBalanceTopUp
    {
        return StoreBalanceTopUp::query()
            ->where('customer_id', $customer->getKey())
            ->where(fn ($query) => $query
                ->whereRaw('LOWER(TRIM(provider)) = ?', [PaymentProvider::MANUAL])
                ->orWhereNotNull('manual_alternative_at'))
            ->where('chat_id', (string) $customer->telegram_id)
            ->where('expires_at', '>', now())
            ->where('status', StoreBalanceTopUpStatus::AwaitingConfirmation->value)
            ->whereNull('payment_proof_submitted_at')
            ->latest('id')
            ->first();
    }

    /** @param array<string, mixed> $message */
    private function largestPhotoFileId(array $message): ?string
    {
        $photos = $message['photo'] ?? $message['reply_to_message']['photo'] ?? null;
        if (! is_array($photos) || $photos === []) {
            return null;
        }

        $candidate = null;
        foreach ($photos as $photo) {
            if (is_array($photo) && is_string($photo['file_id'] ?? null) && trim($photo['file_id']) !== '') {
                $candidate = trim($photo['file_id']);
            }
        }

        return $candidate;
    }
}
