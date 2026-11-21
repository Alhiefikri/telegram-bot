<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Harga Telegram Stars · Admin LHIE STORE</title>
    @include('partials.theme-init')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="admin-page min-h-screen bg-admin-paper text-admin-ink">
<x-admin-dashboard.linked-shell active-tab="settings" active-navigation-id="stars-pricing" title="Harga Telegram Stars">
    <div class="max-w-3xl space-y-4">
        @if (session('status'))
            <x-admin-dashboard.notice tone="success" role="status">{{ session('status') }}</x-admin-dashboard.notice>
        @endif
        @if ($errors->any())
            <x-admin-dashboard.notice tone="danger" role="alert">
                <ul class="list-disc pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-admin-dashboard.notice>
        @endif

        <x-admin-dashboard.card as="section" padding="compact" shadow="sm" aria-label="Kebijakan harga Telegram Stars">
            <h2 class="text-lg font-semibold text-admin-ink">Kebijakan harga global</h2>
            <p class="mt-1 text-sm text-admin-muted">
                Versi tersimpan: {{ $policy->version }} · Izin penerbitan: {{ $policy->issuanceEnabled ? 'Aktif' : 'Nonaktif' }}.
                Izin ini hanya membuka checkout di lingkungan uji Telegram yang terisolasi. Checkout publik tetap tertutup sampai ada cutover terpisah.
            </p>
            <p class="mt-2 text-sm text-admin-muted">
                Gunakan total retail IDR, tanpa biaya metode bayar. Reward hanya acuan; bukan jaminan pendapatan. Cadangan 100 bp = 1%.
            </p>

            <form method="POST" action="{{ route('admin.stars-pricing.update') }}" class="mt-4 space-y-4">
                @csrf
                @php($version = old('version', $policy->version))
                <input type="hidden" name="version" value="{{ is_scalar($version) ? $version : '' }}">
                <div class="grid gap-3 sm:grid-cols-3">
                    @foreach ([
                        'idr_per_usd' => ['Kurs IDR per USD', $policy->idrPerUsd, '18300'],
                        'reward_micro_usd' => ['Reward (micro-USD per Star)', $policy->rewardMicroUsd, '13000'],
                        'reserve_bps' => ['Cadangan (0–9999 bp)', $policy->reserveBps, '1500'],
                    ] as $field => [$label, $saved, $example])
                        @php($value = old($field, $saved))
                        <label class="block space-y-1 text-sm font-medium" for="stars-{{ $field }}">
                            <span>{{ $label }}</span>
                            <x-admin-dashboard.input id="stars-{{ $field }}" :name="$field" :value="is_scalar($value) ? $value : null" placeholder="Contoh: {{ $example }}" inputmode="numeric" pattern="[0-9]+" required />
                        </label>
                    @endforeach
                </div>
                <input type="hidden" name="issuance_enabled" value="0">
                <label class="flex min-h-11 items-center gap-2 text-sm font-medium">
                    <input type="checkbox" name="issuance_enabled" value="1" @checked(in_array(old('issuance_enabled', $policy->issuanceEnabled), [true, 1, '1'], true)) class="h-5 w-5 rounded border-admin-line accent-admin-accent">
                    Izinkan penerbitan invoice baru jika checkout Stars sudah dirilis
                </label>

                <div class="space-y-2 border-t border-admin-line pt-4">
                    @php($totalIdr = old('total_idr'))
                    <label class="block space-y-1 text-sm font-medium" for="stars-total-idr">
                        <span>Total retail IDR untuk preview</span>
                        <x-admin-dashboard.input id="stars-total-idr" name="total_idr" :value="is_scalar($totalIdr) ? $totalIdr : null" placeholder="Contoh: 10000" inputmode="numeric" />
                    </label>
                    @if ($preview !== null)
                        <x-admin-dashboard.notice tone="neutral" role="status">
                            Preview: Rp{{ $preview['total_idr'] }} → {{ $preview['total_xtr'] }} XTR. Belum disimpan dan tidak membuat invoice.
                        </x-admin-dashboard.notice>
                    @endif
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-admin-dashboard.button type="submit" variant="neutral" formaction="{{ route('admin.stars-pricing.preview') }}">Hitung preview</x-admin-dashboard.button>
                    <x-admin-dashboard.button type="submit">Simpan kebijakan</x-admin-dashboard.button>
                </div>
            </form>
        </x-admin-dashboard.card>
    </div>
</x-admin-dashboard.linked-shell>
</body>
</html>
