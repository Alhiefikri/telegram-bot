"""DB-backed store settings and fail-closed readiness controls."""

import json
import re
from datetime import datetime

from django.db import transaction

from ..checkout.db import Database
from ..checkout.errors import CheckoutError
from ..checkout.lifecycle import now as lifecycle_now
from ..domain.money import require_integer
from ..payments.settings import ProviderSettings
from .sanitize import mask_identifier

PROVIDERS = ("manual", "klikqris", "sakurupiah")
CHANNELS = ("QRIS", "QRISC", "QRISMU", "QRIS2")
MAINTENANCE_NOTE_LIMIT = 500


def _time(value):
    if isinstance(value, datetime):
        return value.strftime("%Y-%m-%d %H:%M:%S")
    return "" if value is None else str(value)


def _ip(request):
    value = request.META.get("REMOTE_ADDR")
    return value if isinstance(value, str) and len(value) <= 64 else None


def _audit(db, actor_id, action, subject_type, subject_id, old=None, new=None, ip=None):
    db.execute(
        """INSERT INTO public.audit_logs
           (user_id,action,subject_type,subject_id,old_values,new_values,ip_address,created_at)
           VALUES (%s,%s,%s,%s,%s,%s,%s,CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta')""",
        (actor_id, action, subject_type, None if subject_id is None else str(subject_id),
         None if old is None else json.dumps(old, ensure_ascii=False, separators=(",", ":")),
         None if new is None else json.dumps(new, ensure_ascii=False, separators=(",", ":")),
         ip))


