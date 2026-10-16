<?php

namespace App\Services\Telegram;

use App\Enums\CustomerUsdtFundingRail;
use App\Enums\StoreBalanceTopUpOutcome;
use App\Enums\TelegramNavigationRenderOutcome;
use App\Enums\TelegramNavigationScreen;
use App\Models\Customer;
use App\Models\StoreBalanceFundingIntent;
use App\Models\StoreBalanceTopUp;
use App\Models\StoreChannelSetting;
use App\Models\TelegramNavigationRoot;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use App\Services\StoreBalance\StoreBalanceActivityDetail;
use App\Services\StoreBalance\StoreBalanceActivityItem;
use App\Services\StoreBalance\StoreBalanceActivityPage;
use App\Services\StoreBalance\StoreBalanceFundingIntentService;
use App\Services\StoreBalance\StoreBalanceFundingPolicyManager;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\StoreBalance\StoreBalanceSnapshot;
use App\Services\StoreBalance\StoreBalanceTopUpService;
use App\Services\StoreBalance\TopUp\StoreBalanceTopUpPresentation;
use App\Services\Telegram\Concerns\HasAdminWebAppButton;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtFunding\CustomerUsdtFundingGate;
use App\Support\Money;

class TelegramMenuIntents
{
    use HasAdminWebAppButton;

    public function __construct(
        private TelegramBotClient $bot,
        private TelegramAdminAccess $adminAccess,
        private TelegramLocale $locale,
        private TelegramSupportContact $supportContact,
        private CheckoutMaintenance $maintenance,
        private TelegramStoreStatsReport $statsReport,
        private TelegramNavigationRootService $roots,
        private TelegramNavigationPresenter $navigation,
        private TelegramWelcomeBanner $banner,
        private TelegramHtmlCaption $captions,
        private StoreBalanceLedger $balances,
        private TelegramBalanceActivityToken $activityTokens,
        private StoreBalanceFundingPolicyManager $fundingPolicies,
        private TelegramUiIcons $uiIcons,
        private CustomerUsdtBalanceLedger $usdtBalances,
        private CustomerUsdtFundingGate $usdtFundingGate,
    ) {}

    public function sendLanguagePicker(string $chatId, ?Customer $customer = null, bool $isPrivateChat = true): void
    {
        if ($customer === null || ! $isPrivateChat) {
            $this->bot->sendMessage(
                $chatId,
                $this->captions->html($this->locale->translate($customer, 'language.picker.prompt')),
                $this->locale->languageKeyboard(),
                ['parse_mode' => 'HTML'],
            );

            return;
        }

        $surface = $this->languageSurface($customer);
        if ($this->navigation->presentOnCurrentRoot($customer, $chatId, $surface) === null) {
            $this->roots->establish($customer, $chatId, $surface);
        }
    }

    public function sendWelcome(Customer $customer, string $chatId, bool $isPrivateChat = true): void
    {
        if (! $isPrivateChat) {
            $surface = $this->groupSurface($customer);
            $this->banner->send($chatId, $surface->caption, $surface->keyboard, null, $surface->options);

            return;
        }

        // A returning /start deliberately supersedes the pointer with a fresh
        // root instead of editing the previous one.
        $this->roots->establish($customer, $chatId, $this->homeSurface($customer, $chatId));
    }

    /**
     * Return to the canonical home, reusing the current root when this chat has
     * one and establishing it otherwise.
     */
    public function presentHome(Customer $customer, string $chatId): void
    {
        $surface = $this->homeSurface($customer, $chatId);
        if ($this->navigation->presentOnCurrentRoot($customer, $chatId, $surface) !== null) {
            return;
        }

        $this->roots->establish($customer, $chatId, $surface);
    }

    public function renderHome(TelegramNavigationRoot $root, Customer $customer, string $chatId): TelegramNavigationRenderOutcome
    {
        return $this->roots->render($root, $chatId, $this->homeSurface($customer, $chatId));
    }

    public function homeSurface(Customer $customer, string $chatId): TelegramNavigationSurface
    {
        $channelUrl = $this->storeChannelUrl();

        return new TelegramNavigationSurface(
            TelegramNavigationScreen::Home,
            $this->locale->code($customer),
            $this->privateCaption($customer, $channelUrl !== null),
            $this->homeKeyboard($customer, $chatId, $channelUrl),
        );
    }

    /**
     * Public-safe group surface: no identity, Store Balance, or financial
     * action is ever rendered or offered in a group chat.
     */
    public function groupSurface(Customer $customer): TelegramNavigationSurface
    {
        return new TelegramNavigationSurface(
            TelegramNavigationScreen::Home,
            $this->locale->code($customer),
            $this->groupCaption($customer),
            $this->groupKeyboard($customer),
        );
    }

