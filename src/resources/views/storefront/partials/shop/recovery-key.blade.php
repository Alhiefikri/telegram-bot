<x-storefront.panel as="section" tone="yellow" class="mt-5" aria-labelledby="recovery-key-title">
    <h2 id="recovery-key-title" class="text-balance font-display text-xl font-black leading-tight text-[var(--sf-ink)]">Simpan Recovery Key pesanan website ini.</h2>
    <p class="mt-3 text-sm font-bold leading-6 text-[var(--sf-ink)] sm:text-base sm:leading-7">Recovery Key hanya untuk pesanan yang dibeli melalui website. Gunakan bersama nomor invoice untuk membuka kembali data akun; pesanan via Telegram tidak memerlukannya. Salin ke password manager atau catatan pribadi yang aman. Screenshot hanya sebagai cadangan pribadi. Kami hanya menyimpan versi hash, jadi key tidak dapat ditampilkan ulang dan support tidak dapat membuka kredensial dari nomor invoice saja.</p>
    <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center" data-copy-group>
        <code class="min-w-0 flex-1 break-all rounded-md border-[3px] border-[var(--sf-border)] bg-white px-3 py-3 font-mono text-sm font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)]">{{ $accessKey }}</code>
        <button type="button" class="neo-button h-11 shrink-0 bg-[var(--sf-surface)] px-4 text-sm font-black text-[var(--sf-ink)]" data-copy-text="{{ $accessKey }}" data-copy-success="Recovery Key tersalin.">Salin key</button>
        <span class="sr-only" aria-live="polite" data-copy-status></span>
    </div>
    <p class="mt-4 text-sm font-bold leading-6 text-[var(--sf-muted)]">Jangan membagikan screenshot atau key ini. Bila key hilang, akses ulang tidak dapat diproses otomatis.</p>
</x-storefront.panel>
