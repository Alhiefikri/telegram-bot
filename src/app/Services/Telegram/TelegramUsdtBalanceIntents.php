<?php

namespace App\Services\Telegram;

use App\Enums\CustomerUsdtFundingRail;
use App\Enums\TelegramNavigationScreen;
use App\Models\Customer;
use App\Models\CustomerUsdtFundingIntent;
use App\Models\CustomerUsdtFundingPolicy;
use App\Models\CustomerUsdtTopUp;
use App\Services\UsdtBalance\CustomerUsdtActivityCursor;
use App\Services\UsdtBalance\CustomerUsdtActivityPage;
use App\Services\UsdtBalance\CustomerUsdtActivityService;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtFunding\CustomerUsdtFundingGate;
use App\Services\UsdtTopUps\UsdtTopUpStatus;

/**
 * USDT balance presentation only: top-up target preparation preview, confirm
 * chooser, honest status and personal keyset history. Nothing here credits,
 * debits or repairs money, calls a rail/RPC, or renders an amount in IDR.
 */
final class TelegramUsdtBalanceIntents
{
    private const TOP_UP_CALLBACK = 'balance:usdt';

    public function __construct(
        private readonly TelegramMenuIntents $menu,
        private readonly TelegramLocale $locale,
        private readonly TelegramHtmlCaption $captions,
        private readonly CustomerUsdtActivityService $activities,
        private readonly TelegramUsdtActivityToken $tokens,
        private readonly CustomerUsdtFundingGate $gate,
    ) {}

    public function topUpSurface(Customer $customer, ?string $noticeKey = null): TelegramNavigationSurface
    {
        $policy = CustomerUsdtFundingPolicy::current();
        $availability = $this->gate->availability(CustomerUsdtFundingRail::BscUsdt);
        $lines = [
            $this->locale->translate($customer, 'balance.usdt.topup.title'),
            '━━━━━━━━━━━━━━',
            $this->locale->translate($customer, 'balance.usdt.topup.network'),
            $this->locale->translate($customer, 'balance.usdt.topup.token'),
            $this->locale->translate($customer, 'balance.usdt.topup.fee', [
                'amount' => $this->usdt($policy?->applicationFeeMicro() ?? 0),
            ]),
            '',
            $this->locale->translate($customer, 'balance.usdt.topup.command_hint', ['command' => '/usdt']),
            $this->locale->translate($customer, 'balance.usdt.topup.exact_note'),
        ];
        if ($noticeKey !== null) {
            $lines[] = '';
            $lines[] = $this->locale->translate($customer, $noticeKey);
        }
        if (! $availability->available) {
            $lines[] = '';
            $lines[] = $this->locale->translate($customer, 'balance.usdt.topup.unavailable');
        }

        return new TelegramNavigationSurface(
            TelegramNavigationScreen::UsdtBalance,
            $this->locale->code($customer),
            $this->captions->html(implode("\n", $lines)),
            ['inline_keyboard' => [[$this->historyRow($customer)], $this->menu->navigationRow($customer)]],
        );
    }

