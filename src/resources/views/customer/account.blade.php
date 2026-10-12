<!DOCTYPE html>
<html lang="{{ $metadata->htmlLocale() }}" class="bg-[var(--sf-paper)]">
<head>@include('storefront.partials.head', ['metadata' => $metadata])</head>
<body class="customer-auth customer-auth-entry text-[var(--sf-ink)]">
    <div class="auth-page">
        <div class="auth-appearance"><x-theme-toggle class="auth-theme-toggle" /></div>
        <main id="account-content" class="auth-main">
            <section class="auth-panel auth-entry-panel" aria-labelledby="auth-title">
                <div class="auth-brand">
                    <a href="{{ route('storefront.home') }}" aria-label="Beranda {{ $brand }}">@include('storefront.components.brand-mark', ['brand' => $brand])</a>
                </div>
                <header class="auth-heading">
                    <h1 id="auth-title">{{ match($authView) {
                        'register' => 'Buat akun', 'code' => 'Masuk dengan kode email',
                        'reset' => 'Lupa password?', 'magic' => 'Masuk dengan link email',
                        'verification' => 'Periksa email Anda', default => 'Masuk',
                    } }}</h1>
                    @if($authView === 'login')<p class="auth-help">Masukkan email kamu untuk melanjutkan.</p>@endif
                </header>
                @include('customer.partials.auth-feedback')

                @if($authView === 'verification')
                    <p class="auth-help">Jika email dapat digunakan, instruksi verifikasi telah dikirim. Periksa inbox dan spam. Link berlaku 30 menit dan hanya dapat digunakan sekali.</p>
                    <a class="auth-link" href="{{ route('customer.verification.show') }}">Masukkan kode dari link verifikasi</a>
                @elseif($authView === 'code' && session('customer_otp_challenge') && !request()->boolean('new'))
                    <p class="auth-help">Jika email dapat digunakan, kode telah dikirim. Kode berlaku 10 menit, sekali pakai, dengan maksimal lima percobaan. Tunggu 60 detik sebelum meminta kode baru.</p>
                    <form method="POST" action="{{ route('customer.login-code.consume') }}" class="auth-form" data-auth-form>
                        @csrf
                        <input type="hidden" name="email" value="{{ session('customer_otp_email') }}">
                        <input type="hidden" name="challenge" value="{{ session('customer_otp_challenge') }}">
                        <label class="auth-label">Kode email
                            <input class="auth-input" name="code" type="text" required inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code" aria-describedby="otp-help">
                        </label>
                        <p id="otp-help" class="auth-help">Masukkan enam digit, termasuk angka nol di awal. Jangan bagikan kode.</p>
                        <button class="auth-submit" type="submit">Konfirmasi kode dan masuk</button>
                        <p data-auth-progress role="status" hidden>Mengirim permintaan...</p>
                    </form>
                    <form method="POST" action="{{ route('customer.login-code.request') }}" data-auth-form>
                        @csrf
                        <input type="hidden" name="email" value="{{ session('customer_otp_email') }}">
                        <button class="auth-link" type="submit">Kirim kode baru</button>
                        <p data-auth-progress role="status" hidden>Meminta kode baru...</p>
                    </form>
                    <a class="auth-link" href="{{ route('customer.account', ['view' => 'code', 'new' => 1]) }}">Gunakan email lain</a>
                @else
                    <form method="POST" action="{{ route(match($authView) {
                        'register' => 'customer.register', 'code' => 'customer.login-code.request',
                        'reset' => 'customer.password-reset.request', 'magic' => 'customer.login-link.request',
                        default => 'customer.login',
                    }) }}" class="auth-form" data-auth-form @if($authView === 'login') data-email-first data-password-expanded="{{ $errors->any() || session('customer_login_error') ? 'true' : 'false' }}" @endif>
                        @csrf
                        @if($authView === 'register')
                            <label class="auth-label">Nama lengkap (opsional)
                                <span class="auth-field">
                                    @include('customer.partials.auth-icon', ['icon' => 'user'])
                                    <input class="auth-input" name="name" type="text" maxlength="255" autocomplete="name" placeholder="Nama lengkap kamu" value="{{ old('name') }}">
                                </span>
                            </label>
                        @endif
                        <label class="auth-label">{{ $authView === 'login' ? 'Alamat email' : 'Email' }}
                            <span class="auth-field">
                                @include('customer.partials.auth-icon', ['icon' => 'email'])
                                <input class="auth-input" name="email" type="email" required maxlength="254" autocomplete="{{ $authView === 'login' ? 'username' : 'email' }}" placeholder="nama@email.com" value="{{ old('email') }}">
                            </span>
                        </label>
                        @if(in_array($authView, ['login', 'register'], true))
                            <div class="auth-password-fields {{ $authView === 'register' ? 'auth-password-pair' : '' }}" @if($authView === 'login') data-login-password @endif>
                                <div class="auth-label">
                                    <label for="account-password">{{ $authView === 'register' ? 'Password baru' : 'Password' }}</label>
                                    <span class="auth-field auth-password-field">
                                        @include('customer.partials.auth-icon', ['icon' => 'password'])
                                        <input id="account-password" class="auth-input" name="password" type="password" required @if($authView === 'register') minlength="12" aria-describedby="register-password-help" @endif maxlength="128" autocomplete="{{ $authView === 'register' ? 'new-password' : 'current-password' }}" placeholder="{{ $authView === 'register' ? 'Min. 12 karakter' : 'Password kamu' }}">
                                        <button type="button" class="auth-password-toggle" data-password-toggle aria-label="Tampilkan password" aria-controls="account-password" aria-pressed="false" hidden>@include('customer.partials.auth-icon', ['icon' => 'eye'])</button>
                                    </span>
                                </div>
                                @if($authView === 'register')
                                    <div class="auth-label">
                                        <label for="account-password-confirmation">Konfirmasi password</label>
                                        <span class="auth-field auth-password-field">
                                            @include('customer.partials.auth-icon', ['icon' => 'password'])
                                            <input id="account-password-confirmation" class="auth-input" name="password_confirmation" type="password" required minlength="12" maxlength="128" autocomplete="new-password" placeholder="Ulangi password" aria-describedby="register-password-help">
                                            <button type="button" class="auth-password-toggle" data-password-toggle aria-label="Tampilkan konfirmasi password" aria-controls="account-password-confirmation" aria-pressed="false" hidden>@include('customer.partials.auth-icon', ['icon' => 'eye'])</button>
                                        </span>
                                    </div>
                                @endif
                            </div>
                            @if($authView === 'register')<p id="register-password-help" class="auth-help">12–128 karakter. Verifikasi email diperlukan sebelum masuk.</p>@endif
                        @endif
                        @if($authView === 'reset')
                            <p class="auth-help">Instruksi pemulihan dikirim melalui email jika akun dapat digunakan. Link berlaku 30 menit. Setelah reset, semua sesi pelanggan berakhir.</p>
                        @elseif($authView === 'magic')
                            <p class="auth-help">Link sekali pakai berlaku 15 menit.</p>
                        @elseif($authView === 'code')
                            <p class="auth-help">Kode enam digit dikirim jika email dapat digunakan. Belum punya akun? Daftar terlebih dahulu.</p>
                        @endif
                        <button class="auth-submit" type="submit">{{ match($authView) {
                            'register' => 'Daftarkan akun', 'code' => 'Kirim kode email',
                            'reset' => 'Kirim instruksi pemulihan', 'magic' => 'Kirim link daftar / masuk',
                            default => 'Masuk dengan password',
                        } }}</button>
                        <p data-auth-progress role="status" hidden>Mengirim permintaan...</p>
                    </form>
                    @if($authView === 'register')
                        <p class="auth-legal auth-help">Dengan mendaftar, kamu menyetujui <a href="{{ route('storefront.policies.terms') }}">Syarat &amp; Ketentuan</a> dan <a href="{{ route('storefront.policies.privacy') }}">Kebijakan Privasi</a>.</p>
                    @endif
                @endif

                @if($authView === 'login')
                    <div class="auth-divider"><span>Atau masuk dengan</span></div>
                    <section class="auth-alternatives" aria-label="Metode lain">
                        @if($telegramReady)
                            <form method="POST" action="{{ route('customer.telegram.login') }}" data-telegram-form data-status-url="{{ route('customer.telegram.status') }}" data-consume-url="{{ route('customer.telegram.consume') }}" data-orders-url="{{ route('customer.orders.index') }}" hidden>
                                @csrf
                                <button type="submit" class="auth-secondary">@include('customer.partials.auth-icon', ['icon' => 'telegram']) Masuk dengan Telegram</button>
                            </form>
                            <div data-telegram-status role="status" class="auth-help"></div>
                            <noscript><p class="auth-help">Konfirmasi Telegram memerlukan JavaScript. Gunakan password, kode email, atau link email.</p></noscript>
                        @endif
                        <a class="auth-secondary" href="{{ route('customer.account', ['view' => 'code']) }}">@include('customer.partials.auth-icon', ['icon' => 'email']) Masuk dengan kode email</a>
                        @if($googleReady)<a class="auth-secondary" href="{{ route('customer.google.redirect', ['operation' => 'login']) }}">Masuk dengan Google</a>@endif
                    </section>
                    <a class="auth-link auth-forgot" href="{{ route('customer.account', ['view' => 'reset']) }}">Lupa password?</a>
                @elseif($authView === 'register' && $googleReady)
                    <a class="auth-secondary" href="{{ route('customer.google.redirect', ['operation' => 'register']) }}">Daftar dengan Google</a>
                @endif

                <footer class="auth-card-footer">
                    @if($authView === 'login')
                        <p>Belum punya akun? <a href="{{ route('customer.account', ['view' => 'register']) }}">Daftar</a></p>
                    @elseif($authView === 'register')
                        <p>Sudah punya akun? <a href="{{ route('customer.account') }}">Masuk</a></p>
                    @elseif($authEnabled)
                        <a href="{{ route('customer.account') }}">Kembali ke masuk</a>
                    @else
                        <a href="{{ route('storefront.order-access.create') }}">Akses dengan invoice + Recovery Key</a>
                    @endif
                </footer>
            </section>
            @if($authView === 'login')
                <details class="auth-other-access">
                    <summary>Opsi akses lainnya</summary>
                    <a class="auth-link" href="{{ route('customer.account', ['view' => 'magic']) }}">Masuk tanpa password melalui link email</a>
                    <a class="auth-link" href="{{ route('storefront.order-access.create') }}">Akses dengan invoice + Recovery Key</a>
                </details>
            @endif
        </main>
    </div>
    @include('customer.partials.auth-progress')
</body>
</html>
