<x-filament-widgets::widget>
<x-lhie.dashboard-card>
    <x-lhie.section-header eyebrow="Status pesanan" title="Metrik status pesanan">
        <x-slot:aside>
            <div class="lhie-status-pill" data-tone="slate">
                Total {{ $total }}
            </div>
        </x-slot:aside>
    </x-lhie.section-header>

    <div class="mt-5 space-y-3">
        @foreach ($statuses as $status)
            @php($progressWidth = $status['percentage'].'%')
            <div class="space-y-1">
                <div class="flex justify-between gap-3 text-[11px] font-semibold text-brand-navy">
                    <span class="min-w-0 truncate">{{ $status['label'] }}</span>
                    <span class="shrink-0 font-mono text-slate-500">{{ $status['count'] }} ({{ $status['percentage'] }}%)</span>
                </div>

                <div class="lhie-progress-track">
                    <div class="lhie-progress-fill" data-tone="{{ $status['tone'] }}" @style(['width: '.$progressWidth])></div>
                </div>
            </div>
        @endforeach
    </div>
</x-lhie.dashboard-card>
</x-filament-widgets::widget>
