@props([
    'name',
    'value' => '1',
    'checked' => false,
])

<label {{ $attributes->class(['flex items-center gap-3 rounded-neo-2xl border-[3px] border-neo-ink bg-neo-purple-soft px-4 py-3 text-sm font-bold text-neo-ink shadow-neo-sm']) }}>
    <input
        type="checkbox"
        name="{{ $name }}"
        value="{{ $value }}"
        @checked($checked)
        class="h-5 w-5 rounded-none border-neo-ink text-neo-focus focus:ring-2 focus:ring-neo-focus focus:ring-offset-2 focus:ring-offset-neo-cream"
    >
    <span>{{ $slot }}</span>
</label>
