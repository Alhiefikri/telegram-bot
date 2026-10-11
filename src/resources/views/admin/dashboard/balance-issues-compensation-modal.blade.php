<div
    x-cloak
    x-show="releaseOpen"
    x-transition.opacity
    class="admin-modal-backdrop fixed inset-0 z-[70] flex items-center justify-center bg-neo-ink/60"
    role="dialog"
    aria-modal="true"
    aria-labelledby="compensation-modal-title-{{ $item['top_up_id'] }}"
    x-on:keydown.escape.window="releaseOpen = false"
    x-on:click.self="releaseOpen = false"
>
    <div class="admin-modal-shell w-full max-w-xl rounded-[var(--admin-radius)] border border-admin-line bg-admin-surface p-4 text-admin-ink shadow-admin-lg md:p-6">
        <div class="flex items-start justify-between gap-3">
            <div>
                <p class="text-xs font-medium text-admin-muted">Owner confirmation</p>
                <h3 id="compensation-modal-title-{{ $item['top_up_id'] }}" class="mt-1 text-lg font-semibold tracking-tight text-admin-ink">Release saldo</h3>
            </div>
            <button type="button" class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-admin-line bg-admin-surface text-lg text-admin-ink focus-visible:outline-2 focus-visible:outline-admin-focus" aria-label="Tutup modal" x-on:click="releaseOpen = false">×</button>
        </div>

        <x-admin-dashboard.notice tone="warning" class="mt-4 text-xs">
            Customer <strong>{{ $item['customer_name'] }}</strong> akan menerima <strong>{{ $item['amount_label'] }}</strong>, tepat dari nominal top-up #{{ $item['top_up_id'] }}. Periksa referensi provider dan alasan sebelum konfirmasi.
        </x-admin-dashboard.notice>

        <form method="POST" action="{{ route('admin.store-balance.top-ups.compensate', ['topUp' => $item['top_up_id']]) }}" class="mt-4 space-y-3">
            @csrf
            <label class="block text-xs font-medium text-admin-ink">
                Referensi provider <span class="text-neo-danger-text">*</span>
                <input type="text" name="provider_reference" value="{{ old('provider_reference', $item['provider_reference'] === '—' ? '' : $item['provider_reference']) }}" maxlength="191" required class="mt-1 min-h-11 w-full rounded-lg border border-admin-line bg-admin-surface px-3 text-sm font-normal text-admin-ink transition-colors placeholder:text-admin-muted focus:outline-none focus:ring-2 focus:ring-admin-focus focus:ring-offset-2 focus:ring-offset-admin-paper" placeholder="Reference provider / tiket internal">
            </label>
            <label class="block text-xs font-medium text-admin-ink">
                Alasan resolusi <span class="text-neo-danger-text">*</span>
                <textarea name="reason" rows="4" maxlength="500" required class="mt-1 min-h-28 w-full rounded-lg border border-admin-line bg-admin-surface px-3 py-3 text-sm font-normal text-admin-ink transition-colors placeholder:text-admin-muted focus:outline-none focus:ring-2 focus:ring-admin-focus focus:ring-offset-2 focus:ring-offset-admin-paper" placeholder="Jelaskan bukti dan alasan kompensasi.">{{ old('reason') }}</textarea>
            </label>
            <div class="flex flex-wrap justify-end gap-2 pt-2">
                <x-admin-dashboard.button type="button" variant="neutral" size="sm" x-on:click="releaseOpen = false">Batal</x-admin-dashboard.button>
                <x-admin-dashboard.button type="submit" variant="primary" size="sm">Konfirmasi release</x-admin-dashboard.button>
            </div>
        </form>
    </div>
</div>
