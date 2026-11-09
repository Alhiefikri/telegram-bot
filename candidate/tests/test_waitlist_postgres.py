"""Changed waitlist lifecycle in the existing P25 disposable proof, never live."""
from concurrent.futures import ThreadPoolExecutor
from datetime import timedelta
from threading import Barrier
from types import SimpleNamespace


def exercise_waitlist(fixtures):
    from django.db import connections
    from django.test import Client
    import re
    from lhie_candidate.checkout.db import Database
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.notifications.mail import DefiniteMailFailure, SinkMail
    from lhie_candidate.notifications.waitlist import Waitlist
    from lhie_candidate.receipt.protocol import _now
    from lhie_candidate.runtime import application_key
    from tests.test_checkout_postgres import admin_connection

    db, admin = Database(), admin_connection()
    key, at = application_key(), _now()
    cipher = LaravelEncrypter(key)
    captured = []

    class Mail:
        failure = None

        def send(self, address, subject, text, *, html):
            captured.append((address, subject, text, html))
            if self.failure:
                raise self.failure
            SinkMail().send(address, subject, text, html=html)

    mail = Mail()
    owner = Waitlist(cipher, key, enabled=True, transport=mail)
    try:
        pid = admin.execute('''INSERT INTO public.products
            (name,description,price_amount,currency,stock,reserved_stock,is_active,created_at,updated_at)
            VALUES ('Synthetic waitlist <Product>','Synthetic fixture',15000,'IDR',0,0,true,now(),now()) RETURNING id''').fetchone()[0]
        client = Client(enforce_csrf_checks=True, HTTP_HOST='127.0.0.1:18801', REMOTE_ADDR='127.0.0.1')
        url = f'/produk/{pid}'
        response = client.get(url)
        assert response.status_code == 200, response.status_code
        csrf = re.search(r'name="csrfmiddlewaretoken" value="([^"]+)"', response.content.decode()).group(1)
        assert client.post(url + '/waitlist', {'email': 'first@example.invalid'}).status_code == 403
        response = client.post(url + '/waitlist', {'email': 'first@example.invalid', 'csrfmiddlewaretoken': csrf},
                               HTTP_ORIGIN='http://127.0.0.1:18801')
        assert response.status_code == 302 and response['Location'] == url + '#waitlist', (response.status_code, response.content)
        assert 'first@example.invalid' not in response['Location']
        for address in ('second@example.invalid', 'unknown@example.invalid', 'stop@example.invalid'):
            assert owner.subscribe(pid, address)
            assert not owner.subscribe(pid, address)
        assert not owner.subscribe(pid, 'real@example.com')
        subscriptions = db.rows('SELECT * FROM public.product_waitlist_subscriptions WHERE product_id=%s ORDER BY id', (pid,))
        assert len(subscriptions) == 4
        assert all('@' not in s['email'] and len(s['email_hash']) == 64 for s in subscriptions)
        for subscription in subscriptions:
            sid = subscription['id']
            assert owner.confirm_mail(sid) == 'sent'
            assert owner.confirm_mail(sid) == 'not_ready'
            assert captured[-1][0].endswith('.invalid')
            assert '&lt;Product&gt;' in captured[-1][3]
            url = owner.links.create('confirm', sid, subscription['confirmation_expires_at'])
            assert owner.resolve(sid, 'unsubscribe', url) == 'expired_link'
            response = client.get(url)
            assert response.status_code == 200 and 'Permintaan dikonfirmasi'.encode() in response.content
            assert response['Cache-Control'] == 'no-store, private'
            assert response['Referrer-Policy'] == 'no-referrer'
            assert owner.resolve(sid, 'confirm', url) == 'already_requested'
        # Coherent synthetic restock: projection, units and immutable adjustment
        # are created together, never by weakening guards or changing history.
        with admin.transaction():
            admin.execute('UPDATE public.products SET stock=3 WHERE id=%s', (pid,))
            for index in range(3):
                admin.execute('''INSERT INTO public.product_stock_items (product_id,secret,created_at,updated_at)
                    VALUES (%s,%s,now(),now())''', (pid, cipher.encrypt_string('Synthetic waitlist unit ' + str(index))))
            mid = admin.execute('''INSERT INTO public.inventory_movements
                (product_id,type,quantity,stock_before,available_before,available_after,became_unavailable,created_at)
                VALUES (%s,'adjustment',3,0,0,3,false,now()) RETURNING id''', (pid,)).fetchone()[0]
        # Bounded fan-out resumes from its persisted cursor, no duplicate work.
        assert owner.materialize(mid, 1) == 1
        assert owner.materialize(mid, 1) == 1
        assert owner.materialize(mid, 1) == 1
        assert owner.materialize(mid, 1) == 1
        assert owner.materialize(mid, 1) == 0
        rows = db.rows('''SELECT n.* FROM public.product_waitlist_notifications n
            JOIN public.product_waitlist_events e ON e.id=n.product_waitlist_event_id
            WHERE e.inventory_movement_id=%s ORDER BY n.id''', (mid,))
        assert len(rows) == 4
        stop = owner.links.create('unsubscribe', subscriptions[-1]['id'], at + timedelta(days=1))
        assert owner.resolve(subscriptions[-1]['id'], 'unsubscribe', stop) == 'unsubscribed'
        before = len(captured)
        assert owner.deliver(rows[-1]['id']) == 'not_ready'
        assert len(captured) == before
        # Real PostgreSQL claim race, one SMTP operation, no per-family copy of
        # the shared Telegram ledger race suite.
        barrier = Barrier(2)

        def competing():
            try:
                concurrent = Waitlist(cipher, key, enabled=True, transport=mail)
                barrier.wait(timeout=10)
                return concurrent.deliver(rows[0]['id'])
            finally:
                connections.close_all()

        before = len(captured)
        with ThreadPoolExecutor(max_workers=2) as pool:
            outcomes = list(pool.map(lambda _: competing(), range(2)))
        assert sorted(outcomes) == ['not_ready', 'sent'], outcomes
        assert len(captured) == before + 1
        assert db.one('SELECT status FROM public.product_waitlist_subscriptions WHERE id=%s',
                      (subscriptions[0]['id'],))['status'] == 'notified'
        # Only a proven pre-acceptance failure is retryable; changed address is
        # not accepted by subscribe once active, so retries retain ownership.
        mail.failure = DefiniteMailFailure()
        assert owner.deliver(rows[1]['id']) == 'failed'
        assert owner.deliver(rows[1]['id']) == 'not_ready'
        admin.execute("UPDATE public.product_waitlist_notifications SET next_attempt_at=now()-interval '1 day' WHERE id=%s", (rows[1]['id'],))
        mail.failure = None
        assert owner.deliver(rows[1]['id']) == 'sent'
        assert captured[-1][0] == 'second@example.invalid'
        mail.failure = TimeoutError('private SMTP response')
        assert owner.deliver(rows[2]['id']) == 'unknown'
        before = len(captured)
        assert owner.deliver(rows[2]['id']) == 'not_ready'
        unknown = db.one('SELECT * FROM public.product_waitlist_notifications WHERE id=%s', (rows[2]['id'],))
        assert unknown['last_error'] == 'mail_delivery_unknown' and unknown['delivery_started_at']
        assert len(captured) == before
        # Lost confirmation ACK/expired sending marker never creates new mail.
        empty = admin.execute('''INSERT INTO public.products
            (name,description,price_amount,currency,stock,reserved_stock,is_active,created_at,updated_at)
            VALUES ('Synthetic lost ACK','Synthetic fixture',100,'IDR',0,0,true,now(),now()) RETURNING id''').fetchone()[0]
        assert owner.subscribe(empty, 'interrupted@example.invalid')
        interrupted = db.one('SELECT id FROM public.product_waitlist_subscriptions WHERE product_id=%s', (empty,))['id']
        admin.execute('''UPDATE public.product_waitlist_subscriptions
            SET confirmation_delivery_started_at=%s,confirmation_attempts=3 WHERE id=%s''', (at - timedelta(seconds=181), interrupted))
        assert owner.confirm_mail(interrupted) == 'unknown'
        assert owner.confirm_mail(interrupted) == 'not_ready'
        assert not owner.subscribe(empty, 'interrupted@example.invalid')
        mail.failure = None
        owner.recover(2)
        assert len(captured) == before
        # Readiness accepts only the new narrow grants, never UPDATE on history.
        assert not db.one("SELECT has_table_privilege(current_user,'public.inventory_movements','UPDATE') AS allowed")['allowed']
        browser_waitlist(fixtures)
        return {'waitlist_http_csrf_opt_in': 'passed', 'waitlist_opt_in_signed_links': 'passed', 'waitlist_bounded_fanout': 'passed',
                'waitlist_smtp_claim_race': 'passed', 'waitlist_unsubscribe': 'passed',
                'waitlist_unknown_no_resend': 'passed', 'waitlist_live_browser_flow': 'passed'}
    finally:
        admin.close()


