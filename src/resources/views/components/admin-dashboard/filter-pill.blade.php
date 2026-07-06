@props([
    'filter',
])

<a
    href="{{ $filter['url'] }}"
    class="filter-pill @if ($filter['active'] ?? false) filter-pill-active @endif"
    @if ($filter['active'] ?? false) aria-current="page" @endif
    data-sales-filter="{{ $filter['key'] }}"
>
    <span>{{ $filter['label'] }}</span>
    <span class="filter-pill-count">{{ $filter['count'] }}</span>
</a>