    public function languageSurface(Customer $customer): TelegramNavigationSurface
    {
        $keyboard = $this->locale->languageKeyboard();
        if ($customer->locale !== null) {
            $keyboard['inline_keyboard'][] = $this->navigationRow($customer);
        }

        return new TelegramNavigationSurface(
            TelegramNavigationScreen::Picker,
            $customer->locale === null ? null : $this->locale->code($customer),
            $this->captions->html($this->locale->translate($customer, 'language.picker.prompt')),
            $keyboard,
        );
    }

    public function balanceSurface(Customer $customer): TelegramNavigationSurface
    {
        $balance = $this->balances->snapshot($customer);

        return $this->renderBalanceSurface($customer, $balance, null);
    }

    public function balanceActivitySurface(
        Customer $customer,
        StoreBalanceActivityPage $page,
        ?string $noticeKey = null,
    ): TelegramNavigationSurface {
        $balance = $this->balances->snapshot($customer);

        return $this->renderBalanceSurface(
            $customer,
            $balance,
            $balance->isAvailable() ? $page : null,
            $noticeKey,
        );
    }

    public function balanceActivityDetailSurface(
        Customer $customer,
        StoreBalanceActivityDetail $detail,
    ): TelegramNavigationSurface {
        $balance = $this->balances->snapshot($customer);
        if (! $balance->isAvailable()) {
            return $this->renderBalanceSurface($customer, $balance, null);
        }
        $item = $detail->item;
        $lines = [
            $this->locale->translate($customer, 'balance.activity.detail.title'),
            '━━━━━━━━━━━━━━',
            $this->locale->translate($customer, 'balance.activity.detail.kind', [
                'kind' => $this->locale->translate($customer, 'balance.activity.kind.'.$item->kind),
            ]),
            $this->locale->translate($customer, 'balance.activity.detail.state', [
                'state' => $this->locale->translate($customer, 'balance.activity.state.'.$item->state),
            ]),
        ];
        if ($item->isTopUp() && $item->requestedAmount !== null) {
            $lines[] = $this->locale->translate($customer, 'balance.activity.detail.requested', [
                'amount' => $this->locale->idr($item->requestedAmount),
            ]);
        }
        if ($item->signedAmount !== null) {
            $lines[] = $this->locale->translate($customer, 'balance.activity.detail.amount', [
                'amount' => $this->activityAmount($item),
            ]);
        }
        if ($item->creditedAmount !== null) {
            $lines[] = $this->locale->translate($customer, 'balance.activity.detail.credited', [
                'amount' => $this->locale->idr($item->creditedAmount),
            ]);
        }
        $lines[] = $this->locale->translate($customer, 'balance.activity.detail.date', [
            'date' => $this->locale->formatDate($customer, $item->occurredAt),
        ]);
        $lines[] = $this->locale->translate($customer, 'balance.activity.detail.reference', [
            'reference' => $item->reference,
        ]);

        $keyboard = [];
        if ($item->topUpCanRetry) {
            $keyboard[] = [[
                'text' => $this->locale->translate($customer, 'balance.topup.retry'),
                'callback_data' => StoreBalanceTopUpService::actionCallback('retry', $item->id),
            ]];
        }
        if ($item->topUpCanCheck) {
            $keyboard[] = [[
                'text' => $this->locale->translate($customer, 'balance.topup.check'),
                'callback_data' => StoreBalanceTopUpService::actionCallback('check', $item->id),
            ]];
        }
        if ($detail->relatedItem !== null) {
            $related = $detail->relatedItem;
            $keyboard[] = [[
                'text' => $this->locale->translate($customer, 'balance.activity.detail.original_button', [
                    'reference' => $related->reference,
                ]),
                'callback_data' => 'balance:a:d:'.$this->activityTokens->detailToken(
                    $customer->id,
                    $related->type,
                    $related->id,
                ),
            ]];
            $lines[] = $this->locale->translate($customer, 'balance.activity.detail.original', [
                'reference' => $related->reference,
                'amount' => $this->activityAmount($related),
            ]);
        }
        if ($item->isTopUp() && $item->signedAmount === null) {
            $lines[] = $this->locale->translate($customer, 'balance.activity.detail.support');
        }
        $keyboard[] = [[
            'text' => $this->locale->translate($customer, 'balance.activity.detail.back'),
            'callback_data' => 'balance',
        ], $this->navButton($customer, 'menu.home', 'menu:home')];

        return new TelegramNavigationSurface(
            TelegramNavigationScreen::Balance,
            $this->locale->code($customer),
            $this->captions->html(implode("\n", $lines)),
            ['inline_keyboard' => $keyboard],
        );
    }

