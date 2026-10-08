<?php

namespace App\Services\Telegram\Callbacks;

use App\Models\Customer;
use App\Models\CustomerUsdtTopUp;
use App\Services\Bsc\BscTransactionHash;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;
use App\Services\Telegram\TelegramCatalogQuerySession;
use App\Services\Telegram\TelegramIntentSession;
use App\Services\Telegram\TelegramLocale;
use App\Services\Telegram\TelegramNavigationPresenter;
use App\Services\Telegram\TelegramNavigationRootService;
use App\Services\Telegram\TelegramNavigationSurface;
use App\Services\Telegram\TelegramUsdtActivityToken;
use App\Services\Telegram\TelegramUsdtBalanceIntents;
use App\Services\Telegram\TelegramUsdtFundingActions;
use App\Services\Telegram\TelegramUsdtFundingSession;
use App\Services\UsdtFunding\UsdtFundingIntentResult;
use App\Services\UsdtTopUps\UsdtTopUpSettlement;
use App\Services\UsdtTopUps\UsdtTopUpStatusReader;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Private-chat USDT presentation adapter. It only selects surfaces and passes
 * trusted update facts to the U6/US7 seams; it never credits, inspects RPC,
 * registers Customers, or sends through a second outbound path.
 */
final class TelegramUsdtFundingCallbackHandler implements TelegramCallbackHandler
{
    private const OPEN = 'balance:usdt';

    private const HISTORY = 'usdt:history';

    private const OLDER_PREFIX = 'usdt:h:o:';

    private const NEWER_PREFIX = 'usdt:h:n:';

    private const STATUS_PREFIX = 'usdt:status:';

    private const CHECK_PREFIX = 'usdt:check:';

    private const HASH_PREFIX = 'usdt:hash:';

    public function __construct(
        private readonly TelegramBotClient $bot,
        private readonly TelegramUsdtBalanceIntents $usdt,
        private readonly TelegramUsdtFundingActions $funding,
        private readonly TelegramUsdtActivityToken $tokens,
        private readonly TelegramUsdtFundingSession $hashSession,
        private readonly UsdtTopUpStatusReader $statuses,
        private readonly UsdtTopUpSettlement $settlement,
        private readonly TelegramLocale $locale,
        private readonly TelegramIntentSession $session,
        private readonly TelegramCatalogQuerySession $catalogQueries,
        private readonly TelegramNavigationPresenter $navigation,
        private readonly TelegramNavigationRootService $roots,
    ) {}

    public function stage(): TelegramCallbackStage
    {
        return TelegramCallbackStage::Customer;
    }

    public function allowsNonPrivateChat(TelegramCallbackContext $context): bool
    {
        return false;
    }

    public function isUsdtCommand(string $text): bool
    {
        return preg_match('/\A\/usdt(?:@[A-Za-z0-9_]{1,32})?(?:[ \t]|\z)/D', trim($text)) === 1;
    }

    public function isExternalReturnCommand(string $text): bool
    {
        return preg_match('/\A\/usdt-return(?:@[A-Za-z0-9_]{1,32})?(?:[ \t]|\z)/D', trim($text)) === 1;
    }

    /**
     * Record a Customer-authorized external-return destination. This does not
     * send funds; the Owner review still requires distinct verified outbound
     * evidence before resolving the top-up.
     */
    public function handleExternalReturnCommand(Customer $customer, string $chatId, string $text): void
    {
        if (preg_match('/\A\/usdt-return(?:@[A-Za-z0-9_]{1,32})?[ \t]+([0-9a-fA-F-]{36})[ \t]+([^ \t]+)[ \t]+(0x[0-9a-fA-F]{40})\z/D', trim($text), $matches) !== 1) {
            $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'balance.usdt.return.usage'));

