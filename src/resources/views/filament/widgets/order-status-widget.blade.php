<x-filament-widgets::widget>
<div class="min-w-0 overflow-hidden rounded-[1.25rem] border border-slate-200 bg-white p-5 shadow-sm">
    <div class="flex items-center justify-between gap-4 border-b border-slate-100 pb-4">
        <div>
            <p class="text-[11px] font-bold uppercase tracking-[0.28em] text-sky-600">Status order</p>
            <h2 class="mt-2 font-['Space_Grotesk'] text-xl font-bold tracking-[-0.04em] text-slate-900">Distribusi status pesanan</h2>
        </div>
        <div class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em] text-slate-600">
            Total {{ $total }}
        </div>
    </div>

    <div class="mt-5 grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-1">
        @foreach ($statuses as $status)
            <div class="min-w-0 rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                <div class="flex items-center justify-between gap-4">
                    <x-filament.widgets.partials.status-pill :label="$status['label']" :tone="$status['tone']" />
                    <div class="text-3xl font-bold tracking-[-0.05em] text-slate-900">{{ $status['count'] }}</div>
                </div>
            </div>
        @endforeach
    </div>
</div>
</x-filament-widgets::widget>
