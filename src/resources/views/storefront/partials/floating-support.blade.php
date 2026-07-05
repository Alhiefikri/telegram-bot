@if ($supportHref)
    <a href="{{ $supportHref }}" data-telegram-link data-icon="telegram" class="group fixed bottom-3 right-3 z-40 hidden h-11 w-11 items-center justify-center rounded-full border-[3px] border-[var(--sf-border)] bg-[var(--sf-accent)] text-white shadow-[4px_4px_0_var(--sf-shadow)] transition-transform duration-300 hover:-translate-x-1 hover:-translate-y-1 active:translate-x-0 active:translate-y-0 sm:bottom-6 sm:right-6 sm:flex sm:h-14 sm:w-14 sm:shadow-[6px_6px_0_var(--sf-shadow)]" aria-label="Chat Telegram">
        @include('storefront.components.telegram-icon', ['class' => 'relative z-10 h-6 w-6 transition-transform duration-300 group-hover:-rotate-6 sm:h-7 sm:w-7'])
    </a>
@endif
