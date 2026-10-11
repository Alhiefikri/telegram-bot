<!DOCTYPE html>
<html lang="{{ $metadata->htmlLocale() }}" class="bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head', ['metadata' => $metadata])
</head>
<body class="text-[var(--sf-ink)]">
    <div class="storefront-shell flex min-h-screen flex-col">
        <div class="pt-4">
            @include('storefront.partials.header')
        </div>

        <main class="flex-1 px-5 pb-20 pt-12 sm:pt-16">
            <div class="mx-auto max-w-xl rounded-2xl border border-[var(--sf-border)] bg-[var(--sf-surface)] p-6 shadow-[0_12px_30px_rgb(18_35_63_/_0.1)] sm:p-8">
                @include('customer.partials.navigation')
                <h1 class="mt-5 font-display text-4xl font-black tracking-tight">Klaim pesanan guest</h1>
                <p class="mt-3 text-sm font-semibold leading-6 text-[var(--sf-muted)]">Gunakan invoice dan Recovery Key yang Anda terima saat checkout. Jangan bagikan Recovery Key. Support tidak dapat mengklaimkan order atau membuka kredensial tanpa Recovery Key yang valid.</p>

                @if(session('customer_claim_error'))
                    <div class="mt-5 rounded-lg border border-[var(--sf-border)] bg-[#ffe8f0] p-4 text-sm font-bold">{{ session('customer_claim_error') }}</div>
                @endif

                <form method="POST" action="{{ route('customer.claim.store') }}" class="mt-6 space-y-4">
                    @csrf
                    <label class="block text-sm font-black">Nomor invoice
                        <input name="invoice_number" required value="{{ old('invoice_number') }}" class="mt-2 w-full rounded-lg border border-[var(--sf-border)] bg-white px-4 py-3 font-bold focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)]">
                    </label>
                    <label class="block text-sm font-black">Recovery Key
                        <input name="access_key" required autocomplete="off" class="mt-2 w-full rounded-lg border border-[var(--sf-border)] bg-white px-4 py-3 font-bold focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)]">
                    </label>
                    <button class="neo-button w-full bg-[var(--sf-accent)] px-5 py-3 text-sm font-black text-white">Klaim pesanan</button>
                </form>

                <a href="{{ route('storefront.policies.recovery') }}" class="mt-5 block text-center text-sm font-black underline underline-offset-4">Kebijakan pemulihan pesanan</a>
            </div>
        </main>

        @include('storefront.partials.footer')
    </div>
</body>
</html>