    public function previewSurface(Customer $customer, CustomerUsdtFundingIntent $intent): TelegramNavigationSurface
    {
        $snapshot = is_array($intent->payment_snapshot) ? $intent->payment_snapshot : [];
        $intentRail = $intent->rail instanceof CustomerUsdtFundingRail ? $intent->rail->value : (string) ($intent->rail ?? '');
        $rail = CustomerUsdtFundingRail::tryFrom($intentRail !== '' ? $intentRail : (string) ($snapshot['rail'] ?? 'bsc_usdt'))
            ?? CustomerUsdtFundingRail::BscUsdt;
        $isBinance = $rail === CustomerUsdtFundingRail::BinancePay;
        $copyable = [];
        $lines = [
            $this->locale->translate($customer, 'balance.usdt.preview.title'),
            '━━━━━━━━━━━━━━',
            ...($isBinance ? [
                $this->locale->translate($customer, 'balance.usdt.preview.nominal', [
                    'amount' => $this->usdt((int) $intent->amount_micro),
                ]),
            ] : []),
            $this->locale->translate($customer, $isBinance ? 'balance.usdt.preview.usd_credit' : 'balance.usdt.preview.credit', [
                'amount' => $this->usdt((int) $intent->amount_micro),
            ]),
            $this->locale->translate($customer, 'balance.usdt.preview.fee', [
                'amount' => $this->usdt((int) $intent->fee_micro),
            ]),
            $this->locale->translate($customer, 'balance.usdt.preview.payable', [
                'amount' => $this->copyableValue($this->usdt((int) $intent->payable_micro), $copyable),
            ]),
            $this->locale->translate($customer, 'balance.usdt.preview.rail', [
                'rail' => $this->locale->translate($customer, $rail === CustomerUsdtFundingRail::BinancePay
                    ? 'balance.usd.binance_pay'
                    : 'balance.usd.bsc'),
            ]),
            ...($isBinance ? [
                $this->locale->translate($customer, ! empty($snapshot['receiver_pay_id'])
                    ? 'balance.usdt.status.pay_id' : 'balance.usdt.status.uid', [
                        'pay_id' => $this->copyableValue((string) ($snapshot['receiver_pay_id'] ?? $snapshot['receiver_uid'] ?? ''), $copyable),
                    ]),
            ] : [
                $this->locale->translate($customer, 'balance.usdt.preview.network'),
                $this->locale->translate($customer, 'balance.usdt.preview.token'),
                $this->locale->translate($customer, 'balance.usdt.preview.receiver', [
                    'address' => $this->copyableValue((string) ($snapshot['receiver_address'] ?? ''), $copyable),
                ]),
            ]),
            $this->locale->translate($customer, 'balance.usdt.preview.deadline', [
                'deadline' => $this->locale->formatDate($customer, $intent->expires_at),
            ]),
            $this->locale->translate($customer, 'balance.usdt.preview.payment_window', [
                'minutes' => intdiv((int) ($snapshot['payment_ttl_seconds'] ?? 0), 60),
            ]),
            $this->locale->translate($customer, $rail === CustomerUsdtFundingRail::BinancePay
                ? 'balance.usdt.preview.binance_warning'
                : 'balance.usdt.preview.warning'),
        ];

        return new TelegramNavigationSurface(
            TelegramNavigationScreen::UsdtBalance,
            $this->locale->code($customer),
            strtr($this->captions->html(implode("\n", $lines)), $copyable),
            ['inline_keyboard' => [
                [[
                    'text' => $this->locale->translate($customer, 'balance.usdt.preview.confirm'),
                    'callback_data' => TelegramUsdtFundingActions::callbackData('confirm', (string) $intent->nonce),
                ]],
                [
                    [
                        'text' => $this->locale->translate($customer, 'balance.usdt.preview.cancel'),
                        'callback_data' => TelegramUsdtFundingActions::callbackData('cancel', (string) $intent->nonce),
                    ],
                    [
                        'text' => $this->locale->translate($customer, 'balance.confirmation.back'),
                        'callback_data' => 'balance:currency:usd',
                    ],
                ],
            ]],
        );
    }

