<?php

namespace App\Services\Telegram;

use App\Enums\StoreBalanceTopUpStatus;
use App\Enums\TelegramNavigationRenderOutcome;
use App\Enums\TelegramNavigationScreen;
use App\Models\Customer;
use App\Models\StoreBalanceFundingIntent;
use App\Models\StoreBalanceTopUp;
use App\Models\TelegramNavigationRoot;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use App\Services\KlikQris\KlikQrisNativeQrisOutcome;
use App\Services\KlikQris\KlikQrisTopUpLifecycleResult;
use App\Services\StoreBalance\StoreBalanceFundingIntentService;
use App\Services\StoreBalance\StoreBalanceFundingPolicyManager;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\StoreBalance\StoreBalanceTopUpService;
use App\Services\Telegram\Concerns\HasAdminWebAppButton;

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
        private StoreBalanceFundingPolicyManager $fundingPolicies,
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
        return new TelegramNavigationSurface(
            TelegramNavigationScreen::Home,
            $this->locale->code($customer),
            $this->captions->html($this->privateCaption($customer)),
            $this->homeKeyboard($customer, $chatId),
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
            $this->captions->html($this->groupCaption($customer)),
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
        $lines = [
            $this->locale->translate($customer, 'balance.title'),
            '━━━━━━━━━━━━━━',
            $this->locale->translate($customer, 'balance.available', [
                'balance' => $balance->isAvailable()
                    ? $this->locale->idr($balance->balance())
                    : $this->locale->translate($customer, 'welcome.balance_unavailable'),
            ]),
        ];

        return new TelegramNavigationSurface(
            TelegramNavigationScreen::Balance,
            $this->locale->code($customer),
            $this->captions->html(implode("\n", $lines)),
            ['inline_keyboard' => [
                [[
                    'text' => $this->locale->translate($customer, 'balance.add'),
                    'callback_data' => 'balance:add',
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

    public function fundingConfirmationAcceptedSurface(
        Customer $customer,
        StoreBalanceFundingIntent $intent,
    ): TelegramNavigationSurface {
        return new TelegramNavigationSurface(
            TelegramNavigationScreen::AddBalance,
            $this->locale->code($customer),
            $this->captions->html(implode("\n", [
                $this->locale->translate($customer, 'balance.confirmation.accepted'),
                $this->locale->translate($customer, 'balance.confirmation.credit', [
                    'amount' => $this->locale->idr($intent->amount),
                ]),
                $this->locale->translate($customer, 'balance.confirmation.no_invoice'),
            ])),
            ['inline_keyboard' => [$this->navigationRow($customer)]],
        );
    }

    public function fundingTopUpSurface(
        Customer $customer,
        StoreBalanceTopUp $topUp,
        KlikQrisTopUpLifecycleResult $result,
        bool $instructionsSent = false,
        bool $confirmationReceived = false,
        ?string $confirmationNoticeKey = null,
    ): TelegramNavigationSurface {
        $confirmationLines = $confirmationReceived ? [
            $this->locale->translate(
                $customer,
                $confirmationNoticeKey ?? 'balance.confirmation.accepted',
            ),
            $this->locale->translate($customer, 'balance.confirmation.credit', [
                'amount' => $this->locale->idr((int) $topUp->amount),
            ]),
            $this->locale->translate($customer, 'balance.confirmation.no_invoice'),
        ] : [];
        if ($topUp->status === StoreBalanceTopUpStatus::Paid) {
            $lines = array_merge($confirmationLines, [
                $this->locale->translate($customer, 'balance.topup.paid', [
                    'amount' => $this->locale->idr((int) $topUp->amount),
                ]),
            ]);
            $keyboard = [$this->navigationRow($customer)];
        } elseif ($result->isPayable() && $result->payable() !== null) {
            $payable = $result->payable();
            $lines = array_merge($confirmationLines, [
                $this->locale->translate($customer, 'balance.topup.instruction.title'),
                '━━━━━━━━━━━━━━',
                $this->locale->translate($customer, 'balance.topup.credit', [
                    'amount' => $this->locale->idr($payable->creditAmount()),
                ]),
                $this->locale->translate($customer, 'balance.topup.fee', [
                    'amount' => $this->locale->idr($payable->providerFee()),
                ]),
                $this->locale->translate($customer, 'balance.topup.total', [
                    'amount' => $this->locale->idr($payable->totalAmount()),
                ]),
                $this->locale->translate($customer, 'balance.topup.deadline', [
                    'expires' => $this->locale->formatDate($customer, $payable->effectiveExpiresAt()),
                ]),
                $this->locale->translate($customer, 'balance.topup.unpaid'),
                $this->locale->translate(
                    $customer,
                    $instructionsSent ? 'balance.topup.instructions_sent' : 'balance.topup.instructions_queued',
                ),
            ]);
            $keyboard = [[
                [
                    'text' => $this->locale->translate($customer, 'balance.topup.check'),
                    'callback_data' => StoreBalanceTopUpService::actionCallback('check', $topUp->getKey()),
                ],
            ], $this->navigationRow($customer)];
        } else {
            $noticeKey = $topUp->canRetryCreate()
                ? 'balance.topup.retryable'
                : match ($result->outcome()) {
                    KlikQrisNativeQrisOutcome::RecoveryPending => 'balance.topup.uncertain',
                    KlikQrisNativeQrisOutcome::ProviderTotalPending => 'balance.topup.retryable',
                    KlikQrisNativeQrisOutcome::InvalidProviderResult => 'balance.topup.invalid',
                    KlikQrisNativeQrisOutcome::Expired => 'balance.topup.expired',
                    default => 'balance.topup.preparing',
                };
            $lines = array_merge($confirmationLines, [
                $this->locale->translate($customer, $noticeKey),
                $this->locale->translate($customer, 'balance.topup.help'),
            ]);
            $keyboard = [];
            if ($topUp->canRetryCreate()) {
                $keyboard[] = [[
                    'text' => $this->locale->translate($customer, 'balance.topup.retry'),
                    'callback_data' => StoreBalanceTopUpService::actionCallback('retry', $topUp->getKey()),
                ]];
            }
            if ($result->outcome() !== KlikQrisNativeQrisOutcome::Expired) {
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
            [
                'text' => $this->locale->translate($customer, 'menu.back'),
                'callback_data' => 'menu:back',
            ],
            [
                'text' => $this->locale->translate($customer, 'menu.home'),
                'callback_data' => 'menu:home',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function homeKeyboard(Customer $customer, string $chatId): array
    {
        $keyboard = ['inline_keyboard' => [
            [
                ['text' => $this->locale->translate($customer, 'menu.products'), 'callback_data' => 'katalog'],
            ],
            [
                ['text' => $this->locale->translate($customer, 'menu.balance'), 'callback_data' => 'balance'],
                ['text' => $this->locale->translate($customer, 'menu.orders'), 'callback_data' => 'menu:orders'],
            ],
            [
                ['text' => $this->locale->translate($customer, 'menu.language'), 'callback_data' => 'menu:language'],
                ['text' => $this->locale->translate($customer, 'menu.support'), 'callback_data' => 'menu:support'],
            ],
        ]];

        if ($this->adminAccess->isAdmin($customer, $chatId)) {
            $adminButton = $this->adminDashboardInlineButton('🛠️ Admin Dashboard');
            if ($adminButton !== null) {
                $keyboard['inline_keyboard'][] = [$adminButton];
            }
        }

        return $keyboard;
    }

    /**
     * @return array<string, mixed>
     */
    private function groupKeyboard(Customer $customer): array
    {
        return ['inline_keyboard' => [
            [
                ['text' => $this->locale->translate($customer, 'menu.products'), 'callback_data' => 'katalog'],
            ],
        ]];
    }

    private function privateCaption(Customer $customer): string
    {
        return $this->locale->translate($customer, 'welcome.caption', $this->welcomeReplacements($customer))
            .$this->maintenanceNotice($customer);
    }

    private function groupCaption(Customer $customer): string
    {
        $metrics = $this->statsReport->startMetrics();

        return $this->locale->translate($customer, 'welcome.group_caption', [
            'greeting' => implode(' ', $this->timeAwareGreeting($customer)),
            'bot_users' => $metrics['bot_users'],
            'successful_orders' => $metrics['successful_orders'],
        ]).$this->maintenanceNotice($customer);
    }

    /**
     * The Customer Store Balance is read fresh from its own projection and is
     * never part of the shared bounded-staleness global metrics cache.
     *
     * @return array<string, string|int>
     */
    private function welcomeReplacements(Customer $customer): array
    {
        $metrics = $this->statsReport->startMetrics();
        $balance = $this->balances->snapshot($customer);

        return [
            'greeting' => implode(' ', $this->timeAwareGreeting($customer)),
            'first_name' => $this->welcomeName($customer),
            'telegram_id' => (string) $customer->telegram_id,
            'username' => $this->displayUsername($customer),
            'balance' => $balance->isAvailable()
                ? $this->locale->idr($balance->balance())
                : $this->locale->translate($customer, 'welcome.balance_unavailable'),
            'bot_users' => $metrics['bot_users'],
            'successful_orders' => $metrics['successful_orders'],
        ];
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
            $hour >= 5 && $hour < 11 => ['🌅', $this->locale->translate($customer, 'welcome.greeting.morning')],
            $hour >= 11 && $hour < 15 => ['☀️', $this->locale->translate($customer, 'welcome.greeting.afternoon')],
            $hour >= 15 && $hour < 18 => ['🌇', $this->locale->translate($customer, 'welcome.greeting.evening')],
            default => ['🌙', $this->locale->translate($customer, 'welcome.greeting.night')],
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
