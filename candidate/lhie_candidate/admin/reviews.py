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
from ..receipt.protocol import EFFECT_TYPE, RECEIPT_TYPES, ReceiptProtocol
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

    def _protocol(self, effect):
        from ..notifications.families import LIMITS
        from ..notifications.policy import TERMINAL_CODES
        from ..notifications.waitlist import LIMITS as MAIL_LIMITS, TERMINAL_CODES as MAIL_CODES
        limits = {kind: 3 for kind in RECEIPT_TYPES} | LIMITS | MAIL_LIMITS
        if effect["effect_type"] not in limits:
            raise CheckoutError("delivery_review_unavailable")
        return ReceiptProtocol(self.using, family_limits=limits, terminal_codes=TERMINAL_CODES | MAIL_CODES)

    def mark_sent(self, effect_id, *, message_id, actor_id, ip=None, mail_confirmed=False,
                  evidence_reference=None):
        with transaction.atomic(using=self.using):
            effect = self.db.one("SELECT * FROM public.telegram_outbound_effects WHERE id=%s", (str(effect_id),))
            if effect is None:
                raise CheckoutError("delivery_review_unavailable")
            is_mail = effect["telegram_method"] == "smtp.send"
            if is_mail:
                evidence = _evidence_reference(evidence_reference)
                if mail_confirmed is not True:
                    raise CheckoutError("delivery_review_unavailable")
                message_id = "1"
            else:
                evidence = None
                if isinstance(message_id, bool) or not re.fullmatch(r"0*[1-9][0-9]{0,18}", str(message_id)):
                    raise CheckoutError("telegram_message_id_invalid")
            protocol = self._protocol(effect)
            updated = protocol.resolve_sent(effect_id, message_id, actor_id)
            if updated is None:
                raise CheckoutError("delivery_review_unavailable")
            if effect["effect_type"] in RECEIPT_TYPES:
                if (effect["effect_type"] == EFFECT_TYPE
                        and protocol.is_receipt_group_complete(effect["effect_group_key"])):
                    self.db.execute("""UPDATE public.orders
                        SET telegram_receipt_sent_at=COALESCE(telegram_receipt_sent_at,%s),updated_at=%s WHERE id=%s""",
                        (updated["sent_at"], updated["sent_at"], effect["source_id"]))
            elif effect["effect_type"] in ("waitlist.confirmation_mail", "waitlist.restock_mail"):
                from django.conf import settings
                from ..notifications.waitlist import WaitlistMail
                key = application_key()
                WaitlistMail(LaravelEncrypter(key), signing_key=key,
                             base_url=settings.CANDIDATE_LEGACY_APP_URL,
                             using=self.using).project(updated)
            else:
                from ..notifications.service import Notifications
                from ..notifications.policy import TestRecipients
                # Admission is read from the retained effect after operator
                # authorization; this instance can only repair, never transport.
                Notifications(None, using=self.using, recipients=TestRecipients(
                    frozenset({str(updated["telegram_chat_id"])}))).project(updated)
            _audit(self.db, actor_id, "telegram_outbound_effect.marked_sent",
                   "App\\Models\\TelegramOutboundEffect", effect_id,
                   {"old_state": effect["state"], "telegram_message_id": message_id,
                    "resolution": "operator_marked_sent",
                    **({"evidence_reference": evidence} if is_mail else {})}, ip)
            return updated

    def resend(self, effect_id, *, actor_id, confirm_duplicate_risk, ip=None):
        if confirm_duplicate_risk is not True:
            raise CheckoutError("duplicate_risk_not_acknowledged")
        effect = self.db.one("SELECT * FROM public.telegram_outbound_effects WHERE id=%s", (str(effect_id),))
        if effect is None or effect["state"] not in DELIVERY_REVIEW_STATES:
            raise CheckoutError("delivery_review_unavailable")
        if effect["effect_type"] == EFFECT_TYPE:
            from ..receipt.runtime import receipt_service

            def audit_resend(created):
                _audit(self.db, actor_id, "telegram_outbound_effect.resent",
                       "App\\Models\\TelegramOutboundEffect", effect_id,
                       {"new_effect_id": str(created["id"]), "sequence": created["sequence"],
                        "duplicate_risk_acknowledged": True}, ip)

            created = receipt_service(using=self.using).materialize(
                effect["source_id"], resend_of=str(effect_id), on_materialized=audit_resend)
            if created is None:
                raise CheckoutError("delivery_review_unavailable")
            return created
        with transaction.atomic(using=self.using):
            created = self._protocol(effect).resend_review(
                effect_id, duplicate_risk_acknowledged=True)
            if created is None:
                raise CheckoutError("delivery_review_unavailable")
            _audit(self.db, actor_id, "telegram_outbound_effect.resent",
                   "App\\Models\\TelegramOutboundEffect", effect_id,
                   {"new_effect_id": str(created["id"]), "sequence": created["sequence"],
                    "duplicate_risk_acknowledged": True}, ip)
            return created