    public function statusSurface(
        Customer $customer,
        CustomerUsdtTopUp $target,
        UsdtTopUpStatus $status,
        ?string $noticeKey = null,
    ): TelegramNavigationSurface {
        $snapshot = is_array($target->payment_snapshot) ? $target->payment_snapshot : [];
        $targetRail = $target->rail instanceof CustomerUsdtFundingRail ? $target->rail->value : (string) ($target->rail ?? '');
        $isBinance = ($targetRail !== '' ? $targetRail : (string) ($snapshot['rail'] ?? '')) === CustomerUsdtFundingRail::BinancePay->value;
        $copyable = [];
        $lines = [
            $this->locale->translate($customer, 'balance.usdt.status.title'),
            '━━━━━━━━━━━━━━',
        ];
        if ($noticeKey !== null) {
            $lines[] = $this->locale->translate($customer, $noticeKey);
            $lines[] = '';
        }
        if ($isBinance || $status->balanceAfterMicro !== null) {
            $lines[] = $this->locale->translate($customer, 'balance.usdt.receipt.submitted', [
                'amount' => $this->usdt((int) $target->amount_micro),
            ]);
        }
        if ($status->receivedMicro !== null) {
            $lines[] = $this->locale->translate($customer, 'balance.usdt.receipt.received', [
                'amount' => $this->usdt($status->receivedMicro),
            ]);
        }
        $lines[] = $this->locale->translate($customer, $isBinance
            ? ($status->balanceAfterMicro !== null ? 'balance.usdt.receipt.usd_credit' : 'balance.usdt.preview.usd_credit')
            : 'balance.usdt.status.credit', [
                'amount' => $this->usdt($status->creditMicro),
            ]);
        if ($status->feeMicro > 0 || $isBinance) {
            $lines[] = $this->locale->translate($customer, 'balance.usdt.status.fee', [
                'amount' => $this->usdt($status->feeMicro),
            ]);
        }
        $lines[] = $this->locale->translate($customer, 'balance.usdt.status.payable', [
            'amount' => $this->copyableValue($this->usdt($status->payableMicro), $copyable),
        ]);
        if ($status->balanceBeforeMicro !== null && $status->balanceAfterMicro !== null) {
            $lines[] = $this->locale->translate($customer, $isBinance
                ? 'balance.usdt.receipt.balance_before_usd' : 'balance.usdt.receipt.balance_before', [
                    'amount' => $this->usdt($status->balanceBeforeMicro),
                ]);
            $lines[] = $this->locale->translate($customer, $isBinance
                ? 'balance.usdt.receipt.balance_after_usd' : 'balance.usdt.receipt.balance_after', [
                    'amount' => $this->usdt($status->balanceAfterMicro),
                ]);
        }
        $lines[] = $this->locale->translate($customer, 'balance.usdt.status.state', [
            'state' => $this->locale->translate($customer, 'balance.usdt.state.'.$status->state),
        ]);
        if ($isBinance) {
            $lines[] = $this->locale->translate($customer, 'balance.usdt.status.binance');
            $lines[] = $this->locale->translate($customer, ! empty($snapshot['receiver_pay_id'])
                ? 'balance.usdt.status.pay_id' : 'balance.usdt.status.uid', [
                    'pay_id' => $this->copyableValue((string) ($snapshot['receiver_pay_id'] ?? $snapshot['receiver_uid'] ?? ''), $copyable),
                ]);
        }
        if ($status->isAwaitingPayment()) {
            if (! $isBinance) {
                $lines[] = $this->locale->translate($customer, 'balance.usdt.status.network');
                $lines[] = $this->locale->translate($customer, 'balance.usdt.status.receiver', [
                    'address' => $this->copyableValue((string) ($status->receiverAddress ?? ''), $copyable),
                ]);
            }
            $lines[] = $this->locale->translate($customer, 'balance.usdt.status.deadline', [
                'deadline' => $this->locale->formatDate($customer, $status->deadline),
            ]);
            $lines[] = $this->locale->translate($customer, $isBinance
                ? 'balance.usdt.status.binance_warning'
                : 'balance.usdt.status.warning');
        } else {
            $lines[] = $this->locale->translate($customer, 'balance.usdt.status.'.$status->state.'_note');
        }
        if ($isBinance && ! $status->isAwaitingPayment()) {
            $lines[] = $this->locale->translate($customer, 'balance.usdt.status.deadline', [
                'deadline' => $this->locale->formatDate($customer, $status->deadline),
            ]);
        }
        $lines[] = $this->locale->translate($customer, 'balance.usdt.status.reference', [
            'reference' => $status->reference,
        ]);

        $keyboard = [];
        if ($status->acceptsCustomerLookup()) {
            $keyboard[] = [
                [
                    'text' => $this->locale->translate($customer, 'balance.usdt.status.check'),
                    'callback_data' => 'usdt:check:'.$target->id,
                ],
            ];
            if ($isBinance) {
                $keyboard[0][] = [
                    'text' => $this->locale->translate($customer, 'balance.usdt.status.order_id'),
                    'callback_data' => 'usdt:order:'.$target->id,
                ];
            }
        }
        $keyboard[] = [$this->historyRow($customer)];
        $keyboard[] = $this->menu->navigationRow($customer);

        return new TelegramNavigationSurface(
            TelegramNavigationScreen::UsdtBalance,
            $this->locale->code($customer),
            strtr($this->captions->html(implode("\n", $lines), PHP_INT_MAX), $copyable),
            ['inline_keyboard' => $keyboard],
        );
    }

    /** @param array<string, string> $copyable */
    private function copyableValue(string $value, array &$copyable): string
    {
        $marker = "\x1Fusdt-copy-".count($copyable)."\x1F";
        $copyable[$marker] = '<code>'.$this->captions->escapeTextPreservingIcons($value).'</code>';

        return $marker;
    }