class SettingsService:
    def __init__(self, *, using="default", readiness=None, sakurupiah=None):
        self.using = using
        self.db = Database(using)
        self.readiness = readiness or {"manual": lambda: True}
        self.sakurupiah = sakurupiah

    def payment(self):
        row = self.db.one(
            """SELECT id,payment_mode,payment_provider_version,is_active,sakurupiah_channel,
                      qris_payment_ttl_minutes,binance_pay_enabled,binance_payment_ttl_minutes,
                      binance_verification_mode,bsc_payment_enabled,bsc_payment_ttl_minutes,
                      bsc_min_confirmations,bsc_require_finalized_block,
                      bsc_confirmation_grace_minutes,bsc_hash_attempt_limit,
                      admin_telegram_chat_id,admin_username
               FROM public.manual_payment_settings ORDER BY is_active DESC,id LIMIT 1""")
        if row is None:
            return None
        row = dict(row)
        row["provider"] = str(row["payment_mode"] or "manual").lower()
        row["admin_telegram_chat_id_masked"] = mask_identifier(
            row.get("admin_telegram_chat_id"), visible=4)
        row["readiness"] = {
            provider: bool(self._ready(provider)) for provider in PROVIDERS
        }
        return row

    def _ready(self, provider):
        try:
            callback = self.readiness.get(provider)
            return bool(callback and callback())
        except Exception:
            return False

    def change_provider(self, provider, *, expected_version, actor_id, ip=None):
        if provider not in PROVIDERS:
            raise CheckoutError("provider_retired")
        result = ProviderSettings(
            readiness=self.readiness, sakurupiah=self.sakurupiah, using=self.using
        ).change(provider, expected_version=expected_version, actor_user_id=actor_id)
        return result

    def change_channel(self, channel, *, expected_version, actor_id, ip=None):
        if channel not in CHANNELS:
            raise CheckoutError("provider_unavailable")
        if self.sakurupiah is None:
            raise CheckoutError("provider_unavailable")
        return ProviderSettings(
            readiness=self.readiness, sakurupiah=self.sakurupiah, using=self.using
        ).change_channel(channel, expected_version=expected_version, actor_user_id=actor_id)

    def maintenance(self):
        row = self.db.one(
            """SELECT id,is_active,public_note,version,activated_at,updated_at
               FROM public.checkout_maintenance_settings
               WHERE singleton_key='checkout_maintenance'""")
        if row is None:
            return {"active": False, "public_note": "", "version": 0,
                    "activated_label": ""}
        return {
            "active": bool(row["is_active"]),
            "public_note": row["public_note"] or "",
            "version": int(row["version"] or 0),
            "activated_label": _time(row["activated_at"]),
        }

    def change_maintenance(self, *, active, public_note, expected_version, actor_id, ip=None):
        if type(active) is not bool:
            raise CheckoutError("checkout_maintenance_invalid")
        require_integer(expected_version)
        note = (public_note or "").strip()
        if len(note) > MAINTENANCE_NOTE_LIMIT or re.search(r"[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]", note):
            raise CheckoutError("checkout_maintenance_invalid")
        with transaction.atomic(using=self.using):
            actor = self.db.one("SELECT role FROM public.users WHERE id=%s FOR SHARE", (actor_id,))
            if actor is None or actor["role"] not in ("owner", "admin"):
                raise CheckoutError("operator_forbidden")
            row = self.db.one(
                """SELECT * FROM public.checkout_maintenance_settings
                   WHERE singleton_key='checkout_maintenance' FOR UPDATE""")
            if row is None:
                raise CheckoutError("checkout_maintenance_missing")
            if int(row["version"] or 0) != expected_version:
                raise CheckoutError("checkout_maintenance_conflict")
            next_note = note if active else None
            if bool(row["is_active"]) == active and (row["public_note"] or None) == next_note:
                return row
            now = lifecycle_now()
            activated = row["activated_at"] if active and row["is_active"] else (
                now if active else None)
            updated = self.db.one(
                """UPDATE public.checkout_maintenance_settings
                   SET is_active=%s,public_note=%s,version=version+1,activated_at=%s,updated_at=%s
                   WHERE id=%s RETURNING *""",
                (active, next_note, activated, now, row["id"]))
            action = ("checkout_maintenance.activated" if active and not row["is_active"]
                      else "checkout_maintenance.deactivated" if not active and row["is_active"]
                      else "checkout_maintenance.updated")
            _audit(self.db, actor_id, action, "App\\Models\\CheckoutMaintenanceSetting", row["id"],
                   {"active": bool(row["is_active"]), "public_note": row["public_note"]},
                   {"active": active, "public_note": next_note}, ip)
            return updated

    def api_availability(self):
        row = self.db.one(
            """SELECT enabled,version,changed_at FROM public.customer_api_settings
               WHERE singleton_key='customer_api'""")
        if row is None:
            return {"enabled": False, "version": 0, "changed_label": ""}
        return {"enabled": bool(row["enabled"]), "version": int(row["version"] or 0),
                "changed_label": _time(row["changed_at"])}

    def change_api_availability(self, *, enabled, expected_version, actor_id, ip=None):
        if type(enabled) is not bool:
            raise CheckoutError("customer_api_availability_invalid")
        require_integer(expected_version)
        with transaction.atomic(using=self.using):
            actor = self.db.one("SELECT role FROM public.users WHERE id=%s FOR SHARE", (actor_id,))
            if actor is None or actor["role"] != "owner":
                raise CheckoutError("operator_forbidden")
            self.db.execute(
                """INSERT INTO public.customer_api_settings
                   (singleton_key,enabled,version,changed_at,created_at,updated_at)
                   VALUES ('customer_api',false,0,NULL,
                           CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta',
                           CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta')
                   ON CONFLICT (singleton_key) DO NOTHING""")
            row = self.db.one(
                """SELECT * FROM public.customer_api_settings
                   WHERE singleton_key='customer_api' FOR UPDATE""")
            if int(row["version"] or 0) != expected_version:
                raise CheckoutError("customer_api_availability_conflict")
            if bool(row["enabled"]) == enabled:
                return row
            updated = self.db.one(
                """UPDATE public.customer_api_settings
                   SET enabled=%s,version=version+1,
                       changed_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta',
                       updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'
                   WHERE id=%s RETURNING *""", (enabled, row["id"]))
            _audit(self.db, actor_id,
                   "customer_api.availability.enabled" if enabled
                   else "customer_api.availability.disabled",
                   "App\\Models\\CustomerApiSetting", row["id"],
                   {"enabled": bool(row["enabled"]), "version": int(row["version"])},
                   {"enabled": enabled, "version": int(updated["version"])}, ip)
            return updated

    def receivers(self, limit=50):
        rows = self.db.rows(
            """SELECT id,label,address,address_normalized,chain_id,token_contract,
                      is_active,verification_status,verification_method,verified_at,
                      last_assigned_at,created_at
               FROM public.bsc_payment_receivers ORDER BY id DESC LIMIT %s""",
            (max(1, min(100, int(limit))),))
        for row in rows:
            row["id"] = int(row["id"])
            row["address_masked"] = mask_identifier(row["address"], visible=6)
            row["address_normalized_masked"] = mask_identifier(
                row["address_normalized"], visible=6)
            row["verified_label"] = _time(row["verified_at"])
            row["assigned_label"] = _time(row["last_assigned_at"])
            row["status_label"] = {
                "verified": "Terverifikasi", "pending_verification": "Menunggu verifikasi",
                "revoked": "Dicabut",
            }.get(row["verification_status"], row["verification_status"])
        return rows

    def add_receiver(self, *, label, address, actor_id, ip=None):
        if not isinstance(label, str) or not 1 <= len(label.strip()) <= 120:
            raise CheckoutError("bsc_receiver_invalid")
        if (not isinstance(address, str) or re.fullmatch(r"0x[0-9a-fA-F]{40}", address) is None
                or address.lower() == "0x" + "0" * 40):
            raise CheckoutError("bsc_receiver_invalid")
        normalized = address.lower()
        with transaction.atomic(using=self.using):
            actor = self.db.one("SELECT role FROM public.users WHERE id=%s FOR SHARE", (actor_id,))
            if actor is None or actor["role"] != "owner":
                raise CheckoutError("operator_forbidden")
            row = self.db.one(
                """INSERT INTO public.bsc_payment_receivers
                   (label,address,address_normalized,chain_id,token_contract,token_decimals,
                    is_active,verification_status,created_at,updated_at)
                   VALUES (%s,%s,%s,56,'0x55d398326f99059ff775485246999027b3197955',18,
                           false,'pending_verification',
                           CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta',
                           CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta')
                   ON CONFLICT DO NOTHING RETURNING id""",
                (label.strip(), address, normalized))
            if row is None:
                raise CheckoutError("bsc_receiver_exists")
            _audit(self.db, actor_id, "bsc_receiver.created", "App\\Models\\BscPaymentReceiver",
                   row["id"], None, {"address_masked": mask_identifier(address, visible=6),
                                     "verification_status": "pending_verification"}, ip)
            return row

    def deactivate_receiver(self, receiver_id, *, actor_id, ip=None):
        return self._receiver_state(receiver_id, False, actor_id, ip)

    def activate_receiver(self, receiver_id, *, actor_id, ip=None):
        return self._receiver_state(receiver_id, True, actor_id, ip)

    def _receiver_state(self, receiver_id, active, actor_id, ip):
        with transaction.atomic(using=self.using):
            actor = self.db.one("SELECT role FROM public.users WHERE id=%s FOR SHARE", (actor_id,))
            if actor is None or actor["role"] != "owner":
                raise CheckoutError("operator_forbidden")
            row = self.db.one(
                "SELECT * FROM public.bsc_payment_receivers WHERE id=%s FOR UPDATE",
                (int(receiver_id),))
            if row is None:
                raise CheckoutError("bsc_receiver_missing")
            if active and row["verification_status"] != "verified":
                raise CheckoutError("bsc_receiver_unverified")
            if bool(row["is_active"]) == active:
                return row
            updated = self.db.one(
                """UPDATE public.bsc_payment_receivers SET is_active=%s,
                   updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'
                   WHERE id=%s RETURNING *""", (active, row["id"]))
            _audit(self.db, actor_id,
                   "bsc_receiver.activated" if active else "bsc_receiver.deactivated",
                   "App\\Models\\BscPaymentReceiver", row["id"],
                   {"is_active": bool(row["is_active"])}, {"is_active": active}, ip)
            return updated


def request_ip(request):
    return _ip(request)
