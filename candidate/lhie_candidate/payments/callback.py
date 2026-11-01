"""Authenticated callback hints; settlement requires an independent provider observation."""
import hashlib
import hmac
import json

from django.conf import settings
from django.db import connection

from ..http import response


def _unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("duplicate JSON key")
        result[key] = value
    return result


def _callback(body):
    payload = json.loads(body, object_pairs_hook=_unique_object)
    if not isinstance(payload, dict):
        raise ValueError("invalid callback")
    trx_id, merchant_ref = payload.get("trx_id"), payload.get("merchant_ref")
    for value in (trx_id, merchant_ref):
        if (not isinstance(value, str) or not value or value.strip() != value
                or len(value.encode("utf-8")) > 255):
            raise ValueError("invalid callback identity")
    status = payload.get("status")
    code = payload.get("status_kode")
    if isinstance(code, str) and code in ("0", "1", "2"):
        code = int(code)
    if type(code) is not int or (status, code) not in (("pending", 0), ("berhasil", 1), ("expired", 2)):
        raise ValueError("invalid callback status")
    return merchant_ref, trx_id, status, code


def receive(request):
    if request.method != "POST":
        return response(405, "method_not_allowed")
    key = settings.RUNTIME.p08_internal_key
    try:
        supplied = request.headers.get("X-P08-Internal-Secret", "").encode("ascii")
        if not key or not hmac.compare_digest(supplied, key.encode("ascii")):
            return response(403, "forbidden")
        length = request.headers.get("Content-Length", "")
        if (not length.isascii() or not length.isdigit() or not 0 < int(length) <= 16384
                or request.headers.get("Transfer-Encoding") or request.content_type != "application/json"
                or request.headers.get("X-Callback-Event") != "payment_status"):
            return response(400, "bad_request")
        body = request.body
        if len(body) != int(length):
            return response(400, "bad_request")
        merchant_ref, trx_id, status, code = _callback(body)
    except (ValueError, UnicodeError, TypeError, RecursionError):
        return response(400, "bad_request")
    sanitized = {"merchant_ref": merchant_ref, "status": status, "status_kode": code, "trx_id": trx_id}
    canonical = {"operation": "callback", "merchant_ref": merchant_ref, "provider_reference": trx_id,
                 "provider_status": status, "payload": sanitized}
    encoded = json.dumps(canonical, ensure_ascii=False, separators=(",", ":")).replace("\u2028", "\\u2028").replace("\u2029", "\\u2029")
    digest = hashlib.sha256(encoded.encode("utf-8")).hexdigest()
    try:
        with connection.cursor() as cursor:
            cursor.execute(
                "INSERT INTO public.payment_events "
                "(provider,event_type,merchant_ref,provider_reference,provider_status,raw_payload,"
                "idempotency_key,received_at,last_received_at,received_count) "
                "VALUES ('sakurupiah','callback',%s,%s,%s,%s::jsonb,%s,now(),now(),1) "
                "ON CONFLICT (provider,event_type,idempotency_key) DO UPDATE SET "
                "last_received_at=now(),received_count=public.payment_events.received_count+1",
                [merchant_ref, trx_id, status, json.dumps(sanitized, ensure_ascii=False), digest],
            )
    except Exception:
        return response(503, "unavailable")
    return response(200, "accepted")
