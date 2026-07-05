@props([
    'href' => null,
    'type' => 'button',
    'variant' => 'primary',
    'size' => 'md',
])

@php
    $baseClasses = 'admin-dashboard-action inline-flex items-center justify-center gap-2 rounded-neo-2xl border-[3px] border-neo-ink text-center font-black uppercase tracking-wide transition-all duration-150 hover:-translate-y-0.5 active:translate-x-[2px] active:translate-y-[2px] active:shadow-none focus:outline-none focus:ring-2 focus:ring-neo-focus focus:ring-offset-2 focus:ring-offset-neo-cream';
    $sizeClasses = match ($size) {
        'sm' => 'min-h-11 px-3 py-2 text-xs shadow-neo-sm hover:shadow-neo-md',
        'icon' => 'h-9 min-h-9 w-9 px-0 py-0 text-sm shadow-neo-xs hover:shadow-neo-sm',
        default => 'min-h-12 px-4 py-2 text-sm shadow-neo-md hover:shadow-neo-xl',
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
