"""P19 source-bound HTTP/SMTP proof and synchronized PostgreSQL ownership races."""
from dataclasses import asdict, replace
from datetime import timedelta
import hashlib
import json
import multiprocessing
from types import SimpleNamespace
from urllib.parse import parse_qs, urlencode, urlsplit

from tests.test_checkout_postgres import admin_connection


def core(db=None, clock=None):
    from lhie_candidate.auth.limits import RateLimits
    from lhie_candidate.auth.service import AuthService
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.runtime import application_key
    key = application_key()
    options = {"clock": clock} if clock else {}
    return AuthService(app_key=key, encrypter=LaravelEncrypter(key), limits=RateLimits(key), db=db, **options)


class GoogleFixture:
    def __init__(self, subject, email, barrier=None):
        self.subject, self.email, self.barrier = subject, email, barrier

    def ready(self):
        return True

    def configuration_fingerprint(self):
        return "c" * 64

    def authorization_url(self, state):
        return "https://fixture.invalid/authorize?" + urlencode({"state": state})

    def user(self, code):
        from lhie_candidate.auth.google_provider import GoogleIdentity
        if self.barrier:
            self.barrier.wait(timeout=15)
        return GoogleIdentity(self.subject, self.email)


def competing(spec, barrier, queue):
    from lhie_candidate.startup import configure_django
    configure_django()
    from django.db import connections
    from lhie_candidate.auth.types import AuthError, Browser
    from lhie_candidate.checkout.db import Database
    action = spec["action"]

    class SynchronizedDatabase(Database):
        synchronized = False

        def one(self, sql, params=()):
            row = super().one(sql, params)
            if action == "register" and not self.synchronized and "WHERE email=%s FOR UPDATE" in sql:
                self.synchronized = True
                assert row is None
                barrier.wait(timeout=15)
            return row

    try:
        db = SynchronizedDatabase()
        browser = Browser(**spec["browser"])
        service = core(db=db)
        if action not in ("register", "google"):
            barrier.wait(timeout=15)
        if action == "register":
            # The uniqueness boundary must survive competing already-admitted requests.
            service.limits = SimpleNamespace(mail=lambda email, ip: None)
            service.register(spec["email"], "Synthetic-Race42!", "Race", browser)
            result = None
        elif action == "verify":
            result = service.verify(spec["token"], browser)
        elif action == "otp":
            result = service.consume_otp(spec["challenge"], spec["code"], browser)
        elif action == "google":
            from lhie_candidate.auth.google import GoogleAuthentication
            result = GoogleAuthentication(app_key=service.app_key, limits=service.limits, db=db,
                provider=GoogleFixture(spec["subject"], spec["email"], barrier)).callback(
                    browser, spec["state"], "synthetic-code", spec["nonce"])
        elif action == "claim":
            from lhie_candidate.auth.ownership import OrderAccess
            from lhie_candidate.auth.sessions import Session
            session = Session()
            session.data["customer"] = spec["principal"]
            request = SimpleNamespace(auth_session=session, META={"REMOTE_ADDR": browser.ip})
            result = OrderAccess(app_key=service.app_key, limits=service.limits, db=db).claim(
                request, spec["invoice"], spec["key"])
        else:
            raise ValueError("Unknown race")
        queue.put(("accepted", asdict(result) if hasattr(result, "customer_id") else result))
    except AuthError as error:
        queue.put(("rejected", error.code))
    except Exception as error:
        queue.put(("failed", type(error).__name__))
    finally:
        connections.close_all()


def race(specs):
    from django.db import connections
    connections.close_all()
    context = multiprocessing.get_context("spawn")
    barrier, queue = context.Barrier(len(specs)), context.Queue()
    children = [context.Process(target=competing, args=(spec, barrier, queue)) for spec in specs]
    try:
        for child in children:
            child.start()
        results = [queue.get(timeout=40) for _ in children]
        for child in children:
            child.join(timeout=10)
            assert child.exitcode == 0
        assert not any(status == "failed" for status, _ in results), results
        return results
    finally:
        for child in children:
            if child.is_alive():
                child.kill()
                child.join()
        queue.close()


