<footer class="border-t border-slate-200 bg-white pt-16 pb-10">
    <div class="mx-auto max-w-5xl px-6 grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
        <div class="space-y-4">
            <div class="flex items-center gap-2">
                <div class="h-7 w-7 rounded-lg bg-slate-900 flex items-center justify-center text-white font-bold text-sm select-none shadow-sm">L</div>
                <span class="text-base font-bold tracking-tight text-slate-900">Lhie<span class="text-slate-600">Store</span></span>
            </div>
            <p class="text-sm text-zinc-500 leading-relaxed">
                {{ $copy['footer']['body'] }}
            </p>
        </div>

        <div>
            <h3 class="text-xs font-bold text-zinc-900 mb-4">Produk</h3>
            <ul class="space-y-3 text-sm text-zinc-500">
                <li><a href="#products" class="hover:text-zinc-900 transition-colors">Katalog Lengkap</a></li>
                <li><a href="#cara-beli" class="hover:text-zinc-900 transition-colors">Cara Pembelian</a></li>
                <li><a href="#faq" class="hover:text-zinc-900 transition-colors">FAQ</a></li>
            </ul>
        </div>

        <div>
            <h3 class="text-xs font-bold text-zinc-900 mb-4">Toko</h3>
            <ul class="space-y-3 text-sm text-zinc-500">
                <li><a href="#" class="hover:text-slate-900 transition-colors">Tentang Kami</a></li>
                <li><a href="#" class="hover:text-slate-900 transition-colors">Kebijakan Privasi</a></li>
                <li><a href="#" class="hover:text-slate-900 transition-colors">Syarat Ketentuan</a></li>
            </ul>
        </div>

        <div>
            <h3 class="text-xs font-bold text-zinc-900 mb-4">Bantuan</h3>
            <ul class="space-y-3 text-sm text-zinc-500">
                <li><a href="{{ $supportDestination }}" class="hover:text-slate-900 transition-colors text-slate-700 font-bold">Support Telegram</a></li>
                <li><a href="https://warungai.id" class="hover:text-zinc-900 transition-colors">warungai.id</a></li>
                <li><span class="text-zinc-400">08.00 - 23.00 WIB</span></li>
            </ul>
        </div>
    </div>

    <div class="mx-auto mt-16 max-w-5xl px-6 flex flex-col md:flex-row justify-between items-center border-t border-zinc-100 pt-8 text-xs text-zinc-400">
        <p>&copy; {{ now()->year }} {{ $copy['footer']['copyright_suffix'] }}</p>
        <div class="flex gap-4 mt-4 md:mt-0 items-center">
            <span>QR Manual</span>
            <span>QRIS</span>
            <span>Pakasir</span>
        </div>
    </div>
</footer>
