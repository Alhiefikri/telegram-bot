<?php

namespace App\Services\Telegram;

use App\Enums\CustomerUsdtFundingRail;
use App\Models\Customer;
use App\Models\CustomerUsdtTopUp;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtFunding\UsdtFundingIntentResult;
use App\Services\UsdtTopUps\UsdtTopUpStatusReader;

/**
 * One place that turns a USD funding outcome into the surface the Customer
 * sees, shared by the wallet chooser and the `/usdt` command. It never invents
 * an amount and never writes money: the outcome it receives already carries the
 * policy-resolved target, and a target that is merely still open is shown as
 * itself instead of being silently replaced by the requested one.
 */
final class TelegramUsdtFundingPresenter
{
    public function __construct(
        private readonly TelegramMenuIntents $menu,
        private readonly TelegramUsdtBalanceIntents $usdt,
        private readonly TelegramUsdtFundingSession $session,
        private readonly UsdtTopUpStatusReader $statuses,
        private readonly TelegramLocale $locale,
    ) {}

    /**
     * Let a pasted Binance Order ID or BSC transaction hash reach this target
     * without another tap. Only an unresolved target accepts lookup hints.
     */
    public function armLookup(Customer $customer, CustomerUsdtTopUp $target): void
    {
        if ($this->statuses->for($target)->acceptsCustomerLookup()) {
            $this->session->promptHash($customer, (string) $target->id);
        }
    }

    public function surfaceFor(
        Customer $customer,
        CustomerUsdtFundingRail $rail,
        UsdtFundingIntentResult $result,
    ): TelegramNavigationSurface {
        if ($result->topUp !== null && in_array($result->outcome, ['confirmed', 'active_target'], true)) {
            $target = $result->topUp;
            $this->armLookup($customer, $target);
            if ($result->outcome === 'confirmed') {
                // The instructions are the result: no extra confirmation line
                // competing with the amount and the Pay ID.
                return $this->usdt->statusSurface(
                    $customer,
                    $target,
                    $this->statuses->for($target),
                );
            }

            // An open target blocks a second one across rails. When the Customer
            // asked for another rail, name both so the old target's instructions
            // can never be mistaken for the requested rail.
            $activeRail = $this->railOf($target);
            if ($activeRail === null || $activeRail === $rail) {
                return $this->usdt->statusSurface(
                    $customer,
                    $target,
                    $this->statuses->for($target),
                    'balance.usdt.notice.active_target',
                );
            }

            return $this->usdt->statusSurface(
                $customer,
                $target,
                $this->statuses->for($target),
                'balance.usdt.notice.active_target_other_rail',
                [
                    'requested' => $this->locale->translate($customer, $this->railLabelKey($rail)),
                    'active' => $this->locale->translate($customer, $this->railLabelKey($activeRail)),
                    'amount' => UsdtMicroAmount::formatUnits((int) $target->amount_micro),
                    'deadline' => $this->locale->formatDate($customer, $target->expires_at),
                ],
            );
        }

        return $this->menu->usdAmountSurface($customer, $rail, match ($result->outcome) {
            'active_target' => 'balance.usdt.notice.active_target',
            'invalid_amount' => 'balance.usd.amount.invalid',
            'unauthorized', 'stale' => 'balance.usd.amount.restart',
            'stale_terms', 'stale_policy' => 'balance.add.preset_stale',
            default => 'balance.usd.amount.unavailable',
        });
    }

    private function railOf(CustomerUsdtTopUp $target): ?CustomerUsdtFundingRail
    {
        $snapshot = is_array($target->payment_snapshot) ? $target->payment_snapshot : [];
        $value = $target->rail instanceof CustomerUsdtFundingRail ? $target->rail->value : (string) ($target->rail ?? '');

        return CustomerUsdtFundingRail::tryFrom($value !== '' ? $value : (string) ($snapshot['rail'] ?? ''));
    }

    private function railLabelKey(CustomerUsdtFundingRail $rail): string
    {
        return $rail === CustomerUsdtFundingRail::BinancePay
            ? 'balance.usd.binance_pay'
            : 'balance.usd.bsc';
    }
}
