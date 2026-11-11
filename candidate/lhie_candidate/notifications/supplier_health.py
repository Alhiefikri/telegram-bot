"""SupplierHealthService parity: private, bounded, durable alert episodes."""
import json
import time
from datetime import timedelta
from html import escape
from uuid import uuid4
from zoneinfo import ZoneInfo

from django.db import transaction

from ..receipt.protocol import _now

ERRORS = {
    'authentication': 'Autentikasi gagal. Verifikasi akses akun supplier.',
    'invalid_key': 'API key tidak valid. Verifikasi atau rotasi key melalui Admin.',
    'credential_unreadable': 'Credential tidak dapat dibaca. Pulihkan pasangan APP_KEY/backup atau lakukan re-entry terkontrol.',
    'currency_mismatch': 'Konfigurasi currency supplier tidak sesuai. Nonaktifkan koneksi dan verifikasi akun/currency sebelum sinkronisasi.',
    'account_identity_mismatch': 'Identitas akun supplier berubah. Hentikan pembelian dan verifikasi koneksi yang dipilih.',
    'duplicate_account': 'Akun supplier terhubung ke lebih dari satu koneksi. Hentikan pembelian dan rapikan konfigurasi.',
    'rate_limited': 'Batas request tercapai. Tunggu lalu sinkronkan kembali.',
    'transport': 'Koneksi supplier terganggu. Tunggu lalu sinkronkan kembali.',
}
TRANSIENT = ('rate_limited', 'transport')
HELD = ('sending', 'ambiguous', 'awaiting_delivery', 'review')
FRESHNESS = 300


def decoded(value, default):
    if value is None:
        return default
    return json.loads(value) if isinstance(value, str) else value


def iso(value):
    return value.replace(tzinfo=ZoneInfo('Asia/Jakarta')).isoformat() if value.tzinfo is None else value.isoformat()


def decimal(value):
    whole, fraction = divmod(int(value), 1_000_000)
    return f'{whole}.{fraction:06d}'


def safe(value):
    return escape(str(value)[:160], quote=True).replace('&#x27;', '&#039;')


def available(supplier):
    return max(0, (supplier.get('balance_micros') or 0) - supplier['reserved_balance_micros'])


def hold_identities(fulfillment, purchases, at, *, aged=False):
    result = {f'hold:purchase:{p["id"]}': p['supplier_id'] for p in purchases
              if p['committed_at'] is None and p['state'] in HELD
              and (not aged or p['created_at'] <= at - timedelta(seconds=900))}
    if (fulfillment['reserved_quantity'] > 0 and fulfillment['reserved_supplier_id'] is not None
            and not purchases and (not aged or fulfillment['created_at'] <= at - timedelta(seconds=900))):
        result[f'hold:fulfillment:{fulfillment["id"]}'] = fulfillment['reserved_supplier_id']
    return result


def review_identities(fulfillment, purchases, at, selected_supplier=None):
    result = {f'purchase:{p["id"]}:{p.get("health_review_episode") or ""}': p['supplier_id']
              for p in purchases if p['state'] in ('ambiguous', 'review')}
    if fulfillment['state'] == 'review':
        supplier_id = next(iter(result.values())) if result else fulfillment['reserved_supplier_id']
        if supplier_id is None:
            supplier_id = selected_supplier
        result[f'fulfillment:{fulfillment["id"]}:{fulfillment.get("health_review_episode") or ""}'] = supplier_id
    result.update(hold_identities(fulfillment, purchases, at, aged=True))
    return result


