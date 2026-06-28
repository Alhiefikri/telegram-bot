<x-filament-widgets::widget>
<div class="min-w-0 overflow-hidden rounded-[1.25rem] border border-slate-200 bg-white p-5 shadow-sm">
    <div class="flex items-center justify-between gap-4 border-b border-slate-100 pb-4">
        <div>
            <p class="text-[11px] font-bold uppercase tracking-[0.28em] text-sky-600">Stok perlu dicek</p>
            <h2 class="mt-2 font-['Space_Grotesk'] text-xl font-bold tracking-[-0.04em] text-slate-900">Produk dengan stok tipis atau rawan habis</h2>
        </div>
    </div>

    <div class="mt-5">
        @if ($isEmpty)
            <x-filament.widgets.partials.empty-state
                title="Stok aman"
                description="Belum ada produk aktif yang masuk daftar pantau stok."
            />
        @else
            <div class="grid gap-3">
                @foreach ($products as $product)
                    <div class="min-w-0 rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                        <div class="flex items-center justify-between gap-4">
                            <div class="min-w-0">
                                <p class="truncate font-semibold text-slate-900">{{ $product['name'] }}</p>
                                <p class="mt-1 break-words text-sm text-slate-500">{{ $product['price'] }} · Reserved: {{ $product['reserved_stock'] }}</p>
                                <a href="{{ $product['url'] }}" class="mt-3 inline-flex text-xs font-semibold text-sky-700 hover:text-sky-900">Buka produk →</a>
                            </div>
                            <div class="text-right">
                                <div class="text-2xl font-bold tracking-[-0.05em] text-slate-900">{{ $product['stock'] }}</div>
                                <div class="mt-1 text-xs font-semibold uppercase tracking-[0.18em] {{ $product['needs_attention'] ? 'text-amber-600' : 'text-sky-600' }}">
                                    {{ $product['status_label'] }}
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
</x-filament-widgets::widget>
