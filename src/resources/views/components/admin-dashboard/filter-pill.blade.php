@props([
    'filter',
])

<button
    type="button"
    x-on:click="salesFilter = @js($filter['key'])"
    x-bind:class="isSalesFilterActive(@js($filter['key'])) ? 'filter-pill-active' : ''"
    x-bind:aria-pressed="isSalesFilterActive(@js($filter['key']))"
    class="filter-pill"
    data-sales-filter="{{ $filter['key'] }}"
>
    <span>{{ $filter['label'] }}</span>
    <span class="filter-pill-count">{{ $filter['count'] }}</span>
</button>
