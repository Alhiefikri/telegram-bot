<?php

namespace App\Services\Telegram;

use App\Enums\CustomerUsdtFundingRail;
use App\Enums\CustomerUsdtFundingReservationOutcome;
use App\Models\Customer;
use App\Models\CustomerUsdtTopUp;
use App\Models\TelegramNavigationRoot;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtFunding\CustomerUsdtFundingRelease;
use App\Services\UsdtFunding\CustomerUsdtFundingReservationService;
use App\Services\UsdtFunding\UsdtFundingIntentResult;
use App\Services\UsdtFunding\UsdtFundingIntentService;
use App\Services\UsdtTopUps\UsdtTopUpReview;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

/** U6 boundary for U9 presentation. Caller supplies authenticated update facts. */
final class TelegramUsdtFundingActions
{
    private const MAX_INPUT_LENGTH = 32;

    public function __construct(
        private readonly UsdtFundingIntentService $intents,
        private readonly UsdtTopUpReview $reviews,
        private readonly CustomerUsdtFundingReservationService $reservations,
    ) {}

    /**
     * Starting over cancels one unpaid USD target. A Customer who picked the
     * wrong amount must never be locked into it; releasing the slot moves no
     * money and late transfers still reach manual review.
     */
    public function cancelOpenTargets(Customer $customer): int
    {
        $active = $this->reservations->activeForCustomer($customer);
        if ($active === null || $active->reservation->sourceType !== 'customer_usdt_top_up') {
            return 0;
        }

        try {
            $result = $this->reservations->cancelUnpaidTarget(
                $customer,
                $active->reservation->reservationId,
                CustomerUsdtFundingRelease::cancelledByCustomer(
                    'customer-restart:'.$active->reservation->sourceId,
                    'customer:'.$customer->getKey(),
                ),
            );
        } catch (Throwable) {
            return 0;
        }

        return in_array($result->outcome, [CustomerUsdtFundingReservationOutcome::Released, CustomerUsdtFundingReservationOutcome::Replayed], true) ? 1 : 0;
    }

    /** Cancel one unpaid target from its own instruction surface. */
    public function cancelTarget(Customer $customer, string $topUpId): bool
    {
        $target = CustomerUsdtTopUp::query()
            ->whereKey($topUpId)
            ->where('customer_id', $customer->getKey())
            ->first();
        if ($target === null || $target->reservation_id === null) {
            return false;
        }

        try {
            $result = $this->reservations->cancelUnpaidTarget(
                $customer,
                (int) $target->reservation_id,
                CustomerUsdtFundingRelease::cancelledByCustomer(
                    'customer-cancel:'.$target->id,
                    'customer:'.$customer->getKey(),
                ),
            );
        } catch (Throwable) {
            return false;
        }

        return in_array($result->outcome, [CustomerUsdtFundingReservationOutcome::Released, CustomerUsdtFundingReservationOutcome::Replayed], true);
    }

    /**
     * Open one funding target for an explicit Customer choice and confirm it in
     * the same step. Tapping a preset amount or sending a typed amount is the
     * confirmation, so no separate confirm screen exists; the instructions are
     * rendered from the confirmed target the moment it exists.
     */
    public function openConfirmedIntent(
        string $actorId,
        string $chatId,
        string $chatType,
        int $messageId,
        string $amount,
        CustomerUsdtFundingRail $rail = CustomerUsdtFundingRail::BscUsdt,
    ): UsdtFundingIntentResult {
        $context = $this->context($actorId, $chatId, $chatType, $messageId);
        if ($context === null) {
            return new UsdtFundingIntentResult('unauthorized');
        }
        try {
            // Human boundary: `0,3` is the same user intent as `0.3`. Every
            // Telegram entry point funnels through here so the command and the
            // amount prompt cannot drift; the money parser stays strict.
            $money = UsdtMicroAmount::fromDecimalString(self::normalizeDecimalInput($amount));
        } catch (\InvalidArgumentException) {
            return new UsdtFundingIntentResult('invalid_amount');
        }

        $opened = $this->intents->open($context['customer'], $chatId, $context['root']->id, $messageId, $money, $rail);
        if (! in_array($opened->outcome, ['opened', 'replayed'], true) || $opened->intent === null) {
            return $opened;
        }

        return $this->intents->claim(
            $context['customer'],
            $chatId,
            $context['root']->id,
            $messageId,
            (string) $opened->intent->nonce,
            'confirm',
            $rail,
        );
    }

    /**
     * Normalize one human-typed USDT amount. A lone comma with up to six
     * fractional digits is the supported Indonesian decimal separator, so it is
     * rewritten to the dot form; mixed separators, grouping separators,
     * exponents, zero, negatives, more than six decimals and overflow keep
     * flowing into the strict parser unchanged and stay rejected there.
     */
    private static function normalizeDecimalInput(string $amount): string
    {
        $amount = trim($amount);
        if (strlen($amount) > self::MAX_INPUT_LENGTH) {
            return $amount;
        }
        if (preg_match('/\A[0-9]+,[0-9]{1,6}\z/D', $amount) === 1) {
            return str_replace(',', '.', $amount);
        }
        if (str_contains($amount, ',')) {
            throw new \InvalidArgumentException('USDT amount must use one decimal separator.');
        }

        return $amount;
    }

    /** U8 authorization seam; update actor/chat/root facts come from the trusted channel adapter. */
    public function authorizeExternalReturn(string $actorId, string $chatId, string $chatType, int $messageId,
        string $topUpId, string $amount, string $destinationAddress, string $nonce): object
    {
        $context = $this->context($actorId, $chatId, $chatType, $messageId);
        if ($context === null) {
            throw new AuthorizationException('External-return authorization requires the registered Customer private chat and current root.');
        }

        return $this->reviews->authorizeExternalReturn($context['customer'], $topUpId, $amount, $destinationAddress, $nonce);
    }

    /** @return array{customer: Customer, root: TelegramNavigationRoot}|null */
    private function context(string $actorId, string $chatId, string $chatType, int $messageId): ?array
    {
        if ($chatType !== 'private' || $actorId !== $chatId || preg_match('/\A[1-9][0-9]{0,18}\z/D', $actorId) !== 1 || $messageId < 1
            || (strlen($actorId) === 19 && strcmp($actorId, (string) PHP_INT_MAX) > 0)) {
            return null;
        }
        // Exact numeric identity; no username lookup or automatic registration.
        $customer = Customer::query()->where('telegram_id', $actorId)->first();
        if ($customer === null || (string) $customer->telegram_id !== $actorId) {
            return null;
        }
        $root = TelegramNavigationRoot::query()->where('customer_id', $customer->id)->where('chat_id', $chatId)->where('message_id', $messageId)->first();

        return $root === null ? null : ['customer' => $customer, 'root' => $root];
    }
}
