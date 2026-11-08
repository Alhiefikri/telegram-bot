"""Owner-only IDR/USDT balance controls over the existing ledger owners.

The service never edits a projection, fabricates a balance, or converts a
currency. Every destructive decision is still bound to a preview, a version or
the fresh Owner password checked by the P15/P17 owners.
"""

import json
import re
from datetime import datetime
from zoneinfo import ZoneInfo

from django.db import transaction

from ..adjustments.password import AdjustmentRejected, owner as owner_row
from ..adjustments.service import BalanceAdjustments
from ..auth.types import legacy_now
from ..checkout.db import Database
from ..compat.crypto import LaravelEncrypter
from ..compat.identity import sha256_digest
from ..domain.money import require_integer
from ..funding.policy_admin import FundingPolicyAdmin
from ..runtime import application_key
from ..usdt.operations_controls import OperationalControls
from ..usdt.review import UsdtTopUpReview
from .sanitize import json_value, mask_identifier, safe_error

JAKARTA = ZoneInfo("Asia/Jakarta")
USDT_SCOPES = ("funding", "spending", "rail_binance_pay", "rail_bsc_usdt", "delivery")
MAX_PRESETS = 8


def _time(value):
    if isinstance(value, datetime):
        return value.strftime("%d %b %Y %H:%M")
    return "-" if value is None else str(value)


def _micro(value):
    if value in (None, ""):
        return ""
    whole, fraction = divmod(int(value), 1_000_000)
    return f"{whole}.{fraction:06d}".rstrip("0").rstrip(".")


def _idr(value):
    return "Rp" + format(int(value or 0), ",").replace(",", ".")


def _ip(request):
    value = request.META.get("REMOTE_ADDR")
    return value if isinstance(value, str) and len(value) <= 64 else None


def _admin_chat(db):
    row = db.one("""SELECT admin_telegram_chat_id FROM public.manual_payment_settings
                    ORDER BY is_active DESC,id LIMIT 1""")
    value = row["admin_telegram_chat_id"] if row else None
    return value if isinstance(value, str) and re.fullmatch(r"[1-9][0-9]{0,18}", value) else None


def _json(value):
    if isinstance(value, str):
        try:
            return json.loads(value)
        except ValueError:
            return None
    return value


