<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $headline }}</title>
</head>
<body style="margin:0;background:#f4f1e8;color:#161616;font-family:Arial,sans-serif;line-height:1.6">
    <main style="max-width:620px;margin:0 auto;padding:32px 20px">
        <section style="background:#fff;border:2px solid #161616;padding:24px;box-shadow:5px 5px 0 #161616">
            <p style="margin:0 0 8px;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase">{{ config('app.name') }}</p>
            <h1 style="margin:0 0 16px;font-size:25px;line-height:1.2">{{ $headline }}</h1>
            <p>{{ $intro }}</p>

            <dl>
                <dt style="font-weight:700">Invoice</dt>
                <dd style="margin:0 0 12px">{{ $invoiceNumber }}</dd>
                <dt style="font-weight:700">Status saat ini</dt>
                <dd style="margin:0 0 12px">{{ $statusLabel }} ({{ $orderStatus }})</dd>
            </dl>

            <h2 style="font-size:17px">Ringkasan pesanan</h2>
            <ul>
                @forelse ($items as $item)
                    <li>{{ $item['name'] }} × {{ $item['quantity'] }}</li>
                @empty
                    <li>Detail produk tersedia di halaman invoice.</li>
                @endforelse
            </ul>

            @if ($paymentDeadline)
                <p><strong>Batas pembayaran:</strong> {{ $paymentDeadline }}</p>
            @endif

            <p style="margin-top:24px">
                <a href="{{ $invoiceUrl }}" style="display:inline-block;background:#f3d34a;border:2px solid #161616;color:#161616;padding:11px 16px;font-weight:700;text-decoration:none">Buka invoice dan status</a>
            </p>
            <p><a href="{{ $orderAccessUrl }}">Akses pesanan</a></p>

            <p style="margin-top:24px;font-size:13px;color:#555">Email status ini tidak memuat data akun. Data akun dikirim lewat email terpisah setelah pembayaran terverifikasi bila email diisi saat checkout, dan tetap bisa dibuka dari halaman invoice sesuai kebijakan pesanan yang berlaku.</p>
        </section>
    </main>
</body>
</html>