def review_context(fulfillment, purchases, supplier_id, suppliers):
    text = 'Invoice: ' + safe(fulfillment.get('invoice_number') or fulfillment['order_id']) + '\n'
    for purchase in purchases:
        if purchase['supplier_id'] != supplier_id:
            continue
        currency = (suppliers.get(supplier_id) or {}).get('currency', '')
        text += (f'Purchase #{purchase["id"]}; SKU #{purchase["supplier_catalog_item_id"]}; '
                 f'Jumlah: {purchase["quantity"]}; Hold: {decimal(purchase["expected_cost_micros"])} '
                 f'{safe(currency)}; Waktu: {iso(purchase["created_at"])}\n')
        if purchase['last_error'] == 'dispatch_outcome_unknown':
            text += 'dispatch_outcome_unknown: hasil pembelian belum terbukti.\n'
    if not purchases:
        currency = (suppliers.get(fulfillment['reserved_supplier_id']) or {}).get('currency', '')
        text += (f'Fulfillment #{fulfillment["id"]}; SKU #{fulfillment["reserved_catalog_item_id"] or "-"}; '
                 f'Jumlah: {fulfillment["quantity"]}; Hold: {decimal(fulfillment["reserved_cost_micros"] or 0)} '
                 f'{safe(currency)}; Waktu: {iso(fulfillment["created_at"])}\n')
    return text


def health_message(supplier, conditions, at, monitor_url, blockers=()):
    supplier = supplier or {}
    fresh = bool(supplier.get('balance_observed_at') and
                 supplier['balance_observed_at'] > at - timedelta(seconds=FRESHNESS))
    text = '<b>Health supplier</b>\nKoneksi: ' + safe(supplier.get('name', 'belum ada koneksi terpilih')) + '\n'
    text += ('Saldo tersedia: ' + decimal(available(supplier)) + ' ' + safe(supplier['currency']) + '\n'
             if fresh and supplier.get('balance_micros') is not None else 'Saldo tersedia: belum diketahui\n')
    text += 'Observasi saldo: ' + (iso(supplier['balance_observed_at']) if supplier.get('balance_observed_at') else 'belum diketahui')
    text += (' (stale; bukan bukti saldo nol)' if not fresh else '') + '\n'
    if conditions.get('balance_stale'):
        text += 'Observasi saldo tertahan / tidak ter-observasi >= 15 menit; saldo lama bukan saldo terbaru.\n'
        for purchase in blockers:
            text += (f'Purchase #{purchase["id"]}; SKU #{purchase["supplier_catalog_item_id"]}; '
                     f'Hold: {decimal(purchase["expected_cost_micros"])} {safe(supplier["currency"])}; '
                     f'Sejak: {iso(purchase["created_at"])}\n')
        text += ('Maksimal 10 blocker ditampilkan; daftar lengkap di monitor privat.\n' if blockers else
                 'Tidak ada purchase blocker; periksa jadwal sync/wallet koneksi.\n')
        if supplier.get('sync_next_attempt_at'):
            text += 'Percobaan sync berikutnya: ' + iso(supplier['sync_next_attempt_at']) + '\n'
    if conditions.get('holds'):
        text += 'Hold menua >= 15 menit; jangan lepaskan tanpa bukti.\n'
    if conditions.get('reservations'):
        text += 'Hold itu reservasi kapasitas pesanan yang belum dibayar; lepas otomatis saat pesanan kedaluwarsa atau dibatalkan. Belum ada pembelian ke supplier.\n'
    if conditions.get('low'):
        text += 'Saldo tersedia <= ambang. Tinjau hold dan lakukan top-up sebelum menerima pesanan baru.\n'
    for code in conditions.get('errors', ()):
        text += ERRORS[code] + '\n'
    count = len(conditions.get('reviews', {}))
    if count:
        text += f'Review/ambigu baru: {count} obligation (tanpa hitung ganda).\n'
        text += 'Cek exact order dan bukti debit/delivery; jangan beli ulang atau fallback. Daftar lengkap ada di monitor privat.\n'
    for kind in ('reviews', 'holds', 'reservations'):
        for context in conditions.get(kind, {}).values():
            if len(text) + len(context) > 3500:
                return text + 'Detail lainnya ada di monitor privat.\nAdmin: ' + escape(monitor_url, quote=True)
            text += context
    return text + 'Admin: ' + escape(monitor_url, quote=True)


