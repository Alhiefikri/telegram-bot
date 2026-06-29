@props(['label', 'tone' => 'slate'])

<span {{ $attributes->class(['lhie-status-pill'])->merge(['data-tone' => $tone]) }}>
    {{ $label }}
</span>