def browser_waitlist(fixtures):
    """Serve the real storefront while the host drives a disposable browser."""
    import hmac
    import json
    import os
    import re
    import threading
    import time
    from pathlib import Path
    from wsgiref.simple_server import make_server

    from django.contrib.staticfiles.handlers import StaticFilesHandler
    from django.core.wsgi import get_wsgi_application
    from django.db import connections
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.notifications.mail import SinkMail
    from lhie_candidate.notifications.waitlist import Waitlist
    from lhie_candidate.runtime import application_key
    from serve import BoundedServer, QuietHandler
    from tests.test_checkout_postgres import admin_connection
    control_key = fixtures['p25_browser_key']

    key = application_key()
    cipher = LaravelEncrypter(key)
    admin = admin_connection()
    try:
        product_id = admin.execute('''INSERT INTO public.products
            (name,description,price_amount,currency,stock,reserved_stock,is_active,created_at,updated_at)
            VALUES ('Synthetic browser waitlist','Synthetic fixture',100,'IDR',0,0,true,now(),now()) RETURNING id''').fetchone()[0]
    finally:
        admin.close()
    captured = []

    class Mail:
        def send(self, address, subject, text, *, html):
            assert address == 'browser@example.invalid'
            SinkMail().send(address, subject, text, html=html)
            captured.append(text)

    django_app = StaticFilesHandler(get_wsgi_application())

    def app(environ, start_response):
        path = environ.get('PATH_INFO', '')
        connection = connections['default']
        username = 'candidate_p25_admin' if path.startswith('/admin') else 'candidate_runtime'
        password = fixtures['p25_admin_db_password'] if username == 'candidate_p25_admin' else 'candidate-local-only'
        if connection.settings_dict['USER'] != username:
            connection.close()
            connection.settings_dict = {**connection.settings_dict, 'USER': username, 'PASSWORD': password}
        if not path.startswith('/__p25_browser/'):
            return django_app(environ, start_response)
        if (environ.get('REQUEST_METHOD') != 'POST'
                or not hmac.compare_digest(environ.get('HTTP_X_P25_CONTROL', ''), control_key)):
            start_response('403 Forbidden', [('Content-Length', '0')])
            return [b'']
        admin = admin_connection()
        try:
            subscription = admin.execute('''SELECT id,status FROM public.product_waitlist_subscriptions
                WHERE product_id=%s''', (product_id,)).fetchone()
            assert subscription is not None
            owner = Waitlist(cipher, key, enabled=True, transport=Mail())
            if path == '/__p25_browser/confirmation':
                assert subscription[1] == 'pending_confirmation'
                assert owner.confirm_mail(subscription[0]) == 'sent'
                purpose = 'confirm'
            elif path == '/__p25_browser/restock':
                assert subscription[1] == 'active'
                with admin.transaction():
                    admin.execute('UPDATE public.products SET stock=1 WHERE id=%s', (product_id,))
                    admin.execute('''INSERT INTO public.product_stock_items (product_id,secret,created_at,updated_at)
                        VALUES (%s,%s,now(),now())''', (product_id, cipher.encrypt_string('Synthetic browser stock')))
                    movement = admin.execute('''INSERT INTO public.inventory_movements
                        (product_id,type,quantity,stock_before,available_before,available_after,became_unavailable,created_at)
                        VALUES (%s,'adjustment',1,0,0,1,false,now()) RETURNING id''', (product_id,)).fetchone()[0]
                assert owner.materialize(movement, 10) == 1
                notice = admin.execute('''SELECT n.id FROM public.product_waitlist_notifications n
                    JOIN public.product_waitlist_events e ON e.id=n.product_waitlist_event_id
                    WHERE e.inventory_movement_id=%s AND n.subscription_id=%s''', (movement, subscription[0])).fetchone()
                assert notice and owner.deliver(notice[0]) == 'sent'
                purpose = 'unsubscribe'
            else:
                raise ValueError('Unknown browser operation')
            match = re.search(r'http://127\.0\.0\.1:18801/waitlist/' + purpose
                              + r'/[0-9]+\?expires=[0-9]+&signature=[a-f0-9]{64}', captured[-1])
            assert match
            body = json.dumps({'url': match.group(0)}).encode()
            start_response('200 OK', [('Content-Type', 'application/json'), ('Cache-Control', 'no-store'),
                                      ('Content-Length', str(len(body)))])
            return [body]
        finally:
            admin.close()
            connections.close_all()

    evidence = Path('/evidence')
    discard = open(os.devnull, 'w')
    server = make_server('0.0.0.0', 8080, app, server_class=BoundedServer, handler_class=QuietHandler)
    server.discard = discard
    def serve():
        try:
            server.serve_forever(poll_interval=0.1)
        finally:
            connections.close_all()
    thread = threading.Thread(target=serve, daemon=True)
    thread.start()
    try:
        (evidence / 'waitlist-browser-ready.json').write_text(json.dumps({
            'product_id': product_id, 'retry_order': fixtures['p25_browser_retry_order'],
            'unknown_order': fixtures['p25_browser_unknown_order'],
        }))
        deadline = time.monotonic() + 180
        finished = evidence / 'waitlist-browser-finished'
        while not finished.exists():
            if time.monotonic() >= deadline:
                raise RuntimeError('Disposable waitlist browser timed out')
            time.sleep(0.1)
        if finished.read_text().strip() != 'PASS':
            raise RuntimeError('Disposable waitlist browser failed')
        admin = admin_connection()
        try:
            assert admin.execute('SELECT status FROM public.product_waitlist_subscriptions WHERE product_id=%s',
                                 (product_id,)).fetchone() == ('unsubscribed',)
        finally:
            admin.close()
    finally:
        server.shutdown()
        thread.join(timeout=5)
        server.server_close()
        discard.close()
        (evidence / 'waitlist-browser-ready.json').unlink(missing_ok=True)
        (evidence / 'waitlist-browser-finished').unlink(missing_ok=True)
