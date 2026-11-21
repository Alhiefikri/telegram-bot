@props(['amount', 'signed' => false])
@if($amount === null)
    —
@else
    {{ $amount < 0 ? '−' : ($signed && $amount > 0 ? '+' : '') }}Rp{{ strrev(implode('.', str_split(strrev(ltrim((string) $amount, '-')), 3))) }}
@endif
