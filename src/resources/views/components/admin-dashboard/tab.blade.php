@props([
    'tabId',
    'activeTab' => 'activeTab',
])

<button
    type="button"
    x-bind:class="{{ $activeTab }} === @js($tabId) ? 'bg-brand-navy text-white' : 'text-slate-600 hover:bg-slate-100 hover:text-brand-navy'"
    x-on:click="{{ $activeTab }} = @js($tabId)"
    {{ $attributes->class([
        'min-h-11 rounded-md px-3 py-2 text-sm font-bold transition',
    ]) }}
>
    {{ $slot }}
</button>