    private function renderBalanceSurface(
        Customer $customer,
        StoreBalanceSnapshot $balance,
        ?StoreBalanceActivityPage $page,
        ?string $noticeKey = null,
    ): TelegramNavigationSurface {
        $lines = [
            $this->locale->translate($customer, 'balance.title'),
            '━━━━━━━━━━━━━━',
            $this->locale->translate($customer, 'balance.available', [
                'balance' => $balance->isAvailable()
                    ? $this->locale->idr($balance->balance())
                    : $this->locale->translate($customer, 'welcome.balance_unavailable'),
            ]),
        ];
        if ($noticeKey !== null) {
            $lines[] = '';
            $lines[] = $this->locale->translate($customer, $noticeKey);
        }

        if (! $balance->isAvailable()) {
            $lines[] = '';
            $lines[] = $this->locale->translate($customer, 'balance.activity.unavailable');
            $lines[] = $this->locale->translate($customer, 'balance.activity.support');
        }

        if ($page === null) {
            $usdt = $this->usdtBalanceSection($customer);
            $lines = array_merge($lines, $usdt['lines']);
            $keyboard = [];
            if ($balance->isAvailable()) {
                $keyboard[] = [[
                    'text' => $this->locale->translate($customer, 'balance.add'),
                    'callback_data' => 'balance:add',
                ]];
                $keyboard[] = [[
                    'text' => $this->locale->translate($customer, 'balance.activity.view'),
                    'callback_data' => 'balance:activity',
                ]];
            }

            return new TelegramNavigationSurface(
                TelegramNavigationScreen::Balance,
                $this->locale->code($customer),
                $this->captions->html(implode("\n", $lines)),
                ['inline_keyboard' => [
                    ...$keyboard,
                    ...$usdt['rows'],
                    $this->navigationRow($customer),
                ]],
            );
        }

        $lines[] = '';
        $lines[] = $this->locale->translate($customer, 'balance.activity.title');
        if ($page->isEmpty()) {
            $lines[] = $this->locale->translate($customer, 'balance.activity.empty');
        } else {
            foreach ($page->items as $index => $item) {
                $lines[] = $this->locale->translate($customer, 'balance.activity.row', [
                    'number' => $index + 1,
                    'kind' => $this->locale->translate($customer, 'balance.activity.kind.'.$item->kind),
                    'state' => $this->locale->translate($customer, 'balance.activity.state.'.$item->state),
                    'amount' => $this->activityAmount($item),
                ]);
                $lines[] = $this->locale->translate($customer, 'balance.activity.date', [
                    'date' => $this->locale->formatShortDate($customer, $item->occurredAt),
                ]);
                $lines[] = $this->locale->translate($customer, 'balance.activity.reference', [
                    'reference' => $item->reference,
                ]);
            }
        }

        $keyboard = [[[
            'text' => $this->locale->translate($customer, 'balance.add'),
            'callback_data' => 'balance:add',
        ]], $this->navigationRow($customer)];
        foreach ($page->items as $item) {
            $keyboard[] = [[
                'text' => $this->locale->translate($customer, 'balance.activity.detail_button', [
                    'reference' => $item->reference,
                ]),
                'callback_data' => 'balance:a:d:'.$this->activityTokens->detailToken(
                    $customer->id,
                    $item->type,
                    $item->id,
                ),
            ]];
        }

        $paging = [];
        if ($page->hasNewer && $page->newerCursor !== null) {
            $paging[] = [
                'text' => $this->locale->translate($customer, 'balance.activity.newer'),
                'callback_data' => 'balance:a:n:'.$this->activityTokens->cursorToken(
                    $customer->id,
                    $page->newerCursor,
                ),
            ];
        }
        if ($page->hasOlder && $page->olderCursor !== null) {
            $paging[] = [
                'text' => $this->locale->translate($customer, 'balance.activity.older'),
                'callback_data' => 'balance:a:o:'.$this->activityTokens->cursorToken(
                    $customer->id,
                    $page->olderCursor,
                ),
            ];
        }
        if ($paging !== []) {
            $keyboard[] = $paging;
        }

        return new TelegramNavigationSurface(
            TelegramNavigationScreen::Balance,
            $this->locale->code($customer),
            $this->captions->html(implode("\n", $lines)),
            ['inline_keyboard' => $keyboard],
        );
    }

    /**
     * USDT section of the private Saldo screen. A drifted projection or a
     * frozen account is shown as a localized unavailable/recovery state, never
     * as a fabricated zero, and never offers a funding action.
     *
     * @return array{lines: list<string>, rows: list<list<array<string, string>>>}
     */
    private function usdtBalanceSection(Customer $customer): array
    {
        $snapshot = $this->usdtBalances->snapshot($customer);
        $lines = [''];
        if (! $snapshot->isAvailable()) {
            $lines[] = $this->locale->translate($customer, 'balance.usdt.unavailable');
        } elseif ($snapshot->isFrozen()) {
            $lines[] = $this->locale->translate($customer, 'balance.usdt.frozen');
        } else {
            $lines[] = $this->locale->translate($customer, 'balance.usdt.available', [
                'amount' => UsdtMicroAmount::formatUnits($snapshot->balanceMicro()),
            ]);
        }

        $rows = [[[
            'text' => $this->locale->translate($customer, 'balance.usdt.history.button'),
            'callback_data' => 'usdt:history',
        ]]];

        if ($snapshot->isAvailable() && ! $snapshot->isFrozen()) {
            if ($this->usdtFundingGate->availability(CustomerUsdtFundingRail::BscUsdt)->available) {
                array_unshift($rows, [[
                    'text' => $this->locale->translate($customer, 'balance.usdt.topup.button'),
                    'callback_data' => 'balance:usdt',
                ]]);
            } else {
                $lines[] = $this->locale->translate($customer, 'balance.usdt.topup.unavailable');
            }
        }

        return ['lines' => $lines, 'rows' => $rows];
    }