def exercise_auth_protocol(fixtures):
    from django.conf import settings
    from django.db import connections
    from django.test import override_settings
    from unittest.mock import patch
    from lhie_candidate.auth.google import GoogleAuthentication
    from lhie_candidate.auth.limits import RateLimits
    from lhie_candidate.auth.mail import LocalMailSink, MailWork
    from lhie_candidate.auth.telegram import TelegramAuthentication
    from lhie_candidate.auth.types import AuthError, Browser, legacy_now
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.compat.identity import sha256_digest
    from lhie_candidate.runtime import application_key
    from lhie_candidate.readiness import verify_dependencies

    verify_dependencies()
    from lhie_candidate.auth.bot_identity import TelegramBotIdentity
    assert TelegramBotIdentity().username() is None, "Fake namespace must not invent bot identity"
    admin, service, key = admin_connection(), core(), application_key()
    cipher, data = LaravelEncrypter(key), fixtures["auth"]
    browser = Browser("a" * 64, "127.0.0.19")
    password = data["password"]

    def reject(operation, code=None):
        try:
            operation()
        except AuthError as error:
            if code:
                assert error.code == code, (error.code, code)
        else:
            raise AssertionError("Expected auth denial")

    def mail(email, purpose=None):
        row = admin.execute("""SELECT w.id,w.payload,t.token_hash FROM public.customer_auth_mail_works w
            LEFT JOIN public.customer_auth_tokens t ON t.id=w.auth_token_id
            LEFT JOIN public.customer_login_links l ON l.id=w.login_link_id
            WHERE COALESCE(t.email,l.email)=%s AND (%s::text IS NULL OR t.purpose=%s)
            ORDER BY w.id DESC LIMIT 1""", (email, purpose, purpose)).fetchone()
        assert row is not None
        payload = cipher.decrypt_json(row[1])
        assert payload["email"] == email and payload["token"] not in row[1]
        return row[0], payload["token"]

    def snapshot():
        digest = hashlib.sha256()
        for table in ("store_balance_entries", "customer_balance_accounts", "inventory_movements",
                      "product_stock_items", "order_items", "payments"):
            rows = admin.execute('SELECT row_to_json(t)::text FROM public.' + table + ' t ORDER BY id').fetchall()
            digest.update(json.dumps(rows).encode())
        return digest.hexdigest()

    original = snapshot()
    try:
        # PHP raw and sha384-v1 hashes authenticate the same existing Customer.
        principals = []
        for scheme in ("raw", "sha384-v1"):
            customer = data[scheme]
            proof = service.login(customer["email"], password, browser)
            assert proof.customer_id == customer["id"]
            principals.append(proof)
        assert admin.execute("SELECT password_scheme FROM public.customers WHERE id=%s", (data["raw"]["id"],)).fetchone() == ("sha384-v1",)
        reject(lambda: service.login(data["admin"]["email"], password, browser))

        # One shared high-entropy consumption protocol is proven with a real PG race.
        service.register("p19-registration@example.invalid", password, "New", browser)
        work_id, token = mail("p19-registration@example.invalid", "email_verification")
        reject(lambda: service.reset(token, password, browser))
        expired = core(clock=lambda: legacy_now() + timedelta(minutes=31))
        reject(lambda: expired.verify(token, browser))
        results = race([{"action": "verify", "browser": asdict(browser), "token": token}] * 2)
        assert sorted(status for status, _ in results) == ["accepted", "rejected"], results
        registered = next(result for status, result in results if status == "accepted")
        reject(lambda: service.verify(token, browser))
        assert admin.execute("SELECT payload,status FROM public.customer_auth_mail_works WHERE id=%s", (work_id,)).fetchone() == (None, "cancelled")

        registration = [{"action": "register", "browser": asdict(replace(browser, ip="127.0.1." + str(index))),
                         "email": "p19-registration-race@example.invalid"} for index in (1, 2)]
        assert all(status == "accepted" for status, _ in race(registration))
        assert admin.execute("SELECT count(*) FROM public.customers WHERE email='p19-registration-race@example.invalid'").fetchone() == (1,)
        assert admin.execute("""SELECT count(*) FROM public.customer_auth_tokens
            WHERE email='p19-registration-race@example.invalid' AND purpose='email_verification'""").fetchone() == (1,)

        # Six-digit keyed OTP stays browser/version-bound; five failures survive concurrency.
        challenge = service.request_otp(data["sha384-v1"]["email"], browser)
        _, code = mail(data["sha384-v1"]["email"], "email_otp")
        reject(lambda: service.consume_otp(challenge, code, replace(browser, session_id="b" * 64)))
        for _ in range(4):
            reject(lambda: service.consume_otp(challenge, "invalid", browser))
        results = race([{"action": "otp", "browser": asdict(browser), "challenge": challenge, "code": "invalid"}] * 2)
        assert all(status == "rejected" for status, _ in results)
        assert admin.execute("SELECT attempts FROM public.customer_auth_tokens WHERE challenge_hash=%s", (sha256_digest(challenge),)).fetchone() == (5,)
        reject(lambda: service.consume_otp(challenge, code, browser))
        otp_browser = replace(browser, ip="127.0.2.19")
        challenge = service.request_otp(data["raw"]["email"], otp_browser)
        _, code = mail(data["raw"]["email"], "email_otp")
        assert service.consume_otp(challenge, code, otp_browser).customer_id == data["raw"]["id"]
        reject(lambda: service.consume_otp(challenge, code, otp_browser))

        # Two fresh Customers genuinely compete for one provider subject after state commit.
        specs = []
        for index, proof in enumerate(principals):
            fresh = Browser(str(index + 1) * 64, "127.0.3." + str(index), **{
                name: getattr(proof, name) for name in ("customer_id", "auth_version", "authenticated_at", "method")})
            google = GoogleAuthentication(app_key=key, limits=service.limits,
                provider=GoogleFixture("p19-shared-subject", "p19-provider@example.invalid"))
            start = google.start(fresh, "link")
            state = parse_qs(urlsplit(start["authorization_url"]).query)["state"][0]
            specs.append({"action": "google", "browser": asdict(fresh), "subject": "p19-shared-subject",
                          "email": "p19-provider@example.invalid", "state": state, "nonce": start["nonce"]})
        results = race(specs)
        assert sorted(status for status, _ in results) == ["accepted", "rejected"], results
        owner = admin.execute("SELECT customer_id FROM public.customer_identities WHERE provider='google' AND provider_subject='p19-shared-subject'").fetchone()[0]
        owner_proof = next(proof for proof in principals if proof.customer_id == owner)
        owner_browser = Browser("9" * 64, "127.0.3.9", **{name: getattr(owner_proof, name)
            for name in ("customer_id", "auth_version", "authenticated_at", "method")})
        assert google.unlink(owner_browser).authenticated_at == owner_proof.authenticated_at
        relink = google.start(owner_browser, "link")
        state = parse_qs(urlsplit(relink["authorization_url"]).query)["state"][0]
        assert google.callback(owner_browser, state, "synthetic-code", relink["nonce"]).customer_id == owner
        anonymous = replace(browser, session_id="c" * 64, ip="127.0.4.19")
        google = GoogleAuthentication(app_key=key, limits=service.limits,
            provider=GoogleFixture("p19-shared-subject", data["raw"]["email"]))
        start = google.start(anonymous, "login")
        state = parse_qs(urlsplit(start["authorization_url"]).query)["state"][0]
        assert google.callback(anonymous, state, "synthetic-code", start["nonce"]).customer_id == owner
        reject(lambda: google.callback(anonymous, state, "synthetic-code", start["nonce"]))
        collision = GoogleAuthentication(app_key=key, limits=service.limits,
            provider=GoogleFixture("unlinked-subject", data["raw"]["email"]))
        start = collision.start(anonymous, "register")
        state = parse_qs(urlsplit(start["authorization_url"]).query)["state"][0]
        reject(lambda: collision.callback(anonymous, state, "synthetic-code", start["nonce"]), "collision")
        registration_provider = GoogleAuthentication(app_key=key, limits=service.limits,
            provider=GoogleFixture("p19-new-subject", "p19-google-new@example.invalid"))
        start = registration_provider.start(anonymous, "register")
        state = parse_qs(urlsplit(start["authorization_url"]).query)["state"][0]
        new_google = registration_provider.callback(anonymous, state, "synthetic-code", start["nonce"])
        assert admin.execute("SELECT email_verified_at IS NOT NULL FROM public.customers WHERE id=%s",
                             (new_google.customer_id,)).fetchone() == (True,)
        only = admin.execute("INSERT INTO public.customers(first_name,created_at,updated_at) "
                             "VALUES('Synthetic Google-only',now(),now()) RETURNING id").fetchone()[0]
        identity = admin.execute("INSERT INTO public.customer_identities(customer_id,provider,provider_subject,created_at,updated_at) "
                                 "VALUES(%s,'google','p19-last-method',now(),now()) RETURNING id", (only,)).fetchone()[0]
        last_method = Browser("8" * 64, "127.0.3.8", only, 0, owner_proof.authenticated_at, "google:" + str(identity))
        reject(lambda: google.unlink(last_method), "last_method")

        with patch("lhie_candidate.auth.telegram.TelegramBotIdentity",
                   return_value=SimpleNamespace(username=lambda: "LhieStoreTestBot")):
            tg = TelegramAuthentication(app_key=key, limits=service.limits)
            telegram_customer = fixtures["python"]["customer_id"]
            actor = str(admin.execute("SELECT telegram_id FROM public.customers WHERE id=%s", (telegram_customer,)).fetchone()[0])
            start = tg.start(anonymous)
            capability = parse_qs(urlsplit(start["deep_link"]).query)["start"][0]
            assert tg.prepare(capability, actor, actor, "group") is None
            confirmation = tg.prepare(capability, actor, actor, "private")
            assert confirmation
            reject(lambda: tg.consume(anonymous, start["claim_id"], start["nonce"]))
            assert tg.confirm(confirmation, actor, actor, "private")
            assert not tg.confirm(confirmation, actor, actor, "private")
            foreign = replace(anonymous, session_id="d" * 64)
            reject(lambda: tg.consume(foreign, start["claim_id"], start["nonce"]))
            telegram_proof = tg.consume(anonymous, start["claim_id"], start["nonce"])
            assert telegram_proof.customer_id == telegram_customer
            reject(lambda: tg.consume(anonymous, start["claim_id"], start["nonce"]))
            fresh = Browser("e" * 64, "127.0.5.19", **{name: getattr(principals[0], name)
                for name in ("customer_id", "auth_version", "authenticated_at", "method")})
            conflict = tg.start(fresh, link=True)
            capability = parse_qs(urlsplit(conflict["deep_link"]).query)["start"][0]
            assert tg.prepare(capability, actor, actor, "private") is None
            assert admin.execute("SELECT telegram_id FROM public.customers WHERE id=%s", (fresh.customer_id,)).fetchone() == (None,)
            link_browser = Browser("7" * 64, "127.0.5.7", **{name: registered[name]
                for name in ("customer_id", "auth_version", "authenticated_at", "method")})
            linked = tg.start(link_browser, link=True)
            capability = parse_qs(urlsplit(linked["deep_link"]).query)["start"][0]
            confirmation = tg.prepare(capability, "9000695", "9000695", "private")
            assert confirmation and tg.confirm(confirmation, "9000695", "9000695", "private")
            assert tg.consume(link_browser, linked["claim_id"], linked["nonce"]).customer_id == registered["customer_id"]
            assert admin.execute("SELECT telegram_id FROM public.customers WHERE id=%s",
                                 (registered["customer_id"],)).fetchone() == (9000695,)
            enrollment = Browser("6" * 64, "127.0.5.6", **{name: getattr(telegram_proof, name)
                for name in ("customer_id", "auth_version", "authenticated_at", "method")})
            service.enroll_email("p19-telegram-email@example.invalid", enrollment)
            _, email_token = mail("p19-telegram-email@example.invalid", "email_verification")
            reject(lambda: service.verify(email_token, replace(enrollment, session_id="5" * 64)))
            assert service.verify(email_token, enrollment).customer_id == telegram_customer
            assert admin.execute("SELECT password FROM public.customers WHERE id=%s",
                                 (telegram_customer,)).fetchone() == (None,)

        # The only permitted ownership transfer is explicit paid-guest claim + key.
        guest = data["guest"]
        specs = [{"action": "claim", "browser": asdict(replace(browser, ip="127.0.6." + str(index))),
                  "principal": asdict(proof), "invoice": guest["invoice"], "key": guest["key"]}
                 for index, proof in enumerate(principals)]
        results = race(specs)
        assert sorted(status for status, _ in results) == ["accepted", "rejected"], results
        claimed_owner = admin.execute("SELECT customer_id FROM public.orders WHERE id=%s", (guest["order_id"],)).fetchone()[0]
        assert claimed_owner in (proof.customer_id for proof in principals)
        assert admin.execute("SELECT user_id FROM public.audit_logs WHERE action='order.customer_claimed' ORDER BY id DESC LIMIT 1").fetchone() == (None,)

        # Real SMTP sink, same encrypted work after lost wakeup, terminal erasure.
        email = "p19-sink@example.invalid"
        service.request_magic(email, replace(browser, ip="127.0.7.19"))
        work_id, magic_token = mail(email)
        mailer = MailWork(cipher, app_key=key)
        assert mailer.deliver(work_id)
        assert admin.execute("SELECT status,payload,attempts FROM public.customer_auth_mail_works WHERE id=%s", (work_id,)).fetchone() == ("sent", None, 1)
        magic_proof = service.consume_magic(magic_token, browser)
        assert magic_proof.customer_id == admin.execute("SELECT id FROM public.customers WHERE email=%s", (email,)).fetchone()[0]
        reject(lambda: service.consume_magic(magic_token, browser))
        fresh_magic = Browser("4" * 64, "127.0.7.4", **{name: getattr(magic_proof, name)
            for name in ("customer_id", "auth_version", "authenticated_at", "method")})
        enrolled = service.set_password(password, fresh_magic)
        assert enrolled.customer_id == magic_proof.customer_id and enrolled.auth_version == magic_proof.auth_version + 1
        assert service.login(email, password, browser).customer_id == magic_proof.customer_id

        mail_faults = exercise_mail_faults(service, admin, mail, cipher, browser)
        http = exercise_http(data, admin, mail, service, claimed_owner)
        assert snapshot() == original, "Authentication changed money/stock/history"
        return {"password_compatibility": "PHP raw and sha384-v1 same-Customer; raw rehashed only after proof",
                "shared_challenge": "one verification winner; purpose/expiry/replay denied",
                "registration_race": "one Customer and one grant, no overwrite",
                "otp": "browser binding, durable five-failure race, success and replay denial",
                "provider_ownership": "one competing Google subject owner; email collision no merge; same subject same Customer",
                "telegram": "private two-sided proof, separate browser consume, canonical conflict and replay denial",
                "guest_claim": "one claimant, original Recovery Key retained, no admin audit actor",
                "mail": "encrypted existing ledger, actual disposable SMTP ACK, terminal plaintext erased",
                "mail_recovery": mail_faults,
                "financial_history": "unchanged exact snapshot", **http}
    finally:
        admin.close()
        connections.close_all()


