<?php

namespace App\Filament\Auth\Pages;

use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class Login extends BaseLogin
{
    private const DEFAULT_EMAIL = 'test@example.com';

    private const DEFAULT_PASSWORD = 'password';

    public function mount(): void
    {
        if (Filament::auth()->check()) {
            redirect()->intended(Filament::getUrl());

            return;
        }

        $this->form->fill($this->getDefaultLoginData());
    }

    public function getTitle(): string | Htmlable
    {
        return 'Admin sign in';
    }

    public function getHeading(): string | Htmlable | null
    {
        return 'Admin sign in';
    }

    public function getSubheading(): string | Htmlable | null
    {
        if (! $this->shouldPrefillLoginCredentials()) {
            return parent::getSubheading();
        }

        return new HtmlString(
            '<div class="space-y-1.5">'
            . '<div class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.2em] text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">Local testing mode</div>'
            . '<p class="text-sm leading-6 text-gray-600 dark:text-gray-400">'
            . 'Credentials are prefilled for a one-click sign in: '
            . '<span class="font-medium text-gray-950 dark:text-white">test@example.com</span>'
            . ' / '
            . '<span class="font-medium text-gray-950 dark:text-white">password</span>.'
            . '</p>'
            . '</div>'
        );
    }

    /**
     * @return array{email?: string, password?: string}
     */
    protected function getDefaultLoginData(): array
    {
        if (! $this->shouldPrefillLoginCredentials()) {
            return [];
        }

        return [
            'email' => self::DEFAULT_EMAIL,
            'password' => self::DEFAULT_PASSWORD,
        ];
    }

    protected function shouldPrefillLoginCredentials(): bool
    {
        if (app()->environment(['local', 'testing'])) {
            return true;
        }

        return in_array(request()->getHost(), ['localhost', '127.0.0.1', '::1'], true);
    }
}
