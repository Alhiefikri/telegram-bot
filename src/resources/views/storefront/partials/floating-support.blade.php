@if ($supportHref)
    <a href="{{ $supportHref }}" class="group fixed bottom-3 right-3 z-40 hidden h-11 w-11 items-center justify-center rounded-full border-[3px] border-[var(--sf-border)] bg-[var(--sf-accent)] text-white shadow-[4px_4px_0_var(--sf-shadow)] transition-transform duration-300 hover:-translate-x-1 hover:-translate-y-1 active:translate-x-0 active:translate-y-0 sm:bottom-6 sm:right-6 sm:flex sm:h-14 sm:w-14 sm:shadow-[6px_6px_0_var(--sf-shadow)]" aria-label="Chat Telegram">
        <svg class="relative z-10 h-6 w-6 transition-transform duration-300 group-hover:-rotate-6 sm:h-7 sm:w-7" fill="currentColor" viewBox="0 0 24 24">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69.01-.03.01-.14-.07-.2-.08-.06-.19-.04-.27-.02-.11.02-1.93 1.23-5.46 3.62-.51.35-.98.53-1.39.51-.46-.01-1.35-.26-2.01-.48-.81-.27-1.46-.42-1.4-.88.03-.24.37-.49 1.03-.75 4.04-1.76 6.74-2.92 8.09-3.48 3.85-1.6 4.64-1.88 5.17-1.89.11 0 .37.03.54.17.14.12.18.28.2.45-.02.07-.02.16-.02.26z"/>
        </svg>
    </a>
@endif
