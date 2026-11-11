"""Server-rendered P23 operational surfaces.

Every mutation is a POST with session CSRF, a repeated role guard and a
server-owned preview/version binding. Exceptions never echo provider or
credential payloads.
"""

import re

from django.http import Http404, HttpResponse, HttpResponseRedirect
from django.views.decorators.http import require_GET, require_http_methods, require_POST

from ..checkout.errors import CheckoutError
from . import access, navigation
from .balance import BalanceAdmin, BalanceReads
from .orders import OrderOperations, OrderReads
from .reporting import Reporting, export_filename, normalize_filters
from .reviews import (DeliveryReviewOperations, DeliveryReviewReads, MailDeliveryReviewOperations,
                      MailDeliveryReviewReads, ManualReviewOperations, ManualReviewReads)
from .settings import SettingsService
from .suppliers import SupplierAdmin, SupplierReads
from .views import _flash_set, _headers, _page

SAFE_MESSAGES = {
    "invalid_amount_or_reason": "Nominal dan alasan adjustment tidak valid.",
    "customer_not_registered": "Customer tidak terdaftar pada Telegram ID tersebut.",
    "invalid_telegram_id": "Telegram ID harus berupa angka positif.",
    "invalid_original_adjustment": "Adjustment asal tidak dapat dibalik.",
    "original_already_reversed": "Adjustment asal sudah dibalik.",
    "reversal_already_open": "Masih ada reversal terbuka untuk adjustment ini.",
    "reversal_insufficient_funds": "Saldo tersedia tidak cukup untuk reversal penuh. Reversal dicatat untuk review.",
    "preview_unavailable": "Preview sudah kedaluwarsa atau tidak tersedia. Buat preview baru.",
    "preview_binding_mismatch": "Preview berubah atau bukan milik sesi ini. Muat ulang halaman.",
    "invalid_password": "Password Owner tidak valid.",
    "owner_locked": "Konfirmasi Owner dikunci sementara setelah beberapa percobaan gagal.",
    "confirmation_busy": "Konfirmasi lain sedang diproses. Coba lagi sebentar.",
    "stale_authentication": "Konfirmasi sudah tidak berlaku. Muat ulang lalu coba lagi.",
    "operator_forbidden": "Akun ini tidak memiliki izin untuk tindakan tersebut.",
    "owner_notification_unavailable": "Penerima notifikasi Owner belum dikonfigurasi.",
    "customer_api_availability_conflict": "Status Customer API berubah. Muat ulang halaman.",
    "customer_api_availability_invalid": "Status Customer API tidak valid.",
    "checkout_maintenance_conflict": "Checkout Maintenance berubah. Muat ulang halaman.",
    "checkout_maintenance_invalid": "Catatan Checkout Maintenance tidak valid.",
    "checkout_maintenance_missing": "Checkout Maintenance belum dikonfigurasi.",
    "provider_retired": "Provider tersebut tidak tersedia untuk checkout baru.",
    "provider_unavailable": "Provider belum memenuhi readiness. Pilihan tidak diaktifkan.",
    "provider_switch_conflict": "Versi pengaturan berubah. Muat ulang halaman.",
    "payment_setting_missing": "Pengaturan pembayaran tidak tersedia.",
    "binance_mismatch_evidence_required": "Pembayaran Binance ini belum memiliki bukti mismatch untuk direview.",
    "manual_payment_required": "Pembayaran manual tidak ditemukan pada order ini.",
    "invalid_order_transition": "Status order berubah. Muat ulang halaman.",
    "payment_receipt_held": "Pembayaran ini ditahan untuk review receipt.",
    "review_not_found": "Review tidak ditemukan.",
    "review_resolution_invalid": "Tindakan review tidak valid.",
    "paid_review_required": "Review ini belum dapat dipenuhi pada status pembayaran saat ini.",
    "refund_not_confirmed": "Refund belum terkonfirmasi penuh.",
    "uncertain_refund_requires_confirmation": "Refund uncertain memerlukan referensi yang sama.",
    "handoff_requires_supplier_review": "Handoff hanya tersedia untuk review fulfillment supplier.",
    "handoff_invalid": "Data handoff tidak valid.",
    "manual_handoff_invalid": "Data handoff tidak valid.",
    "manual_handoff_key_conflict": "Kunci handoff sudah digunakan untuk data berbeda.",
    "manual_handoff_state_invalid": "Status fulfillment tidak mengizinkan handoff.",
    "manual_handoff_quantity_invalid": "Jumlah handoff melebihi sisa fulfillment.",
    "manual_handoff_review_invalid": "Review handoff tidak valid.",
    "manual_delivery_invalid": "Data kredensial manual tidak valid.",
    "manual_delivery_quantity_invalid": "Jumlah kredensial harus sama dengan sisa fulfillment.",
    "manual_delivery_state_invalid": "Fulfillment belum siap menerima kredensial manual.",
    "supplier_evidence_invalid": "Bukti no-debit tidak valid.",
    "supplier_evidence_conflict": "Bukti no-debit sudah tercatat dengan data berbeda.",
    "supplier_recovery_unavailable": "Status purchase tidak mengizinkan tindakan tersebut.",
    "supplier_fulfillment_missing": "Fulfillment supplier tidak ditemukan.",
    "supplier_history_inconclusive": "Riwayat supplier belum konklusif.",
    "refund_entitlement_missing": "Entitlement refund tidak ditemukan.",
    "refund_entitlement_invalid": "Entitlement refund tidak valid untuk data ini.",
    "telegram_message_id_invalid": "Telegram message ID harus angka positif.",
    "delivery_review_unavailable": "Effect sudah tidak berada dalam antrean review.",
    "duplicate_risk_not_acknowledged": "Centang risiko duplikat sebelum resend.",
    "receipt_resend_requires_receipt_group": "Resend receipt group mengikuti flow receipt P25.",
    "credential_mail_not_eligible": "Email data akun hanya untuk order lunas yang aktif.",
    "credential_mail_unavailable": "Alamat email pengiriman tidak tersedia.",
    "credential_mail_busy": "Pengiriman email masih berjalan.",
    "credential_mail_review": "Status email perlu diperiksa sebelum dikirim ulang.",
    "payment_not_reconcilable": "Pembayaran ini tidak dapat direkonsiliasi.",
    "payment_provider_not_reconcilable": "Provider pembayaran ini tidak memiliki reconciliation admin.",
    "bsc_receiver_invalid": "Alamat/label receiver BSC tidak valid.",
    "bsc_receiver_exists": "Alamat receiver sudah terdaftar.",
    "bsc_receiver_missing": "Receiver BSC tidak ditemukan.",
    "bsc_receiver_unverified": "Receiver harus terverifikasi sebelum diaktifkan.",
    "invalid_usdt_policy": "Kebijakan USDT tidak valid.",
    "invalid_usdt_terms": "Terms USDT tidak valid.",
    "invalid_usdt_presets": "Preset USDT tidak valid (maksimal 8 nominal positif).",
    "funding_policy_conflict": "Kebijakan berubah. Muat ulang halaman.",
    "funding_policy_missing": "Kebijakan belum dikonfigurasi.",
    "owner_notification_unavailable": "Penerima notifikasi Owner belum dikonfigurasi.",
    "sales_proof_not_failed": "Sales Proof Post tidak dalam status gagal. Retry tidak dijalankan.",
    "sales_proof_not_ready": "Sales Proof Post belum memiliki target Store Channel yang dapat dikirimi.",
    "fake_sales_proof_invalid": "Pilih produk aktif dengan stok tersedia dan muat ulang formulir info pembelian fake.",
    "delivery_evidence_required": "Referensi bukti yang valid dan konfirmasi penerimaan wajib diisi.",
}


