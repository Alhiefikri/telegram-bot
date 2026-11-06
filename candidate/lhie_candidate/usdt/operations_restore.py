"""Post-restore barriers: replay precedes a complete, clean reconciliation."""
import json
from django.db import transaction
from lhie_candidate.auth.types import legacy_now
from lhie_candidate.checkout.db import Database
from lhie_candidate.usdt.operations import _json, _outside

_FAMILIES = ['usdt.top_up_receipt', 'usdt.refund_receipt', 'balance.top_up_receipt',
             'admin.balance_top_up_owner_alert', 'admin.balance_top_up_paid_notification',
             'balance.owner_compensation_notification', 'balance.adjustment_notice', 'admin.balance_operations_alert']
_TERMINAL = ['settled', 'externally_refunded', 'resolved_by_compensation', 'rejected']


def _text(value):
    if not isinstance(value, str) or not 1 <= len(value.strip()) <= 191:
        raise ValueError('Invalid operator text')
    return value.strip()


class RestoreGate:
    def __init__(self, using='default'):
        self.using, self.db = using, Database(using)

    def open(self, start, end, reason, actor):
        _outside(self.using)
        reason, actor = _text(reason), _text(actor)
        if start is None or end is None or end < start:
            raise ValueError('Invalid restore window')
        with transaction.atomic(using=self.using):
            gate = self.db.one('SELECT * FROM public.balance_restore_gates WHERE id=1 FOR UPDATE')
            if not gate or gate['state'] != 'cleared':
                raise ValueError('Restore gate unavailable')
            now = legacy_now()
            result = self.db.one('''UPDATE public.balance_restore_gates SET state='open',window_start=%s,window_end=%s,
                reason=%s,opened_by=%s,opened_at=%s,replay_completed_at=NULL,replay_by=NULL,replay_evidence=NULL,
                cleared_at=NULL,cleared_by=NULL,cleared_evidence=NULL,updated_at=%s WHERE id=1 RETURNING *''',
                (start, end, reason, actor, now, now))
            self.db.execute("INSERT INTO public.audit_logs(action,new_values,created_at) VALUES ('usdt_balance.restore_gate.opened',%s,%s)",
                            (json.dumps({'actor': actor, 'window_start': start.isoformat(), 'window_end': end.isoformat(), 'reason': reason}), now))
            return result

    def replay(self, settlement, scan, *, actor, limit=25):
        """Call real settlement and receiver-scan adapters outside database locks.

        scan(window_start, window_end) must return a complete authoritative scan result
        with status='completed'; failed or incomplete scans keep the barrier open.
        """
        _outside(self.using)
        actor = _text(actor)
        if type(limit) is not int or not 1 <= limit <= 100:
            raise ValueError('Invalid replay limit')
        gate = self.db.one('SELECT * FROM public.balance_restore_gates WHERE id=1')
        if not gate or gate['state'] != 'open':
            raise ValueError('Restore gate not open')
        old = _json(gate['replay_evidence']) or {}
        cursor = old.get('settlement_cursor') if old.get('window_opened_at') == gate['opened_at'].isoformat() else None
        rows = self.db.rows('''SELECT top_up_id FROM public.customer_usdt_top_up_settlement_work
            WHERE NOT (state=ANY(%s)) AND (%s::uuid IS NULL OR top_up_id>%s::uuid)
            ORDER BY top_up_id LIMIT %s''', (_TERMINAL, cursor, cursor, limit + 1))
        batch, more = rows[:limit], len(rows) > limit
        failed = 0
        for row in batch:
            try:
                if settlement(str(row['top_up_id'])) is not True:
                    failed += 1
            except Exception:
                failed += 1
        try:
            scan_result = scan(gate['window_start'], gate['window_end'])
        except Exception:
            scan_result = {'status': 'failed'}
        if not isinstance(scan_result, dict) or scan_result.get('status') != 'completed':
            scan_result = {'status': 'failed'}
        with transaction.atomic(using=self.using):
            current = self.db.one('SELECT * FROM public.balance_restore_gates WHERE id=1 FOR UPDATE')
            if not current or current['state'] != 'open' or current['opened_at'] != gate['opened_at'] or current['replay_evidence'] != gate['replay_evidence']:
                raise ValueError('Restore window or replay changed')
            now = legacy_now()
            quarantined = self.db.execute('''UPDATE public.telegram_outbound_effects SET state='unknown',claim_token=NULL,
                lease_until=NULL,next_attempt_at=NULL,unknown_at=%s,last_error_code='restore_ambiguous_delivery',
                last_error=NULL,updated_at=%s WHERE id IN (SELECT id FROM public.telegram_outbound_effects
                WHERE effect_type=ANY(%s) AND state IN ('pending','sending','failed') ORDER BY id LIMIT %s FOR UPDATE SKIP LOCKED)''',
                (now, now, _FAMILIES, limit))
            remaining = self.db.one("SELECT COUNT(*) AS n FROM public.telegram_outbound_effects WHERE effect_type=ANY(%s) AND state IN ('pending','sending','failed')", (_FAMILIES,))['n']
            evidence = {'window_opened_at': gate['opened_at'].isoformat(), 'settlement_cursor': str(batch[-1]['top_up_id']) if batch and not failed else cursor,
                        'settlement': {'checked': len(batch), 'failed': failed, 'has_more': more},
                        'scan': {'status': scan_result['status']}, 'outbound': {'quarantined': quarantined, 'remaining': remaining}}
            completed = not failed and not more and not remaining and scan_result['status'] == 'completed'
            self.db.execute('''UPDATE public.balance_restore_gates SET replay_completed_at=%s,replay_by=%s,replay_evidence=%s,updated_at=%s WHERE id=1''',
                            (now if completed else None, actor, json.dumps(evidence), now))
            self.db.execute("INSERT INTO public.audit_logs(action,new_values,created_at) VALUES ('usdt_balance.restore_gate.replayed',%s,%s)", (json.dumps({'actor': actor, **evidence}), now))
            return evidence

    def close(self, *, actor):
        _outside(self.using)
        actor = _text(actor)
        with transaction.atomic(using=self.using):
            gate = self.db.one('SELECT * FROM public.balance_restore_gates WHERE id=1 FOR UPDATE')
            if not gate or gate['state'] != 'open' or not gate['replay_completed_at']:
                raise ValueError('Restore replay incomplete')
            evidence = _json(gate['replay_evidence']) or {}
            if (evidence.get('window_opened_at') != gate['opened_at'].isoformat()
                    or evidence.get('settlement', {}).get('failed') != 0
                    or evidence.get('settlement', {}).get('has_more') is not False
                    or evidence.get('scan', {}).get('status') != 'completed'
                    or evidence.get('outbound', {}).get('remaining') != 0):
                raise ValueError('Incomplete replay evidence')
            remaining = self.db.one("SELECT COUNT(*) AS n FROM public.telegram_outbound_effects WHERE effect_type=ANY(%s) AND state IN ('pending','sending','failed')", (_FAMILIES,))['n']
            if remaining:
                raise ValueError('Unquarantined delivery')
            run = self.db.one("SELECT * FROM public.usdt_balance_reconciliation_runs WHERE scope='full' AND checked_at >= %s ORDER BY checked_at DESC,id DESC LIMIT 1", (gate['replay_completed_at'],))
            if not run:
                raise ValueError('Missing post-replay reconciliation')
            findings = _json(run['findings']) or {}
            if (run['cursor_after'] not in (None, 0) or run['receipt_cursor_after'] not in (None, 0)
                    or run['credit_cursor_after'] not in (None, 0) or run['verdict'] != 'consistent'
                    or run['account_finding_count'] != 0 or run['receipt_finding_count'] != 0
                    or run['custody_state'] != 'available' or run['custody_assets_micro'] is None
                    or findings.get('accounts', {}).get('has_more') is not False
                    or findings.get('receipts', {}).get('receipts_has_more') is not False
                    or findings.get('receipts', {}).get('credits_have_more') is not False
                    or findings.get('treasury', {}).get('unexplained_micro') not in (0, '0')):
                raise ValueError('Reconciliation incomplete or drift unexplained')
            now = legacy_now()
            closed = {'reconciliation_run_id': run['id'], 'unexplained_micro': '0', 'unquarantined_deliveries': 0}
            result = self.db.one("UPDATE public.balance_restore_gates SET state='cleared',cleared_at=%s,cleared_by=%s,cleared_evidence=%s,updated_at=%s WHERE id=1 RETURNING *", (now, actor, json.dumps(closed), now))
            self.db.execute("INSERT INTO public.audit_logs(action,new_values,created_at) VALUES ('usdt_balance.restore_gate.cleared',%s,%s)", (json.dumps({'actor': actor, **closed}), now))
            return result
