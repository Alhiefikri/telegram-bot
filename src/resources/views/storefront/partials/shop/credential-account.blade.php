<article class="credential-section min-w-0 py-4" aria-label="{{ $secret['label'] }}">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
        <h4 class="font-display text-base font-bold text-[var(--sf-ink)]">{{ $secret['label'] }}</h4>
        <div data-copy-group>
            <button type="button" class="credential-action" data-credential-copy="{{ base64_encode((string) $secret['text']) }}" data-copy-success="{{ $secret['label'] }} tersalin." hidden>Salin akun</button>
            <p class="mt-1 min-h-5 text-xs text-[var(--sf-ink)]" aria-live="polite" data-copy-status></p>
        </div>
    </div>
    @php
        $structured = ($secret['structured'] ?? false) === true;
        $parts = $structured ? $secret : ($secret['parts'] ?? null);
        $fields = [
            ['key' => 'email', 'label' => 'Email', 'sensitive' => false],
            ['key' => 'password', 'label' => 'Password', 'sensitive' => true],
        ];
        if (! $structured && ($parts['mfa'] ?? null) !== null) {
            $fields[] = ['key' => 'mfa', 'label' => 'MFA Secret', 'sensitive' => true];
        }
    @endphp
    @if ($parts !== null)
        <div class="mt-3 grid min-w-0 gap-3 sm:grid-cols-2">
            @foreach ($fields as $field)
                @include('storefront.partials.shop.credential-value', [
                    'valueId' => $credentialId.'-'.$field['key'],
                    'valueLabel' => $field['label'],
                    'accountLabel' => $secret['label'],
                    'value' => $parts[$field['key']],
                    'sensitive' => $field['sensitive'],
                ])
            @endforeach
            @if ($structured && ($secret['access_method'] ?? null) === 'auth_token' && filled($secret['access_value'] ?? null))
                <div class="min-w-0 sm:col-span-2">
                    @include('storefront.partials.shop.credential-value', [
                        'valueId' => $credentialId.'-token',
                        'valueLabel' => 'Auth Token Outlook/Hotmail',
                        'accountLabel' => $secret['label'],
                        'value' => $secret['access_value'],
                        'sensitive' => true,
                    ])
                </div>
            @endif
            @if ($structured && ($secret['access_method'] ?? null) === 'otp_mail_link' && filled($secret['access_value'] ?? null) && filled($secret['access_domain'] ?? null))
                <div class="min-w-0 sm:col-span-2">
                    <p class="text-sm font-semibold text-[var(--sf-ink)]">OTP Mail</p>
                    <p class="mt-1 break-all text-sm text-[var(--sf-muted)]">Domain tujuan: {{ $secret['access_domain'] }}</p>
                    <a href="{{ $secret['access_value'] }}" target="_blank" rel="noreferrer noopener" referrerpolicy="no-referrer" class="credential-action mt-2">Buka OTP Mail</a>
                </div>
            @endif
        </div>
    @else
        <div class="mt-3">
            @include('storefront.partials.shop.credential-value', [
                'valueId' => $credentialId.'-raw',
                'valueLabel' => 'Data akun',
                'accountLabel' => $secret['label'],
                'value' => $secret['text'],
                'sensitive' => true,
            ])
        </div>
    @endif
    @if ($structured && filled($secret['tutorial'] ?? null) && $secret['tutorial'] !== $group['delivery_note'])
        <div class="mt-3 text-sm leading-6 text-[var(--sf-ink)]">
            <p class="font-semibold">Instruksi akun</p>
            <p class="mt-1 whitespace-pre-line break-words">{{ $secret['tutorial'] }}</p>
        </div>
    @endif
</article>