EVIDENCE_REFERENCE = re.compile(r"[A-Za-z0-9][A-Za-z0-9._:/#-]{0,199}\Z")


def _evidence_reference(value):
    if not isinstance(value, str) or not EVIDENCE_REFERENCE.fullmatch(value):
        raise CheckoutError("delivery_evidence_required")
    return value


class MailDeliveryReviewOperations:
    """Resolve ambiguous SMTP outcomes without exposing stored mail snapshots."""

    def __init__(self, using="default"):
        self.using = using
        self.db = Database(using)

    def mark_sent(self, kind, work_id, *, evidence_reference, affirmative_evidence,
                  actor_id, ip=None):
        evidence = _evidence_reference(evidence_reference)
        if affirmative_evidence is not True:
            raise CheckoutError("delivery_evidence_required")
        with transaction.atomic(using=self.using):
            if kind == "auth":
                try:
                    numeric_id = int(work_id)
                except (TypeError, ValueError):
                    raise CheckoutError("delivery_review_unavailable") from None
                row = self.db.one(
                    "SELECT * FROM public.customer_auth_mail_works WHERE id=%s FOR UPDATE",
                    (numeric_id,))
                if not row or row["status"] != "unknown":
                    raise CheckoutError("delivery_review_unavailable")
                updated = self.db.execute("""UPDATE public.customer_auth_mail_works
                    SET status='sent',payload=NULL,sent_at=%s,last_error_code='operator_marked_sent',
                        updated_at=%s WHERE id=%s AND status='unknown'""",
                    (lifecycle_now(), lifecycle_now(), numeric_id))
                subject_type = "App\\Models\\CustomerAuthMailWork"
                audit_id = numeric_id
            elif kind == "event":
                row = self.db.one("""SELECT * FROM public.order_notification_events
                    WHERE id=%s AND event_type IN ('credentials_ready','order_created',
                      'payment_confirmed','fulfillment_completed','order_expired','order_cancelled')
                    FOR UPDATE""", (str(work_id),))
                if (not row or row["status"] != "failed" or
                        row["last_error"] != "mail_delivery_unknown" or
                        row["delivery_started_at"] is None):
                    raise CheckoutError("delivery_review_unavailable")
                updated = self.db.execute("""UPDATE public.order_notification_events
                    SET status='sent',sent_at=%s,failed_at=NULL,delivery_started_at=NULL,
                        next_attempt_at=NULL,last_error='operator_marked_sent',updated_at=%s
                    WHERE id=%s AND status='failed' AND last_error='mail_delivery_unknown'
                      AND delivery_started_at IS NOT NULL""",
                    (lifecycle_now(), lifecycle_now(), str(work_id)))
                subject_type = "App\\Models\\OrderNotificationEvent"
                audit_id = work_id
            else:
                raise CheckoutError("delivery_review_unavailable")
            if not updated:
                raise CheckoutError("delivery_review_unavailable")
            _audit(self.db, actor_id, "mail_delivery.marked_sent", subject_type, audit_id,
                   {"resolution": "operator_marked_sent", "evidence_reference": evidence}, ip)

    def resend(self, kind, work_id, *, confirm_duplicate_risk, actor_id, ip=None):
        if confirm_duplicate_risk is not True:
            raise CheckoutError("duplicate_risk_not_acknowledged")
        with transaction.atomic(using=self.using):
            if kind == "auth":
                from ..auth.mail import MailWork

                try:
                    numeric_id = int(work_id)
                except (TypeError, ValueError):
                    raise CheckoutError("delivery_review_unavailable") from None
                mail = MailWork(None, app_key="", db=self.db)
                customer, grant, row = mail._lock(numeric_id)
                if (not row or row["status"] != "unknown" or row["payload"] is None
                        or row["expires_at"] <= mail.clock()
                        or not mail._eligible(customer, grant) or row["attempts"] >= 5):
                    raise CheckoutError("delivery_review_unavailable")
                changed = self.db.execute("""UPDATE public.customer_auth_mail_works
                    SET status='pending',next_attempt_at=%s,last_error_code='manual_retry_authorized',
                        updated_at=%s WHERE id=%s AND status='unknown'""",
                    (lifecycle_now(), lifecycle_now(), numeric_id))
                if not changed:
                    raise CheckoutError("delivery_review_unavailable")
                _audit(self.db, actor_id, "mail_delivery.resent",
                       "App\\Models\\CustomerAuthMailWork", numeric_id,
                       {"duplicate_risk_acknowledged": True, "attempt": row["attempts"] + 1}, ip)
            elif kind == "event":
                row = self.db.one("""SELECT * FROM public.order_notification_events
                    WHERE id=%s AND event_type IN ('credentials_ready','order_created',
                      'payment_confirmed','fulfillment_completed','order_expired','order_cancelled')
                    FOR UPDATE""", (str(work_id),))
                limit = 5 if row and row["event_type"] == "credentials_ready" else 3
                if (not row or row["status"] != "failed" or
                        row["last_error"] != "mail_delivery_unknown" or
                        row["delivery_started_at"] is None or row["recipient_email"] is None
                        or row["attempts"] >= limit):
                    raise CheckoutError("delivery_review_unavailable")
                changed = self.db.execute("""UPDATE public.order_notification_events
                    SET status='pending',delivery_started_at=NULL,failed_at=NULL,next_attempt_at=%s,
                        last_error='manual_retry_authorized',updated_at=%s
                    WHERE id=%s AND status='failed' AND last_error='mail_delivery_unknown'
                      AND delivery_started_at IS NOT NULL""",
                    (lifecycle_now(), lifecycle_now(), str(work_id)))
                if not changed:
                    raise CheckoutError("delivery_review_unavailable")
                _audit(self.db, actor_id, "mail_delivery.resent",
                       "App\\Models\\OrderNotificationEvent", work_id,
                       {"duplicate_risk_acknowledged": True, "attempt": row["attempts"] + 1}, ip)
            else:
                raise CheckoutError("delivery_review_unavailable")


