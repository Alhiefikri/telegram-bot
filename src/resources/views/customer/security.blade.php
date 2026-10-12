@extends('customer.partials.account-layout')

@section('account-title', 'Keamanan akun')
@section('account-body-class', 'customer-auth')
@section('account-description', 'Kelola password dan metode masuk ke akun Anda.')

@section('account-content')
    <div class="account-panel account-security auth-panel">
        @include('customer.partials.auth-feedback')
        <section class="account-security-section" aria-labelledby="password-security-title">
            <h2 id="password-security-title">Password</h2>
            @if(!$authEnabled)
                <p class="auth-help mt-3">Metode baru sementara tidak tersedia. Link email dan akses Recovery Key tetap dapat digunakan. Identitas, pesanan, dan saldo tidak berubah.</p>
            @elseif($customer->password === null)
                <p class="auth-help mt-3">Tambahkan password pertama dalam lima menit setelah masuk melalui link email. Pendaftaran ulang tidak dapat mengubah akun Anda.</p>
                <form method="POST" action="{{ route('customer.password.enroll') }}" class="account-form" data-auth-form>
                    @csrf
                    <div class="account-password-fields">
                        <div>
                            <label class="auth-label">Password baru
                                <input name="password" type="password" required minlength="8" maxlength="128" autocomplete="new-password" aria-describedby="enroll-password-help" class="auth-input">
                            </label>
                            <p id="enroll-password-help" class="auth-help">Minimal 8 karakter, dengan huruf besar, angka, dan simbol.</p>
                        </div>
                        <div>
                            <label class="auth-label">Ulangi password baru
                                <input name="password_confirmation" type="password" required minlength="8" maxlength="128" autocomplete="new-password" class="auth-input">
                            </label>
                        </div>
                    </div>
                    <button type="submit" class="account-button">Simpan password pertama</button>
                    <p data-auth-progress role="status" class="auth-help" hidden>Mengirim permintaan...</p>
                </form>
            @else
                <p class="auth-help mt-3">Password akun sudah tersedia. Untuk menggantinya, keluar lalu gunakan instruksi pemulihan password melalui email.</p>
            @endif
        </section>
        @if($authEnabled)
            <section class="account-security-section" aria-labelledby="google-security-title">
                <h2 id="google-security-title">Google</h2>
                <p class="auth-help mt-2">Hubungkan atau lepas Google dalam lima menit setelah masuk. Akun, pesanan, dan saldo tidak digabungkan.</p>
                @if($googleLinked)
                    <p class="mt-3 text-sm font-bold">Google terhubung ke akun ini.</p>
                    @if($googleReady)
                        <form method="POST" action="{{ route('customer.google.unlink') }}" class="mt-3" data-auth-form>
                            @csrf
                            <button type="submit" class="account-secondary-button">Lepas Google</button>
                            <p data-auth-progress role="status" class="auth-help" hidden>Melepas Google...</p>
                        </form>
                    @else
                        <p class="auth-help mt-3">Konfigurasi Google belum tersedia. Pelepasan belum dapat dilakukan; gunakan metode masuk lain.</p>
                    @endif
                    <p class="auth-help mt-2">Pelepasan memerlukan metode masuk lain yang terverifikasi.</p>
                @elseif($googleReady)
                    <form method="POST" action="{{ route('customer.google.link') }}" class="mt-3" data-auth-form>
                        @csrf
                        <button type="submit" class="account-secondary-button">Hubungkan Google</button>
                        <p data-auth-progress role="status" class="auth-help" hidden>Membuka Google...</p>
                    </form>
                @else
                    <p class="auth-help mt-3">Google belum tersedia. Metode masuk lain tetap dapat digunakan.</p>
                @endif
            </section>
            <section class="account-security-section" aria-labelledby="telegram-security-title">
                <h2 id="telegram-security-title">Telegram</h2>
                @if($customer->telegram_id !== null)
                    <p class="auth-help mt-2">Telegram sudah terhubung. Identitas Store Bot tidak dapat dilepas atau dipindahkan.</p>
                    @if($telegramAuthenticated && $customer->email_verified_at === null)
                        <form method="POST" action="{{ route('customer.telegram.email') }}" class="account-form" data-auth-form>
                            @csrf
                            <label class="auth-label">Email untuk verifikasi
                                <input class="auth-input" name="email" type="email" required maxlength="254" autocomplete="email">
                            </label>
                            <p class="auth-help">Gunakan email yang belum digunakan dalam lima menit setelah konfirmasi Telegram. Verifikasi menambahkan email ke akun ini, bukan menggabungkan akun.</p>
                            <button class="account-button" type="submit">Kirim verifikasi email</button>
                            <p data-auth-progress role="status" class="auth-help" hidden>Mengirim permintaan...</p>
                        </form>
                    @endif
                @elseif($telegramReady)
                    <p class="auth-help mt-2">Hubungkan dalam lima menit setelah masuk, lalu konfirmasi di chat pribadi Store Bot. Akun dan saldo tidak digabungkan.</p>
                    <form method="POST" action="{{ route('customer.telegram.link') }}" data-telegram-form data-status-url="{{ route('customer.telegram.status') }}" data-consume-url="{{ route('customer.telegram.consume') }}" data-orders-url="{{ route('customer.security') }}" hidden>
                        @csrf
                        <button type="submit" class="account-secondary-button">Hubungkan Telegram</button>
                    </form>
                    <div data-telegram-status role="status" class="auth-help mt-2"></div>
                    <noscript><p class="auth-help">Konfirmasi Telegram memerlukan JavaScript. Link email tetap dapat digunakan tanpa JavaScript.</p></noscript>
                @else
                    <p class="auth-help mt-2">Telegram belum tersedia. Metode email tetap dapat digunakan.</p>
                @endif
            </section>
        @endif
        <a href="{{ route('customer.orders.index') }}" class="account-text-link">Kembali ke pesanan</a>
    </div>
@endsection

@section('account-scripts')
    @include('customer.partials.auth-progress')
@endsection
