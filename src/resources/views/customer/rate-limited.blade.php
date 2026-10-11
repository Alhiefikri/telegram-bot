<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Batas permintaan akun</title>
    @vite(['resources/css/storefront.css', 'resources/js/storefront-entry.js'])
</head>
<body class="customer-auth bg-[var(--sf-paper)] text-[var(--sf-ink)]">
    <main class="mx-auto max-w-xl px-5 py-12">
        <div class="auth-panel rounded-2xl border border-[var(--sf-border)] bg-[var(--sf-surface)] p-6">
            <h1 class="text-3xl font-black">Batas permintaan tercapai</h1>
            <p role="alert" class="mt-4 leading-7">Permintaan akun sementara dibatasi. Tunggu {{ $retryAfter }} detik sebelum mencoba kembali. Jangan meminta kode atau link berulang kali.</p>
            <a class="auth-link mt-5" href="{{ route('customer.account') }}">Kembali ke akun pelanggan</a>
            <a class="auth-link" href="{{ route('storefront.order-access.create') }}">Akses dengan invoice + Recovery Key</a>
        </div>
    </main>
</body>
</html>
