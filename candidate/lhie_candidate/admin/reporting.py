"""Read-only operational reporting over the existing payment/Order authority.

Definitions mirror ``PaymentReceiptReport``: one canonical paid Payment per
Order (earliest ``paid_at``/``id``), successful Orders only, exact integer
amounts, and both currencies kept apart. Nothing here caches a balance, adds an
implicit FX rate, or writes to a Payment/Order.
"""

import calendar
import csv
import io
from datetime import date, datetime, time, timedelta
from zoneinfo import ZoneInfo

from ..checkout.db import Database
from .sanitize import bounded, json_value

JAKARTA = ZoneInfo("Asia/Jakarta")
CURRENCIES = ("IDR", "USDT")
PERIODS = ("harian", "mingguan", "bulanan", "bulan")
DAYS = (7, 30)
SUCCESSFUL_STATUSES = ("processing", "completed")
HISTORY_LIMIT = 50
EXPORT_LIMIT = 5000
AUDIT_LIMIT = 50


def now():
    return datetime.now(JAKARTA).replace(tzinfo=None)


def _net(alias="p"):
    fee = f"COALESCE({alias}.provider_fee,0)"
    total = f"{alias}.provider_total_payment"
    pakasir = (
        f"(LOWER(COALESCE({alias}.provider,''))='pakasir' AND "
        f"(LOWER(COALESCE({alias}.method,''))='api_qris' OR "
        f"LOWER(COALESCE({alias}.provider_payment_method,''))='qris'))"
    )
    fallback = f"CASE WHEN {pakasir} THEN {alias}.amount ELSE {alias}.amount - {fee} END"
    return f"GREATEST(CASE WHEN {total} IS NOT NULL THEN {total} - {fee} ELSE {fallback} END,0)"


def _gross(alias="p"):
    return f"COALESCE({alias}.provider_total_payment,{alias}.amount)"


def _fee(alias="p"):
    return f"COALESCE({alias}.provider_fee,0)"


def _canonical(alias="p"):
    return f"""{alias}.status='paid' AND {alias}.paid_at IS NOT NULL
        AND NOT EXISTS (
            SELECT 1 FROM public.payments earlier
            WHERE earlier.order_id={alias}.order_id AND earlier.status='paid'
              AND earlier.paid_at IS NOT NULL
              AND (earlier.paid_at < {alias}.paid_at
                   OR (earlier.paid_at = {alias}.paid_at AND earlier.id < {alias}.id)))"""


def _successful_order(alias="p"):
    return f"""EXISTS (SELECT 1 FROM public.orders successful
        WHERE successful.id={alias}.order_id AND successful.payment_status='paid'
          AND successful.order_status IN ('processing','completed'))"""


def _month_start(value):
    return value.replace(day=1)


def _add_months(value, months):
    total = value.year * 12 + (value.month - 1) + months
    year, month = divmod(total, 12)
    month += 1
    day = min(value.day, calendar.monthrange(year, month)[1])
    return value.replace(year=year, month=month, day=day)


def _selected_month(value):
    if not isinstance(value, str):
        return None
    try:
        parsed = datetime.strptime(value, "%Y-%m")
    except ValueError:
        return None
    current = _month_start(now())
    oldest = _add_months(current, -35)
    selected = current.replace(year=parsed.year, month=parsed.month)
    return selected if oldest <= selected <= current else None


def normalize_filters(params):
    currency = params.get("currency")
    if currency not in CURRENCIES:
        currency = "IDR"
    period = params.get("period")
    if period not in PERIODS:
        period = "harian"
    try:
        days = int(params.get("days") or 7)
    except (TypeError, ValueError):
        days = 7
    if days not in DAYS:
        days = 7
    month = _selected_month(params.get("month"))
    provider = params.get("provider")
    if provider not in ("manual", "klikqris", "sakurupiah", "pakasir",
                        "binance_pay", "bsc_usdt"):
        provider = ""
    status = params.get("status")
    if status not in SUCCESSFUL_STATUSES:
        status = ""
    return {
        "currency": currency,
        "period": period,
        "days": days,
        "month": (month or _month_start(now())).strftime("%Y-%m"),
        "provider": provider,
        "status": status,
        "invoice": _invoice(params.get("invoice")),
    }


