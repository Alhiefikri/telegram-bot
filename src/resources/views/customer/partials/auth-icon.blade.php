<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="auth-icon">
    @switch($icon)
        @case('email')
            <rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 6 9 7 9-7"/>
            @break
        @case('user')
            <circle cx="12" cy="8" r="4"/><path d="M5 21v-2a7 7 0 0 1 14 0v2"/>
            @break
        @case('password')
            <circle cx="16" cy="8" r="5"/><path d="m12 12-9 9v-4l3-3h3l3-3M17 7h.01"/>
            @break
        @case('eye')
            <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>
            @break
        @case('telegram')
            <path d="m21 3-7 18-4-7-7-4 18-7ZM10 14 21 3"/>
            @break
    @endswitch
</svg>
