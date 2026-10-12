<!DOCTYPE html>
<html lang="{{ $metadata->htmlLocale() }}" class="bg-[var(--sf-paper)]">
<head>@include('storefront.partials.head', ['metadata' => $metadata])</head>
<body class="customer-auth text-[var(--sf-ink)]">
    <div class="storefront-shell flex min-h-screen flex-col">
        <div class="pt-4">@include('storefront.partials.header')</div>
        <main class="flex-1 px-5 pb-20 pt-8 sm:pt-12">
            <div class="auth-panel mx-auto max-w-xl rounded-2xl border border-[var(--sf-border)] bg-[var(--sf-surface)] p-5 sm:p-8">
                <h1 class="font-display text-3xl font-black tracking-tight">{{ $reset ? 'Atur ulang password' : 'Verifikasi email' }}</h1>
                <p class="mt-3 text-sm leading-6 text-[var(--sf-muted)]">{{ $reset ? 'Pilih password baru. Setelah berhasil, semua sesi pelanggan sebelumnya berakhir dan Anda perlu masuk kembali.' : 'Konfirmasi email untuk menyelesaikan pendaftaran dan masuk ke akun Anda.' }} Link berlaku 30 menit dan hanya dapat digunakan sekali.</p>
                @include('customer.partials.auth-feedback')
                <form method="POST" action="{{ route($reset ? 'customer.password-reset.consume' : 'customer.verification.consume') }}" class="mt-6 space-y-4" data-auth-form>
                    @csrf
                    <label class="block text-sm font-black" id="challenge-label">Kode dari link email
                        <input id="challenge-token" name="token" type="text" required maxlength="64" pattern="[a-fA-F0-9]{64}" autocomplete="off" spellcheck="false" aria-describedby="challenge-help" class="mt-2 w-full rounded-lg border border-[var(--sf-muted)] bg-white px-4 py-3 focus:ring-2 focus:ring-[var(--sf-accent)]">
                    </label>
                    <p id="challenge-help" class="text-sm text-[var(--sf-muted)]">Jika kode belum terisi, salin 64 karakter setelah #token= dari link email. Jangan bagikan kode ini.</p>
                    @if($reset)
                        <label class="block text-sm font-black">Password baru
                            <input name="password" type="password" required minlength="8" maxlength="128" autocomplete="new-password" aria-describedby="reset-password-help" class="mt-2 w-full rounded-lg border border-[var(--sf-muted)] bg-white px-4 py-3 focus:ring-2 focus:ring-[var(--sf-accent)]">
                        </label>
                        <p id="reset-password-help" class="text-sm text-[var(--sf-muted)]">Minimal 8 karakter, dengan huruf besar, angka, dan simbol.</p>
                        <label class="block text-sm font-black">Ulangi password baru
                            <input name="password_confirmation" type="password" required minlength="8" maxlength="128" autocomplete="new-password" class="mt-2 w-full rounded-lg border border-[var(--sf-muted)] bg-white px-4 py-3 focus:ring-2 focus:ring-[var(--sf-accent)]">
                        </label>
                    @endif
                    <button class="neo-button w-full bg-[var(--sf-accent)] px-5 py-3 text-sm font-black text-white">{{ $reset ? 'Simpan password baru' : 'Konfirmasi email dan masuk' }}</button>
                    <p data-auth-progress role="status" class="text-sm font-semibold" hidden>Mengirim permintaan...</p>
                </form>
                <a href="{{ route('customer.account') }}" class="mt-5 block py-3 text-center text-sm font-black underline underline-offset-4">Kembali ke akun atau minta link baru</a>
            </div>
        </main>
        @include('storefront.partials.footer')
    </div>
    <script>
    const token = new URLSearchParams(location.hash.slice(1)).get('token') || '';
    history.replaceState(null, '', location.pathname);
    if (/^[a-f0-9]{64}$/i.test(token)) {
        const input = document.getElementById('challenge-token');
        input.value = token;
        input.type = 'hidden';
        document.getElementById('challenge-label').hidden = true;
        document.getElementById('challenge-help').hidden = true;
    }
    </script>
    @include('customer.partials.auth-progress')
</body>
</html>