def _message(error):
    code = getattr(error, "args", [None])[0] or getattr(error, "code", None)
    if isinstance(code, str):
        return SAFE_MESSAGES.get(code, "Tindakan tidak dapat diproses pada status saat ini.")
    return "Tindakan tidak dapat diproses pada status saat ini."


def _fail(request, error, target):
    _flash_set(request, _message(error), "danger")
    return _headers(HttpResponseRedirect(target))


def _ok(request, message, target):
    _flash_set(request, message, "success")
    return _headers(HttpResponseRedirect(target))


def _int(value, default=1, minimum=1, maximum=10_000):
    try:
        return min(maximum, max(minimum, int(value)))
    except (TypeError, ValueError):
        return default


def _text(value, maximum=500):
    return value.strip()[:maximum] if isinstance(value, str) else ""


def _truth(value):
    return value in ("1", "true", "on", "yes")


# -- Pesanan ------------------------------------------------------------

@access.guard
def orders_tab(request):
    page = _int(request.GET.get("page"), 1, 1, 10_000)
    data = OrderReads().list_orders(request.GET, page)
    return _page(request, "admin/orders.html", "Pesanan",
                 navigation.navigation("pesanan", "pesanan"), **data)


@access.guard
def order_detail(request, order_id):
    data = OrderReads().detail(order_id)
    if data is None:
        raise Http404()
    return _page(request, "admin/order_detail.html", f"Pesanan {data['invoice_number']}",
                 navigation.navigation("pesanan", "pesanan"), order=data)