class MailDeliveryReviewReads:
    def __init__(self, using="default"):
        self.using = using
        self.db = Database(using)

    def index(self, page=1, limit=PAGE_SIZE):
        page = max(1, int(page or 1))
        total = self.db.one("""SELECT
            (SELECT count(*) FROM public.customer_auth_mail_works WHERE status='unknown') +
            (SELECT count(*) FROM public.order_notification_events
             WHERE status='failed' AND last_error='mail_delivery_unknown'
               AND delivery_started_at IS NOT NULL
               AND event_type IN ('credentials_ready','order_created','payment_confirmed',
                 'fulfillment_completed','order_expired','order_cancelled')) AS n""")["n"]
        result = self.db.rows("""SELECT * FROM (
            SELECT id::text AS work_id,'auth' AS kind,status,attempts,
                   last_error_code AS error_code,updated_at,NULL::text AS event_type,
                   NULL::text AS invoice_number
            FROM public.customer_auth_mail_works WHERE status='unknown'
            UNION ALL
            SELECT e.id::text AS work_id,'event' AS kind,e.status,e.attempts,
                   e.last_error AS error_code,e.updated_at,e.event_type,o.invoice_number
            FROM public.order_notification_events e
            LEFT JOIN public.orders o ON o.id=e.order_id
            WHERE e.status='failed' AND e.last_error='mail_delivery_unknown'
              AND e.delivery_started_at IS NOT NULL
              AND e.event_type IN ('credentials_ready','order_created','payment_confirmed',
                'fulfillment_completed','order_expired','order_cancelled')
        ) review_rows ORDER BY updated_at DESC,work_id DESC LIMIT %s OFFSET %s""",
            (limit, (page - 1) * limit))
        for row in result:
            row["kind_label"] = "Auth" if row["kind"] == "auth" else "Order mail"
            row["source_label"] = row["invoice_number"] or "Auth request"
            row["updated_label"] = _format_time(row["updated_at"])
            row["error_code"] = safe_error(row["error_code"]) or "mail_delivery_unknown"
            row["url"] = f"/admin/mail-delivery-reviews/{row['kind']}/{row['work_id']}"
        pages = max(1, (total + limit - 1) // limit)
        return {"rows": result, "total": total, "page": page, "pages": pages,
                "has_previous": page > 1, "has_next": page < pages,
                "previous": page - 1, "next": page + 1}

    def show(self, kind, work_id):
        if kind == "auth":
            try:
                work_id = int(work_id)
            except (TypeError, ValueError):
                return None
            row = self.db.one("""SELECT id AS work_id,status,attempts,last_error_code AS error_code,
                updated_at,expires_at,payload IS NOT NULL AS has_payload,
                auth_token_id,login_link_id,NULL::text AS event_type,NULL::text AS invoice_number
                FROM public.customer_auth_mail_works WHERE id=%s AND status='unknown'""", (work_id,))
        elif kind == "event":
            row = self.db.one("""SELECT e.id::text AS work_id,e.status,e.attempts,
                e.last_error AS error_code,e.updated_at,o.invoice_number,e.event_type,
                e.recipient_email IS NOT NULL AS has_snapshot,
                NULL::timestamp AS expires_at
                FROM public.order_notification_events e LEFT JOIN public.orders o ON o.id=e.order_id
                WHERE e.id=%s AND e.status='failed' AND e.last_error='mail_delivery_unknown'
                  AND e.delivery_started_at IS NOT NULL
                  AND e.event_type IN ('credentials_ready','order_created','payment_confirmed',
                    'fulfillment_completed','order_expired','order_cancelled')""", (str(work_id),))
        else:
            return None
        if not row:
            return None
        row["kind"] = kind
        row["kind_label"] = "Auth mail" if kind == "auth" else "Order mail"
        row["state_label"] = "Tidak pasti"
        row["source_label"] = row["invoice_number"] or "Auth request"
        row["updated_label"] = _format_time(row["updated_at"])
        row["expires_label"] = _format_time(row["expires_at"])
        row["error_code"] = safe_error(row["error_code"]) or "mail_delivery_unknown"
        if kind == "auth":
            from ..auth.mail import MailWork
            binding = row
            if binding["auth_token_id"] is not None:
                grant = self.db.one("""SELECT t.*,c.id AS customer_id
                    FROM public.customer_auth_tokens t JOIN public.customers c ON c.id=t.customer_id
                    WHERE t.id=%s""", (binding["auth_token_id"],))
                customer = self.db.one("""SELECT id,email,email_verified_at,password,auth_version,enrollment_version
                    FROM public.customers WHERE id=%s""", (grant["customer_id"],)) if grant else None
            else:
                grant = self.db.one("""SELECT *, 'magic' AS purpose FROM public.customer_login_links
                    WHERE id=%s""", (binding["login_link_id"],))
                customer = None
            mail = MailWork(None, app_key="", db=self.db)
            row["can_retry"] = bool(
                row["has_payload"] and row["attempts"] < 5 and row["expires_at"] > mail.clock()
                and mail._eligible(customer, grant))
        else:
            limit = 5 if row["event_type"] == "credentials_ready" else 3
            row["can_retry"] = bool(row["has_snapshot"] and row["attempts"] < limit)
        row.pop("has_payload", None)
        row.pop("has_snapshot", None)
        row.pop("auth_token_id", None)
        row.pop("login_link_id", None)
        row["audit"] = []
        return row


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
        metadata = json_value(row["outcome_metadata"])
        metadata = metadata if isinstance(metadata, dict) else {}
        row["outcome"] = {key: metadata[key] for key in (
            "resolution", "resolution_actor_id", "resolution_at", "explicit_resend",
            "duplicate_risk_acknowledged", "supersedes_effect_id", "unit_role",
            "receipt_chunk_index", "receipt_chunk_count", "contains_credentials",
            "first_pickup_at", "telegram_response") if key in metadata}
        row.pop("outcome_metadata", None)
        row["last_error_code"] = safe_error(row["last_error_code"]) or "-"
        row["last_error"] = safe_error(row["last_error"]) or (
            "" if not row["last_error"] else "Pesan error disamarkan")
        row["created_label"] = _format_time(row["created_at"])
        row["updated_label"] = _format_time(row["updated_at"])
        row["state_label"] = {"manual_review": "Manual Delivery Review", "unknown": "Tidak pasti",
                              "sent": "Terkirim", "pending": "Tertunda", "sending": "Sedang dikirim",
                              "failed": "Gagal"}.get(row["state"], row["state"])
        row["is_receipt"] = row["effect_type"] == EFFECT_TYPE
        row["is_mail"] = row["telegram_method"] == "smtp.send"
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
