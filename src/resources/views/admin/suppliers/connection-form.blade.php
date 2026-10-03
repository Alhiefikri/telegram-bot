@php($connection = $connection ?? [])
@php($providerSettings = collect(\App\Enums\SupplierProvider::cases())->mapWithKeys(fn ($provider) => [$provider->value => ['currency' => $provider->defaultCurrency(), 'confirm' => $provider->requiresCurrencyConfirmation()]])->all())
<form method="POST" action="{{ isset($connection['id']) ? route('admin.suppliers.update', $connection['id']) : route('admin.suppliers.store') }}" class="grid gap-3 sm:grid-cols-2" x-data="supplierConnectionForm(@js($connection['provider'] ?? 'prodseller'), @js($providerSettings), @js(route('admin.password.confirm.store')))" x-ref="connectionForm" x-on:submit.prevent="save()">
    @csrf
    @if(isset($connection['id'])) @method('PATCH') <input type="hidden" name="version" value="{{ $connection['version'] }}"> @endif
    <label class="grid gap-1">Pilih supplier <select class="rounded border p-2" name="provider" x-model="provider" required>@foreach(\App\Enums\SupplierProvider::cases() as $provider)<option value="{{ $provider->value }}" @selected(($connection['provider'] ?? 'prodseller') === $provider->value)>{{ $provider->label() }}</option>@endforeach</select></label>
    <p class="self-center text-sm">Nama dan alamat koneksi otomatis mengikuti supplier yang dipilih.</p>
    <label class="grid gap-1">API key {{ isset($connection['id']) ? '(kosong = tidak berubah)' : '' }} <input class="rounded border p-2" name="api_key" type="password" autocomplete="new-password" maxlength="2048" @required(!isset($connection['id']))></label>
    <p class="self-center text-sm" x-show="!providers[provider].confirm">Mata uang otomatis: <strong x-text="providers[provider].currency"></strong></p>
    <label class="grid gap-1" x-show="providers[provider].confirm" x-cloak>Mata uang akun supplier <select class="rounded border p-2" name="currency" x-bind:disabled="!providers[provider].confirm">@foreach(['USDT', 'USD'] as $currency)<option @selected(($connection['currency'] ?? 'USD') === $currency)>{{ $currency }}</option>@endforeach</select></label>
    <label class="grid gap-1" x-show="provider !== 'buymail'">Nilai rupiah untuk 1 <span x-text="providers[provider].confirm ? 'unit mata uang akun' : providers[provider].currency"></span><input class="rounded border p-2" name="exchange_rate_idr" type="number" min="1" max="1000000" required value="{{ $connection['exchange_rate_idr'] ?? 16000 }}" x-bind:disabled="provider === 'buymail'"><small class="text-sm">Contoh: isi 18000 jika biaya mengisi saldo 1 USD/USDT adalah Rp18.000.</small></label>
    <p class="text-sm sm:col-span-2" x-show="provider === 'buymail'" x-cloak>BuyMail memakai IDR; kurs otomatis 1.</p>
    <label class="grid gap-1 sm:col-span-2" x-show="provider === 'buymail'" x-cloak>Kunci verifikasi notifikasi BuyMail (signing secret; kosong = tetap memakai kunci lama) <input class="rounded border p-2" name="webhook_secret" type="password" autocomplete="new-password" maxlength="256" x-bind:disabled="provider !== 'buymail'"></label>
    <label class="grid gap-1">Peringatan saldo rendah (micro-unit; 1 unit = 1000000) <input class="rounded border p-2" name="low_balance_threshold_micros" type="number" min="0" max="1000000000000" value="{{ $connection['low_balance_threshold_micros'] ?? 0 }}" required></label>
    <input type="hidden" name="currency_confirmed" value="0" x-bind:disabled="!providers[provider].confirm"><label x-show="providers[provider].confirm" x-cloak><input name="currency_confirmed" type="checkbox" value="1" @checked($connection['currency_confirmed'] ?? false) x-bind:disabled="!providers[provider].confirm">Saya telah memverifikasi mata uang akun supplier (wajib ARRS/Warzone).</label>
    <input type="hidden" name="is_active" value="0"><label><input name="is_active" type="checkbox" value="1" @checked($connection['is_active'] ?? false)> Izinkan pembelian dari supplier ini setelah katalog diperbarui</label>
    <p class="text-sm text-red-700 sm:col-span-2" role="alert" x-show="error" x-text="error" x-cloak></p>
    <button class="rounded border-2 bg-white px-4 py-2 font-bold sm:col-span-2" type="submit" x-ref="saveButton" x-bind:disabled="saving || confirming"><span x-show="saving" x-cloak>Menyimpan...</span><span x-show="!saving">{{ isset($connection['id']) ? 'Simpan supplier' : 'Buat supplier' }}</span></button>
    <dialog x-ref="passwordDialog" class="m-auto w-[calc(100%_-_2rem)] max-w-md rounded-2xl border-2 bg-white p-5 text-neo-ink shadow-xl backdrop:bg-black/40" aria-labelledby="supplier-password-title-{{ $connection['id'] ?? 'new' }}" aria-describedby="supplier-password-help-{{ $connection['id'] ?? 'new' }}" x-on:cancel.prevent="cancelConfirmation()">
        <h2 id="supplier-password-title-{{ $connection['id'] ?? 'new' }}" class="text-xl font-bold">Konfirmasi password</h2>
        <p id="supplier-password-help-{{ $connection['id'] ?? 'new' }}" class="mt-2 text-sm">Masukkan password admin untuk menyimpan supplier. API key dan isianmu tetap ada.</p>
        <label class="mt-4 grid gap-1" for="supplier-password-{{ $connection['id'] ?? 'new' }}">Password admin
            <input id="supplier-password-{{ $connection['id'] ?? 'new' }}" type="password" autocomplete="current-password" class="rounded border p-2" x-ref="confirmationPassword" x-model="confirmationPassword" x-bind:disabled="confirming" x-on:keydown.enter.prevent="confirmPassword()">
        </label>
        <p class="mt-2 text-sm text-red-700" role="alert" x-show="confirmationError" x-text="confirmationError" x-cloak></p>
        <div class="mt-4 flex flex-wrap gap-3">
            <button type="button" class="rounded border-2 px-3 py-2 font-bold" x-bind:disabled="confirming" x-on:click="confirmPassword()">Konfirmasi dan simpan</button>
            <button type="button" class="rounded border px-3 py-2" x-bind:disabled="confirming" x-on:click="cancelConfirmation()">Batal</button>
        </div>
    </dialog>
</form>
