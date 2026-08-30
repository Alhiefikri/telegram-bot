<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Konfirmasi notifikasi stok</title>
</head>
<body style="margin:0;background:#f4f1e8;color:#161616;font-family:Arial,sans-serif;line-height:1.6">
    <main style="max-width:620px;margin:0 auto;padding:32px 20px">
        <section style="background:#fff;border:2px solid #161616;padding:24px;box-shadow:5px 5px 0 #161616">
            <p style="margin:0 0 8px;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase">{{ config('app.name') }}</p>
            <h1 style="margin:0 0 16px;font-size:25px;line-height:1.2">Konfirmasi permintaan stok</h1>
            <p>Klik tombol di bawah untuk mengaktifkan notifikasi saat produk tersedia kembali.</p>
            <p style="font-weight:700">{{ $productName }}</p>
            <p style="margin-top:24px">
                <a href="{{ $confirmationUrl }}" style="display:inline-block;background:#f3d34a;border:2px solid #161616;color:#161616;padding:11px 16px;font-weight:700;text-decoration:none">Konfirmasi permintaan</a>
            </p>
            <p style="margin-top:24px;font-size:13px;color:#555">Tautan ini hanya untuk konfirmasi permintaan notifikasi dan akan kedaluwarsa. Kami tidak mengirim Recovery Key, kredensial, atau data pesanan melalui email ini.</p>
        </section>
    </main>
</body>
</html>
