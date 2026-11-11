"""Order, Payment and refund-facing operator read models and mutations.

The read side is presentation only. Every mutation delegates to the existing
Order lifecycle/payment owners; no transition is reimplemented here and no
Payment/Order projection is written directly.
"""

import hashlib
import json
import re
from datetime import datetime

from django.db import transaction

from ..checkout.db import Database
from ..checkout.errors import CheckoutError
from ..checkout.lifecycle import OrderLifecycle, now as lifecycle_now
from ..compat.crypto import LaravelEncrypter, CryptoError
from ..receipt.source import ReceiptSource
from ..runtime import application_key
from .sanitize import json_value, mask_identifier, safe_error
from .reporting import _fee, _gross, _net

FILTERS = {
    "all": None,
    "completed": ("completed",),
    "issue": ("cancelled", "expired"),
    "waiting": ("draft", "pending_payment"),
    "processing": ("processing",),
}

FILTER_LABELS = (
    ("all", "Semua"),
    ("completed", "Selesai"),
    ("issue", "Masalah"),
    ("waiting", "Menunggu"),
    ("processing", "Diproses"),
)

PAYMENT_STATUSES = ("pending", "paid", "failed", "expired", "refunded")
RECONCILABLE = ("klikqris", "sakurupiah", "pakasir")


class _NoPresentation:
    def manual(self):
        raise ValueError("presentation unavailable")


def normalize_filter(value):
    return value if value in FILTERS else "all"


def invoice_search(value):
    if not isinstance(value, str):
        return "", False
    needle = "".join(char for char in value.upper().strip()[:80] if char.isalnum() or char == "-")
    if not needle:
        return "", False
    if len(needle) < 3:
        return "", True
    return needle, False


def _customer_label(row):
    first, username = row.get("first_name") or "", row.get("username") or ""
    if first and username:
        return f"{first} (@{username})"
    return first or (f"@{username}" if username else "Pelanggan")


def _format_time(value):
    if isinstance(value, datetime):
        return value.strftime("%d %b %Y %H:%M")
    return "-" if value is None else str(value)


def _status_label(status):
    return {
        "draft": "Draft",
        "pending_payment": "Menunggu pembayaran",
        "processing": "Diproses",
        "completed": "Selesai",
        "cancelled": "Dibatalkan",
        "expired": "Kedaluwarsa",
    }.get(status, status or "-")


def _status_tone(status):
    return {
        "completed": "good",
        "processing": "warning",
        "pending_payment": "info",
        "draft": "muted",
        "cancelled": "danger",
        "expired": "danger",
    }.get(status, "muted")


def _payment_label(status):
    return {
        "pending": "Menunggu",
        "paid": "Lunas",
        "failed": "Gagal",
        "expired": "Kedaluwarsa",
        "refunded": "Refund",
    }.get(status, status or "-")


def _payment_tone(status):
    return {"paid": "good", "pending": "info", "failed": "danger",
            "expired": "danger", "refunded": "muted"}.get(status, "muted")


def _ip(request):
    value = request.META.get("REMOTE_ADDR")
    return value if isinstance(value, str) and len(value) <= 64 else None


def _audit(db, actor_id, action, order_id, values=None, ip=None):
    db.execute(
        """INSERT INTO public.audit_logs
           (user_id,action,subject_type,subject_id,new_values,ip_address,created_at)
           VALUES (%s,%s,'App\\Models\\Order',%s,%s,%s,%s)""",
        (actor_id, action, str(order_id),
         None if values is None else json.dumps(values, ensure_ascii=False, separators=(",", ":")),
         ip, lifecycle_now()),
    )