@require_POST
@access.guard
def order_complete_manual(request, order_id):
    target = f"/admin/orders/{order_id}"
    if not access.can_manage_operations(request):
        raise Http404()
    try:
        OrderOperations().complete_manual(order_id, request.admin_user["id"])
    except CheckoutError as error:
        return _fail(request, error, target)
    return _ok(request, "Pembayaran disetujui dan lifecycle order diproses.", target)


@require_POST
@access.guard
def order_reject_manual(request, order_id):
    target = f"/admin/orders/{order_id}"
    try:
        OrderOperations().reject_manual(order_id, request.admin_user["id"])
    except CheckoutError as error:
        return _fail(request, error, target)
    return _ok(request, "Pembayaran manual ditolak dan stok dilepas.", target)


@require_POST
@access.guard
def order_cancel_release(request, order_id):
    target = f"/admin/orders/{order_id}"
    try:
        OrderOperations().cancel_release(order_id, request.admin_user["id"])
    except CheckoutError as error:
        return _fail(request, error, target)
    return _ok(request, "Order pending dibatalkan dan stok dilepas.", target)


@require_POST
@access.guard
def order_credential_mail(request, order_id):
    target = f"/admin/orders/{order_id}"
    try:
        OrderOperations().resend_credential_mail(
            order_id, request.admin_user["id"], request,
            request.META.get("REMOTE_ADDR"))
    except CheckoutError as error:
        return _fail(request, error, target)
    return _ok(request, "Pengiriman ulang email data akun dicatat.", target)


@require_POST
@access.guard
def order_sales_proof(request, order_id):
    from ..notifications.proof_publication import SalesProofPublication
    from ..notifications.runtime import notification_service
    target = f"/admin/orders/{order_id}"
    if not access.can_manage_operations(request):
        raise Http404()
    try:
        SalesProofPublication(notification_service()).retry(
            order_id, request.admin_user["id"], request.META.get("REMOTE_ADDR"))
    except CheckoutError as error:
        return _fail(request, error, target)
    return _ok(request, "Retry Sales Proof Post dijalankan.", target)


@require_POST
@access.guard
def payment_reconcile(request, payment_id):
    data = OrderReads().detail_for_payment(payment_id)
    target = f"/admin/orders/{data}" if data else "/admin?tab=pesanan"
    try:
        OrderOperations().reconcile(payment_id, request.admin_user["id"],
                                    request.META.get("REMOTE_ADDR"))
    except CheckoutError as error:
        return _fail(request, error, target)
    return _ok(request, "Reconciliation pembayaran dicatat.", target)


# -- Manual Fulfillment Review -----------------------------------------

@access.guard
def manual_reviews(request):
    page = _int(request.GET.get("page"))
    data = ManualReviewReads().index(page)
    return _page(request, "admin/manual_reviews.html", "Manual Fulfillment Review",
                 navigation.navigation(None, "reviews"), **data)


@access.guard
def manual_review(request, review_id):
    data = ManualReviewReads().show(review_id)
    if data is None:
        raise Http404()
    return _page(request, "admin/manual_review.html", f"Review #{review_id}",
                 navigation.navigation(None, "reviews"), review=data)


@require_POST
@access.guard
def manual_review_resolve(request, review_id):
    target = f"/admin/manual-fulfillment-reviews/{review_id}"
    action = request.POST.get("action")
    reason = _text(request.POST.get("reason"), 2000)
    reference = _text(request.POST.get("refund_provider_reference"), 255) or None
    result = request.POST.get("refund_provider_result", "success")
    if result not in ("success", "failure", "uncertain"):
        result = "success"
    try:
        ManualReviewOperations().resolve(
            int(review_id), actor_id=request.admin_user["id"], action=action,
            reason=reason, reference=reference, result=result)
    except CheckoutError as error:
        return _fail(request, error, target)
    return _ok(request, "Review diselesaikan sesuai tindakan operator.", target)


@require_POST
@access.guard
def manual_review_handoff(request, review_id):
    target = f"/admin/manual-fulfillment-reviews/{review_id}"
    try:
        ManualReviewOperations().hand_off(
            int(review_id), actor_id=request.admin_user["id"],
            fulfillment_id=_int(request.POST.get("fulfillment_id")),
            quantity=_int(request.POST.get("quantity")),
            reason=_text(request.POST.get("reason"), 2000),
            idempotency_key=_text(request.POST.get("handoff_key"), 64) or None)
    except CheckoutError as error:
        return _fail(request, error, target)
    return _ok(request, "Penyerahan manual dicatat; purchase supplier tetap unresolved.", target)


