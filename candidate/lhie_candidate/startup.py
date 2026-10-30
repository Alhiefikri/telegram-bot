import logging
import os
import sys
import warnings


FAILURE = "candidate startup refused\n"


def suppress_payload_logging():
    logging.raiseExceptions = False
    logging.disable(sys.maxsize)
    warnings.showwarning = lambda *args, **kwargs: None
    sys.tracebacklimit = 0
    sys.excepthook = lambda *args: sys.stderr.write(FAILURE)


def configure_django():
    suppress_payload_logging()
    os.environ["DJANGO_SETTINGS_MODULE"] = "lhie_candidate.settings"
    import django
    django.setup()
    from .domain.money import self_check
    self_check()


def create_application():
    configure_django()
    from django.core.wsgi import get_wsgi_application
    from .readiness import verify_dependencies
    verify_dependencies()
    return SafeApplication(get_wsgi_application())


class SafeApplication:
    def __init__(self, application):
        self.application = application

    def __call__(self, environ, start_response):
        try:
            return self.application(environ, start_response)
        except Exception:
            body = b'{"status":"error"}'
            start_response("500 Internal Server Error", [
                ("Content-Type", "application/json"),
                ("Content-Length", str(len(body))),
                ("Cache-Control", "no-store"),
            ])
            return [body]