class OrderOperations:
    def __init__(self, *, using="default", payments=None, encrypter=None):
        self.using = using
        self.db = Database(using)
        self.lifecycle = OrderLifecycle(using=using, encrypter=encrypter)
        self.payments = payments

    def has_pending_binance_mismatch(self, order_id):
        payment = self.db.one(
            """SELECT id,provider_payload,status FROM public.payments
               WHERE order_id=%s AND provider='manual' AND method='BINANCE_PAY_ID'
               ORDER BY created_at,id LIMIT 1""", (str(order_id),))
        if payment is None or payment["status"] != "pending":
            return False
        payload = payment["provider_payload"]
        if isinstance(payload, str):
            try:
                payload = json.loads(payload)
            except ValueError:
                payload = None
        if not isinstance(payload, dict):
            return False
        return bool(payload.get("transactionId") and payload.get("amount")
                    and payload.get("currency"))

    def complete_manual(self, order_id, actor_id):
        if not self.has_pending_binance_mismatch(order_id):
            raise CheckoutError("binance_mismatch_evidence_required")
        result = self.lifecycle.apply_manual_completion(order_id, actor_user_id=actor_id)
        return result

    def reject_manual(self, order_id, actor_id):
        if not self.has_pending_binance_mismatch(order_id):
            raise CheckoutError("binance_mismatch_evidence_required")
        return self.lifecycle.apply_manual_rejection(order_id, actor_user_id=actor_id)

    def cancel_release(self, order_id, actor_id):
        return self.lifecycle.apply_pending_cancellation(order_id, actor_user_id=actor_id)

    def resend_credential_mail(self, order_id, actor_id, request, ip=None):
        db = self.db
        cipher = LaravelEncrypter(application_key())
        with transaction.atomic(using=self.using):
            order = db.one(
                "SELECT id,payment_status,order_status FROM public.orders WHERE id=%s FOR UPDATE",
                (str(order_id),))
            if order is None or order["payment_status"] != "paid" or order["order_status"] not in (
                    "processing", "completed"):
                raise CheckoutError("credential_mail_not_eligible")
            event = db.one(
                """SELECT id,recipient_email,status,delivery_started_at
                   FROM public.order_notification_events
                   WHERE order_id=%s AND event_type='credentials_ready' FOR UPDATE""",
                (str(order_id),))
            if event is None or not event["recipient_email"]:
                raise CheckoutError("credential_mail_unavailable")
            try:
                recipient = cipher.decrypt_string(event["recipient_email"])
            except (CryptoError, ValueError, TypeError):
                raise CheckoutError("credential_mail_unavailable") from None
            if not recipient:
                raise CheckoutError("credential_mail_unavailable")
            if event["status"] in ("pending", "sending") or event["delivery_started_at"] is not None:
                raise CheckoutError("credential_mail_busy")
            safe_failure = event["status"] == "failed"
            if event["status"] != "sent" and not safe_failure:
                raise CheckoutError("credential_mail_review")
            db.execute(
                """UPDATE public.order_notification_events
                   SET status='pending',attempts=0,
                       next_attempt_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta',
                       delivery_started_at=NULL,failed_at=NULL,last_error=NULL,
                       updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'
                   WHERE id=%s""", (event["id"],))
            _audit(db, actor_id, "order.credential_mail_resent", order_id,
                   {"queue": "notifications"}, ip)
        return "queued"

    def reconcile(self, payment_id, actor_id, ip=None):
        payment = self.db.one(
            "SELECT id,order_id,provider,status,merchant_ref,provider_reference FROM public.payments WHERE id=%s",
            (str(payment_id),))
        if payment is None:
            raise CheckoutError("foreign_payment")
        if payment["status"] not in ("pending", "expired", "failed"):
            raise CheckoutError("payment_not_reconcilable")
        provider = str(payment["provider"] or "").lower()
        if provider not in RECONCILABLE:
            raise CheckoutError("payment_provider_not_reconcilable")
        if not payment["merchant_ref"]:
            raise CheckoutError("payment_not_reconcilable")
        router = self.payments
        if router is None:
            from ..payments.runtime import payment_services
            router = payment_services(using=self.using)
        result = router.refresh(str(payment["id"]))
        _audit(self.db, actor_id, "payment.manually_reconciled", payment["order_id"],
               {"payment_id": str(payment["id"]),
                "result": safe_error(getattr(result, "state", None))}, ip)
        return result


