@props([
    'href' => null,
    'type' => 'button',
    'variant' => 'primary',
])

@php
    $baseClasses = 'inline-flex min-h-12 items-center justify-center gap-2 rounded-[1rem] border-[3px] border-neo-ink px-4 text-center text-sm font-black uppercase tracking-wide shadow-[4px_4px_0_0_#050505] transition-all duration-150 hover:-translate-y-0.5 hover:shadow-[6px_6px_0_0_#050505] active:translate-x-[2px] active:translate-y-[2px] active:shadow-none focus:outline-none focus:ring-2 focus:ring-[#8758de] focus:ring-offset-2 focus:ring-offset-neo-cream';
    $variantClasses = match ($variant) {
        'secondary' => 'bg-[#16B4CA] text-white',
        'neutral' => 'bg-white text-neo-ink',
        'warning' => 'bg-[#f2da3d] text-neo-ink',
        'destructive' => 'bg-[#ef4444] text-white',
        default => 'bg-[#7BD10A] text-neo-ink',
    };
@endphp

@if ($href)
    <a
        href="{{ $href }}"
        {{ $attributes->class([$baseClasses, $variantClasses]) }}
    >
        {{ $slot }}
    </a>
@else
    <button
        type="{{ $type }}"
        {{ $attributes->class([$baseClasses, $variantClasses]) }}
    >
        {{ $slot }}
    </button>
@endif
