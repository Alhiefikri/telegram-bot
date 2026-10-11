{{ $headline }}

{{ $intro }}

Invoice: {{ $invoiceNumber }}
Status saat ini: {{ $statusLabel }} ({{ $orderStatus }})

Ringkasan pesanan:
@forelse ($items as $item)
- {{ $item['name'] }} x {{ $item['quantity'] }}
@empty
- Detail produk tersedia di halaman invoice.
@endforelse
@if ($paymentDeadline)

Batas pembayaran: {{ $paymentDeadline }}
@endif

Buka invoice dan status: {{ $invoiceUrl }}
Akses pesanan: {{ $orderAccessUrl }}

Email status ini tidak memuat data akun. Data akun dikirim lewat email terpisah setelah pembayaran terverifikasi bila email diisi saat checkout, dan tetap bisa dibuka dari halaman invoice sesuai kebijakan pesanan yang berlaku.
