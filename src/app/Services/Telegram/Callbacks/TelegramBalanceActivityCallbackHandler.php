<?php

namespace App\Services\Telegram\Callbacks;

use App\Services\StoreBalance\StoreBalanceActivityService;
use App\Services\Telegram\TelegramBalanceActivityToken;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;
use App\Services\Telegram\TelegramCatalogQuerySession;
use App\Services\Telegram\TelegramIntentSession;
use App\Services\Telegram\TelegramLocale;
use App\Services\Telegram\TelegramMenuIntents;
use App\Services\Telegram\TelegramNavigationPresenter;
use App\Services\Telegram\TelegramNavigationSurface;

final class TelegramBalanceActivityCallbackHandler implements TelegramCallbackHandler
{
    private const OPEN = 'balance:activity';

    private const OLDER_PREFIX = 'balance:a:o:';

    private const NEWER_PREFIX = 'balance:a:n:';

    private const DETAIL_PREFIX = 'balance:a:d:';

    public function __construct(
        private readonly TelegramBotClient $bot,
        private readonly TelegramMenuIntents $menu,
        private readonly StoreBalanceActivityService $activities,
        private readonly TelegramBalanceActivityToken $tokens,
        private readonly TelegramNavigationPresenter $navigation,
        private readonly TelegramLocale $locale,
        private readonly TelegramIntentSession $session,
        private readonly TelegramCatalogQuerySession $catalogQueries,
    ) {}

    public function stage(): TelegramCallbackStage
    {
        return TelegramCallbackStage::AfterCommunityGate;
    }

    public function allowsNonPrivateChat(TelegramCallbackContext $context): bool
    {
        return false;
    }

    public function supports(TelegramCallbackContext $context): bool
    {
        return $context->data === self::OPEN
            || $this->tokenFor($context->data, self::OLDER_PREFIX) !== null
            || $this->tokenFor($context->data, self::NEWER_PREFIX) !== null
            || $this->tokenFor($context->data, self::DETAIL_PREFIX) !== null;
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        if (! $this->supports($context)) {
            return false;
        }

        $root = $this->navigation->matchRoot($context->customer, $context);
        if ($root === null) {
            $this->bot->answerCallbackQuery(
                $context->callbackQueryId,
                $this->locale->translate($context->customer, 'callback.navigation_stale'),
            );

            return true;
        }

        $this->session->forgetWithLock($context->customer);
        $this->catalogQueries->forget($context->customer);
        $surface = $this->surfaceFor($context);
        $outcome = $this->navigation->presentOnRoot($root, $context->chatId, $surface);
        $this->bot->answerCallbackQuery(
            $context->callbackQueryId,
            $this->navigation->feedback($context->customer, $outcome),
        );

        return true;
    }

    private function surfaceFor(TelegramCallbackContext $context): TelegramNavigationSurface
    {
        if ($context->data === self::OPEN) {
            return $this->menu->balanceActivitySurface(
                $context->customer,
                $this->activities->firstPage($context->customer),
            );
        }

        $data = $context->data;
        foreach ([self::OLDER_PREFIX, self::NEWER_PREFIX] as $prefix) {
            $token = $this->tokenFor($data, $prefix);
            if ($token === null) {
                continue;
            }

            $decoded = $this->tokens->decodeCursorToken($token);
            if ($decoded === null || (int) $decoded['customer_id'] !== (int) $context->customer->id) {
                return $this->staleSurface($context);
            }

            $page = $prefix === self::OLDER_PREFIX
                ? $this->activities->olderPage($context->customer, $decoded['cursor'])
                : $this->activities->newerPage($context->customer, $decoded['cursor']);
            if ($page === null) {
                return $this->staleSurface($context);
            }

            return $this->menu->balanceActivitySurface($context->customer, $page);
        }

        $token = $this->tokenFor($data, self::DETAIL_PREFIX);
        if ($token === null) {
            return $this->staleSurface($context);
        }

        $decoded = $this->tokens->decodeDetailToken($token);
        if ($decoded === null || (int) $decoded['customer_id'] !== (int) $context->customer->id) {
            return $this->staleSurface($context);
        }

        $detail = $this->activities->detail(
            $context->customer,
            $decoded['type'],
            (int) $decoded['id'],
        );
        if ($detail === null) {
            return $this->staleSurface($context);
        }

        return $this->menu->balanceActivityDetailSurface($context->customer, $detail);
    }

    private function staleSurface(TelegramCallbackContext $context): TelegramNavigationSurface
    {
        return $this->menu->balanceActivitySurface(
            $context->customer,
            $this->activities->firstPage($context->customer),
            'balance.activity.stale',
        );
    }

    private function tokenFor(string $data, string $prefix): ?string
    {
        if (! str_starts_with($data, $prefix)) {
            return null;
        }

        $token = substr($data, strlen($prefix));

        return $token === '' ? null : $token;
    }
}
