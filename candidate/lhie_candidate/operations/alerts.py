"""Scheduler/recovery runtime alerts over the durable balance episode ledger."""

import re

from lhie_candidate.checkout.db import Database
from lhie_candidate.usdt.operations_controls import OperationalControls, runtime_alert_summary


def _admin_chat(db):
    row = db.one("""SELECT admin_telegram_chat_id FROM public.manual_payment_settings
        WHERE is_active=true ORDER BY id LIMIT 1""")
    chat = str((row or {}).get("admin_telegram_chat_id") or "").strip()
    return chat if re.fullmatch(r"[1-9][0-9]{0,18}", chat) else None


def scheduler_runtime(component, state, reason=None, *, using="default", admin_chat_id=None):
    """Reconcile one runtime episode; no recipient means an explicit deferral."""
    if state not in ("failed", "recovered"):
        raise ValueError("Invalid runtime alert state")
    healthy = state == "recovered"
    # Validate copy before touching the database so a typo cannot create an
    # episode with an unbounded/unknown reason.
    runtime_alert_summary(component, healthy, reason=reason)
    if admin_chat_id is None:
        admin_chat_id = _admin_chat(Database(using))
    if admin_chat_id is None:
        return {"status": "deferred", "reason": "admin_recipient_unavailable"}
    episode = OperationalControls(using).reconcile_runtime(
        component, healthy=healthy, reason=reason, admin_chat_id=admin_chat_id)
    return {"status": "recovered" if healthy else "opened",
            "key": runtime_alert_summary(component, healthy, reason=reason)[0],
            "notified": bool(episode)}