class SupplierHealth:
    def __init__(self, operations, monitor_url):
        self.operations, self.db, self.monitor_url = operations, operations.db, monitor_url

    def _ids(self, table, cursor, limit):
        # Table is chosen by this module, never an external identifier.
        rows = self.db.rows(f'SELECT id FROM public.{table} WHERE id>%s ORDER BY id LIMIT %s', (cursor, limit))
        if not rows:
            rows = self.db.rows(f'SELECT id FROM public.{table} ORDER BY id LIMIT %s', (limit,))
        return [row['id'] for row in rows]

    def materialize(self, limit, chat):
        from .operations import SUPPLIER
        deadline = time.monotonic() + 60
        with transaction.atomic(using=self.db.using):
            self.db.execute("SET LOCAL lock_timeout='5s'")
            self.db.execute("SET LOCAL statement_timeout='10s'")
            scan = self.db.one('SELECT supplier_cursor,fulfillment_cursor FROM public.supplier_health_scans WHERE id=1 FOR UPDATE')
            if not scan:
                raise ValueError('Supplier health cursor is missing')
            at = _now()
            supplier_ids = self._ids('suppliers', scan['supplier_cursor'], limit)
            fulfillment_ids = self._ids('supplier_fulfillments', scan['fulfillment_cursor'], limit)
            fulfillments = self.db.rows('''SELECT f.id,f.order_id,f.quantity,f.state,f.reserved_supplier_id,
                f.reserved_catalog_item_id,f.reserved_cost_micros,f.reserved_quantity,f.sources_snapshot,
                f.health_review_episode,f.created_at,o.invoice_number,o.order_status
                FROM public.supplier_fulfillments f LEFT JOIN public.orders o ON o.id=f.order_id
                WHERE f.id=ANY(%s) ORDER BY f.id''', (fulfillment_ids,)) if fulfillment_ids else []
            purchases = self.db.rows('''SELECT id,supplier_fulfillment_id,supplier_id,supplier_catalog_item_id,
                quantity,expected_cost_micros,state,health_review_episode,last_error,created_at,committed_at
                FROM public.supplier_purchases WHERE supplier_fulfillment_id=ANY(%s)
                AND (health_review_episode IS NOT NULL OR (committed_at IS NULL AND state=ANY(%s))) ORDER BY id''',
                (fulfillment_ids, list(HELD))) if fulfillment_ids else []
            lock_ids = set(supplier_ids)
            lock_ids.update(p['supplier_id'] for p in purchases)
            lock_ids.update(f['reserved_supplier_id'] for f in fulfillments if f['reserved_supplier_id'] is not None)
            suppliers = {row['id']: row for row in self.db.rows('''SELECT id,name,currency,is_active,balance_micros,
                reserved_balance_micros,balance_observed_at,created_at,synced_at,sync_expires_at,sync_next_attempt_at,
                low_balance_threshold_micros,last_error,sync_failures FROM public.suppliers
                WHERE id=ANY(%s) ORDER BY id FOR UPDATE''', (sorted(lock_ids),))} if lock_ids else {}
            groups = {}
            for fulfillment in fulfillments:
                self._deadline(deadline)
                owned = [p for p in purchases if p['supplier_fulfillment_id'] == fulfillment['id']]
                previous = self.db.one('SELECT active_identities FROM public.supplier_health_reviews WHERE supplier_fulfillment_id=%s', (fulfillment['id'],))
                known = decoded(previous['active_identities'], {}) if previous else {}
                active = {f'purchase:{p["id"]}:{p["health_review_episode"]}' for p in owned if p['health_review_episode']}
                if fulfillment['health_review_episode']:
                    active.add(f'fulfillment:{fulfillment["id"]}:{fulfillment["health_review_episode"]}')
                active.update(hold_identities(fulfillment, owned, at))
                known = {key: value for key, value in known.items() if ':'.join(key.split(':')[:3]) in active}
                selected = None
                if fulfillment['reserved_supplier_id'] is None and fulfillment['state'] == 'review' and not owned:
                    selected = self._selected(fulfillment['sources_snapshot'])
                    if selected is not None and selected not in suppliers:
                        # No lock inversion: this is only private context, not state ownership.
                        row = self.db.one('SELECT id,name,currency,balance_micros,reserved_balance_micros,balance_observed_at,sync_next_attempt_at FROM public.suppliers WHERE id=%s', (selected,))
                        if row:
                            suppliers[selected] = row
                current = review_identities(fulfillment, owned, at, selected)
                for identity, supplier_id in current.items():
                    if identity in known:
                        continue
                    kind = ('reservations' if fulfillment['order_status'] == 'pending_payment' else 'holds') if identity.startswith('hold:') else 'reviews'
                    groups.setdefault(supplier_id, {}).setdefault(kind, {})[fulfillment['id']] = review_context(fulfillment, owned, supplier_id, suppliers)
                if previous or current:
                    self.db.execute('''INSERT INTO public.supplier_health_reviews (supplier_fulfillment_id,active_identities)
                        VALUES (%s,%s) ON CONFLICT (supplier_fulfillment_id) DO UPDATE SET active_identities=EXCLUDED.active_identities''',
                        (fulfillment['id'], json.dumps({**known, **current})))
            for supplier_id in supplier_ids:
                self._deadline(deadline)
                supplier = suppliers.get(supplier_id)
                if not supplier:
                    continue
                state = self.db.one('SELECT low_active,error_codes,balance_stale_active,error_recovered_at FROM public.supplier_health_states WHERE supplier_id=%s', (supplier_id,)) or {}
                low = bool(state.get('low_active'))
                codes = decoded(state.get('error_codes'), [])
                fresh = bool(supplier['synced_at'] and supplier['sync_expires_at'] and supplier['sync_expires_at'] > at)
                balance_fresh = bool(supplier['balance_observed_at'] and supplier['balance_observed_at'] > at - timedelta(seconds=FRESHNESS))
                overdue = (supplier['balance_observed_at'] or supplier['created_at']) <= at - timedelta(seconds=900)
                blockers = self.db.one('''SELECT EXISTS(SELECT 1 FROM public.supplier_purchases WHERE supplier_id=%s
                    AND committed_at IS NULL AND state=ANY(%s)) AS present''', (supplier_id, list(HELD)))['present'] if overdue else False
                monitored = overdue and (supplier['is_active'] or supplier['reserved_balance_micros'] > 0 or blockers)
                retry = bool(overdue and supplier['sync_next_attempt_at'] and supplier['sync_next_attempt_at'] + timedelta(seconds=120) > at)
                stale = monitored and (bool(state.get('balance_stale_active')) or not retry or blockers)
                if stale and not state.get('balance_stale_active'):
                    groups[('balance', supplier_id)] = {'balance_stale': True, 'supplier_id': supplier_id}
                if supplier['low_balance_threshold_micros'] <= 0:
                    low = False
                    self.db.execute('UPDATE public.suppliers SET low_balance_alerted_at=NULL WHERE id=%s', (supplier_id,))
                elif balance_fresh and supplier['balance_micros'] is not None:
                    if available(supplier) <= supplier['low_balance_threshold_micros']:
                        if not low:
                            groups.setdefault(supplier_id, {})['low'] = True
                            self.db.execute('UPDATE public.suppliers SET low_balance_alerted_at=%s WHERE id=%s', (at, supplier_id))
                        low = True
                    elif supplier['last_error'] is None:
                        low = False
                        self.db.execute('UPDATE public.suppliers SET low_balance_alerted_at=NULL WHERE id=%s', (supplier_id,))
                error = supplier['last_error']
                recovered = state.get('error_recovered_at')
                transient = error in TRANSIENT
                code = 'connection' if transient else error
                notified = code in codes or (transient and bool(set(codes) & set(TRANSIENT)))
                if error in ERRORS and (not transient or supplier['sync_failures'] >= 3) and not notified:
                    groups.setdefault(supplier_id, {}).setdefault('errors', []).append(error)
                    codes.append(code)
                if error is not None or not fresh:
                    recovered = None
                elif codes:
                    recovered = recovered or at
                    if recovered <= at - timedelta(seconds=900):
                        codes, recovered = [], None
                self.db.execute('''INSERT INTO public.supplier_health_states
                    (supplier_id,low_active,error_codes,balance_stale_active,error_recovered_at) VALUES (%s,%s,%s,%s,%s)
                    ON CONFLICT (supplier_id) DO UPDATE SET low_active=EXCLUDED.low_active,error_codes=EXCLUDED.error_codes,
                    balance_stale_active=EXCLUDED.balance_stale_active,error_recovered_at=EXCLUDED.error_recovered_at''',
                    (supplier_id, low, json.dumps(codes), bool(stale), recovered))
            for key, conditions in groups.items():
                self._deadline(deadline)
                supplier_id = conditions.get('supplier_id', key)
                supplier = suppliers.get(supplier_id)
                blockers = self.db.rows('''SELECT id,supplier_catalog_item_id,expected_cost_micros,created_at
                    FROM public.supplier_purchases WHERE supplier_id=%s AND committed_at IS NULL
                    AND state=ANY(%s) ORDER BY id LIMIT 10''', (supplier_id, list(HELD))) if conditions.get('balance_stale') else ()
                message = health_message(supplier, conditions, at, self.monitor_url, blockers)
                source = str(uuid4())
                self.db.execute('INSERT INTO public.supplier_health_events (id,supplier_id,message,created_at) VALUES (%s,%s,%s,%s)',
                                (source, supplier['id'] if supplier else None, message, at))
                self.operations._record(SUPPLIER, source, chat, {'chat_id': chat, 'text': message, 'parse_mode': 'HTML'}, key='supplier-health:' + source)
            self.db.execute('UPDATE public.supplier_health_scans SET supplier_cursor=%s,fulfillment_cursor=%s WHERE id=1',
                            (max(supplier_ids, default=0), max(fulfillment_ids, default=0)))
            return len(groups)

    @staticmethod
    def _selected(snapshot):
        try:
            from ..compat.crypto import LaravelEncrypter
            from ..runtime import application_key
            rows = LaravelEncrypter(application_key()).decrypt_json(snapshot)
            ids = {int(row['supplier_id']) for row in rows}
            return next(iter(ids)) if len(ids) == 1 else None
        except (ValueError, TypeError, KeyError):
            return None

    @staticmethod
    def _deadline(deadline):
        if time.monotonic() >= deadline:
            raise RuntimeError('Supplier health scan exceeded its runtime bound')


def observe_recovery(db, supplier_id, at, *, balance_observed):
    """Called only while the catalog owner holds the Supplier lock."""
    db.require_transaction()
    db.execute('''UPDATE public.supplier_health_states SET error_recovered_at=COALESCE(error_recovered_at,%s),
        balance_stale_active=CASE WHEN %s THEN false ELSE balance_stale_active END,
        low_active=CASE WHEN EXISTS(SELECT 1 FROM public.suppliers s WHERE s.id=%s
          AND (s.low_balance_threshold_micros<=0 OR (%s AND s.balance_micros IS NOT NULL
            AND GREATEST(0,s.balance_micros-s.reserved_balance_micros)>s.low_balance_threshold_micros)))
          THEN false ELSE low_active END WHERE supplier_id=%s''',
        (at, balance_observed, supplier_id, balance_observed, supplier_id))
    db.execute('''UPDATE public.suppliers SET low_balance_alerted_at=NULL WHERE id=%s
        AND (low_balance_threshold_micros<=0 OR (%s AND balance_micros IS NOT NULL
          AND GREATEST(0,balance_micros-reserved_balance_micros)>low_balance_threshold_micros))''',
        (supplier_id, balance_observed))
