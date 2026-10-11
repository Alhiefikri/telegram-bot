<!DOCTYPE html>
<html lang="{{ $metadata->htmlLocale() }}" class="bg-[var(--sf-paper)]">
<head>@include('storefront.partials.head', ['metadata' => $metadata])</head>
<body class="customer-auth text-[var(--sf-ink)]">
    <div class="storefront-shell flex min-h-screen flex-col">
        <div class="pt-4">@include('storefront.partials.header')</div>
        <main class="flex-1 px-5 pb-20 pt-8 sm:pt-12">
            <div class="auth-panel mx-auto max-w-xl rounded-2xl border border-[var(--sf-border)] bg-[var(--sf-surface)] p-5 sm:p-8">
                <h1 class="font-display text-3xl font-black">Konfirmasi link login</h1>
                <p class="auth-help mt-3">Link sekali pakai berlaku 15 menit. Konfirmasi hanya jika Anda meminta link ini. Membuka halaman ini belum membuat Anda masuk.</p>
                @include('customer.partials.auth-feedback')
                <form method="POST" action="{{ $consumeUrl }}" class="mt-6 space-y-4" data-auth-form>
                    @csrf
                    <label class="auth-label" id="challenge-label">Kode dari link email
                        <input class="auth-input" id="challenge-token" name="token" type="text" required maxlength="64" pattern="[a-fA-F0-9]{64}" autocomplete="off" spellcheck="false" aria-describedby="challenge-help">
                    </label>
                    <p id="challenge-help" class="auth-help">Tanpa JavaScript, salin 64 karakter setelah #token= dari link email. Jangan bagikan kode ini.</p>
                    <button class="auth-submit" type="submit">Konfirmasi link dan masuk</button>
                    <p data-auth-progress role="status" hidden>Mengonfirmasi link...</p>
                </form>
                <a class="auth-link mt-5" href="{{ $accountUrl }}">Kembali ke akun atau minta link baru</a>
            </div>
        </main>
        @include('storefront.partials.footer')
    </div>
    <script>
    (() => {
        const token = new URLSearchParams(location.hash.slice(1)).get('token') || '';
        history.replaceState(null, '', location.pathname);
        if (/^[a-f0-9]{64}$/i.test(token)) {
            const input = document.getElementById('challenge-token');
            input.value = token;
            input.type = 'hidden';
            document.getElementById('challenge-label').hidden = true;
            document.getElementById('challenge-help').hidden = true;
        }
    })();
    </script>
    @include('customer.partials.auth-progress')
</body>
</html>