# -- Manual Delivery Review --------------------------------------------

@access.guard
def delivery_reviews(request):
    page = _int(request.GET.get("page"))
    data = DeliveryReviewReads().index(page)
    return _page(request, "admin/delivery_reviews.html", "Manual Delivery Review",
                 navigation.navigation(None, "delivery"), **data)


@access.guard
def delivery_review(request, effect_id):
    data = DeliveryReviewReads().show(effect_id)
    if data is None:
        raise Http404()
    return _page(request, "admin/delivery_review.html", "Manual Delivery Review",
                 navigation.navigation(None, "delivery"), effect=data)


@require_POST
@access.guard
def delivery_mark_sent(request, effect_id):
    target = f"/admin/telegram-outbound-effects/{effect_id}"
    try:
        DeliveryReviewOperations().mark_sent(
            effect_id, message_id=request.POST.get("telegram_message_id", ""),
            actor_id=request.admin_user["id"], ip=request.META.get("REMOTE_ADDR"),
            mail_confirmed=request.POST.get("confirm_mail_received") == "1",
            evidence_reference=request.POST.get("evidence_reference", ""))
    except CheckoutError as error:
        return _fail(request, error, target)
    return _ok(request, "Effect ditandai sent tanpa mengirim pesan baru.", target)


@require_POST
@access.guard
def delivery_resend(request, effect_id):
    target = f"/admin/telegram-outbound-effects/{effect_id}"
    try:
        created = DeliveryReviewOperations().resend(
            effect_id, actor_id=request.admin_user["id"],
            confirm_duplicate_risk=_truth(request.POST.get("confirm_duplicate_risk")),
            ip=request.META.get("REMOTE_ADDR"))
    except CheckoutError as error:
        return _fail(request, error, target)
    return _ok(request, "Versi resend baru dibuat. Periksa risiko duplikasi.",
               f"/admin/telegram-outbound-effects/{created['id']}")


@access.guard
def mail_delivery_reviews(request):
    data = MailDeliveryReviewReads().index(_int(request.GET.get("page")))
    return _page(request, "admin/mail_delivery_reviews.html", "Manual Delivery Review",
                 navigation.navigation(None, "delivery"), **data)


@access.guard
def mail_delivery_review(request, kind, work_id):
    data = MailDeliveryReviewReads().show(kind, work_id)
    if data is None:
        raise Http404()
    return _page(request, "admin/mail_delivery_review.html", "Manual Delivery Review",
                 navigation.navigation(None, "delivery"), item=data)


@require_POST
@access.guard
def mail_delivery_mark_sent(request, kind, work_id):
    target = f"/admin/mail-delivery-reviews/{kind}/{work_id}"
    try:
        MailDeliveryReviewOperations().mark_sent(
            kind, work_id, evidence_reference=request.POST.get("evidence_reference", ""),
            affirmative_evidence=request.POST.get("confirm_mark_sent") == "1",
            actor_id=request.admin_user["id"], ip=request.META.get("REMOTE_ADDR"))
    except CheckoutError as error:
        return _fail(request, error, target)
    return _ok(request, "Email ditandai terkirim berdasarkan referensi bukti.", "/admin/mail-delivery-reviews")


@require_POST
@access.guard
def mail_delivery_resend(request, kind, work_id):
    target = f"/admin/mail-delivery-reviews/{kind}/{work_id}"
    try:
        MailDeliveryReviewOperations().resend(
            kind, work_id, actor_id=request.admin_user["id"],
            confirm_duplicate_risk=_truth(request.POST.get("confirm_duplicate_risk")),
            ip=request.META.get("REMOTE_ADDR"))
    except CheckoutError as error:
        return _fail(request, error, target)
    return _ok(request, "Pengiriman ulang masuk antrean untuk diproses worker.", "/admin/mail-delivery-reviews")


# -- Saldo IDR ----------------------------------------------------------

def _owner(request):
    access.require_owner(request)


@access.guard
def balance_adjustments(request):
    _owner(request)
    telegram_id = _text(request.GET.get("telegram_id"), 19) or None
    preview_id = _text(request.GET.get("preview"), 36) or None
    page = _int(request.GET.get("page"))
    data = BalanceReads().adjustment_index(
        telegram_id=telegram_id, preview_id=preview_id, page=page, request=request)
    return _page(request, "admin/adjustments.html", "Saldo & adjustment",
                 navigation.navigation(None, "adjustments"), **data)


