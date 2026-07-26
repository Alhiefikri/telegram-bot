@php
    $marqueeItems = [
        'AI Premium Store',
        'ChatGPT Plus',
        'Canva Pro',
        'Akun Premium Original',
        'Pengiriman Instan',
        'QRIS Otomatis',
        'Recovery Key Aman',
    ];
@endphp

<div class="neo-marquee relative z-20 -mx-1 -rotate-1" role="presentation" aria-hidden="true">
    <div class="neo-marquee-track">
        @foreach (range(1, 2) as $group)
            <div class="neo-marquee-group">
                @foreach ($marqueeItems as $item)
                    <span class="neo-marquee-item">
                        <span>{{ $item }}</span>
                        <span class="text-[var(--sf-violet)]">✦</span>
                    </span>
                @endforeach
            </div>
        @endforeach
    </div>
</div>
