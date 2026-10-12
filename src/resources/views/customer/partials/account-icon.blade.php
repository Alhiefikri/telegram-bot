<svg class="account-icon {{ $iconClass ?? '' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($icon)
        @case('user')
            <circle cx="12" cy="8" r="4" />
            <path d="M4 21v-2a8 8 0 0 1 16 0v2" />
            @break
        @case('orders')
            <path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Z" />
            <path d="M9 7h6M9 11h6M9 15h3" />
            @break
        @case('cart')
            <path d="M3 3h2l3 12h11l2-8H6M8 15l-1 3h12" />
            <circle cx="9" cy="21" r="1" /><circle cx="18" cy="21" r="1" />
            @break
        @case('claim')
            <rect x="3" y="5" width="18" height="14" rx="2" />
            <path d="M7 10h4M7 14h3m4-1 2 2 3-4" />
            @break
        @case('balance')
            <path d="M20 8V5H5a2 2 0 0 0 0 4h15v11H5a2 2 0 0 1-2-2V7" />
            <path d="M20 12h-5v4h5" />
            @break
        @case('security')
            <path d="m12 3 8 3v6c0 4-3 7-8 9-5-2-8-5-8-9V6l8-3Z" />
            <path d="m8 12 3 3 5-6" />
            @break
        @case('guide')
            <path d="M4 5a2 2 0 0 1 2-2h14v16H6a2 2 0 0 0 0 4h14M4 5v16M8 7h8M8 11h6" />
            @break
        @case('support')
            <path d="M4 14v-3a8 8 0 0 1 16 0v3M4 12H2v6h4v-6H4Zm16 0h2v6h-4v-6h2Zm0 6v1a3 3 0 0 1-3 3h-3" />
            @break
        @case('logout')
            <path d="M9 4H4v16h5m5-12 4 4-4 4m-5-4h13" />
            @break
    @endswitch
</svg>
