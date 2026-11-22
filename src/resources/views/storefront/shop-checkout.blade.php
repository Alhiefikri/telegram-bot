<!DOCTYPE html>
<html lang="{{ $metadata->htmlLocale() }}" class="bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head', ['metadata' => $metadata])
    @if ($checkoutCanSubmit && $checkoutChallengeSiteKey !== null)
        <script>
            function checkoutChallengeStatus(message) {
                const status = document.getElementById('checkout-challenge-status');
                if (status) status.textContent = message;
            }
            function checkoutChallengeReady() {
                checkoutChallengeStatus(@js(__('storefront_funnel.challenge_ready')));
            }
            function checkoutChallengeExpired() {
                checkoutChallengeStatus(@js(__('storefront_funnel.challenge_expired')));
            }
            function checkoutChallengeError() {
                checkoutChallengeStatus(@js(__('storefront_funnel.challenge_error')));
            }
        </script>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer onerror="checkoutChallengeError()"></script>
    @endif
</head>
<body class="text-[var(--sf-ink)] antialiased">
    <div class="storefront-shell storefront-checkout-page flex min-h-screen flex-col">
        @include('storefront.partials.header')
        @php
            $oldReviewField = static function (string $field): mixed {
                $value = old($field);

                return is_scalar($value) ? $value : null;
            };
            $checkoutQuantityInput = $restoredCheckoutQuantity ?? $checkoutQuantity;
            $checkoutQuantityQuoted = filter_var($checkoutQuantityInput, FILTER_VALIDATE_INT) === $checkoutQuantity;
            $checkoutReviewConfig = [
                'quantityEditable' => $checkoutSource === 'direct' && $checkoutProduct !== null,
                'quantity' => $checkoutQuantityInput,
                'minimumQuantity' => 1,
                'maximumQuantity' => $checkoutQuantityMax,
                'baseIdrPrice' => $checkoutProductPresentation?->priceAmount,
                'baseUsdtPrice' => $checkoutProductPresentation?->usdtPriceAmount,
                'tiers' => $checkoutProductPresentation?->reachableQuantityPriceTierData() ?? [],
                'copy' => [
                    'unavailable' => __('storefront_funnel.not_yet_available'),
                    'tier' => __('storefront_funnel.bulk_tier'),
                    'invalidQuantity' => __('storefront_funnel.subtotal_unavailable'),
                    'quantityAnnouncement' => __('storefront_funnel.quantity_announcement'),
                ],
            ];
        @endphp
        <main class="checkout-frame mx-auto w-full max-w-5xl flex-1 pb-8" x-data="checkoutReview(@js($checkoutReviewConfig))" @pageshow.window="submitting = false; syncContact()">
            <section aria-labelledby="checkout-title" class="grid gap-4">
                @php
                    $checkoutValidationMessages = $errors->getMessages();
                    $checkoutSessionError = session('storefront_checkout_error');
                    $checkoutErrorMessages = collect($errors->all());
                    $checkoutSessionErrorIsDuplicate = is_string($checkoutSessionError) && $checkoutErrorMessages->contains($checkoutSessionError);
                    $checkoutCustomer = auth('customer')->user();
                    $checkoutCustomerName = trim((string) $checkoutCustomer?->first_name);
                    $restoredPaymentMethod = old('payment_method', $checkoutDefaultPaymentMethod);
                    $selectedPayment = collect($checkoutPaymentOptions)->firstWhere('value', '===', $restoredPaymentMethod) ?? ($checkoutPaymentOptions[0] ?? null);
                    $selectedPaymentMethod = $selectedPayment['value'] ?? null;
                    $paymentOptionCount = count($checkoutPaymentOptions);
                    $paymentHasSakurupiah = in_array('sakurupiah', array_column($checkoutPaymentOptions, 'provider'), true);
                    $paymentRequiresPhone = ($selectedPayment['provider'] ?? null) === 'sakurupiah';

                    if (is_string($checkoutSessionError) && ! $checkoutSessionErrorIsDuplicate) {
                        $checkoutErrorMessages->prepend($checkoutSessionError);
                    }

                    $checkoutErrorTargets = [
                        'quantity' => 'checkout-quantity',
                        'buyer_name' => $checkoutCustomer ? 'checkout-customer-name' : 'checkout-buyer-name',
                        'payment_method' => 'checkout-payment-method',
                        'email' => 'checkout-email',
                        'support_contact_channel' => $paymentRequiresPhone ? 'checkout-sakurupiah-phone' : 'checkout-support-contact-channel',
                        'support_contact_value' => $paymentRequiresPhone ? 'checkout-sakurupiah-phone' : 'checkout-support-contact-value',
                        'terms_accepted' => 'checkout-terms-accepted',
                        'cf-turnstile-response' => 'checkout-challenge',
                    ];
                    $checkoutFirstErrorField = array_key_first($checkoutValidationMessages);
                    $checkoutErrorTarget = $checkoutErrorTargets[$checkoutFirstErrorField] ?? 'checkout-recovery-action';
                @endphp

                <a id="checkout-recovery-action" href="{{ $checkoutReturnUrl }}" class="inline-flex min-h-11 w-fit items-center gap-2 text-sm font-bold underline underline-offset-4">
                    <span aria-hidden="true">&larr;</span> {{ $checkoutReturnLabel }}
                </a>
                <h1 id="checkout-title" class="font-display text-2xl font-black tracking-tight sm:text-3xl">{{ __('storefront_funnel.order_summary') }}</h1>

                @if ($checkoutErrorMessages->isNotEmpty())
                    <section id="checkout-error-summary" tabindex="-1" autofocus data-checkout-error-summary data-focus-target="{{ $checkoutErrorTarget }}" role="alert" aria-labelledby="checkout-error-summary-title" class="checkout-alert rounded-lg border p-3 text-sm leading-relaxed">
                        <h2 id="checkout-error-summary-title" class="font-bold">{{ $checkoutValidationMessages === [] ? __('storefront_funnel.checkout_failed') : __('storefront_funnel.review_checkout_fields') }}</h2>
                        <ul class="mt-2 grid list-disc gap-1.5 pl-5">
                            @foreach ($checkoutValidationMessages as $field => $messages)
                                @foreach ($messages as $message)
                                    <li><a href="#{{ $checkoutErrorTargets[$field] ?? 'checkout-recovery-action' }}" class="underline underline-offset-2">{{ $message }}</a></li>
                                @endforeach
                            @endforeach
                            @if (is_string($checkoutSessionError) && ! $checkoutSessionErrorIsDuplicate)
                                <li><a href="#checkout-recovery-action" class="underline underline-offset-2">{{ $checkoutSessionError }}</a></li>
                            @endif
                        </ul>
                    </section>
                @endif

                @if (session('storefront_pending_invoice_urls', []) !== [])
                    <section aria-labelledby="checkout-pending-invoices-title" class="mt-4 rounded-lg border-2 border-[var(--sf-border)] bg-[var(--sf-surface)] p-4 text-sm font-bold leading-6 text-[var(--sf-ink)]">
                        <h2 id="checkout-pending-invoices-title" class="font-black">{{ __('storefront_funnel.pending_orders') }}</h2>
                        <p>{{ __('storefront_funnel.resolve_pending_orders') }}</p>
                        <ul class="mt-2 grid gap-2">
                            @foreach (session('storefront_pending_invoice_urls', []) as $invoiceUrl)
                                <li><a href="{{ $invoiceUrl }}" class="inline-flex min-h-11 items-center underline underline-offset-2 focus-visible:outline focus-visible:outline-3 focus-visible:outline-[var(--sf-blue)]">{{ __('storefront_funnel.open_order_invoice') }} {{ $loop->iteration }}</a></li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @php
                    $contactDisclosureOpen = filled(old('email')) || $errors->has('email')
                        || (! $paymentRequiresPhone && (filled(old('support_contact_channel')) || filled(old('support_contact_value'))
                            || $errors->hasAny(['support_contact_channel', 'support_contact_value'])));
                    $termsAccepted = in_array(old('terms_accepted'), [true, 1, '1', 'on', 'yes', 'true'], true);
                    $checkoutHasRecoveryInput = $checkoutErrorMessages->isNotEmpty()
                        || collect(['buyer_name', 'email', 'support_contact_channel', 'support_contact_value', 'payment_method', 'terms_accepted'])
                            ->contains(static fn (string $field): bool => old($field) !== null);
                    $showFields = $checkoutCanSubmit || $checkoutHasRecoveryInput;
                    $reviewElement = $showFields ? 'form' : 'div';
                @endphp
                @if (! $checkoutCanSubmit)
                    <section id="checkout-submit-disabled-help" class="checkout-alert rounded-lg border p-3 text-sm leading-relaxed" role="{{ $checkoutMaintenance->active ? 'status' : 'alert' }}">
                        <h2 class="font-bold">{{ $checkoutMaintenance->active ? __('storefront_funnel.maintenance_heading') : ($cartHasConflicts ? __('storefront_funnel.checkout_not_ready') : __('storefront_funnel.checkout_unavailable')) }}</h2>
                        <p>{{ $checkoutMaintenance->active ? $checkoutMaintenanceNotice : ($checkoutUnavailableNotice ?? ($cartHasConflicts ? __('storefront_funnel.cart_snapshot_conflict') : __('storefront_funnel.payment_options_unavailable'))) }}</p>
                        <a href="{{ $checkoutReturnUrl }}" class="inline-flex min-h-11 items-center font-bold underline underline-offset-4">{{ $checkoutReturnLabel }}</a>
                    </section>
                @endif

                @if ($cartItems->contains(fn ($item) => $item->manualUnlimited))
                    @php
                        $manualSupport = $storefront->support()->whatsappOption(__('storefront_funnel.manual_checkout_message'));
                    @endphp
                    <section class="rounded-lg border border-[var(--sf-border)] bg-[var(--sf-surface)] p-3 text-sm leading-relaxed">
                        <h2 class="font-bold">{{ __('storefront_funnel.manual_products') }}</h2>
                        <p>{{ __('storefront_funnel.manual_after_payment_help') }}</p>
                        @if ($manualSupport['available'])
                            <a href="{{ $manualSupport['href'] }}" class="inline-flex min-h-11 items-center underline underline-offset-2">{{ __('storefront_funnel.contact_whatsapp') }}</a>
                        @else
                            <p>{{ __('storefront_funnel.manual_whatsapp_unavailable') }}</p>
                        @endif
                    </section>
                @endif

                <{{ $reviewElement }} class="checkout-review grid min-w-0 gap-4 lg:grid-cols-[minmax(0,1.12fr)_minmax(0,1fr)] lg:items-start"
                    @if ($showFields) id="checkout-form" method="POST" action="{{ $checkoutSubmitUrl }}" x-ref="checkoutForm" @submit="submit($event)" @change="if ($event.target.name === 'payment_method') syncContact()" @invalid.capture="openInvalidDetails($event)" :data-submitting="submitting" @endif>
                    @if ($showFields)
                        @csrf
                        <input type="hidden" name="checkout_attempt" value="{{ old('checkout_attempt', (string) \Illuminate\Support\Str::uuid()) }}">
                        @if ($checkoutSource === 'direct' && $checkoutProduct)
                            <input type="hidden" name="product_id" value="{{ $checkoutProduct->id }}">
                        @elseif ($checkoutSnapshot)
                            <input type="hidden" name="cart_snapshot" value="{{ $checkoutSnapshot }}">
                        @endif
                    @endif

                    <section aria-labelledby="checkout-details-title" data-checkout-details class="grid min-w-0 gap-4 rounded-2xl border border-[var(--sf-border)] bg-[var(--sf-surface)] p-3 sm:p-5">
                    <h2 id="checkout-details-title" class="font-display text-lg font-bold">{{ __('storefront_funnel.checkout_details') }}</h2>
                    <ul class="grid divide-y divide-[var(--sf-border)]" aria-label="{{ __('storefront_funnel.products') }}">
                        @foreach ($cartItems as $item)
                            @if ($checkoutSource === 'direct' && $item->imageUrl)
                                <li class="mb-3 grid aspect-[16/10] place-items-center overflow-hidden rounded-xl border border-[var(--sf-border)] bg-white" aria-hidden="true">
                                    <img src="{{ $item->imageUrl }}" alt="" width="480" height="300" class="h-full min-h-0 w-full min-w-0 object-contain" decoding="async">
                                </li>
                            @endif
                            <li class="flex min-w-0 flex-wrap items-center justify-between gap-x-4 gap-y-1 py-2 first:pt-0 last:pb-0" data-checkout-item="{{ $item->productId }}">
                                @if ($checkoutSource !== 'direct' && $item->imageUrl)
                                    <img src="{{ $item->imageUrl }}" alt="" width="64" height="64" class="size-16 shrink-0 rounded-lg border border-[var(--sf-border)] bg-white object-contain" loading="lazy" decoding="async">
                                @endif
                                <div class="min-w-0 flex-[1_1_10rem]">
                                    <p class="font-display text-lg font-bold leading-tight [overflow-wrap:anywhere]" data-checkout-item-name>{{ $item->name }}</p>
                                    @if ($checkoutSource === 'direct' && $showFields)
                                        <p class="text-sm text-[var(--sf-muted)]" data-checkout-item-tier-note x-show="tierNote !== ''" x-text="tierNote" @if (! $item->hasAppliedQuantityPriceTier()) style="display: none" @endif>@if ($item->hasAppliedQuantityPriceTier()) {{ __('storefront_funnel.bulk_tier', ['count' => $item->appliedTierMinimum]) }} @endif</p>
                                    @else
                                        <p class="text-sm text-[var(--sf-muted)]" data-checkout-item-quantity>{{ __('storefront_funnel.quantity') }} {{ $item->quantity }}</p>
                                        @if ($item->hasAppliedQuantityPriceTier())
                                            <p class="text-sm text-[var(--sf-muted)]">{{ __('storefront_funnel.bulk_tier', ['count' => $item->appliedTierMinimum]) }}</p>
                                        @endif
                                    @endif
                                    @if ($item->conflictMessage)
                                        <p class="checkout-error text-sm">{{ $item->conflictMessage }}</p>
                                    @endif
                                </div>
                                @if ($checkoutSource === 'direct' && $checkoutProduct && $showFields)
                                    <div class="grid w-[min(100%,9rem)] gap-1">
                                        <label for="checkout-quantity" class="text-sm font-bold">{{ __('storefront_funnel.quantity') }}</label>
                                        <div class="checkout-control flex items-center rounded-lg border">
                                            <button type="button" data-checkout-quantity-step="-1" class="checkout-step min-h-11 shrink-0 font-bold" aria-label="{{ __('storefront_funnel.decrease_quantity') }}" @click="stepQuantity(-1)">−</button>
                                            <input id="checkout-quantity" name="quantity" type="number" inputmode="numeric" min="1" max="{{ $checkoutQuantityMax }}" required value="{{ $checkoutQuantityInput }}" data-checkout-quantity x-model.number="quantity" @change="announceQuantity()" class="min-h-11 w-full min-w-[max(44px,3ch)] bg-transparent px-1 text-center font-semibold" @error('quantity') aria-invalid="true" aria-describedby="checkout-quantity-error" @enderror>
                                            <button type="button" data-checkout-quantity-step="1" class="checkout-step min-h-11 shrink-0 font-bold" aria-label="{{ __('storefront_funnel.increase_quantity') }}" @click="stepQuantity(1)">+</button>
                                        </div>
                                        @error('quantity')
                                            <p id="checkout-quantity-error" class="checkout-error text-sm">{{ $message }}</p>
                                        @enderror
                                    </div>
                                    <p data-checkout-quantity-announcement class="sr-only" aria-live="polite" x-text="quantityAnnouncement"></p>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    @if ($showFields)
                        @if ($checkoutCustomer)
                            <div id="checkout-customer-name" class="rounded-lg bg-[var(--sf-paper)] px-3 py-2">
                                <p class="text-sm text-[var(--sf-muted)]">{{ __('storefront_funnel.buyer_name') }}</p>
                                <p class="font-bold [overflow-wrap:anywhere]">{{ $checkoutCustomerName !== '' ? $checkoutCustomerName : __('storefront_funnel.buyer_name') }}</p>
                            </div>
                        @else
                        <div class="grid gap-1">
                            <label for="checkout-buyer-name" class="text-sm font-bold">{{ __('storefront_funnel.buyer_name') }}</label>
                            <input id="checkout-buyer-name" name="buyer_name" type="text" required value="{{ $oldReviewField('buyer_name') }}" autocomplete="name" class="checkout-control min-h-11 w-full rounded-lg border px-3 text-base" @error('buyer_name') aria-invalid="true" aria-describedby="checkout-buyer-name-error" @enderror>
                            @error('buyer_name')
                                <p id="checkout-buyer-name-error" class="checkout-error text-sm">{{ $message }}</p>
                            @enderror
                        </div>
                        @endif

                        @if ($paymentHasSakurupiah)
                            <div class="checkout-whatsapp gap-1">
                                <label for="checkout-sakurupiah-phone" class="text-sm font-bold">{{ __('storefront_funnel.whatsapp_number') }}</label>
                                <input id="checkout-sakurupiah-phone" name="sakurupiah_phone" x-ref="sakurupiahPhone" type="tel" autocomplete="tel" maxlength="21" pattern="\+?[0-9][0-9 .\-]{7,19}" @required($paymentRequiresPhone) value="{{ old('support_contact_channel') === 'whatsapp' ? $oldReviewField('support_contact_value') : null }}" class="checkout-control min-h-11 w-full rounded-lg border px-3 text-base" aria-describedby="checkout-sakurupiah-help @if ($paymentRequiresPhone && $errors->hasAny(['support_contact_channel', 'support_contact_value'])) checkout-sakurupiah-error @endif" @if ($paymentRequiresPhone && $errors->hasAny(['support_contact_channel', 'support_contact_value'])) aria-invalid="true" @endif>
                                <p id="checkout-sakurupiah-help" class="text-sm text-[var(--sf-muted)]">{{ __('storefront_funnel.whatsapp_required') }}</p>
                                @if ($paymentRequiresPhone && $errors->hasAny(['support_contact_channel', 'support_contact_value']))
                                    <p id="checkout-sakurupiah-error" class="checkout-error text-sm">{{ $errors->first('support_contact_value') ?: $errors->first('support_contact_channel') }}</p>
                                @endif
                            </div>
                        @endif

                        <details id="checkout-contact-details" class="checkout-control rounded-lg border px-3" @if ($contactDisclosureOpen) open @endif>
                            <summary class="min-h-11 cursor-pointer py-3 text-sm font-bold">
                                <span class="checkout-optional-contact">{{ __('storefront_funnel.email_or_contact') }}</span>
                                <span class="checkout-whatsapp">{{ __('storefront_funnel.optional_email') }}</span>
                            </summary>
                            <div class="grid gap-2 pb-3">
                                <div class="checkout-optional-contact grid gap-2">
                                <label for="checkout-support-contact-channel" class="text-sm font-bold">{{ __('storefront_funnel.contact_channel') }}</label>
                                <select id="checkout-support-contact-channel" name="support_contact_channel" class="checkout-control min-h-11 w-full rounded-lg border px-2 text-base" aria-describedby="checkout-support-contact-help @error('support_contact_channel') checkout-support-contact-channel-error @enderror" @error('support_contact_channel') aria-invalid="true" @enderror>
                                    <option value="">{{ __('storefront_funnel.not_needed') }}</option>
                                    <option value="telegram" @selected(old('support_contact_channel') === 'telegram')>Telegram</option>
                                    <option value="whatsapp" @selected(old('support_contact_channel') === 'whatsapp')>WhatsApp</option>
                                </select>
                                @error('support_contact_channel')
                                    <p id="checkout-support-contact-channel-error" class="checkout-error text-sm">{{ $message }}</p>
                                @enderror
                                <label for="checkout-support-contact-value" class="text-sm font-bold">{{ __('storefront_funnel.contact_input') }}</label>
                                <input id="checkout-support-contact-value" name="support_contact_value" type="text" value="{{ $paymentRequiresPhone ? null : $oldReviewField('support_contact_value') }}" class="checkout-control min-h-11 w-full rounded-lg border px-3 text-base" aria-describedby="checkout-support-contact-help @error('support_contact_value') checkout-support-contact-value-error @enderror" @error('support_contact_value') aria-invalid="true" @enderror>
                                <p id="checkout-support-contact-help" class="text-sm text-[var(--sf-muted)]">{{ __('storefront_funnel.optional_contact_help') }}</p>
                                @error('support_contact_value')
                                    <p id="checkout-support-contact-value-error" class="checkout-error text-sm">{{ $message }}</p>
                                @enderror
                                </div>
                                <label for="checkout-email" class="text-sm font-bold">{{ __('storefront_funnel.optional_email') }}</label>
                                <input id="checkout-email" name="email" type="email" value="{{ $oldReviewField('email') }}" autocomplete="email" class="checkout-control min-h-11 w-full rounded-lg border px-3 text-base" aria-describedby="checkout-email-help @error('email') checkout-email-error @enderror" @error('email') aria-invalid="true" @enderror>
                                <p id="checkout-email-help" class="text-sm text-[var(--sf-muted)]">{{ __('storefront_funnel.optional_email_help') }}</p>
                                @error('email')
                                    <p id="checkout-email-error" class="checkout-error text-sm">{{ $message }}</p>
                                @enderror
                            </div>
                        </details>
                    @endif
                    </section>

                    <section aria-labelledby="checkout-payment-title" data-checkout-payment class="grid min-w-0 gap-4 rounded-2xl border border-[var(--sf-border)] bg-[var(--sf-surface)] p-3 sm:p-5">
                    <h2 id="checkout-payment-title" class="font-display text-lg font-bold">{{ __('storefront_funnel.payment_method') }}</h2>
                    @if ($selectedPaymentMethod !== null)
                        <fieldset id="checkout-payment-method" class="grid min-w-0 gap-2" @error('payment_method') aria-describedby="checkout-payment-error" @enderror>
                            <legend class="sr-only">{{ __('storefront_funnel.choose_payment_method') }}</legend>
                            @foreach ($checkoutPaymentOptions as $option)
                                <div class="checkout-payment-option relative grid min-w-0 grid-cols-[auto_minmax(0,1fr)_auto] items-start gap-x-3 rounded-xl border border-[var(--sf-border)] p-3">
                                    @include('storefront.partials.shop.payment-method-icon', ['method' => $option['value']])
                                    <div class="min-w-0 [overflow-wrap:anywhere]">
                                        @if ($paymentOptionCount === 1)
                                            <strong>{{ $option['label'] }}</strong>
                                        @else
                                            <label for="checkout-payment-option-{{ $option['value'] }}" class="cursor-pointer font-bold after:absolute after:inset-0">{{ $option['label'] }}</label>
                                        @endif
                                        <p id="checkout-option-help-{{ $option['value'] }}" class="text-sm leading-snug text-[var(--sf-muted)]">{{ $option['value'] === 'qris' ? __('storefront_funnel.checkout_qris_description') : $option['description'] }}</p>
                                    </div>
                                    @if ($paymentOptionCount === 1)
                                        <input type="hidden" name="payment_method" value="{{ $selectedPaymentMethod }}" data-payment-provider="{{ $option['provider'] ?? '' }}">
                                        <span aria-hidden="true" class="text-[var(--checkout-focus)]">✓</span>
                                    @else
                                        <input id="checkout-payment-option-{{ $option['value'] }}" class="relative z-10 mt-1 size-4 accent-[var(--checkout-focus)]" type="radio" name="payment_method" value="{{ $option['value'] }}" data-payment-provider="{{ $option['provider'] ?? '' }}" aria-describedby="checkout-option-help-{{ $option['value'] }}" required @checked($option['value'] === $selectedPaymentMethod)>
                                    @endif
                                </div>
                            @endforeach
                        </fieldset>
                    @endif
                    @error('payment_method')
                        <p id="checkout-payment-error" class="checkout-error text-sm">{{ $message }}</p>
                    @enderror

                    <dl class="border-y border-[var(--sf-border)] py-3">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3 font-bold">
                            <dt>{{ __('storefront_funnel.product_subtotal') }}</dt>
                            <dd data-checkout-order-subtotal>
                                <span class="checkout-idr" @if ($checkoutSource === 'direct' && $checkoutCanSubmit) x-text="subtotalIdr" @endif>{{ $checkoutQuantityQuoted ? $storefront->idr($cartSubtotal) : __('storefront_funnel.not_yet_available') }}</span>
                                @if ($cartUsdtSubtotal !== null && collect($checkoutPaymentOptions)->contains(fn (array $option): bool => $option['value'] !== 'qris'))
                                    <span class="checkout-usdt" @if ($checkoutSource === 'direct' && $checkoutCanSubmit) x-text="subtotalUsdt" @endif>{{ $checkoutQuantityQuoted ? $storefront->usdt($cartUsdtSubtotal) : __('storefront_funnel.not_yet_available') }}</span>
                                @endif
                            </dd>
                        </div>
                    </dl>
                    <p class="-mt-2 text-sm text-[var(--sf-muted)]">{{ __('storefront_funnel.payable_on_invoice') }}</p>

                    @if ($showFields)
                        <label for="checkout-terms-accepted" class="flex min-h-11 items-start gap-2 py-1 text-sm font-semibold">
                            <input id="checkout-terms-accepted" name="terms_accepted" type="checkbox" value="1" required @checked($termsAccepted) class="mt-0.5 h-5 w-5 shrink-0" @error('terms_accepted') aria-invalid="true" aria-describedby="checkout-terms-accepted-error" @enderror>
                            <span>{{ __('storefront_funnel.agree_to') }} <a href="{{ route('storefront.policies.terms') }}" target="_blank" rel="noopener noreferrer" class="underline underline-offset-2">{{ __('storefront_funnel.accept_terms') }}</a> {{ __('storefront_funnel.and') }} <a href="{{ route('storefront.policies.refund') }}" target="_blank" rel="noopener noreferrer" class="underline underline-offset-2">{{ __('storefront_funnel.refund_policy') }}</a>.</span>
                        </label>
                        @error('terms_accepted')
                            <p id="checkout-terms-accepted-error" class="checkout-error text-sm">{{ $message }}</p>
                        @enderror

                        @if ($checkoutCanSubmit)
                            <section id="checkout-challenge" aria-label="{{ __('storefront_funnel.security_check') }}" class="flex min-w-0 flex-wrap items-center gap-2 text-sm text-[var(--sf-muted)]">
                                @if ($checkoutChallengeSiteKey !== null)
                                    <div class="cf-turnstile max-w-full" data-sitekey="{{ $checkoutChallengeSiteKey }}" data-action="checkout" data-size="compact" data-callback="checkoutChallengeReady" data-error-callback="checkoutChallengeError" data-expired-callback="checkoutChallengeExpired" data-timeout-callback="checkoutChallengeExpired" data-refresh-expired="auto" data-refresh-timeout="auto" aria-describedby="checkout-challenge-status"></div>
                                    <p id="checkout-challenge-status" class="min-w-0 flex-[1_1_9rem]" role="status">{{ __('storefront_funnel.finish_security_check') }}</p>
                                    <noscript><p>{{ __('storefront_funnel.enable_js_checkout') }}</p></noscript>
                                @else
                                    <p role="status">{{ __('storefront_funnel.challenge_unavailable') }}</p>
                                @endif
                            </section>
                        @endif

                        <button type="submit" data-checkout-submit class="checkout-submit min-h-12 w-full rounded-lg px-3 py-2 text-base font-bold" x-bind:disabled="submitting || @js(! $checkoutCanSubmit)" @if (! $checkoutCanSubmit) disabled aria-describedby="checkout-submit-disabled-help" @endif @if ($paymentHasSakurupiah && $paymentOptionCount > 1) formnovalidate x-bind:formnovalidate="false" @endif>
                            @forelse ($checkoutPaymentOptions as $option)
                                <span class="checkout-rail-copy" data-rail="{{ $option['value'] }}">{{ __('storefront_funnel.create_invoice') }} {{ $option['label'] }}</span>
                            @empty
                                {{ __('storefront_funnel.checkout_unavailable') }}
                            @endforelse
                            <span x-cloak x-show="submitting">{{ __('storefront_funnel.creating_invoice') }}</span>
                        </button>
                    @endif
                    </section>
                </{{ $reviewElement }}>

                @if ($supportHref)
                    <a href="{{ $supportHref }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-11 w-fit items-center text-sm font-bold underline underline-offset-4">{{ __('storefront_funnel.contact_support') }}</a>
                @endif
            </section>
        </main>
    </div>
</body>
</html>
