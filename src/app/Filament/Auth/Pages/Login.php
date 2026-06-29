<?php

namespace App\Filament\Auth\Pages;

use Filament\Actions\Action;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
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
        return 'LHIE STORE Admin';
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Masuk ke LHIE STORE';
    }

    public function getSubheading(): string|Htmlable|null
    {
        $copy = 'Masuk untuk mengelola pesanan, pembayaran, dan stok dari satu tampilan yang tenang dan jelas.';

        if (! $this->shouldPrefillLoginCredentials()) {
            return $copy;
        }

        return new HtmlString(
            '<div class="lhie-login-note">'
            .'<div class="lhie-login-note__label">Mode uji lokal</div>'
            .'<p class="lhie-login-note__copy">'
            .'<span class="lhie-login-note__intro">Kredensial admin lokal sudah terisi otomatis.</span>'
            .' <span class="lhie-login-note__credential">test@example.com</span>'
            .' / '
            .'<span class="lhie-login-note__credential">password</span>.'
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

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('Email')
            ->email()
            ->required()
            ->autocomplete()
            ->autofocus();
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label('Sandi')
            ->password()
            ->revealable(Filament::arePasswordsRevealable())
            ->autocomplete('current-password')
            ->required();
    }

    protected function getRememberFormComponent(): Component
    {
        return Checkbox::make('remember')
            ->label('Tetap masuk');
    }

    protected function getAuthenticateFormAction(): Action
    {
        return Action::make('authenticate')
            ->label('Masuk')
            ->submit('authenticate');
    }
}
