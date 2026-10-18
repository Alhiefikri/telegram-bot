<?php

namespace App\Services\Telegram\Callbacks;

use App\Enums\CustomerUsdtFundingRail;
use App\Enums\StoreBalanceFundingIntentClaimOutcome;
use App\Enums\TelegramNavigationRenderOutcome;
use App\Enums\TelegramNavigationScreen;
use App\Models\Customer;
use App\Models\StoreBalanceFundingIntent;
use App\Models\StoreBalanceTopUp;
use App\Models\TelegramNavigationRoot;
use App\Services\StoreBalance\StoreBalanceFundingAmountValidator;
use App\Services\StoreBalance\StoreBalanceFundingIntentInput;
use App\Services\StoreBalance\StoreBalanceFundingIntentOpening;
use App\Services\StoreBalance\StoreBalanceFundingIntentService;
use App\Services\StoreBalance\StoreBalanceFundingPolicyManager;
use App\Services\StoreBalance\StoreBalanceTopUpService;
use App\Services\StoreBalance\TopUp\StoreBalanceTopUpPresentation;
use App\Services\Telegram\StoreBalanceTopUpOutbound;
use App\Services\Telegram\StoreBalanceTopUpOutboundOutcome;
use App\Services\Telegram\StoreBalanceTopUpOutboundResult;
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
use App\Services\Telegram\TelegramUsdtBalanceIntents;
use App\Services\Telegram\TelegramUsdtFundingActions;
use App\Services\Telegram\TelegramUsdtFundingSession;
use Throwable;

final class TelegramBalanceFundingCallbackHandler implements TelegramCallbackHandler
{
    private const ADD_BALANCE = 'balance:add';

    private const CURRENCY_PREFIX = 'balance:currency:';

    public function __construct(
        private readonly TelegramBotClient $bot,
        private readonly TelegramMenuIntents $menu,
        private readonly StoreBalanceFundingPolicyManager $fundingPolicies,
        private readonly StoreBalanceFundingAmountValidator $amounts,
        private readonly StoreBalanceFundingIntentService $intents,
        private readonly StoreBalanceTopUpService $topUps,
        private readonly StoreBalanceTopUpOutbound $topUpOutbound,
        private readonly TelegramUsdtBalanceIntents $usdtBalance,
        private readonly TelegramUsdtFundingActions $usdtFunding,
        private readonly TelegramUsdtFundingSession $usdtFundingSession,
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

    public function isDepositCommand(string $text): bool
    {
        return $this->amounts->isDepositCommand($text);
    }

    /** Consume a prompted exact USDT amount; invalid input keeps the prompt alive. */
    public function handleUsdtAmountInput(Customer $customer, string $chatId, string $text): bool
    {
        if ((string) $customer->telegram_id !== $chatId) {
            return false;
        }
        $prompt = $this->usdtFundingSession->promptedAmount($customer);
        $root = $this->roots->currentForChat($customer, $chatId);
        if ($prompt === null) {
            if ($root?->screen === TelegramNavigationScreen::UsdtFundingAmount) {
                return $this->restartAmount($customer, $chatId);
            }

            return false;
        }
        if ($prompt['chat_id'] !== $chatId) {
            return false;
        }
        if ($root === null || (int) $root->id !== $prompt['root_id']
            || (int) $root->message_id !== $prompt['message_id']
            || $root->screen !== TelegramNavigationScreen::UsdtFundingAmount) {
            return $this->restartAmount($customer, $chatId);
        }
        $rail = CustomerUsdtFundingRail::tryFrom($prompt['rail']);
        if ($rail === null) {
            return $this->restartAmount($customer, $chatId);
        }

        $result = $this->usdtFunding->preview(
            (string) $customer->telegram_id,
            $chatId,
            'private',
            $prompt['message_id'],
            trim($text),
            $rail,
        );
        if ($result->outcome !== 'invalid_amount') {
            $this->usdtFundingSession->forget($customer);
        }
        if ($result->outcome === 'invalid_amount') {
            $this->presentAmount($customer, $chatId, $root, $rail, 'balance.usd.amount.invalid');

            return true;
        }
        if ($result->outcome === 'unauthorized' || $result->outcome === 'stale') {
            return $this->restartAmount($customer, $chatId);
        }
        if (in_array($result->outcome, ['opened', 'replayed'], true) && $result->intent !== null) {
            $this->navigation->presentOnRoot($root, $chatId, $this->usdtBalance->previewSurface($customer, $result->intent));

            return true;
        }

        $this->navigation->presentOnRoot(
            $root,
            $chatId,
            $this->menu->usdAmountSurface($customer, $rail, 'balance.usd.amount.unavailable'),
        );

        return true;
    }

    private function restartAmount(Customer $customer, string $chatId): bool
    {
        $this->usdtFundingSession->forget($customer);
        $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'balance.usd.amount.restart'));
        $this->navigation->presentOrEstablish($customer, $chatId, $this->menu->usdRailSurface($customer));