def exercise_mail_faults(service, admin, mail, cipher, browser):
    from concurrent.futures import ThreadPoolExecutor
    from threading import Event
    from lhie_candidate.auth.mail import LocalMailSink, MailWork
    from lhie_candidate.auth.types import AuthError, legacy_now
    from django.db import connections
    entered, release = Event(), Event()
    base = legacy_now()
    email = "p19-mail-lease@example.invalid"
    service.request_magic(email, replace(browser, ip="127.0.9.19"))
    work_id, capability = mail(email)

    class DelayedSink:
        def send(self, email, purpose, token):
            assert token == capability
            entered.set()
            assert release.wait(timeout=15)
            LocalMailSink().send(email, purpose, token)

    class UnavailableSink:
        def send(self, email, purpose, token):
            assert token == capability
            raise TimeoutError("private SMTP diagnostic")

    def delayed():
        try:
            return MailWork(cipher, app_key=service.app_key, clock=lambda: base,
                            transport=DelayedSink()).deliver(work_id)
        finally:
            connections.close_all()

    with ThreadPoolExecutor(max_workers=1) as executor:
        future = executor.submit(delayed)
        try:
            assert entered.wait(timeout=10)
            newer = MailWork(cipher, app_key=service.app_key, clock=lambda: base + timedelta(seconds=121),
                             transport=UnavailableSink())
            try:
                newer.deliver(work_id)
            except AuthError:
                pass
            else:
                raise AssertionError("Expected SMTP failure")
        finally:
            release.set()
        assert future.result(timeout=10)
    assert admin.execute("SELECT status,attempts,payload IS NOT NULL,last_error_code FROM public.customer_auth_mail_works WHERE id=%s",
                         (work_id,)).fetchone() == ("pending", 2, True, "mail_delivery_failed")
    # A stale successful ACK cannot erase the newer attempt's durable retry.
    recovered = MailWork(cipher, app_key=service.app_key, clock=lambda: base + timedelta(seconds=242))
    recovered.recover(limit=100)
    assert admin.execute("SELECT status,attempts,payload FROM public.customer_auth_mail_works WHERE id=%s",
                         (work_id,)).fetchone() == ("sent", 3, None)
    return "lost wakeup recovery; same capability retried; stale successful sender fenced"


