@php($connection = $connection ?? [])
@php($providerSettings = collect(\App\Enums\SupplierProvider::cases())->mapWithKeys(fn ($provider) => [$provider->value => ['currency' => $provider->defaultCurrency(), 'confirm' => $provider->requiresCurrencyConfirmation(), 'band' => \App\Services\Suppliers\SupplierExchangeRate::band($provider->defaultCurrency())]])->all())
@php($sample = isset($connection['sample_unit'], $connection['currency']) ? ['unit' => $connection['sample_unit'], 'currency' => $connection['currency']] : null)
<form method="POST" action="{{ isset($connection['id']) ? route('admin.suppliers.update', $connection['id']) : route('admin.suppliers.store') }}" class="grid gap-3 sm:grid-cols-2" x-data="supplierConnectionForm(@js($connection['provider'] ?? 'prodseller'), @js($providerSettings), @js(route('admin.password.confirm.store')), @js($sample))" x-ref="connectionForm" x-on:submit.prevent="save()">
    @csrf
    @if(isset($connection['id'])) @method('PATCH') <input type="hidden" name="version" value="{{ $connection['version'] }}"> @endif
    <label class="grid gap-2 text-sm font-medium text-admin-ink">Pilih supplier<x-admin-dashboard.select name="provider" x-model="provider" required>@foreach(\App\Enums\SupplierProvider::cases() as $provider)<option value="{{ $provider->value }}" @selected(($connection['provider'] ?? 'prodseller') === $provider->value)>{{ $provider->label() }}</option>@endforeach</x-admin-dashboard.select></label>
    <p class="self-center text-sm text-admin-muted">Nama dan alamat koneksi otomatis mengikuti supplier yang dipilih.</p>
    <label class="grid gap-2 text-sm font-medium text-admin-ink">API key {{ isset($connection['id']) ? '(kosong = tidak berubah)' : '' }}<x-admin-dashboard.input name="api_key" type="password" autocomplete="new-password" maxlength="2048" :required="!isset($connection['id'])" /></label>
    <p class="self-center text-sm text-admin-muted" x-show="!providers[provider].confirm">Mata uang otomatis: <strong class="font-semibold text-admin-ink" x-text="providers[provider].currency"></strong></p>
    <label class="grid gap-2 text-sm font-medium text-admin-ink" x-show="providers[provider].confirm" x-cloak>Mata uang akun supplier<x-admin-dashboard.select name="currency" x-on:change="clearExchangeRate()" x-bind:disabled="!providers[provider].confirm">@foreach(['USDT', 'USD'] as $currency)<option @selected(($connection['currency'] ?? 'USD') === $currency)>{{ $currency }}</option>@endforeach</x-admin-dashboard.select></label>
    <label class="grid gap-2 text-sm font-medium text-admin-ink">Nilai rupiah untuk 1 <span x-text="providers[provider].confirm ? 'unit mata uang akun' : providers[provider].currency"></span><x-admin-dashboard.input name="exchange_rate_idr" x-ref="exchangeRate" type="number" min="0.000001" step="0.000001" max="1000000" required value="{{ $connection['exchange_rate_idr'] ?? '' }}" x-on:input="refreshRateDerivation()" /><small class="text-sm text-admin-muted">Isi biaya nyata dalam rupiah untuk 1 unit mata uang supplier. Maksimal 6 angka desimal. Kurs terlalu rendah dapat membuat harga jual rugi.</small><small class="text-sm text-admin-muted" x-show="provider === 'nastele'" x-cloak>Nastele memakai VND, bukan USD/USDT. Kurs = biaya rupiah per 1 USDT ÷ jumlah VND yang diterima per 1 USDT. Contoh nyata: Rp18.000 ÷ 24.000 VND = Rp0,75 per VND (SKU 69.000 VND ≈ Rp51.750). Pakai angka kredit dari halaman deposit, bukan perkiraan; sistem menolak angka di luar rentang wajar.</small></label>
    <p class="text-sm text-admin-muted sm:col-span-2" x-show="rateDerivation" x-cloak><span x-text="rateDerivation"></span> <span x-text="sampleDerivation"></span></p>
    <x-admin-dashboard.notice tone="warning" class="sm:col-span-2" role="status" x-show="rateWarning" x-cloak x-text="rateWarning"></x-admin-dashboard.notice>
    <details class="sm:col-span-2 rounded-lg border border-admin-line bg-admin-surface-muted p-3" x-show="providers[provider].currency === 'VND'" x-cloak>
        <summary class="min-h-11 cursor-pointer text-sm font-medium text-admin-ink">Bantu isi kurs (opsional, khusus wallet VND)</summary>
        <p class="mt-2 text-sm text-admin-muted">Kalau wallet ini diisi pakai USDT, jawab dua pertanyaan ini lalu tekan tombolnya. Contoh: deposit 1 USDT dapat 24.000 VND dan 1 USDT biayanya Rp18.000 → kurs <strong class="font-semibold text-admin-ink">0,75</strong>. Bukan kurs pasar Google. Titik ribuan boleh (24.000), koma desimal juga boleh (18.000,5).</p>
        <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <label class="grid gap-1.5 text-sm font-medium text-admin-ink">1 USDT deposit dapat berapa VND?<x-admin-dashboard.input x-model="calcVndPerUsdt" inputmode="decimal" autocomplete="off" x-on:input="refreshRateCalculator()" /><span class="text-sm font-normal text-admin-muted">Lihat halaman deposit Nastele: deposit 10 USDT dapat berapa VND, lalu bagi 10.</span></label>
            <label class="grid gap-1.5 text-sm font-medium text-admin-ink">Biaya rupiah untuk 1 USDT<x-admin-dashboard.input x-model="calcIdrPerUsdt" inputmode="decimal" autocomplete="off" x-on:input="refreshRateCalculator()" /><span class="text-sm font-normal text-admin-muted">Biaya funding nyata Anda, termasuk fee.</span></label>
        </div>
        <div class="mt-3 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
            <div>
                <p class="text-sm text-admin-muted" x-show="!calcSuggestion">Isi kedua kolom di atas, lalu tekan <span class="font-medium text-admin-ink">Isi kolom kurs</span>. Saran kurs muncul di sini.</p>
                <p class="text-sm text-admin-muted" x-show="calcSuggestion" x-cloak>Kurs: <strong class="font-semibold text-admin-ink" x-text="calcSuggestion"></strong><template x-if="calcCost"><span> ⇒ <span x-text="calcSample"></span> ≈ <span x-text="calcCost"></span></span></template></p>
                <p class="mt-1 text-sm text-admin-muted" x-show="calcError" x-cloak x-text="calcError"></p>
                <p class="mt-1 text-sm text-admin-muted" x-show="calcWarning" x-cloak x-text="calcWarning"></p>
            </div>
            <x-admin-dashboard.button type="button" variant="neutral" size="sm" x-bind:disabled="calcSuggestion === ''" x-on:click="applyRateSuggestion()">Isi kolom kurs</x-admin-dashboard.button>
        </div>
        <p class="mt-2 text-sm text-admin-muted">Kalau harga SKU Nastele tidak sejalan dengan kurs deposit (contoh: SKU 69.000 VND ditagih 2,87 USDT ⇒ 1 USDT ≈ 24.042 VND), pakai angka dari harga SKU itu: itu biaya nyata Anda dan lebih aman daripada angka deposit.</p>
    </details>
    <label class="grid gap-2 text-sm font-medium text-admin-ink">Peringatan saldo rendah (micro-unit; 1 unit = 1000000)<x-admin-dashboard.input name="low_balance_threshold_micros" type="number" min="0" max="1000000000000" value="{{ $connection['low_balance_threshold_micros'] ?? 0 }}" required /></label>
    <input type="hidden" name="currency_confirmed" value="0" x-bind:disabled="!providers[provider].confirm"><label class="flex min-h-11 items-center gap-2 text-sm font-medium text-admin-ink" x-show="providers[provider].confirm" x-cloak><input name="currency_confirmed" type="checkbox" value="1" class="h-5 w-5 rounded border-admin-line accent-admin-accent" @checked($connection['currency_confirmed'] ?? false) x-bind:disabled="!providers[provider].confirm">Saya telah memverifikasi mata uang akun supplier (wajib ARRS/Warzone).</label>
    <input type="hidden" name="is_active" value="0"><x-admin-dashboard.checkbox name="is_active" value="1" :checked="(bool) ($connection['is_active'] ?? false)">Izinkan pembelian dari supplier ini setelah katalog diperbarui</x-admin-dashboard.checkbox>
    <x-admin-dashboard.field-error class="sm:col-span-2" role="alert" x-show="error" x-text="error" x-cloak></x-admin-dashboard.field-error>
    <x-admin-dashboard.button type="submit" variant="primary" class="sm:col-span-2" x-ref="saveButton" x-bind:disabled="saving || confirming"><span x-show="saving" x-cloak>Menyimpan...</span><span x-show="!saving">{{ isset($connection['id']) ? 'Simpan supplier' : 'Buat supplier' }}</span></x-admin-dashboard.button>
    <dialog x-ref="passwordDialog" class="m-auto w-[calc(100%_-_2rem)] max-w-md rounded-xl border border-admin-line bg-admin-surface p-5 text-admin-ink shadow-admin-lg backdrop:bg-black/40" aria-labelledby="supplier-password-title-{{ $connection['id'] ?? 'new' }}" aria-describedby="supplier-password-help-{{ $connection['id'] ?? 'new' }}" x-on:cancel.prevent="cancelConfirmation()">
        <h2 id="supplier-password-title-{{ $connection['id'] ?? 'new' }}" class="text-xl font-semibold text-admin-ink">Konfirmasi password</h2>
        <p id="supplier-password-help-{{ $connection['id'] ?? 'new' }}" class="mt-2 text-sm text-admin-muted">Masukkan password admin untuk menyimpan supplier. API key dan isianmu tetap ada.</p>
        <label class="mt-4 grid gap-2 text-sm font-medium text-admin-ink" for="supplier-password-{{ $connection['id'] ?? 'new' }}">Password admin
            <x-admin-dashboard.input id="supplier-password-{{ $connection['id'] ?? 'new' }}" type="password" autocomplete="current-password" x-ref="confirmationPassword" x-model="confirmationPassword" x-bind:disabled="confirming" x-on:keydown.enter.prevent="confirmPassword()" />
        </label>
        <x-admin-dashboard.field-error role="alert" x-show="confirmationError" x-text="confirmationError" x-cloak />
        <div class="mt-4 flex flex-wrap gap-3">
            <x-admin-dashboard.button type="button" variant="primary" size="sm" x-bind:disabled="confirming" x-on:click="confirmPassword()">Konfirmasi dan simpan</x-admin-dashboard.button>
            <x-admin-dashboard.button type="button" variant="neutral" size="sm" x-bind:disabled="confirming" x-on:click="cancelConfirmation()">Batal</x-admin-dashboard.button>
        </div>
    </dialog>
</form>
