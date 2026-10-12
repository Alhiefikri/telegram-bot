@extends('customer.partials.account-layout')

@section('account-title', 'Pesanan saya')
@section('account-description', 'Lihat status pembayaran dan buka detail pesanan Anda.')

@section('account-content')
    <section class="account-panel account-orders" aria-label="Daftar pesanan">
        <nav class="account-filters" aria-label="Filter status pesanan">
            @foreach($filters as $filter)
                <a href="{{ $filter['url'] }}" @if($filter['active']) aria-current="page" @endif>{{ $filter['label'] }}</a>
            @endforeach
        </nav>
        <div class="account-order-list">
            @forelse($orders as $order)
                @php
                    $orderStatusLabel = match ($order->order_status?->value) {
                        'draft' => 'Draft',
                        'pending_payment' => 'Menunggu pembayaran',
                        'processing' => 'Diproses',
                        'completed' => 'Selesai',
                        'cancelled' => 'Dibatalkan',
                        'expired' => 'Kedaluwarsa',
                        default => 'Tidak diketahui',
                    };
                    $paymentStatusLabel = match ($order->payment_status?->value) {
                        'pending' => 'Menunggu pembayaran',
                        'paid' => 'Dibayar',
                        'failed' => 'Gagal',
                        'expired' => 'Kedaluwarsa',
                        'refunded' => 'Dikembalikan',
                        default => 'Tidak diketahui',
                    };
                @endphp
                <article class="account-order-row">
                    <div class="account-order-identity">
                        <h2>{{ $order->invoice_number }}</h2>
                        @if($order->created_at)
                            <time datetime="{{ $order->created_at->toIso8601String() }}">{{ $order->created_at->format('d M Y H:i') }}</time>
                        @endif
                    </div>
                    <div class="account-order-statuses">
                        <p><span>Pesanan</span><strong @class([
                            'account-status-success' => $order->order_status?->value === 'completed',
                            'account-status-waiting' => in_array($order->order_status?->value, ['draft', 'pending_payment', 'processing'], true),
                            'account-status-issue' => in_array($order->order_status?->value, ['cancelled', 'expired'], true),
                        ])>{{ $orderStatusLabel }}</strong></p>
                        <p><span>Pembayaran</span><strong @class([
                            'account-status-success' => $order->payment_status?->value === 'paid',
                            'account-status-waiting' => $order->payment_status?->value === 'pending',
                            'account-status-issue' => in_array($order->payment_status?->value, ['failed', 'expired', 'refunded'], true),
                        ])>{{ $paymentStatusLabel }}</strong></p>
                    </div>
                    <div class="account-order-total">
                        <strong>{{ number_format($order->total_amount, 0, ',', '.') }} {{ $order->currency }}</strong>
                        <a href="{{ route('customer.orders.show', $order) }}" class="account-detail-link" aria-label="Lihat detail pesanan {{ $order->invoice_number }}">Lihat detail</a>
                    </div>
                </article>
            @empty
                @php($activeFilterLabel = collect($filters)->firstWhere('key', $activeFilter)['label'] ?? $activeFilter)
                <div class="account-empty">
                    @if($activeFilter === 'all')
                        <h2>Belum ada pesanan di akun ini</h2>
                        <p>Pesanan yang terhubung ke akun Anda akan tampil di sini.</p>
                        <a class="account-button" href="{{ route('storefront.shop.index') }}">Belanja produk</a>
                    @else
                        <h2>Tidak ada pesanan pada filter ini</h2>
                        <p>Belum ada pesanan dengan status "{{ $activeFilterLabel }}".</p>
                        <a class="account-text-link" href="{{ route('customer.orders.index') }}">Lihat semua pesanan</a>
                    @endif
                </div>
            @endforelse
        </div>
        @if($orders->hasPages())
            <div class="account-pagination">{{ $orders->links() }}</div>
        @endif
    </section>
@endsection
