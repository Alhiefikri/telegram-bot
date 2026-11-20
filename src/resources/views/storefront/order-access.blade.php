<!DOCTYPE html>
<html lang="{{ $metadata->htmlLocale() }}" class="scroll-smooth bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head', ['metadata' => $metadata])
</head>
<body class="overflow-x-hidden antialiased selection:bg-[var(--sf-ink)] selection:text-white relative text-[var(--sf-ink)]">
    <div class="storefront-shell relative min-h-screen z-10 flex flex-col justify-between overflow-x-clip">
        @include('storefront.partials.header')

        <main class="flex-grow px-5 py-14 sm:px-6 sm:py-20">
            <section class="neo-panel mx-auto max-w-xl bg-[var(--sf-surface)] p-6 sm:p-8" aria-labelledby="order-access-title">
                <p class="text-sm font-black text-[var(--sf-accent)]">{{ __('storefront_funnel.order_access_title') }}</p>
                <h1 id="order-access-title" class="mt-3 font-display text-2xl font-black leading-tight text-[var(--sf-ink)] sm:text-4xl">{{ __('storefront_funnel.reopen_account') }}</h1>
                <p class="mt-3 text-sm font-semibold leading-7 text-[var(--sf-muted)]">{{ __('storefront_funnel.web_orders_only') }}</p>

                @if (session('storefront_order_access_error'))
                    <x-storefront.notice tone="danger" class="mt-5" role="alert">{{ session('storefront_order_access_error') }}</x-storefront.notice>
                @endif

                <form method="POST" action="{{ route('storefront.order-access.store') }}" class="mt-8 grid gap-5">
                    @csrf
                    <div class="grid gap-2">
                        <label for="invoice-number" class="text-sm font-black text-[var(--sf-ink)]">{{ __('storefront_funnel.invoice_number') }}</label>
                        <input id="invoice-number"
                               name="invoice_number"
                               value="{{ old('invoice_number') }}"
                               autocomplete="off"
                               required
                               @if ($errors->has('invoice_number')) aria-invalid="true" aria-describedby="invoice-number-error" @endif
                               class="neo-input h-12 w-full px-3 text-sm font-bold text-[var(--sf-ink)]"
                               placeholder="INV-12345678">
                        @error('invoice_number')<p id="invoice-number-error" class="text-sm font-bold text-[#b42336]">{{ $message }}</p>@enderror
                    </div>

                    <div class="grid gap-2">
                        <label for="access-key" class="text-sm font-black text-[var(--sf-ink)]">{{ __('storefront_funnel.recovery_key') }}</label>
                        <input id="access-key"
                               name="access_key"
                               type="password"
                               autocomplete="off"
                               required
                               @if ($errors->has('access_key')) aria-invalid="true" aria-describedby="access-key-help access-key-error" @else aria-describedby="access-key-help" @endif
                               class="neo-input h-12 w-full px-3 font-mono text-sm font-bold text-[var(--sf-ink)]"
                               placeholder="LHIE-XXXXXXXXXXXXXXXXXXXX">
                        <p id="access-key-help" class="text-sm font-semibold leading-5 text-[var(--sf-muted)]">{{ __('storefront_funnel.saved_key_only') }}</p>
                        @error('access_key')<p id="access-key-error" class="text-sm font-bold text-[#b42336]">{{ $message }}</p>@enderror
                    </div>

                    <button type="submit" class="neo-button inline-flex h-12 items-center justify-center bg-[var(--sf-accent)] px-5 text-sm font-black text-white">{{ __('storefront_funnel.open_order_details') }}</button>
                </form>
                <a href="{{ route('storefront.policies.recovery') }}" class="mt-6 block text-center text-sm font-black underline">{{ __('storefront_funnel.read_recovery_policy') }}</a>
            </section>
        </main>

        @include('storefront.partials.footer')
    </div>
</body>
</html>
