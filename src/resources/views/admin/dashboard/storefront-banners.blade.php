@if (isset($productsTab['banners']))
<section id="admin-storefront-banners" class="rounded-[1.125rem] border-[3px] border-neo-ink bg-white/80 p-4 sm:p-5" aria-labelledby="admin-storefront-banners-title">
    <h3 id="admin-storefront-banners-title" class="font-display text-base font-black text-neo-ink">Banner beranda storefront</h3>
    <p class="mt-2 text-xs font-semibold text-brand-muted">Maksimal 5 banner, tampil berurutan dari slot 1–5. Rekomendasi 1600 × 400 piksel (4:1), JPG/PNG/WebP maksimal 5 MB. Gambar tampil utuh. Banner ini khusus beranda web.</p>
    @if ($canManageProducts)
        @if ($errors->getBag('storefrontBanners')->any())
            <div role="alert" class="mt-3 text-sm font-bold text-red-700">
                @foreach ($errors->getBag('storefrontBanners')->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif
        <form method="POST" enctype="multipart/form-data" action="{{ $productsTab['banners']['update_url'] }}" class="mt-4 space-y-3">
            @csrf
            <input type="hidden" name="version" value="{{ $productsTab['banners']['version'] }}">
            @for ($slot = 0; $slot < 5; $slot++)
                @php($banner = $productsTab['banners']['slots'][$slot] ?? null)
                <details class="rounded-xl border border-brand-border bg-white p-3" @if ($slot === 0) open @endif>
                    <summary class="min-h-11 cursor-pointer text-sm font-bold">Banner {{ $slot + 1 }} · {{ $banner ? $banner['title'] : 'Kosong' }}</summary>
                    @if ($banner)
                        <img src="/storage/{{ $banner['path'] }}" alt="{{ $banner['title'] }}" width="1600" height="400" loading="lazy" class="my-3 aspect-[4/1] w-full rounded-lg bg-brand-bg object-contain">
                    @endif
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="storefront-banner-title-{{ $slot }}" class="text-xs font-bold">Deskripsi gambar</label>
                            <x-admin-dashboard.input id="storefront-banner-title-{{ $slot }}" name="banners[{{ $slot }}][title]" maxlength="160" value="{{ old('banners.'.$slot.'.title', $banner['title'] ?? '') }}" class="mt-1" placeholder="Contoh: Promo akun premium" />
                        </div>
                        <div>
                            <label for="storefront-banner-link-{{ $slot }}" class="text-xs font-bold">Tautan halaman toko (opsional)</label>
                            <x-admin-dashboard.input id="storefront-banner-link-{{ $slot }}" name="banners[{{ $slot }}][link]" maxlength="2048" value="{{ old('banners.'.$slot.'.link', $banner['link'] ?? '') }}" class="mt-1" placeholder="/katalog" />
                        </div>
                        <div class="sm:col-span-2">
                            <label for="storefront-banner-image-{{ $slot }}" class="mb-2 block text-xs font-bold">{{ $banner ? 'Ganti gambar (kosongkan untuk mempertahankan)' : 'Upload gambar' }}</label>
                            <input id="storefront-banner-image-{{ $slot }}" type="file" name="banners[{{ $slot }}][image]" accept="image/jpeg,image/png,image/webp" class="w-full min-w-0 text-xs file:mr-3 file:rounded-lg file:border-0 file:bg-brand-bg file:p-3 file:font-bold">
                        </div>
                        <label class="inline-flex min-h-11 items-center gap-2 text-xs font-bold">
                            <input type="checkbox" name="banners[{{ $slot }}][remove]" value="1"> Kosongkan slot ini
                        </label>
                    </div>
                </details>
            @endfor
            <x-admin-dashboard.button type="submit" variant="purple" size="sm">Simpan banner beranda</x-admin-dashboard.button>
        </form>
    @else
        <p class="mt-3 text-xs font-semibold text-brand-muted">Owner/Admin dapat mengelola banner beranda.</p>
    @endif
</section>
@endif
