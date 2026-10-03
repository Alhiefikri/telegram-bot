<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('order.warranty.title', [], $locale) }} · Lhie Store</title>
    @vite('resources/css/app.css')
</head>
<body class="bg-slate-50 text-slate-900">
    <main class="mx-auto grid max-w-3xl gap-5 px-4 py-8">
        <header class="grid gap-3">
            <a href="{{ route('storefront.home') }}" class="font-bold">Lhie Store</a>
            <h1 class="text-2xl font-bold">{{ __('order.warranty.title', [], $locale) }}</h1>
            <p class="break-all font-mono">{{ __('order.warranty.invoice', ['number' => $order->invoice_number], $locale) }}</p>
            <nav class="flex gap-3" aria-label="{{ __('order.warranty.language', [], $locale) }}">
                <a href="{{ route('warranty.show', ['order' => $order->id, 'locale' => 'id']) }}" lang="id">{{ __('order.warranty.language_id', [], $locale) }}</a>
                <a href="{{ route('warranty.show', ['order' => $order->id, 'locale' => 'en']) }}" lang="en">{{ __('order.warranty.language_en', [], $locale) }}</a>
            </nav>
        </header>
        @if(session('status'))<p role="status" class="rounded border border-green-700 bg-green-50 p-4">{{ session('status') }}</p>@endif
        @if($errors->has('claim'))<p role="alert" class="rounded border border-red-700 bg-red-50 p-4">{{ $errors->first('claim') }}</p>@endif
        <section class="grid gap-3 rounded-xl border bg-white p-4">
            <p>{{ __('order.warranty.proof', [], $locale) }}</p>
            <p>{{ __('order.warranty.eligibility', [], $locale) }}</p>
            <p>{{ __('order.warranty.support', [], $locale) }}</p>
            @if($supportUrl)<a class="font-bold underline" href="{{ $supportUrl }}" rel="noreferrer">{{ __('order.warranty.support_link', [], $locale) }}</a>@endif
            @if($order->order_status->value === 'processing')<p>{{ __('order.warranty.partial', [], $locale) }}</p>@endif
        </section>
        @foreach($missingFulfillments as $missing)
            <section class="grid gap-3 rounded-xl border bg-white p-4">
                <h2 class="font-bold">{{ $missing->orderItem->product_name }}</h2>
                <p>{{ __('order.warranty.missing_review', [], $locale) }}</p>
                <form method="POST" action="{{ route('warranty.refund-missing', $order) }}">
                    @csrf<input type="hidden" name="order_item_id" value="{{ $missing->order_item_id }}">
                    <button class="rounded border px-4 py-3 font-bold">{{ __('order.warranty.refund_missing', [], $locale) }}</button>
                </form>
            </section>
        @endforeach
        @foreach($missingRefunds as $missingRefund)
            <p class="rounded-xl border bg-white p-4" role="status">{{ __($missingRefund->state === 'confirmed' ? 'order.warranty.missing_refunded' : 'order.warranty.refund_pending', ['amount' => $missingRefund->currency === 'USDT' ? \App\Support\Money::usdt($missingRefund->amount) : \App\Support\Money::idr($missingRefund->amount)], $locale) }}</p>
        @endforeach
        @foreach($units as $unit)
            @php
                $claim = $claims->get($unit->id);
            @endphp
            <article class="grid min-w-0 gap-3 rounded-xl border bg-white p-4">
                <h2 class="font-bold">{{ $unit->orderItem->product_name }} · {{ __('receipt.account_label', ['number' => $unit->unit_number], $locale) }}</h2>
                <p>{{ __('order.warranty.window', ['deadline' => $unit->refund_deadline->timezone(config('app.timezone'))->format('Y-m-d H:i T')], $locale) }}</p>
                @if(array_key_exists($unit->id, $replacementCredentials))
                    @php
                        $credential = $replacementCredentials[$unit->id];
                    @endphp
                    <div class="grid gap-2 rounded bg-slate-100 p-3">
                        @if($credential !== null)
                            @if($credential->email() !== null)
                                <p class="break-all">{{ __('order.warranty.email', [], $locale) }}: {{ $credential->email() }}</p>
                                <p class="break-all">{{ __('order.warranty.password', [], $locale) }}: <code>{{ $credential->password() }}</code></p>
                                @if($credential->legacyParts()['mfa'] ?? null)
                                    <p class="break-all">{{ __('order.warranty.mfa', [], $locale) }}: <code>{{ $credential->legacyParts()['mfa'] }}</code></p>
                                @endif
                            @else
                                <pre class="whitespace-pre-wrap break-all">{{ $credential->text() }}</pre>
                            @endif
                            @if($credential->accessMethod() === \App\Enums\CredentialAccessMethod::AuthToken)
                                <p class="break-all">{{ __('order.warranty.auth_token', [], $locale) }}: <code>{{ $credential->accessValue() }}</code></p>
                            @elseif($credential->accessMethod() === \App\Enums\CredentialAccessMethod::OtpMailLink)
                                <a class="font-bold underline" href="{{ $credential->accessValue() }}" rel="noreferrer">{{ __('order.warranty.otp_mail', [], $locale) }}</a>
                            @endif
                            @if($credential->readerMailLink() !== null)
                                <a class="font-bold underline" href="{{ $credential->readerMailLink() }}" rel="noreferrer">{{ __('order.warranty.reader_mail', [], $locale) }}</a>
                            @endif
                            @if($credential->tutorial() !== null)
                                <p class="whitespace-pre-wrap break-all">{{ $credential->tutorial() }}</p>
                            @endif
                        @else
                            <p>{{ __('order.warranty.credentials_unavailable', [], $locale) }}</p>
                        @endif
                    </div>
                @endif
                @if($claim)
                    @php
                        $stateKey = match($claim->settlement_state) {
                            'refunded' => 'refunded', 'replaced' => 'replaced',
                            'refund_pending', 'refund_uncertain' => 'refund_pending',
                            'replacement_pending', 'replacement_sending', 'replacement_unknown' => 'replacement_pending',
                            'replacement_failed' => 'replacement_failed', 'replacement_review' => 'review',
                            default => match($claim->state) {
                                'approved' => 'choice', 'rejected' => 'rejected', 'expired' => 'expired',
                                'reversed' => 'reversed', 'cancelled' => 'cancelled', 'review' => 'review', default => 'pending',
                            },
                        };
                    @endphp
                    <p role="status">{{ __('order.warranty.'.$stateKey, [], $locale) }}</p>
                    @if($claim->state === 'approved' && $claim->settlement_state === 'awaiting_choice')
                        <form method="POST" action="{{ route('warranty.choose', $order) }}" class="flex flex-wrap gap-3">
                            @csrf<input type="hidden" name="claim_id" value="{{ $claim->public_id }}">
                            <button class="rounded border px-4 py-3 font-bold" name="choice" value="refund">{{ __('order.warranty.refund', [], $locale) }}</button>
                            <button class="rounded border px-4 py-3 font-bold" name="choice" value="replacement">{{ __('order.warranty.replacement', [], $locale) }}</button>
                        </form>
                    @endif
                    @if($claim->state === 'approved' && in_array($claim->settlement_state, ['replacement_pending', 'replacement_failed'], true))
                        <form method="POST" action="{{ route('warranty.choose', $order) }}" class="grid gap-3">
                            @csrf<input type="hidden" name="claim_id" value="{{ $claim->public_id }}">
                            <p>{{ __('order.warranty.change_to_refund', [], $locale) }}</p>
                            <button class="rounded border px-4 py-3 font-bold" name="choice" value="refund">{{ __('order.warranty.refund', [], $locale) }}</button>
                        </form>
                    @endif
                @elseif($unit->confirmation_state !== null)
                    <p>{{ __($unit->confirmation_state === 'confirmed' ? 'order.warranty.confirmed' : 'order.warranty.confirmation_pending', [], $locale) }}</p>
                @elseif($unit->provider_state === 'active' && $unit->refund_deadline->isFuture() && $unit->stockItem->retired_at === null)
                    <form method="POST" enctype="multipart/form-data" action="{{ route('warranty.claim', $order) }}" class="grid gap-3">
                        @csrf<input type="hidden" name="unit_id" value="{{ $unit->public_id }}">
                        <label class="grid gap-2">{{ __('order.warranty.proof', [], $locale) }}<input type="file" name="proof" accept="image/jpeg,image/png" required class="max-w-full"></label>
                        <label class="grid gap-2">{{ __('order.warranty.note', [], $locale) }}<textarea class="min-h-24 rounded border p-2" name="note" maxlength="500"></textarea></label>
                        <button class="rounded bg-slate-900 px-4 py-3 font-bold text-white" type="submit">{{ __('order.warranty.submit', [], $locale) }}</button>
                    </form>
                    <details class="rounded border p-3">
                        <summary>{{ __('order.warranty.confirm', [], $locale) }}</summary>
                        <form method="POST" action="{{ route('warranty.confirm', $order) }}" class="mt-3 grid gap-3">
                            @csrf<input type="hidden" name="unit_id" value="{{ $unit->public_id }}">
                            <label><input type="checkbox" name="closes_refund_window" value="1" required> {{ __('order.warranty.confirm_warning', [], $locale) }}</label>
                            <button class="rounded border px-4 py-3 font-bold" type="submit">{{ __('order.warranty.confirm', [], $locale) }}</button>
                        </form>
                    </details>
                @else
                    <p>{{ __('order.warranty.expired', [], $locale) }}</p>
                @endif
            </article>
        @endforeach
        {{ $units->links() }}
    </main>
</body>
</html>
