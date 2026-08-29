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

Tautan email ini hanya membuka permukaan invoice/status. Rahasia akses tidak dikirim melalui email.
