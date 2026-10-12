<script>
(() => {
    document.querySelectorAll('[data-email-first]').forEach(form => {
        const fields = form.querySelector('[data-login-password]');
        const password = fields.querySelector('input[name="password"]');
        const submit = form.querySelector('button[type="submit"]');
        if (form.dataset.passwordExpanded === 'true') return;
        fields.hidden = true;
        password.disabled = true;
        submit.textContent = 'Lanjutkan';
        form.addEventListener('submit', event => {
            if (!fields.hidden) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            fields.hidden = false;
            password.disabled = false;
            submit.textContent = 'Masuk dengan password';
            password.focus();
        });
    });
    document.querySelectorAll('[data-auth-form]').forEach(form => {
        form.addEventListener('submit', () => {
            const submit = form.querySelector('button[type="submit"], button:not([type])');
            if (submit) submit.disabled = true;
            const progress = form.querySelector('[data-auth-progress]');
            if (progress) progress.hidden = false;
            form.setAttribute('aria-busy', 'true');
        });
    });
    window.addEventListener('pageshow', () => {
        document.querySelectorAll('[data-auth-form]').forEach(form => {
            const submit = form.querySelector('button[type="submit"], button:not([type])');
            if (submit) submit.disabled = false;
            const progress = form.querySelector('[data-auth-progress]');
            if (progress) progress.hidden = true;
            form.removeAttribute('aria-busy');
        });
    });
    document.querySelectorAll('[data-password-toggle]').forEach(toggle => {
        const input = document.getElementById(toggle.getAttribute('aria-controls'));
        const label = toggle.getAttribute('aria-label');
        toggle.hidden = false;
        toggle.addEventListener('click', () => {
            const visible = input.type === 'password';
            input.type = visible ? 'text' : 'password';
            toggle.setAttribute('aria-label', visible ? label.replace('Tampilkan', 'Sembunyikan') : label);
            toggle.setAttribute('aria-pressed', String(visible));
        });
    });
    document.querySelectorAll('input[type="password"]').forEach((input, index) => {
        if (input.closest('.auth-password-field')) return;
        input.id ||= 'account-password-' + index;
        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'auth-link';
        toggle.textContent = 'Tampilkan password';
        toggle.setAttribute('aria-controls', input.id);
        toggle.setAttribute('aria-pressed', 'false');
        input.parentElement.insertAdjacentElement('afterend', toggle);
        toggle.addEventListener('click', () => {
            const visible = input.type === 'password';
            input.type = visible ? 'text' : 'password';
            toggle.textContent = visible ? 'Sembunyikan password' : 'Tampilkan password';
            toggle.setAttribute('aria-pressed', String(visible));
        });
    });
    const telegramForm = document.querySelector('[data-telegram-form]');
    if (!telegramForm) return;
    telegramForm.hidden = false;
    const status = document.querySelector('[data-telegram-status]');
    const start = telegramForm.querySelector('button');
    const csrf = telegramForm.querySelector('[name="_token"]').value;
    const call = async (url, method = 'POST') => {
        const response = await fetch(url, { method, credentials: 'same-origin', headers: {
            'Accept': 'application/json', 'X-CSRF-TOKEN': csrf,
        }});
        if (!response.ok) throw new Error('unavailable');
        return response.json();
    };
    telegramForm.addEventListener('submit', async event => {
        event.preventDefault();
        start.disabled = true;
        status.textContent = 'Menyiapkan konfirmasi Telegram...';
        try {
            const result = await call(telegramForm.action);
            if (!result.deep_link) throw new Error('unavailable');
            status.textContent = 'Buka Store Bot dan konfirmasi di chat pribadi dalam lima menit, lalu periksa konfirmasi di browser ini. Identitas yang sudah dimiliki akun lain tidak dapat dipindahkan.';
            const link = document.createElement('a');
            link.href = result.deep_link;
            link.className = 'auth-link';
            link.textContent = 'Buka Store Bot';
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            const check = document.createElement('button');
            check.type = 'button';
            check.className = 'auth-link';
            check.textContent = 'Periksa konfirmasi Telegram';
            status.append(document.createElement('br'), link, document.createElement('br'), check);
            const feedback = document.createElement('p');
            status.append(feedback);
            check.addEventListener('click', async () => {
                check.disabled = true;
                feedback.textContent = 'Memeriksa konfirmasi...';
                try {
                    const proof = await call(telegramForm.dataset.statusUrl, 'GET');
                    if (proof.status === 'confirmed') {
                        await call(telegramForm.dataset.consumeUrl);
                        location.assign(telegramForm.dataset.ordersUrl);
                        return;
                    }
                    if (proof.status === 'pending') {
                        feedback.textContent = 'Konfirmasi belum diterima. Konfirmasi di chat pribadi Store Bot lalu periksa lagi.';
                        check.disabled = false;
                    } else {
                        status.textContent = proof.status === 'expired' ? 'Konfirmasi Telegram kedaluwarsa. Mulai kembali.' : 'Konfirmasi tidak dapat digunakan. Masuk dengan email atau mulai kembali; akun tidak digabung.';
                        start.disabled = false;
                    }
                } catch {
                    feedback.textContent = 'Telegram sementara tidak tersedia. Coba lagi atau gunakan email.';
                    check.disabled = false;
                }
            });
        } catch {
            status.textContent = 'Telegram sementara tidak tersedia atau batas permintaan tercapai. Coba lagi nanti atau gunakan email.';
            start.disabled = false;
        }
    });
})();
</script>
