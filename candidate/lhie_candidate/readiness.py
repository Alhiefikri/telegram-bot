import socket

from django.db import connections


class ReadinessError(Exception):
    def __init__(self):
        super().__init__("candidate dependencies unavailable")


def verify_database():
    connection = connections["default"]
    try:
        with connection.cursor() as cursor:
            cursor.execute(
                "SELECT pg_catalog.current_database(), current_user, "
                "pg_catalog.host(pg_catalog.inet_server_addr()), pg_catalog.inet_server_port(), "
                "pg_catalog.pg_is_in_recovery(), r.rolsuper, r.rolcreatedb, "
                "r.rolcreaterole, r.rolinherit, r.rolconnlimit, "
                "pg_catalog.current_setting('search_path'), "
                "pg_catalog.pg_get_userbyid(n.nspowner), "
                "pg_catalog.has_schema_privilege(current_user, 'public', 'USAGE'), "
                "pg_catalog.has_schema_privilege(current_user, 'public', 'CREATE') "
                "FROM pg_catalog.pg_roles r, pg_catalog.pg_namespace n "
                "WHERE r.rolname = current_user AND n.nspname = 'django_candidate'"
            )
            if cursor.fetchone() != (
                "disposable", "candidate_runtime", "127.0.0.1", 5432, False,
                False, False, False, False, 4, "django_candidate", "candidate_runtime",
                False, False,
            ):
                raise ReadinessError()
    except Exception:
        raise ReadinessError() from None
    finally:
        connection.close()


def verify_redis():
    try:
        with socket.create_connection(("127.0.0.1", 6379), timeout=1) as connection:
            connection.settimeout(1)
            connection.sendall(b"*1\r\n$4\r\nPING\r\n")
            result = bytearray()
            while len(result) < 7:
                chunk = connection.recv(7 - len(result))
                if not chunk:
                    break
                result.extend(chunk)
            if result != b"+PONG\r\n":
                raise ReadinessError()
    except Exception:
        raise ReadinessError() from None


def verify_dependencies():
    verify_database()
    verify_redis()
