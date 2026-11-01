from django.db import connections


class Database:
    def __init__(self, using="default"):
        self.using = using

    def require_transaction(self):
        connection = connections[self.using]
        if connection.vendor != "postgresql" or not connection.in_atomic_block:
            raise RuntimeError("Checkout writes require a PostgreSQL atomic transaction")

    def rows(self, sql, params=()):
        with connections[self.using].cursor() as cursor:
            cursor.execute(sql, params)
            columns = [column[0] for column in cursor.description]
            return [dict(zip(columns, row)) for row in cursor.fetchall()]

    def one(self, sql, params=()):
        rows = self.rows(sql, params)
        return rows[0] if rows else None

    def execute(self, sql, params=()):
        with connections[self.using].cursor() as cursor:
            cursor.execute(sql, params)
            return cursor.rowcount
