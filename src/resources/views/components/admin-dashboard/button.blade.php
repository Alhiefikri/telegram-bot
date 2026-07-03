@props([
    'href' => null,
    'type' => 'button',
    'variant' => 'primary',
    'size' => 'md',
])

@php
    $baseClasses = 'admin-dashboard-action inline-flex items-center justify-center gap-2 rounded-[1rem] border-[3px] border-neo-ink text-center font-black uppercase tracking-wide transition-all duration-150 hover:-translate-y-0.5 active:translate-x-[2px] active:translate-y-[2px] active:shadow-none focus:outline-none focus:ring-2 focus:ring-[#8758de] focus:ring-offset-2 focus:ring-offset-neo-cream';
    $sizeClasses = match ($size) {
        'sm' => 'min-h-11 px-3 py-2 text-xs shadow-[3px_3px_0_0_#050505] hover:shadow-[4px_4px_0_0_#050505]',
        'icon' => 'h-9 min-h-9 w-9 px-0 py-0 text-sm shadow-[2px_2px_0_0_#050505] hover:shadow-[3px_3px_0_0_#050505]',
        default => 'min-h-12 px-4 py-2 text-sm shadow-[4px_4px_0_0_#050505] hover:shadow-[6px_6px_0_0_#050505]',
    };
    $variantClasses = match ($variant) {
        'secondary' => 'bg-[#16B4CA] text-white',
        'cyan' => 'bg-[#16B4CA] text-white',
        'neutral' => 'bg-white text-neo-ink',
        'warning' => 'bg-[#f2da3d] text-neo-ink',
        'destructive' => 'bg-[#ef4444] text-white',
        'rose' => 'bg-[#ef4444] text-white',
        'pink' => 'bg-[#db3f98] text-white',
        'purple' => 'bg-[#8758de] text-white',
        'ink' => 'bg-neo-ink text-white',
        default => 'bg-[#7BD10A] text-neo-ink',
    };
@endphp

@if ($href)
    <a
        href="{{ $href }}"
        {{ $attributes->class([$baseClasses, $sizeClasses, $variantClasses]) }}
    >
        {{ $slot }}
    </a>
@else
    <button
        type="{{ $type }}"
        {{ $attributes->class([$baseClasses, $sizeClasses, $variantClasses]) }}
    >
        {{ $slot }}
    </button>
@endif
