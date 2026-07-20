@include('errors.partials.storefront-page', [
    'status' => '404',
    'pageTitle' => 'Halaman tidak ditemukan',
    'title' => 'Halaman ini tidak ditemukan.',
    'body' => 'Tautannya mungkin sudah berubah atau halaman tersebut tidak tersedia. Kembali ke katalog untuk memilih produk yang aktif.',
    'primaryHref' => app(\App\Storefront\StorefrontUrl::class)->catalogUrl(),
    'primaryLabel' => 'Lihat katalog',
])
