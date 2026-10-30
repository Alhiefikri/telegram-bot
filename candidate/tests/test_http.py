import contextlib
import io
import logging
import os
import unittest
from unittest.mock import patch

from lhie_candidate.runtime import Runtime


class HttpTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        import django
        os.environ["DJANGO_SETTINGS_MODULE"] = "lhie_candidate.settings"
        with patch("lhie_candidate.runtime.load_runtime", return_value=Runtime("synthetic-empty", "a" * 64)):
            django.setup()
        from django.test import Client
        cls.client = Client(raise_request_exception=False, HTTP_HOST="127.0.0.1")

    def test_health_is_liveness_without_database_or_redis_io(self):
        with patch("lhie_candidate.http.verify_dependencies") as verify:
            result = self.client.get("/health")
        self.assertEqual(result.status_code, 200)
        self.assertEqual(result.json(), {"status": "ok"})
        self.assertEqual(result["Cache-Control"], "no-store")
        self.assertEqual(result["X-Content-Type-Options"], "nosniff")
        self.assertNotIn("Set-Cookie", result)
        verify.assert_not_called()
        self.assertEqual(self.client.head("/health").content, b"")

    def test_readiness_returns_safe_503_on_identity_or_connection_failure(self):
        with patch("lhie_candidate.http.verify_dependencies", side_effect=ValueError("sensitive-sentinel")):
            result = self.client.get("/ready?token=sensitive-sentinel")
        self.assertEqual(result.status_code, 503)
        self.assertEqual(result.json(), {"status": "not_ready"})
        with patch("lhie_candidate.http.verify_dependencies") as verify:
            result = self.client.get("/ready")
        self.assertEqual(result.status_code, 200)
        self.assertEqual(result.json(), {"status": "ready"})
        verify.assert_called_once_with()

    def test_query_path_headers_body_and_errors_are_never_logged_or_reflected(self):
        stdout, stderr, logs = io.StringIO(), io.StringIO(), io.StringIO()
        handler = logging.StreamHandler(logs)
        logging.getLogger().addHandler(handler)
        try:
            with contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr), patch(
                "lhie_candidate.http.verify_dependencies", side_effect=RuntimeError("exception-sensitive-sentinel")
            ):
                results = [
                    self.client.get("/path-sensitive-sentinel?token=query-sensitive-sentinel",
                                    HTTP_AUTHORIZATION="Bearer header-sensitive-sentinel"),
                    self.client.post("/health?token=query-sensitive-sentinel", data="body-sensitive-sentinel",
                                     content_type="application/json", HTTP_COOKIE="session=header-sensitive-sentinel"),
                    self.client.get("/ready?token=query-sensitive-sentinel",
                                    HTTP_AUTHORIZATION="Bearer header-sensitive-sentinel"),
                    self.client.get("/health", HTTP_HOST="host-sensitive-sentinel.invalid"),
                ]
        finally:
            logging.getLogger().removeHandler(handler)
        self.assertEqual([result.status_code for result in results], [404, 405, 503, 400])
        for result in results:
            self.assertNotIn(b"sensitive-sentinel", result.content)
            self.assertEqual(result["X-Content-Type-Options"], "nosniff")
            self.assertEqual(result["Referrer-Policy"], "no-referrer")
            self.assertNotIn("sensitive-sentinel", str(dict(result.items())))
        self.assertEqual(stdout.getvalue() + stderr.getvalue() + logs.getvalue(), "")

    def test_view_exception_returns_fixed_500_without_payload(self):
        from django.test import override_settings
        from django.urls import path

        def explode(request):
            raise RuntimeError("exception-sensitive-sentinel")

        class ErrorUrls:
            urlpatterns = [path("explode", explode)]

        with override_settings(ROOT_URLCONF=ErrorUrls):
            result = self.client.get("/explode?token=query-sensitive-sentinel")
        self.assertEqual(result.status_code, 500)
        self.assertEqual(result.json(), {"status": "error"})

    def test_unknown_slashes_and_methods_do_not_redirect_or_run_work(self):
        with patch("lhie_candidate.http.verify_dependencies") as verify:
            self.assertEqual(self.client.get("/health/").status_code, 404)
            self.assertEqual(self.client.post("/ready").status_code, 405)
            self.assertEqual(self.client.options("/health").status_code, 405)
        verify.assert_not_called()


    def test_wsgi_head_is_bodyless_without_test_client_head_normalization(self):
        from django.core.wsgi import get_wsgi_application
        from django.test import RequestFactory
        from unittest.mock import MagicMock
        application = get_wsgi_application()
        start = MagicMock()
        result = application(RequestFactory().head("/health", HTTP_HOST="127.0.0.1").environ, start)
        try:
            self.assertEqual(b"".join(result), b"")
            self.assertEqual(start.call_args.args[0], "200 OK")
            self.assertEqual(dict(start.call_args.args[1])["Content-Length"], str(len(b'{"status": "ok"}')))
        finally:
            result.close()
