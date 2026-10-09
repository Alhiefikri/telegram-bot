@props([
    'name',
    'value' => '1',
    'checked' => false,
])

<label {{ $attributes->class(['flex min-h-11 items-center gap-3 rounded-lg border border-admin-line bg-admin-surface px-4 py-3 text-sm font-medium text-admin-ink']) }}>
    <input
        type="checkbox"
        name="{{ $name }}"
        value="{{ $value }}"
        @checked($checked)
        class="h-5 w-5 rounded border-admin-line accent-admin-accent focus:ring-2 focus:ring-admin-focus focus:ring-offset-2 focus:ring-offset-admin-paper"
    >
    <span>{{ $slot }}</span>
</label>
