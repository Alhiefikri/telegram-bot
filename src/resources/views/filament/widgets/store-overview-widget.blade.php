<x-filament-widgets::widget>
<x-lhie.dashboard-card>
    <x-lhie.section-header
        eyebrow="Ringkasan cepat"
        title="Status operasional toko"
        copy="Angka di bawah ini diambil langsung dari data pesanan, pembayaran, dan produk aktif."
    />

    <div class="lhie-metric-grid">
        @foreach ($cards as $card)
            <article class="lhie-metric-card">
                <div class="lhie-metric-head">
                    <div class="min-w-0">
                        <p class="lhie-metric-label">{{ $card['label'] }}</p>
                        <p class="lhie-metric-value">{{ $card['value'] }}</p>
                    </div>

                    <div class="lhie-icon-tile" data-tone="{{ $card['tone'] }}">
                        <x-lhie.icon :name="$card['icon']" />
                    </div>
                </div>

                <p class="lhie-metric-note">{{ $card['note'] }}</p>
            </article>
        @endforeach
    </div>
</x-lhie.dashboard-card>
</x-filament-widgets::widget>
