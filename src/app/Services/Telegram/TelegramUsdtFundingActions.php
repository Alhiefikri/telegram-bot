<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use App\Models\TelegramNavigationRoot;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtFunding\UsdtFundingIntentResult;
use App\Services\UsdtFunding\UsdtFundingIntentService;

/** U6 boundary for U9 presentation. Caller supplies authenticated update facts. */
final class TelegramUsdtFundingActions
{
    public function __construct(private readonly UsdtFundingIntentService $intents) {}

    public function preview(string $actorId, string $chatId, string $chatType, int $messageId, string $amount): UsdtFundingIntentResult
    {
        $context = $this->context($actorId, $chatId, $chatType, $messageId);
        if ($context === null) {
            return new UsdtFundingIntentResult('unauthorized');
        }
        try {
            $money = UsdtMicroAmount::fromDecimalString($amount);
        } catch (\InvalidArgumentException) {
            return new UsdtFundingIntentResult('invalid_amount');
        }

        return $this->intents->open($context['customer'], $chatId, $context['root']->id, $messageId, $money);
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
