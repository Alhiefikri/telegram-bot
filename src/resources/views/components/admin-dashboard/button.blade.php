@props([
    'href' => null,
    'type' => 'button',
    'variant' => 'primary',
])

@php
    $baseClasses = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-lg px-4 text-sm font-semibold transition focus:outline-none focus:ring-2 focus:ring-offset-2';
    $variantClasses = match ($variant) {
        'secondary' => 'border border-brand-electric/20 bg-brand-electricLight text-brand-electric hover:border-brand-electric/30 hover:bg-blue-100 focus:ring-brand-electric/30',
        'neutral' => 'border border-slate-200 bg-white text-brand-navy hover:border-slate-300 hover:bg-slate-50 focus:ring-slate-300',
        'warning' => 'border border-amber-200 bg-amber-50 text-amber-800 hover:bg-amber-100 focus:ring-amber-300',
        'destructive' => 'border border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100 focus:ring-rose-300',
        default => 'bg-brand-electric text-white shadow-sm shadow-blue-950/10 hover:bg-brand-electricDark focus:ring-brand-electric',
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
