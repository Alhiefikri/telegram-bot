<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Data akun pesanan {{ $invoiceNumber }}</title>
</head>
<body style="margin:0;background:#f4f1e8;color:#161616;font-family:Arial,sans-serif;line-height:1.6">
    <main style="max-width:620px;margin:0 auto;padding:32px 20px">
        <section style="background:#fff;border:2px solid #161616;padding:24px;box-shadow:5px 5px 0 #161616">
            <p style="margin:0 0 8px;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase">{{ config('app.name') }}</p>
            <h1 style="margin:0 0 16px;font-size:25px;line-height:1.2">Data akun pesanan {{ $invoiceNumber }}</h1>
            <p>Pembayaran pesanan ini sudah terverifikasi. Data akun untuk pesanan Anda ada di email ini.</p>
            <p style="margin:0 0 8px"><strong>Simpan email ini di tempat aman.</strong> Siapa pun yang membuka email ini dapat melihat data akun.</p>

            @foreach ($groups as $group)
                <div style="margin-top:24px;border:2px solid #161616;padding:16px">
                    <h2 style="margin:0 0 12px;font-size:18px;line-height:1.3">{{ $group['product_name'] }}</h2>

                    @foreach ($group['accounts'] as $account)
                        <div style="margin-top:12px;border:2px solid #161616;background:#161616;color:#fff;padding:12px">
                            <p style="margin:0 0 8px;font-size:13px;font-weight:700">{{ $account['label'] }}</p>

                            @if ($account['kind'] === 'legacy')
                                <pre style="margin:0;white-space:pre-wrap;word-break:break-all;font-family:Consolas,Menlo,monospace;font-size:13px">{{ $account['text'] }}</pre>
                                @if ($account['parts'] !== null)
                                    <p style="margin:12px 0 0;font-size:13px">
                                        Email: <span style="font-family:Consolas,Menlo,monospace">{{ $account['parts']['email'] }}</span><br>
                                        Password: <span style="font-family:Consolas,Menlo,monospace">{{ $account['parts']['password'] }}</span>
                                        @if (filled($account['parts']['mfa'] ?? null))
                                            <br>MFA Secret: <span style="font-family:Consolas,Menlo,monospace">{{ $account['parts']['mfa'] }}</span>
                                        @endif
                                    </p>
                                @endif
                            @else
                                <p style="margin:0;font-size:13px">
                                    Email: <span style="font-family:Consolas,Menlo,monospace;word-break:break-all">{{ $account['email'] }}</span><br>
                                    Password: <span style="font-family:Consolas,Menlo,monospace;word-break:break-all">{{ $account['password'] }}</span>
                                </p>

                                @if ($account['access'] !== null)
                                    <p style="margin:12px 0 0;font-size:13px">
                                        {{ $account['access']['label'] }}:
                                        @if (filled($account['access']['value'] ?? null))
                                            <span style="font-family:Consolas,Menlo,monospace;word-break:break-all">{{ $account['access']['value'] }}</span>
                                        @endif
                                        @if (filled($account['access']['domain'] ?? null))
                                            <br>Domain tujuan: <span style="font-family:Consolas,Menlo,monospace">{{ $account['access']['domain'] }}</span>
                                        @endif
                                    </p>
                                    @if (filled($account['access']['url'] ?? null))
                                        <p style="margin:12px 0 0">
                                            <a href="{{ $account['access']['url'] }}" rel="noreferrer noopener" referrerpolicy="no-referrer" style="display:inline-block;background:#fff;border:2px solid #fff;color:#161616;padding:9px 14px;font-weight:700;text-decoration:none">Buka {{ $account['access']['label'] }}</a>
                                        </p>
                                    @endif
                                @endif
                            @endif

                            @if (filled($account['tutorial'] ?? null))
                                <p style="margin:12px 0 0;font-size:13px;white-space:pre-line">{{ $account['tutorial'] }}</p>
                            @endif
                        </div>
                    @endforeach

                    @if (filled($group['delivery_note'] ?? null))
                        <p style="margin:16px 0 0;background:#f3d34a;border:2px solid #161616;padding:12px;font-size:13px;font-weight:700;white-space:pre-line">{{ $group['delivery_note'] }}</p>
                    @endif

                    @if (filled($group['usage_guide']['url'] ?? null))
                        <p style="margin:12px 0 0">
                            <a href="{{ $group['usage_guide']['url'] }}" style="display:inline-block;background:#f3d34a;border:2px solid #161616;color:#161616;padding:11px 16px;font-weight:700;text-decoration:none">{{ $group['usage_guide']['title'] }}</a>
                        </p>
                    @endif
                </div>
            @endforeach

            @if ($groups !== [] && filled($defaultInstruction))
                <p style="margin:24px 0 0;background:#f4f1e8;border:2px solid #161616;padding:12px;font-size:13px">Instruksi penggunaan aman: {{ $defaultInstruction }}</p>
            @endif

            @if ($usageGuides !== [])
                <h2 style="margin:24px 0 8px;font-size:17px">Panduan produk</h2>
                <ul style="margin:0;padding-left:20px;font-size:13px">
                    @foreach ($usageGuides as $guide)
                        <li><a href="{{ $guide['url'] }}" rel="noreferrer noopener" referrerpolicy="no-referrer">{{ $guide['title'] }}</a></li>
                    @endforeach
                </ul>
            @endif

            <p style="margin-top:24px">
                <a href="{{ $invoiceUrl }}" style="display:inline-block;background:#f3d34a;border:2px solid #161616;color:#161616;padding:11px 16px;font-weight:700;text-decoration:none">Buka halaman invoice</a>
            </p>
            <p><a href="{{ $orderAccessUrl }}">Akses pesanan dengan nomor invoice dan Recovery Key</a></p>

            <p style="margin-top:24px;font-size:13px;color:#555">
                Jangan bagikan data akun, email ini, atau Recovery Key kepada siapa pun, termasuk pihak yang mengaku support. Ganti password akun setelah login pertama bila produk mendukungnya. Link halaman invoice pada email ini kedaluwarsa; akses ulang tetap bisa memakai nomor invoice dan Recovery Key yang Anda simpan saat checkout.
            </p>
        </section>
    </main>
</body>
</html>