@require_POST
@access.guard
def balance_preview(request):
    _owner(request)
    telegram_id = _text(request.POST.get("telegram_id"), 19)
    amount = _text(request.POST.get("amount"), 19)
    reason = _text(request.POST.get("reason"), 500)
    category = request.POST.get("category", "owner_attested")
    if category not in ("owner_attested", "promotional_grant", "correction"):
        category = "owner_attested"
    target = f"/admin/store-balance/adjustments?telegram_id={telegram_id}"
    try:
        preview = BalanceAdmin().request_adjustment(
            request, telegram_id, amount, reason, category=category)
    except Exception as error:
        return _fail(request, error, target)
    return _ok(request, "Preview dibuat. Periksa Customer dan nominal sebelum password Owner.",
               f"/admin/store-balance/adjustments?telegram_id={preview['telegram_id']}"
               f"&preview={preview['id']}")


@require_POST
@access.guard
def balance_reversal(request):
    _owner(request)
    telegram_id = _text(request.POST.get("telegram_id"), 19)
    source_id = _text(request.POST.get("source_id"), 36)
    target = f"/admin/store-balance/adjustments?telegram_id={telegram_id}"
    try:
        preview = BalanceAdmin().request_reversal(request, telegram_id, source_id)
    except Exception as error:
        return _fail(request, error, target)
    return _ok(request, "Preview reversal dibuat. Periksa adjustment asal sebelum konfirmasi.",
               f"/admin/store-balance/adjustments?telegram_id={preview['telegram_id']}"
               f"&preview={preview['id']}")


@require_POST
@access.guard
def balance_confirm(request, request_id):
    _owner(request)
    preview = BalanceReads().db.one(
        "SELECT telegram_id FROM public.balance_adjustment_requests WHERE id=%s",
        (str(request_id),))
    target = f"/admin/store-balance/adjustments?preview={request_id}"
    if preview:
        target = f"/admin/store-balance/adjustments?telegram_id={preview['telegram_id']}"
    try:
        BalanceAdmin().confirm_adjustment(
            request, str(request_id), _text(request.POST.get("fingerprint"), 64),
            _int(request.POST.get("version"), 0, 0, 2**31), request.POST.get("password", ""))
    except Exception as error:
        return _fail(request, error, target)
    return _ok(request, "Adjustment diterapkan melalui ledger dan audit.", target)


@require_POST
@access.guard
def balance_cancel(request, request_id):
    _owner(request)
    preview = BalanceReads().db.one(
        "SELECT telegram_id FROM public.balance_adjustment_requests WHERE id=%s",
        (str(request_id),))
    target = f"/admin/store-balance/adjustments?preview={request_id}"
    if preview:
        target = f"/admin/store-balance/adjustments?telegram_id={preview['telegram_id']}"
    try:
        BalanceAdmin().close_adjustment(
            request, str(request_id), _text(request.POST.get("fingerprint"), 64),
            _int(request.POST.get("version"), 0, 0, 2**31), "cancelled")
    except Exception as error:
        return _fail(request, error, target)
    return _ok(request, "Preview dibatalkan tanpa efek ledger.", target)


@require_POST
@access.guard
def balance_compensate(request, top_up_id):
    _owner(request)
    row = BalanceReads().db.one(
        """SELECT t.id,t.customer_id,t.amount,t.status,c.telegram_id
           FROM public.store_balance_top_ups t JOIN public.customers c ON c.id=t.customer_id
           WHERE t.id=%s""", (str(top_up_id),))
    target = "/admin/store-balance/reports"
    if row is None:
        return _fail(request, CheckoutError("preview_unavailable"), target)
    reason = _text(request.POST.get("reason"), 500) or (
        "Pemulihan top-up IDR yang tidak terselesaikan.")
    try:
        preview = BalanceAdmin().request_adjustment(
            request, str(row["telegram_id"]), str(row["amount"]), reason,
            category="top_up_recovery", source_id=str(row["id"]))
    except Exception as error:
        return _fail(request, error, target)
    return _ok(request, "Preview pemulihan top-up dibuat. Konfirmasi dengan password Owner.",
               f"/admin/store-balance/adjustments?telegram_id={row['telegram_id']}"
               f"&preview={preview['id']}")


@access.guard
def balance_topups(request):
    page = _int(request.GET.get("page"))
    status = request.GET.get("status")
    telegram_id = request.GET.get("telegram_id")
    data = BalanceReads().topups(status=status, telegram_id=telegram_id, page=page)
    return _page(request, "admin/topups.html", "Top-up IDR",
                 navigation.navigation(None, "topups"), **data)


