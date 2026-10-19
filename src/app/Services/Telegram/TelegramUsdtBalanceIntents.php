<?php

namespace App\Services\Telegram;

use App\Enums\CustomerUsdtFundingRail;
use App\Enums\TelegramNavigationScreen;
use App\Models\Customer;
use App\Models\CustomerUsdtTopUp;
use App\Services\UsdtBalance\CustomerUsdtActivityCursor;
use App\Services\UsdtBalance\CustomerUsdtActivityPage;
use App\Services\UsdtBalance\CustomerUsdtActivityService;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtFunding\CustomerUsdtFundingGate;
use App\Services\UsdtTopUps\UsdtTopUpStatus;

/**
 * USD balance presentation only: the rail chooser, the payment instructions of
 * one confirmed target with its verification placeholders, and personal keyset
 * history. Nothing here credits, debits or repairs money, calls a rail/RPC, or
 * renders an amount in IDR.
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

    /**
     * USD top-up entry: the USDT rails the store can accept. Amounts are chosen
     * on the rail screen and payment targets are created by that choice, so this
     * screen never carries an amount and never touches money.
     */
    public function topUpSurface(Customer $customer, ?string $noticeKey = null): TelegramNavigationSurface
    {
        $lines = [
            $this->locale->translate($customer, 'balance.usd.title'),
            '━━━━━━━━━━━━━━',
        ];
        if ($noticeKey !== null) {
            $lines[] = $this->locale->translate($customer, $noticeKey);
            $lines[] = '';
        }
        $lines[] = $this->locale->translate($customer, 'balance.usd.prompt');

        $keyboard = [];
        $rails = 0;
        foreach (CustomerUsdtFundingRail::selectable() as $rail) {
            if (! $this->gate->availability($rail)->available) {
                continue;
            }
            $keyboard[] = [$this->menu->fundingRailButton($customer, $rail)];
            $rails++;
        }
        if ($rails === 0) {
            $lines[] = $this->locale->translate($customer, 'balance.usdt.topup.unavailable');
        }
        $keyboard[] = $this->menu->navigationRow($customer);

        return new TelegramNavigationSurface(
            TelegramNavigationScreen::UsdtBalance,
            $this->locale->code($customer),
            $this->captions->html(implode("\n", $lines)),
            ['inline_keyboard' => $keyboard],
        );
    }

    /**
     * Transient verification surface for a submitted lookup hint. It carries no
     * money state, drops the keyboard so a hint cannot be double-submitted, and
     * is always replaced by the durable status surface of the same target.
     */
    public function checkingSurface(Customer $customer, string $hintKey): TelegramNavigationSurface
    {
        return new TelegramNavigationSurface(
            TelegramNavigationScreen::UsdtBalance,
            $this->locale->code($customer),
            $this->captions->html(implode("\n", [
                $this->locale->translate($customer, 'balance.usdt.checking.title'),
                '━━━━━━━━━━━━━━',
                $this->locale->translate($customer, $hintKey),
            ])),
            ['inline_keyboard' => []],
        );
    }

    public function statusSurface(
        Customer $customer,
        CustomerUsdtTopUp $target,
        UsdtTopUpStatus $status,
        ?string $noticeKey = null,
        array $noticeReplacements = [],
    ): TelegramNavigationSurface {
        $snapshot = is_array($target->payment_snapshot) ? $target->payment_snapshot : [];
        $targetRail = $target->rail instanceof CustomerUsdtFundingRail ? $target->rail->value : (string) ($target->rail ?? '');
        $railValue = $targetRail !== '' ? $targetRail : (string) ($snapshot['rail'] ?? '');
        $isBinance = $railValue === CustomerUsdtFundingRail::BinancePay->value;
        $isShared = $railValue === CustomerUsdtFundingRail::BscShared->value;
        $copyable = [];
        $railLabel = $this->locale->translate($customer, $isBinance ? 'balance.usdt.rail_label.binance' : 'balance.usdt.rail_label.bep20');
        $lines = [
            $this->locale->translate($customer, $isBinance
                ? 'balance.usdt.status.title_binance'
                : 'balance.usdt.status.title_bep20'),
            '━━━━━━━━━━━━━━',
        ];
        if ($noticeKey !== null) {
            $lines[] = $this->locale->translate($customer, $noticeKey, $noticeReplacements);
            $lines[] = '';
        }
        $lines[] = $this->locale->translate($customer, 'balance.usdt.status.invoice', [
            'reference' => $status->reference,
        ]);
        if ($status->isAwaitingPayment()) {
            // One idea per paragraph: what to send, where to send it, then how
            // to prove it. Customers scan this screen on a phone.
            $minutes = max(1, (int) ceil(($status->deadline->getTimestamp() - now()->getTimestamp()) / 60));
            $lines[] = '';
            $lines[] = $this->locale->translate($customer, 'balance.usdt.status.method', ['rail' => $railLabel]);
            $lines[] = $this->locale->translate($customer, 'balance.usdt.status.window', [
                'minutes' => $minutes,
                'deadline' => $this->locale->formatDate($customer, $status->deadline),
            ]);
            $lines[] = '';
            $lines[] = $this->locale->translate($customer, 'balance.usdt.status.send_exact', [
                'amount' => $this->copyableValue($this->usdt($status->payableMicro), $copyable),
            ]);
            if ($status->feeMicro > 0) {
                $lines[] = $this->locale->translate($customer, 'balance.usdt.status.fee', [
                    'amount' => $this->usdt($status->feeMicro),
                ]);
            }
            $lines[] = $this->locale->translate($customer, $isBinance
                ? 'balance.usdt.preview.usd_credit'
                : 'balance.usdt.status.credit', [
                    'amount' => $this->usdt($status->creditMicro),
                ]);
            if ($isShared && (int) ($snapshot['requested_micro'] ?? 0) !== (int) $target->amount_micro) {
                $lines[] = $this->locale->translate($customer, 'balance.usdt.preview.shared_note', [
                    'requested' => $this->usdt((int) ($snapshot['requested_micro'] ?? 0)),
                    'amount' => $this->usdt((int) $target->amount_micro),
                ]);
            }
            $lines[] = '';
            if ($isBinance) {
                $lines[] = $this->locale->translate($customer, 'balance.usdt.status.proof');
                $lines[] = '';
                $lines[] = $this->locale->translate($customer, ! empty($snapshot['receiver_pay_id'])
                    ? 'balance.usdt.status.pay_id_title' : 'balance.usdt.status.uid_title');
                $lines[] = $this->copyableValue((string) ($snapshot['receiver_pay_id'] ?? $snapshot['receiver_uid'] ?? ''), $copyable);
                $lines[] = '';
                $lines[] = $this->locale->translate($customer, 'balance.usdt.status.binance_howto');
            } else {
                $lines[] = $this->locale->translate($customer, 'balance.usdt.status.address_title');
                $lines[] = $this->copyableValue((string) ($status->receiverAddress ?? ''), $copyable);
                $lines[] = '';
                $lines[] = $this->locale->translate($customer, 'balance.usdt.status.bep20_howto');
            }
            $lines[] = '';
            $lines[] = $this->locale->translate($customer, $isBinance
                ? 'balance.usdt.status.order_howto'
                : 'balance.usdt.status.hash_howto');
            $lines[] = '';
            $lines[] = $this->locale->translate($customer, 'balance.usdt.status.wait_hint');
        } else {
            $credited = $status->isSettled() || $status->balanceAfterMicro !== null;
            $lines[] = '';
            $lines[] = $this->locale->translate($customer, 'balance.usdt.status.method', ['rail' => $railLabel]);
            $lines[] = '';
            $lines[] = $this->locale->translate($customer, 'balance.usdt.preview.nominal', [
                'amount' => $this->usdt((int) $target->amount_micro),
            ]);
            if ($status->feeMicro > 0) {
                $lines[] = $this->locale->translate($customer, 'balance.usdt.status.fee', [
                    'amount' => $this->usdt($status->feeMicro),
                ]);
            }
            if ($status->receivedMicro !== null) {
                $lines[] = $this->locale->translate($customer, 'balance.usdt.receipt.received', [
                    'amount' => $this->usdt($status->receivedMicro),
                ]);
            }
            if ($credited || ! in_array($status->state, [
                UsdtTopUpStatus::STATE_EXPIRED_UNRESOLVED,
                UsdtTopUpStatus::STATE_EXPIRED_UNPAID,
                UsdtTopUpStatus::STATE_REJECTED,
            ], true)) {
                $lines[] = $this->locale->translate($customer, $isBinance
                    ? ($credited ? 'balance.usdt.receipt.usd_credit' : 'balance.usdt.preview.usd_credit')
                    : 'balance.usdt.status.credit', [
                        'amount' => $this->usdt($status->creditMicro),
                    ]);
            }
            if ($status->balanceBeforeMicro !== null && $status->balanceAfterMicro !== null) {
                $lines[] = '';
                $lines[] = $this->locale->translate($customer, $isBinance
                    ? 'balance.usdt.receipt.balance_before_usd' : 'balance.usdt.receipt.balance_before', [
                        'amount' => $this->usdt($status->balanceBeforeMicro),
                    ]);
                $lines[] = $this->locale->translate($customer, $isBinance
                    ? 'balance.usdt.receipt.balance_after_usd' : 'balance.usdt.receipt.balance_after', [
                        'amount' => $this->usdt($status->balanceAfterMicro),
                    ]);
            }
            if ($status->acceptsCustomerLookup()) {
                $lines[] = '';
                $lines[] = $this->locale->translate($customer, 'balance.usdt.status.deadline', [
                    'deadline' => $this->locale->formatDate($customer, $status->deadline),
                ]);
            }
            $lines[] = '';
            $lines[] = $this->locale->translate($customer, 'balance.usdt.status.'.$status->state.'_note');
        }
        $lines[] = '';
        $lines[] = $this->locale->translate($customer, 'balance.usdt.status.state', [
            'state' => $this->locale->translate($customer, 'balance.usdt.state.'.$status->state),
        ]);
        $keyboard = [];
        if ($status->acceptsCustomerLookup()) {
            $keyboard[] = [
                [
                    'text' => $this->locale->translate($customer, 'balance.usdt.status.check'),
                    'callback_data' => 'usdt:check:'.$target->id,
                ],
                [
                    'text' => $this->locale->translate($customer, $isBinance
                        ? 'balance.usdt.status.order_id'
                        : 'balance.usdt.status.hash'),
                    'callback_data' => ($isBinance ? 'usdt:order:' : 'usdt:hash:').$target->id,
                ],
            ];
            // A Customer who picked the wrong amount must be able to start over
            // without waiting for the deadline.
            $keyboard[] = [[
                'text' => $this->locale->translate($customer, 'balance.usdt.status.cancel'),
                'callback_data' => 'usdt:close:'.$target->id,
                'style' => 'danger',
            ]];
        }
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
