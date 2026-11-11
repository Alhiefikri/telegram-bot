"""Optional P25 helper for the existing disposable PostgreSQL smoke driver."""
from pathlib import Path
import subprocess
import sys
import tempfile
import time
from uuid import uuid4


def _slow_delivery(marker, queue, sentinel):
    from lhie_candidate.startup import configure_django
    configure_django()
    import workers
    if queue == 'mail':
        from lhie_candidate.notifications.mail import SinkMail
        original = SinkMail.send
        def slow(self, *args, **kwargs):
            Path(marker).touch()
            time.sleep(8)
            return original(self, *args, **kwargs)
        SinkMail.send = slow
    else:
        from lhie_candidate.notifications.transport import NotificationTransport
        original = NotificationTransport.execute
        def slow(self, method, payload):
            if sentinel in payload.get('text', ''):
                Path(marker).touch()
                time.sleep(8)
            return original(self, method, payload)
        NotificationTransport.execute = slow
    return workers._worker(queue, None, None)


def _supervise(directory, marker, queue, sentinel):
    from lhie_candidate.workers import supervisor
    original = supervisor.commands

    def commands(metrics_dir, *, include_http):
        values = original(metrics_dir, include_http=include_http)
        values[queue] = [sys.executable, '-c',
                         'import sys;from tests.test_notification_isolation_postgres import _slow_delivery;'
                         'raise SystemExit(_slow_delivery(*sys.argv[1:]))', marker, queue, sentinel]
        return values

    supervisor.commands = commands
    return supervisor.run(directory, include_http=True)


def exercise_slow_delivery_isolation(order_id, seed_during_delay, *, slow_queue='mail',
                                     slow_effect_id=None, sentinel=''):
    """Prove independently seeded work finishes while a real transport is delayed."""
    from lhie_candidate.notifications.mail import CredentialMail
    from lhie_candidate.notifications.policy import TestRecipients
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.receipt.source import ReceiptSource
    from lhie_candidate.runtime import application_key, load_runtime
    from tests.test_checkout_postgres import admin_connection
    from tests.test_workers_postgres import _CgroupSampler, _ConnectionSampler, _wait_for

    runtime = load_runtime()
    assert runtime.workers and runtime.notifications, 'Explicit fake-only worker admission required'
    assert slow_queue in ('mail', 'notifications')
    admin = admin_connection()
    cipher = LaravelEncrypter(application_key())
    source = ReceiptSource(cipher, signing_key=application_key(), invoice_base_url='http://127.0.0.1:18801')
    event = None
    if slow_queue == 'mail':
        event = CredentialMail(source, cipher, recipients=TestRecipients(frozenset({'1'}))).record(order_id)
        assert event is not None
    sampler, cgroups = _ConnectionSampler(), _CgroupSampler()
    process = None
    try:
        with tempfile.TemporaryDirectory(prefix='p25-isolation-') as temporary:
            marker = str(Path(temporary) / 'smtp-started')
            # Only this fake-only test child changes transport latency. The real
            # fixed SMTP sink still accepts the complete MIME message afterwards.
            supervisor_program = (
                'import sys;from tests.test_notification_isolation_postgres import _supervise;'
                'raise SystemExit(_supervise(*sys.argv[1:]))'
            )
            sampler.start()
            cgroups.start()
            process = subprocess.Popen([sys.executable, '-c', supervisor_program, temporary, marker, slow_queue, sentinel],
                                       cwd=Path(__file__).resolve().parents[1],
                                       stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            deadline = time.monotonic() + 30
            while not Path(marker).exists():
                assert process.poll() is None, 'Supervised runtime exited before delayed transport'
                assert time.monotonic() < deadline, 'Delayed transport was not discovered durably'
                time.sleep(.02)
            started = time.monotonic()
            if event is None:
                event = CredentialMail(source, cipher, recipients=TestRecipients(frozenset({'1'}))).record(order_id)
                assert event is not None
                from lhie_candidate.workers import wakeup
                assert wakeup.enqueue('credential.mail', event['id'])
            from tests.test_auth_postgres import core
            from lhie_candidate.auth.types import Browser
            from lhie_candidate.workers import wakeup
            auth_email = 'p25-auth-isolation-' + uuid4().hex + '@example.invalid'
            core().register(auth_email, 'P25-Worker-Test42!', 'Synthetic auth worker', Browser('e' * 64, '127.0.0.49'))
            auth_work_id = admin.execute('''SELECT w.id FROM public.customer_auth_mail_works w
                JOIN public.customer_auth_tokens t ON t.id=w.auth_token_id
                WHERE t.email=%s AND t.purpose='email_verification' ORDER BY w.id DESC LIMIT 1''',
                (auth_email,)).fetchone()[0]
            assert auth_work_id and wakeup.enqueue('auth.mail', auth_work_id)
            checks = seed_during_delay(admin, mail_event_id=event['id'], auth_work_id=auth_work_id)
            expected = {'interaction', 'payment', 'receipt', 'notifications', 'bulk', 'mail', 'auth-mail'} - {slow_queue}
            assert set(checks) == expected
            elapsed = {}
            for queue, (sql, params) in checks.items():
                remaining = max(.01, 7 - (time.monotonic() - started))
                elapsed[queue] = round(_wait_for(admin, queue, sql, params, timeout=remaining) - started, 3)
            if slow_queue == 'mail':
                assert admin.execute('SELECT sent_at IS NULL AND delivery_started_at IS NOT NULL FROM public.order_notification_events WHERE id=%s', (event['id'],)).fetchone() == (True,)
                _wait_for(admin, 'mail', 'SELECT sent_at IS NOT NULL FROM public.order_notification_events WHERE id=%s', (event['id'],), timeout=15)
            else:
                assert admin.execute("SELECT state='sending' FROM public.telegram_outbound_effects WHERE id=%s", (slow_effect_id,)).fetchone() == (True,)
                _wait_for(admin, 'notifications', "SELECT state='sent' FROM public.telegram_outbound_effects WHERE id=%s", (slow_effect_id,), timeout=15)
            process.terminate()
            assert process.wait(timeout=90) == 0
            maximum = sampler.stop()
            observed = cgroups.stop()
            # The probe driver's one runtime session is a diagnostic slot, not
            # additional admitted queue capacity.
            assert maximum <= 10, maximum
            return {'slow_' + slow_queue + '_isolation': elapsed,
                    'runtime_budget': {'processes': 9, 'threads': 9, 'db_connections': 8},
                    slow_queue + '_peak_role_connections_including_probe': maximum,
                    slow_queue + '_cgroup': observed}
    finally:
        if process is not None and process.poll() is None:
            process.terminate()
            try:
                process.wait(timeout=90)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait()
        sampler.stop()
        cgroups.stop()
        admin.close()
