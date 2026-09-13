<div
    x-cloak
    x-show="releaseOpen"
    x-transition.opacity
    class="fixed inset-0 z-[70] flex items-center justify-center bg-neo-ink/70 p-3"
    role="dialog"
    aria-modal="true"
    aria-labelledby="compensation-modal-title-{{ $item['top_up_id'] }}"
    x-on:keydown.escape.window="releaseOpen = false"
    x-on:click.self="releaseOpen = false"
>
    <div class="w-full max-w-xl rounded-[1.25rem] border-[4px] border-neo-ink bg-neo-paper p-4 text-neo-ink shadow-neo-xl md:p-6">
        <div class="flex items-start justify-between gap-3">
            <div>
                <p class="font-display text-[10px] font-black uppercase tracking-[0.18em] text-brand-muted">Owner confirmation</p>
                <h3 id="compensation-modal-title-{{ $item['top_up_id'] }}" class="mt-1 font-display text-xl font-black uppercase tracking-tight">Release saldo</h3>
            </div>
            <button type="button" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-[0.45rem] border-[3px] border-neo-ink bg-white text-lg font-black shadow-neo-sm" aria-label="Tutup modal" x-on:click="releaseOpen = false">×</button>
        </div>

        <x-admin-dashboard.notice tone="warning" class="mt-4 text-xs">
            Customer <strong>{{ $item['customer_name'] }}</strong> akan menerima <strong>{{ $item['amount_label'] }}</strong>, tepat dari nominal top-up #{{ $item['top_up_id'] }}. Periksa referensi provider dan alasan sebelum konfirmasi.
        </x-admin-dashboard.notice>

        <form method="POST" action="{{ route('admin.store-balance.top-ups.compensate', ['topUp' => $item['top_up_id']]) }}" class="mt-4 space-y-3">
            @csrf
            <label class="block text-xs font-black uppercase tracking-wide text-brand-muted">
                Referensi provider <span class="text-neo-danger-text">*</span>
                <input type="text" name="provider_reference" value="{{ old('provider_reference', $item['provider_reference'] === '—' ? '' : $item['provider_reference']) }}" maxlength="191" required class="mt-1 w-full rounded-xl border-[3px] border-neo-ink bg-white px-3 py-2 font-bold text-neo-ink" placeholder="Reference provider / tiket internal">
            </label>
            <label class="block text-xs font-black uppercase tracking-wide text-brand-muted">
                Alasan resolusi <span class="text-neo-danger-text">*</span>
                <textarea name="reason" rows="4" maxlength="500" required class="mt-1 w-full rounded-xl border-[3px] border-neo-ink bg-white px-3 py-2 font-semibold text-neo-ink" placeholder="Jelaskan bukti dan alasan kompensasi.">{{ old('reason') }}</textarea>
            </label>
            <div class="flex flex-wrap justify-end gap-2 pt-2">
                <button type="button" class="inline-flex min-h-11 items-center justify-center rounded-neo-2xl border-[3px] border-neo-ink bg-white px-3 py-2 text-xs font-black uppercase tracking-wide shadow-neo-sm" x-on:click="releaseOpen = false">Batal</button>
                <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-neo-2xl border-[3px] border-neo-ink bg-neo-yellow px-3 py-2 text-xs font-black uppercase tracking-wide shadow-neo-sm">Konfirmasi release</button>
            </div>
        </form>
    </div>
</div>
