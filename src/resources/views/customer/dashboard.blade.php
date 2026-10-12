@extends('customer.partials.account-layout')

@section('account-title', 'Akun saya')
@section('account-description', 'Pesanan, saldo, dan pengaturan akun Lhie Store Anda.')

@section('account-content')
    <section class="account-panel account-action-group" aria-labelledby="account-shopping-title">
        <h2 id="account-shopping-title">Belanja</h2>
        <div class="account-shopping-actions">
            <a class="account-shopping-action" href="{{ route('customer.orders.index') }}">
                @include('customer.partials.account-icon', ['icon' => 'orders'])
                <strong>Pesanan saya</strong>
                <span>Invoice dan status pesanan</span>
            </a>
            <a class="account-shopping-action" href="{{ route('storefront.shop.cart') }}">
                @include('customer.partials.account-icon', ['icon' => 'cart'])
                <strong>Keranjang</strong>
                <span>Lanjutkan belanja Anda</span>
            </a>
            <a class="account-shopping-action" href="{{ route('customer.claim.create') }}">
                @include('customer.partials.account-icon', ['icon' => 'claim'])
                <strong>Klaim pesanan</strong>
                <span>Gunakan invoice dan Recovery Key</span>
            </a>
        </div>
    </section>

    <section class="account-panel account-action-group" aria-labelledby="account-settings-title">
        <h2 id="account-settings-title">Akun</h2>
        <div class="account-action-list">
            <a class="account-action" href="{{ route('customer.security') }}">
                @include('customer.partials.account-icon', ['icon' => 'security'])
                <span><strong>Keamanan akun</strong><small>Password dan metode masuk</small></span>
            </a>
            <a class="account-action" href="{{ route('customer.balance.show') }}">
                @include('customer.partials.account-icon', ['icon' => 'balance'])
                <span><strong>Saldo saya</strong><small>Saldo IDR, USDT, dan aktivitasnya</small></span>
            </a>
            <div class="account-theme-row">
                <span><strong>Tampilan</strong><small>Pilih tema terang atau gelap</small></span>
                <x-theme-toggle showLabel icon-class="account-icon" class="account-theme-toggle" />
            </div>
        </div>
    </section>

    <section class="account-panel account-action-group" aria-labelledby="account-help-title">
        <h2 id="account-help-title">Bantuan</h2>
        <div class="account-action-list">
            <a class="account-action" href="{{ route('storefront.guides.index') }}">
                @include('customer.partials.account-icon', ['icon' => 'guide'])
                <span><strong>Panduan penggunaan</strong><small>Petunjuk login produk digital</small></span>
            </a>
            <a class="account-action" href="{{ route('storefront.home') }}#support">
                @include('customer.partials.account-icon', ['icon' => 'support'])
                <span><strong>Hubungi support</strong><small>Bantuan pesanan dan masalah login</small></span>
            </a>
        </div>
    </section>
@endsection