def exercise_http(data, admin, mail, service, claimed_owner):
    import http.client
    from http.cookies import SimpleCookie
    import os
    import threading


    from wsgiref.simple_server import make_server
    from django.core.wsgi import get_wsgi_application
    from django.db import connections
    from django.test import override_settings
    from serve import BoundedServer, QuietHandler

    class Client:
        def __init__(self):
            self.cookies, self.csrf = {}, None
            self.headers = None

        def request(self, path, data=None, *, csrf=True):
            connection = http.client.HTTPConnection("127.0.0.1", server.server_port, timeout=10)
            headers = {"Cookie": "; ".join(name + "=" + value for name, value in self.cookies.items())}
            if data is not None:
                data = dict(data)
                if csrf and self.csrf:
                    data["csrfmiddlewaretoken"] = self.csrf
                headers["Content-Type"] = "application/x-www-form-urlencoded"
            connection.request("POST" if data is not None else "GET", path,
                               urlencode(data).encode() if data is not None else None, headers)
            response = connection.getresponse()
            body, status = response.read(), response.status
            self.headers = response.headers
            for name, value in response.getheaders():
                if name.lower() == "set-cookie":
                    parsed = SimpleCookie(value)
                    for cookie, item in parsed.items():
                        assert item["secure"] and item["httponly"] and item["samesite"] == "Lax"
                        self.cookies[cookie] = item.value
            connection.close()
            if response.headers.get_content_type() == "application/json":
                payload = json.loads(body)
                if "csrf_token" in payload:
                    self.csrf = payload["csrf_token"]
            else:
                payload = body.decode()
            assert "Synthetic-Auth42!" not in body.decode()
            return status, payload

        def refresh(self):
            status, payload = self.request("/akun")
            assert status == 200, (status, payload)
            return payload

    discard = open(os.devnull, "w")
    server = make_server("127.0.0.1", 8080, get_wsgi_application(), server_class=BoundedServer, handler_class=QuietHandler)
    server.discard = discard

    def serve():
        try:
            server.serve_forever(poll_interval=0.05)
        finally:
            connections.close_all()

    thread = threading.Thread(target=serve)
    thread.start()
    try:
        customer, other, guest = Client(), Client(), Client()
        assert customer.refresh()["customer"] is None
        pre_auth = dict(customer.cookies)
        denied_post = customer.request("/akun/login", {"email": data["raw"]["email"], "password": data["password"]}, csrf=False)
        assert denied_post[0] == 403, denied_post
        assert customer.request("/akun/login", {"email": data["raw"]["email"], "password": data["password"]})[0] == 200
        assert customer.cookies != pre_auth
        assert customer.refresh()["customer"] == {"id": data["raw"]["id"]}
        stale = Client()
        stale.cookies = pre_auth
        assert stale.refresh()["customer"] is None
        assert customer.request("/admin")[0] in (401, 403)
        assert customer.request("/admin/login", {"email": data["admin"]["email"], "password": data["password"]})[0] == 200
        customer.refresh()
        assert customer.request("/admin")[0] == 200
        assert customer.refresh()["customer"] == {"id": data["raw"]["id"]}
        assert customer.request("/akun/logout", {})[0] == 200
        assert customer.refresh()["customer"] is None
        assert customer.request("/admin")[0] == 200
        assert customer.request("/akun/login", {"email": data["raw"]["email"], "password": data["password"]})[0] == 200
        customer.refresh()

        assert other.refresh()["customer"] is None
        assert other.request("/akun/login", {"email": data["raw"]["email"], "password": data["password"]})[0] == 200
        other.refresh()
        assert other.request("/akun/pesanan/" + data["guest"]["order_id"])[0] == (200 if claimed_owner == data["raw"]["id"] else 404)
        guest.refresh()
        assert guest.request("/akses-pesanan", {"invoice_number": data["guest"]["invoice"], "access_key": data["guest"]["key"].lower()})[0] == 200
        assert guest.request("/akses-pesanan/" + data["guest"]["order_id"])[0] == 200
        assert guest.headers["Cache-Control"] == "no-store, private"
        assert guest.headers["Referrer-Policy"] == "no-referrer"
        assert guest.request("/akun/pesanan/" + data["guest"]["order_id"])[0] == 401

        # Existing PHP signed links remain byte-compatible; tampering never grants access.
        parts = urlsplit(data["signed_url"])
        with override_settings(CANDIDATE_LEGACY_APP_URL=parts.scheme + "://" + parts.netloc):
            signed = parts.path + "?" + parts.query
            assert guest.request(signed)[0] == 200
            assert guest.request(signed + "&tampered=1")[0] == 403
        providers = exercise_http_providers(Client, server.server_port, admin, service, parts.path.rsplit("/", 1)[1])

        # Old outstanding reset links work, then version-fence every other Customer session.
        # Mail request uses a separate account/IP budget to avoid unrelated delivery cooldown.
        from lhie_candidate.auth.types import Browser
        email = data["raw"]["email"]
        from lhie_candidate.auth.limits import RateLimits
        # Clear only this disposable test's delivery cooldown, not a shared runtime reset.
        limit_key = "candidate:auth:mail-cooldown:"
        from lhie_candidate.compat.identity import application_hmac
        service.limits.client.command("DEL", limit_key + application_hmac(email, service.app_key))
        service.request_reset(email, Browser("f" * 64, "127.0.8.19"))
        work_id, token = mail(email, "password_reset")
        import re
        import smtplib
        from unittest.mock import patch
        delivered = []
        original_send = smtplib.SMTP.send_message

        def observe_message(smtp, message, *args, **kwargs):
            delivered.append(message.get_content())
            return original_send(smtp, message, *args, **kwargs)

        with patch.object(smtplib.SMTP, "send_message", observe_message):
            assert service.mail.deliver(work_id)
        destination = urlsplit(re.search(r"https://\S+", delivered[0])[0])
        assert parse_qs(destination.fragment)["token"] == [token]
        assert customer.request(destination.path)[0] == 200
        assert admin.execute("SELECT consumed_at FROM public.customer_auth_tokens WHERE token_hash=%s",
                             (hashlib.sha256(token.encode()).hexdigest(),)).fetchone() == (None,)
        assert customer.request(destination.path, {"token": token, "password": "New-Synthetic42!",
                                                              "password_confirmation": "New-Synthetic42!"})[0] == 200
        assert customer.refresh()["customer"] is None
        assert other.refresh()["customer"] is None
        assert customer.request("/admin")[0] == 200
        assert guest.request("/akses-pesanan/" + data["guest"]["order_id"])[0] == 200
        customer.refresh()
        assert customer.request("/akun/password-reset/confirm", {"token": token, "password": "New-Synthetic42!",
                                                              "password_confirmation": "New-Synthetic42!"})[0] == 403
        assert customer.request("/akun/login", {"email": email, "password": "New-Synthetic42!"})[0] == 200
        customer.refresh()
        assert customer.request("/admin/logout", {})[0] == 200
        assert customer.refresh()["customer"] == {"id": data["raw"]["id"]}
        assert customer.request("/admin")[0] in (401, 403)
        return {"http": "real WSGI CSRF/fixation/cross-guard/login/reset/recovery/old-signature proof",
                "session_revocation": "reset revokes other Customer sessions; admin and Recovery Key remain valid",
                **providers}
    finally:
        server.shutdown()
        thread.join(timeout=5)
        server.server_close()
        discard.close()