# -- Saldo USDT ---------------------------------------------------------

@access.guard
def usdt_review(request):
    _owner(request)
    top_up = _text(request.GET.get("top_up"), 36) or None
    preview_id = _text(request.GET.get("preview"), 36) or None
    page = _int(request.GET.get("page"))
    reads = BalanceReads()
    data = reads.usdt_review_index(
        top_up_id=top_up, preview_id=preview_id, page=page, request=request)
    return _page(request, "admin/usdt_review.html", "USDT review",
                 navigation.navigation(None, "usdt"), **data)


@require_POST
@access.guard
def usdt_preview(request):
    _owner(request)
    top_up_id = _text(request.POST.get("top_up_id"), 36)
    decision = request.POST.get("decision", "settled")
    amount = _text(request.POST.get("amount_micro"), 19)
    hint = _text(request.POST.get("hint"), 255)
    reason = _text(request.POST.get("reason"), 1000)
    return_hash = _text(request.POST.get("return_hash"), 66) or None
    authorization_id = _text(request.POST.get("authorization_id"), 36) or None
    target = f"/admin/store-balance/usdt-review?top_up={top_up_id}"
    try:
        if not re.fullmatch(r"[1-9][0-9]{0,18}", amount):
            raise CheckoutError("invalid_usdt_policy")
        preview = BalanceAdmin().request_usdt_review(
            request, top_up_id, decision, int(amount), hint, reason,
            return_hash=return_hash, authorization_id=authorization_id)
    except Exception as error:
        return _fail(request, error, target)
    return _ok(request, "Preview review USDT dibuat. Konfirmasi dengan password Owner.",
               f"/admin/store-balance/usdt-review?top_up={top_up_id}&preview={preview['id']}")


@require_POST
@access.guard
def usdt_confirm(request, request_id):
    _owner(request)
    preview = BalanceReads().db.one(
        "SELECT top_up_id FROM public.usdt_top_up_review_requests WHERE id=%s",
        (str(request_id),))
    target = f"/admin/store-balance/usdt-review?preview={request_id}"
    if preview:
        target = f"/admin/store-balance/usdt-review?top_up={preview['top_up_id']}"
    try:
        BalanceAdmin().confirm_usdt_review(
            request, str(request_id), _text(request.POST.get("fingerprint"), 64),
            _int(request.POST.get("version"), 0, 0, 2**31), request.POST.get("password", ""))
    except Exception as error:
        return _fail(request, error, target)
    return _ok(request, "Review USDT diselesaikan dengan evidence dan audit.", target)


# -- Supplier -----------------------------------------------------------

@access.guard
def suppliers(request):
    if not access.can_manage_operations(request):
        raise Http404()
    reads = SupplierReads()
    invoice = _text(request.GET.get("invoice"), 80)
    page = _int(request.GET.get("page"))
    return _page(request, "admin/suppliers.html", "Supplier",
                 navigation.navigation(None, "suppliers"),
                 connections=reads.connections(), rates=reads.rates(),
                 mappings=reads.mappings(), purchases=reads.purchases(
                     invoice=invoice, page=page),
                 refunds=reads.external_refunds(), handoffs=reads.history())


@require_POST
@access.guard
def supplier_operate(request, fulfillment_id):
    if not access.can_manage_operations(request):
        raise Http404()
    target = "/admin/suppliers"
    action = request.POST.get("action")
    actor_id = request.admin_user["id"]
    admin = SupplierAdmin()
    try:
        if action == "manual":
            credentials = [
                line.strip() for line in re.split(r"\r\n|\r|\n",
                                                  request.POST.get("credentials", ""))
                if line.strip()
            ]
            admin.manual_credentials(int(fulfillment_id), credentials, actor_id=actor_id)
        elif action == "no_debit":
            admin.no_debit(
                int(fulfillment_id), actor_id=actor_id,
                purchase_id=_int(request.POST.get("purchase_id")),
                evidence_source=request.POST.get("evidence_source", "provider_case"),
                provider_reference=_text(request.POST.get("provider_reference"), 255),
                note=_text(request.POST.get("note"), 4000))
        elif action == "owner_no_debit":
            admin.owner_no_debit(
                int(fulfillment_id), actor_id=actor_id,
                purchase_id=_int(request.POST.get("purchase_id")))
        else:
            raise CheckoutError("supplier_recovery_unavailable")
    except CheckoutError as error:
        return _fail(request, error, target)
    return _ok(request, "Tindakan supplier diproses. Periksa status dan bukti.", target)


