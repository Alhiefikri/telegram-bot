@php($connection = $connection ?? [])
<form method="POST" action="{{ isset($connection['id']) ? route('admin.suppliers.update', $connection['id']) : route('admin.suppliers.store') }}" class="grid gap-3 sm:grid-cols-2">
    @csrf
    @if(isset($connection['id'])) @method('PATCH') <input type="hidden" name="version" value="{{ $connection['version'] }}"> @endif
    <label class="grid gap-1">Nama supplier <input class="rounded border p-2" name="name" maxlength="120" required value="{{ $connection['name'] ?? '' }}"></label>
    <label class="grid gap-1">Provider (URL tetap) <select class="rounded border p-2" name="provider" required>@foreach(\App\Enums\SupplierProvider::cases() as $provider)<option value="{{ $provider->value }}" @selected(($connection['provider'] ?? 'prodseller') === $provider->value)>{{ $provider->label() }}</option>@endforeach</select></label>
    <label class="grid gap-1">API key {{ isset($connection['id']) ? '(kosong = tidak berubah)' : '' }} <input class="rounded border p-2" name="api_key" type="password" autocomplete="new-password" maxlength="2048" @required(!isset($connection['id']))></label>
    <label class="grid gap-1">Mata uang <select class="rounded border p-2" name="currency">@foreach(['USDT', 'USD', 'IDR'] as $currency)<option @selected(($connection['currency'] ?? 'USDT') === $currency)>{{ $currency }}</option>@endforeach</select></label>
    <label class="grid gap-1">IDR per 1 unit mata uang <input class="rounded border p-2" name="exchange_rate_idr" type="number" min="1" max="1000000" required value="{{ $connection['exchange_rate_idr'] ?? 16000 }}"></label>
    <p class="text-sm sm:col-span-2">BuyMail: pilih IDR dan kurs 1. API key disimpan terenkripsi di Dashboard; perubahan tidak memerlukan rebuild atau ENV.</p>
    <label class="grid gap-1 sm:col-span-2">Signing secret webhook BuyMail (kosong = tidak berubah) <input class="rounded border p-2" name="webhook_secret" type="password" autocomplete="new-password" maxlength="256"></label>
    <label class="grid gap-1">Peringatan saldo rendah (micro-unit; 1 unit = 1000000) <input class="rounded border p-2" name="low_balance_threshold_micros" type="number" min="0" max="1000000000000" value="{{ $connection['low_balance_threshold_micros'] ?? 0 }}" required></label>
    <input type="hidden" name="currency_confirmed" value="0"><label><input name="currency_confirmed" type="checkbox" value="1" @checked($connection['currency_confirmed'] ?? false)> Saya telah memverifikasi mata uang akun supplier (wajib ARRS/Warzone).</label>
    <input type="hidden" name="is_active" value="0"><label><input name="is_active" type="checkbox" value="1" @checked($connection['is_active'] ?? false)> Aktif untuk checkout baru setelah sinkronisasi</label>
    <button class="rounded border-2 bg-white px-4 py-2 font-bold sm:col-span-2" type="submit">{{ isset($connection['id']) ? 'Simpan supplier' : 'Buat supplier' }}</button>
</form>
