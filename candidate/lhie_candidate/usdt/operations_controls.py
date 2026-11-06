"""Owner-scoped USDT pauses and durable operational alert episodes."""
import hashlib
import json
import re
from uuid import uuid4
from django.db import transaction
from lhie_candidate.adjustments.password import owner
from lhie_candidate.auth.types import legacy_now
from lhie_candidate.checkout.db import Database
from lhie_candidate.usdt.operations import _outside

_SCOPES = frozenset(('funding', 'spending', 'rail_binance_pay', 'rail_bsc_usdt', 'delivery'))
_ALERTS = frozenset(('reorg_pending', 'reorg_loss_unresolved', 'reconciliation_drift',
    'reconciliation_receipt_collision', 'treasury_coverage', 'restore_gate_open',
    'delivery_paused', 'account_frozen', 'receipt_registry_conflict', 'top_up_work_backlog',
    'settlement_backlog', 'outbound_backlog'))


class OperationalControls:
    def __init__(self, using='default'):
        self.using, self.db = using, Database(using)

    def pause(self, request, scope, reason):
        _outside(self.using)
        if scope not in _SCOPES or not isinstance(reason, str) or not 1 <= len(reason.strip()) <= 191:
            raise ValueError('Invalid pause')
        with transaction.atomic(using=self.using):
            actor = owner(request, self.db, lock=True)
            if not self.db.one("SELECT id FROM public.customer_usdt_funding_policies WHERE singleton_key='customer_usdt_funding_policy' FOR UPDATE"):
                raise ValueError('Funding policy unavailable')
            now = legacy_now()
            row = self.db.one('''INSERT INTO public.customer_usdt_funding_pauses
                (scope,reason,paused_by_user_id,paused_at,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%s)
                ON CONFLICT (scope) DO NOTHING RETURNING *''', (scope, reason.strip(), actor['id'], now, now, now))
            if row:
                self.db.execute("INSERT INTO public.audit_logs(user_id,action,new_values,created_at) VALUES (%s,'usdt_funding.paused',%s,%s)", (actor['id'], json.dumps({'scope': scope, 'reason': reason.strip()}), now))
            return row or self.db.one('SELECT * FROM public.customer_usdt_funding_pauses WHERE scope=%s', (scope,))

    def resume(self, request, scope):
        _outside(self.using)
        if scope not in _SCOPES:
            raise ValueError('Invalid pause scope')
        with transaction.atomic(using=self.using):
            actor = owner(request, self.db, lock=True)
            if not self.db.one("SELECT id FROM public.customer_usdt_funding_policies WHERE singleton_key='customer_usdt_funding_policy' FOR UPDATE"):
                raise ValueError('Funding policy unavailable')
            row = self.db.one('DELETE FROM public.customer_usdt_funding_pauses WHERE scope=%s RETURNING *', (scope,))
            if row:
                self.db.execute("INSERT INTO public.audit_logs(user_id,action,new_values,created_at) VALUES (%s,'usdt_funding.resumed',%s,%s)", (actor['id'], json.dumps({'scope': scope}), legacy_now()))
            return bool(row)

    def unfreeze(self, request, customer_id, reason, *, dry_run=False):
        from lhie_candidate.domain.money import require_integer
        from lhie_candidate.usdt.reconciliation import account_health
        _outside(self.using)
        require_integer(customer_id, minimum=1)
        if (not isinstance(reason, str) or not 1 <= len(reason.strip()) <= 191
                or any(not 32 <= ord(ch) <= 126 for ch in reason.strip()) or type(dry_run) is not bool):
            raise ValueError('Invalid unfreeze reason')
        with transaction.atomic(using=self.using):
            actor = owner(request, self.db, lock=True)
            if not self.db.one('SELECT id FROM public.customers WHERE id=%s FOR UPDATE', (customer_id,)):
                return 'missing_account'
            account = self.db.one('''SELECT id,customer_id,frozen_reason FROM public.customer_usdt_balance_accounts
                WHERE customer_id=%s FOR UPDATE''', (customer_id,))
            if not account:
                return 'missing_account'
            frozen = account['frozen_reason']
            if frozen is None:
                return 'not_frozen'
            hold = self.db.one('''SELECT EXISTS(SELECT 1 FROM public.usdt_reorg_incidents WHERE account_id=%s)
                OR EXISTS(SELECT 1 FROM public.usdt_reorg_receiver_evidence e
                    JOIN public.customer_usdt_deposit_receivers r ON r.id=e.receiver_id
                    WHERE r.customer_id=%s) AS held''', (account['id'], customer_id))
            if frozen.startswith(('usdt_reorg_incident:', 'usdt_receiver_reorg:')) or hold['held']:
                return 'reorg_hold'
            if frozen not in ('reconciliation_drift:balance_mismatch',
                              'reconciliation_drift:cursor_mismatch', 'reconciliation_drift:history_gap'):
                return 'unsupported_freeze'
            health = account_health(self.db, account_id=account['id'])
            if health is None or health['issues']:
                return 'drift_remains'
            if dry_run:
                return 'dry_run_eligible'
            self.db.execute('''UPDATE public.customer_usdt_balance_accounts SET frozen_reason=NULL,updated_at=%s
                WHERE id=%s''', (legacy_now(), account['id']))
            self.db.execute('''INSERT INTO public.audit_logs(user_id,action,subject_type,subject_id,old_values,new_values,created_at)
                VALUES (%s,'usdt_balance.account_unfrozen','App\\Models\\Customer',%s,%s,%s,%s)''',
                (actor['id'], str(customer_id), json.dumps({'frozen_reason': frozen}),
                 json.dumps({'account_id': account['id'], 'reason': reason.strip()}), legacy_now()))
            return 'unfrozen'

    def alert(self, key, severity, summary, evidence, *, admin_chat_id):
        _outside(self.using)
        if not isinstance(admin_chat_id, str) or not re.fullmatch(r'[1-9][0-9]{0,18}', admin_chat_id):
            raise ValueError('Authorized admin recipient required')
        if (key not in _ALERTS or severity not in ('warning', 'critical') or not isinstance(summary, str)
                or not 1 <= len(summary.strip()) <= 500 or not isinstance(evidence, dict)
                or any(k not in ('count', 'run_id', 'unexplained_micro', 'state') for k in evidence)
                or any(type(v) not in (int, str, type(None)) for v in evidence.values())):
            raise ValueError('Invalid alert evidence')
        payload = json.dumps(evidence, sort_keys=True, separators=(',', ':'))
        if len(payload) > 1024:
            raise ValueError('Alert evidence too large')
        stable = json.dumps({k: v for k, v in evidence.items() if k != 'run_id'},
                            sort_keys=True, separators=(',', ':'))
        fingerprint = hashlib.sha256((severity + '\0' + summary.strip() + '\0' + stable).encode()).hexdigest()
        now = legacy_now()
        with transaction.atomic(using=self.using):
            episode = self.db.one('''INSERT INTO public.balance_operations_alert_episodes
                (alert_key,severity,state,summary,fingerprint,evidence,occurrences,open_count,first_seen_at,last_seen_at,created_at,updated_at)
                VALUES (%s,%s,'open',%s,%s,%s,1,1,%s,%s,%s,%s)
                ON CONFLICT (alert_key) DO UPDATE SET severity=EXCLUDED.severity,state='open',summary=EXCLUDED.summary,
                fingerprint=EXCLUDED.fingerprint,evidence=EXCLUDED.evidence,occurrences=balance_operations_alert_episodes.occurrences+1,
                open_count=balance_operations_alert_episodes.open_count+CASE WHEN balance_operations_alert_episodes.state='resolved' THEN 1 ELSE 0 END,
                last_seen_at=EXCLUDED.last_seen_at,resolved_at=NULL,updated_at=EXCLUDED.updated_at RETURNING *''',
                (key, severity, summary.strip(), fingerprint, payload, now, now, now, now))
            effect_key = f'balance-ops-alert:{episode["id"]}:{fingerprint}:{episode["open_count"]}'
            message = f'USDT operations alert: {key}. Review the operational evidence.'
            self.db.execute('''INSERT INTO public.telegram_outbound_effects
                (id,effect_key,effect_group_key,sequence,effect_type,source_type,source_id,target_type,
                 telegram_chat_id,queue,telegram_method,locale,template_version,state,attempts,
                 next_attempt_at,outcome_metadata,created_at,updated_at)
                VALUES (%s,%s,%s,1,'admin.balance_operations_alert','App\\Models\\BalanceOperationsAlertEpisode',
                        %s,'admin',%s,'telegram-outbound','sendMessage','id','balance-operations-alert-v1',
                        'pending',0,%s,%s,%s,%s) ON CONFLICT (effect_key) DO NOTHING''',
                (str(uuid4()), effect_key, effect_key, str(episode['id']), admin_chat_id, now,
                 json.dumps({'delivery_context': 'balance_operations_alert', 'message': message}), now, now))
            effect = self.db.one('''SELECT effect_type,source_id,target_type,telegram_chat_id,outcome_metadata
                FROM public.telegram_outbound_effects WHERE effect_key=%s''', (effect_key,))
            metadata = effect['outcome_metadata']
            if isinstance(metadata, str):
                metadata = json.loads(metadata)
            if (effect['effect_type'] != 'admin.balance_operations_alert' or effect['source_id'] != str(episode['id'])
                    or effect['target_type'] != 'admin' or effect['telegram_chat_id'] != admin_chat_id
                    or metadata.get('message') != message):
                raise ValueError('Alert delivery identity conflict')
            return episode

    def resolve(self, key, *, admin_chat_id):
        _outside(self.using)
        if key not in _ALERTS or not isinstance(admin_chat_id, str) or not re.fullmatch(r'[1-9][0-9]{0,18}', admin_chat_id):
            raise ValueError('Invalid alert resolution')
        with transaction.atomic(using=self.using):
            now = legacy_now()
            episode = self.db.one('''UPDATE public.balance_operations_alert_episodes
                SET state='resolved',resolved_at=%s,last_seen_at=%s,updated_at=%s
                WHERE alert_key=%s AND state='open' RETURNING *''', (now, now, now, key))
            if episode is None:
                return False
            effect_key = f'balance-ops-alert:{episode["id"]}:resolved:{episode["open_count"]}'
            message = f'USDT operations alert resolved: {key}.'
            self.db.execute('''INSERT INTO public.telegram_outbound_effects
                (id,effect_key,effect_group_key,sequence,effect_type,source_type,source_id,target_type,
                 telegram_chat_id,queue,telegram_method,locale,template_version,state,attempts,
                 next_attempt_at,outcome_metadata,created_at,updated_at)
                VALUES (%s,%s,%s,1,'admin.balance_operations_alert','App\\Models\\BalanceOperationsAlertEpisode',
                        %s,'admin',%s,'telegram-outbound','sendMessage','id','balance-operations-alert-v1',
                        'pending',0,%s,%s,%s,%s) ON CONFLICT (effect_key) DO NOTHING''',
                (str(uuid4()), effect_key, effect_key, str(episode['id']), admin_chat_id, now,
                 json.dumps({'delivery_context': 'balance_operations_alert', 'message': message}), now, now))
            return True

    def evaluate(self, *, admin_chat_id):
        """Reconcile scoped, sanitized signals independently of funding pauses."""
        _outside(self.using)
        row = self.db.one('''SELECT
            (SELECT COUNT(*) FROM public.usdt_bsc_scan_checkpoints WHERE review_reason IN
                ('checkpoint_reorg','log_block_reorg','page_boundary_reorg','finalized_block_changed')) AS reorg_pending,
            (SELECT COUNT(*) FROM public.usdt_reorg_incidents) AS reorg_loss_unresolved,
            (SELECT COUNT(*) FROM public.customer_usdt_balance_accounts WHERE frozen_reason IS NOT NULL) AS account_frozen,
            (SELECT COUNT(*) FROM public.balance_restore_gates WHERE state='open') AS restore_gate_open,
            (SELECT COUNT(*) FROM public.customer_usdt_funding_pauses WHERE scope='delivery') AS delivery_paused,
            (SELECT COUNT(*) FROM public.external_money_receipts WHERE status='review')
              + (SELECT COUNT(*) FROM public.external_money_receipt_reservations
                 WHERE reason='registry_conflict' AND resolved_at IS NULL) AS receipt_registry_conflict''')
        run = self.db.one('''SELECT id,account_finding_count,receipt_finding_count,custody_state,liability_micro,findings
            FROM public.usdt_balance_reconciliation_runs WHERE scope='full' ORDER BY checked_at DESC,id DESC LIMIT 1''')
        signals = {key: int(row[key]) for key in
                   ('reorg_pending','reorg_loss_unresolved','account_frozen','restore_gate_open','delivery_paused','receipt_registry_conflict')}
        now = legacy_now()
        backlog = self.db.one('''SELECT
            (SELECT COUNT(*) FROM public.customer_usdt_top_up_work
             WHERE state IN ('pending','creating','creation_unknown') AND next_attempt_at<=%s) AS preparation_count,
            (SELECT MIN(next_attempt_at) FROM public.customer_usdt_top_up_work
             WHERE state IN ('pending','creating','creation_unknown') AND next_attempt_at<=%s) AS preparation_oldest,
            (SELECT COUNT(*) FROM public.customer_usdt_top_up_settlement_work
             WHERE state NOT IN ('settled','externally_refunded','resolved_by_compensation','rejected') AND next_check_at<=%s) AS settlement_count,
            (SELECT MIN(next_check_at) FROM public.customer_usdt_top_up_settlement_work
             WHERE state NOT IN ('settled','externally_refunded','resolved_by_compensation','rejected') AND next_check_at<=%s) AS settlement_oldest,
            (SELECT COUNT(*) FROM public.telegram_outbound_effects WHERE state IN ('pending','failed')
             AND effect_type IN ('usdt.top_up_receipt','usdt.refund_receipt','balance.top_up_receipt',
                 'admin.balance_top_up_owner_alert','admin.balance_top_up_paid_notification','balance.owner_compensation_notification')) AS outbound_count,
            (SELECT MIN(created_at) FROM public.telegram_outbound_effects WHERE state IN ('pending','failed')
             AND effect_type IN ('usdt.top_up_receipt','usdt.refund_receipt','balance.top_up_receipt',
                 'admin.balance_top_up_owner_alert','admin.balance_top_up_paid_notification','balance.owner_compensation_notification')) AS outbound_oldest''',
            (now, now, now, now))
        backlog_severity = {}
        for key, prefix, warning, critical in (
                ('top_up_work_backlog', 'preparation', 600, 1800),
                ('settlement_backlog', 'settlement', 600, 1800),
                ('outbound_backlog', 'outbound', 300, 900)):
            oldest = backlog[prefix + '_oldest']
            age = max(0, int((now - oldest).total_seconds())) if oldest else 0
            signals[key] = int(backlog[prefix + '_count']) if age > warning else 0
            backlog_severity[key] = 'critical' if age > critical else 'warning'
        if run:
            findings = run['findings']
            if isinstance(findings, str):
                findings = json.loads(findings)
            unexplained = (findings or {}).get('treasury', {}).get('unexplained_micro')
            signals.update(reconciliation_drift=run['account_finding_count'],
                           reconciliation_receipt_collision=run['receipt_finding_count'],
                           treasury_coverage=int((run['liability_micro'] != 0 and run['custody_state'] != 'available')
                                                 or unexplained not in (0, '0', None)))
        for key, count in signals.items():
            if count:
                self.alert(key, backlog_severity.get(key, 'warning' if key == 'delivery_paused' else 'critical'),
                           'Balance operational evidence requires review.', {'count': int(count)},
                           admin_chat_id=admin_chat_id)
            else:
                self.resolve(key, admin_chat_id=admin_chat_id)
        return signals
