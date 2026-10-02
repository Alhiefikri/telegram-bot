@php
    $connection = $connection ?? [];
    $repopulate = $repopulate ?? false;
    $fieldValue = fn (string $key, $default = '') => $repopulate ? old($key, $connection[$key] ?? $default) : ($connection[$key] ?? $default);
    $fieldChecked = fn (string $key, bool $default = false): bool => (bool) ($repopulate ? old($key, $connection[$key] ?? $default) : ($connection[$key] ?? $default));
    $fieldId = 'supplier-' . ($connection['id'] ?? 'new');
    $thresholdMicros = (int) $fieldValue('low_balance_threshold_micros', 0);
    $thresholdDecimal = $thresholdMicros > 0
        ? rtrim(rtrim(number_format($thresholdMicros / 1000000, 6, '.', ''), '0'), '.')
        : '';
@endphp
<form
    method="POST"
    action="{{ isset($connection['id']) ? route('admin.suppliers.update', $connection['id']) : route('admin.suppliers.store') }}"
    class="grid gap-3 sm:grid-cols-2"
    x-data="{ threshold: @js($thresholdDecimal) }"
>
    @csrf
    @if (isset($connection['id']))
        @method('PATCH')
        <input type="hidden" name="version" value="{{ $connection['version'] }}">
        <input type="hidden" name="context" value="suppliers">
        <input type="hidden" name="context_id" value="{{ $connection['id'] }}">
    @endif

    <div class="grid gap-1">
        <x-admin-dashboard.field-label for="{{ $fieldId }}-name">Nama supplier</x-admin-dashboard.field-label>
        <x-admin-dashboard.input id="{{ $fieldId }}-name" name="name" maxlength="120" required autocomplete="off" :value="$fieldValue('name')" />
        @error('name') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
    </div>

    <div class="grid gap-1">
        <x-admin-dashboard.field-label for="{{ $fieldId }}-provider">Provider (alamat tetap, tidak bisa diubah manual)</x-admin-dashboard.field-label>
        <x-admin-dashboard.select id="{{ $fieldId }}-provider" name="provider" required>
            @foreach (['prodseller' => 'ProdSeller', 'csxstore' => 'CSxStore', 'arrs' => 'ARRS', 'warzone' => 'Warzone', 'aivault' => 'AI Vault Hub'] as $value => $label)
                <option value="{{ $value }}" @selected($fieldValue('provider', 'prodseller') === $value)>{{ $label }}</option>
            @endforeach
        </x-admin-dashboard.select>
        @error('provider') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
    </div>

    <div class="grid gap-1">
        <x-admin-dashboard.field-label for="{{ $fieldId }}-api-key">API key {{ isset($connection['id']) ? '(kosongkan bila tidak diganti)' : '' }}</x-admin-dashboard.field-label>
        <x-admin-dashboard.input id="{{ $fieldId }}-api-key" name="api_key" type="password" autocomplete="new-password" maxlength="2048" @required(! isset($connection['id'])) />
        <p class="text-[10px] font-bold text-neo-ink/60">Disimpan terenkripsi dan tidak pernah ditampilkan kembali.</p>
        @error('api_key') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
    </div>

    <div class="grid gap-1">
        <x-admin-dashboard.field-label for="{{ $fieldId }}-currency">Mata uang akun supplier</x-admin-dashboard.field-label>
        <x-admin-dashboard.select id="{{ $fieldId }}-currency" name="currency">
            @foreach (['USDT', 'USD'] as $currency)
                <option value="{{ $currency }}" @selected($fieldValue('currency', 'USDT') === $currency)>{{ $currency }}</option>
            @endforeach
        </x-admin-dashboard.select>
        @error('currency') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
    </div>

    <div class="grid gap-1">
        <x-admin-dashboard.field-label for="{{ $fieldId }}-rate">Kurs: 1 unit mata uang = berapa Rupiah?</x-admin-dashboard.field-label>
        <x-admin-dashboard.input id="{{ $fieldId }}-rate" name="exchange_rate_idr" type="number" min="1" max="1000000" required inputmode="numeric" :value="$fieldValue('exchange_rate_idr', 16000)" />
        <p class="text-[10px] font-bold text-neo-ink/60">Contoh: 16000. Dipakai untuk menghitung modal dalam Rupiah.</p>
        @error('exchange_rate_idr') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
    </div>

    <div class="grid gap-1">
        <x-admin-dashboard.field-label for="{{ $fieldId }}-threshold">Ingatkan saya bila saldo tersisa di bawah</x-admin-dashboard.field-label>
        <div class="flex items-center gap-2">
            <x-admin-dashboard.input
                id="{{ $fieldId }}-threshold"
                class="min-w-0"
                type="number"
                min="0"
                step="0.000001"
                inputmode="decimal"
                placeholder="0"
                x-model.number="threshold"
            />
            <span class="shrink-0 text-sm font-black" x-text="@js($fieldValue('currency', 'USDT'))">{{ $fieldValue('currency', 'USDT') }}</span>
        </div>
        <input type="hidden" name="low_balance_threshold_micros" x-bind:value="Math.max(0, Math.round((Number(threshold) || 0) * 1000000))" value="{{ $thresholdMicros }}">
        @error('low_balance_threshold_micros') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
    </div>

    <div class="grid gap-2 sm:col-span-2">
        <input type="hidden" name="currency_confirmed" value="0">
        <label class="flex items-start gap-3 rounded-neo-2xl border-[3px] border-neo-ink bg-white px-4 py-3 text-sm font-bold text-neo-ink shadow-neo-sm">
            <input type="checkbox" name="currency_confirmed" value="1" @checked($fieldChecked('currency_confirmed')) class="mt-0.5 h-5 w-5 rounded-none border-neo-ink text-neo-focus focus:ring-2 focus:ring-neo-focus focus:ring-offset-2 focus:ring-offset-neo-cream">
            <span>Saya sudah memastikan mata uang akun supplier ini. Wajib untuk ARRS/Warzone.</span>
        </label>

        <input type="hidden" name="is_active" value="0">
        <label class="flex items-start gap-3 rounded-neo-2xl border-[3px] border-neo-ink bg-white px-4 py-3 text-sm font-bold text-neo-ink shadow-neo-sm">
            <input type="checkbox" name="is_active" value="1" @checked($fieldChecked('is_active')) class="mt-0.5 h-5 w-5 rounded-none border-neo-ink text-neo-focus focus:ring-2 focus:ring-neo-focus focus:ring-offset-2 focus:ring-offset-neo-cream">
            <span>Izinkan pembelian dari supplier ini setelah sinkron berhasil.</span>
        </label>
        @error('currency_confirmed') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
        @error('is_active') <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error> @enderror
    </div>

    <x-admin-dashboard.button type="submit" variant="primary" class="sm:col-span-2">
        {{ isset($connection['id']) ? 'Simpan koneksi' : 'Buat supplier' }}
    </x-admin-dashboard.button>
</form>
