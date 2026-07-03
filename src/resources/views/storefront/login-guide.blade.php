<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head')
</head>
<body class="overflow-x-hidden antialiased selection:bg-[var(--sf-ink)] selection:text-white relative text-[var(--sf-ink)] motion-reduce:animate-none">
    <div class="storefront-shell min-h-screen">
        <header class="sticky top-4 z-50 mx-auto w-[calc(100%-2rem)] max-w-5xl rounded-2xl bg-white/92 backdrop-blur-xl border border-[var(--sf-border)] shadow-sm">
            <div class="flex h-14 items-center justify-between px-6">
                <a href="{{ route('storefront.home') }}" class="flex items-center gap-2">
                    <div class="h-7 w-7 rounded-lg bg-slate-900 flex items-center justify-center text-white font-black text-sm select-none shadow-sm">L</div>
                    <span class="text-sm font-bold tracking-tight text-[var(--sf-ink)]">{{ $brand }}</span>
                </a>

                <nav class="hidden md:flex items-center gap-1.5 p-1 bg-slate-50 rounded-xl text-xs font-semibold text-slate-600 border border-slate-200">
                    <a href="{{ route('storefront.home') }}#products" class="px-4 py-1.5 rounded-lg hover:bg-white hover:text-slate-900 transition-colors">Produk</a>
                    <a href="{{ route('storefront.home') }}#cara-beli" class="px-4 py-1.5 rounded-lg hover:bg-white hover:text-slate-900 transition-colors">Cara Beli</a>
                    <a href="{{ $supportDestination }}" class="px-4 py-1.5 rounded-lg hover:bg-white hover:text-slate-900 transition-colors">Support</a>
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-5xl px-6 py-16 lg:py-24">
            <section class="grid gap-10 lg:grid-cols-[1.05fr_0.95fr] lg:items-center">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.24em] text-slate-500">Panduan setelah pembelian</p>
                    <h1 class="mt-4 text-3xl sm:text-5xl font-black tracking-tight text-slate-950 leading-tight">Cara masuk dan amankan akun digital kamu.</h1>
                    <p class="mt-5 max-w-2xl text-base text-slate-600 leading-relaxed">
                        Ikuti urutan ini setelah data akun dikirim oleh bot. Tujuannya agar buyer bisa login dengan rapi, mengambil kode verifikasi, lalu menjaga akses akun tanpa bingung bolak-balik chat support.
                    </p>
                    <div class="mt-8 flex flex-col sm:flex-row gap-3">
                        <a href="{{ $pdfUrl }}" class="inline-flex h-12 items-center justify-center rounded-xl bg-slate-900 px-6 text-sm font-bold text-white hover:bg-slate-700 transition-colors">
                            Download PDF
                        </a>
                        <a href="{{ $supportDestination }}" class="inline-flex h-12 items-center justify-center rounded-xl border border-slate-300 bg-white px-6 text-sm font-bold text-slate-900 hover:border-slate-400 transition-colors">
                            Chat Support
                        </a>
                    </div>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Ringkasan cepat</p>
                    <ol class="mt-5 space-y-4 text-sm text-slate-600">
                        <li class="flex gap-3">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-slate-900 text-xs font-bold text-white">1</span>
                            <span>Buka Outlook atau halaman login Microsoft, lalu masuk memakai email dan password yang diterima.</span>
                        </li>
                        <li class="flex gap-3">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-slate-900 text-xs font-bold text-white">2</span>
                            <span>Jika diminta kode, cek instruksi iCloud atau Google Authenticator sesuai data yang diberikan di pesan order.</span>
                        </li>
                        <li class="flex gap-3">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-slate-900 text-xs font-bold text-white">3</span>
                            <span>Simpan data akun di tempat aman. Jangan ubah email pemulihan, nomor, atau pengaturan keamanan tanpa arahan support.</span>
                        </li>
                    </ol>
                </div>
            </section>

            <section class="mt-16 border-t border-slate-200 pt-12">
                <div class="grid gap-6 md:grid-cols-3">
                    <div class="rounded-2xl border border-slate-200 bg-white p-6">
                        <h2 class="text-sm font-bold text-slate-950">Sebelum masuk</h2>
                        <p class="mt-3 text-sm leading-relaxed text-slate-600">Pastikan email, password, dan catatan verifikasi dari bot sudah tersimpan lengkap.</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-6">
                        <h2 class="text-sm font-bold text-slate-950">Saat diminta kode</h2>
                        <p class="mt-3 text-sm leading-relaxed text-slate-600">Gunakan panduan PDF untuk melihat alur Outlook, iCloud, atau Google Authenticator yang sesuai.</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-6">
                        <h2 class="text-sm font-bold text-slate-950">Kalau gagal masuk</h2>
                        <p class="mt-3 text-sm leading-relaxed text-slate-600">Jangan mencoba terlalu banyak kali. Kirim invoice dan screenshot kendala ke support agar dicek.</p>
                    </div>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
