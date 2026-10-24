@php($connection = $connection ?? [])
@php($currencyRates = $currencyRates ?? [])
@php($rateMap = collect($currencyRates)->flatMap(fn (array $rate): array => collect($rate['currencies'] ?? [$rate['currency']])->mapWithKeys(fn (string $currency): array => [$currency => $rate['exchange_rate_idr']])->all())->all())
@php($providerSettings = collect(\App\Enums\SupplierProvider::cases())->mapWithKeys(fn ($provider) => [$provider->value => ['currency' => $provider->defaultCurrency(), 'confirm' => $provider->requiresCurrencyConfirmation(), 'label' => $provider->label()]])->all())
@php($sample = isset($connection['sample_unit'], $connection['currency']) ? ['unit' => $connection['sample_unit'], 'currency' => $connection['currency'], 'cost_idr' => $connection['sample_cost_idr'] ?? null] : null)
@php($initialName = $connection['name'] ?? (\App\Enums\SupplierProvider::tryFrom((string) ($connection['provider'] ?? 'prodseller')) ?? \App\Enums\SupplierProvider::ProdSeller)->label())
<form method="POST" action="{{ isset($connection['id']) ? route('admin.suppliers.update', $connection['id']) : route('admin.suppliers.store') }}" class="grid gap-3 sm:grid-cols-2" x-data="supplierConnectionForm(@js($connection['provider'] ?? 'prodseller'), @js($providerSettings), @js(route('admin.password.confirm.store')), @js($sample), @js($rateMap), @js($connection['currency'] ?? null), @js($connection['name'] ?? null))" x-ref="connectionForm" x-on:submit.prevent="save()">
    @csrf
    @if(isset($connection['id'])) @method('PATCH') <input type="hidden" name="version" value="{{ $connection['version'] }}"> @endif
    <label class="grid gap-2 text-sm font-medium text-admin-ink">Pilih supplier<x-admin-dashboard.select name="provider" x-model="provider" required>@foreach(\App\Enums\SupplierProvider::cases() as $provider)<option value="{{ $provider->value }}" @selected(($connection['provider'] ?? 'prodseller') === $provider->value)>{{ $provider->label() }}</option>@endforeach</x-admin-dashboard.select></label>
    <label class="grid gap-2 text-sm font-medium text-admin-ink">Nama supplier
        <span class="flex items-start gap-2">
            <x-admin-dashboard.input name="name" value="{{ $initialName }}" x-model="name" x-ref="nameInput" required maxlength="120" class="min-w-0 flex-1" />
            <x-admin-dashboard.button type="button" variant="neutral" size="sm" x-on:click="resetName()" aria-label="Kembalikan nama supplier ke default" title="Kembalikan ke nama default supplier">Default</x-admin-dashboard.button>
        </span>
    </label>
    <p class="self-center text-sm text-admin-muted">Nama boleh diubah dan bisa dikembalikan ke default. Alamat koneksi tetap mengikuti supplier yang dipilih.</p>
    <label class="grid gap-2 text-sm font-medium text-admin-ink">API key {{ isset($connection['id']) ? '(kosong = tidak berubah)' : '' }}<x-admin-dashboard.input name="api_key" type="password" autocomplete="new-password" maxlength="2048" :required="!isset($connection['id'])" /></label>
    <p class="self-center text-sm text-admin-muted" x-show="!providers[provider].confirm">Mata uang otomatis: <strong class="font-semibold text-admin-ink" x-text="providers[provider].currency"></strong></p>
    <label class="grid gap-2 text-sm font-medium text-admin-ink" x-show="providers[provider].confirm" x-cloak>Mata uang akun supplier<x-admin-dashboard.select name="currency" x-model="accountCurrency" x-bind:disabled="!providers[provider].confirm">@foreach(['USDT', 'USD'] as $currency)<option value="{{ $currency }}" @selected(($connection['currency'] ?? 'USD') === $currency)>{{ $currency }}</option>@endforeach</x-admin-dashboard.select></label>
    <div class="sm:col-span-2 rounded-lg border border-admin-line bg-admin-surface-muted p-3 text-sm text-admin-muted">
        <p>Kurs global: 1 <strong class="font-semibold text-admin-ink" x-text="currency"></strong> = <strong class="font-semibold text-admin-ink" x-text="rateText"></strong></p>
        <p class="mt-1" x-show="sampleText" x-cloak x-text="sampleText"></p>
        <p class="mt-1">Kurs diatur sekali di kartu <strong class="font-semibold text-admin-ink">Kurs mata uang supplier</strong> di atas. USD dan USDT memakai satu kurs dolar; VND memakai kurs sendiri. Form ini tidak menyimpan kurs per koneksi.</p>
    </div>
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