@require_POST
@access.guard
def supplier_refund_external(request, refund_id):
    if not access.can_manage_operations(request):
        raise Http404()
    target = "/admin/suppliers"
    try:
        SupplierAdmin().confirm_external_refund(
            refund_id, actor_id=request.admin_user["id"],
            amount=_int(request.POST.get("amount"), 1, 1, 2**63 - 1),
            currency=request.POST.get("currency", "IDR"),
            reference=_text(request.POST.get("reference"), 255),
            result=request.POST.get("result", "success"))
    except CheckoutError as error:
        return _fail(request, error, target)
    return _ok(request, "Refund eksternal terkonfirmasi pada entitlement asli.", target)


# -- Pengaturan ---------------------------------------------------------

def _settings_context(request):
    settings = SettingsService()
    balance = BalanceReads()
    return {
        "payment": settings.payment(),
        "maintenance": settings.maintenance(),
        "api": settings.api_availability(),
        "receivers": settings.receivers(),
        "funding_policy": balance.funding_policy(),
        "usdt_policy": balance.usdt_policy(),
        "pauses": balance.pauses(),
        "restore_gate": balance.restore_gate(),
        "alerts": balance.alerts(),
        "owner": access.can_owner_controls(request),
    }


@access.guard
def settings_tab(request):
    if not access.can_manage_settings(request):
        raise Http404()
    return _page(request, "admin/settings.html", "Pengaturan",
                 navigation.navigation("pengaturan", "pengaturan"),
                 **_settings_context(request))


@require_POST
@access.guard
def settings_provider(request):
    _owner(request)
    target = "/admin?tab=pengaturan"
    provider = request.POST.get("payment_mode")
    version = _int(request.POST.get("payment_provider_version"), 0, 0, 2**31)
    try:
        SettingsService().change_provider(
            provider, expected_version=version, actor_id=request.admin_user["id"],
            ip=request.META.get("REMOTE_ADDR"))
    except CheckoutError as error:
        return _fail(request, error, target)
    return _ok(request, "Provider checkout baru diperbarui; pembayaran lama tidak berubah.", target)


@require_POST
@access.guard
def settings_channel(request):
    _owner(request)
    target = "/admin?tab=pengaturan"
    try:
        SettingsService().change_channel(
            request.POST.get("sakurupiah_channel"),
            expected_version=_int(request.POST.get("payment_provider_version"), 0, 0, 2**31),
            actor_id=request.admin_user["id"], ip=request.META.get("REMOTE_ADDR"))
    except CheckoutError as error:
        return _fail(request, error, target)
    return _ok(request, "Channel Sakurupiah diperbarui untuk transaksi baru.", target)


@require_POST
@access.guard
def settings_maintenance(request):
    if not access.can_manage_operations(request):
        raise Http404()
    target = "/admin?tab=pengaturan"
    try:
        SettingsService().change_maintenance(
            active=_truth(request.POST.get("active")),
            public_note=_text(request.POST.get("public_note"), 500),
            expected_version=_int(request.POST.get("version"), 0, 0, 2**31),
            actor_id=request.admin_user["id"], ip=request.META.get("REMOTE_ADDR"))
    except CheckoutError as error:
        return _fail(request, error, target)
    return _ok(request, "Checkout Maintenance diperbarui. Order lama tetap berjalan.", target)


@require_POST
@access.guard
def settings_customer_api(request):
    _owner(request)
    target = "/admin?tab=pengaturan"
    try:
        SettingsService().change_api_availability(
            enabled=_truth(request.POST.get("enabled")),
            expected_version=_int(request.POST.get("version"), 0, 0, 2**31),
            actor_id=request.admin_user["id"], ip=request.META.get("REMOTE_ADDR"))
    except CheckoutError as error:
        return _fail(request, error, target)
    return _ok(request, "Status Customer API diperbarui.", target)


@require_POST
@access.guard
def settings_funding_policy(request):
    if not access.can_manage_operations(request):
        raise Http404()
    target = "/admin?tab=pengaturan"
    presets = [
        value.strip() for value in re.split(r"[\s,;]+",
                                            request.POST.get("preset_amounts", ""))
        if value.strip()
    ]
    try:
        BalanceAdmin().change_funding_policy(
            _text(request.POST.get("minimum_amount"), 19), presets,
            expected_version=_int(request.POST.get("funding_version"), 0, 0, 2**31),
            actor_id=request.admin_user["id"], ip=request.META.get("REMOTE_ADDR"))
    except Exception as error:
        return _fail(request, error, target)
    return _ok(request, "Kebijakan top-up IDR disimpan.", target)


