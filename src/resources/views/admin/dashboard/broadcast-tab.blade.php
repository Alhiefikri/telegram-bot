            {{-- ╔═════════════════════════════════════════════════════╗
                 ║  BROADCAST TAB (hidden from bottom nav)             ║
                 ╚═════════════════════════════════════════════════════╝ --}}
            <div x-cloak x-show="activeTab === 'broadcast'" class="mt-4 space-y-4">

                <div>
                    <h2 class="font-display text-lg font-black uppercase tracking-[0.18em] text-neo-ink">📢 Siaran</h2>
                    <p class="mt-1 text-xs font-semibold text-brand-muted">Kirim info ke pengguna aktif lewat bot</p>
                </div>

                @if ($errors->broadcast->any())
                    <x-admin-dashboard.notice tone="danger" class="animate-slide-up">
                        Periksa kembali pesan broadcast.
                    </x-admin-dashboard.notice>
                @endif

                <form method="POST" action="{{ route('admin.broadcast.store') }}" class="space-y-4">
                    @csrf

                    <x-admin-dashboard.notice tone="warning" class="rounded-xl p-4">
                        <p class="text-xs font-bold text-neo-ink">
                            ⚠️ Jangan isi data akun, password, kode OTP, atau secret pembeli di broadcast. Data akun hanya dikirim otomatis ke pembeli setelah pembayaran lunas.
                        </p>
                    </x-admin-dashboard.notice>

                    <div>
                        <x-admin-dashboard.field-label for="broadcast-message" class="text-xs">Pesan</x-admin-dashboard.field-label>
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

                    <x-admin-dashboard.button type="submit" variant="purple" class="w-full text-sm">
                        📤 Kirim Siaran
                    </x-admin-dashboard.button>
                </form>
            </div>
