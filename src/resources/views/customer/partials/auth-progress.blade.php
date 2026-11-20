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
    const call = async (url, method = 'POST', requestId = null, body = null) => {
        const response = await fetch(url, { method, credentials: 'same-origin', headers: {
            'Accept': 'application/json', 'X-CSRF-TOKEN': csrf,
            ...(requestId ? { 'X-Telegram-Login-Request': requestId } : {}),
        }, body, ...(requestId ? { signal: AbortSignal.timeout(8000) } : {}) });
        if (!response.ok) throw new Error('unavailable');
        return response.json();
    };
    const openTelegram = result => {
        const link = document.createElement('a');
        link.href = result.deep_link;
        link.className = 'auth-secondary';
        link.textContent = 'Buka Telegram';
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        return link;
    };
    const showTelegramLogin = result => {
        const deadline = Date.now() + result.expires_in * 1000;
        const title = document.createElement('strong');
        title.textContent = 'Konfirmasi di Telegram';
        const countdown = document.createElement('span');
        countdown.className = 'auth-telegram-countdown';
        countdown.setAttribute('role', 'timer');
        const instruction = document.createElement('p');
        instruction.textContent = 'Buka bot, tekan Start, lalu pilih angka di bawah. Setujui hanya permintaan yang Anda mulai sendiri.';
        const number = document.createElement('strong');
        number.className = 'auth-telegram-number';
        number.textContent = result.number;
        const feedback = document.createElement('p');
        feedback.textContent = 'Kembali ke tab ini setelah konfirmasi. Anda akan masuk otomatis.';
        status.replaceChildren(title, countdown, instruction, number, openTelegram(result), feedback);

        let timer;
        let tick;
        let checking = false;
        let stopped = false;
        let lastCheck = 0;
        const stop = message => {
            stopped = true;
            clearTimeout(timer);
            clearInterval(tick);
            document.removeEventListener('visibilitychange', resume);
            window.removeEventListener('focus', resume);
            if (message) {
                status.textContent = message;
                start.disabled = false;
                start.textContent = 'Ulangi masuk dengan Telegram';
            }
        };
        const updateCountdown = () => {
            const seconds = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
            countdown.textContent = `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
            if (!seconds && !checking && !stopped) {
                stop('Waktu konfirmasi Telegram habis. Mulai kembali.');
            }
        };
        const check = async () => {
            if (stopped || checking || document.hidden) return;
            clearTimeout(timer);
            updateCountdown();
            if (stopped) return;
            checking = true;
            lastCheck = Date.now();
            try {
                const proof = await call(telegramForm.dataset.statusUrl, 'GET', result.request_id);
                if (proof.status === 'confirmed') {
                    await call(telegramForm.dataset.consumeUrl, 'POST', result.request_id, new FormData(telegramForm));
                    stop();
                    location.assign(telegramForm.dataset.ordersUrl);
                } else if (proof.status === 'expired') {
                    stop('Waktu konfirmasi Telegram habis. Mulai kembali.');
                } else if (proof.status === 'unavailable') {
                    // A transient failure should not discard the current request.
                    feedback.textContent = 'Belum bisa memeriksa konfirmasi. Mencoba lagi otomatis…';
                } else if (proof.status === 'pending') {
                    feedback.textContent = 'Kembali ke tab ini setelah konfirmasi. Anda akan masuk otomatis.';
                } else {
                    stop('Konfirmasi tidak berlaku. Jika salah memilih angka, mulai lagi dari situs.');
                }
            } catch {
                feedback.textContent = 'Belum bisa memeriksa konfirmasi. Mencoba lagi otomatis…';
            } finally {
                checking = false;
                if (!stopped) timer = setTimeout(check, 3000);
            }
        };
        const resume = () => {
            if (!checking && !document.hidden) {
                // Focus and visibility can fire together when returning from the app.
                clearTimeout(timer);
                timer = setTimeout(check, Math.max(150, lastCheck + 3000 - Date.now()));
            }
        };
        updateCountdown();
        if (stopped) return;
        tick = setInterval(updateCountdown, 1000);
        document.addEventListener('visibilitychange', resume);
        window.addEventListener('focus', resume);
        timer = setTimeout(check, 3000);
    };
    telegramForm.addEventListener('submit', async event => {
        event.preventDefault();
        if (start.disabled) return;
        start.disabled = true;
        status.textContent = 'Menyiapkan konfirmasi Telegram...';
        try {
            const result = await call(telegramForm.action);
            if (!result.deep_link) throw new Error('unavailable');
            if (result.number) {
                showTelegramLogin(result);
                return;
            }
            status.textContent = 'Buka Store Bot dan konfirmasi di chat pribadi dalam lima menit, lalu periksa konfirmasi di browser ini. Identitas yang sudah dimiliki akun lain tidak dapat dipindahkan.';
            const link = openTelegram(result);
            link.textContent = 'Buka Store Bot';
            const check = document.createElement('button');
            check.type = 'button';
            check.className = 'auth-submit';
            check.textContent = 'Periksa konfirmasi Telegram';
            const actions = document.createElement('div');
            actions.className = 'auth-telegram-actions';
            actions.append(link, check);
            const feedback = document.createElement('p');
            feedback.className = 'auth-telegram-feedback';
            status.append(actions, feedback);
            check.addEventListener('click', async () => {
                check.disabled = true;
                check.setAttribute('aria-busy', 'true');
                check.textContent = 'Memeriksa konfirmasi...';
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
                } finally {
                    check.removeAttribute('aria-busy');
                    check.textContent = 'Periksa konfirmasi Telegram';
                }
            });
        } catch {
            status.textContent = 'Telegram sementara tidak tersedia atau batas permintaan tercapai. Coba lagi nanti atau gunakan email.';
            start.disabled = false;
        }
    });
})();
</script>