@require_POST
@access.guard
def settings_usdt_policy(request):
    _owner(request)
    target = "/admin?tab=pengaturan"
    presets = []
    for value in re.split(r"[\s,;]+", request.POST.get("preset_amounts_micro", "")):
        value = value.strip()
        if not value:
            continue
        if not re.fullmatch(r"[1-9][0-9]{0,18}", value):
            return _fail(request, CheckoutError("invalid_usdt_presets"), target)
        presets.append(int(value))
    try:
        fee = request.POST.get("application_fee_micro", "0")
        if not re.fullmatch(r"0|[1-9][0-9]{0,18}", (fee or "").strip()):
            raise CheckoutError("invalid_usdt_policy")
        BalanceAdmin().change_usdt_policy(
            funding_enabled=_truth(request.POST.get("funding_enabled")),
            confirmation_ttl_seconds=_int(request.POST.get("confirmation_ttl_seconds"), 600, 60, 3600),
            application_fee_micro=int(fee.strip()),
            terms_body=request.POST.get("terms_body", ""),
            preset_amounts=presets,
            expected_version=_int(request.POST.get("usdt_version"), 0, 0, 2**31),
            actor_id=request.admin_user["id"], ip=request.META.get("REMOTE_ADDR"))
    except Exception as error:
        return _fail(request, error, target)
    return _ok(request, "Kebijakan USDT disimpan. Rail tetap mengikuti readiness.", target)


@require_POST
@access.guard
def settings_usdt_pause(request, scope):
    _owner(request)
    target = "/admin?tab=pengaturan"
    try:
        BalanceAdmin().pause(request, scope, _text(request.POST.get("reason"), 191))
    except Exception as error:
        return _fail(request, error, target)
    return _ok(request, "Scope USDT dijeda. Settlement dana lama tetap berjalan.", target)


@require_POST
@access.guard
def settings_usdt_resume(request, scope):
    _owner(request)
    target = "/admin?tab=pengaturan"
    try:
        BalanceAdmin().resume(request, scope)
    except Exception as error:
        return _fail(request, error, target)
    return _ok(request, "Scope USDT dilanjutkan.", target)


@require_POST
@access.guard
def settings_receiver_add(request):
    _owner(request)
    target = "/admin?tab=pengaturan"
    try:
        SettingsService().add_receiver(
            label=request.POST.get("label", ""), address=request.POST.get("address", ""),
            actor_id=request.admin_user["id"], ip=request.META.get("REMOTE_ADDR"))
    except CheckoutError as error:
        return _fail(request, error, target)
    return _ok(request, "Receiver BSC ditambahkan sebagai pending. Verifikasi tetap wajib.", target)


@require_POST
@access.guard
def settings_receiver_state(request, receiver_id):
    _owner(request)
    target = "/admin?tab=pengaturan"
    action = request.POST.get("action")
    try:
        settings = SettingsService()
        if action == "activate":
            settings.activate_receiver(receiver_id, actor_id=request.admin_user["id"],
                                       ip=request.META.get("REMOTE_ADDR"))
        elif action == "deactivate":
            settings.deactivate_receiver(receiver_id, actor_id=request.admin_user["id"],
                                         ip=request.META.get("REMOTE_ADDR"))
        else:
            raise CheckoutError("bsc_receiver_invalid")
    except CheckoutError as error:
        return _fail(request, error, target)
    return _ok(request, "Status receiver BSC diperbarui.", target)


# -- Laporan & audit ----------------------------------------------------

@access.guard
def reports(request):
    filters = normalize_filters(request.GET)
    overview = Reporting().overview(filters)
    history = Reporting().history(filters, page=_int(request.GET.get("page")))
    return _page(request, "admin/reports.html", "Laporan",
                 navigation.navigation(None, "laporan"),
                 filters=filters, overview=overview, history=history)


@access.guard
def reports_export(request):
    filters = normalize_filters(request.GET)
    body = Reporting().export_csv(filters)
    response = HttpResponse(body, content_type="text/csv; charset=utf-8")
    response["Content-Disposition"] = (
        'attachment; filename="' + export_filename(filters) + '"')
    response["Cache-Control"] = "no-store, private"
    response["Referrer-Policy"] = "no-referrer"
    response["X-Content-Type-Options"] = "nosniff"
    return response


@access.guard
def audit_log(request):
    page = _int(request.GET.get("page"))
    data = Reporting().audit(request.GET, page=page)
    return _page(request, "admin/audit.html", "Audit",
                 navigation.navigation(None, "audit"), **data)
