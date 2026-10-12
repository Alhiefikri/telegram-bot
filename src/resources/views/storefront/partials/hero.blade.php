<section class="storefront-hero-card storefront-banner-carousel" aria-label="Banner toko" aria-roledescription="carousel" data-storefront-carousel data-carousel-align="center" style="--sf-banner-aspect: {{ $homepageBannerAspect ?? '4 / 1' }}">
    <h1 class="sr-only">{{ $brand }} — Produk digital</h1>
    <div class="storefront-banner-viewport">
        <div class="storefront-banner-track" data-carousel-track>
            @forelse ($homepageBanners ?? [] as $banner)
                <div class="storefront-banner-slide" data-carousel-slide>
                    @if ($banner['link'])
                        <a href="{{ $banner['link'] }}" aria-label="{{ $banner['title'] }}">
                    @endif
                    <img src="{{ $banner['imageUrl'] }}" alt="{{ $banner['title'] }}" width="{{ $banner['width'] ?? 1600 }}" height="{{ $banner['height'] ?? 400 }}" loading="{{ $loop->first ? 'eager' : 'lazy' }}" fetchpriority="{{ $loop->first ? 'high' : 'auto' }}" decoding="async">
                    @if ($banner['link'])
                        </a>
                    @endif
                </div>
            @empty
                <div class="storefront-banner-slide storefront-banner-empty" aria-label="Banner toko"></div>
            @endforelse
        </div>
        @if (count($homepageBanners ?? []) > 1)
            <div class="carousel-overlay" data-carousel-controls hidden>
                <button type="button" class="carousel-nav carousel-nav--prev" data-carousel-prev aria-label="Banner sebelumnya">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m14.5 5.5-7 6.5 7 6.5" /></svg>
                </button>
                <button type="button" class="carousel-nav carousel-nav--next" data-carousel-next aria-label="Banner berikutnya">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9.5 5.5 7 6.5-7 6.5" /></svg>
                </button>
                <div class="carousel-status">
                    <div class="carousel-dots">
                        @foreach ($homepageBanners as $banner)
                            <button type="button" data-carousel-dot="{{ $loop->index }}" aria-label="Lihat banner {{ $loop->iteration }}" aria-current="{{ $loop->first ? 'true' : 'false' }}"></button>
                        @endforeach
                    </div>
                    <button type="button" class="carousel-pause" data-carousel-pause aria-label="Jeda pergantian otomatis" aria-pressed="false">
                        <svg data-icon="pause" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M9.5 5v14" /><path d="M14.5 5v14" /></svg>
                        <svg data-icon="play" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8.5 5.5 18 12l-9.5 6.5z" /></svg>
                    </button>
                </div>
            </div>
        @endif
    </div>
</section>
