<?php

namespace App\Services\Telegram;

use App\Enums\BalancePaymentPreference;
use App\Enums\TelegramNavigationScreen;
use App\Models\Customer;

final class TelegramCustomerApiIntents
{
    public function __construct(private TelegramLocale $locale, private TelegramUiIcons $icons) {}

    public function management(Customer $customer, array $summary, ?string $notice = null): TelegramNavigationSurface
    {
        $text = $this->text($customer, 'menu.api')."\n\n".$this->text($customer, 'account.api.backend');
        if ($notice !== null) {
            $text .= "\n\n".$this->text($customer, $notice);
        }
        $rows = [];
        if ($summary['active']) {
            $text .= "\n\n".$this->text($customer, 'account.api.metadata', [
                'mask' => $summary['masked_identifier'], 'created' => $summary['created_at'],
                'last' => $summary['last_used_at'] ?? $this->text($customer, 'account.api.no_usage'),
                'count' => $summary['account_request_count'],
            ])."\n".$this->text($customer, 'account.api.usage_note');
            $rows[] = [$this->button($customer, 'account.api.regenerate', 'api:rotate')];
            $rows[] = [$this->button($customer, 'account.api.disable', 'api:disable')];
        } else {
            $text .= "\n\n".$this->text($customer, 'account.api.no_key');
            $rows[] = [$this->button($customer, 'account.api.generate', 'api:generate')];
        }
        $customer->refresh();
        $text .= "\n\n".$this->text($customer, 'account.api.current_preference', [
            'preference' => $this->text($customer, 'account.api.'.$customer->balance_payment_preference->value),
        ]);
        $rows[] = [$this->button($customer, 'account.api.preference', 'api:preferences', 'menu.balance')];
        $rows[] = [[...$this->icons->apply('message.link', $this->text($customer, 'account.api.documentation')), 'url' => url('/api-docs/'), 'style' => 'primary']];
        $rows[] = [$this->button($customer, 'menu.back', 'menu:back', 'menu.back'), $this->button($customer, 'menu.home', 'menu:home', 'menu.home')];

        return $this->surface($customer, $text, $rows);
    }

    public function maintenance(Customer $customer): TelegramNavigationSurface
    {
        $rows = [[
            $this->button($customer, 'menu.back', 'menu:back', 'menu.back'),
            $this->button($customer, 'menu.home', 'menu:home', 'menu.home'),
        ]];

        return $this->surface($customer, $this->text($customer, 'account.api.maintenance'), $rows);
    }

    public function preferences(Customer $customer): TelegramNavigationSurface
    {
        $rows = [];
        foreach (BalancePaymentPreference::cases() as $preference) {
            $rows[] = [$this->button($customer, 'account.api.'.$preference->value, 'api:preference:'.$preference->value, 'menu.balance')];
        }
        $rows[] = [$this->button($customer, 'menu.back', 'api:menu', 'menu.back')];

        return $this->surface($customer, $this->text($customer, 'account.api.preference_help'), $rows);
    }

    public function confirmation(Customer $customer, string $operation, string $nonce): TelegramNavigationSurface
    {
        return $this->surface($customer, $this->text($customer, 'account.api.confirm_'.$operation), [
            [$this->button($customer, 'account.api.confirm', 'api:'.$operation.':'.$nonce)],
            [$this->button($customer, 'menu.back', 'api:menu', 'menu.back')],
        ]);
    }

    public function secretMessage(Customer $customer, #[\SensitiveParameter] string $secret): string
    {
        return $this->text($customer, 'account.api.save_now')."\n\n<code>".htmlspecialchars($secret, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</code>';
    }

    private function text(Customer $customer, string $key, array $replace = []): string
    {
        return $this->locale->translate($customer, $key, $replace);
    }

    private function button(Customer $customer, string $key, string $data, string $icon = 'menu.api'): array
    {
        return [...$this->icons->apply($icon, $this->text($customer, $key)), 'callback_data' => $data, 'style' => 'primary'];
    }

    private function surface(Customer $customer, string $text, array $rows): TelegramNavigationSurface
    {
        return new TelegramNavigationSurface(TelegramNavigationScreen::Api, $this->locale->code($customer), $text, ['inline_keyboard' => $rows]);
    }
}
