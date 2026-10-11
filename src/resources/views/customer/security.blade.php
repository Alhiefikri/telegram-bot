<!DOCTYPE html>
<html lang="{{ $metadata->htmlLocale() }}" class="bg-[var(--sf-paper)]">
<head>@include('storefront.partials.head', ['metadata' => $metadata])</head>
<body class="customer-auth text-[var(--sf-ink)]">
    <div class="storefront-shell flex min-h-screen flex-col">
        <div class="pt-4">@include('storefront.partials.header')</div>
        <main class="flex-1 px-5 pb-20 pt-8 sm:pt-12">
            <div class="auth-panel mx-auto max-w-xl rounded-2xl border border-[var(--sf-border)] bg-[var(--sf-surface)] p-5 sm:p-8">
                @include('customer.partials.navigation')
                <h1 class="font-display text-3xl font-black tracking-tight">Keamanan akun</h1>
                @include('customer.partials.auth-feedback')
                @if(!$authEnabled)
                    <p class="auth-help mt-3">Metode baru sementara tidak tersedia. Link email dan akses Recovery Key tetap dapat digunakan. Identitas, pesanan, dan saldo tidak berubah.</p>
                @elseif($customer->password === null)
                    <p class="mt-3 text-sm leading-6 text-[var(--sf-muted)]">Tambahkan password pertama dalam lima menit setelah masuk melalui link email. Pendaftaran ulang tidak dapat mengubah akun Anda.</p>
                    <form method="POST" action="{{ route('customer.password.enroll') }}" class="mt-6 space-y-4" data-auth-form>
                        @csrf
                        <label class="block text-sm font-black">Password baru
                            <input name="password" type="password" required minlength="12" maxlength="128" autocomplete="new-password" aria-describedby="enroll-password-help" class="mt-2 w-full rounded-lg border border-[var(--sf-muted)] bg-white px-4 py-3 focus:ring-2 focus:ring-[var(--sf-accent)]">
                        </label>
                        <p id="enroll-password-help" class="text-sm text-[var(--sf-muted)]">12–128 karakter. Spasi dan pengelola password dapat digunakan.</p>
                        <label class="block text-sm font-black">Ulangi password baru
                            <input name="password_confirmation" type="password" required minlength="12" maxlength="128" autocomplete="new-password" class="mt-2 w-full rounded-lg border border-[var(--sf-muted)] bg-white px-4 py-3 focus:ring-2 focus:ring-[var(--sf-accent)]">
                        </label>
                        <button class="neo-button w-full bg-[var(--sf-accent)] px-5 py-3 text-sm font-black text-white">Simpan password pertama</button>
                        <p data-auth-progress role="status" class="text-sm font-semibold" hidden>Mengirim permintaan...</p>
                    </form>
                @else
                    <p class="mt-4 text-sm leading-6">Password akun sudah tersedia. Untuk menggantinya, keluar lalu gunakan instruksi pemulihan password melalui email.</p>
                @endif
                @if($authEnabled)
                <section class="mt-6 border-t border-[var(--sf-border)] pt-4" aria-labelledby="google-security-title">
                    <h2 id="google-security-title" class="text-lg font-black">Google</h2>
                    <p class="auth-help mt-2">Hubungkan atau lepas Google dalam lima menit setelah masuk. Akun, pesanan, dan saldo tidak digabungkan.</p>
                    @if($googleLinked)
                        <p class="mt-3 text-sm font-bold">Google terhubung ke akun ini.</p>
                        @if($googleReady)
                            <form method="POST" action="{{ route('customer.google.unlink') }}" class="mt-3" data-auth-form>
                                @csrf
                                <button type="submit" class="auth-link">Lepas Google</button>
                                <p data-auth-progress role="status" hidden>Melepas Google...</p>
                            </form>
                        @else
                            <p class="auth-help mt-3">Konfigurasi Google belum tersedia. Pelepasan belum dapat dilakukan; gunakan metode masuk lain.</p>
                        @endif
                        <p class="auth-help mt-2">Pelepasan memerlukan metode masuk lain yang terverifikasi.</p>
                    @elseif($googleReady)
                        <form method="POST" action="{{ route('customer.google.link') }}" class="mt-3" data-auth-form>
                            @csrf
                            <button type="submit" class="auth-link">Hubungkan Google</button>
                            <p data-auth-progress role="status" hidden>Membuka Google...</p>
                        </form>
                    @else
                        <p class="auth-help mt-3">Google belum tersedia. Metode masuk lain tetap dapat digunakan.</p>
                    @endif
                </section>
                <section class="mt-6 border-t border-[var(--sf-border)] pt-4" aria-labelledby="telegram-security-title">
                    <h2 id="telegram-security-title" class="text-lg font-black">Telegram</h2>
                    @if($customer->telegram_id !== null)
                        <p class="auth-help mt-2">Telegram sudah terhubung. Identitas Store Bot tidak dapat dilepas atau dipindahkan.</p>
                        @if($telegramAuthenticated && $customer->email_verified_at === null)
                            <form method="POST" action="{{ route('customer.telegram.email') }}" class="mt-4 space-y-4" data-auth-form>
                                @csrf
                                <label class="auth-label">Email untuk verifikasi
                                    <input class="auth-input" name="email" type="email" required maxlength="254" autocomplete="email">
                                </label>
                                <p class="auth-help">Gunakan email yang belum digunakan dalam lima menit setelah konfirmasi Telegram. Verifikasi menambahkan email ke akun ini, bukan menggabungkan akun.</p>
                                <button class="auth-submit" type="submit">Kirim verifikasi email</button>
                                <p data-auth-progress role="status" hidden>Mengirim permintaan...</p>
                            </form>
                        @endif
                    @elseif($telegramReady)
                        <p class="auth-help mt-2">Hubungkan dalam lima menit setelah masuk, lalu konfirmasi di chat pribadi Store Bot. Akun dan saldo tidak digabungkan.</p>
                        <form method="POST" action="{{ route('customer.telegram.link') }}" data-telegram-form data-status-url="{{ route('customer.telegram.status') }}" data-consume-url="{{ route('customer.telegram.consume') }}" data-orders-url="{{ route('customer.security') }}" hidden>
                            @csrf
                            <button type="submit" class="auth-link">Hubungkan Telegram</button>
                        </form>
                        <div data-telegram-status role="status" class="auth-help mt-2"></div>
                        <noscript><p class="auth-help">Konfirmasi Telegram memerlukan JavaScript. Link email tetap dapat digunakan tanpa JavaScript.</p></noscript>
                    @else
                        <p class="auth-help mt-2">Telegram belum tersedia. Metode email tetap dapat digunakan.</p>
                    @endif
                </section>
                @endif
                <a href="{{ route('customer.orders.index') }}" class="mt-5 block py-3 text-center text-sm font-black underline underline-offset-4">Kembali ke pesanan</a>
                <form method="POST" action="{{ route('customer.logout') }}" class="mt-4">
                    @csrf
                    <button class="neo-button w-full bg-[var(--sf-ink)] px-5 py-3 text-sm font-black text-white">Keluar dari akun pelanggan</button>
                </form>
            </div>
        </main>
        @include('storefront.partials.footer')
    </div>
    @include('customer.partials.auth-progress')
</body>
</html>
