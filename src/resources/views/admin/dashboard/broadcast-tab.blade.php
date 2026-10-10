            {{-- ╔═════════════════════════════════════════════════════╗
                 ║  BROADCAST TAB                                      ║
                 ╚═════════════════════════════════════════════════════╝ --}}
            <div id="admin-section-broadcast" data-admin-return-target tabindex="-1" class="mt-4 space-y-4">

                <div>
                    <p class="text-xs font-medium text-admin-muted">Komunikasi</p>
                    <h2 class="mt-1 text-2xl font-semibold tracking-tight text-admin-ink">Siaran</h2>
                    <p class="mt-1 text-sm leading-6 text-admin-muted">Kirim info ke pengguna aktif lewat bot</p>
                </div>

                @if ($errors->broadcast->any())
                    <x-admin-dashboard.notice tone="danger">
                        Periksa kembali pesan broadcast.
                    </x-admin-dashboard.notice>
                @endif

                <form method="POST" action="{{ route('admin.broadcast.store') }}" class="space-y-4">
                    @csrf

                    <x-admin-dashboard.notice tone="warning">
                        Jangan isi data akun, password, kode OTP, atau secret pembeli di broadcast. Data akun hanya dikirim otomatis ke pembeli setelah pembayaran lunas.
                    </x-admin-dashboard.notice>

                    <div>
                        <x-admin-dashboard.field-label for="broadcast-message">Pesan</x-admin-dashboard.field-label>
                        <x-admin-dashboard.textarea
                            id="broadcast-message"
                            name="message"
                            rows="7"
                            placeholder="Contoh: Stok Canva Pro sudah ready. Silakan cek /katalog."
                            class="mt-2"
                        >{{ old('message') }}</x-admin-dashboard.textarea>
                        @error('message', 'broadcast')
                            <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                        @enderror
                    </div>

                    <x-admin-dashboard.button type="submit" variant="primary" class="w-full">
                        Kirim Siaran
                    </x-admin-dashboard.button>
                </form>

                @php($recentBroadcasts = $broadcastTab['recent'] ?? collect())
                <x-admin-dashboard.card as="section" padding="compact" shadow="sm" aria-label="Status Siaran">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-semibold text-admin-ink">Status Siaran</h3>
                            <p class="mt-1 text-sm leading-6 text-admin-muted">Progres pengiriman terbaru. Isi pesan tidak ditampilkan di log operasional.</p>
                        </div>
                    </div>

                    <div class="mt-3 space-y-2">
                        @forelse ($recentBroadcasts as $broadcast)
                            <div class="rounded-lg border border-admin-line bg-admin-surface-muted p-3">
                                <div class="flex items-center justify-between gap-3 text-xs">
                                    <span class="font-semibold text-admin-ink">#{{ $broadcast->id }} · {{ str_replace('_', ' ', $broadcast->status) }}</span>
                                    <span class="font-medium text-admin-muted">{{ $broadcast->created_at?->format('Y-m-d H:i') }}</span>
                                </div>
                                <div class="mt-2 grid grid-cols-4 gap-2 text-center text-sm">
                                    <div class="rounded-lg border border-admin-line bg-admin-surface p-2"><span class="block text-xs font-medium text-admin-muted">Total</span><span class="font-semibold text-admin-ink">{{ $broadcast->recipient_count }}</span></div>
                                    <div class="rounded-lg border border-admin-line bg-admin-surface p-2"><span class="block text-xs font-medium text-admin-muted">Terkirim</span><span class="font-semibold text-admin-ink">{{ $broadcast->sent_count }}</span></div>
                                    <div class="rounded-lg border border-admin-line bg-admin-surface p-2"><span class="block text-xs font-medium text-admin-muted">Pending</span><span class="font-semibold text-admin-ink">{{ $broadcast->pending_count }}</span></div>
                                    <div class="rounded-lg border border-admin-line bg-admin-surface p-2"><span class="block text-xs font-medium text-admin-muted">Gagal</span><span class="font-semibold text-admin-ink">{{ $broadcast->failed_count }}</span></div>
                                </div>
                            </div>
                        @empty
                            <x-admin-dashboard.empty-state title="Belum ada siaran yang masuk antrean." />
                        @endforelse
                    </div>
                </x-admin-dashboard.card>
            </div>