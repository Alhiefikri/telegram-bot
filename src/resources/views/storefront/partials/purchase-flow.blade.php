<section id="cara-beli" class="mx-auto max-w-5xl px-6 py-28 border-t border-slate-200 mt-10 relative">
    <div class="max-w-2xl mb-16">
        <h2 class="text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900">Belanja akun AI dalam tiga langkah.</h2>
        <p class="mt-3 text-sm text-zinc-500 leading-relaxed max-w-lg">Pilih produk dari katalog, hubungi support untuk instruksi pembayaran, lalu tunggu akses digital dikirim setelah konfirmasi.</p>
    </div>

    <div class="grid sm:grid-cols-3 gap-6">
        @foreach ($copy['payment_flow']['steps'] as $index => $step)
            <div class="reveal-item opacity-0 transform translate-y-8 bg-white p-6 rounded-2xl border border-zinc-200/80 relative shadow-sm transition-shadow duration-300 hover:shadow-md">
                <span class="flex h-10 w-10 items-center justify-center rounded-full bg-slate-900 font-bold text-white text-base shadow-md mb-5">
                    0{{ $index + 1 }}
                </span>
                <h3 class="text-sm font-bold text-zinc-900">{{ $step['title'] ?? 'Langkah' }}</h3>
                <p class="mt-2 text-sm text-zinc-500 leading-relaxed">{{ $step['body'] ?? $step }}</p>
            </div>
        @endforeach
    </div>

    <div class="mt-16 grid sm:grid-cols-2 gap-8 border-t border-zinc-100 pt-12">
        <div class="bg-zinc-50/50 p-6 rounded-2xl border border-zinc-100">
            <p class="mb-2 text-[10px] font-bold uppercase tracking-[0.2em] text-slate-500">{{ $copy['delivery']['eyebrow'] }}</p>
            <h3 class="text-base font-bold text-zinc-900">{{ $copy['delivery']['title'] }}</h3>
            <p class="mt-3 text-sm text-zinc-500 leading-relaxed">{{ $copy['delivery']['body'] }}</p>
        </div>
        <div class="bg-zinc-50/50 p-6 rounded-2xl border border-zinc-100">
            <p class="mb-2 text-[10px] font-bold uppercase tracking-[0.2em] text-slate-500">{{ $copy['telegram_support']['eyebrow'] }}</p>
            <h3 class="text-base font-bold text-zinc-900">{{ $copy['telegram_support']['title'] }}</h3>
            <p class="mt-3 text-sm text-zinc-500 leading-relaxed">{{ $copy['telegram_support']['body'] }}</p>
        </div>
    </div>
</section>
