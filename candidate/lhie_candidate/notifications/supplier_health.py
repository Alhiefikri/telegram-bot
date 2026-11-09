"""Bounded supplier health episodes using the legacy cursor and effect ledger."""
from datetime import timedelta
from html import escape
import json
from time import monotonic
from uuid import uuid4
from zoneinfo import ZoneInfo

from django.conf import settings
from django.db import transaction

from ..auth.types import legacy_now
from .policy import SUPPLIER_HEALTH


_ERRORS = {
    'authentication': 'Autentikasi gagal. Verifikasi akses akun supplier.',
    'invalid_key': 'API key tidak valid. Verifikasi atau rotasi key melalui Admin.',
    'credential_unreadable': 'Credential tidak dapat dibaca. Pulihkan pasangan APP_KEY/backup atau lakukan re-entry terkontrol.',
    'currency_mismatch': 'Konfigurasi currency supplier tidak sesuai. Nonaktifkan koneksi dan verifikasi akun/currency sebelum sinkronisasi.',
    'account_identity_mismatch': 'Identitas akun supplier berubah. Hentikan pembelian dan verifikasi koneksi yang dipilih.',
    'duplicate_account': 'Akun supplier terhubung ke lebih dari satu koneksi. Hentikan pembelian dan rapikan konfigurasi.',
    'rate_limited': 'Batas request tercapai. Tunggu lalu sinkronkan kembali.',
    'transport': 'Koneksi supplier terganggu. Tunggu lalu sinkronkan kembali.',
}
_HOLD = ('sending', 'ambiguous', 'awaiting_delivery', 'review')
_ZONE = ZoneInfo('Asia/Jakarta')


def _date(value):
    if value is None:
        return None
    return value.replace(tzinfo=_ZONE) if value.tzinfo is None else value.astimezone(_ZONE)


def _iso(value):
    return _date(value).replace(microsecond=0).isoformat() if value else None


def _money(value):
    if type(value) is not int or value < 0:
        raise ValueError('Invalid supplier amount')
    whole, fraction = divmod(value, 1000000)
    return f'{whole}.{fraction:06d}'


def _escape(value):
    return escape(str(value or '')[:160], quote=True).replace('&#x27;', '&#039;')


def _decoded(value, default):
    if isinstance(value, str):
        value = json.loads(value)
    return value if value is not None else default


