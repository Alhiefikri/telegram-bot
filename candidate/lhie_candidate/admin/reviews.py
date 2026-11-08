"""Manual Fulfillment Review and Manual Delivery Review operator surfaces.

Resolutions delegate to the existing lifecycle and supplier recovery owners.
Manual Delivery Review only records operator evidence for an ambiguous effect;
it never sends Telegram traffic synchronously.
"""

import json
import re
from datetime import datetime
from uuid import uuid4

from django.db import transaction

from ..checkout.db import Database
from ..checkout.errors import CheckoutError
from ..checkout.lifecycle import OrderLifecycle, now as lifecycle_now
from ..compat.crypto import LaravelEncrypter
from ..receipt.protocol import EFFECT_TYPE, _now as receipt_now
from ..runtime import application_key
from ..suppliers.manual import ManualSupplierRecovery
from .sanitize import json_value, safe_error

REVIEW_STATES = ("pending", "refund_uncertain")
DELIVERY_REVIEW_STATES = ("unknown", "manual_review")
PAGE_SIZE = 20


def _format_time(value):
    if isinstance(value, datetime):
        return value.strftime("%d %b %Y %H:%M")
    return "-" if value is None else str(value)


def _audit(db, actor_id, action, subject_type, subject_id, values, ip=None):
    db.execute(
        """INSERT INTO public.audit_logs
           (user_id,action,subject_type,subject_id,new_values,ip_address,created_at)
           VALUES (%s,%s,%s,%s,%s,%s,%s)""",
        (actor_id, action, subject_type, str(subject_id),
         json.dumps(values, ensure_ascii=False, separators=(",", ":")), ip, lifecycle_now()),
    )


def _ip(request):
    value = request.META.get("REMOTE_ADDR")
    return value if isinstance(value, str) and len(value) <= 64 else None


class ManualReviewOperations:
    def __init__(self, *, using="default", encrypter=None):
        self.using = using
        self.db = Database(using)
        self.lifecycle = OrderLifecycle(using=using, encrypter=encrypter)
        self.encrypter = encrypter

    def resolve(self, review_id, *, actor_id, action, reason, reference=None, result="success"):
        if action not in ("fulfill", "refund", "reject"):
            raise CheckoutError("review_resolution_invalid")
        return self.lifecycle.resolve_review(
            review_id, actor_user_id=actor_id, action=action, reason=reason,
            reference=reference or None, result=result)

    def hand_off(self, review_id, *, actor_id, fulfillment_id, quantity, reason,
                 idempotency_key=None):
        db = self.db
        review = db.one(
            "SELECT order_id,reason,state FROM public.manual_fulfillment_reviews WHERE id=%s",
            (int(review_id),))
        if review is None or review["state"] not in REVIEW_STATES:
            raise CheckoutError("review_not_found")
        if review["reason"] != "supplier_fulfillment":
            raise CheckoutError("handoff_requires_supplier_review")
        key = idempotency_key or str(uuid4())
        if not re.fullmatch(r"[0-9a-fA-F-]{36}", str(key)):
            raise CheckoutError("handoff_invalid")
        recovery = ManualSupplierRecovery(
            self.encrypter or LaravelEncrypter(application_key()), using=self.using)
        return recovery.record_external_handoff(
            int(review_id), int(fulfillment_id), int(quantity), reason,
            actor_user_id=actor_id, idempotency_key=str(key))