    public function historySurface(
        Customer $customer,
        CustomerUsdtActivityPage $page,
        ?string $noticeKey = null,
    ): TelegramNavigationSurface {
        $lines = [
            $this->locale->translate($customer, 'balance.usdt.history.title'),
            '━━━━━━━━━━━━━━',
        ];
        if ($noticeKey !== null) {
            $lines[] = $this->locale->translate($customer, $noticeKey);
        }
        $lines[] = $this->locale->translate($customer, 'balance.usdt.history.note');
        if ($page->isEmpty()) {
            $lines[] = $this->locale->translate($customer, 'balance.usdt.history.empty');
        }
        foreach ($page->items as $index => $item) {
            $lines[] = $this->locale->translate($customer, 'balance.usdt.history.row', [
                'number' => $index + 1,
                'kind' => $this->locale->translate($customer, 'balance.usdt.history.kind.'.$item->kind),
                'state' => $this->locale->translate($customer, 'balance.usdt.history.state.'.$item->state),
            ]);
            $lines[] = $item->isTarget()
                ? $this->locale->translate($customer, 'balance.usdt.history.requested', [
                    'amount' => $this->usdt($item->amountMicro),
                ])
                : $this->locale->translate($customer, 'balance.usdt.history.amount', [
                    'amount' => $this->signed($item->signedMicro ?? 0),
                ]);
            $lines[] = $this->locale->translate($customer, 'balance.usdt.history.date', [
                'date' => $this->locale->formatShortDate($customer, $item->occurredAt),
            ]);
            $lines[] = $this->locale->translate($customer, 'balance.usdt.history.reference', [
                'reference' => $item->reference,
            ]);
        }

        $keyboard = [];
        foreach ($page->items as $index => $item) {
            if (! $item->isTarget() || $item->targetId === null) {
                continue;
            }
            $keyboard[] = [[
                'text' => $this->locale->translate($customer, 'balance.usdt.history.open', [
                    'number' => $index + 1,
                ]),
                'callback_data' => 'usdt:status:'.$item->targetId,
            ]];
        }

        $paging = [];
        if ($page->hasNewer && $page->newerCursor !== null) {
            $paging[] = [
                'text' => $this->locale->translate($customer, 'balance.activity.newer'),
                'callback_data' => 'usdt:h:n:'.$this->tokens->cursorToken($customer->id, $page->newerCursor),
            ];
        }
        if ($page->hasOlder && $page->olderCursor !== null) {
            $paging[] = [
                'text' => $this->locale->translate($customer, 'balance.activity.older'),
                'callback_data' => 'usdt:h:o:'.$this->tokens->cursorToken($customer->id, $page->olderCursor),
            ];
        }
        if ($paging !== []) {
            $keyboard[] = $paging;
        }
        $keyboard[] = [$this->topUpRow($customer)];
        $keyboard[] = $this->menu->navigationRow($customer);

        return new TelegramNavigationSurface(
            TelegramNavigationScreen::UsdtBalance,
            $this->locale->code($customer),
            $this->captions->html(implode("\n", $lines)),
            ['inline_keyboard' => $keyboard],
        );
    }

    public function firstPage(Customer $customer): CustomerUsdtActivityPage
    {
        return $this->activities->firstPage($customer);
    }

    public function olderPage(Customer $customer, CustomerUsdtActivityCursor $cursor): ?CustomerUsdtActivityPage
    {
        return $this->activities->olderPage($customer, $cursor);
    }

    public function newerPage(Customer $customer, CustomerUsdtActivityCursor $cursor): ?CustomerUsdtActivityPage
    {
        return $this->activities->newerPage($customer, $cursor);
    }

    /**
     * @return array<string, string>
     */
    private function historyRow(Customer $customer): array
    {
        return [
            'text' => $this->locale->translate($customer, 'balance.usdt.history.button'),
            'callback_data' => 'usdt:history',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function topUpRow(Customer $customer): array
    {
        return [
            'text' => $this->locale->translate($customer, 'balance.usdt.topup.button'),
            'callback_data' => self::TOP_UP_CALLBACK,
        ];
    }

    private function usdt(int $micro): string
    {
        return UsdtMicroAmount::formatUnits(max(0, $micro));
    }

    private function signed(int $micro): string
    {
        return $micro < 0
            ? '-'.$this->usdt(abs($micro))
            : '+'.$this->usdt($micro);
    }
}
