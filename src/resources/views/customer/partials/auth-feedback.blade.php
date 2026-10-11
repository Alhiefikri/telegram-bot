@if(session('customer_login_sent'))
    <p role="status" class="mt-5 rounded-lg border border-[var(--sf-border)] bg-[var(--sf-yellow)] p-4 text-sm font-bold">{{ session('customer_login_sent') }}</p>
@endif
@if(session('customer_login_error'))
    <p role="alert" class="mt-5 rounded-lg border border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-4 text-sm font-bold">{{ session('customer_login_error') }}</p>
@endif
@if($errors->any())
    <div role="alert" class="mt-5 rounded-lg border border-[var(--sf-border)] p-4 text-sm font-bold">
        <p>Periksa isian berikut:</p>
        <ul class="mt-2 list-disc space-y-1 pl-5">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
