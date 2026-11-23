@extends('customer.partials.account-layout')

@section('account-eyebrow', __('customer_account.order_history'))
@section('account-title', __('customer_account.orders'))
@section('account-description', trans_choice('customer_account.order_count', $orders->total()).' · '.__('customer_account.per_page', ['count' => $orders->perPage()]))
@section('account-heading-action')
    <a class="account-secondary-button" href="{{ route('storefront.shop.index') }}">{{ __('customer_account.browse') }}</a>
@endsection

@section('account-content')
    <section class="account-panel account-orders" aria-label="{{ __('customer_account.order_history') }}">
        <nav class="account-filters" aria-label="{{ __('customer_account.order_filters') }}">
            @foreach($filters as $filter)
                <a href="{{ $filter['url'] }}" @if($filter['active']) aria-current="page" @endif>{{ $filter['label'] }}</a>
            @endforeach
        </nav>
        <div class="account-order-list">
            @forelse($orders as $order)
                @php
                    $item = $order->orderItems->first();
                    $family = $item?->family_id === null ? $item?->product?->family : $item->family;
                    $image = \App\Storefront\StorefrontMediaUrl::fromPath($family?->sidebar_icon_path);
                    $glyph = $family?->icon ?: $item?->product_icon;
                    $orderState = $order->order_status?->value ?? 'unknown';
                    $paymentState = $order->payment_status?->value ?? 'unknown';
                    $quantity = (int) $order->item_quantity;
                @endphp
                <article>
                    <a class="account-order-row" href="{{ route('customer.orders.show', $order) }}">
                        <div class="account-order-identity">
                            <span class="account-order-icon" aria-hidden="true">
                                @if($image !== null)
                                    <img src="{{ $image }}" alt="" width="24" height="24" loading="lazy" decoding="async">
                                @elseif($glyph)
                                    <span class="account-order-glyph">{{ $glyph }}</span>
                                @else
                                    @include('customer.partials.account-icon', ['icon' => 'package'])
                                @endif
                            </span>
                            <div>
                                <h2>{{ $item?->product_name ?: $order->invoice_number }}</h2>
                                @if($item?->product_name)
                                    <p class="account-order-meta">{{ $order->invoice_number }} · {{ trans_choice('customer_account.item_count', $quantity) }}</p>
                                @endif
                                @if($order->created_at)
                                    <time datetime="{{ $order->created_at->toIso8601String() }}">{{ $order->created_at->locale(\App\Storefront\StorefrontLocale::code())->translatedFormat('d M Y H:i') }}</time>
                                @endif
                            </div>
                        </div>
                        <div class="account-order-total">
                            <strong>{{ number_format($order->total_amount, 0, '', \App\Storefront\StorefrontLocale::code() === 'id' ? '.' : ',') }} {{ $order->currency }}</strong>
                            <span @class([
                                'account-status-pill',
                                'account-status-success' => $orderState === 'completed',
                                'account-status-waiting' => in_array($orderState, ['draft', 'pending_payment', 'processing'], true),
                                'account-status-issue' => in_array($orderState, ['cancelled', 'expired'], true),
                            ])>{{ __('customer_account.order_states.'.$orderState) }}</span>
                            <small>{{ __('customer_account.payment_states.'.$paymentState) }}</small>
                        </div>
                        @include('customer.partials.account-icon', ['icon' => 'chevron'])
                    </a>
                </article>
            @empty
                <div class="account-empty">
                    @if($activeFilter === 'all')
                        <h2>{{ __('customer_account.empty_orders') }}</h2>
                        <p>{{ __('customer_account.empty_orders_description') }}</p>
                        <a class="account-button" href="{{ route('storefront.shop.index') }}">{{ __('customer_account.browse') }}</a>
                    @else
                        <h2>{{ __('customer_account.empty_filter') }}</h2>
                        <a class="account-text-link" href="{{ route('customer.orders.index') }}">{{ __('customer_account.all_orders') }}</a>
                    @endif
                </div>
            @endforelse
        </div>
        @if($orders->hasPages())
            <nav class="account-history-pagination account-orders-pagination" aria-label="{{ __('customer_account.order_pages') }}">
                @if($orders->previousPageUrl())
                    <a class="account-secondary-button" rel="prev" href="{{ $orders->previousPageUrl() }}">{{ __('customer_account.previous') }}</a>
                @else
                    <span class="account-secondary-button" aria-disabled="true">{{ __('customer_account.previous') }}</span>
                @endif
                <p>{{ __('customer_account.page_of', ['page' => $orders->currentPage(), 'total' => $orders->lastPage()]) }}</p>
                @if($orders->nextPageUrl())
                    <a class="account-secondary-button" rel="next" href="{{ $orders->nextPageUrl() }}">{{ __('customer_account.next') }}</a>
                @else
                    <span class="account-secondary-button" aria-disabled="true">{{ __('customer_account.next') }}</span>
                @endif
            </nav>
        @endif
    </section>
@endsection
