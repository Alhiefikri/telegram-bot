<x-filament-widgets::widget>
<div class="min-w-0 overflow-hidden rounded-[1.25rem] border border-slate-200 bg-white p-5 shadow-sm">
    <div class="flex items-center justify-between gap-4 border-b border-slate-100 pb-4">
        <div>
            <p class="text-[11px] font-bold uppercase tracking-[0.28em] text-sky-600">Support & aksi cepat</p>
            <h2 class="mt-2 font-['Space_Grotesk'] text-xl font-bold tracking-[-0.04em] text-slate-900">Bantuan Telegram dan pintasan kerja</h2>
        </div>
    </div>

    <div class="mt-5 grid min-w-0 gap-4 2xl:grid-cols-2">
        <div class="min-w-0 rounded-2xl border border-slate-200 bg-slate-50/80 p-5">
            <p class="text-sm font-semibold uppercase tracking-[0.16em] text-slate-500">Support Telegram</p>

            @if ($setting)
                <div class="mt-4 space-y-3 text-sm text-slate-600">
                    <div>
                        <div class="font-semibold text-slate-900">Mode pembayaran</div>
                        <div class="mt-1">{{ ucfirst((string) $support['payment_mode']) }}</div>
                    </div>

                    <div>
                        <div class="font-semibold text-slate-900">Admin kontak</div>
                        @if ($support['contact_url'])
                            <a href="{{ $support['contact_url'] }}" target="_blank" rel="noreferrer" class="mt-1 inline-flex text-sky-700 hover:text-sky-900">Buka chat Telegram →</a>
                        @elseif ($support['username'])
                            <div class="mt-1">{{ '@'.$support['username'] }}</div>
                        @elseif ($support['chat_id'])
                            <div class="mt-1">Chat ID: {{ $support['chat_id'] }}</div>
                        @else
                            <div class="mt-1 text-slate-500">Belum diisi</div>
                        @endif
                    </div>

                    @if ($support['instructions'])
                        <div>
                            <div class="font-semibold text-slate-900">Instruksi aktif</div>
                            <p class="mt-1 leading-6 text-slate-600">{{ $support['instructions'] }}</p>
                        </div>
                    @endif
                </div>
            @else
                <x-filament.widgets.partials.empty-state
                    class="mt-4"
                    title="Belum ada pengaturan pembayaran aktif"
                    description="Buat pengaturan pembayaran agar data support Telegram dan instruksi muncul di dashboard."
                />
            @endif
        </div>

        <div class="min-w-0 rounded-2xl border border-slate-200 bg-slate-50/80 p-5">
            <p class="text-sm font-semibold uppercase tracking-[0.16em] text-slate-500">Quick actions</p>

            <div class="mt-4 grid min-w-0 gap-3 2xl:grid-cols-2">
                @foreach ($actions as $action)
                    <a href="{{ $action['url'] }}" class="min-w-0 break-words rounded-2xl border border-sky-100 bg-white px-4 py-4 text-sm font-semibold leading-5 text-slate-900 shadow-sm transition hover:border-sky-200 hover:bg-sky-50/50 hover:text-sky-700">
                        {{ $action['label'] }}
                    </a>
                @endforeach
            </div>
        </div>
    </div>
</div>
</x-filament-widgets::widget>
