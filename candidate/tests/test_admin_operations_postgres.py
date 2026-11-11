"""P23 operational admin proof on the disposable P01/P22 database."""

import json
import re
import uuid
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo

from tests.test_checkout_postgres import admin_connection


JAKARTA = ZoneInfo("Asia/Jakarta")


def csrf(response):
    match = re.search(rb'name="csrfmiddlewaretoken" value="([^"]+)"', response.content)
    assert match is not None, ("csrf form missing", response.status_code)
    return match.group(1).decode()


def now():
    return datetime.now(JAKARTA).replace(tzinfo=None)


def _browser():
    from django.test import Client
    return Client(enforce_csrf_checks=True, HTTP_HOST="localhost",
                  HTTP_ORIGIN="https://localhost", HTTP_ACCEPT="text/html",
                  REMOTE_ADDR="127.0.40.10", raise_request_exception=False)


def _login(client, email, password):
    token = csrf(client.get("/admin/login", secure=True))
    response = client.post("/admin/login", {
        "csrfmiddlewaretoken": token, "email": email, "password": password,
    }, secure=True, HTTP_ACCEPT="text/html")
    assert response.status_code == 302, response.status_code


def _form_token(client, path):
    response = client.get(path, secure=True)
    assert response.status_code == 200, (path, response.status_code, response.content[:500])
    return csrf(response)


def _customer(admin, telegram_id, name):
    return admin.execute(
        """INSERT INTO public.customers(telegram_id,first_name,username,created_at,updated_at)
           VALUES (%s,%s,%s,now(),now()) RETURNING id""",
        (telegram_id, name, "p23_" + name.lower().replace(" ", "_"))).fetchone()[0]


def _product(admin, name, price=25000):
    return admin.execute(
        """INSERT INTO public.products(name,description,price_amount,usdt_price_amount,currency,stock,reserved_stock,
               is_active,created_at,updated_at)
           VALUES (%s,'Synthetic P23 product',%s,NULL,'IDR',5,0,true,now(),now()) RETURNING id""",
        (name, price)).fetchone()[0]


def _order(admin, customer_id, product_id, *, paid=False, status="pending_payment",
           payment_status="pending", provider="manual", method="BINANCE_PAY_ID",
           total=25000, paid_at=None, invoice=None):
    order_id = str(uuid.uuid4())
    invoice = invoice or "INV-P23-" + order_id.replace("-", "")[:12].upper()
    admin.execute(
        """INSERT INTO public.orders(id,customer_id,invoice_number,order_status,payment_status,
               total_amount,currency,expires_at,created_at,updated_at)
           VALUES (%s,%s,%s,%s,%s,%s,'IDR',%s,%s,%s)""",
        (order_id, customer_id, invoice, status, payment_status, total,
         now() + timedelta(hours=1), now() - timedelta(hours=1), now()))
    admin.execute(
        """INSERT INTO public.order_items(order_id,product_id,product_name,quantity,unit_price,
               subtotal,created_at,updated_at)
           VALUES (%s,%s,'Synthetic P23 item',1,%s,%s,now(),now())""",
        (order_id, product_id, total, total))
    payment_id = str(uuid.uuid4())
    admin.execute(
        """INSERT INTO public.payments(id,order_id,provider,merchant_ref,method,amount,currency,
               status,paid_at,created_at,updated_at,provider_total_payment,provider_fee)
           VALUES (%s,%s,%s,%s,%s,%s,'IDR',%s,%s,now(),now(),%s,0)""",
        (payment_id, order_id, provider, "P23-" + payment_id, method, total,
         payment_status, paid_at, total if paid else None))
    return order_id, payment_id, invoice