class SupplierHealth:
    def __init__(self, db, recipients, *, clock=legacy_now):
        self.db, self.recipients, self.clock = db, recipients, clock

    def _next(self, table, cursor, limit):
        rows = self.db.rows(f'SELECT id FROM public.{table} WHERE id>%s ORDER BY id LIMIT %s', (cursor, limit))
        if not rows:
            rows = self.db.rows(f'SELECT id FROM public.{table} ORDER BY id LIMIT %s', (limit,))
        return [row['id'] for row in rows]

    def _supplier_from_snapshot(self, value):
        try:
            from ..runtime import application_key
            from ..compat.crypto import LaravelEncrypter
            snapshots = LaravelEncrypter(application_key()).decrypt_json(value)
            ids = {item['supplier_id'] for item in snapshots if isinstance(item, dict) and item.get('supplier_id') is not None}
            return int(next(iter(ids))) if len(ids) == 1 else None
        except (ValueError, TypeError, KeyError, AttributeError, OverflowError, RuntimeError):
            return None

    def _fulfillment(self, row, purchases, at):
        current, active, holds = {}, set(), {}
        cutoff = at - timedelta(seconds=900)
        for purchase in purchases:
            episode = purchase['health_review_episode']
            if episode is not None:
                active.add(f"purchase:{purchase['id']}:{episode}")
            if purchase['state'] in ('ambiguous', 'review'):
                current[f"purchase:{purchase['id']}:{episode or ''}"] = purchase['supplier_id']
            if purchase['committed_at'] is None and purchase['state'] in _HOLD:
                identity = f"hold:purchase:{purchase['id']}"
                active.add(identity)
                if purchase['created_at'] <= cutoff:
                    holds[identity] = purchase['supplier_id']
        if row['health_review_episode'] is not None:
            active.add(f"fulfillment:{row['id']}:{row['health_review_episode']}")
        if row['reserved_quantity'] > 0 and row['reserved_supplier_id'] is not None and not purchases:
            identity = f"hold:fulfillment:{row['id']}"
            active.add(identity)
            if row['created_at'] <= cutoff:
                holds[identity] = row['reserved_supplier_id']
        if row['state'] == 'review':
            supplier = (next(reversed(current.values())) if current else
                        row['reserved_supplier_id'] if row['reserved_supplier_id'] is not None else
                        self._supplier_from_snapshot(row['sources_snapshot']))
            current[f"fulfillment:{row['id']}:{row['health_review_episode'] or ''}"] = supplier
        current.update(holds)
        return current, active

    def _context(self, fulfillment, purchases, supplier_id, suppliers):
        text = 'Invoice: ' + _escape(fulfillment['invoice_number'] or fulfillment['order_id']) + '\n'
        for purchase in purchases:
            if purchase['supplier_id'] != supplier_id:
                continue
            currency = (suppliers.get(supplier_id) or {}).get('currency')
            text += (f"Purchase #{purchase['id']}; SKU #{purchase['supplier_catalog_item_id']}; Jumlah: {purchase['quantity']}; "
                     f"Hold: {_money(purchase['expected_cost_micros'])} {_escape(currency)}; Waktu: {_iso(purchase['created_at'])}\n")
            if purchase['last_error'] == 'dispatch_outcome_unknown':
                text += 'dispatch_outcome_unknown: hasil pembelian belum terbukti.\n'
        if not purchases:
            supplier = suppliers.get(fulfillment['reserved_supplier_id']) or {}
            sku = fulfillment['reserved_catalog_item_id'] or '-'
            text += (f"Fulfillment #{fulfillment['id']}; SKU #{sku}; Jumlah: {fulfillment['quantity']}; "
                     f"Hold: {_money(fulfillment['reserved_cost_micros'] or 0)} {_escape(supplier.get('currency'))}; "
                     f"Waktu: {_iso(fulfillment['created_at'])}\n")
        return text

    def _message(self, supplier, conditions, at):
        fresh = bool(supplier and supplier['balance_observed_at'] and supplier['balance_observed_at'] > at - timedelta(seconds=300))
        name = _escape(supplier['name']) if supplier else 'belum ada koneksi terpilih'
        text = '<b>Health supplier</b>\nKoneksi: ' + name + '\n'
        if fresh and supplier['balance_micros'] is not None:
            available = max(0, supplier['balance_micros'] - supplier['reserved_balance_micros'])
            text += 'Saldo tersedia: ' + _money(available) + ' ' + _escape(supplier['currency']) + '\n'
        else:
            text += 'Saldo tersedia: belum diketahui\n'
        text += 'Observasi saldo: ' + (_iso(supplier['balance_observed_at']) if supplier and supplier['balance_observed_at'] else 'belum diketahui')
        if not fresh:
            text += ' (stale; bukan bukti saldo nol)'
        text += '\n'
        if conditions.get('balance_stale'):
            text += 'Observasi saldo tertahan / tidak ter-observasi >= 15 menit; saldo lama bukan saldo terbaru.\n'
            blockers = self.db.rows('''SELECT id,supplier_catalog_item_id,expected_cost_micros,created_at
                FROM public.supplier_purchases WHERE supplier_id=%s AND committed_at IS NULL
                AND state=ANY(%s) ORDER BY id LIMIT 10''', (supplier['id'], list(_HOLD)))
            for purchase in blockers:
                text += (f"Purchase #{purchase['id']}; SKU #{purchase['supplier_catalog_item_id']}; "
                         f"Hold: {_money(purchase['expected_cost_micros'])} {_escape(supplier['currency'])}; "
                         f"Sejak: {_iso(purchase['created_at'])}\n")
            text += ('Maksimal 10 blocker ditampilkan; daftar lengkap di monitor privat.\n' if blockers else
                     'Tidak ada purchase blocker; periksa jadwal sync/wallet koneksi.\n')
            if supplier['sync_next_attempt_at'] is not None:
                text += 'Percobaan sync berikutnya: ' + _iso(supplier['sync_next_attempt_at']) + '\n'
        if conditions.get('holds'):
            text += 'Hold menua >= 15 menit; jangan lepaskan tanpa bukti.\n'
        if conditions.get('reservations'):
            text += 'Hold itu reservasi kapasitas pesanan yang belum dibayar; lepas otomatis saat pesanan kedaluwarsa atau dibatalkan. Belum ada pembelian ke supplier.\n'
        if conditions.get('low'):
            text += 'Saldo tersedia <= ambang. Tinjau hold dan lakukan top-up sebelum menerima pesanan baru.\n'
        for code in conditions.get('errors', []):
            text += _ERRORS[code] + '\n'
        reviews = conditions.get('reviews', {})
        if reviews:
            text += f'Review/ambigu baru: {len(reviews)} obligation (tanpa hitung ganda).\n'
            text += 'Cek exact order dan bukti debit/delivery; jangan beli ulang atau fallback. Daftar lengkap ada di monitor privat.\n'
        for kind in ('reviews', 'holds', 'reservations'):
            for context in conditions.get(kind, {}).values():
                if len(text) + len(context) > 3500:
                    text += 'Detail lainnya ada di monitor privat.\n'
                    break
                text += context
            else:
                continue
            break
        base = (getattr(settings, 'CANDIDATE_LEGACY_APP_URL', '') or 'http://localhost').rstrip('/')
        return text + 'Admin: ' + escape(base + '/admin/suppliers/monitor/purchases', quote=True)

    def materialize(self, record, target, limit=25):
        if not target or not self.recipients.permits(target):
            return 0
        limit = max(1, min(100, int(limit)))
        deadline = monotonic() + 60
        at = self.clock()
        with transaction.atomic(using=self.db.using):
            self.db.execute("SET LOCAL lock_timeout = '5s'")
            self.db.execute("SET LOCAL statement_timeout = '10s'")
            scan = self.db.one('SELECT supplier_cursor,fulfillment_cursor FROM public.supplier_health_scans WHERE id=1 FOR UPDATE')
            supplier_ids = self._next('suppliers', scan['supplier_cursor'], limit)
            fulfillment_ids = self._next('supplier_fulfillments', scan['fulfillment_cursor'], limit)
            fulfillments = self.db.rows('''SELECT f.id,f.order_id,f.quantity,f.state,f.reserved_supplier_id,
                f.reserved_catalog_item_id,f.reserved_cost_micros,f.reserved_quantity,f.current_purchase_id,
                f.sources_snapshot,f.health_review_episode,f.created_at,o.invoice_number,o.order_status
                FROM public.supplier_fulfillments f LEFT JOIN public.orders o ON o.id=f.order_id
                WHERE f.id=ANY(%s) ORDER BY f.id''', (fulfillment_ids,))
            purchases = self.db.rows('''SELECT id,supplier_fulfillment_id,supplier_id,supplier_catalog_item_id,
                quantity,expected_cost_micros,state,health_review_episode,last_error,created_at,committed_at
                FROM public.supplier_purchases WHERE supplier_fulfillment_id=ANY(%s)
                AND (health_review_episode IS NOT NULL OR (committed_at IS NULL AND state=ANY(%s))) ORDER BY id''',
                (fulfillment_ids, list(_HOLD)))
            by_fulfillment = {}
            for purchase in purchases:
                by_fulfillment.setdefault(purchase['supplier_fulfillment_id'], []).append(purchase)
            groups, updates, lock_ids = {}, {}, set(supplier_ids)
            contexts = []
            for fulfillment in fulfillments:
                related = by_fulfillment.get(fulfillment['id'], [])
                if fulfillment['reserved_supplier_id'] is not None:
                    lock_ids.add(fulfillment['reserved_supplier_id'])
                lock_ids.update(p['supplier_id'] for p in related)
                current, active = self._fulfillment(fulfillment, related, at)
                previous = self.db.one('SELECT active_identities FROM public.supplier_health_reviews WHERE supplier_fulfillment_id=%s', (fulfillment['id'],))
                known = _decoded(previous['active_identities'], {}) if previous else {}
                if known == []:
                    known = {}
                known = {identity: supplier for identity, supplier in known.items() if ':'.join(identity.split(':')[:3]) in active}
                for identity, supplier in current.items():
                    if identity in known:
                        continue
                    if supplier is not None:
                        lock_ids.add(supplier)
                    kind = ('reservations' if fulfillment['reserved_cost_micros'] and fulfillment['current_purchase_id'] is None
                            and fulfillment['order_status'] == 'pending_payment' else 'holds') if identity.startswith('hold:') else 'reviews'
                    contexts.append((supplier, kind, fulfillment, related))
                if previous is not None or current:
                    updates[fulfillment['id']] = {**known, **current}
            suppliers = self.db.rows('''SELECT id,name,currency,is_active,balance_micros,reserved_balance_micros,
                low_balance_threshold_micros,balance_observed_at,created_at,synced_at,sync_expires_at,
                sync_next_attempt_at,sync_failures,last_error FROM public.suppliers WHERE id=ANY(%s) ORDER BY id FOR UPDATE''',
                (sorted(lock_ids),))
            suppliers = {supplier['id']: supplier for supplier in suppliers}
            for supplier, kind, fulfillment, related in contexts:
                groups.setdefault(supplier, {}).setdefault(kind, {})[fulfillment['id']] = self._context(fulfillment, related, supplier, suppliers)
            for identity, values in updates.items():
                self.db.execute('''INSERT INTO public.supplier_health_reviews (supplier_fulfillment_id,active_identities)
                    VALUES (%s,%s::json) ON CONFLICT (supplier_fulfillment_id)
                    DO UPDATE SET active_identities=EXCLUDED.active_identities''', (identity, json.dumps(values)))
            for identity in supplier_ids:
                supplier = suppliers.get(identity)
                if not supplier:
                    continue
                state = self.db.one('SELECT low_active,error_codes,balance_stale_active,error_recovered_at FROM public.supplier_health_states WHERE supplier_id=%s', (identity,)) or {}
                codes = _decoded(state.get('error_codes'), [])
                fresh = supplier['synced_at'] is not None and supplier['sync_expires_at'] is not None and supplier['sync_expires_at'] > at
                balance_fresh = supplier['balance_observed_at'] is not None and supplier['balance_observed_at'] > at - timedelta(seconds=300)
                observed = supplier['balance_observed_at'] or supplier['created_at']
                overdue = observed is not None and observed <= at - timedelta(seconds=900)
                blockers = overdue and bool(self.db.one('''SELECT id FROM public.supplier_purchases WHERE supplier_id=%s
                    AND committed_at IS NULL AND state=ANY(%s) LIMIT 1''', (identity, list(_HOLD))))
                monitored = overdue and (supplier['is_active'] or supplier['reserved_balance_micros'] > 0 or blockers)
                retry = overdue and supplier['sync_next_attempt_at'] is not None and supplier['sync_next_attempt_at'] + timedelta(seconds=120) > at
                stale = bool(monitored and (state.get('balance_stale_active') or not retry or blockers))
                if stale and not state.get('balance_stale_active'):
                    groups[('balance', identity)] = {'balance_stale': True, 'supplier_id': identity}
                low = bool(state.get('low_active'))
                if supplier['low_balance_threshold_micros'] <= 0:
                    low = False
                    self.db.execute('UPDATE public.suppliers SET low_balance_alerted_at=NULL WHERE id=%s', (identity,))
                elif balance_fresh and supplier['balance_micros'] is not None:
                    available = max(0, supplier['balance_micros'] - supplier['reserved_balance_micros'])
                    if available <= supplier['low_balance_threshold_micros']:
                        if not low:
                            groups.setdefault(identity, {})['low'] = True
                            self.db.execute('UPDATE public.suppliers SET low_balance_alerted_at=%s WHERE id=%s', (at, identity))
                        low = True
                    elif supplier['last_error'] is None:
                        low = False
                        self.db.execute('UPDATE public.suppliers SET low_balance_alerted_at=NULL WHERE id=%s', (identity,))
                error, recovered = supplier['last_error'], state.get('error_recovered_at')
                transient = error in ('rate_limited', 'transport')
                code = 'connection' if transient else error
                notified = code in codes or (transient and any(value in codes for value in ('transport', 'rate_limited')))
                if error in _ERRORS and (not transient or supplier['sync_failures'] >= 3) and not notified:
                    groups.setdefault(identity, {}).setdefault('errors', []).append(error)
                    codes.append(code)
                if error is not None or not fresh:
                    recovered = None
                elif codes:
                    recovered = recovered or at
                    if recovered <= at - timedelta(seconds=900):
                        codes, recovered = [], None
                self.db.execute('''INSERT INTO public.supplier_health_states
                    (supplier_id,low_active,error_codes,balance_stale_active,error_recovered_at)
                    VALUES (%s,%s,%s::json,%s,%s) ON CONFLICT (supplier_id) DO UPDATE SET
                    low_active=EXCLUDED.low_active,error_codes=EXCLUDED.error_codes,
                    balance_stale_active=EXCLUDED.balance_stale_active,error_recovered_at=EXCLUDED.error_recovered_at''',
                    (identity, low, json.dumps(codes), stale, recovered))
                if monotonic() >= deadline:
                    raise RuntimeError('Supplier health scan exceeded its runtime bound')
            for identity, conditions in groups.items():
                supplier = suppliers.get(conditions.get('supplier_id', identity))
                event = self.db.one('INSERT INTO public.supplier_health_events (id,supplier_id,message,created_at) VALUES (%s,%s,%s,%s) RETURNING id',
                                    (str(uuid4()), supplier['id'] if supplier else None, self._message(supplier, conditions, at), at))
                record(key=f"supplier-health:{event['id']}", kind=SUPPLIER_HEALTH, model='SupplierHealthEvent',
                       source=event['id'], chat=target, target='admin', locale='id', template='supplier-health-v1')
                if monotonic() >= deadline:
                    raise RuntimeError('Supplier health scan exceeded its runtime bound')
            self.db.execute('UPDATE public.supplier_health_scans SET supplier_cursor=%s,fulfillment_cursor=%s WHERE id=1',
                            (max(supplier_ids) if supplier_ids else 0, max(fulfillment_ids) if fulfillment_ids else 0))
            return len(groups)
