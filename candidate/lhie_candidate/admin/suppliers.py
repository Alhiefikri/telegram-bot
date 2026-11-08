"""Supplier recovery, warranty and operator evidence surfaces.

Only actions whose evidence is supplied by the operator are exposed here.
Purchase dispatch/recovery that requires a supplier network read stays with the
P18 worker and is never triggered implicitly by rendering an admin page.
"""

from datetime import datetime

from ..checkout.db import Database
from ..checkout.errors import CheckoutError
from ..compat.crypto import LaravelEncrypter
from ..runtime import application_key
from ..suppliers.acquisition import SupplierAcquisition
from ..suppliers.manual import ManualSupplierRecovery
from ..suppliers.warranty import HistoricalWarranty
from .sanitize import mask_identifier, safe_error

CURRENCY_RATE_KEYS = ("USD", "USDT", "VND")


def _time(value):
    if isinstance(value, datetime):
        return value.strftime("%d %b %Y %H:%M")
    return "-" if value is None else str(value)


def _decimal(value):
    if value in (None, ""):
        return ""
    whole, fraction = divmod(int(value), 1_000_000)
    return f"{whole}.{fraction:06d}".rstrip("0").rstrip(".")


class SupplierAdmin:
    def __init__(self, *, using="default"):
        self.using = using
        self.db = Database(using)
        self.encrypter = LaravelEncrypter(application_key())

    def manual_credentials(self, fulfillment_id, credentials, *, actor_id):
        recovery = ManualSupplierRecovery(self.encrypter, using=self.using)
        return recovery.provide_manual_credentials(
            int(fulfillment_id), credentials, actor_user_id=actor_id)

    def no_debit(self, fulfillment_id, *, actor_id, purchase_id, evidence_source,
                 provider_reference, note):
        acquisition = SupplierAcquisition(
            self.encrypter, None, using=self.using)
        return acquisition.confirm_no_debit(
            int(fulfillment_id), actor_user_id=actor_id,
            expected_purchase_id=int(purchase_id), evidence_source=evidence_source,
            provider_reference=provider_reference, note=note)

    def owner_no_debit(self, fulfillment_id, *, actor_id, purchase_id):
        acquisition = SupplierAcquisition(self.encrypter, None, using=self.using)
        return acquisition.confirm_owner_no_debit(
            int(fulfillment_id), actor_user_id=actor_id,
            expected_purchase_id=int(purchase_id))

    def confirm_external_refund(self, refund_id, *, actor_id, amount, currency,
                                reference, result):
        warranty = HistoricalWarranty(using=self.using, encrypter=self.encrypter)
        return warranty.confirm_external(
            str(refund_id), actor_user_id=actor_id, amount=int(amount),
            currency=currency, reference=reference, result=result)