        return true;
    }

    private function presentAmount(
        Customer $customer,
        string $chatId,
        TelegramNavigationRoot $root,
        CustomerUsdtFundingRail $rail,
        ?string $noticeKey = null,
    ): TelegramNavigationRenderOutcome {
        $outcome = $this->navigation->presentOnRoot($root, $chatId, $this->menu->usdAmountSurface($customer, $rail, $noticeKey));
        $this->usdtFundingSession->forget($customer);
        if (in_array($outcome, [TelegramNavigationRenderOutcome::Applied, TelegramNavigationRenderOutcome::Unchanged, TelegramNavigationRenderOutcome::Replaced], true)) {
            $rendered = $this->roots->currentForChat($customer, $chatId);
            if ($rendered?->screen === TelegramNavigationScreen::UsdtFundingAmount) {
                $this->usdtFundingSession->promptAmount($customer, $chatId, (int) $rendered->id, (int) $rendered->message_id, $rail->value);
            }
        }

        return $outcome;
    }

    public function supports(TelegramCallbackContext $context): bool
    {
        return $context->data === self::ADD_BALANCE
            || in_array($context->data, [
                self::CURRENCY_PREFIX.'idr',
                self::CURRENCY_PREFIX.'usd',
                'balance:usd:binance_pay',
                'balance:usd:bsc',
            ], true)
            || StoreBalanceFundingPolicyManager::isPresetCallbackFormat($context->data)
            || StoreBalanceFundingIntentService::isActionCallbackFormat($context->data)
            || StoreBalanceTopUpService::isActionCallbackFormat($context->data);
    }

    public function handleDepositCommand(Customer $customer, string $chatId, string $text): void
    {
        $root = $this->roots->currentForChat($customer, $chatId);
        if ($root === null) {
            $root = $this->roots->establish(
                $customer,
                $chatId,
                $this->menu->addBalanceSurface($customer),
            );
        }

        $token = $this->amounts->customAmountToken($text) ?? '';
        $opening = $this->intents->open(
            $customer,
            $chatId,
            $root->id,
            $root->message_id,
            StoreBalanceFundingIntentInput::custom($token),
        );

        $this->presentOpening($customer, $chatId, $root, $opening);
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        if (! $this->supports($context)) {
            return false;
        }

        $topUpAction = StoreBalanceTopUpService::actionFromCallback($context->data);
        if ($topUpAction !== null) {
            return $this->handleTopUpAction($context, $topUpAction['action'], $topUpAction['topUpId']);
        }

        $root = $this->navigation->matchRoot($context->customer, $context);
        if ($root === null
            && $context->data === 'balance'
            && $context->messageId !== null
            && $this->topUpOutbound->hasSentReceiptFor(
                $context->customer,
                $context->chatId,
                $context->messageId,
            )) {
            $currentRoot = $this->roots->currentForChat($context->customer, $context->chatId);
            if ($currentRoot !== null) {
                $this->session->forgetWithLock($context->customer);
                $this->catalogQueries->forget($context->customer);
                $outcome = $this->navigation->presentOnRoot(
                    $currentRoot,
                    $context->chatId,
                    $this->menu->balanceSurface($context->customer),
                );
                $this->bot->answerCallbackQuery($context->callbackQueryId, $this->navigation->feedback($context->customer, $outcome));

                return true;
            }
        }

        if ($root === null) {
            $currentRoot = $this->roots->currentForChat($context->customer, $context->chatId);
            $notice = $this->recoveryNotice($context->data);
            if ($currentRoot !== null) {
                $outcome = $this->navigation->presentOnRoot(
                    $currentRoot,
                    $context->chatId,
                    $this->menu->addBalanceSurface($context->customer, $notice),
                );
                $feedback = $this->navigation->feedback($context->customer, $outcome)
                    ?? $this->locale->translate(
                        $context->customer,
                        $notice ?? 'callback.navigation_stale',
                    );
                $this->bot->answerCallbackQuery($context->callbackQueryId, $feedback);

                return true;
            }

            $this->bot->answerCallbackQuery(
                $context->callbackQueryId,
                $this->locale->translate($context->customer, 'callback.navigation_stale'),
            );

            return true;
        }

        $this->session->forgetWithLock($context->customer);
        $this->usdtFundingSession->forget($context->customer);
        $this->catalogQueries->forget($context->customer);

        if ($context->data === self::ADD_BALANCE) {
            $outcome = $this->navigation->presentOnRoot(
                $root,
                $context->chatId,
                $this->menu->balanceCurrencySurface($context->customer),
            );
            $this->bot->answerCallbackQuery($context->callbackQueryId, $this->navigation->feedback($context->customer, $outcome));

            return true;
        }

        if ($context->data === self::CURRENCY_PREFIX.'idr') {
            $outcome = $this->presentChooser($root, $context);
            $this->bot->answerCallbackQuery($context->callbackQueryId, $this->navigation->feedback($context->customer, $outcome));

            return true;
        }

        if ($context->data === self::CURRENCY_PREFIX.'usd') {
            $outcome = $this->navigation->presentOnRoot(
                $root,
                $context->chatId,
                $this->menu->usdRailSurface($context->customer),
            );
            $this->bot->answerCallbackQuery($context->callbackQueryId, $this->navigation->feedback($context->customer, $outcome));

            return true;
        }

        if ($context->data === 'balance:usd:bsc' || $context->data === 'balance:usd:binance_pay') {
            $rail = $context->data === 'balance:usd:binance_pay'
                ? CustomerUsdtFundingRail::BinancePay
                : CustomerUsdtFundingRail::BscShared;
            $outcome = $this->presentAmount($context->customer, $context->chatId, $root, $rail);
            $this->bot->answerCallbackQuery($context->callbackQueryId, $this->navigation->feedback($context->customer, $outcome));

            return true;
        }
        $action = StoreBalanceFundingIntentService::actionFromCallback($context->data);
        if ($action !== null) {
            $this->handleAction($root, $context, $action['action'], $action['nonce']);

            return true;
        }

        // The callback carries only a signed, versioned identity. The current
        // policy resolves the amount, and a missing identity fails closed.
        $selection = $this->fundingPolicies->resolvePresetCallback($context->data);
        if ($selection === null) {
            $outcome = $this->presentChooser($root, $context, 'balance.add.preset_stale');
            $feedback = $this->navigation->feedback($context->customer, $outcome)
                ?? $this->locale->translate($context->customer, 'balance.add.preset_stale');
            $this->bot->answerCallbackQuery($context->callbackQueryId, $feedback);

            return true;
        }

        $opening = $this->intents->open(
            $context->customer,
            $context->chatId,
            $root->id,
            $root->message_id,
            StoreBalanceFundingIntentInput::preset($selection),
        );
        $this->presentOpening($context->customer, $context->chatId, $root, $opening);
        $this->bot->answerCallbackQuery($context->callbackQueryId);

        return true;
    }

    private function recoveryNotice(string $data): ?string
    {
        if ($data === self::ADD_BALANCE) {
            return null;
        }

        if (StoreBalanceFundingIntentService::isActionCallbackFormat($data)) {
            return 'balance.confirmation.stale';
        }

        return 'balance.add.preset_stale';
    }

    private function handleAction(
        TelegramNavigationRoot $root,
        TelegramCallbackContext $context,
        string $action,
        string $nonce,
    ): void {
        $claim = $this->intents->claim(
            $context->customer,
            $context->chatId,
            $root->id,
            $root->message_id,
            $nonce,
            $action,
        );

        $notice = null;
        $noticeReplacements = [];
        $feedback = null;
        $surface = null;

        switch ($claim->outcome) {
            case StoreBalanceFundingIntentClaimOutcome::Confirmed:
                $surface = $this->surfaceForConfirmedTopUp($context, $claim->intent, confirmationReceived: true);
                $feedback = $this->locale->translate($context->customer, 'balance.confirmation.accepted');
                break;
            case StoreBalanceFundingIntentClaimOutcome::AlreadyConfirmed:
                if ($claim->intent !== null) {
                    $surface = $this->surfaceForConfirmedTopUp(
                        $context,
                        $claim->intent,
                        confirmationReceived: true,
                        confirmationNoticeKey: 'balance.confirmation.already_confirmed',
                    );
                } else {
                    $notice = 'balance.confirmation.already_confirmed';
                    $surface = $this->menu->addBalanceSurface($context->customer, $notice, $noticeReplacements);
                }
                $feedback = $this->locale->translate($context->customer, 'balance.confirmation.already_confirmed');
                break;
            case StoreBalanceFundingIntentClaimOutcome::Cancelled:
            case StoreBalanceFundingIntentClaimOutcome::AlreadyCancelled:
                $notice = $action === 'back' ? null : 'balance.confirmation.cancelled';
                $feedback = $notice === null
                    ? null
                    : $this->locale->translate($context->customer, 'balance.confirmation.cancelled');
                $surface = $this->menu->addBalanceSurface($context->customer, $notice, $noticeReplacements);
                break;
            case StoreBalanceFundingIntentClaimOutcome::Expired:
                $notice = 'balance.confirmation.expired';
                $surface = $this->menu->addBalanceSurface($context->customer, $notice, $noticeReplacements);
                $feedback = $this->locale->translate($context->customer, $notice);
                break;
            case StoreBalanceFundingIntentClaimOutcome::Stale:
                $notice = 'balance.confirmation.stale';
                $surface = $this->menu->addBalanceSurface($context->customer, $notice, $noticeReplacements);
                $feedback = $this->locale->translate($context->customer, $notice);
                break;
        }

        if ($surface !== null) {
            $outcome = $this->navigation->presentOnRoot($root, $context->chatId, $surface);
            $feedback ??= $this->navigation->feedback($context->customer, $outcome);
        }
        $this->bot->answerCallbackQuery($context->callbackQueryId, $feedback);
    }

    private function surfaceForConfirmedTopUp(
        TelegramCallbackContext $context,
        ?StoreBalanceFundingIntent $intent,
        bool $confirmationReceived = false,
        ?string $confirmationNoticeKey = null,
    ): TelegramNavigationSurface {
        if ($intent === null) {
            return $this->menu->addBalanceSurface($context->customer, 'balance.topup.retryable');
        }

        try {
            $topUp = $this->topUps->createOrReuseConfirmedIntent($intent);
        } catch (Throwable $exception) {
            report($exception);

            return $this->menu->addBalanceSurface($context->customer, 'balance.topup.retryable');
        }

        $presentation = $this->resolveTopUp($topUp);
        $instructionHandoff = $this->topUpInstructionsHandoff($topUp, $presentation);

        return $this->menu->fundingTopUpSurface(
            $context->customer,
            $topUp->fresh(),
            $presentation,
            instructionsSent: $instructionHandoff->isDelivered(),
            confirmationReceived: $confirmationReceived,
            confirmationNoticeKey: $confirmationNoticeKey,
            instructionOutcome: $instructionHandoff->outcome,
            instructionRetryable: $instructionHandoff->canRetry(),
        );
    }

    private function resolveTopUp(
        StoreBalanceTopUp $topUp,
        bool $reconcile = false,
    ): StoreBalanceTopUpPresentation {
        try {
            if ($reconcile) {
                $this->topUps->reconcile($topUp);
            } else {
                $this->topUps->lifecycle($topUp);
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        return $this->topUps->presentation($topUp);
    }

    private function topUpInstructionsHandoff(
        StoreBalanceTopUp $topUp,
        StoreBalanceTopUpPresentation $presentation,
    ): StoreBalanceTopUpOutboundResult {
        if ($presentation->payable() === null
            || (! $presentation->isPayable() && ! $presentation->isAwaitingConfirmation())) {
            return new StoreBalanceTopUpOutboundResult(StoreBalanceTopUpOutboundOutcome::NotEligible);
        }

        return $this->topUpOutbound->handoffInstructions($topUp);
    }

    private function handleTopUpAction(
        TelegramCallbackContext $context,
        string $action,
        string $topUpId,
    ): bool {
        $topUp = $this->topUps->forAction($context->customer, $context->chatId, $topUpId);
        $root = $this->roots->currentForChat($context->customer, $context->chatId);

        $rootMatches = $topUp !== null
            && $root !== null
            && (int) $topUp->telegram_navigation_root_id === (int) $root->getKey()
            && (int) $topUp->canonical_message_id === (int) $root->message_id;
        $messageMatches = $context->messageId !== null
            && $rootMatches
            && (int) $context->messageId === (int) $root->message_id;
        $instructionMatches = $context->messageId !== null
            && $topUp !== null
            && $this->topUpOutbound->hasSentInstructionFor(
                $topUp,
                $context->chatId,
                $context->messageId,
            );

        if (! $rootMatches || (! $messageMatches && ! $instructionMatches)) {
            $this->bot->answerCallbackQuery(
                $context->callbackQueryId,
                $this->locale->translate($context->customer, 'callback.navigation_stale'),
            );

            return true;
        }

        $this->session->forgetWithLock($context->customer);
        $this->catalogQueries->forget($context->customer);

        if ($topUp === null || $root === null) {
            return true;
        }

        $presentation = $this->resolveTopUp($topUp, $action === 'check');
        $topUp->refresh();
        $instructionHandoff = $action === 'retry'
            && ($presentation->isPayable() || $presentation->isAwaitingConfirmation())
            ? $this->topUpOutbound->retryInstructions($topUp)
            : $this->topUpInstructionsHandoff($topUp, $presentation);
        $outcome = $this->navigation->presentOnRoot(
            $root,
            $context->chatId,
            $this->menu->fundingTopUpSurface(
                $context->customer,
                $topUp->fresh(),
                $presentation,
                instructionsSent: $instructionHandoff->isDelivered(),
                instructionOutcome: $instructionHandoff->outcome,
                instructionRetryable: $instructionHandoff->canRetry(),
            ),
        );

        $this->bot->answerCallbackQuery(
            $context->callbackQueryId,
            $this->navigation->feedback($context->customer, $outcome),
        );

        return true;
    }

    private function presentOpening(
        Customer $customer,
        string $chatId,
        TelegramNavigationRoot $root,
        StoreBalanceFundingIntentOpening $opening,
    ): void {
        if (! $opening->accepted()) {
            $notice = match ($opening->rejection) {
                'below_minimum' => 'balance.deposit.minimum',
                'stale_policy' => 'balance.add.preset_stale',
                'stale' => 'balance.confirmation.stale',
                default => 'balance.deposit.invalid',
            };
            $replacements = $notice === 'balance.deposit.minimum'
                ? ['amount' => $this->locale->idr($this->fundingPolicies->current()->minimumAmount)]
                : [];
            $this->navigation->presentOnRoot(
                $root,
                $chatId,
                $this->menu->addBalanceSurface($customer, $notice, $replacements),
            );

            return;
        }

        $this->navigation->presentOnRoot(
            $root,
            $chatId,
            $this->menu->fundingConfirmationSurface($customer, $opening->intent),
        );
    }

    private function presentChooser(
        TelegramNavigationRoot $root,
        TelegramCallbackContext $context,
        ?string $notice = null,
        array $replacements = [],
    ): TelegramNavigationRenderOutcome {
        return $this->navigation->presentOnRoot(
            $root,
            $context->chatId,
            $this->menu->addBalanceSurface($context->customer, $notice, $replacements),
        );
    }
}
