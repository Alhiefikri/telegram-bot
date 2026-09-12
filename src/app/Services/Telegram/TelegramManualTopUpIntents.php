<?php

namespace App\Services\Telegram;

use App\Enums\StoreBalanceTopUpStatus;
use App\Models\Customer;
use App\Models\StoreBalanceTopUp;
use App\Support\PaymentProvider;
use Illuminate\Support\Facades\DB;

/**
 * Customer-side submission of a manual Store Balance top-up payment proof.
 *
 * This mirrors the manual product purchase flow: the customer pays the static
 * QR, then sends the proof photo with the `/payment` caption. The proof is
 * review evidence only and never credits the balance by itself.
 */
final class TelegramManualTopUpIntents
{
    public function __construct(
        private readonly TelegramBotClient $bot,
        private readonly TelegramLocale $locale,
        private readonly TelegramAdminAccess $adminAccess,
        private readonly StoreBalanceTopUpOutbound $topUpOutbound,
    ) {}

    /**
     * Handle `/payment` for a manual top-up awaiting proof.
     *
     * Returns false when the customer has no top-up waiting for proof so the
     * caller can fall back to the manual order purchase flow.
     *
     * @param  array<string, mixed>  $message
     */
    public function submitTopUpProof(Customer $customer, string $chatId, array $message): bool
    {
        $topUp = $this->awaitingProofTopUp($customer);
        if ($topUp === null) {
            return false;
        }

        $photoFileId = $this->largestPhotoFileId($message);
        if ($photoFileId === null) {
            $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'balance.topup.proof.photo_required'));

            return true;
        }

        $adminChatId = trim((string) $this->adminAccess->adminChatId());
        if ($adminChatId === '') {
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
                || strtolower(trim((string) $locked->provider)) !== PaymentProvider::MANUAL
                || $locked->payment_proof_submitted_at !== null) {
                return false;
            }

            $locked->forceFill([
                'payment_proof_file_id' => $photoFileId,
                'payment_proof_submitted_at' => now(),
                'payment_proof_admin_chat_id' => $adminChatId,
            ])->save();

            return true;
        }, attempts: 5);

        if (! $claimed) {
            $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'balance.topup.proof.duplicate'));

            return true;
        }

        $this->topUpOutbound->handoffPaymentProof($topUp->fresh() ?? $topUp);
        $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'balance.topup.proof.received'));

        return true;
    }

    private function awaitingProofTopUp(Customer $customer): ?StoreBalanceTopUp
    {
        return StoreBalanceTopUp::query()
            ->where('customer_id', $customer->getKey())
            ->whereRaw('LOWER(TRIM(provider)) = ?', [PaymentProvider::MANUAL])
            ->where('status', StoreBalanceTopUpStatus::AwaitingConfirmation->value)
            ->whereNull('payment_proof_submitted_at')
            ->latest('id')
            ->first();
    }

    /** @param array<string, mixed> $message */
    private function largestPhotoFileId(array $message): ?string
    {
        $photos = $message['photo'] ?? null;
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
