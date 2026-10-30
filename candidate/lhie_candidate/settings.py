from pathlib import Path

from .runtime import load_runtime


BASE_DIR = Path(__file__).resolve().parent.parent
RUNTIME = load_runtime()
SECRET_KEY = RUNTIME.secret_key
DEBUG = False
ALLOWED_HOSTS = ["127.0.0.1", "localhost", "[::1]"]
ROOT_URLCONF = "lhie_candidate.urls"
WSGI_APPLICATION = "lhie_candidate.wsgi.application"
INSTALLED_APPS = ["django.contrib.staticfiles"]
MIDDLEWARE = [
    "django.middleware.security.SecurityMiddleware",
    "lhie_candidate.http.PayloadSafeMiddleware",
]
DATABASES = {
    "default": {
        "ENGINE": "django.db.backends.postgresql",
        "NAME": "disposable",
        "USER": "candidate_runtime",
        "PASSWORD": "candidate-local-only",
        "HOST": "127.0.0.1",
        "PORT": "5432",
        "CONN_MAX_AGE": 0,
        "CONN_HEALTH_CHECKS": False,
        "ATOMIC_REQUESTS": False,
        "OPTIONS": {
            "connect_timeout": 2,
            "application_name": "lhie_candidate",
            "options": "-c search_path=django_candidate -c statement_timeout=2000 "
                       "-c lock_timeout=1000 -c idle_in_transaction_session_timeout=3000",
        },
    }
}
DATABASE_ROUTERS = ["lhie_candidate.metadata.MetadataRouter"]
TEMPLATES = [{
    "BACKEND": "django.template.backends.django.DjangoTemplates",
    "DIRS": [BASE_DIR / "templates"],
    "APP_DIRS": True,
    "OPTIONS": {"context_processors": []},
}]
STATIC_URL = "/static/"
STATIC_ROOT = Path("/tmp/candidate-static")
STATICFILES_DIRS = [BASE_DIR / "static"]
STORAGES = {
    "default": {"BACKEND": "django.core.files.storage.InMemoryStorage"},
    "staticfiles": {"BACKEND": "django.contrib.staticfiles.storage.ManifestStaticFilesStorage"},
}
DEFAULT_AUTO_FIELD = "django.db.models.BigAutoField"
USE_TZ = True
TIME_ZONE = "UTC"
LANGUAGE_CODE = "en-us"
APPEND_SLASH = False
PREPEND_WWW = False
DATA_UPLOAD_MAX_MEMORY_SIZE = 16384
FILE_UPLOAD_MAX_MEMORY_SIZE = 16384
DATA_UPLOAD_MAX_NUMBER_FIELDS = 32
DATA_UPLOAD_MAX_NUMBER_FILES = 0
SESSION_ENGINE = "django.contrib.sessions.backends.signed_cookies"
SESSION_COOKIE_HTTPONLY = True
SESSION_COOKIE_SECURE = True
SESSION_COOKIE_SAMESITE = "Strict"
CSRF_COOKIE_HTTPONLY = True
CSRF_COOKIE_SECURE = True
CSRF_COOKIE_SAMESITE = "Strict"
SECURE_CONTENT_TYPE_NOSNIFF = True
SECURE_REFERRER_POLICY = "no-referrer"
SECURE_CROSS_ORIGIN_OPENER_POLICY = "same-origin"
LOGGING = {
    "version": 1,
    "disable_existing_loggers": True,
    "handlers": {"discard": {"class": "logging.NullHandler"}},
    "root": {"handlers": ["discard"], "level": "CRITICAL"},
    "loggers": {
        name: {"handlers": ["discard"], "propagate": False, "level": "CRITICAL"}
        for name in ("django", "django.request", "django.server", "django.security", "py.warnings")
    },
}
ADMINS = []
MANAGERS = []
EMAIL_BACKEND = "django.core.mail.backends.dummy.EmailBackend"
