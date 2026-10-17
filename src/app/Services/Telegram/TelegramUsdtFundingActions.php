<?php

namespace App\Services\Telegram;

use App\Enums\CustomerUsdtFundingRail;
use App\Models\Customer;
use App\Models\TelegramNavigationRoot;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtFunding\UsdtFundingIntentResult;
use App\Services\UsdtFunding\UsdtFundingIntentService;
use App\Services\UsdtTopUps\UsdtTopUpReview;
use Illuminate\Auth\Access\AuthorizationException;

/** U6 boundary for U9 presentation. Caller supplies authenticated update facts. */
final class TelegramUsdtFundingActions
{
    private const MAX_INPUT_LENGTH = 32;

    public function __construct(
        private readonly UsdtFundingIntentService $intents,
        private readonly UsdtTopUpReview $reviews,
    ) {}

    public function preview(
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

        return $this->intents->open($context['customer'], $chatId, $context['root']->id, $messageId, $money, $rail);
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

    public function callback(string $actorId, string $chatId, string $chatType, int $messageId, string $data): UsdtFundingIntentResult
    {
        $context = $this->context($actorId, $chatId, $chatType, $messageId);
        if ($context === null) {
            return new UsdtFundingIntentResult('unauthorized');
        }
        if (strlen($data) > 64 || preg_match('/\Ausdt:(confirm|cancel):([a-f0-9]{32})\z/D', $data, $matches) !== 1) {
            return new UsdtFundingIntentResult('stale');
        }

        return $this->intents->claim($context['customer'], $chatId, $context['root']->id, $messageId, $matches[2], $matches[1]);
    }

    public static function callbackData(string $action, string $nonce): string
    {
        if (! in_array($action, ['confirm', 'cancel'], true) || preg_match('/\A[a-f0-9]{32}\z/D', $nonce) !== 1) {
            throw new \InvalidArgumentException('Invalid USDT confirmation callback.');
        }

        return 'usdt:'.$action.':'.$nonce;
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
