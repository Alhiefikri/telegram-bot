"""Bounded cleanup of consumed/expired customer authentication challenges.

Mail work is the retention owner for its encrypted payload and FK references.
This cleanup therefore refuses to delete a token or link while a
``customer_auth_mail_works`` row still references it, including an in-flight
lease. Claims are deleted only after their terminal state is durable.
"""

from django.db import connections, transaction

JAKARTA = "Asia/Jakarta"


def _count(cursor, sql, params=()):
    cursor.execute(sql, params)
    return int(cursor.rowcount)


def cleanup_auth_challenges(limit=500, *, legacy_only=False, using="default"):
    if type(limit) is not int or not 1 <= limit <= 1000:
        raise ValueError("Cleanup limit must be between 1 and 1000")
    if type(legacy_only) is not bool:
        raise ValueError("Invalid legacy-only flag")
    with transaction.atomic(using=using), connections[using].cursor() as cursor:
        cursor.execute("SELECT CURRENT_TIMESTAMP AT TIME ZONE %s", (JAKARTA,))
        now = cursor.fetchone()[0]
        tokens = 0
        if not legacy_only:
            tokens = _count(cursor, """WITH page AS (
                SELECT t.id FROM public.customer_auth_tokens t
                WHERE (t.consumed_at IS NOT NULL OR t.expires_at <= %s)
                  AND NOT EXISTS (SELECT 1 FROM public.customer_auth_mail_works w
                      WHERE w.auth_token_id=t.id)
                ORDER BY t.id LIMIT %s FOR UPDATE)
                DELETE FROM public.customer_auth_tokens target USING page
                WHERE target.id=page.id""", (now, limit))
        links = _count(cursor, """WITH page AS (
            SELECT l.id FROM public.customer_login_links l
            WHERE (l.used_at IS NOT NULL OR l.expires_at <= %s)
              AND NOT EXISTS (SELECT 1 FROM public.customer_auth_mail_works w
                  WHERE w.login_link_id=l.id)
            ORDER BY l.id LIMIT %s FOR UPDATE)
            DELETE FROM public.customer_login_links target USING page
            WHERE target.id=page.id""", (now, limit))
        telegram_claims = google_claims = 0
        if not legacy_only:
            telegram_claims = _count(cursor, """WITH page AS (
                SELECT c.id FROM public.customer_telegram_auth_claims c
                WHERE (c.consumed_at IS NOT NULL OR c.failure IS NOT NULL OR c.expires_at <= %s)
                ORDER BY c.id LIMIT %s FOR UPDATE)
                DELETE FROM public.customer_telegram_auth_claims target USING page
                WHERE target.id=page.id""", (now, limit))
            google_claims = _count(cursor, """WITH page AS (
                SELECT c.id FROM public.customer_google_auth_claims c
                WHERE (c.consumed_at IS NOT NULL OR c.expires_at <= %s)
                ORDER BY c.id LIMIT %s FOR UPDATE)
                DELETE FROM public.customer_google_auth_claims target USING page
                WHERE target.id=page.id""", (now, limit))
    return {"tokens": tokens, "login_links": links, "telegram_claims": telegram_claims,
            "google_claims": google_claims, "limit": limit, "legacy_only": legacy_only}