    private function activityAmount(StoreBalanceActivityItem $item): string
    {
        if ($item->signedAmount === null) {
            return $this->locale->idr($item->amount);
        }

        return $item->signedAmount < 0
            ? '-'.$this->locale->idr(abs($item->signedAmount))
            : '+'.$this->locale->idr($item->signedAmount);
    }

    public function balanceCurrencySurface(Customer $customer): TelegramNavigationSurface
    {
        return new TelegramNavigationSurface(
            TelegramNavigationScreen::AddBalance,
            $this->locale->code($customer),
            $this->captions->html(implode("\n", [
                $this->locale->translate($customer, 'balance.currency.title'),
                '━━━━━━━━━━━━━━',
                $this->locale->translate($customer, 'balance.currency.prompt'),
            ])),
            ['inline_keyboard' => [
                [[
                    'text' => $this->locale->translate($customer, 'balance.currency.idr'),
                    'callback_data' => 'balance:currency:idr',
                ]],
                [[
                    'text' => $this->locale->translate($customer, 'balance.currency.usd'),
                    'callback_data' => 'balance:currency:usd',
                ]],
                $this->navigationRow($customer),
            ]],
        );
    }

    public function usdRailSurface(Customer $customer): TelegramNavigationSurface
    {
        return new TelegramNavigationSurface(
            TelegramNavigationScreen::AddBalance,
            $this->locale->code($customer),
            $this->captions->html(implode("\n", [
                $this->locale->translate($customer, 'balance.usd.title'),
                '━━━━━━━━━━━━━━',
                $this->locale->translate($customer, 'balance.usd.prompt'),
            ])),
            ['inline_keyboard' => [
                [[
                    'text' => $this->locale->translate($customer, 'balance.usd.binance_pay'),
                    'callback_data' => 'balance:usd:binance_pay',
                ]],
                [[
                    'text' => $this->locale->translate($customer, 'balance.usd.bsc'),
                    'callback_data' => 'balance:usd:bsc',
                ]],
                $this->navigationRow($customer),
            ]],
        );
    }

