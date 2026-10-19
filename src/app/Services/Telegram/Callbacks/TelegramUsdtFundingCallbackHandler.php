<?php

namespace App\Services\Telegram\Callbacks;

use App\Enums\CustomerUsdtFundingRail;
use App\Models\Customer;
use App\Models\CustomerUsdtTopUp;
use App\Services\Binance\BinanceOrderId;
use App\Services\Bsc\BscTransactionInput;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;
use App\Services\Telegram\TelegramCatalogQuerySession;
use App\Services\Telegram\TelegramIntentSession;
use App\Services\Telegram\TelegramLocale;
use App\Services\Telegram\TelegramMenuIntents;
use App\Services\Telegram\TelegramNavigationPresenter;
use App\Services\Telegram\TelegramNavigationRootService;
use App\Services\Telegram\TelegramNavigationSurface;
use App\Services\Telegram\TelegramUsdtActivityToken;
use App\Services\Telegram\TelegramUsdtBalanceIntents;
use App\Services\Telegram\TelegramUsdtFundingActions;
use App\Services\Telegram\TelegramUsdtFundingPresenter;
use App\Services\Telegram\TelegramUsdtFundingSession;
use App\Services\UsdtTopUps\BinanceUsdtTopUpSettlement;
use App\Services\UsdtTopUps\UsdtTopUpSettlement;
use App\Services\UsdtTopUps\UsdtTopUpStatusReader;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

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

    private const ORDER_PREFIX = 'usdt:order:';

    private const CLOSE_PREFIX = 'usdt:close:';

    public function __construct(
        private readonly TelegramBotClient $bot,
        private readonly TelegramUsdtBalanceIntents $usdt,
        private readonly TelegramUsdtFundingActions $funding,
        private readonly TelegramUsdtFundingPresenter $presenter,
        private readonly TelegramUsdtActivityToken $tokens,
        private readonly TelegramUsdtFundingSession $hashSession,
        private readonly TelegramMenuIntents $menu,
        private readonly UsdtTopUpStatusReader $statuses,
        private readonly UsdtTopUpSettlement $settlement,
        private readonly BinanceUsdtTopUpSettlement $binanceSettlement,
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
        } catch (Throwable $error) {
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
        if ($this->tokenFor($data, self::OLDER_PREFIX) !== null
            || $this->tokenFor($data, self::NEWER_PREFIX) !== null) {
            return true;
        }
        foreach ([self::STATUS_PREFIX, self::CHECK_PREFIX, self::HASH_PREFIX, self::ORDER_PREFIX, self::CLOSE_PREFIX] as $prefix) {
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

        $result = $this->funding->openConfirmedIntent(
            (string) $customer->telegram_id,
            $chatId,
            'private',
            (int) $root->message_id,
            $amount,
            CustomerUsdtFundingRail::BscShared,
        );
        $this->navigation->presentOnRoot(
            $root,
            $chatId,
            $this->presenter->surfaceFor($customer, CustomerUsdtFundingRail::BscShared, $result),
        );
    }

    /**
     * Consume one prompted Binance Order ID as a bounded lookup hint: show that
     * the system is checking, run the authoritative provider lookup, then render
     * the durable result. The hint never credits the balance by itself.
     */
    public function handleOrderIdInput(Customer $customer, string $chatId, string $text): bool
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
        $snapshot = is_array($target->payment_snapshot) ? $target->payment_snapshot : [];
        $targetRail = $target->rail instanceof CustomerUsdtFundingRail ? $target->rail->value : (string) ($target->rail ?? '');
        if (($targetRail !== '' ? $targetRail : (string) ($snapshot['rail'] ?? '')) !== CustomerUsdtFundingRail::BinancePay->value) {
            return false;
        }
        if (! $this->statuses->for($target)->acceptsCustomerLookup()) {
            $this->hashSession->forget($customer);
            $this->presentStatus($customer, $chatId, $target, 'balance.usdt.notice.hash_closed');

            return true;
        }
        $orderId = BinanceOrderId::normalize(trim($text));
        if ($orderId === null) {
            $this->presentStatus($customer, $chatId, $target, 'balance.usdt.notice.order_invalid');

            return true;
        }

        $this->verifyAndPresent(
            $customer,
            $chatId,
            $target,
            'balance.usdt.checking.order',
            fn () => $this->binanceSettlement->submit($customer, (string) $target->id, $orderId),
            'balance.usdt.notice.order_recorded',
        );

        return true;
    }

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
        $hash = BscTransactionInput::normalize($text);
        if ($hash === null) {
            $this->presentStatus($customer, $chatId, $target, 'balance.usdt.notice.hash_invalid');

            return true;
        }

        $this->verifyAndPresent(
            $customer,
            $chatId,
            $target,
            'balance.usdt.checking.hash',
            fn () => $this->settlement->submit($customer, (string) $target->id, $hash),
            'balance.usdt.notice.hash_recorded',
        );

        return true;
    }

    /**
     * One verification interaction: the transient checking surface occupies the
     * message the Customer is looking at, the authoritative rail lookup runs,
     * and the durable result replaces the placeholder in the same message.
     */
    private function verifyAndPresent(
        Customer $customer,
        string $chatId,
        CustomerUsdtTopUp $target,
        string $checkingKey,
        callable $submit,
        string $recordedKey,
    ): void {
        $this->navigation->presentOrSend($customer, $chatId, $this->usdt->checkingSurface($customer, $checkingKey));

        try {
            $submit();
        } catch (AuthorizationException) {
            $this->hashSession->forget($customer);
            $this->presentStatus($customer, $chatId, $target, 'balance.usdt.notice.hash_closed');

            return;
        } catch (InvalidArgumentException) {
            $this->hashSession->forget($customer);
            $this->presentStatus($customer, $chatId, $target, 'balance.usdt.notice.hash_limited');

            return;
        }

        try {
            if ($this->railOf($target) === CustomerUsdtFundingRail::BinancePay) {
                $this->binanceSettlement->check((string) $target->id);
            } else {
                $this->settlement->check((string) $target->id);
            }
        } catch (Throwable) {
            // The hint is durable. Recovery retries settlement; the webhook
            // still renders the honest current state for this target.
        }

        $this->hashSession->forget($customer);
        $fresh = $target->refresh();
        // A rail check only means an inspection ran; the paid notice requires
        // durable settlement evidence, never the boolean return value.
        $status = $this->statuses->for($fresh);
        $this->presentStatus(
            $customer,
            $chatId,
            $fresh,
            $status->isSettled() || $status->balanceAfterMicro !== null
                ? 'balance.usdt.notice.paid'
                : $recordedKey,
        );
    }

    private function railOf(CustomerUsdtTopUp $target): ?CustomerUsdtFundingRail
    {
        $snapshot = is_array($target->payment_snapshot) ? $target->payment_snapshot : [];
        $value = $target->rail instanceof CustomerUsdtFundingRail ? $target->rail->value : (string) ($target->rail ?? '');

        return CustomerUsdtFundingRail::tryFrom($value !== '' ? $value : (string) ($snapshot['rail'] ?? ''));
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

        $closeId = $this->uuidFor($data, self::CLOSE_PREFIX);
        if ($closeId !== null) {
            $target = CustomerUsdtTopUp::query()
                ->whereKey($closeId)
                ->where('customer_id', $customer->id)
                ->first();
            if ($target === null) {
                return [$this->staleSurface($customer), 'balance.usdt.stale'];
            }
            if ($this->funding->cancelTarget($customer, (string) $target->id)) {
                return [
                    $this->menu->usdAmountSurface(
                        $customer,
                        $this->railOf($target) ?? CustomerUsdtFundingRail::BscShared,
                        'balance.usdt.notice.cancelled_target',
                    ),
                    'balance.usdt.notice.cancelled_target',
                ];
            }

            return [
                $this->usdt->statusSurface(
                    $customer,
                    $target->fresh(),
                    $this->statuses->for($target),
                    'balance.usdt.notice.cancel_unavailable',
                ),
                'balance.usdt.notice.cancel_unavailable',
            ];
        }

        foreach ([self::STATUS_PREFIX, self::CHECK_PREFIX, self::HASH_PREFIX, self::ORDER_PREFIX] as $prefix) {
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
                self::ORDER_PREFIX => 'balance.usdt.notice.order_prompt',
                default => null,
            };
            $targetRail = $target->rail instanceof CustomerUsdtFundingRail ? $target->rail->value : (string) ($target->rail ?? '');
            $railValue = $targetRail !== '' ? $targetRail : (string) (is_array($target->payment_snapshot) ? ($target->payment_snapshot['rail'] ?? '') : '');
            if ($prefix === self::CHECK_PREFIX) {
                try {
                    if ($railValue === CustomerUsdtFundingRail::BinancePay->value) {
                        $this->binanceSettlement->check((string) $target->id);
                    } else {
                        $this->settlement->check((string) $target->id);
                    }
                } catch (Throwable) {
                    // A provider/RPC failure must never fail the webhook update.
                    // Durable work stays leased and recovery keeps retrying,
                    // while the surface below renders the honest durable state.
                }
            }
            if (in_array($prefix, [self::HASH_PREFIX, self::ORDER_PREFIX], true)
                && $this->statuses->for($target)->acceptsCustomerLookup()) {
                $this->hashSession->promptHash($customer, (string) $target->id);
            }

            return [
                $this->usdt->statusSurface($customer, $target, $this->statuses->for($target), $notice),
                $notice,
            ];
        }

        return [$this->staleSurface($customer), 'balance.usdt.stale'];
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
