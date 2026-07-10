<x-storefront.panel as="section" tone="yellow" class="mt-5" aria-labelledby="recovery-key-title">
    <h2 id="recovery-key-title" class="font-display text-xl font-black text-[var(--sf-ink)]">Simpan recovery key sekarang.</h2>
    <p class="mt-2 text-sm font-bold leading-6 text-[var(--sf-ink)]">Key ini dipakai bersama nomor invoice untuk membuka kembali data akun. Ambil screenshot atau salin ke password manager pribadi. Kami hanya menyimpan versi hash, jadi key tidak dapat ditampilkan ulang.</p>
    <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center">
        <code class="min-w-0 flex-1 break-all rounded-md border-[3px] border-[var(--sf-border)] bg-white px-3 py-3 font-mono text-sm font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)]">{{ $accessKey }}</code>
        <button type="button" class="neo-button h-11 shrink-0 bg-[var(--sf-surface)] px-4 text-sm font-black text-[var(--sf-ink)]" data-copy-text="{{ $accessKey }}">Salin key</button>
    </div>
    <p class="mt-4 text-xs font-bold leading-5 text-[var(--sf-muted)]">Jangan membagikan screenshot atau key ini. Bila key hilang, akses ulang tidak dapat diproses otomatis.</p>
</x-storefront.panel>
