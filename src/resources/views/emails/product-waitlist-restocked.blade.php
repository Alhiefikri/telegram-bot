<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Produk tersedia kembali</title>
</head>
<body style="margin:0;background:#f4f1e8;color:#161616;font-family:Arial,sans-serif;line-height:1.6">
    <main style="max-width:620px;margin:0 auto;padding:32px 20px">
        <section style="background:#fff;border:2px solid #161616;padding:24px;box-shadow:5px 5px 0 #161616">
            <p style="margin:0 0 8px;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase">{{ config('app.name') }}</p>
            <h1 style="margin:0 0 16px;font-size:25px;line-height:1.2">Produk tersedia kembali</h1>
            <p style="font-weight:700">{{ $productName }}</p>
            <p>Stok tersedia: {{ $availableStock }}.</p>
            <p style="margin-top:24px">
                <a href="{{ $productUrl }}" style="display:inline-block;background:#f3d34a;border:2px solid #161616;color:#161616;padding:11px 16px;font-weight:700;text-decoration:none">Buka produk</a>
            </p>
            <p style="margin-top:24px;font-size:13px;color:#555"><a href="{{ $unsubscribeUrl }}">Berhenti menerima notifikasi produk ini</a></p>
        </section>
    </main>
</body>
</html>