class ManualReviewReads:
    def __init__(self, using="default"):
        self.using = using
        self.db = Database(using)

    def index(self, page=1, limit=PAGE_SIZE):
        page = max(1, int(page or 1))
        total = self.db.one("SELECT COUNT(*) AS n FROM public.manual_fulfillment_reviews")["n"]
        rows = self.db.rows(
            """SELECT r.id,r.order_id,r.payment_id,r.amount,r.currency,r.reason,r.state,
                      r.provider_reference,r.resolution_reason,r.resolved_at,r.created_at,
                      o.invoice_number,o.order_status,o.payment_status,
                      p.provider,p.method,p.status AS payment_method_status
               FROM public.manual_fulfillment_reviews r
               JOIN public.orders o ON o.id=r.order_id
               JOIN public.payments p ON p.id=r.payment_id
               ORDER BY CASE WHEN r.state IN ('pending','refund_uncertain') THEN 0 ELSE 1 END,
                        r.created_at DESC,r.id DESC
               LIMIT %s OFFSET %s""", (limit, (page - 1) * limit))
        for row in rows:
            row["id"] = int(row["id"])
            row["order_id"] = str(row["order_id"])
            row["created_label"] = _format_time(row["created_at"])
            row["resolved_label"] = _format_time(row["resolved_at"])
            row["state_label"] = {
                "pending": "Menunggu", "refund_uncertain": "Refund belum pasti",
                "fulfilled": "Dipenuhi", "refunded": "Refund", "rejected": "Ditolak",
            }.get(row["state"], row["state"])
            row["reason_label"] = {
                "late_payment": "Pembayaran terlambat",
                "local_credentials_missing": "Kredensial lokal tidak lengkap",
                "receipt_conflict": "Konflik receipt",
                "supplier_fulfillment": "Fulfillment supplier",
                "bsc_manual_review": "Review BSC",
            }.get(row["reason"], row["reason"])
            row["url"] = f"/admin/manual-fulfillment-reviews/{row['id']}"
        pages = max(1, (int(total) + limit - 1) // limit)
        return {"rows": rows, "total": int(total), "page": page, "pages": pages,
                "has_previous": page > 1, "has_next": page < pages,
                "previous": page - 1, "next": page + 1}

    def show(self, review_id):
        row = self.db.one(
            """SELECT r.*,o.invoice_number,o.order_status,o.payment_status,o.total_amount,
                      o.currency AS order_currency,o.created_at AS order_created_at,
                      c.first_name,c.username,c.telegram_id,
                      p.provider,p.method,p.status AS payment_state,p.currency AS payment_currency
               FROM public.manual_fulfillment_reviews r
               JOIN public.orders o ON o.id=r.order_id
               JOIN public.customers c ON c.id=o.customer_id
               JOIN public.payments p ON p.id=r.payment_id
               WHERE r.id=%s""", (int(review_id),))
        if row is None:
            return None
        row["created_label"] = _format_time(row["created_at"])
        row["resolved_label"] = _format_time(row["resolved_at"])
        row["items"] = self.db.rows(
            """SELECT i.id,i.product_name,i.quantity,i.subtotal,f.id AS fulfillment_id,
                      f.state,f.quantity AS fulfillment_quantity,f.reserved_quantity,
                      f.refunded_quantity,f.last_error,
                      (SELECT COUNT(*) FROM public.supplier_manual_handoffs h
                       WHERE h.supplier_fulfillment_id=f.id) AS handoff_count
               FROM public.order_items i
               LEFT JOIN public.supplier_fulfillments f ON f.order_item_id=i.id
               WHERE i.order_id=%s ORDER BY i.id""", (str(row["order_id"]),))
        row["handoffs"] = self.db.rows(
            """SELECT h.supplier_fulfillment_id,h.quantity,h.note,h.handed_off_at,u.name AS actor_name
               FROM public.supplier_manual_handoffs h
               JOIN public.supplier_fulfillments f ON f.id=h.supplier_fulfillment_id
               LEFT JOIN public.users u ON u.id=h.actor_user_id
               WHERE f.order_id=%s ORDER BY h.id""", (str(row["order_id"]),))
        for handoff in row["handoffs"]:
            handoff["handed_off_label"] = _format_time(handoff["handed_off_at"])
            handoff["actor"] = handoff["actor_name"] or "operator"
        row["audit"] = self.db.rows(
            """SELECT a.action,a.new_values,a.created_at,u.name AS actor_name
               FROM public.audit_logs a LEFT JOIN public.users u ON u.id=a.user_id
               WHERE a.subject_id=%s AND a.subject_type IN
                 ('App\\Models\\Order','App\\Models\\ManualFulfillmentReview')
               ORDER BY a.created_at DESC,a.id DESC LIMIT 20""", (str(row["order_id"]),))
        for entry in row["audit"]:
            entry["new_values"] = json_value(entry["new_values"])
            entry["created_label"] = _format_time(entry["created_at"])
            entry["actor"] = entry["actor_name"] or "sistem"
        row["can_handoff"] = (row["state"] in REVIEW_STATES
                              and row["reason"] == "supplier_fulfillment")
        return row


class DeliveryReviewOperations:
    def __init__(self, using="default"):
        self.using = using
        self.db = Database(using)

    def mark_sent(self, effect_id, *, message_id, actor_id, ip=None):
        if type(message_id) is int:
            message_id = str(message_id)
        if not isinstance(message_id, str) or not re.fullmatch(r"0*[1-9][0-9]{0,18}", message_id):
            raise CheckoutError("telegram_message_id_invalid")
        with transaction.atomic(using=self.using):
            effect = self.db.one(
                "SELECT * FROM public.telegram_outbound_effects WHERE id=%s",
                (str(effect_id),))
            if effect is None or effect["state"] not in DELIVERY_REVIEW_STATES:
                raise CheckoutError("delivery_review_unavailable")
            now = receipt_now()
            metadata = effect["outcome_metadata"] or {}
            if isinstance(metadata, str):
                try:
                    metadata = json.loads(metadata)
                except ValueError:
                    metadata = {}
            metadata = dict(metadata) if isinstance(metadata, dict) else {}
            metadata.update({
                "resolution": "operator_marked_sent",
                "resolution_actor_id": int(actor_id),
                "resolution_at": now.isoformat(),
            })
            updated_id = self.db.one(
                """UPDATE public.telegram_outbound_effects
                   SET state='sent',telegram_message_id=%s,sent_at=%s,unknown_at=NULL,
                       failed_at=NULL,manual_review_at=NULL,next_attempt_at=NULL,
                       lease_until=NULL,claim_token=NULL,last_error_code=NULL,last_error=NULL,
                       outcome_metadata=%s::json,updated_at=%s
                   WHERE id=%s AND state IN ('unknown','manual_review') RETURNING id""",
                (message_id, now, json.dumps(metadata), now, str(effect_id)))
            if updated_id is None:
                raise CheckoutError("delivery_review_unavailable")
            updated = self.db.one(
                "SELECT * FROM public.telegram_outbound_effects WHERE id=%s",
                (str(effect_id),))
            if effect["effect_type"] == EFFECT_TYPE and effect.get("effect_group_key"):
                group = self.db.rows(
                    """SELECT state FROM public.telegram_outbound_effects
                       WHERE effect_type=%s AND effect_group_key=%s""",
                    (EFFECT_TYPE, effect["effect_group_key"]))
                if group and all(row["state"] == "sent" for row in group):
                    self.db.execute(
                        """UPDATE public.orders
                           SET telegram_receipt_sent_at=COALESCE(telegram_receipt_sent_at,%s),
                               updated_at=%s
                           WHERE id=%s""",
                        (now, now, str(effect["source_id"])))
            _audit(self.db, actor_id, "telegram_outbound_effect.marked_sent",
                   "App\\Models\\TelegramOutboundEffect", effect_id,
                   {"old_state": effect["state"], "telegram_message_id": message_id,
                    "resolution": "operator_marked_sent"}, ip)
            return updated

    def resend(self, effect_id, *, actor_id, confirm_duplicate_risk, ip=None):
        if confirm_duplicate_risk is not True:
            raise CheckoutError("duplicate_risk_not_acknowledged")
        with transaction.atomic(using=self.using):
            effect = self.db.one(
                "SELECT * FROM public.telegram_outbound_effects WHERE id=%s",
                (str(effect_id),))
            if effect is None or effect["state"] not in DELIVERY_REVIEW_STATES:
                raise CheckoutError("delivery_review_unavailable")
            if effect["effect_type"] == EFFECT_TYPE:
                raise CheckoutError("receipt_resend_requires_receipt_group")
            group = effect["effect_group_key"] or effect["effect_key"]
            self.db.one("SELECT pg_advisory_xact_lock(hashtextextended(%s,0))",
                        ("effect-resend:" + str(group),))
            latest = self.db.one(
                """SELECT sequence FROM public.telegram_outbound_effects
                   WHERE effect_group_key=%s ORDER BY sequence DESC LIMIT 1""",
                (group,))
            sequence = max(int(effect["sequence"] or 1), int((latest or {}).get("sequence") or 0)) + 1
            effect_key = f"{group}:resend:v{sequence}"
            while self.db.one("SELECT id FROM public.telegram_outbound_effects WHERE effect_key=%s", (effect_key,)):
                sequence += 1
                effect_key = f"{group}:resend:v{sequence}"
            metadata = effect["outcome_metadata"] or {}
            if isinstance(metadata, str):
                try:
                    metadata = json.loads(metadata)
                except ValueError:
                    metadata = {}
            metadata = dict(metadata) if isinstance(metadata, dict) else {}
            metadata.update({
                "delivery_context": "explicit_resend",
                "duplicate_risk_acknowledged": True,
                "supersedes_effect_id": str(effect["id"]),
            })
            now = receipt_now()
            created = self.db.one(
                """INSERT INTO public.telegram_outbound_effects
                   (id,effect_key,effect_group_key,supersedes_effect_id,sequence,effect_type,
                    source_type,source_id,target_type,telegram_chat_id,queue,telegram_method,
                    locale,template_version,state,attempts,next_attempt_at,outcome_metadata,
                    created_at,updated_at)
                   VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,'pending',0,%s,%s::json,%s,%s)
                   RETURNING *""",
                (str(uuid4()), effect_key, group, str(effect["id"]), sequence,
                 effect["effect_type"], effect["source_type"], effect["source_id"],
                 effect["target_type"], effect["telegram_chat_id"], effect["queue"],
                 effect["telegram_method"], effect["locale"], effect["template_version"],
                 now, json.dumps(metadata), now, now))
            _audit(self.db, actor_id, "telegram_outbound_effect.resent",
                   "App\\Models\\TelegramOutboundEffect", effect["id"],
                   {"new_effect_id": str(created["id"]), "sequence": sequence,
                    "duplicate_risk_acknowledged": True}, ip)
            return created


class DeliveryReviewReads:
    def __init__(self, using="default"):
        self.using = using
        self.db = Database(using)

    def index(self, page=1, limit=PAGE_SIZE):
        page = max(1, int(page or 1))
        total = self.db.one(
            "SELECT COUNT(*) AS n FROM public.telegram_outbound_effects "
            "WHERE state IN ('unknown','manual_review')")["n"]
        rows = self.db.rows(
            """SELECT e.id,e.effect_type,e.state,e.attempts,e.target_type,e.telegram_chat_id,
                      e.telegram_method,e.sequence,e.created_at,e.updated_at,e.unknown_at,
                      e.manual_review_at,e.last_error_code,e.source_type,e.source_id,
                      o.invoice_number
               FROM public.telegram_outbound_effects e
               LEFT JOIN public.orders o ON e.source_type='App\\Models\\Order'
                 AND o.id::text=e.source_id
               WHERE e.state IN ('unknown','manual_review')
               ORDER BY CASE WHEN e.state='manual_review' THEN 0 ELSE 1 END,
                        e.updated_at DESC,e.id DESC LIMIT %s OFFSET %s""",
            (limit, (page - 1) * limit))
        for row in rows:
            row["id"] = str(row["id"])
            row["target_mask"] = "••••" + str(row["telegram_chat_id"] or "")[-4:]
            row["updated_label"] = _format_time(row["updated_at"])
            row["state_label"] = ("Manual Delivery Review"
                                  if row["state"] == "manual_review" else "Tidak pasti")
            row["error_code"] = safe_error(row["last_error_code"]) or "-"
            row["source_label"] = row["invoice_number"] or f"{row['source_type']}:{row['source_id']}"
            row["url"] = f"/admin/telegram-outbound-effects/{row['id']}"
        pages = max(1, (int(total) + limit - 1) // limit)
        return {"rows": rows, "total": int(total), "page": page, "pages": pages,
                "has_previous": page > 1, "has_next": page < pages,
                "previous": page - 1, "next": page + 1}

    def show(self, effect_id):
        row = self.db.one(
            """SELECT e.*,o.invoice_number,o.order_status,o.payment_status
               FROM public.telegram_outbound_effects e
               LEFT JOIN public.orders o ON e.source_type='App\\Models\\Order'
                 AND o.id::text=e.source_id
               WHERE e.id=%s""", (str(effect_id),))
        if row is None:
            return None
        row["id"] = str(row["id"])
        row["target_mask"] = "••••" + str(row["telegram_chat_id"] or "")[-4:]
        row["outcome"] = json_value(row["outcome_metadata"])
        row["last_error_code"] = safe_error(row["last_error_code"]) or "-"
        row["last_error"] = safe_error(row["last_error"]) or (
            "" if not row["last_error"] else "Pesan error disamarkan")
        row["created_label"] = _format_time(row["created_at"])
        row["updated_label"] = _format_time(row["updated_at"])
        row["state_label"] = ("Manual Delivery Review"
                              if row["state"] == "manual_review" else "Tidak pasti")
        row["is_receipt"] = row["effect_type"] == EFFECT_TYPE
        row["can_review"] = row["state"] in DELIVERY_REVIEW_STATES
        row["audit"] = self.db.rows(
            """SELECT a.action,a.old_values,a.new_values,a.created_at,u.name AS actor_name
               FROM public.audit_logs a LEFT JOIN public.users u ON u.id=a.user_id
               WHERE a.subject_type='App\\Models\\TelegramOutboundEffect' AND a.subject_id=%s
               ORDER BY a.created_at DESC,a.id DESC LIMIT 20""", (str(effect_id),))
        for entry in row["audit"]:
            entry["old_values"] = json_value(entry["old_values"])
            entry["new_values"] = json_value(entry["new_values"])
            entry["created_label"] = _format_time(entry["created_at"])
            entry["actor"] = entry["actor_name"] or "sistem"
        return row