def exercise_http_providers(Client, port, admin, service, order_id):
    import http.client
    from unittest.mock import patch
    from lhie_candidate.auth.google import GoogleAuthentication
    from lhie_candidate.auth.telegram import TelegramAuthentication
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.navigation.processor import NavigationProcessor
    from lhie_candidate.navigation.types import TelegramResult
    from lhie_candidate.navigation.webhook import webhook_secret

    owner, actor = admin.execute("SELECT o.customer_id,c.telegram_id FROM public.orders o "
                                "JOIN public.customers c ON c.id=o.customer_id WHERE o.id=%s", (order_id,)).fetchone()
    actor = str(actor)

    class Transport:
        def __init__(self):
            self.calls = []

        def execute(self, method, payload):
            self.calls.append((method, payload))
            return TelegramResult(695, "success")

    def webhook(update):
        connection = http.client.HTTPConnection("127.0.0.1", port, timeout=10)
        connection.request("POST", "/webhook/telegram", json.dumps(update).encode(), {
            "Content-Type": "application/json", "X-Telegram-Bot-Api-Secret-Token": webhook_secret()})
        result = connection.getresponse()
        body = result.read()
        connection.close()
        assert result.status == 200, body

    with patch("lhie_candidate.auth.telegram.TelegramBotIdentity",
               return_value=SimpleNamespace(username=lambda: "LhieStoreTestBot")):
        tg = TelegramAuthentication(app_key=service.app_key, limits=service.limits)
        transport = Transport()
        processor = NavigationProcessor(None, None, None, transport, authentication=tg,
                                         auth_encrypter=LaravelEncrypter(service.app_key))
        browser = Client()
        browser.refresh()
        status, started = browser.request("/akun/telegram/login", {})
        assert status == 200, (status, started)
        capability = parse_qs(urlsplit(started["deep_link"]).query)["start"][0]
        update = {"update_id": 695001, "message": {"message_id": 695,
                  "from": {"id": int(actor), "is_bot": False},
                  "chat": {"id": int(actor), "type": "private"}, "text": "/start " + capability}}
        before = admin.execute("SELECT count(*) FROM public.customers").fetchone()
        webhook(update)
        stored = admin.execute("SELECT payload FROM django_candidate.navigation_updates WHERE update_id=695001").fetchone()[0]
        assert "_encrypted_web_auth" in stored and capability not in json.dumps(stored)
        assert processor.process(695001) == "auth_proof"
        confirmation = transport.calls[-1][1]["reply_markup"]["inline_keyboard"][0][0]["callback_data"]
        assert browser.request("/akun/telegram/consume", {})[0] == 403
        callback = {"update_id": 695002, "callback_query": {"id": "p19-callback", "data": confirmation,
                    "from": update["message"]["from"], "message": {"message_id": 695, "chat": update["message"]["chat"]}}}
        webhook(callback)
        assert processor.process(695002) == "auth_proof"
        assert browser.request("/akun/telegram/status")[1]["status"] == "confirmed"
        assert browser.request("/akun/telegram/consume", {})[0] == 200
        assert browser.refresh()["customer"] == {"id": owner}
        assert browser.request("/akun/pesanan/" + order_id)[0] == 200
        assert admin.execute("SELECT count(*) FROM public.customers").fetchone() == before
        assert admin.execute("SELECT count(*) FROM django_candidate.navigation_updates WHERE update_id IN (695001,695002) "
                             "AND payload ? '_encrypted_web_auth'").fetchone() == (0,)
        assert processor.process(695002) == "duplicate"

    # An actual HTTP callback uses the admitted provider seam, not email-based account matching.
    proof_owner = admin.execute("SELECT customer_id FROM public.customer_identities WHERE provider='google' "
                                "AND provider_subject='p19-shared-subject'").fetchone()[0]
    provider = GoogleAuthentication(app_key=service.app_key, limits=service.limits,
        provider=GoogleFixture("p19-shared-subject", "p19-new-evidence@example.invalid"))
    google = Client()
    google.refresh()
    with patch("lhie_candidate.auth.http.google_service", return_value=provider):
        assert google.request("/auth/google/redirect")[0] == 302
        state = parse_qs(urlsplit(google.headers["Location"]).query)["state"][0]
        assert google.request("/auth/google/callback?" + urlencode({"state": state, "code": "synthetic"}))[0] == 200
        assert google.refresh()["customer"] == {"id": proof_owner}
        assert google.request("/akun/pesanan")[0] == 200
        assert google.request("/akun/pesanan/" + order_id)[0] == 404
    return {"provider_http": "Google callback and encrypted Telegram webhook/private confirmation/browser consumption reach owned reads"}
