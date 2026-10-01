<section class="storefront-hero-card storefront-banner-carousel" aria-label="Banner toko" aria-roledescription="carousel" data-storefront-carousel data-carousel-align="center">
    <h1 class="sr-only">{{ $brand }} — Produk digital</h1>
    <div class="storefront-banner-track" data-carousel-track>
        @forelse ($homepageBanners ?? [] as $banner)
            <div class="storefront-banner-slide" data-carousel-slide>
                @if ($banner['link'])
                    <a href="{{ $banner['link'] }}" aria-label="{{ $banner['title'] }}">
                @endif
                <img src="{{ $banner['imageUrl'] }}" alt="{{ $banner['title'] }}" width="1600" height="400" loading="{{ $loop->first ? 'eager' : 'lazy' }}" fetchpriority="{{ $loop->first ? 'high' : 'auto' }}" decoding="async">
                @if ($banner['link'])
                    </a>
                @endif
            </div>
        @empty
            <div class="storefront-banner-slide storefront-banner-empty" aria-label="Banner toko"></div>
        @endforelse
    </div>
    @if (count($homepageBanners ?? []) > 1)
        <div class="carousel-controls" data-carousel-controls hidden>
            <button type="button" data-carousel-prev aria-label="Banner sebelumnya">←</button>
            <div class="carousel-dots">
                @foreach ($homepageBanners as $banner)
                    <button type="button" data-carousel-dot="{{ $loop->index }}" aria-label="Lihat banner {{ $loop->iteration }}" aria-current="{{ $loop->first ? 'true' : 'false' }}"></button>
                @endforeach
            </div>
            <button type="button" data-carousel-next aria-label="Banner berikutnya">→</button>
            <button type="button" data-carousel-pause aria-label="Jeda pergantian otomatis" aria-pressed="false">Ⅱ</button>
        </div>
    @endif
</section>
