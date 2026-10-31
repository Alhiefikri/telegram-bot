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
            observed = cursor.fetchone()
            expected = (
                "disposable", "candidate_runtime", "127.0.0.1", 5432, False,
                False, False, False, False, 4, "django_candidate", "candidate_runtime",
                False, False,
            )
            if observed is not None and observed[-2] is True:
                verify_navigation_grants(cursor)
                expected = (*expected[:-2], True, False)
            if observed != expected:
                raise ReadinessError()
    except Exception:
        raise ReadinessError() from None
    finally:
        connection.close()


def verify_navigation_grants(cursor):
    cursor.execute(
        "SELECT to_regclass('django_candidate.navigation_roots') IS NOT NULL, "
        "to_regclass('django_candidate.navigation_updates') IS NOT NULL, "
        "to_regclass('django_candidate.navigation_assets') IS NOT NULL"
    )
    if cursor.fetchone() != (True, True, True):
        raise ReadinessError()
    # Public-schema writes remain closed except narrow Telegram identity registration.
    cursor.execute(
        "SELECT c.relname FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace "
        "WHERE n.nspname='public' AND c.relkind IN ('r','p') AND ("
        "has_table_privilege(current_user,c.oid,'INSERT,UPDATE,DELETE,TRUNCATE,TRIGGER') "
        "OR EXISTS (SELECT 1 FROM pg_catalog.pg_attribute a WHERE a.attrelid=c.oid "
        "AND a.attnum>0 AND NOT a.attisdropped AND ("
        "has_column_privilege(current_user,c.oid,a.attnum,'INSERT') OR "
        "has_column_privilege(current_user,c.oid,a.attnum,'UPDATE')) "
        "AND NOT (c.relname='customers' AND a.attname IN "
        "('telegram_id','first_name','username','locale','created_at','updated_at'))))"
    )
    if cursor.fetchone() is not None:
        raise ReadinessError()


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
