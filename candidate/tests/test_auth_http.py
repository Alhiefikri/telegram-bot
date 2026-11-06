import copy
from contextlib import nullcontext
from datetime import datetime
import os
import re
import unittest
from types import SimpleNamespace
from unittest.mock import Mock, patch

from lhie_candidate.runtime import Runtime


class MetadataDatabase:
    using = "default"

    def __init__(self):
        self.sessions = {}
        self.customer_rows = {
            7: {"id": 7, "email": "synthetic@example.test", "email_verified_at": datetime(2026, 1, 1),
                "telegram_id": None, "auth_version": 0, "first_name": "Synthetic", "username": None},
        }
        self.identities = []

    def one(self, sql, params=()):
        if "browser_sessions" in sql:
            row = self.sessions.get(params[0])
            return {"metadata": copy.deepcopy(row[0])} if row and row[1] > params[1] else None
        if "public.customers" in sql:
            return copy.deepcopy(self.customer_rows.get(params[0]))
        if "customer_identities" in sql:
            if len(params) == 1:
                return next((row for row in self.identities if row["customer_id"] == params[0]
                             and row["provider"] == "google"), None)
            if "provider='telegram'" in sql:
                return next((row for row in self.identities if row["customer_id"] == params[0]
                             and row["provider"] == "telegram" and row["provider_subject"] == params[1]), None)
            return next((row for row in self.identities if row["id"] == params[0]
                         and row["customer_id"] == params[1] and row["provider"] == "google"), None)
        raise AssertionError(sql)

    def execute(self, sql, params=()):
        import json
        if sql.startswith("INSERT INTO django_candidate.browser_sessions"):
            self.sessions[params[0]] = (json.loads(params[1]), params[2])
            return 1
        if sql.startswith("UPDATE django_candidate.browser_sessions"):
            row = self.sessions.get(params[1])
            if row and row[1] > params[2]:
                self.sessions[params[1]] = (json.loads(params[0]), row[1])
                return 1
            return 0
        if sql.startswith("DELETE FROM django_candidate.browser_sessions"):
            return int(self.sessions.pop(params[0], None) is not None)
        raise AssertionError(sql)


class AuthHttpTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        import django
        os.environ["DJANGO_SETTINGS_MODULE"] = "lhie_candidate.settings"
        with patch("lhie_candidate.runtime.load_runtime", return_value=Runtime("synthetic-empty", "a" * 64)):
            django.setup()

    def setUp(self):
        from django.test import Client
        from lhie_candidate.auth.types import Principal
        self.db = MetadataDatabase()
        self.core = Mock()
        self.core.enabled.return_value = True
        self.core.login.side_effect = lambda *args: Principal(7, self.db.customer_rows[7]["auth_version"], 1793754000, "password")
        self.core.verify.side_effect = self.core.login.side_effect
        self.core.request_otp.return_value = "opaque-synthetic-challenge"
        self.bundle = SimpleNamespace(core=self.core)
        self.patches = [patch("lhie_candidate.auth.sessions.Database", return_value=self.db),
                        patch("lhie_candidate.auth.sessions.transaction.atomic", side_effect=lambda **kwargs: nullcontext()),
                        patch("lhie_candidate.storefront.account_auth.Database", return_value=self.db),
                        patch("lhie_candidate.storefront.account.Database", return_value=self.db),
                        patch("lhie_candidate.storefront.account._account_summary", return_value={
                            "name": "Synthetic", "initial": "S", "username": None,
                            "email": "synthetic@example.test", "joined": None,
                            "counts": {"total": 0, "waiting": 0, "processing": 0, "completed": 0, "issue": 0},
                            "idr": "Rp 0", "usdt": "0 USDT", "frozen": False}),
                        patch("lhie_candidate.auth.http.services", return_value=self.bundle),
                        patch("lhie_candidate.auth.http.google_service", return_value=SimpleNamespace(ready=lambda: False)),
                        patch("lhie_candidate.auth.http.telegram_service", return_value=SimpleNamespace(ready=lambda: False))]
        for item in self.patches:
            item.start()
            self.addCleanup(item.stop)
        self.client = Client(enforce_csrf_checks=True, raise_request_exception=False,
                             HTTP_HOST="localhost", HTTP_ORIGIN="https://localhost")

    def csrf(self, client=None):
        response = (client or self.client).get("/akun", secure=True)
        match = re.search(rb'name="csrfmiddlewaretoken" value="([^"]+)"', response.content)
        self.assertIsNotNone(match, "Account page must contain a CSRF-protected form")
        return match.group(1).decode()

    def post(self, path, values=None, client=None):
        client = client or self.client
        return client.post(path, {"csrfmiddlewaretoken": self.csrf(client), **(values or {})}, secure=True)

    def session_metadata(self, client=None):
        from lhie_candidate.compat.identity import sha256_digest
        token = (client or self.client).cookies["candidate_browser"].value
        return self.db.sessions[sha256_digest(token)][0]

    def test_customer_identity_follows_session_and_does_not_expose_email(self):
        guest = self.client.get("/akun/identitas", secure=True)
        self.assertEqual(guest.json(), {"authenticated": False, "name": None, "initial": None})
        self.post("/akun/login", {"email": "synthetic@example.test", "password": "synthetic"})
        identity = self.client.get("/akun/identitas", secure=True)
        self.assertEqual(identity.json(), {"authenticated": True, "name": "Synthetic", "initial": "S"})
        self.assertNotIn(b"synthetic@example.test", identity.content)
        self.assertEqual(identity["Cache-Control"], "no-store, private")
        self.db.customer_rows[7]["auth_version"] += 1
        self.assertFalse(self.client.get("/akun/identitas", secure=True).json()["authenticated"])


    def test_csrf_is_session_bound_and_authentication_destroys_old_identifier(self):
        from django.test import Client
        from lhie_candidate.compat.identity import sha256_digest
        old_csrf = self.csrf()
        old_id = self.client.cookies["candidate_browser"].value
        attacker = Client(enforce_csrf_checks=True, HTTP_HOST="localhost", HTTP_ORIGIN="https://localhost")
        foreign_csrf = self.csrf(attacker)
        for token in (None, foreign_csrf):
            values = {"email": "synthetic@example.test", "password": "synthetic"}
            if token:
                values["csrfmiddlewaretoken"] = token
            self.assertEqual(self.client.post("/akun/login", values, secure=True).status_code, 403)
        self.core.login.assert_not_called()
        result = self.client.post("/akun/login", {"csrfmiddlewaretoken": old_csrf,
                                 "email": "synthetic@example.test", "password": "synthetic"}, secure=True)
        self.assertEqual(result.status_code, 302)
        self.assertEqual(result["Location"], "/akun/pesanan")
        self.assertNotEqual(old_id, self.client.cookies["candidate_browser"].value)
        self.assertNotIn(sha256_digest(old_id), self.db.sessions)
        self.assertTrue(result.cookies["candidate_browser"]["secure"])
        self.assertTrue(result.cookies["candidate_browser"]["httponly"])
        self.assertEqual(result.cookies["candidate_browser"]["samesite"], "Lax")
        self.assertEqual(self.client.post("/akun/logout", {"csrfmiddlewaretoken": old_csrf}, secure=True).status_code, 403)
        self.assertEqual(self.client.get("/akun/keamanan", secure=True).status_code, 200)
        self.assertNotIn("synthetic", result.content.decode())

    def test_cross_guard_logout_reset_and_guest_grants_remain_independent(self):
        from lhie_candidate.auth.admin import AdminAuthentication
        self.bundle.admin = AdminAuthentication(limits=Mock(), db=Mock())
        self.csrf()
        metadata = self.session_metadata()
        metadata["admin"] = {"id": 92, "password_digest": "synthetic-admin-binding"}
        metadata["orders"] = {"synthetic-order": 9999999999}
        self.assertEqual(self.post("/akun/login", {"email": "synthetic@example.test", "password": "synthetic"}).status_code, 302)
        self.assertEqual(self.session_metadata()["admin"]["id"], 92)
        self.assertEqual(self.post("/admin/logout").status_code, 200)
        self.assertNotIn("admin", self.session_metadata())
        self.assertEqual(self.client.get("/akun/keamanan", secure=True).status_code, 200)
        self.session_metadata()["admin"] = {"id": 92}
        reset = self.post("/akun/password-reset/confirm", {"token": "synthetic", "password": "Synthetic1!",
                                                          "password_confirmation": "Synthetic1!"})
        self.assertEqual((reset.status_code, reset["Location"]), (302, "/akun"))
        self.assertNotIn("customer", self.session_metadata())
        self.assertEqual(self.session_metadata()["admin"]["id"], 92)
        self.assertIn("synthetic-order", self.session_metadata()["orders"])

    def test_version_and_nullable_email_provider_markers_are_checked_each_request(self):
        self.post("/akun/login", {"email": "synthetic@example.test", "password": "synthetic"})
        self.db.customer_rows[7]["auth_version"] += 1
        self.assertEqual(self.client.get("/akun/keamanan", secure=True).status_code, 302)
        self.assertNotIn("customer", self.session_metadata())
        self.post("/akun/login", {"email": "synthetic@example.test", "password": "synthetic"})
        self.db.customer_rows[7]["email_verified_at"] = None
        self.session_metadata()["customer"]["method"] = "google:13"
        self.db.identities = [{"id": 13, "customer_id": 999, "provider": "google"}]
        self.assertEqual(self.client.get("/akun/keamanan", secure=True).status_code, 302)
        self.db.customer_rows[7]["email_verified_at"] = datetime(2026, 1, 1)
        self.post("/akun/login", {"email": "synthetic@example.test", "password": "synthetic"})
        self.db.customer_rows[7]["email_verified_at"] = None
        self.session_metadata()["customer"]["method"] = "google:13"
        self.db.identities[0]["customer_id"] = 7
        self.assertEqual(self.client.get("/akun/keamanan", secure=True).status_code, 200)
        self.db.identities.clear()
        self.assertEqual(self.client.get("/akun/keamanan", secure=True).status_code, 302)

    def test_confirmation_get_never_consumes_or_reflects_tokens(self):
        for path in ("/akun/verifikasi-email?token=private-sentinel", "/akun/password-reset/confirm?token=private-sentinel",
                     "/akun/login-link?token=private-sentinel"):
            response = self.client.get(path, secure=True)
            self.assertEqual(response.status_code, 200)
            self.assertNotIn(b"private-sentinel", response.content)
            self.assertIn(b'form method="post"', response.content)
        self.core.verify.assert_not_called()
        self.core.reset.assert_not_called()
        self.core.consume_magic.assert_not_called()

    def test_unknown_and_known_otp_request_responses_are_identical(self):
        first = self.post("/akun/login-code", {"email": "known@example.test"})
        first_page = self.client.get(first["Location"], secure=True)
        self.core.request_otp.return_value = None
        second = self.post("/akun/login-code", {"email": "unknown@example.test"})
        second_page = self.client.get(second["Location"], secure=True)
        def normalize(response):
            return re.sub(rb'(name="csrfmiddlewaretoken" value=")[^"]+', rb'\1CSRF', response.content)
        self.assertEqual((first.status_code, first["Location"]), (second.status_code, second["Location"]))
        self.assertEqual((first_page.status_code, second_page.status_code), (200, 200))
        self.assertEqual(normalize(first_page), normalize(second_page))
        self.assertNotIn("challenge", second.content.decode())

    def test_native_confirmation_post_keeps_same_origin_csrf_checks(self):
        from django.test import Client
        client = Client(enforce_csrf_checks=True, HTTP_HOST="localhost")
        token = self.csrf(client)
        form = client.get("/akun/verifikasi-email", secure=True)
        self.assertEqual(form["Referrer-Policy"], "same-origin")
        values = {"csrfmiddlewaretoken": token, "token": "synthetic"}
        foreign = client.post("/akun/verifikasi-email", values, secure=True, HTTP_ORIGIN="https://foreign.invalid")
        self.assertEqual(foreign.status_code, 403)
        self.core.verify.assert_not_called()
        same = client.post("/akun/verifikasi-email", values, secure=True,
                           HTTP_REFERER="https://localhost/akun/verifikasi-email")
        self.assertEqual(same.status_code, 302)

    def test_postgresql_json_text_session_reload_keeps_guard_and_csrf(self):
        import json
        self.post("/akun/login", {"email": "synthetic@example.test", "password": "synthetic"})
        original = self.db.one

        def encoded_metadata(sql, params=()):
            row = original(sql, params)
            if row and "browser_sessions" in sql:
                return {"metadata": json.dumps(row["metadata"])}
            return row

        self.db.one = encoded_metadata
        self.assertEqual(self.client.get("/akun/keamanan", secure=True).status_code, 200)
        self.assertEqual(self.post("/akun/logout").status_code, 302)
        self.assertEqual(self.client.get("/akun/keamanan", secure=True).status_code, 302)


class OrderBoundaryTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        AuthHttpTests.setUpClass()

    def test_recovery_is_order_scoped_expiring_and_limits_fail_closed(self):
        from lhie_candidate.auth.ownership import OrderAccess
        from lhie_candidate.auth.sessions import Session
        from lhie_candidate.auth.types import AuthError
        order_id = "e8b56ad4-b6cc-4b3e-b735-77488f9573c0"
        db, limits = Mock(), Mock()
        row = {"id": order_id, "invoice_number": "INV-SYNTHETIC", "customer_id": 8,
               "payment_status": "paid", "status": "completed", "credential_access_key_hash": "synthetic", "checkout_key": None}
        db.one.return_value = row
        session = SimpleNamespace(data={}, browser=lambda request: SimpleNamespace(ip="127.0.0.1", session_id="synthetic"))
        session.set = lambda key, value: session.data.__setitem__(key, value)
        request = SimpleNamespace(auth_session=session)
        access = OrderAccess(limits=limits, app_key="synthetic", db=db, timestamp=lambda: 100)
        with patch.object(access, "_key", return_value=True):
            result = access.recovery(request, " inv-synthetic ", "synthetic")
        self.assertEqual(session.data["orders"][order_id], 1900)
        self.assertNotIn("customer_id", result)
        self.assertNotIn("credential_access_key_hash", result)
        self.assertEqual(access.recovered(request, order_id), result)
        access.timestamp = lambda: 1900
        with self.assertRaises(AuthError):
            access.recovered(request, order_id)
        limits.reserve.side_effect = AuthError("unavailable", 503)
        with self.assertRaises(AuthError) as raised:
            access.recovery(request, "INV-SYNTHETIC", "synthetic")
        self.assertEqual(raised.exception.status, 503)

    def test_signed_url_preserves_raw_mac_expiry_and_never_discloses_key(self):
        from django.test import RequestFactory, override_settings
        from lhie_candidate.auth.ownership import OrderAccess
        from lhie_candidate.auth.types import AuthError
        from lhie_candidate.compat.identity import signed_url_digest
        order_id = "e8b56ad4-b6cc-4b3e-b735-77488f9573c0"
        path = "/shop/orders/" + order_id
        unsigned = path + "?expires=200&label=a%20b"
        mac = signed_url_digest(unsigned, "synthetic")
        db = Mock()
        db.one.return_value = {"id": order_id, "invoice_number": "INV", "payment_status": "paid", "status": "completed"}
        access = OrderAccess(limits=Mock(), app_key="synthetic", db=db, timestamp=lambda: 100)
        with override_settings(ALLOWED_HOSTS=["testserver"]):
            request = RequestFactory().get(unsigned + "&signature=" + mac)
            self.assertEqual(access.signed(request, order_id)["id"], order_id)
            tampered = RequestFactory().get(unsigned.replace("a%20b", "a+b") + "&signature=" + mac)
            with self.assertRaises(AuthError):
                access.signed(tampered, order_id)
            access.timestamp = lambda: 201
            with self.assertRaises(AuthError):
                access.signed(request, order_id)

    def test_claim_rejects_any_provider_authenticated_owner(self):
        from lhie_candidate.auth.ownership import OrderAccess
        from lhie_candidate.auth.types import AuthError
        actor = {"id": 7, "auth_version": 0}
        guest = {"id": 8, "email": None, "telegram_id": None, "password": None, "email_verified_at": None}
        row = {"id": "e8b56ad4-b6cc-4b3e-b735-77488f9573c0", "invoice_number": "INV",
               "customer_id": 8, "payment_status": "paid", "status": "completed"}
        db = Mock(using="default")
        db.rows.return_value = [actor, guest]
        db.one.side_effect = [{"customer_id": 8}, row, {"id": 99}]
        session = SimpleNamespace(browser=lambda request: SimpleNamespace(auth_version=0, ip="127.0.0.1"))
        request = SimpleNamespace(auth_session=session, META={"REMOTE_ADDR": "127.0.0.1"})
        access = OrderAccess(limits=Mock(), app_key="synthetic", db=db)
        with patch("lhie_candidate.auth.ownership.customer", return_value=actor), \
                patch("lhie_candidate.auth.ownership.transaction.atomic", return_value=nullcontext()), \
                patch.object(access, "_key", return_value=True):
            with self.assertRaises(AuthError):
                access.claim(request, "INV", "synthetic")
        db.execute.assert_not_called()

    def test_owned_order_denies_foreign_customer_without_recovery_bypass(self):
        from lhie_candidate.auth.ownership import OrderAccess
        from lhie_candidate.auth.types import AuthError
        db = Mock()
        db.one.return_value = {"id": "e8b56ad4-b6cc-4b3e-b735-77488f9573c0", "customer_id": 8}
        access = OrderAccess(limits=Mock(), app_key="synthetic", db=db)
        with patch("lhie_candidate.auth.ownership.customer", return_value={"id": 7}):
            with self.assertRaises(AuthError) as raised:
                access.owned(SimpleNamespace(), db.one.return_value["id"])
        self.assertEqual(raised.exception.status, 404)