class BalanceAdmin:
    def __init__(self, *, using="default", binance=None, bsc=None):
        self.using = using
        self.db = Database(using)
        self.encrypter = LaravelEncrypter(application_key())
        self.binance = binance
        self.bsc = bsc

    def _chat(self):
        chat = _admin_chat(self.db)
        if chat is None:
            raise AdjustmentRejected("owner_notification_unavailable")
        return chat

    def adjustments(self):
        return BalanceAdjustments(
            admin_chat_id=self._chat(), using=self.using, encrypter=self.encrypter)

    def usdt_reviews(self):
        return UsdtTopUpReview(
            admin_chat_id=self._chat(), binance=self.binance, bsc=self.bsc, using=self.using)

    # -- IDR adjustments ---------------------------------------------------
    def request_adjustment(self, request, telegram_id, amount, reason, category="owner_attested",
                           source_id=None, receipt=None):
        return self.adjustments().request(
            request, telegram_id, amount, reason, category=category,
            source_id=source_id, receipt=receipt)

    def request_reversal(self, request, telegram_id, original_id):
        return self.adjustments().request_reversal(request, telegram_id, original_id)

    def confirm_adjustment(self, request, request_id, fingerprint, version, password):
        return self.adjustments().confirm(
            request, request_id, fingerprint, version, password)

    def close_adjustment(self, request, request_id, fingerprint, version, decision):
        return self.adjustments().close(
            request, request_id, fingerprint, version, decision)

    # -- IDR funding policy ------------------------------------------------
    def change_funding_policy(self, minimum_amount, preset_amounts, *, expected_version,
                              actor_id, ip=None):
        return FundingPolicyAdmin(self.using).change(
            minimum_amount, preset_amounts, expected_version=expected_version,
            actor_user_id=actor_id, ip=ip)

    # -- USDT policy and controls -----------------------------------------
    def change_usdt_policy(self, *, funding_enabled, confirmation_ttl_seconds,
                           application_fee_micro, terms_body, preset_amounts,
                           expected_version, actor_id, ip=None):
        if type(funding_enabled) is not bool:
            raise AdjustmentRejected("invalid_usdt_policy")
        require_integer(confirmation_ttl_seconds, minimum=60)
        if not 60 <= confirmation_ttl_seconds <= 3600:
            raise AdjustmentRejected("invalid_usdt_policy")
        require_integer(application_fee_micro)
        if not isinstance(terms_body, str) or not 1 <= len(terms_body.strip()) <= 20000:
            raise AdjustmentRejected("invalid_usdt_terms")
        if (not isinstance(preset_amounts, list) or len(preset_amounts) > MAX_PRESETS
                or any(type(value) is not int or value < 1 for value in preset_amounts)):
            raise AdjustmentRejected("invalid_usdt_presets")
        require_integer(expected_version)
        with transaction.atomic(using=self.using):
            actor = self.db.one("SELECT id,role FROM public.users WHERE id=%s FOR SHARE", (actor_id,))
            if actor is None or actor["role"] != "owner":
                raise AdjustmentRejected("operator_forbidden")
            policy = self.db.one(
                """SELECT * FROM public.customer_usdt_funding_policies
                   WHERE singleton_key='customer_usdt_funding_policy' FOR UPDATE""")
            if policy is None:
                raise AdjustmentRejected("funding_policy_missing")
            if policy["version"] != expected_version:
                raise AdjustmentRejected("funding_policy_conflict")
            old_terms = self.db.one(
                "SELECT body FROM public.customer_usdt_funding_terms WHERE version=%s",
                (policy["terms_version"],))
            body = terms_body.strip()
            terms_version = policy["terms_version"]
            if old_terms is None or old_terms["body"] != body:
                row = self.db.one(
                    "SELECT COALESCE(MAX(version),0) AS v FROM public.customer_usdt_funding_terms")
                terms_version = int(row["v"]) + 1
                self.db.execute(
                    """INSERT INTO public.customer_usdt_funding_terms
                       (version,body,created_by_user_id,created_at) VALUES (%s,%s,%s,%s)""",
                    (terms_version, body, actor_id, legacy_now()))
            changed = (policy["funding_enabled"] != funding_enabled
                       or policy["confirmation_ttl_seconds"] != confirmation_ttl_seconds
                       or policy["application_fee_micro"] != application_fee_micro
                       or policy["terms_version"] != terms_version
                       or _json(policy["preset_amounts_micro"]) != preset_amounts)
            if not changed:
                return policy
            updated = self.db.one(
                """UPDATE public.customer_usdt_funding_policies
                   SET funding_enabled=%s,confirmation_ttl_seconds=%s,application_fee_micro=%s,
                       preset_amounts_micro=%s,terms_version=%s,version=version+1,
                       updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'
                   WHERE id=%s RETURNING *""",
                (funding_enabled, confirmation_ttl_seconds, application_fee_micro,
                 json.dumps(preset_amounts), terms_version, policy["id"]))
            self.db.execute(
                """INSERT INTO public.audit_logs
                   (user_id,action,subject_type,subject_id,old_values,new_values,ip_address,created_at)
                   VALUES (%s,'customer_usdt.funding_policy.updated',
                           'App\\Models\\CustomerUsdtFundingPolicy',%s,%s,%s,%s,%s)""",
                (actor_id, str(policy["id"]),
                 json.dumps({"funding_enabled": policy["funding_enabled"],
                             "confirmation_ttl_seconds": policy["confirmation_ttl_seconds"],
                             "application_fee_micro": str(policy["application_fee_micro"]),
                             "terms_version": policy["terms_version"],
                             "version": policy["version"]}),
                 json.dumps({"funding_enabled": updated["funding_enabled"],
                             "confirmation_ttl_seconds": updated["confirmation_ttl_seconds"],
                             "application_fee_micro": str(updated["application_fee_micro"]),
                             "terms_version": updated["terms_version"],
                             "version": updated["version"]}),
                 ip, legacy_now()))
            return updated

    def pause(self, request, scope, reason):
        return OperationalControls(self.using).pause(request, scope, reason)

    def resume(self, request, scope):
        return OperationalControls(self.using).resume(request, scope)

    def unfreeze(self, request, customer_id, reason, *, dry_run=False):
        return OperationalControls(self.using).unfreeze(
            request, customer_id, reason, dry_run=dry_run)

    # -- USDT exception review --------------------------------------------
    def request_usdt_review(self, request, top_up_id, decision, amount_micro, hint, reason,
                            *, return_hash=None, authorization_id=None):
        return self.usdt_reviews().request(
            request, top_up_id, decision, amount_micro, hint, reason,
            return_hash=return_hash, authorization_id=authorization_id)

    def confirm_usdt_review(self, request, request_id, fingerprint, version, password):
        return self.usdt_reviews().confirm(request, request_id, fingerprint, version, password)