def exercise_admin_operations(fixtures):
    from django.db import connections
    from django.test import override_settings
    from lhie_candidate.readiness import verify_dependencies
    from lhie_candidate.admin.reporting import Reporting

    admin = admin_connection()
    verify_dependencies()
    admin.execute("UPDATE public.manual_payment_settings SET admin_telegram_chat_id='900001'")
    owner_email = fixtures["auth"]["admin"]["email"]
    password = fixtures["auth"]["password"]

    # Synthetic record set; no production/history data is involved.
    customer_id = _customer(admin, 910000000000001, "P23 Alpha")
    product_id = _product(admin, "Synthetic P23 paid")
    paid_order, paid_payment, paid_invoice = _order(
        admin, customer_id, product_id, paid=True, status="processing",
        payment_status="paid", paid_at=now())
    pending_order, pending_payment, pending_invoice = _order(
        admin, customer_id, product_id, paid=False)
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.runtime import application_key
    frozen_snapshot = LaravelEncrypter(application_key()).encrypt_json({
        "event_type": "order_created", "order_id": pending_order,
        "recipient": "private-review-recipient@example.invalid",
        "content": {"subject": "secret subject", "text": "must-not-leak-body",
                    "html": "<p>must-not-leak-body</p>"}})
    reviewed_event_id = admin.execute("""INSERT INTO public.order_notification_events
        (order_id,event_type,recipient_email,status,attempts,delivery_started_at,failed_at,
         last_error,created_at,updated_at)
        VALUES (%s,'order_created',%s,'failed',1,now() - interval '3 minutes',now(),
                'mail_delivery_unknown',now(),now()) RETURNING id""",
        (pending_order, frozen_snapshot)).fetchone()[0]
    resend_snapshot = LaravelEncrypter(application_key()).encrypt_json({
        "event_type": "payment_confirmed", "order_id": paid_order,
        "recipient": "private-review-recipient@example.invalid",
        "content": {"subject": "secret subject", "text": "must-not-leak-body",
                    "html": "<p>must-not-leak-body</p>"}})
    resent_event_id = admin.execute("""INSERT INTO public.order_notification_events
        (order_id,event_type,recipient_email,status,attempts,delivery_started_at,failed_at,
         last_error,created_at,updated_at)
        VALUES (%s,'payment_confirmed',%s,'failed',1,now() - interval '3 minutes',now(),
                'mail_delivery_unknown',now(),now()) RETURNING id""",
        (paid_order, resend_snapshot)).fetchone()[0]
    review_id = admin.execute(
        """INSERT INTO public.manual_fulfillment_reviews
           (order_id,payment_id,amount,currency,reason,state,created_at,updated_at)
           VALUES (%s,%s,25000,'IDR','local_credentials_missing','pending',now(),now()) RETURNING id""",
        (pending_order, pending_payment)).fetchone()[0]
    effect_id = str(uuid.uuid4())
    admin.execute(
        """INSERT INTO public.telegram_outbound_effects
           (id,effect_key,effect_group_key,sequence,effect_type,source_type,source_id,target_type,
            telegram_chat_id,queue,telegram_method,locale,template_version,state,attempts,
            unknown_at,outcome_metadata,created_at,updated_at)
           VALUES (%s,%s,%s,1,'admin.order_paid_notification','App\\Models\\Order',%s,'admin',
                   '999','telegram-outbound','sendMessage','id','v1','unknown',1,%s,
                   %s,now(),now())""",
        (effect_id, "p23-effect-" + effect_id, "p23-effect-" + effect_id,
         paid_order, now(), json.dumps({"delivery_context": "test",
                                        "credential_payload": "must-not-leak"})))
    admin.execute(
        """INSERT INTO public.audit_logs(user_id,action,subject_type,subject_id,new_values,created_at)
           VALUES (%s,'p23.seed','App\\Models\\Order',%s,%s,now())""",
        (int(fixtures["auth"]["admin"]["id"]), paid_order,
         json.dumps({"password": "must-not-leak", "amount": 25000})))

    owner = _browser()
    _login(owner, owner_email, password)
    try:
        for path in (
                "/admin?tab=pesanan", "/admin/laporan", "/admin/audit",
                "/admin/store-balance/adjustments", "/admin/store-balance/usdt-review",
                "/admin/manual-fulfillment-reviews", "/admin/telegram-outbound-effects",
                "/admin/mail-delivery-reviews"):
            response = owner.get(path, secure=True)
            assert response.status_code == 200, (path, response.status_code, response.content[:800])

        orders = owner.get("/admin?tab=pesanan", secure=True)
        assert paid_invoice.encode() in orders.content and pending_invoice.encode() in orders.content
        detail = owner.get(f"/admin/orders/{paid_order}", secure=True)
        assert detail.status_code == 200, (detail.status_code, detail.content[:800])
        assert paid_invoice.encode() in detail.content, (paid_invoice, detail.content[:800])
        assert b"must-not-leak" not in detail.content
        review_page = owner.get(f"/admin/manual-fulfillment-reviews/{review_id}", secure=True)
        assert review_page.status_code == 200 and pending_invoice.encode() in review_page.content
        delivery_page = owner.get(f"/admin/telegram-outbound-effects/{effect_id}", secure=True)
        assert delivery_page.status_code == 200 and b"must-not-leak" not in delivery_page.content
        mail_reviews = owner.get("/admin/mail-delivery-reviews", secure=True)
        assert mail_reviews.status_code == 200
        mail_detail_path = f"/admin/mail-delivery-reviews/event/{reviewed_event_id}"
        mail_detail = owner.get(mail_detail_path, secure=True)
        assert mail_detail.status_code == 200
        for secret in (b"private-review-recipient@example.invalid", b"secret subject",
                       b"must-not-leak-body"):
            assert secret not in mail_detail.content
        mail_token = csrf(mail_detail)
        owner.post(f"{mail_detail_path}/mark-sent", {
            "csrfmiddlewaretoken": mail_token, "evidence_reference": "smtp-evidence:p23",
        }, secure=True, HTTP_ACCEPT="text/html")
        assert admin.execute("SELECT status FROM public.order_notification_events WHERE id=%s",
                             (reviewed_event_id,)).fetchone()[0] == "failed"
        response = owner.post(f"{mail_detail_path}/mark-sent", {
            "csrfmiddlewaretoken": mail_token, "evidence_reference": "smtp-evidence:p23",
            "confirm_mark_sent": "1",
        }, secure=True, HTTP_ACCEPT="text/html")
        assert response.status_code == 302
        assert admin.execute("SELECT status FROM public.order_notification_events WHERE id=%s",
                             (reviewed_event_id,)).fetchone()[0] == "sent"

        resend_path = f"/admin/mail-delivery-reviews/event/{resent_event_id}"
        resend_detail = owner.get(resend_path, secure=True)
        resend_token = csrf(resend_detail)
        original_snapshot = admin.execute(
            "SELECT recipient_email FROM public.order_notification_events WHERE id=%s",
            (resent_event_id,)).fetchone()[0]
        owner.post(f"{resend_path}/resend", {
            "csrfmiddlewaretoken": resend_token,
        }, secure=True, HTTP_ACCEPT="text/html")
        assert admin.execute("SELECT status FROM public.order_notification_events WHERE id=%s",
                             (resent_event_id,)).fetchone()[0] == "failed"
        response = owner.post(f"{resend_path}/resend", {
            "csrfmiddlewaretoken": resend_token, "confirm_duplicate_risk": "1",
        }, secure=True, HTTP_ACCEPT="text/html")
        assert response.status_code == 302
        assert admin.execute("SELECT status,attempts,recipient_email,delivery_started_at FROM public.order_notification_events WHERE id=%s",
                             (resent_event_id,)).fetchone() == ("pending", 1, original_snapshot, None)
        assert admin.execute("""SELECT count(*) FROM public.audit_logs WHERE action='mail_delivery.resent'
            AND subject_id=%s AND new_values::text LIKE '%duplicate_risk_acknowledged%'""",
            (str(resent_event_id),)).fetchone()[0] == 1

        # Manual fulfillment reject is one audited transition; replay is a no-op.
        token = csrf(review_page)
        target = f"/admin/manual-fulfillment-reviews/{review_id}/resolve"
        for attempt in range(2):
            response = owner.post(target, {
                "csrfmiddlewaretoken": token, "action": "reject",
                "reason": "P23 synthetic rejection",
            }, secure=True, HTTP_ACCEPT="text/html")
            assert response.status_code == 302, (attempt, response.status_code)
        state = admin.execute(
            "SELECT state FROM public.manual_fulfillment_reviews WHERE id=%s", (review_id,)).fetchone()[0]
        assert state == "rejected"
        audits = admin.execute(
            "SELECT COUNT(*) FROM public.audit_logs WHERE action='manual_fulfillment_review.rejected' "
            "AND subject_id=%s", (str(pending_order),)).fetchone()[0]
        assert audits == 1, audits

        # Manual Delivery Review marks operator evidence without a Telegram send.
        token = csrf(delivery_page)
        priv = admin.execute("""SELECT has_table_privilege('candidate_runtime','public.telegram_outbound_effects','SELECT'), has_column_privilege('candidate_runtime','public.telegram_outbound_effects','state','UPDATE'), has_column_privilege('candidate_runtime','public.telegram_outbound_effects','outcome_metadata','UPDATE'), has_column_privilege('candidate_runtime','public.telegram_outbound_effects','claim_token','UPDATE')""").fetchone()
        assert priv == (True, True, True, True), priv
        response = owner.post(f"/admin/telegram-outbound-effects/{effect_id}/mark-sent", {
            "csrfmiddlewaretoken": token, "telegram_message_id": "4242",
        }, secure=True, HTTP_ACCEPT="text/html")
        assert response.status_code == 302
        effect = admin.execute(
            "SELECT state,telegram_message_id FROM public.telegram_outbound_effects WHERE id=%s",
            (effect_id,)).fetchone()
        assert effect == ("sent", "4242"), effect
        mark_audits = admin.execute(
            "SELECT COUNT(*) FROM public.audit_logs WHERE action='telegram_outbound_effect.marked_sent' "
            "AND subject_id=%s", (effect_id,)).fetchone()[0]
        assert mark_audits == 1

        # Reporting uses one canonical successful receipt and exact integer money.
        reporting = Reporting()
        start = datetime.combine(now().date(), datetime.min.time())
        net, count = reporting.net_total(start, None, "IDR")
        assert net >= 25000 and count >= 1, (net, count)
        report = owner.get("/admin/laporan?period=harian&currency=IDR", secure=True)
        assert report.status_code == 200 and paid_invoice.encode() in report.content
        assert b"Rp25.000" in report.content
        export = owner.get("/admin/laporan/export?currency=IDR&period=harian&currency=IDR",
                           secure=True)
        assert export.status_code == 200 and paid_invoice.encode() in export.content
        assert b"must-not-leak" not in export.content
        audit_page = owner.get("/admin/audit?action=p23.seed", secure=True)
        assert audit_page.status_code == 200
        assert b"must-not-leak" not in audit_page.content
        assert b"dirahasiakan" in audit_page.content

        # IDR adjustment preview is owner-bound and creates no ledger effect.
        token = csrf(owner.get("/admin/store-balance/adjustments", secure=True))
        response = owner.post("/admin/store-balance/adjustments/previews", {
            "csrfmiddlewaretoken": token, "telegram_id": "910000000000001",
            "amount": "10000", "reason": "P23 synthetic preview",
            "category": "owner_attested",
        }, secure=True, HTTP_ACCEPT="text/html")
        assert response.status_code == 302
        preview = admin.execute(
            """SELECT id,fingerprint,version FROM public.balance_adjustment_requests
               WHERE telegram_id=%s ORDER BY created_at DESC,id DESC LIMIT 1""",
            ("910000000000001",)).fetchone()
        assert preview is not None
        preview_id, fingerprint, version = preview
        token = csrf(owner.get(f"/admin/store-balance/adjustments?preview={preview_id}", secure=True))
        response = owner.post(f"/admin/store-balance/adjustments/previews/{preview_id}/confirm", {
            "csrfmiddlewaretoken": token, "fingerprint": fingerprint,
            "version": str(version), "password": "wrong-password",
        }, secure=True, HTTP_ACCEPT="text/html")
        assert response.status_code == 302
        assert admin.execute(
            "SELECT COUNT(*) FROM public.store_balance_entries WHERE source_type='balance_adjustment' "
            "AND source_id=%s", (str(preview_id),)).fetchone()[0] == 0
        assert admin.execute(
            "SELECT state FROM public.balance_adjustment_requests WHERE id=%s", (preview_id,)
        ).fetchone()[0] == "pending"

        # Settings are DB-backed and a not-ready provider never activates.
        payment = admin.execute(
            "SELECT id,payment_provider_version,payment_mode FROM public.manual_payment_settings "
            "ORDER BY is_active DESC,id LIMIT 1").fetchone()
        setting_id, provider_version, provider_mode = payment
        token = csrf(owner.get("/admin?tab=pengaturan", secure=True))
        response = owner.post("/admin/payment-mode", {
            "csrfmiddlewaretoken": token, "payment_mode": "klikqris",
            "payment_provider_version": str(provider_version),
        }, secure=True, HTTP_ACCEPT="text/html")
        assert response.status_code == 302
        assert admin.execute(
            "SELECT payment_mode,payment_provider_version FROM public.manual_payment_settings WHERE id=%s",
            (setting_id,)).fetchone() == (provider_mode, provider_version)

        token = csrf(owner.get("/admin?tab=pengaturan", secure=True))
        version = admin.execute(
            "SELECT version FROM public.checkout_maintenance_settings "
            "WHERE singleton_key='checkout_maintenance'").fetchone()[0]
        response = owner.post("/admin/checkout-maintenance", {
            "csrfmiddlewaretoken": token, "active": "1",
            "public_note": "P23 synthetic maintenance window", "version": str(version),
        }, secure=True, HTTP_ACCEPT="text/html")
        assert response.status_code == 302
        assert admin.execute(
            "SELECT is_active,version FROM public.checkout_maintenance_settings "
            "WHERE singleton_key='checkout_maintenance'").fetchone() == (True, version + 1)

        token = csrf(owner.get("/admin?tab=pengaturan", secure=True))
        api_version = admin.execute(
            "SELECT version FROM public.customer_api_settings WHERE singleton_key='customer_api'"
        ).fetchone()[0]
        response = owner.post("/admin/customer-api-availability", {
            "csrfmiddlewaretoken": token, "enabled": "1", "version": str(api_version),
        }, secure=True, HTTP_ACCEPT="text/html")
        assert response.status_code == 302, (response.status_code, response.content[:800])
        assert admin.execute(
            "SELECT enabled,version FROM public.customer_api_settings "
            "WHERE singleton_key='customer_api'").fetchone() == (True, api_version + 1)

        # A non-owner admin cannot enter Owner-only balance controls.
        import bcrypt
        admin_hash = bcrypt.hashpw(b"p23-admin", bcrypt.gensalt(rounds=4)).decode()
        admin.execute(
            """INSERT INTO public.users(name,email,password,role,created_at,updated_at)
               VALUES ('P23 Admin','p23-admin@example.invalid',%s,'admin',now(),now())""",
            (admin_hash,))
        staff = _browser()
        _login(staff, "p23-admin@example.invalid", "p23-admin")
        denied = staff.get("/admin/store-balance/adjustments", secure=True)
        assert denied.status_code == 403, denied.status_code
        assert staff.get("/admin/store-balance/usdt-review", secure=True).status_code == 403
        assert staff.get("/admin?tab=pengaturan", secure=True).status_code == 200
        return {"p23_checks": 24}
    finally:
        connections.close_all()
