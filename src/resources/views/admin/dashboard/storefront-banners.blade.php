@if (isset($productsTab['banners']))
<x-admin-dashboard.card as="section" id="admin-storefront-banners" padding="compact" shadow="sm" aria-labelledby="admin-storefront-banners-title">
    <h3 id="admin-storefront-banners-title" class="text-lg font-semibold text-admin-ink">Banner beranda storefront</h3>
    <p class="mt-1 max-w-3xl text-sm leading-6 text-admin-muted">Maksimal 5 banner, tampil berurutan dari slot 1–5. Rekomendasi 1600 × 400 piksel (4:1), JPG/PNG/WebP maksimal 5 MB. Gambar tampil utuh. Banner ini khusus beranda web.</p>
    @if ($canManageProducts)
        @if ($errors->getBag('storefrontBanners')->any())
            <div role="alert" class="mt-3 rounded-lg border border-admin-line bg-neo-danger-pale p-3 text-sm font-medium text-neo-danger-text">
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
                <details class="rounded-lg border border-admin-line bg-admin-surface p-3" @if ($slot === 0) open @endif>
                    <summary class="min-h-11 cursor-pointer text-sm font-medium text-admin-ink">Banner {{ $slot + 1 }} · {{ $banner ? $banner['title'] : 'Kosong' }}</summary>
                    @if ($banner)
                        <img src="/storage/{{ $banner['path'] }}" alt="{{ $banner['title'] }}" width="1600" height="400" loading="lazy" class="my-3 aspect-[4/1] w-full rounded-lg bg-admin-surface-muted object-contain">
                    @endif
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <x-admin-dashboard.field-label for="storefront-banner-title-{{ $slot }}">Deskripsi gambar</x-admin-dashboard.field-label>
                            <x-admin-dashboard.input id="storefront-banner-title-{{ $slot }}" name="banners[{{ $slot }}][title]" maxlength="160" value="{{ old('banners.'.$slot.'.title', $banner['title'] ?? '') }}" class="mt-1" placeholder="Contoh: Promo akun premium" />
                        </div>
                        <div>
                            <x-admin-dashboard.field-label for="storefront-banner-link-{{ $slot }}">Tautan halaman toko (opsional)</x-admin-dashboard.field-label>
                            <x-admin-dashboard.input id="storefront-banner-link-{{ $slot }}" name="banners[{{ $slot }}][link]" maxlength="2048" value="{{ old('banners.'.$slot.'.link', $banner['link'] ?? '') }}" class="mt-1" placeholder="/katalog" />
                        </div>
                        <div class="sm:col-span-2">
                            <x-admin-dashboard.field-label for="storefront-banner-image-{{ $slot }}" class="mb-2 block">{{ $banner ? 'Ganti gambar (kosongkan untuk mempertahankan)' : 'Upload gambar' }}</x-admin-dashboard.field-label>
                            <input id="storefront-banner-image-{{ $slot }}" type="file" name="banners[{{ $slot }}][image]" accept="image/jpeg,image/png,image/webp" class="min-h-11 w-full min-w-0 rounded-lg border border-admin-line bg-admin-surface text-xs file:mr-3 file:min-h-11 file:rounded-md file:border-0 file:bg-admin-accent file:p-3 file:text-xs file:font-semibold file:text-admin-accent-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-admin-focus">
                        </div>
                        <label class="inline-flex min-h-11 items-center gap-2 text-sm font-medium text-admin-ink">
                            <input type="checkbox" name="banners[{{ $slot }}][remove]" value="1" class="h-5 w-5 rounded border-admin-line accent-admin-accent"> Kosongkan slot ini
                        </label>
                    </div>
                </details>
            @endfor
            <x-admin-dashboard.button type="submit" variant="primary" size="sm">Simpan banner beranda</x-admin-dashboard.button>
        </form>
    @else
        <p class="mt-3 text-sm text-admin-muted">Owner/Admin dapat mengelola banner beranda.</p>
    @endif
</x-admin-dashboard.card>
@endif
