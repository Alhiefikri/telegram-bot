@include('errors.partials.storefront-page', [
    'status' => '403',
    'pageTitle' => 'Akses belum terbuka',
    'title' => 'Data pesanan belum dapat dibuka.',
    'body' => 'Masukkan kembali nomor invoice dan recovery key yang Anda simpan saat checkout. Halaman data akun hanya tersedia setelah pembayaran terverifikasi.',
    'primaryHref' => route('storefront.order-access.create'),
    'primaryLabel' => 'Masukkan invoice & recovery key',
])
