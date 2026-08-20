<x-storefront.panel as="section" class="mt-5" aria-label="Pengiriman data akun">
    @if (! $isPaid)
        @if ($isTerminal)
            <h2 class="font-display text-2xl font-black text-[var(--sf-ink)]">Data akun tidak dikirim dari invoice ini</h2>
            <p class="mt-3 text-sm font-bold leading-6 text-[var(--sf-muted)]">
                Invoice ini sudah tidak aktif. Buat order baru jika masih ingin membeli produk ini.
            </p>
        @else
            <h2 class="font-display text-2xl font-black text-[var(--sf-ink)]">Data akun aman sampai pembayaran masuk</h2>
            <p class="mt-3 text-sm font-bold leading-6 text-[var(--sf-muted)]">
                Setelah pembayaran terverifikasi, data akun muncul di halaman ini. Simpan link invoice ini sampai akun diterima.
            </p>
        @endif
    @elseif ($accountGroups !== [] && $canRevealCredentials)
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <p class="text-xs font-black uppercase tracking-[0.2em] text-[var(--sf-accent)]">Tindakan utama</p>
                <h2 class="mt-2 font-display text-2xl font-black text-[var(--sf-ink)]">Data akun siap digunakan</h2>
                <p class="mt-3 text-sm font-bold leading-6 text-[var(--sf-muted)]">Salin kredensial dan ikuti panduan produk jika tersedia, lalu amankan akun dari perangkat pribadi.</p>
            </div>
            <div class="shrink-0" data-copy-group>
                <button type="button"
                        class="neo-button inline-flex min-h-12 w-full items-center justify-center bg-[var(--sf-accent)] px-5 text-sm font-black text-white sm:w-auto"
                        data-copy-text="{{ $accountCopyText }}"
                        data-copy-success="Semua data akun tersalin.">
                    Salin semua data
                </button>
                <p class="mt-2 min-h-5 text-xs font-black text-[var(--sf-success)]" aria-live="polite" data-copy-status></p>
            </div>
        </div>
        <div class="mt-5 space-y-4">
            @foreach ($accountGroups as $group)
                <section class="rounded-lg border-[3px] border-[var(--sf-border)] bg-white p-4 shadow-[5px_5px_0_var(--sf-shadow)]" aria-label="Data akun {{ $group['product_name'] }}">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <h3 class="font-display text-lg font-black text-[var(--sf-ink)]">{{ $group['product_name'] }}</h3>
                        <div data-copy-group>
                            <button type="button"
                                    class="inline-flex min-h-10 w-full items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] px-4 text-xs font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)] sm:w-auto"
                                    data-copy-text="{{ $group['copy_text'] }}"
                                    data-copy-success="Data {{ $group['product_name'] }} tersalin.">
                                Salin produk ini
                            </button>
                            <p class="mt-1 min-h-5 text-xs font-black text-[var(--sf-success)]" aria-live="polite" data-copy-status></p>
                        </div>
                    </div>
                    <div class="mt-3 space-y-3">
                        @foreach ($group['secrets'] as $secret)
                            @if (($secret['structured'] ?? false) === true)
                                <article class="rounded-md border-[3px] border-[var(--sf-border)] bg-[var(--sf-ink)] p-3 text-white shadow-[3px_3px_0_var(--sf-shadow)]" aria-label="{{ $secret['label'] }}">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <p class="font-display text-sm font-black">{{ $secret['label'] }}</p>
                                            <p class="mt-1 text-[11px] font-bold text-white/70">Akun terstruktur · salin setiap nilai sesuai kebutuhan.</p>
                                        </div>
                                        <div class="shrink-0" data-copy-group>
                                            <button type="button"
                                                    class="inline-flex min-h-9 w-full items-center justify-center rounded-md border-2 border-white bg-white px-3 text-xs font-black text-[var(--sf-ink)] sm:w-auto"
                                                    data-copy-text="{{ $secret['text'] }}"
                                                    data-copy-success="{{ $secret['label'] }} tersalin.">
                                                <span data-copy-feedback>Salin akun</span>
                                            </button>
                                            <p class="mt-1 min-h-5 text-right text-xs font-black text-[var(--sf-green)]" aria-live="polite" data-copy-status></p>
                                        </div>
                                    </div>

                                    <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                                        @foreach ([['key' => 'email', 'label' => 'Email'], ['key' => 'password', 'label' => 'Password']] as $field)
                                            <div class="rounded-md border-2 border-white/30 p-2" data-copy-group>
                                                <dt class="text-[10px] font-black uppercase tracking-[0.14em] text-white/70">{{ $field['label'] }}</dt>
                                                <button type="button"
                                                        class="mt-1 block min-h-14 w-full rounded-md border-2 border-white/30 bg-white/10 px-2 py-2 text-left transition hover:bg-white/20 focus:outline-none focus:ring-2 focus:ring-white"
                                                        data-copy-text="{{ $secret[$field['key']] }}"
                                                        data-copy-success="{{ $field['label'] }} tersalin."
                                                        aria-label="Salin {{ $field['label'] }}">
                                                    <span class="block min-w-0 break-all font-mono text-xs font-bold">{{ $secret[$field['key']] }}</span>
                                                    <span class="mt-2 block text-[10px] font-black uppercase tracking-[0.14em] text-white/70" data-copy-feedback>Salin</span>
                                                </button>
                                                <p class="mt-1 min-h-5 text-xs font-black text-[var(--sf-green)]" aria-live="polite" data-copy-status></p>
                                            </div>
                                        @endforeach

                                        @if (($secret['access_method'] ?? null) === 'auth_token' && filled($secret['access_value'] ?? null))
                                            <div class="rounded-md border-2 border-white/30 p-2 sm:col-span-2" data-copy-group>
                                                <dt class="text-[10px] font-black uppercase tracking-[0.14em] text-white/70">Auth Token Outlook/Hotmail</dt>
                                                <button type="button"
                                                        class="mt-1 block min-h-14 w-full rounded-md border-2 border-white/30 bg-white/10 px-2 py-2 text-left transition hover:bg-white/20 focus:outline-none focus:ring-2 focus:ring-white"
                                                        data-copy-text="{{ $secret['access_value'] }}"
                                                        data-copy-success="Auth Token tersalin."
                                                        aria-label="Salin Auth Token Outlook/Hotmail">
                                                    <span class="block min-w-0 break-all font-mono text-xs font-bold">{{ $secret['access_value'] }}</span>
                                                    <span class="mt-2 block text-[10px] font-black uppercase tracking-[0.14em] text-white/70" data-copy-feedback>Salin</span>
                                                </button>
                                                <p class="mt-1 min-h-5 text-xs font-black text-[var(--sf-green)]" aria-live="polite" data-copy-status></p>
                                            </div>
                                        @endif

                                        @if (($secret['access_method'] ?? null) === 'otp_mail_link' && filled($secret['access_value'] ?? null) && filled($secret['access_domain'] ?? null))
                                            <div class="rounded-md border-2 border-white/30 p-2 sm:col-span-2">
                                                <dt class="text-[10px] font-black uppercase tracking-[0.14em] text-white/70">OTP Mail</dt>
                                                <dd class="mt-1 break-all font-mono text-xs font-bold">Domain tujuan: {{ $secret['access_domain'] }}</dd>
                                                <a href="{{ $secret['access_value'] }}"
                                                   target="_blank"
                                                   rel="noreferrer noopener"
                                                   referrerpolicy="no-referrer"
                                                   class="mt-3 inline-flex min-h-10 w-full items-center justify-center rounded-md border-2 border-white bg-white px-3 text-xs font-black text-[var(--sf-ink)] transition hover:bg-[var(--sf-yellow)] focus:outline-none focus:ring-2 focus:ring-white sm:w-auto">
                                                    Buka OTP Mail
                                                </a>
                                            </div>
                                        @endif
                                    </dl>

                                    @if (filled($secret['tutorial'] ?? null) && ($secret['tutorial'] ?? null) !== $group['delivery_note'])
                                        <div class="mt-4 rounded-md border-[3px] border-white/30 bg-white/10 p-3 text-sm font-bold leading-6">
                                            <p class="font-display text-xs font-black uppercase tracking-[0.14em]">Instruksi akun</p>
                                            <p class="mt-2 whitespace-pre-line">{{ $secret['tutorial'] }}</p>
                                        </div>
                                    @endif
                                </article>
                            @else
                                <article class="rounded-md border-[3px] border-[var(--sf-border)] bg-[var(--sf-ink)] p-3 text-white shadow-[3px_3px_0_var(--sf-shadow)]" data-copy-group>
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <p class="font-display text-sm font-black">{{ $secret['label'] }}</p>
                                        <button type="button"
                                                class="inline-flex min-h-9 w-full items-center justify-center rounded-md border-2 border-white bg-white px-3 text-xs font-black text-[var(--sf-ink)] sm:w-auto"
                                                data-copy-text="{{ $secret['text'] }}"
                                                data-copy-success="{{ $secret['label'] }} tersalin.">
                                            Salin data
                                        </button>
                                    </div>
                                    <pre class="mt-3 overflow-x-auto whitespace-pre-wrap font-mono text-sm font-bold leading-6">{{ $secret['text'] }}</pre>
                                    @if ($secret['parts'] !== null)
                                        <dl class="mt-4 grid gap-2 sm:grid-cols-3">
                                            @foreach (['email' => 'Email', 'password' => 'Password', 'mfa' => 'MFA Secret'] as $key => $label)
                                                <div class="rounded-md border-2 border-white/30 p-2">
                                                    <dt class="text-[10px] font-black uppercase tracking-[0.14em] text-white/70">{{ $label }}</dt>
                                                    <dd class="mt-1 min-w-0 break-all font-mono text-xs font-bold">{{ $secret['parts'][$key] }}</dd>
                                                    <button type="button"
                                                            class="mt-2 inline-flex min-h-8 w-full items-center justify-center rounded-md bg-white px-2 text-[11px] font-black text-[var(--sf-ink)]"
                                                            data-copy-text="{{ $secret['parts'][$key] }}"
                                                            data-copy-success="{{ $label }} tersalin.">
                                                        Salin
                                                    </button>
                                                </div>
                                            @endforeach
                                        </dl>
                                    @endif
                                    <p class="mt-2 min-h-5 text-xs font-black text-[var(--sf-green)]" aria-live="polite" data-copy-status></p>
                                </article>
                            @endif
                        @endforeach
                    </div>
                    @if (filled($group['delivery_note']))
                        <div class="mt-4 rounded-md border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] p-3 text-sm font-bold leading-6 text-[var(--sf-ink)]">
                            <p class="font-display text-xs font-black uppercase tracking-[0.14em]">Instruksi produk</p>
                            <p class="mt-2 whitespace-pre-line">{{ $group['delivery_note'] }}</p>
                        </div>
                    @else
                        <div class="mt-4 rounded-md border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-3 text-sm font-bold leading-6 text-[var(--sf-ink)]">
                            <p class="font-display text-xs font-black uppercase tracking-[0.14em]">Instruksi penggunaan aman</p>
                            <p class="mt-2">{{ data_get($copy, 'fulfillment.automatic_default_instruction') }}</p>
                        </div>
                    @endif
                    @if ($group['usage_guide'] !== null)
                        <a href="{{ $group['usage_guide']['url'] }}" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-accent)] px-4 py-3 text-center text-sm font-black text-white shadow-[4px_4px_0_var(--sf-shadow)] transition hover:-translate-y-0.5 hover:shadow-[6px_6px_0_var(--sf-shadow)] sm:w-auto">
                            {{ $group['usage_guide']['title'] }}
                        </a>
                    @endif
                </section>
            @endforeach
        </div>
        <p class="mt-4 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] p-3 text-sm font-black leading-6 text-[var(--sf-ink)] shadow-[4px_4px_0_var(--sf-shadow)]">
            Simpan data ini di password manager pribadi. Jangan kirim Recovery Key, password, MFA Secret, atau kode MFA ke support.
        </p>
    @elseif ($accountGroups !== [])
        <h2 class="font-display text-2xl font-black text-[var(--sf-ink)]">Data akun siap dibuka</h2>
        <p class="mt-3 text-sm font-bold leading-6 text-[var(--sf-muted)]">Untuk melindungi data akun, buka kembali menggunakan nomor invoice dan recovery key yang Anda simpan saat checkout.</p>
        <a href="{{ $orderAccessUrl }}" class="neo-button mt-5 inline-flex h-12 items-center justify-center bg-[var(--sf-accent)] px-5 text-sm font-black text-white">Akses data pesanan</a>
    @elseif ($paidDeliveryType === 'credentials_unavailable')
        <p class="text-xs font-black uppercase tracking-[0.2em] text-[var(--sf-accent)]">Kredensial otomatis</p>
        <h2 class="mt-2 font-display text-2xl font-black text-[var(--sf-ink)]">{{ data_get($copy, 'fulfillment.credentials_unavailable.title') }}</h2>
        <p class="mt-3 text-sm font-bold leading-6 text-[var(--sf-muted)]">
            {{ data_get($copy, 'fulfillment.credentials_unavailable.body') }}
        </p>
    @else
        @php
            $manualFulfillmentComplete = $manualFulfillmentStatus === 'completed';
        @endphp
        <p class="text-xs font-black uppercase tracking-[0.2em] text-[var(--sf-accent)]">Fulfillment manual</p>
        <h2 class="mt-2 font-display text-2xl font-black text-[var(--sf-ink)]">
            {{ $manualFulfillmentComplete ? data_get($copy, 'fulfillment.manual.completed_title') : data_get($copy, 'fulfillment.manual.processing_title') }}
        </h2>
        <div class="mt-4 grid gap-3 sm:grid-cols-3">
            <div class="rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] p-3">
                <p class="font-display text-sm font-black text-[var(--sf-ink)]">Pembayaran diterima</p>
                <p class="mt-1 text-xs font-bold leading-5 text-[var(--sf-muted)]">{{ data_get($copy, 'fulfillment.manual.received_body') }}</p>
            </div>
            <div class="rounded-lg border-[3px] border-[var(--sf-border)] {{ $manualFulfillmentComplete ? 'bg-white' : 'bg-[var(--sf-surface-warm)]' }} p-3">
                <p class="font-display text-sm font-black text-[var(--sf-ink)]">Menunggu diproses</p>
                <p class="mt-1 text-xs font-bold leading-5 text-[var(--sf-muted)]">{{ $manualFulfillmentComplete ? data_get($copy, 'fulfillment.manual.processing_done_body') : data_get($copy, 'fulfillment.manual.processing_body') }}</p>
            </div>
            <div class="rounded-lg border-[3px] border-[var(--sf-border)] {{ $manualFulfillmentComplete ? 'bg-[var(--sf-green)]' : 'bg-white' }} p-3">
                <p class="font-display text-sm font-black text-[var(--sf-ink)]">Selesai</p>
                <p class="mt-1 text-xs font-bold leading-5 text-[var(--sf-muted)]">{{ $manualFulfillmentComplete ? data_get($copy, 'fulfillment.manual.completed_body') : data_get($copy, 'fulfillment.manual.completed_waiting_body') }}</p>
            </div>
        </div>
        <p class="mt-4 text-sm font-bold leading-6 text-[var(--sf-muted)]">
            {{ data_get($copy, 'fulfillment.manual.next_step') }}
        </p>
    @endif

    @if ($isPaid && $accountGroups === [] && $usageGuides !== [])
        <div class="mt-5 rounded-lg border-[3px] border-[var(--sf-border)] bg-white p-4 shadow-[4px_4px_0_var(--sf-shadow)]">
            <h3 class="font-display text-lg font-black text-[var(--sf-ink)]">Panduan produk</h3>
            <p class="mt-2 text-sm font-bold leading-6 text-[var(--sf-muted)]">Ikuti panduan yang sesuai dengan produk pada pesanan ini.</p>
            <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                @foreach ($usageGuides as $guide)
                    <a href="{{ $guide['url'] }}" class="inline-flex min-h-11 items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-accent)] px-4 py-3 text-center text-sm font-black text-white shadow-[4px_4px_0_var(--sf-shadow)] transition hover:-translate-y-0.5 hover:shadow-[6px_6px_0_var(--sf-shadow)]">
                        {{ $guide['title'] }}
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    @if ($isPaid)
        <div class="mt-5 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-4">
            <h3 class="font-display text-lg font-black text-[var(--sf-ink)]">Simpan pesanan ke akun</h3>
            <p class="mt-2 text-sm font-bold leading-6 text-[var(--sf-muted)]">Opsional. Kredensial tetap tampil di halaman ini, dan invoice plus Recovery Key tetap bisa dipakai bila Anda melewati akun.</p>
            <a href="{{ auth('customer')->check() ? route('customer.claim.create') : route('customer.account') }}" class="neo-button mt-4 inline-flex h-12 w-full items-center justify-center bg-[var(--sf-accent)] px-5 text-sm font-black text-white sm:w-auto">Simpan pesanan ke akun</a>
        </div>
    @endif
</x-storefront.panel>
