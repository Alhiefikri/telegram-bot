"""Small PostgreSQL transaction helpers shared by the admin services."""

import json

from django.db import DatabaseError, transaction


_RETRYABLE = frozenset({"40001", "40P01"})


def _sqlstate(error):
    cause = error.__cause__
    return getattr(cause, "pgcode", None) or getattr(error, "pgcode", None)


def bounded_transaction(operation, *, attempts=5):
    """Retry only serialization/deadlock failures of the whole closure."""
    for attempt in range(attempts):
        try:
            with transaction.atomic():
                return operation()
        except DatabaseError as error:
            if _sqlstate(error) not in _RETRYABLE or attempt + 1 >= attempts:
                raise
    raise RuntimeError("Admin transaction retry limit reached")


def unique_slug_conflict(error):
    cause = error.__cause__
    constraint = getattr(getattr(cause, "diag", None), "constraint_name", None)
    return (
        getattr(cause, "pgcode", None) == "23505"
        and isinstance(constraint, str)
        and "slug" in constraint
        and ("categories" in constraint or "product_families" in constraint)
    )


def audit(db, actor_id, action, subject_type, subject_id, old, new, ip=None):
    """Persist sanitized JSON only; callers must never pass credential values."""
    db.execute(
        """INSERT INTO public.audit_logs
           (user_id,action,subject_type,subject_id,old_values,new_values,ip_address,created_at)
           VALUES (%s,%s,%s,%s,%s::jsonb,%s::jsonb,%s,
                   CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta')""",
        (
            actor_id,
            action,
            subject_type,
            None if subject_id is None else str(subject_id),
            None if old is None else json.dumps(old, ensure_ascii=False, separators=(",", ":")),
            None if new is None else json.dumps(new, ensure_ascii=False, separators=(",", ":")),
            ip,
        ),
    )


def available_stock_sql(product_alias="p"):
    """PHP Product::availableStock() for local stock, bounded by real rows."""
    prefix = product_alias + "."
    return f"""CASE WHEN EXISTS (SELECT 1 FROM public.product_stock_items s
                   WHERE s.product_id={prefix}id AND s.supplier_purchase_id IS NULL)
      THEN LEAST(GREATEST(0,{prefix}stock-{prefix}reserved_stock),
        (SELECT COUNT(*) FROM public.product_stock_items s
         WHERE s.product_id={prefix}id AND s.supplier_purchase_id IS NULL
           AND s.order_item_id IS NULL AND s.retired_at IS NULL))
      ELSE GREATEST(0,{prefix}stock-{prefix}reserved_stock) END"""


def local_item_count(db, product_id):
    row = db.one(
        """SELECT COUNT(*) FILTER (WHERE supplier_purchase_id IS NULL
               AND supplier_unit_index IS NULL) AS total,
             COUNT(*) FILTER (WHERE supplier_purchase_id IS NULL
               AND supplier_unit_index IS NULL AND order_item_id IS NULL
               AND retired_at IS NULL) AS available,
             COUNT(*) FILTER (WHERE supplier_purchase_id IS NULL
               AND supplier_unit_index IS NULL AND retired_at IS NOT NULL) AS retired
           FROM public.product_stock_items WHERE product_id=%s""",
        (product_id,),
    )
    return {key: int(value or 0) for key, value in row.items()}


def local_available(db, product):
    """Clamp the numeric projection to real, unallocated, non-retired rows."""
    counts = local_item_count(db, product["id"])
    projected = max(0, int(product["stock"]) - int(product["reserved_stock"]))
    if counts["total"]:
        return min(projected, counts["available"])
    return projected
