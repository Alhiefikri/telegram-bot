@props([
    'tabId',
    'activeTab' => 'activeTab',
])

<button
    type="button"
    x-bind:class="{{ $activeTab }} === @js($tabId) ? 'bg-[#f2da3d] text-neo-ink shadow-[4px_4px_0_0_#050505]' : 'bg-white text-neo-ink hover:bg-neo-yellow/30'"
    x-on:click="{{ $activeTab }} = @js($tabId)"
    {{ $attributes->class([
        'min-h-11 min-w-24 rounded-full border-[3px] border-neo-ink px-3 py-2 text-xs font-black uppercase tracking-wider transition-all duration-150 active:translate-x-[2px] active:translate-y-[2px] active:shadow-none sm:min-w-28',
    ]) }}
>
    {{ $slot }}
</button>
