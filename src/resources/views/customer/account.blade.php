<!DOCTYPE html>
<html lang="{{ $metadata->htmlLocale() }}" class="bg-[var(--sf-paper)]">
<head>@include('storefront.partials.head', ['metadata' => $metadata])</head>
<body class="customer-auth text-[var(--sf-ink)]">
    <div class="storefront-shell flex min-h-screen flex-col">
        <div class="pt-4">@include('storefront.partials.header')</div>
        <main id="account-content" class="flex-1 px-5 pb-20 pt-8 sm:pt-12">
            <div class="auth-panel mx-auto max-w-xl rounded-2xl border border-[var(--sf-border)] bg-[var(--sf-surface)] p-5 sm:p-8">
                <nav class="auth-tabs" aria-label="Akses akun">
                    <a href="{{ route('customer.account') }}" @if($authView === 'login') aria-current="page" @endif>Masuk</a>
                    <a href="{{ route('customer.account', ['view' => 'register']) }}" @if($authView === 'register') aria-current="page" @endif>Daftar</a>
                </nav>
                <h1 class="mt-5 font-display text-3xl font-black tracking-tight sm:text-4xl">{{ match($authView) {
                    'register' => 'Daftar akun pelanggan', 'code' => 'Masuk dengan kode email',
                    'reset' => 'Pulihkan password', 'magic' => 'Masuk dengan link email',
                    'verification' => 'Periksa email Anda', default => 'Masuk ke akun pelanggan',
                } }}</h1>
                <p class="auth-help mt-3">Pesanan dan saldo tetap milik akun Anda. Belanja tanpa akun dan akses dengan Recovery Key tetap tersedia.</p>
                @include('customer.partials.auth-feedback')

                @if($authView === 'verification')
                    <p class="mt-6 leading-7">Jika email dapat digunakan, instruksi verifikasi telah dikirim. Periksa inbox dan spam. Link berlaku 30 menit dan hanya dapat digunakan sekali.</p>
                    <p class="auth-help mt-3">Sudah punya akun? Masuk atau pulihkan akses. Pendaftaran ulang tidak mengubah akun yang sudah ada.</p>
                    <a class="auth-link mt-4" href="{{ route('customer.verification.show') }}">Masukkan kode dari link verifikasi</a>
                @elseif($authView === 'code' && session('customer_otp_challenge') && !request()->boolean('new'))
                    <p class="auth-help mt-5">Jika email dapat digunakan, kode telah dikirim. Kode berlaku 10 menit, sekali pakai, dengan maksimal lima percobaan. Tunggu 60 detik sebelum meminta kode baru.</p>
                    <form method="POST" action="{{ route('customer.login-code.consume') }}" class="mt-6 space-y-4" data-auth-form>
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
                    <form method="POST" action="{{ route('customer.login-code.request') }}" class="mt-4" data-auth-form>
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
                    }) }}" class="mt-6 space-y-4" data-auth-form>
                        @csrf
                        <label class="auth-label">Email
                            <input class="auth-input" name="email" type="email" required maxlength="254" autocomplete="{{ $authView === 'login' ? 'username' : 'email' }}" value="{{ old('email') }}">
                        </label>
                        @if($authView === 'register')
                            <label class="auth-label">Nama lengkap (opsional)
                                <input class="auth-input" name="name" type="text" maxlength="255" autocomplete="name" value="{{ old('name') }}">
                            </label>
                        @endif
                        @if(in_array($authView, ['login', 'register'], true))
                            <label class="auth-label">{{ $authView === 'register' ? 'Password baru' : 'Password' }}
                                <input class="auth-input" name="password" type="password" required @if($authView === 'register') minlength="12" aria-describedby="register-password-help" @endif maxlength="128" autocomplete="{{ $authView === 'register' ? 'new-password' : 'current-password' }}">
                            </label>
                            @if($authView === 'register')
                                <p id="register-password-help" class="auth-help">12–128 karakter. Spasi, paste, dan pengelola password dapat digunakan.</p>
                                <label class="auth-label">Ulangi password baru
                                    <input class="auth-input" name="password_confirmation" type="password" required minlength="12" maxlength="128" autocomplete="new-password">
                                </label>
                                <p class="auth-help">Verifikasi email diperlukan sebelum masuk. Optional Support Contact (WhatsApp) dapat diberikan saat checkout; tidak wajib, bukan metode login atau bukti kepemilikan akun maupun pesanan.</p>
                            @endif
                        @endif
                        @if($authView === 'reset')
                            <p class="auth-help">Jika email dapat digunakan, instruksi pemulihan dikirim melalui email. Link berlaku 30 menit. Setelah reset, semua sesi pelanggan berakhir.</p>
                        @elseif($authView === 'magic')
                            <p class="auth-help">Link sekali pakai berlaku 15 menit. Metode ini tetap tersedia selama migrasi untuk akun lama maupun baru.</p>
                        @elseif($authView === 'code')
                            <p class="auth-help">Jika email dapat digunakan, kode enam digit dikirim melalui email. Kode tidak membuat akun baru; gunakan Daftar untuk pendaftaran.</p>
                        @endif
                        <button class="auth-submit" type="submit">{{ match($authView) {
                            'register' => 'Daftar dan kirim verifikasi', 'code' => 'Kirim kode email',
                            'reset' => 'Kirim instruksi pemulihan', 'magic' => 'Kirim link daftar / masuk',
                            default => 'Masuk dengan password',
                        } }}</button>
                        <p data-auth-progress role="status" hidden>Mengirim permintaan...</p>
                    </form>
                @endif
                <div class="mt-5 border-t border-[var(--sf-border)] pt-3">
                    @if($authView !== 'code')<a class="auth-link" href="{{ route('customer.account', ['view' => 'code']) }}">Masuk dengan kode email</a>@endif
                    @if($authView !== 'reset')<a class="auth-link" href="{{ route('customer.account', ['view' => 'reset']) }}">Lupa password?</a>@endif
                    @if($authView !== 'magic')<a class="auth-link" href="{{ route('customer.account', ['view' => 'magic']) }}">Masuk tanpa password melalui link email</a>@endif
                </div>
                <section class="mt-4 border-t border-[var(--sf-border)] pt-4" aria-label="Metode lain">
                    @if($telegramReady)
                        <form method="POST" action="{{ route('customer.telegram.login') }}" data-telegram-form data-status-url="{{ route('customer.telegram.status') }}" data-consume-url="{{ route('customer.telegram.consume') }}" data-orders-url="{{ route('customer.orders.index') }}" hidden>
                            @csrf
                            <button type="submit" class="auth-link">Masuk dengan Telegram</button>
                        </form>
                        <div data-telegram-status role="status" class="auth-help mt-2"></div>
                        <noscript><p class="auth-help">Konfirmasi Telegram memerlukan JavaScript. Gunakan password, kode email, atau link email tanpa JavaScript.</p></noscript>
                    @else
                        <p class="auth-help">Login Telegram belum tersedia. Gunakan metode email.</p>
                    @endif
                    <p class="auth-help mt-2">Login Google belum tersedia. Gunakan metode email.</p>
                </section>
                <a href="{{ route('storefront.order-access.create') }}" class="auth-link mt-5">Akses dengan invoice + Recovery Key</a>
            </div>
        </main>
        @include('storefront.partials.footer')
    </div>
    @include('customer.partials.auth-progress')
</body>
</html>
