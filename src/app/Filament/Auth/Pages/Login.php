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

    public function getTitle(): string|Htmlable
    {
        return 'Admin sign in';
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Admin sign in';
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (! $this->shouldPrefillLoginCredentials()) {
            return parent::getSubheading();
        }

        return new HtmlString(
            '<div class="nb-login-note">'
            .'<div class="nb-login-note__label">Local testing mode</div>'
            .'<p class="nb-login-note__copy">'
            .'Credentials are prefilled for a one-click sign in: '
            .'<span class="nb-login-note__credential">test@example.com</span>'
            .' / '
            .'<span class="nb-login-note__credential">password</span>.'
            .'</p>'
            .'</div>'
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