def _invoice(value):
    """Invoice search is bounded to the characters invoices can contain."""
    if not isinstance(value, str):
        return ""
    needle = "".join(char for char in value.upper().strip()[:80] if char.isalnum() or char == "-")
    return needle if len(needle) >= 3 else ""


def _range(filters):
    today = now().date()
    if filters["period"] == "mingguan":
        end_day = today - timedelta(days=today.weekday()) + timedelta(days=7)
        start = end_day - timedelta(days=7 * 8)
        return start, end_day, "week"
    if filters["period"] in ("bulanan", "bulan"):
        selected = _selected_month(filters.get("month")) if filters["period"] == "bulan" else None
        end_month = _month_start(selected or today)
        start = _add_months(end_month, -11) if selected is None else end_month
        end = _add_months(end_month, 1)
        return start.date(), end.date(), "month" if selected is None else "day"
    end = today + timedelta(days=1)
    start = end - timedelta(days=filters["days"])
    return start, end, "day"


def _sql_range(start, end):
    return datetime.combine(start, time.min), datetime.combine(end, time.min)


def _bucket_expression(mode):
    if mode == "week":
        return "TO_CHAR(DATE_TRUNC('week', p.paid_at),'YYYY-MM-DD')"
    if mode == "month":
        return "TO_CHAR(DATE_TRUNC('month', p.paid_at),'YYYY-MM')"
    return "TO_CHAR(p.paid_at,'YYYY-MM-DD')"


def _points(mode, start, end):
    points = []
    if mode == "week":
        cursor = start
        while cursor < end:
            points.append((cursor.isoformat(), cursor.strftime("%d %b")))
            cursor += timedelta(days=7)
    elif mode == "month":
        cursor = _month_start(datetime.combine(start, time.min))
        while cursor.date() < end:
            points.append((cursor.strftime("%Y-%m"), cursor.strftime("%b %Y")))
            cursor = _add_months(cursor, 1)
    else:
        cursor = start
        while cursor < end:
            points.append((cursor.isoformat(), cursor.strftime("%d %b")))
            cursor += timedelta(days=1)
    return points