class BalanceReads:
    def __init__(self, using="default"):
        self.using = using
        self.db = Database(using)

    def adjustment_index(self, *, telegram_id=None, preview_id=None, page=1, request=None):
        customer = None
        lookup_error = None
        if isinstance(telegram_id, str) and telegram_id:
            if not re.fullmatch(r"[1-9][0-9]{0,18}", telegram_id):
                lookup_error = "Telegram ID harus berupa angka positif."
            else:
                customer = self.db.one(
                    """SELECT id,telegram_id,username,first_name FROM public.customers
                       WHERE telegram_id=%s""", (telegram_id,))
                if customer is None:
                    lookup_error = "Customer tidak ditemukan untuk Telegram ID tersebut."
        balance = None
        history = None
        reversible = []
        preview = None
        preview_customer = None
        preview_original = None
        if customer is not None:
            account = self.db.one(
                "SELECT id,balance FROM public.customer_balance_accounts WHERE customer_id=%s",
                (customer["id"],))
            balance = int(account["balance"] or 0) if account else 0
            page = max(1, int(page or 1))
            total = self.db.one(
                "SELECT COUNT(*) AS n FROM public.balance_adjustment_requests WHERE customer_id=%s",
                (customer["id"],))["n"]
            history = self.db.rows(
                """SELECT id,telegram_id,username,amount,reason,category,currency,state,
                          ledger_entry_id,created_at,resolved_at
                   FROM public.balance_adjustment_requests WHERE customer_id=%s
                   ORDER BY created_at DESC,id DESC LIMIT 20 OFFSET %s""",
                (customer["id"], (page - 1) * 20))
            for row in history:
                row["id"] = str(row["id"])
                row["created_label"] = _time(row["created_at"])
                row["resolved_label"] = _time(row["resolved_at"])
                row["state_label"] = {
                    "pending": "Preview", "approved": "Disetujui", "applied": "Diterapkan",
                    "rejected": "Ditolak", "cancelled": "Dibatalkan", "expired": "Kedaluwarsa",
                }.get(row["state"], row["state"])
                row["category_label"] = {
                    "owner_attested": "Owner attested", "promotional_grant": "Promosi",
                    "correction": "Koreksi", "reversal": "Reversal",
                    "top_up_recovery": "Pemulihan top-up", "refund_recovery": "Pemulihan refund",
                }.get(row["category"], row["category"])
                if row["state"] == "applied" and row["category"] not in ("reversal",):
                    reversible.append(str(row["id"]))
            history = {"rows": history, "page": page,
                       "pages": max(1, (int(total) + 19) // 20), "total": int(total)}
        if preview_id:
            if request is not None:
                actor = owner_row(request, self.db)
                preview = self.db.one(
                    """SELECT * FROM public.balance_adjustment_requests
                       WHERE id=%s AND owner_user_id=%s AND session_binding=%s
                         AND credential_binding=%s""",
                    (str(preview_id), actor["id"], sha256_digest(request.auth_session.token),
                     sha256_digest(actor["password"])))
            else:
                preview = self.db.one(
                    """SELECT * FROM public.balance_adjustment_requests WHERE id=%s""",
                    (str(preview_id),))
            if preview is not None:
                preview_customer = self.db.one(
                    "SELECT id,telegram_id,username FROM public.customers WHERE id=%s",
                    (preview["customer_id"],))
                preview["id"] = str(preview["id"])
                preview["expires_label"] = _time(preview["expires_at"])
                if preview["category"] == "reversal":
                    preview_original = self.db.one(
                        """SELECT id,amount,reason,category,created_at
                           FROM public.balance_adjustment_requests WHERE id=%s""",
                        (str(preview["source_id"]),))
        positives = self.db.rows(
            """SELECT a.customer_id,a.balance,c.telegram_id,c.username,c.first_name
               FROM public.customer_balance_accounts a
               JOIN public.customers c ON c.id=a.customer_id
               WHERE a.balance>0 ORDER BY a.balance DESC,a.customer_id LIMIT 20""")
        for row in positives:
            row["label"] = row["first_name"] or row["username"] or f"Customer {row['customer_id']}"
        return {
            "telegram_id": telegram_id or "",
            "customer": customer,
            "balance": balance,
            "lookup_error": lookup_error,
            "history": history,
            "reversible": reversible,
            "preview": preview,
            "preview_customer": preview_customer,
            "preview_original": preview_original,
            "positive_balances": positives,
        }

    def funding_policy(self):
        row = self.db.one(
            """SELECT id,version,minimum_amount,preset_amounts,updated_at
               FROM public.store_balance_funding_policies
               WHERE singleton_key='store_balance_funding_policy'""")
        if row is None:
            return {"version": 0, "minimum_amount": "", "preset_amounts": [],
                    "updated_label": "-"}
        presets = _json(row["preset_amounts"]) or []
        valid = [int(value) for value in presets if isinstance(value, int)]
        return {
            "version": int(row["version"]),
            "minimum_amount": int(row["minimum_amount"]),
            "preset_amounts": valid,
            "preset_amounts_raw": ", ".join(str(value) for value in valid),
            "updated_label": _time(row["updated_at"]),
        }

    def usdt_policy(self):
        policy = self.db.one(
            """SELECT * FROM public.customer_usdt_funding_policies
               WHERE singleton_key='customer_usdt_funding_policy'""")
        if policy is None:
            return None
        terms = self.db.one(
            "SELECT body FROM public.customer_usdt_funding_terms WHERE version=%s",
            (policy["terms_version"],))
        presets = _json(policy["preset_amounts_micro"]) or []
        valid_presets = [int(value) for value in presets if isinstance(value, int)]
        return {
            "version": int(policy["version"]),
            "funding_enabled": bool(policy["funding_enabled"]),
            "confirmation_ttl_seconds": int(policy["confirmation_ttl_seconds"]),
            "application_fee_micro": int(policy["application_fee_micro"]),
            "application_fee_label": _micro(policy["application_fee_micro"]),
            "terms_version": int(policy["terms_version"]),
            "terms_body": terms["body"] if terms else "",
            "presets_micro": valid_presets,
            "presets_raw": ", ".join(str(value) for value in valid_presets),
            "presets_label": ", ".join(_micro(value) for value in valid_presets),
        }

    def pauses(self):
        rows = self.db.rows(
            "SELECT scope,reason,paused_at FROM public.customer_usdt_funding_pauses ORDER BY scope")
        found = {row["scope"]: row for row in rows}
        return [{
            "scope": scope,
            "label": {
                "funding": "Funding", "spending": "Spending",
                "rail_binance_pay": "Rail Binance Pay", "rail_bsc_usdt": "Rail BSC USDT",
                "delivery": "Delivery receipt",
            }.get(scope, scope),
            "paused": scope in found,
            "reason": (found.get(scope) or {}).get("reason") or "",
            "paused_label": _time((found.get(scope) or {}).get("paused_at")),
        } for scope in USDT_SCOPES]

    def restore_gate(self):
        row = self.db.one("SELECT * FROM public.balance_restore_gates WHERE id=1")
        if row is None:
            return {"state": "absent", "open": False}
        return {
            "state": row["state"],
            "open": row["state"] == "open",
            "reason": row["reason"] or "",
            "opened_label": _time(row["opened_at"]),
            "window_start_label": _time(row["window_start"]),
            "window_end_label": _time(row["window_end"]),
            "replay_completed_label": _time(row["replay_completed_at"]),
        }

    def alerts(self, limit=20):
        rows = self.db.rows(
            """SELECT alert_key,severity,summary,occurrences,last_seen_at
               FROM public.balance_operations_alert_episodes WHERE state='open'
               ORDER BY CASE severity WHEN 'critical' THEN 0 ELSE 1 END,last_seen_at DESC
               LIMIT %s""", (limit,))
        for row in rows:
            row["last_seen_label"] = _time(row["last_seen_at"])
        return rows

    def topups(self, *, status=None, telegram_id=None, page=1, limit=20):
        where, params = [], []
        if status in ("pending", "paid", "settled", "expired", "failed", "recovery_pending",
                      "invalid_provider_result", "compensated", "cancelled"):
            where.append("t.status=%s")
            params.append(status)
        if isinstance(telegram_id, str) and re.fullmatch(r"[1-9][0-9]{0,18}", telegram_id):
            where.append("c.telegram_id=%s")
            params.append(telegram_id)
        clause = (" WHERE " + " AND ".join(where)) if where else ""
        page = max(1, int(page or 1))
        total = self.db.one(
            "SELECT COUNT(*) AS n FROM public.store_balance_top_ups t "
            "JOIN public.customers c ON c.id=t.customer_id" + clause, tuple(params))["n"]
        rows = self.db.rows(
            """SELECT t.id,t.customer_id,t.currency,t.amount,t.status,t.provider,t.merchant_ref,
                      t.provider_reference,t.provider_fee,t.provider_total_payment,
                      t.provider_create_state,t.expires_at,t.paid_at,t.settled_at,t.created_at,
                      t.payment_proof_submitted_at,c.telegram_id,c.username,c.first_name
               FROM public.store_balance_top_ups t
               JOIN public.customers c ON c.id=t.customer_id""" + clause +
            " ORDER BY t.created_at DESC,t.id DESC LIMIT %s OFFSET %s",
            tuple(params + [limit, (page - 1) * limit]))
        for row in rows:
            row["id"] = str(row["id"])
            row["merchant_ref_masked"] = mask_identifier(row["merchant_ref"], visible=6)
            row["provider_reference_masked"] = mask_identifier(row["provider_reference"], visible=6)
            row["provider"] = str(row["provider"] or "").lower()
            row["status_label"] = {
                "pending": "Menunggu", "paid": "Lunas", "settled": "Settled",
                "expired": "Kedaluwarsa", "failed": "Gagal",
                "recovery_pending": "Recovery", "invalid_provider_result": "Review",
                "compensated": "Kompensasi", "cancelled": "Dibatalkan",
            }.get(row["status"], row["status"])
            row["proof_label"] = "Ada" if row["payment_proof_submitted_at"] else "Tidak ada"
            row["created_label"] = _time(row["created_at"])
            row["paid_label"] = _time(row["paid_at"])
        pages = max(1, (int(total) + limit - 1) // limit)
        return {"rows": rows, "page": page, "pages": pages, "total": int(total),
                "has_previous": page > 1, "has_next": page < pages,
                "previous": page - 1, "next": page + 1}

    def usdt_review_index(self, *, top_up_id=None, preview_id=None, page=1, request=None):
        page = max(1, int(page or 1))
        base = """SELECT t.id,t.customer_id,t.reference,t.rail,t.amount_micro,t.fee_micro,
                         t.payable_micro,t.expires_at,t.created_at,
                         w.state AS work_state,w.version AS work_version,
                         w.evidence_version,w.attempts,
                         c.telegram_id,c.username,c.first_name,
                         (SELECT COUNT(*) FROM public.customer_usdt_top_up_resolutions r
                          WHERE r.top_up_id=t.id) AS resolved
                  FROM public.customer_usdt_top_ups t
                  JOIN public.customer_usdt_top_up_settlement_work w ON w.top_up_id=t.id
                  JOIN public.customers c ON c.id=t.customer_id
                  WHERE NOT EXISTS (SELECT 1 FROM public.customer_usdt_top_up_resolutions r
                                    WHERE r.top_up_id=t.id)
                    AND w.state IN ('manual_review','expired_unresolved')"""
        total = self.db.one("SELECT COUNT(*) AS n FROM (" + base + ") targets")["n"]
        rows = self.db.rows(base + " ORDER BY t.created_at DESC,t.id LIMIT 20 OFFSET %s",
                            ((page - 1) * 20,))
        for row in rows:
            row["id"] = str(row["id"])
            row["amount_label"] = _micro(row["amount_micro"])
            row["fee_label"] = _micro(row["fee_micro"])
            row["payable_label"] = _micro(row["payable_micro"])
            row["created_label"] = _time(row["created_at"])
            row["expires_label"] = _time(row["expires_at"])
            row["state_label"] = ("Manual review" if row["work_state"] == "manual_review"
                                  else "Expired unresolved")
            row["customer"] = row["first_name"] or row["username"] or f"Customer {row['customer_id']}"
            row["url"] = f"/admin/store-balance/usdt-review?top_up={row['id']}"
        selected = None
        preview = None
        if isinstance(top_up_id, str) and re.fullmatch(r"[0-9a-fA-F-]{36}", top_up_id):
            selected = self.db.one(base + " AND t.id=%s", (top_up_id,))
            if selected:
                selected["id"] = str(selected["id"])
                selected["amount_label"] = _micro(selected["amount_micro"])
                selected["fee_label"] = _micro(selected["fee_micro"])
                selected["payable_label"] = _micro(selected["payable_micro"])
                selected["customer"] = (selected["first_name"] or selected["username"]
                                        or f"Customer {selected['customer_id']}")
                target = self.db.one(
                    "SELECT payment_snapshot FROM public.customer_usdt_top_ups WHERE id=%s",
                    (top_up_id,))
                snapshot = _json(target["payment_snapshot"]) if target else {}
                if isinstance(snapshot, dict):
                    snapshot = dict(snapshot)
                    for key in ("receiver_address", "token_contract"):
                        if key in snapshot:
                            snapshot[key] = mask_identifier(snapshot[key], visible=6)
                selected["snapshot"] = snapshot
                selected["evidence"] = self.db.rows(
                    """SELECT version,status,reason,created_at FROM public.customer_usdt_top_up_evidence
                       WHERE top_up_id=%s ORDER BY version DESC LIMIT 10""", (top_up_id,))
                selected["requests"] = self.db.rows(
                    """SELECT id,decision,amount_micro,expires_at,created_at FROM public.usdt_top_up_review_requests
                       WHERE top_up_id=%s ORDER BY created_at DESC LIMIT 10""", (top_up_id,))
                for row in selected["evidence"]:
                    row["created_label"] = _time(row["created_at"])
                    row["status_label"] = safe_error(row["status"]) or row["status"]
                for row in selected["requests"]:
                    row["id"] = str(row["id"])
                    row["amount_label"] = _micro(row["amount_micro"])
                    row["expires_label"] = _time(row["expires_at"])
        if preview_id:
            if request is not None:
                actor = owner_row(request, self.db)
                preview = self.db.one(
                    """SELECT * FROM public.usdt_top_up_review_requests
                       WHERE id=%s AND owner_user_id=%s AND session_binding=%s
                         AND credential_binding=%s""",
                    (str(preview_id), actor["id"], sha256_digest(request.auth_session.token),
                     sha256_digest(actor["password"])))
            else:
                preview = self.db.one(
                    "SELECT * FROM public.usdt_top_up_review_requests WHERE id=%s",
                    (str(preview_id),))
            if preview:
                preview["id"] = str(preview["id"])
                preview["amount_label"] = _micro(preview["amount_micro"])
                preview["expires_label"] = _time(preview["expires_at"])
                preview["transaction_hash_masked"] = mask_identifier(
                    preview["transaction_hash"], visible=8)
        incidents = self.db.rows(
            """SELECT id,top_up_id,reason,exposure_micro,available_exposure_micro,
                      spent_exposure_micro,created_at
               FROM public.usdt_reorg_incidents ORDER BY created_at DESC,id DESC LIMIT 20""")
        for row in incidents:
            row["exposure_label"] = _micro(row["exposure_micro"])
            row["created_label"] = _time(row["created_at"])
        pages = max(1, (int(total) + 19) // 20)
        return {"rows": rows, "page": page, "pages": pages, "total": int(total),
                "selected": selected, "preview": preview, "incidents": incidents,
                "has_previous": page > 1, "has_next": page < pages,
                "previous": page - 1, "next": page + 1}