    public function usdAmountSurface(
        Customer $customer,
        CustomerUsdtFundingRail $rail,
        ?string $noticeKey = null,
    ): TelegramNavigationSurface {
        $railKey = $rail === CustomerUsdtFundingRail::BinancePay
            ? 'balance.usd.binance_pay'
            : 'balance.usd.bsc';
        $lines = [
            $this->locale->translate($customer, 'balance.usd.amount.title'),
            '━━━━━━━━━━━━━━',
            $this->locale->translate($customer, 'balance.usd.amount.rail', [
                'rail' => $this->locale->translate($customer, $railKey),
            ]),
        ];
        $copyable = [];
        $available = $this->usdtFundingGate->availability($rail)->available;
        if ($available) {
            if ($rail === CustomerUsdtFundingRail::BinancePay) {
                $payId = trim((string) config('services.binance.pay_id'));
                $receiver = $payId !== '' ? $payId : trim((string) config('services.binance.pay_uid'));
                $marker = "\x1Fbinance-receiver\x1F";
                $copyable[$marker] = '<code>'.htmlspecialchars($receiver, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</code>';
                $lines[] = $this->locale->translate($customer, $payId !== ''
                    ? 'balance.usdt.status.pay_id'
                    : 'balance.usd.amount.uid', [
                        'pay_id' => $marker,
                        'uid' => $marker,
                    ]);
                $lines[] = $this->locale->translate($customer, 'balance.usd.amount.parity');
            }
            $lines[] = $this->locale->translate($customer, 'balance.usd.amount.prompt');
            $lines[] = $this->locale->translate($customer, 'balance.usd.amount.precision');
        } else {
            $lines[] = $this->locale->translate($customer, 'balance.usd.amount.unavailable');
        }
        if ($noticeKey !== null) {
            $lines[] = '';
            $lines[] = $this->locale->translate($customer, $noticeKey);
        }

        return new TelegramNavigationSurface(
            $available ? TelegramNavigationScreen::UsdtFundingAmount : TelegramNavigationScreen::AddBalance,
            $this->locale->code($customer),
            strtr($this->captions->html(implode("\n", $lines)), $copyable),
            ['inline_keyboard' => [
                [[
                    'text' => $this->locale->translate($customer, 'balance.confirmation.cancel'),
                    'callback_data' => 'balance:currency:usd',
                ]],
                $this->navigationRow($customer),
            ]],
        );
    }

    public function addBalanceSurface(
        Customer $customer,
        ?string $noticeKey = null,
        array $noticeReplacements = [],
    ): TelegramNavigationSurface {
        $policy = $this->fundingPolicies->current();
        $lines = [
            $this->locale->translate($customer, 'balance.add.title'),
            '━━━━━━━━━━━━━━',
            $this->locale->translate($customer, 'balance.add.minimum', [
                'amount' => $this->locale->idr($policy->minimumAmount),
            ]),
            $this->locale->translate($customer, 'balance.add.prompt'),
            '',
            $this->locale->translate($customer, 'balance.add.command_hint'),
        ];
        if ($noticeKey !== null) {
            $lines[] = '';
            $lines[] = $this->locale->translate($customer, $noticeKey, $noticeReplacements);
        }
        $keyboard = [];

        foreach ($policy->presetAmounts as $index => $amount) {
            $keyboard[] = [[
                'text' => $this->locale->idr($amount),
                'callback_data' => $this->fundingPolicies->presetCallback($policy, $index),
            ]];
        }

        $keyboard[] = $this->navigationRow($customer);

        return new TelegramNavigationSurface(
            TelegramNavigationScreen::AddBalance,
            $this->locale->code($customer),
            $this->captions->html(implode("\n", $lines)),
            ['inline_keyboard' => $keyboard],
        );
    }

    public function fundingConfirmationSurface(
        Customer $customer,
        StoreBalanceFundingIntent $intent,
    ): TelegramNavigationSurface {
        $lines = [
            $this->locale->translate($customer, 'balance.confirmation.title'),
            '━━━━━━━━━━━━━━',
            $this->locale->translate($customer, 'balance.confirmation.credit', [
                'amount' => $this->locale->idr($intent->amount),
            ]),
            $this->locale->translate($customer, 'balance.confirmation.provider_note'),
            $this->locale->translate($customer, 'balance.confirmation.no_invoice'),
            $this->locale->translate($customer, 'balance.confirmation.expires', [
                'expires' => $this->locale->formatDate($customer, $intent->expires_at),
            ]),
        ];

        return new TelegramNavigationSurface(
            TelegramNavigationScreen::AddBalance,
            $this->locale->code($customer),
            $this->captions->html(implode("\n", $lines)),
            ['inline_keyboard' => [
                [[
                    'text' => $this->locale->translate($customer, 'balance.confirmation.confirm'),
                    'callback_data' => StoreBalanceFundingIntentService::actionCallback('confirm', $intent->nonce),
                ]],
                [
                    [
                        'text' => $this->locale->translate($customer, 'balance.confirmation.cancel'),
                        'callback_data' => StoreBalanceFundingIntentService::actionCallback('cancel', $intent->nonce),
                    ],
                    [
                        'text' => $this->locale->translate($customer, 'balance.confirmation.back'),
                        'callback_data' => StoreBalanceFundingIntentService::actionCallback('back', $intent->nonce),
                    ],
                ],
            ]],
        );
    }

    public function fundingTopUpSurface(
        Customer $customer,
        StoreBalanceTopUp $topUp,
        StoreBalanceTopUpPresentation $presentation,
        bool $instructionsSent = false,
        bool $confirmationReceived = false,
        ?string $confirmationNoticeKey = null,
        ?StoreBalanceTopUpOutboundOutcome $instructionOutcome = null,
        ?bool $instructionRetryable = null,
    ): TelegramNavigationSurface {
        // A confirmed top-up already owns a payment target, so the
        // pre-confirmation "no invoice yet" line must never be repeated here.
        $confirmationLines = $confirmationReceived ? [
            $this->locale->translate(
                $customer,
                $confirmationNoticeKey ?? 'balance.confirmation.accepted',
            ),
            $this->locale->translate($customer, 'balance.confirmation.credit', [
                'amount' => $this->locale->idr((int) $topUp->amount),
            ]),
        ] : [];
        if ($presentation->isPaid()) {
            $paidNoticeKey = $presentation->isRecovered()
                ? 'balance.topup.recovered'
                : 'balance.topup.paid';
            $lines = array_merge($confirmationLines, [
                $this->locale->translate($customer, $paidNoticeKey, [
                    'amount' => $this->locale->idr((int) $topUp->amount),
                ]),
            ]);
            $keyboard = [$this->navigationRow($customer)];
        } elseif (($presentation->isPayable() || $presentation->isAwaitingConfirmation())
            && $presentation->payable() !== null) {
            $awaitingConfirmation = $presentation->isAwaitingConfirmation();
            $payable = $presentation->payable();
            $lines = array_merge($confirmationLines, [
                $this->locale->translate($customer, 'balance.topup.instruction.title'),
                '━━━━━━━━━━━━━━',
                $this->locale->translate($customer, 'balance.topup.credit', [
                    'amount' => $this->locale->idr($payable->creditAmount()),
                ]),
                $this->locale->translate($customer, 'balance.topup.fee', [
                    'amount' => $this->locale->idr($payable->providerFee() ?? 0),
                ]),
                $this->locale->translate($customer, 'balance.topup.total', [
                    'amount' => $this->locale->idr($payable->totalAmount()),
                ]),
                $this->locale->translate($customer, 'balance.topup.deadline', [
                    'expires' => $this->locale->formatDate($customer, $payable->effectiveExpiresAt()),
                ]),
                $this->locale->translate(
                    $customer,
                    $awaitingConfirmation
                        ? 'balance.topup.awaiting_confirmation'
                        : 'balance.topup.unpaid',
                ),
                $this->locale->translate(
                    $customer,
                    $this->instructionNoticeKey($instructionsSent, $instructionOutcome, $instructionRetryable),
                ),
            ]);
            $keyboard = [];
            if ($instructionRetryable ?? $this->defaultInstructionRetryable($instructionOutcome)) {
                $keyboard[] = [[
                    'text' => $this->locale->translate($customer, 'balance.topup.retry'),
                    'callback_data' => StoreBalanceTopUpService::actionCallback('retry', $topUp->getKey()),
                ]];
            }
            $keyboard[] = [[
                'text' => $this->locale->translate($customer, 'balance.topup.check'),
                'callback_data' => StoreBalanceTopUpService::actionCallback('check', $topUp->getKey()),
            ]];
            $keyboard[] = $this->navigationRow($customer);
        } else {
            $noticeKey = match (true) {
                $presentation->isRejected() => 'balance.topup.rejected',
                $presentation->outcome() === StoreBalanceTopUpOutcome::ProviderUnavailable => 'balance.topup.provider_unavailable',
                $presentation->canRetry() => 'balance.topup.retryable',
                $presentation->isManualReview() => 'balance.topup.manual_review',
                default => match ($presentation->outcome()) {
                    StoreBalanceTopUpOutcome::RecoveryPending => 'balance.topup.pending',
                    StoreBalanceTopUpOutcome::ProviderTotalPending => 'balance.topup.retry_later',
                    StoreBalanceTopUpOutcome::InvalidProviderResult => 'balance.topup.invalid',
                    StoreBalanceTopUpOutcome::ProviderUnavailable => 'balance.topup.provider_unavailable',
                    StoreBalanceTopUpOutcome::Expired => 'balance.topup.expired',
                    default => 'balance.topup.preparing',
                },
            };
            $lines = array_merge($confirmationLines, [
                $this->locale->translate($customer, $noticeKey),
                $this->locale->translate($customer, 'balance.topup.help'),
            ]);
            $keyboard = [];
            if ($presentation->canRetry()) {
                $keyboard[] = [[
                    'text' => $this->locale->translate($customer, 'balance.topup.retry'),
                    'callback_data' => StoreBalanceTopUpService::actionCallback('retry', $topUp->getKey()),
                ]];
            }
            if ($presentation->outcome() !== StoreBalanceTopUpOutcome::Expired) {
                $keyboard[] = [[
                    'text' => $this->locale->translate($customer, 'balance.topup.check'),
                    'callback_data' => StoreBalanceTopUpService::actionCallback('check', $topUp->getKey()),
                ]];
            }
            $keyboard[] = $this->navigationRow($customer);
        }

        return new TelegramNavigationSurface(
            TelegramNavigationScreen::AddBalance,
            $this->locale->code($customer),
            $this->captions->html(implode("\n", $lines)),
            ['inline_keyboard' => $keyboard],
        );
    }

    private function instructionNoticeKey(
        bool $instructionsSent,
        ?StoreBalanceTopUpOutboundOutcome $instructionOutcome,
        ?bool $instructionRetryable,
    ): string {
        if ($instructionOutcome === null) {
            return $instructionsSent
                ? 'balance.topup.instructions_sent'
                : 'balance.topup.instructions_queued';
        }

        return match ($instructionOutcome) {
            StoreBalanceTopUpOutboundOutcome::Delivered => 'balance.topup.instructions_sent',
            StoreBalanceTopUpOutboundOutcome::RetryScheduled => 'balance.topup.instructions_retry_scheduled',
            StoreBalanceTopUpOutboundOutcome::Failed,
            StoreBalanceTopUpOutboundOutcome::NotEligible => ($instructionRetryable
                ?? $this->defaultInstructionRetryable($instructionOutcome))
                ? 'balance.topup.instructions_failed'
                : 'balance.topup.instructions_unavailable',
            StoreBalanceTopUpOutboundOutcome::ReviewRequired => 'balance.topup.instructions_review',
            StoreBalanceTopUpOutboundOutcome::InProgress,
            StoreBalanceTopUpOutboundOutcome::Queued => 'balance.topup.instructions_queued',
        };
    }

    private function defaultInstructionRetryable(?StoreBalanceTopUpOutboundOutcome $instructionOutcome): bool
    {
        return in_array($instructionOutcome, [
            StoreBalanceTopUpOutboundOutcome::Failed,
            StoreBalanceTopUpOutboundOutcome::NotEligible,
            StoreBalanceTopUpOutboundOutcome::RetryScheduled,
        ], true);
    }

    public function supportSurface(Customer $customer): TelegramNavigationSurface
    {
        $adminContactUrl = $this->supportContact->resolve()['url'];
        $keyboard = [];
        if ($adminContactUrl) {
            $keyboard[] = [[
                'text' => $this->locale->translate($customer, 'support.contact'),
                'url' => $adminContactUrl,
            ]];
        }
        $keyboard[] = $this->navigationRow($customer);

        return new TelegramNavigationSurface(
            TelegramNavigationScreen::Support,
            $this->locale->code($customer),
            $this->captions->html($this->locale->translate($customer, 'support.message')),
            ['inline_keyboard' => $keyboard],
        );
    }

    /**
     * Localized Back/Home chrome for routine navigation screens.
     *
     * @return list<array<string, string>>
     */
    public function navigationRow(?Customer $customer): array
    {
        return [
            $this->navButton($customer, 'menu.back', 'menu:back'),
            $this->navButton($customer, 'menu.home', 'menu:home'),
        ];
    }

    /**
     * One navigation button with the standard shape: label text, an optional
     * custom emoji icon, and its callback data.
     *
     * @return array<string, string>
     */
    private function navButton(?Customer $customer, string $localeKey, string $callbackData): array
    {
        return [...$this->navLabel($customer, $localeKey), 'callback_data' => $callbackData];
    }

    /**
     * @return array<string, string>
     */
    private function navLabel(?Customer $customer, string $localeKey): array
    {
        return $this->uiIcons->apply($localeKey, $this->locale->translate($customer, $localeKey));
    }

    /**
     * @return array<string, mixed>
     */
    private function homeKeyboard(Customer $customer, string $chatId, ?string $channelUrl): array
    {
        $keyboard = ['inline_keyboard' => [
            [
                $this->navButton($customer, 'menu.products', 'katalog'),
            ],
            [
                $this->navButton($customer, 'menu.balance', 'balance'),
                $this->navButton($customer, 'menu.orders', 'menu:orders'),
            ],
            [
                $this->navButton($customer, 'menu.language', 'menu:language'),
                $this->navButton($customer, 'menu.support', 'menu:support'),
            ],
        ]];

        if ($channelUrl !== null) {
            $keyboard['inline_keyboard'][] = [[
                ...$this->navLabel($customer, 'menu.channel'),
                'url' => $channelUrl,
            ]];
        }
        if ($this->adminAccess->isAdmin($customer, $chatId)) {
            $adminButton = $this->adminDashboardInlineButton($this->uiIcons->renderLegacyIcons('🛠️ Admin Dashboard'));
            if ($adminButton !== null) {
                $keyboard['inline_keyboard'][] = [$adminButton];
            }
            $keyboard['inline_keyboard'][] = [[
                'text' => $this->uiIcons->renderLegacyIcons('📊 Statistik'),
                'callback_data' => 'statistik_harian',
            ]];
            $keyboard['inline_keyboard'][] = [[
                'text' => $this->uiIcons->renderLegacyIcons('🎨 Ikon Produk'),
                'callback_data' => 'producticon:list',
            ]];
        }

        foreach ($keyboard['inline_keyboard'] as &$row) {
            foreach ($row as &$button) {
                $button['style'] = 'primary';
            }
            unset($button);
        }
        unset($row);

        return $keyboard;
    }

    /**
     * @return array<string, mixed>
     */
    private function groupKeyboard(Customer $customer): array
    {
        return ['inline_keyboard' => [
            [
                $this->navButton($customer, 'menu.products', 'katalog'),
            ],
        ]];
    }

    private function privateCaption(Customer $customer, bool $showChannelInvitation): string
    {
        $replacements = $this->welcomeReplacements($customer);
        $profile = $this->locale->translate($customer, 'welcome.profile', $replacements);
        $activity = $this->locale->translate($customer, 'welcome.activity', $replacements);

        return $this->captions->htmlWithBlockquotes(
            $this->locale->translate($customer, 'welcome.caption', [
                ...$replacements,
                'profile' => $profile,
                'activity' => $activity,
                'channel_invitation' => $showChannelInvitation
                    ? "\n\n".$this->locale->translate($customer, 'welcome.channel_invitation')
                    : '',
            ]).$this->maintenanceNotice($customer),
            [$profile, $activity],
        );
    }

    private function storeChannelUrl(): ?string
    {
        return StoreChannelSetting::current()->publicUrl();
    }

    private function groupCaption(Customer $customer): string
    {
        $metrics = $this->statsReport->startMetrics();
        $activity = $this->locale->translate($customer, 'welcome.activity', [
            'bot_users' => $metrics['bot_users'],
            'successful_orders' => $metrics['successful_orders'],
        ]);

        return $this->captions->htmlWithBlockquotes(
            $this->locale->translate($customer, 'welcome.group_caption', [
                'greeting' => implode(' ', $this->timeAwareGreeting($customer)),
                'activity' => $activity,
            ]).$this->maintenanceNotice($customer),
            [$activity],
        );
    }

    /**
     * Customer-owned Store Balance and transaction totals are read fresh and
     * never included in the shared bounded-staleness global metrics cache.
     *
     * @return array<string, string|int>
     */
    private function welcomeReplacements(Customer $customer): array
    {
        $metrics = $this->statsReport->startMetrics();
        $balance = $this->balances->snapshot($customer);
        $transactionTotals = $this->statsReport->customerTransactionTotals($customer);

        return [
            'greeting' => implode(' ', $this->timeAwareGreeting($customer)),
            'first_name' => $this->welcomeName($customer),
            'telegram_id' => (string) $customer->telegram_id,
            'username' => $this->displayUsername($customer),
            'transactions' => $this->formatTransactionTotals($transactionTotals),
            'balance' => $balance->isAvailable()
                ? $this->locale->idr($balance->balance())
                : $this->locale->translate($customer, 'welcome.balance_unavailable'),
            'bot_users' => $metrics['bot_users'],
            'successful_orders' => $metrics['successful_orders'],
        ];
    }

    /**
     * @param  array{IDR: int, USDT: int}  $totals
     */
    private function formatTransactionTotals(array $totals): string
    {
        return $this->locale->idr($totals['IDR']).' / '.Money::usdt($totals['USDT']);
    }

    public function sendAccountSummary(Customer $customer, string $chatId): void
    {
        $ordersCount = $customer->orders()->count();
        $latestOrder = $customer->orders()->latest()->first();
        $lines = [
            $this->locale->translate($customer, 'account.title'),
            $this->locale->translate($customer, 'account.name').($customer->first_name ?: '-'),
            $this->locale->translate($customer, 'account.username').'@'.($customer->username ?: '-'),
            $this->locale->translate($customer, 'account.total_orders').$ordersCount,
        ];
        if ($latestOrder) {
            $lines[] = $this->locale->translate($customer, 'account.latest_order').$latestOrder->invoice_number.' · '.$this->locale->accountPaymentStatus($customer, $latestOrder->payment_status);
        }
        $lines[] = '';
        $lines[] = $this->locale->translate($customer, 'account.follow_up');

        $this->bot->sendMessage($chatId, implode("\n", $lines), ['inline_keyboard' => [[['text' => $this->locale->translate($customer, 'account.orders_button'), 'callback_data' => 'status']]]]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function timeAwareGreeting(Customer $customer): array
    {
        $hour = now('Asia/Jakarta')->hour;

        return match (true) {
            $hour >= 5 && $hour < 11 => [$this->uiIcons->renderLegacyIcons('🌅'), $this->locale->translate($customer, 'welcome.greeting.morning')],
            $hour >= 11 && $hour < 15 => [$this->uiIcons->renderLegacyIcons('☀️'), $this->locale->translate($customer, 'welcome.greeting.afternoon')],
            $hour >= 15 && $hour < 18 => [$this->uiIcons->renderLegacyIcons('🌇'), $this->locale->translate($customer, 'welcome.greeting.evening')],
            default => [$this->uiIcons->renderLegacyIcons('🌙'), $this->locale->translate($customer, 'welcome.greeting.night')],
        };
    }

    private function maintenanceNotice(Customer $customer): string
    {
        $maintenance = $this->maintenance->current();
        if (! $maintenance->active) {
            return '';
        }

        $message = "\n\n".$this->locale->translate($customer, 'menu.maintenance');
        if ($maintenance->publicNote !== null) {
            $message .= $this->locale->translate($customer, 'maintenance.admin_note', ['note' => $maintenance->publicNote]);
        }

        return $message;
    }

    private function welcomeName(Customer $customer): string
    {
        $firstName = $this->profileName($customer);

        return $firstName !== '' ? $firstName : $this->locale->translate($customer, 'welcome.default_name');
    }

    private function profileName(Customer $customer): string
    {
        $firstName = trim((string) $customer->first_name);

        return strcasecmp($firstName, 'Telegram Customer') === 0 ? '' : $firstName;
    }

    private function displayUsername(Customer $customer): string
    {
        $username = trim((string) $customer->username);

        return $username !== '' ? '@'.ltrim($username, '@') : $this->locale->translate($customer, 'welcome.missing_value');
    }
}
