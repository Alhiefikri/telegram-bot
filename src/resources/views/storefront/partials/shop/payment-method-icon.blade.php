@switch($method)
    @case('qris')
        <span aria-hidden="true" class="grid size-9 place-items-center rounded-lg bg-rose-100 text-rose-800">
            <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false">
                <rect x="2.5" y="2.5" width="6" height="6" rx="1" />
                <rect x="15.5" y="2.5" width="6" height="6" rx="1" />
                <rect x="2.5" y="15.5" width="6" height="6" rx="1" />
                <path d="M15.5 15.5h3v3h3v3h-6zM12 3v2m0 7v2m-9-2h4m10 0h4m-9 5v4" />
            </svg>
        </span>
        @break
    @case('binance_pay_id')
        <span aria-hidden="true" class="grid size-9 place-items-center rounded-lg bg-amber-100 text-amber-900">
            <svg viewBox="0 0 24 24" class="size-5" fill="currentColor" focusable="false">
                <path d="m7.34 10.09-2.71-2.7L12 0l7.38 7.38-2.7 2.71L12 5.42zM0 12l2.71-2.71L5.42 12l-2.71 2.71zm7.34 1.91L12 18.58l4.68-4.67 2.7 2.7L12 24l-7.37-7.39zM18.58 12l2.71-2.71L24 12l-2.71 2.71zM9.29 12 12 9.29 14.71 12 12 14.71z" />
            </svg>
        </span>
        @break
    @case('usdt_bep20')
        <span aria-hidden="true" class="grid size-9 place-items-center rounded-lg bg-[#232426] text-[#f0b90b]">
            <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false">
                <path d="m12 2.5 8.2 4.75v9.5L12 21.5l-8.2-4.75v-9.5zM3.8 7.25 12 12l8.2-4.75M12 12v9.5M7.9 4.88l8.2 4.75v4.12" />
            </svg>
        </span>
        @break
@endswitch