class Reporting:
    def __init__(self, using="default"):
        self.using = using
        self.db = Database(using)

    # -- shared money reads -------------------------------------------------
    def net_total(self, start=None, end=None, currency="IDR"):
        params = []
        where = [_canonical("p"), _successful_order("p"),
                 "UPPER(COALESCE(NULLIF(p.currency,''),'IDR'))=%s"]
        params.append(currency)
        if start is not None:
            where.append("p.paid_at>=%s")
            params.append(start)
        if end is not None:
            where.append("p.paid_at<%s")
            params.append(end)
        row = self.db.one(
            f"SELECT COALESCE(SUM({_net('p')}),0) AS amount, COUNT(*) AS orders "
            f"FROM public.payments p WHERE " + " AND ".join(where), tuple(params))
        return int(row["amount"] or 0), int(row["orders"] or 0)

    def earnings(self, start=None, end=None):
        params = []
        where = [_canonical("p"), _successful_order("p")]
        if start is not None:
            where.append("p.paid_at>=%s")
            params.append(start)
        if end is not None:
            where.append("p.paid_at<%s")
            params.append(end)
        rows = self.db.rows(
            "SELECT UPPER(COALESCE(NULLIF(p.currency,''),'IDR')) AS currency, "
            f"COALESCE(SUM({_gross('p')}),0) AS gross, COALESCE(SUM({_fee('p')}),0) AS fee, "
            f"COALESCE(SUM({_net('p')}),0) AS net, COUNT(*) AS orders "
            "FROM public.payments p WHERE " + " AND ".join(where) +
            " GROUP BY UPPER(COALESCE(NULLIF(p.currency,''),'IDR'))", tuple(params))
        found = {row["currency"]: row for row in rows}
        return {
            currency: {
                "gross": int(found.get(currency, {}).get("gross") or 0),
                "fee": int(found.get(currency, {}).get("fee") or 0),
                "net": int(found.get(currency, {}).get("net") or 0),
                "orders": int(found.get(currency, {}).get("orders") or 0),
            }
            for currency in CURRENCIES
        }

    def sales_series(self, filters):
        start, end, mode = _range(filters)
        begin, finish = _sql_range(start, end)
        params = [begin, finish]
        provider = ""
        if filters.get("provider"):
            provider = " AND LOWER(COALESCE(p.provider,''))=%s"
            params.append(filters["provider"])
        rows = self.db.rows(
            "SELECT " + _bucket_expression(mode) + " AS bucket, "
            "UPPER(COALESCE(NULLIF(p.currency,''),'IDR')) AS currency, "
            f"COALESCE(SUM({_net('p')}),0) AS amount, COUNT(*) AS orders "
            "FROM public.payments p WHERE " + _canonical("p") + " AND " + _successful_order("p") +
            " AND p.paid_at>=%s AND p.paid_at<%s" + provider +
            " GROUP BY " + _bucket_expression(mode) +
            ", UPPER(COALESCE(NULLIF(p.currency,''),'IDR'))", tuple(params))
        lookup = {(row["bucket"], row["currency"]): row for row in rows}
        points = []
        for key, label in _points(mode, start, end):
            row = lookup.get((key, filters["currency"])) or {}
            points.append({
                "key": key,
                "label": label,
                "amount": int(row.get("amount") or 0),
                "orders": int(row.get("orders") or 0),
            })
        amounts = [point["amount"] for point in points]
        return {
            "mode": mode,
            "start": start.isoformat(),
            "end": (end - timedelta(days=1)).isoformat(),
            "points": points,
            "total": sum(amounts),
            "average": (sum(amounts) // len(amounts)) if amounts else 0,
            "peak": max(amounts) if amounts else 0,
            "has_sales": any(amounts),
        }

    def overview(self, filters=None):
        filters = normalize_filters(filters or {})
        today = now()
        start = datetime.combine(today.date(), time.min)
        series = self.sales_series(filters)
        earnings = self.earnings(*_sql_range(*_range(filters)[:2]))
        today_amount, today_orders = self.net_total(start, None, filters["currency"])
        processing = self.db.one(
            "SELECT COUNT(*) AS n FROM public.orders WHERE order_status='processing'")["n"]
        months = self._month_tiles(filters["currency"], today)
        return {
            "currency": filters["currency"],
            "period": filters["period"],
            "days": filters["days"],
            "month": filters["month"],
            "series": series,
            "earnings": earnings,
            "today_amount": today_amount,
            "today_orders": today_orders,
            "processing": int(processing or 0),
            "months": months,
            "period_options": self._period_options(filters),
            "updated_at": today.strftime("%d %b %Y, %H:%M"),
        }

    def _period_options(self, filters):
        month = filters["month"]
        days = filters["days"]
        currency = filters["currency"]
        return [
            {"key": "harian", "label": "Harian", "hint": "Tanggal bayar lunas per hari.",
             "url": f"/admin/laporan?period=harian&days={days}&month={month}&currency={currency}"},
            {"key": "mingguan", "label": "Mingguan", "hint": "8 minggu terakhir, Senin–Minggu.",
             "url": f"/admin/laporan?period=mingguan&days={days}&month={month}&currency={currency}"},
            {"key": "bulanan", "label": "Bulanan", "hint": "12 bulan terakhir.",
             "url": f"/admin/laporan?period=bulanan&days={days}&month={month}&currency={currency}"},
            {"key": "bulan", "label": "Bulan tertentu", "hint": "Per hari dalam satu bulan pilihan.",
             "url": f"/admin/laporan?period=bulan&days={days}&month={month}&currency={currency}"},
        ]

    def _month_tiles(self, currency, current):
        start = _add_months(_month_start(current), -1)
        end = _add_months(_month_start(current), 1)
        rows = self.db.rows(
            "SELECT TO_CHAR(DATE_TRUNC('month', p.paid_at),'YYYY-MM') AS bucket, "
            f"COALESCE(SUM({_net('p')}),0) AS amount, COUNT(*) AS orders "
            "FROM public.payments p WHERE " + _canonical("p") + " AND " + _successful_order("p") +
            " AND p.paid_at>=%s AND p.paid_at<%s "
            "AND UPPER(COALESCE(NULLIF(p.currency,''),'IDR'))=%s GROUP BY 1",
            (_month_start(start), _month_start(end), currency))
        lookup = {row["bucket"]: row for row in rows}
        tiles = []
        cursor = start
        while cursor < end:
            key = cursor.strftime("%Y-%m")
            row = lookup.get(key) or {}
            tiles.append({
                "key": key,
                "label": cursor.strftime("%B %Y"),
                "short_label": cursor.strftime("%b %Y"),
                "amount": int(row.get("amount") or 0),
                "orders": int(row.get("orders") or 0),
                "current": key == _month_start(current).strftime("%Y-%m"),
            })
            cursor = _add_months(cursor, 1)
        current_row = next((tile for tile in tiles if tile["current"]), None)
        previous = tiles[-2] if len(tiles) > 1 else None
        delta = None
        if current_row and previous and previous["amount"] > 0:
            percent = (current_row["amount"] - previous["amount"]) / previous["amount"] * 100
            delta = round(percent, 1)
        return {"tiles": tiles, "delta": delta, "previous": previous}

    # -- history and audit --------------------------------------------------
    def history(self, filters, page=1, limit=HISTORY_LIMIT):
        filters = normalize_filters(filters)
        where = [_canonical("p"), _successful_order("p")]
        params = []
        if filters["invoice"]:
            where.append("o.invoice_number LIKE %s")
            params.append(filters["invoice"] + "%")
        if filters["currency"]:
            where.append("UPPER(COALESCE(NULLIF(p.currency,''),'IDR'))=%s")
            params.append(filters["currency"])
        if filters["provider"]:
            where.append("LOWER(COALESCE(p.provider,''))=%s")
            params.append(filters["provider"])
        if filters["status"]:
            where.append("o.order_status=%s")
            params.append(filters["status"])
        page = max(1, int(page or 1))
        limit = max(1, min(EXPORT_LIMIT, int(limit or HISTORY_LIMIT)))
        offset = (page - 1) * limit
        total = self.db.one(
            "SELECT COUNT(*) AS n FROM public.payments p JOIN public.orders o ON o.id=p.order_id "
            "WHERE " + " AND ".join(where), tuple(params))["n"]
        rows = self.db.rows(
            "SELECT p.id AS payment_id,p.order_id,o.invoice_number,o.order_status,o.payment_status,"
            "p.provider,p.method,p.provider_payment_method,p.currency,p.amount,p.provider_fee,"
            "p.provider_total_payment,p.paid_at,c.telegram_id,c.first_name,c.username,"
            f"{_gross('p')} AS gross,{_fee('p')} AS fee,{_net('p')} AS net "
            "FROM public.payments p JOIN public.orders o ON o.id=p.order_id "
            "JOIN public.customers c ON c.id=o.customer_id WHERE " + " AND ".join(where) +
            " ORDER BY p.paid_at DESC,p.id DESC LIMIT %s OFFSET %s",
            tuple(params + [limit, offset]))
        for row in rows:
            row["currency"] = str(row["currency"] or "IDR").upper()
            row["customer_label"] = _customer_label(row)
            row["provider"] = str(row["provider"] or "").lower()
        pages = max(1, (int(total) + limit - 1) // limit)
        return {
            "rows": rows,
            "total": int(total),
            "page": page,
            "pages": pages,
            "has_previous": page > 1,
            "has_next": page < pages,
            "previous": page - 1,
            "next": page + 1,
        }

    def export_rows(self, filters):
        result = self.history(filters, page=1, limit=EXPORT_LIMIT)
        return result["rows"]

    def export_csv(self, filters):
        output = io.StringIO()
        writer = csv.writer(output, lineterminator="\n")
        writer.writerow(["invoice", "paid_at", "provider", "method", "currency",
                         "gross", "fee", "net", "order_status", "payment_status"])
        for row in self.export_rows(filters):
            writer.writerow([
                row["invoice_number"],
                _format_time(row["paid_at"]),
                row["provider"],
                row.get("provider_payment_method") or row.get("method") or "",
                row["currency"],
                row["gross"], row["fee"], row["net"],
                row["order_status"], row["payment_status"],
            ])
        return output.getvalue()

    def audit(self, filters, page=1, limit=AUDIT_LIMIT):
        page = max(1, int(page or 1))
        limit = max(1, min(100, int(limit or AUDIT_LIMIT)))
        where, params = ["1=1"], []
        action = filters.get("action")
        if isinstance(action, str) and action.strip():
            cleaned = "".join(char for char in action.strip()[:80] if char.isalnum() or char in "._-")
            if cleaned:
                where.append("a.action LIKE %s")
                params.append(cleaned + "%")
        subject = filters.get("subject")
        if isinstance(subject, str) and subject.strip():
            cleaned = "".join(char for char in subject.strip()[:80] if char.isalnum() or char in "._\\-")
            if cleaned:
                where.append("a.subject_type=%s")
                params.append(cleaned)
        total = self.db.one(
            "SELECT COUNT(*) AS n FROM public.audit_logs a WHERE " + " AND ".join(where),
            tuple(params))["n"]
        rows = self.db.rows(
            "SELECT a.id,a.action,a.subject_type,a.subject_id,a.old_values,a.new_values,"
            "a.created_at,a.ip_address,u.name AS actor_name,u.role AS actor_role "
            "FROM public.audit_logs a LEFT JOIN public.users u ON u.id=a.user_id "
            "WHERE " + " AND ".join(where) +
            " ORDER BY a.created_at DESC,a.id DESC LIMIT %s OFFSET %s",
            tuple(params + [limit, (page - 1) * limit]))
        for row in rows:
            row["old_values"] = json_value(row["old_values"])
            row["new_values"] = json_value(row["new_values"])
            row["ip_address"] = _mask_ip(row["ip_address"])
            row["actor"] = row["actor_name"] or "sistem"
            row["actor_role"] = row["actor_role"] or "-"
        pages = max(1, (int(total) + limit - 1) // limit)
        return {"rows": rows, "total": int(total), "page": page, "pages": pages,
                "has_previous": page > 1, "has_next": page < pages,
                "previous": page - 1, "next": page + 1}


def _format_time(value):
    if isinstance(value, datetime):
        return value.strftime("%Y-%m-%d %H:%M:%S")
    return "" if value is None else str(value)


def _customer_label(row):
    first = row.get("first_name") or ""
    username = row.get("username") or ""
    if first and username:
        return f"{first} (@{username})"
    return first or (f"@{username}" if username else "Pelanggan")


def _mask_ip(value):
    if not isinstance(value, str) or not value:
        return ""
    if ":" in value:
        return value.split(":")[0] + ":…"
    parts = value.split(".")
    return ".".join(parts[:3] + ["…"]) if len(parts) == 4 else "…"


def export_filename(filters, stamp=None):
    stamp = (stamp or now()).strftime("%Y%m%d-%H%M%S")
    return f"lhie-earnings-{normalize_filters(filters)['currency'].lower()}-{stamp}.csv"


def bounded_audit_value(value):
    return bounded(value)


def net_sql(alias="p"):
    """Shared net receipt expression so order cards and reports cannot drift."""
    return _net(alias)
