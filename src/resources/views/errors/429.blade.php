@php($isOrderAccess = request()->routeIs('storefront.order-access.*'))

@include('errors.partials.storefront-page', [
    'status' => '429',
    'pageTitle' => 'Terlalu banyak percobaan',
    'title' => $isOrderAccess ? 'Tunggu sebentar sebelum mencoba recovery key lagi.' : 'Tunggu sebentar sebelum checkout lagi.',
    'body' => $isOrderAccess
        ? 'Untuk melindungi data akun, percobaan membuka pesanan dibatasi sementara. Pastikan nomor invoice dan recovery key yang disimpan sudah benar sebelum mencoba lagi.'
        : 'Untuk menjaga stok dan pembayaran tetap aman, checkout dibatasi sementara. Pesanan Anda belum dibuat dan stok belum berkurang.',
    'primaryHref' => $isOrderAccess ? route('storefront.order-access.create') : app(\App\Storefront\StorefrontUrl::class)->catalogUrl(),
    'primaryLabel' => $isOrderAccess ? 'Kembali ke akses pesanan' : 'Kembali ke katalog',
])