class OrderReads:
    def __init__(self, using="default"):
        self.using = using
        self.db = Database(using)

    def status_counts(self):
        rows = self.db.rows(
            "SELECT order_status, COUNT(*) AS n FROM public.orders GROUP BY order_status")
        return {row["order_status"]: int(row["n"]) for row in rows}

    def detail_for_payment(self, payment_id):
        row = self.db.one("SELECT order_id FROM public.payments WHERE id=%s", (str(payment_id),))
        return str(row["order_id"]) if row else None

    def filters(self, active, invoice=""):
        counts = self.status_counts()
        total = sum(counts.values())
        result = []
        for key, label in FILTER_LABELS:
            statuses = FILTERS[key]
            count = total if statuses is None else sum(counts.get(status, 0) for status in statuses)
            suffix = f"&invoice={invoice}" if invoice else ""
            result.append({
                "key": key,
                "label": label,
                "count": count,
                "active": key == active,
                "url": f"/admin?tab=pesanan&sales_filter={key}{suffix}",
            })
        return result

    def list_orders(self, filters, page=1, limit=10):
        active = normalize_filter(filters.get("sales_filter"))
        invoice, too_short = invoice_search(filters.get("invoice"))
        statuses = FILTERS[active]
        where, params = [], []
        if statuses is not None:
            where.append("o.order_status IN (" + ",".join(["%s"] * len(statuses)) + ")")
            params.extend(statuses)
        if invoice:
            where.append("o.invoice_number LIKE %s")
            params.append(invoice + "%")
        clause = (" WHERE " + " AND ".join(where)) if where else ""
        page = max(1, int(page or 1))
        limit = max(1, min(50, int(limit or 10)))
        total = self.db.one("SELECT COUNT(*) AS n FROM public.orders o" + clause,
                            tuple(params))["n"]
        rows = self.db.rows(
            "SELECT o.id,o.invoice_number,o.order_status,o.payment_status,o.total_amount,o.currency,"
            "o.created_at,o.expires_at,c.first_name,c.username,c.telegram_id,"
            "(SELECT COUNT(*) FROM public.order_items i WHERE i.order_id=o.id) AS item_count,"
            "(SELECT i.product_name FROM public.order_items i WHERE i.order_id=o.id ORDER BY i.id LIMIT 1) AS first_item,"
            "(SELECT p.provider FROM public.payments p WHERE p.order_id=o.id ORDER BY p.created_at DESC,p.id DESC LIMIT 1) AS provider,"
            "(SELECT p.method FROM public.payments p WHERE p.order_id=o.id ORDER BY p.created_at DESC,p.id DESC LIMIT 1) AS method,"
            "(SELECT p.status FROM public.payments p WHERE p.order_id=o.id ORDER BY p.created_at DESC,p.id DESC LIMIT 1) AS latest_payment_status,"
            "(SELECT COUNT(*) FROM public.manual_fulfillment_reviews r WHERE r.order_id=o.id AND r.state IN ('pending','refund_uncertain')) AS open_reviews,"
            "(SELECT COUNT(*) FROM public.telegram_outbound_effects e WHERE e.source_id=o.id::text AND e.effect_type='receipt.credentials' AND e.state IN ('unknown','manual_review')) AS delivery_reviews "
            "FROM public.orders o JOIN public.customers c ON c.id=o.customer_id" + clause +
            " ORDER BY o.created_at DESC,o.id DESC LIMIT %s OFFSET %s",
            tuple(params + [limit, (page - 1) * limit]))
        for row in rows:
            row["id"] = str(row["id"])
            row["customer"] = _customer_label(row)
            row["order_status_label"] = _status_label(row["order_status"])
            row["order_status_tone"] = _status_tone(row["order_status"])
            row["payment_status_label"] = _payment_label(row["payment_status"])
            row["payment_status_tone"] = _payment_tone(row["payment_status"])
            row["latest_payment_status_label"] = _payment_label(row["latest_payment_status"])
            row["created_label"] = _format_time(row["created_at"])
            row["item_label"] = row["first_item"] or "Produk"
            if int(row["item_count"] or 0) > 1:
                row["item_label"] += f" + {int(row['item_count']) - 1} produk"
            row["provider"] = str(row["provider"] or "").lower()
            row["url"] = f"/admin/orders/{row['id']}"
        pages = max(1, (int(total) + limit - 1) // limit)
        query = f"tab=pesanan&sales_filter={active}"
        if invoice:
            query += f"&invoice={invoice}"
        return {
            "rows": rows,
            "filters": self.filters(active, invoice),
            "active_filter": active,
            "invoice": invoice,
            "invoice_too_short": too_short,
            "pagination": {
                "current": page,
                "pages": pages,
                "total": int(total),
                "has_previous": page > 1,
                "has_next": page < pages,
                "previous_url": f"/admin?{query}&page={page - 1}" if page > 1 else None,
                "next_url": f"/admin?{query}&page={page + 1}" if page < pages else None,
            },
        }

    def detail(self, order_id):
        order = self.db.one(
            """SELECT o.id,o.customer_id,o.invoice_number,o.order_status,o.payment_status,
                      o.total_amount,o.currency,o.expires_at,o.created_at,o.updated_at,
                      o.telegram_receipt_sent_at,
                      (o.notification_email IS NOT NULL) AS has_email,
                      c.first_name,c.username,c.telegram_id,c.locale
               FROM public.orders o JOIN public.customers c ON c.id=o.customer_id
               WHERE o.id=%s""", (str(order_id),))
        if order is None:
            return None
        order["id"] = str(order["id"])
        order["customer"] = _customer_label(order)
        order["order_status_label"] = _status_label(order["order_status"])
        order["order_status_tone"] = _status_tone(order["order_status"])
        order["payment_status_label"] = _payment_label(order["payment_status"])
        order["payment_status_tone"] = _payment_tone(order["payment_status"])
        order["created_label"] = _format_time(order["created_at"])
        order["expires_label"] = _format_time(order["expires_at"])
        order["receipt_sent_label"] = _format_time(order.get("telegram_receipt_sent_at"))
        order["items"] = self._items(order["id"])
        order["payments"] = self._payments(order["id"])
        order["review"] = self.db.one(
            """SELECT id,reason,state,amount,currency,provider_reference,resolution_reason,
                      resolved_at,created_at
               FROM public.manual_fulfillment_reviews WHERE order_id=%s
               ORDER BY CASE WHEN state IN ('pending','refund_uncertain') THEN 0 ELSE 1 END,id DESC LIMIT 1""",
            (order["id"],))
        if order["review"]:
            order["review"]["provider_reference"] = mask_identifier(
                order["review"]["provider_reference"], visible=6)
        order["effects"] = self._effects(order["id"])
        order["sales_proof"] = self.db.one(
            "SELECT status,attempts,telegram_message_id,sent_at,last_error FROM public.sales_proof_posts WHERE order_id=%s",
            (order["id"],))
        order["can_retry_sales_proof"] = bool(
            order["sales_proof"] and order["sales_proof"]["status"] == "failed")
        order["supplier"] = self._supplier(order["id"])
        order["audit"] = self._audit(order["id"])
        order["can_complete_manual"] = (
            self._has_binance_mismatch(order["payments"])
            and self._can_complete_manual(order, order["payments"]))
        order["can_reject_manual"] = (
            self._has_binance_mismatch(order["payments"])
            and order["order_status"] == "pending_payment" and any(
                payment["provider"] == "manual" and payment["status"] == "pending"
                for payment in order["payments"]))
        order["can_cancel_release"] = (
            order["order_status"] == "pending_payment"
            and order["payment_status"] == "pending"
            and any(payment["status"] == "pending" for payment in order["payments"]))
        order["late_paid"] = (
            order["payment_status"] == "paid"
            and order["order_status"] in ("cancelled", "expired"))
        order["can_resend_mail"] = bool(order.get("has_email")) and (
            order["payment_status"] == "paid"
            and order["order_status"] in ("processing", "completed"))
        return order

    def _items(self, order_id):
        rows = self.db.rows(
            """SELECT i.id,i.product_name,i.product_icon,i.quantity,i.unit_price,i.subtotal,
                      i.local_quantity,i.supplier_quantity,
                      s.state AS fulfillment_state,s.reserved_quantity,s.refunded_quantity,
                      (SELECT COUNT(*) FROM public.product_stock_items si WHERE si.order_item_id=i.id) AS credential_count
               FROM public.order_items i
               LEFT JOIN public.supplier_fulfillments s ON s.order_item_id=i.id
               WHERE i.order_id=%s ORDER BY i.id""", (str(order_id),))
        for row in rows:
            row["remaining_quantity"] = max(
                0, int(row["quantity"] or 0) - int(row["refunded_quantity"] or 0))
            row["fulfillment_label"] = {
                "delivered": "Terkirim", "review": "Perlu review",
                "cancelled": "Dibatalkan", "refund_pending": "Menunggu refund",
            }.get(row["fulfillment_state"], "Stok lokal" if not row["fulfillment_state"] else "Diproses")
        return rows

    def _payments(self, order_id):
        rows = self.db.rows(
            "SELECT p.id,p.provider,p.method,p.provider_payment_method,p.amount,p.currency,"
            "p.status,p.paid_at,p.expired_at,p.provider_expires_at,p.provider_reference,"
            "p.merchant_ref,p.provider_create_state,p.provider_create_last_error,"
            "p.provider_total_payment,p.provider_fee,"
            "CASE WHEN p.provider='manual' AND p.method='BINANCE_PAY_ID' AND p.status='pending' "
            "AND COALESCE(p.provider_payload->>'transactionId','')<>'' "
            "AND COALESCE(p.provider_payload->>'amount','')<>'' "
            "AND COALESCE(p.provider_payload->>'currency','')<>'' THEN TRUE ELSE FALSE END AS binance_mismatch,"
            f"{_gross('p')} AS gross,{_fee('p')} AS fee,{_net('p')} AS net,"
            "(SELECT COUNT(*) FROM public.payment_events e WHERE e.payment_id=p.id) AS event_count "
            "FROM public.payments p WHERE p.order_id=%s ORDER BY p.created_at,p.id",
            (str(order_id),))
        for row in rows:
            row["id"] = str(row["id"])
            row["provider"] = str(row["provider"] or "").lower()
            row["merchant_ref_masked"] = mask_identifier(row["merchant_ref"], visible=6)
            row["provider_reference_masked"] = mask_identifier(row["provider_reference"], visible=6)
            row["merchant_ref_hash"] = hashlib.sha256(
                str(row["merchant_ref"] or "").encode()).hexdigest()[:16]
            row["status_label"] = _payment_label(row["status"])
            row["status_tone"] = _payment_tone(row["status"])
            row["paid_label"] = _format_time(row["paid_at"])
            row["expires_label"] = _format_time(row["provider_expires_at"] or row["expired_at"])
            row["create_state"] = safe_error(row["provider_create_state"]) or "-"
            row["create_error"] = safe_error(row["provider_create_last_error"]) or "-"
            row["can_reconcile"] = (
                row["status"] in ("pending", "expired", "failed")
                and row["provider"] in RECONCILABLE
                and bool(row["merchant_ref"]))
            row["events"] = self._events(row["id"])
        return rows

    def _events(self, payment_id):
        rows = self.db.rows(
            """SELECT event_type,provider_status,processed_at,received_at,received_count,last_received_at
               FROM public.payment_events WHERE payment_id=%s
               ORDER BY received_at DESC,id DESC LIMIT 10""", (str(payment_id),))
        for row in rows:
            row["event_type"] = safe_error(row["event_type"]) or "-"
            row["provider_status"] = safe_error(row["provider_status"]) or "-"
            row["received_label"] = _format_time(row["received_at"])
            row["processed_label"] = _format_time(row["processed_at"])
        return rows

    def _effects(self, order_id):
        rows = self.db.rows(
            """SELECT id,effect_type,state,attempts,telegram_method,locale,template_version,
                      sequence,unknown_at,manual_review_at,sent_at,last_error_code,supersedes_effect_id
               FROM public.telegram_outbound_effects
               WHERE source_id=%s AND effect_type IN ('receipt.credentials','receipt.txt_download',
                     'receipt.order_actions','sales_proof.post','admin.order_paid_notification',
                     'admin.order_pending_notification','admin.order_expired_notification')
               ORDER BY created_at DESC,id DESC LIMIT 30""", (str(order_id),))
        for row in rows:
            row["id"] = str(row["id"])
            row["error_code"] = safe_error(row["last_error_code"]) or "-"
            row["state_label"] = {
                "pending": "Menunggu", "sending": "Mengirim", "sent": "Terkirim",
                "failed": "Gagal", "unknown": "Tidak pasti",
                "manual_review": "Manual Delivery Review",
            }.get(row["state"], row["state"])
            row["updated_label"] = _format_time(
                row["manual_review_at"] or row["unknown_at"] or row["sent_at"])
        return rows

    def _supplier(self, order_id):
        return self.db.rows(
            """SELECT f.id,i.product_name,f.state,f.quantity,f.reserved_quantity,
                      f.refunded_quantity,f.last_error,
                      (SELECT COUNT(*) FROM public.supplier_purchases p WHERE p.supplier_fulfillment_id=f.id) AS purchase_count,
                      (SELECT COUNT(*) FROM public.supplier_manual_handoffs h WHERE h.supplier_fulfillment_id=f.id) AS handoff_count
               FROM public.supplier_fulfillments f JOIN public.order_items i ON i.id=f.order_item_id
               WHERE f.order_id=%s ORDER BY f.id""", (str(order_id),))

    def _audit(self, order_id):
        rows = self.db.rows(
            """SELECT a.id,a.action,a.new_values,a.ip_address,a.created_at,u.name AS actor_name,u.role AS actor_role
               FROM public.audit_logs a LEFT JOIN public.users u ON u.id=a.user_id
               WHERE a.subject_type='App\\Models\\Order' AND a.subject_id=%s
               ORDER BY a.created_at DESC,a.id DESC LIMIT 20""", (str(order_id),))
        for row in rows:
            row["new_values"] = json_value(row["new_values"])
            row["created_label"] = _format_time(row["created_at"])
            row["actor"] = row["actor_name"] or "sistem"
        return rows

    def _can_complete_manual(self, order, payments):
        if order["order_status"] != "pending_payment" or order["payment_status"] != "pending":
            return False
        return any(payment["provider"] == "manual" and payment["status"] == "pending"
                   for payment in payments)

    @staticmethod
    def _has_binance_mismatch(payments):
        return any(bool(payment.get("binance_mismatch")) for payment in payments)


def latest_payment(payments):
    return payments[-1] if payments else None


def require_valid_uuid(value):
    text = str(value or "")
    if not re.fullmatch(r"[0-9a-fA-F-]{36}", text):
        raise CheckoutError("order_not_found")
    return text