            return;
        }
        $root = $this->roots->currentForChat($customer, $chatId)
            ?? $this->roots->establishText($customer, $chatId, $this->usdt->topUpSurface($customer));

        try {
            $authorization = $this->funding->authorizeExternalReturn(
                (string) $customer->telegram_id,
                $chatId,
                'private',
                (int) $root->message_id,
                $matches[1],
                $matches[2],
                $matches[3],
                bin2hex(random_bytes(16)),
            );
        } catch (\Throwable $error) {
            if (! $error instanceof AuthorizationException && ! $error instanceof \DomainException && ! $error instanceof InvalidArgumentException) {
                throw $error;
            }
            $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'balance.usdt.return.invalid'));

            return;
        }

        $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'balance.usdt.return.authorized', [
            'authorization' => (string) $authorization->id,
            'amount' => $matches[2],
            'destination' => strtolower($matches[3]),
        ]));
    }

    public function supports(TelegramCallbackContext $context): bool
    {
        $data = $context->data;
        if ($data === self::OPEN || $data === self::HISTORY) {
            return true;
        }
        if (preg_match('/\Ausdt:(?:confirm|cancel):[a-f0-9]{32}\z/D', $data) === 1) {
            return true;
        }
        if ($this->tokenFor($data, self::OLDER_PREFIX) !== null
            || $this->tokenFor($data, self::NEWER_PREFIX) !== null) {
            return true;
        }
        foreach ([self::STATUS_PREFIX, self::CHECK_PREFIX, self::HASH_PREFIX] as $prefix) {
            if ($this->uuidFor($data, $prefix) !== null) {
                return true;
            }
        }

        // The namespace is reserved for this feature: any other usdt: callback
        // (for example a stale preset or an older menu generation) resolves to
        // the localized stale surface instead of an unknown action.
        return str_starts_with($data, 'usdt:');
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        if (! $this->supports($context)) {
            return false;
        }

        $root = $this->navigation->matchRoot($context->customer, $context);
        if ($root === null) {
            $this->presentStale($context);

            return true;
        }

        $this->session->forgetWithLock($context->customer);
        $this->catalogQueries->forget($context->customer);

        [$surface, $feedbackKey] = $this->surfaceFor($context);
        $outcome = $this->navigation->presentOnRoot($root, $context->chatId, $surface);
        $feedback = $feedbackKey === null ? null : $this->locale->translate($context->customer, $feedbackKey);
        $this->bot->answerCallbackQuery($context->callbackQueryId, $feedback
            ?? $this->navigation->feedback($context->customer, $outcome));

        return true;
    }

    /**
     * `/usdt` opens the chooser; `/usdt <amount>` opens one exact-amount
     * preview on the canonical root without any provider or money effect.
     */
    public function handleUsdtCommand(Customer $customer, string $chatId, string $text): void
    {
        if (preg_match('/\A\/usdt(?:@[A-Za-z0-9_]{1,32})?(?:[ \t](.*))?\z/Ds', trim($text), $matches) !== 1) {
            return;
        }
        $amount = trim((string) ($matches[1] ?? ''));
        $root = $this->roots->currentForChat($customer, $chatId);
        if ($root === null) {
            $root = $this->roots->establishText($customer, $chatId, $this->usdt->topUpSurface($customer));
        }
        if ($amount === '') {
            $this->navigation->presentOnRoot($root, $chatId, $this->usdt->topUpSurface($customer));

            return;
        }

        $result = $this->funding->preview(
            (string) $customer->telegram_id,
            $chatId,
            'private',
            (int) $root->message_id,
            $amount,
        );
        [$surface] = $this->previewOutcomeSurface($customer, $result);
        $this->navigation->presentOnRoot($root, $chatId, $surface);
    }

    /**
     * Consume one prompted transaction-hash message as a bounded lookup hint.
     * The hint is never evidence and never credits the balance by itself.
     */
    public function handleHashInput(Customer $customer, string $chatId, string $text): bool
    {
        $topUpId = $this->hashSession->promptedTopUpId($customer);
        if ($topUpId === null) {
            return false;
        }
        $target = CustomerUsdtTopUp::query()
            ->whereKey($topUpId)
            ->where('customer_id', $customer->id)
            ->first();
        if ($target === null) {
            $this->hashSession->forget($customer);

            return false;
        }
        if (! $this->statuses->for($target)->acceptsCustomerLookup()) {
            $this->hashSession->forget($customer);
            $this->presentStatus($customer, $chatId, $target, 'balance.usdt.notice.hash_closed');

            return true;
        }

        // Invalid format stays a local retry: it consumes neither the bounded
        // hint budget nor an RPC lookup.
        if (BscTransactionHash::normalize($text) === null || strlen($text) !== 66) {
            $this->presentStatus($customer, $chatId, $target, 'balance.usdt.notice.hash_invalid');

            return true;
        }

        try {
            $this->settlement->submit($customer, (string) $target->id, $text);
        } catch (AuthorizationException) {
            $this->hashSession->forget($customer);
            $this->presentStatus($customer, $chatId, $target, 'balance.usdt.notice.hash_closed');

            return true;
        } catch (InvalidArgumentException) {
            $this->hashSession->forget($customer);
            $this->presentStatus($customer, $chatId, $target, 'balance.usdt.notice.hash_limited');

            return true;
        }

        $this->hashSession->forget($customer);
        $this->presentStatus($customer, $chatId, $target, 'balance.usdt.notice.hash_recorded');

        return true;
    }

    /**
     * @return array{0: TelegramNavigationSurface, 1: ?string}
     */
    private function surfaceFor(TelegramCallbackContext $context): array
    {
        $customer = $context->customer;
        $data = $context->data;

        if ($data === self::OPEN) {
            return [$this->usdt->topUpSurface($customer), null];
        }
        if ($data === self::HISTORY) {
            return [$this->usdt->historySurface($customer, $this->usdt->firstPage($customer)), null];
        }

        foreach ([self::OLDER_PREFIX, self::NEWER_PREFIX] as $prefix) {
            $token = $this->tokenFor($data, $prefix);
            if ($token === null) {
                continue;
            }
            $decoded = $this->tokens->decodeCursorToken($token);
            if ($decoded === null || (int) $decoded['customer_id'] !== (int) $customer->id) {
                return [$this->staleSurface($customer), 'balance.usdt.stale'];
            }
            $page = $prefix === self::OLDER_PREFIX
                ? $this->usdt->olderPage($customer, $decoded['cursor'])
                : $this->usdt->newerPage($customer, $decoded['cursor']);
            if ($page === null) {
                return [$this->staleSurface($customer), 'balance.usdt.stale'];
            }

            return [$this->usdt->historySurface($customer, $page), null];
        }

        if (preg_match('/\Ausdt:(?:confirm|cancel):[a-f0-9]{32}\z/D', $data) === 1) {
            $result = $this->funding->callback(
                (string) $customer->telegram_id,
                $context->chatId,
                $context->chatType,
                (int) $context->messageId,
                $data,
            );

            return $this->previewOutcomeSurface($customer, $result);
        }

        foreach ([self::STATUS_PREFIX, self::CHECK_PREFIX, self::HASH_PREFIX] as $prefix) {
            $targetId = $this->uuidFor($data, $prefix);
            if ($targetId === null) {
                continue;
            }
            $target = CustomerUsdtTopUp::query()
                ->whereKey($targetId)
                ->where('customer_id', $customer->id)
                ->first();
            if ($target === null) {
                return [$this->staleSurface($customer), 'balance.usdt.stale'];
            }
            $notice = match ($prefix) {
                self::CHECK_PREFIX => 'balance.usdt.notice.refreshed',
                self::HASH_PREFIX => 'balance.usdt.notice.hash_prompt',
                default => null,
            };
            if ($prefix === self::HASH_PREFIX) {
                $this->hashSession->promptHash($customer, (string) $target->id);
            }

            return [
                $this->usdt->statusSurface($customer, $target, $this->statuses->for($target), $notice),
                $notice,
            ];
        }

        return [$this->staleSurface($customer), 'balance.usdt.stale'];
    }

    /**
     * @return array{0: TelegramNavigationSurface, 1: ?string}
     */
    private function previewOutcomeSurface(Customer $customer, UsdtFundingIntentResult $result): array
    {
        if (in_array($result->outcome, ['opened', 'replayed'], true) && $result->intent !== null) {
            return [$this->usdt->previewSurface($customer, $result->intent), null];
        }
        if ($result->outcome === 'confirmed' && $result->topUp !== null) {
            return [
                $this->usdt->statusSurface(
                    $customer,
                    $result->topUp,
                    $this->statuses->for($result->topUp),
                    'balance.usdt.notice.confirmed',
                ),
                'balance.usdt.notice.confirmed',
            ];
        }
        if ($result->outcome === 'active_target') {
            $notice = 'balance.usdt.notice.active_target';
            if ($result->topUp !== null) {
                return [
                    $this->usdt->statusSurface(
                        $customer,
                        $result->topUp,
                        $this->statuses->for($result->topUp),
                        $notice,
                    ),
                    $notice,
                ];
            }

            return [$this->usdt->topUpSurface($customer, $notice), $notice];
        }

        return match ($result->outcome) {
            'cancelled' => [$this->usdt->topUpSurface($customer, 'balance.usdt.notice.cancelled'), 'balance.usdt.notice.cancelled'],
            'expired' => [$this->usdt->topUpSurface($customer, 'balance.usdt.notice.expired'), 'balance.usdt.notice.expired'],
            'invalid_amount' => [$this->usdt->topUpSurface($customer, 'balance.usdt.input.invalid'), 'balance.usdt.input.invalid'],
            'unavailable' => [$this->usdt->topUpSurface($customer, 'balance.usdt.topup.unavailable'), 'balance.usdt.topup.unavailable'],
            default => [$this->usdt->topUpSurface($customer, 'balance.usdt.stale'), 'balance.usdt.stale'],
        };
    }

    private function presentStale(TelegramCallbackContext $context): void
    {
        $currentRoot = $this->roots->currentForChat($context->customer, $context->chatId);
        $outcome = $currentRoot === null
            ? null
            : $this->navigation->presentOnRoot(
                $currentRoot,
                $context->chatId,
                $this->usdt->topUpSurface($context->customer, 'balance.usdt.stale'),
            );
        $this->bot->answerCallbackQuery(
            $context->callbackQueryId,
            ($outcome === null ? null : $this->navigation->feedback($context->customer, $outcome))
                ?? $this->locale->translate($context->customer, 'balance.usdt.stale'),
        );
    }

    private function presentStatus(Customer $customer, string $chatId, CustomerUsdtTopUp $target, string $noticeKey): void
    {
        $this->navigation->presentOrSend(
            $customer,
            $chatId,
            $this->usdt->statusSurface($customer, $target, $this->statuses->for($target), $noticeKey),
        );
    }

    private function staleSurface(Customer $customer): TelegramNavigationSurface
    {
        return $this->usdt->topUpSurface($customer, 'balance.usdt.stale');
    }

    private function tokenFor(string $data, string $prefix): ?string
    {
        if (! str_starts_with($data, $prefix)) {
            return null;
        }

        $token = substr($data, strlen($prefix));

        return $token !== '' && strlen($token) <= 64 ? $token : null;
    }

    private function uuidFor(string $data, string $prefix): ?string
    {
        if (! str_starts_with($data, $prefix)) {
            return null;
        }

        $uuid = substr($data, strlen($prefix));

        return Str::isUuid($uuid) ? $uuid : null;
    }
}
