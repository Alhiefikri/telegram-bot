@extends('customer.partials.account-layout')

@section('account-title', 'Klaim pesanan guest')
@section('account-description', 'Hubungkan pesanan checkout guest menggunakan invoice dan Recovery Key.')

@section('account-content')
    <section class="account-panel account-claim">
        <p class="account-help">Gunakan invoice dan Recovery Key yang Anda terima saat checkout. Jangan bagikan Recovery Key. Support tidak dapat mengklaimkan order atau membuka kredensial tanpa Recovery Key yang valid.</p>
        @if(session('customer_claim_error'))
            <div class="account-notice" role="alert">{{ session('customer_claim_error') }}</div>
        @endif
        @if($errors->any())
            <div class="account-notice" role="alert">
                <p>Periksa isian berikut:</p>
                <ul class="mt-2 list-disc pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        <form method="POST" action="{{ route('customer.claim.store') }}" class="account-form">
            @csrf
            <label class="account-label">Nomor invoice
                <input name="invoice_number" required value="{{ old('invoice_number') }}" class="account-input">
            </label>
            <label class="account-label">Recovery Key
                <input name="access_key" required autocomplete="off" class="account-input">
            </label>
            <button type="submit" class="account-button">Klaim pesanan</button>
        </form>
        <a href="{{ route('storefront.policies.recovery') }}" class="account-text-link">Kebijakan pemulihan pesanan</a>
    </section>
@endsection