class SupplierReads:
    def __init__(self, using="default"):
        self.using = using
        self.db = Database(using)

    def connections(self):
        rows = self.db.rows(
            """SELECT id,name,provider,currency,currency_confirmed,exchange_rate_idr,
                      balance_micros,reserved_balance_micros,is_active,version,
                      low_balance_threshold_micros,synced_at,sync_expires_at,
                      balance_observed_at,last_error
               FROM public.suppliers ORDER BY id""")
        for row in rows:
            balance = row["balance_micros"]
            available = (None if balance is None
                         else max(0, int(balance) - int(row["reserved_balance_micros"] or 0)))
            row["balance_label"] = "Belum diketahui" if balance is None else _decimal(balance)
            row["held_label"] = _decimal(row["reserved_balance_micros"])
            row["available_label"] = ("Belum diketahui" if available is None
                                      else _decimal(available))
            row["low_balance"] = (
                available is not None
                and available <= int(row["low_balance_threshold_micros"] or 0))
            row["synced_label"] = _time(row["synced_at"])
            row["observed_label"] = _time(row["balance_observed_at"])
            row["error_code"] = safe_error(row["last_error"]) or "-"
        return rows

    def rates(self):
        rows = self.db.rows(
            "SELECT currency,exchange_rate_idr,version,updated_at FROM public.supplier_currency_rates ORDER BY currency")
        found = {row["currency"]: row for row in rows}
        result = []
        for currency in CURRENCY_RATE_KEYS:
            row = found.get(currency)
            result.append({
                "currency": currency,
                "rate": "" if row is None else row["exchange_rate_idr"],
                "version": 0 if row is None else int(row["version"]),
                "updated_label": _time(row["updated_at"] if row else None),
            })
        return result

    def mappings(self, limit=50):
        return self.db.rows(
            """SELECT s.id,s.product_id,s.supplier_catalog_item_id,s.priority,s.is_active,
                      s.version,p.name AS product_name,c.name AS catalog_name,
                      c.external_id,sup.name AS supplier_name
               FROM public.product_supplier_sources s
               JOIN public.products p ON p.id=s.product_id
               JOIN public.supplier_catalog_items c ON c.id=s.supplier_catalog_item_id
               JOIN public.suppliers sup ON sup.id=c.supplier_id
               ORDER BY p.name,s.priority,s.id LIMIT %s""", (limit,))

    def purchases(self, *, invoice=None, page=1, limit=25):
        page = max(1, int(page or 1))
        needle = None
        if isinstance(invoice, str):
            cleaned = "".join(char for char in invoice.upper().strip()[:80]
                              if char.isalnum() or char == "-")
            needle = cleaned if len(cleaned) >= 3 else None
        where, params = [], []
        if needle:
            where.append("o.invoice_number LIKE %s")
            params.append(needle + "%")
        clause = (" WHERE " + " AND ".join(where)) if where else ""
        total = self.db.one(
            "SELECT COUNT(*) AS n FROM public.supplier_fulfillments f "
            "JOIN public.orders o ON o.id=f.order_id" + clause, tuple(params))["n"]
        rows = self.db.rows(
            """SELECT f.id,f.order_id,f.order_item_id,f.quantity,f.state,f.maximum_cost_idr,
                      f.reserved_cost_micros,f.reserved_quantity,f.refunded_quantity,
                      f.current_purchase_id,f.last_error,f.created_at,
                      o.invoice_number,o.order_status,o.payment_status,
                      i.product_name,
                      sup.name AS supplier_name,
                      p.id AS purchase_id,p.state AS purchase_state,p.supplier_order_id,
                      p.quantity AS purchase_quantity,p.expected_cost_micros,
                      p.actual_cost_micros,p.committed_at,p.last_error AS purchase_error,
                      (SELECT COUNT(*) FROM public.supplier_manual_handoffs h
                       WHERE h.supplier_fulfillment_id=f.id) AS handoff_count,
                      (SELECT COALESCE(SUM(h.quantity),0) FROM public.supplier_manual_handoffs h
                       WHERE h.supplier_fulfillment_id=f.id) AS handoff_quantity
               FROM public.supplier_fulfillments f
               JOIN public.orders o ON o.id=f.order_id
               JOIN public.order_items i ON i.id=f.order_item_id
               LEFT JOIN public.suppliers sup ON sup.id=f.reserved_supplier_id
               LEFT JOIN public.supplier_purchases p ON p.id=f.current_purchase_id""" + clause +
            " ORDER BY f.id DESC LIMIT %s OFFSET %s",
            tuple(params + [limit, (page - 1) * limit]))
        for row in rows:
            row["id"] = int(row["id"])
            row["order_id"] = str(row["order_id"])
            row["invoice_number"] = row["invoice_number"]
            row["state_label"] = {
                "reserved": "Dipesan", "purchasing": "Membeli", "review": "Perlu review",
                "delivered": "Terkirim", "cancelled": "Dibatalkan",
                "refund_pending": "Refund pending",
            }.get(row["state"], row["state"])
            row["purchase_state_label"] = {
                "ambiguous": "Ambigu", "review": "Perlu review", "settled": "Debit tercatat",
                "delivered": "Selesai", "rejected": "Ditolak", "sending": "Mengirim",
            }.get(row["purchase_state"], row["purchase_state"] or "-")
            row["expected_cost_label"] = _decimal(row["expected_cost_micros"])
            row["actual_cost_label"] = _decimal(row["actual_cost_micros"])
            row["reserved_cost_label"] = _decimal(row["reserved_cost_micros"])
            row["remaining"] = max(
                0, int(row["quantity"] or 0) - int(row["refunded_quantity"] or 0)
                - int(row["handoff_quantity"] or 0))
            row["can_manual"] = row["state"] == "review" and row["remaining"] > 0
            row["can_no_debit"] = (row["purchase_state"] in ("ambiguous", "review")
                                   and row["purchase_id"] is not None)
            row["error_code"] = (safe_error(row["purchase_error"])
                                 or safe_error(row["last_error"]) or "-")
        pages = max(1, (int(total) + limit - 1) // limit)
        return {"rows": rows, "page": page, "pages": pages, "total": int(total),
                "has_previous": page > 1, "has_next": page < pages,
                "previous": page - 1, "next": page + 1, "invoice": needle or ""}

    def external_refunds(self, limit=50):
        rows = self.db.rows(
            """SELECT r.id,r.order_id,r.amount,r.currency,r.state,r.external_reference,
                      r.created_at,o.invoice_number,p.provider,p.status AS payment_state,
                      c.telegram_id,c.username,
                      (r.warranty_claim_id IS NOT NULL) AS warranty,
                      (r.supplier_fulfillment_id IS NOT NULL) AS missing_fulfillment
               FROM public.order_refunds r
               JOIN public.orders o ON o.id=r.order_id
               JOIN public.payments p ON p.id=r.payment_id
               JOIN public.customers c ON c.id=r.customer_id
               WHERE r.state IN ('requested','uncertain')
                 AND p.provider NOT IN ('store_balance','usdt_balance')
               ORDER BY r.created_at DESC,r.id LIMIT %s""", (limit,))
        for row in rows:
            row["id"] = str(row["id"])
            row["order_id"] = str(row["order_id"])
            row["external_reference"] = mask_identifier(row["external_reference"], visible=6)
            row["created_label"] = _time(row["created_at"])
            row["reason_label"] = ("Warranty" if row["warranty"]
                                   else "Missing fulfillment" if row["missing_fulfillment"]
                                   else "Remainder")
            row["provider"] = str(row["provider"] or "").lower()
        return rows

    def history(self, limit=25):
        rows = self.db.rows(
            """SELECT h.id,h.supplier_fulfillment_id,h.quantity,h.note,h.handed_off_at,
                      u.name AS actor_name,f.order_id,o.invoice_number
               FROM public.supplier_manual_handoffs h
               JOIN public.supplier_fulfillments f ON f.id=h.supplier_fulfillment_id
               JOIN public.orders o ON o.id=f.order_id
               LEFT JOIN public.users u ON u.id=h.actor_user_id
               ORDER BY h.id DESC LIMIT %s""", (limit,))
        for row in rows:
            row["handed_off_label"] = _time(row["handed_off_at"])
            row["actor"] = row["actor_name"] or "operator"
        return rows
