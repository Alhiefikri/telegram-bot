<x-storefront.panel as="section" class="invoice-delivery-panel mt-5" aria-label="Pengiriman data akun">
    @if ($isPaid && $canRevealCredentials && $accountGroups !== [])
        @php
            $mailCopy = data_get($copy, 'credential_email', []);
            $mailStatus = $credentialMail['status'] ?? 'pending';
            $mailTitle = match ($mailStatus) {
                'sent' => data_get($mailCopy, 'sent_title'),
                'failed' => data_get($mailCopy, 'failed_title'),
                default => data_get($mailCopy, 'pending_title'),
            };
            $mailBody = match ($mailStatus) {
                'sent' => data_get($mailCopy, 'sent_body'),
                'failed' => data_get($mailCopy, 'failed_body'),
                default => data_get($mailCopy, 'pending_body'),
            };
        @endphp
        <div class="mb-4 border-b border-[var(--sf-border)] pb-4 text-sm leading-6 text-[var(--sf-ink)]" role="status" aria-live="polite">
            @if (($credentialMail['recipient'] ?? null) !== null)
                <p class="font-semibold">{{ $mailTitle }}</p>
                <p class="mt-1">{{ str_replace(':email', $credentialMail['recipient'], (string) $mailBody) }}</p>
                @if ($mailStatus === 'sent' && filled($credentialMail['sent_at'] ?? null))
                    <p class="mt-1 text-xs text-[var(--sf-muted)]">{{ str_replace(':time', $credentialMail['sent_at'], (string) data_get($mailCopy, 'sent_at')) }}</p>
                @endif
                @if ($credentialMail['can_resend'] ?? false)
                    <form method="POST" action="{{ $credentialMail['resend_url'] }}" class="mt-2">
                        @csrf
                        <button type="submit" class="credential-action">{{ data_get($mailCopy, 'resend_cta') }}</button>
                    </form>
                @endif
                @error('credential_mail')
                    <p role="alert" class="mt-2 font-semibold">{{ $message }}</p>
                @enderror
            @else
                <p class="font-semibold">{{ data_get($mailCopy, 'no_recipient_title') }}</p>
                <p class="mt-1">{{ data_get($mailCopy, 'no_recipient_body') }}</p>
            @endif
        </div>
    @endif

    @if (! $isPaid)
        @if ($isTerminal)
            <h2 class="font-display text-xl font-bold text-[var(--sf-ink)]">Data akun tidak dikirim dari invoice ini</h2>
            <p class="mt-2 text-sm leading-6 text-[var(--sf-muted)]">Invoice ini sudah tidak aktif. Buat order baru jika masih ingin membeli produk ini.</p>
        @else
            <h2 class="font-display text-xl font-bold text-[var(--sf-ink)]">Menunggu pembayaran</h2>
            <p class="mt-2 text-sm leading-6 text-[var(--sf-muted)]">Data akun hanya dapat dibuka setelah pembayaran terverifikasi dan pengiriman selesai. Produk manual diproses oleh admin.</p>
        @endif
    @elseif ($accountGroups !== [] && $canRevealCredentials)
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h2 class="font-display text-xl font-bold text-[var(--sf-ink)]">Data akun</h2>
                <p class="mt-2 text-sm leading-6 text-[var(--sf-muted)]">Password dan data rahasia disembunyikan. Pilih Tampilkan untuk melihat atau Salin untuk menyalin nilai lengkap.</p>
                <noscript><p class="mt-2 text-sm text-[var(--sf-ink)]">Buka tiap data dengan Tampilkan / Sembunyikan, lalu pilih teks untuk menyalinnya. Aktifkan JavaScript untuk menggunakan tombol Salin.</p></noscript>
            </div>
            <div class="shrink-0" data-copy-group>
                <button type="button" class="credential-action w-full sm:w-auto" data-credential-copy="{{ base64_encode((string) $accountCopyText) }}" data-copy-success="Semua data akun tersalin." hidden>Salin semua data</button>
                <p class="mt-1 min-h-5 text-xs text-[var(--sf-ink)]" aria-live="polite" data-copy-status></p>
            </div>
        </div>
        <div class="mt-4 space-y-5">
            @foreach ($accountGroups as $group)
                @php($groupIndex = $loop->index)
                <section class="min-w-0" aria-label="Data akun {{ $group['product_name'] }}">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <h3 class="font-display text-lg font-bold text-[var(--sf-ink)]">{{ $group['product_name'] }}</h3>
                        <div data-copy-group>
                            <button type="button" class="credential-action" data-credential-copy="{{ base64_encode((string) $group['copy_text']) }}" data-copy-success="Data {{ $group['product_name'] }} tersalin." hidden>Salin produk ini</button>
                            <p class="mt-1 min-h-5 text-xs text-[var(--sf-ink)]" aria-live="polite" data-copy-status></p>
                        </div>
                    </div>
                    @foreach ($group['secrets'] as $secret)
                        @include('storefront.partials.shop.credential-account', ['credentialId' => 'credential-'.$groupIndex.'-'.$loop->index])
                    @endforeach
                    <div class="text-sm leading-6 text-[var(--sf-ink)]">
                        <p class="font-semibold">{{ filled($group['delivery_note']) ? 'Instruksi produk' : 'Instruksi penggunaan aman' }}</p>
                        <p class="mt-1 whitespace-pre-line break-words">{{ filled($group['delivery_note']) ? $group['delivery_note'] : data_get($copy, 'fulfillment.automatic_default_instruction') }}</p>
                    </div>
                    @if ($group['usage_guide'] !== null)
                        <a href="{{ $group['usage_guide']['url'] }}" class="credential-action mt-3">{{ $group['usage_guide']['title'] }}</a>
                    @endif
                </section>
            @endforeach
        </div>
        <p class="mt-4 border-t border-[var(--sf-border)] pt-4 text-sm leading-6 text-[var(--sf-ink)]">Simpan data ini di password manager pribadi. Jangan kirim Recovery Key, password, MFA Secret, atau kode MFA ke support.</p>
    @elseif ($accountGroups !== [])
        <h2 class="font-display text-xl font-bold text-[var(--sf-ink)]">Data akun siap dibuka</h2>
        <p class="mt-2 text-sm leading-6 text-[var(--sf-muted)]">Buka kembali menggunakan nomor invoice dan Recovery Key yang Anda simpan saat checkout.</p>
        <a href="{{ $orderAccessUrl }}" class="credential-action mt-3">Akses data pesanan</a>
    @elseif ($paidDeliveryType === 'credentials_unavailable')
        <h2 class="font-display text-xl font-bold text-[var(--sf-ink)]">{{ data_get($copy, 'fulfillment.credentials_unavailable.title') }}</h2>
        <p class="mt-2 text-sm leading-6 text-[var(--sf-muted)]">{{ data_get($copy, 'fulfillment.credentials_unavailable.body') }}</p>
    @else
        <h2 class="font-display text-xl font-bold text-[var(--sf-ink)]">Pengiriman manual</h2>
        <dl class="mt-3 text-sm leading-6 text-[var(--sf-ink)]">
            <div class="flex flex-wrap gap-x-2">
                <dt class="font-semibold">Status:</dt>
                <dd>{{ $manualFulfillmentStatus === 'completed' ? 'Selesai diproses' : 'Menunggu pemrosesan admin' }}</dd>
            </div>
        </dl>
        <p class="mt-2 text-sm leading-6 text-[var(--sf-muted)]">
            {{ $manualFulfillmentStatus === 'completed' ? 'Pesanan ditandai selesai. Tidak ada data akun yang tersimpan di invoice ini. Jika hasil pesanan belum diterima, hubungi support dengan nomor invoice.' : 'Pembayaran diterima. Admin perlu memproses produk ini secara manual. Hubungi support dengan nomor invoice untuk informasi pengiriman.' }}
        </p>
    @endif

    @if ($isPaid && $accountGroups === [] && $usageGuides !== [])
        <div class="mt-4 border-t border-[var(--sf-border)] pt-4">
            <h3 class="font-display text-base font-bold text-[var(--sf-ink)]">Panduan produk</h3>
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach ($usageGuides as $guide)
                    <a href="{{ $guide['url'] }}" class="credential-action">{{ $guide['title'] }}</a>
                @endforeach
            </div>
        </div>
    @endif

    @if ($isPaid && ! auth('customer')->check())
        <div class="mt-4 border-t border-[var(--sf-border)] pt-4 text-sm leading-6 text-[var(--sf-muted)]">
            <p>Opsional: simpan pesanan ke akun. Akses dengan invoice dan Recovery Key tetap tersedia.</p>
            <a href="{{ route('customer.account') }}" class="credential-action mt-2">Simpan pesanan ke akun</a>
        </div>
    @elseif ($isPaid && ! request()->routeIs('customer.orders.show'))
        <a href="{{ route('customer.claim.create') }}" class="credential-action mt-4">Simpan pesanan ke akun</a>
    @endif
</x-storefront.panel>
