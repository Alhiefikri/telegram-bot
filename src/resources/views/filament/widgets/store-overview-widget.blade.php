<x-filament-widgets::widget>
<div class="min-w-0 overflow-hidden rounded-[1.25rem] border border-sky-100 bg-white/95 p-5 shadow-[0_14px_40px_rgba(37,99,235,0.06)]">
    <div class="flex flex-col gap-2 border-b border-slate-100 pb-4 md:flex-row md:items-end md:justify-between">
        <div>
            <p class="text-[11px] font-bold uppercase tracking-[0.28em] text-sky-600">Ringkasan cepat</p>
            <h2 class="mt-2 font-['Space_Grotesk'] text-xl font-bold tracking-[-0.04em] text-slate-900">Status operasional store</h2>
        </div>
        <p class="max-w-2xl text-sm leading-6 text-slate-500">Semua angka di bawah ini diambil langsung dari data pesanan, pembayaran, dan produk aktif.</p>
    </div>

    <div class="mt-5 grid min-w-0 grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5">
        @foreach ($cards as $card)
            <div class="min-w-0 rounded-2xl border border-slate-200 bg-slate-50/80 p-4 shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <p class="min-w-0 text-sm font-medium leading-5 text-slate-500">{{ $card['label'] }}</p>
                    <span @class([
                        'h-2.5 w-2.5 rounded-full',
                        'bg-sky-500' => $card['tone'] === 'blue',
                        'bg-cyan-500' => $card['tone'] === 'cyan',
                        'bg-emerald-500' => $card['tone'] === 'emerald',
                        'bg-amber-500' => $card['tone'] === 'amber',
                        'bg-slate-400' => $card['tone'] === 'slate',
                    ])></span>
                </div>

                <p class="mt-4 min-w-0 break-words text-2xl font-bold tracking-[-0.05em] text-slate-900 sm:text-3xl">{{ $card['value'] }}</p>
            </div>
        @endforeach
    </div>
</div>
</x-filament-widgets::widget>
