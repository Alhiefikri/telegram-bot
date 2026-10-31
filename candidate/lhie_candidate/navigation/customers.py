"""Telegram identity registration never searches by email or username."""
from django.db import connection, transaction


def resolve_customer(repository, actor):
    identity = actor["id"]
    customer = repository.customer(identity)
    with transaction.atomic():
        with connection.cursor() as cursor:
            # Legacy identity triggers resolve unqualified public relations. Keep
            # that compatibility search path local to this short profile write.
            cursor.execute("SET LOCAL search_path = pg_catalog, public, pg_temp")
            if customer is None:
                cursor.execute(
                    "INSERT INTO public.customers (telegram_id,first_name,username,locale,created_at,updated_at) "
                    "VALUES (%s,%s,%s,'id',timezone('Asia/Jakarta',clock_timestamp()),timezone('Asia/Jakarta',clock_timestamp())) "
                    "ON CONFLICT (telegram_id) DO NOTHING",
                    [identity, actor.get("first_name") or "Telegram Customer", actor.get("username")],
                )
            cursor.execute(
                "UPDATE public.customers SET first_name=%s,username=%s,locale=COALESCE(locale,'id'),"
                "updated_at=timezone('Asia/Jakarta',clock_timestamp()) WHERE telegram_id=%s",
                [actor.get("first_name") or "Telegram Customer", actor.get("username"), identity],
            )
    customer = repository.customer(identity)
    return customer
