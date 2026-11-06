"""Bounded ledger drift detection and exactly-once accounting aggregation."""
import re

from lhie_candidate.checkout.db import Database
from lhie_candidate.domain.money import require_integer


_ACCOUNT_QUERY = """SELECT a.id AS account_id,a.customer_id,a.currency,a.balance_micro,a.version,
    a.last_entry_id,a.frozen_reason,h.entry_count,h.min_sequence,h.max_sequence,h.signed_sum,
    h.unknown_entries,l.id AS latest_id,l.account_sequence AS latest_sequence,
    l.balance_after_micro AS latest_balance
    FROM (SELECT id,customer_id,currency,balance_micro,version,last_entry_id,frozen_reason
          FROM public.customer_usdt_balance_accounts
          WHERE (%s::bigint IS NULL OR customer_id=%s)
            AND (%s::bigint IS NULL OR id>%s) AND (%s::bigint IS NULL OR id=%s)
          ORDER BY id LIMIT %s) a
    CROSS JOIN LATERAL (SELECT COUNT(*) AS entry_count,MIN(account_sequence) AS min_sequence,
        MAX(account_sequence) AS max_sequence,
        COALESCE(SUM(CASE WHEN kind IN ('purchase_debit','reversal_debit') THEN -amount_micro::numeric
                     WHEN kind IN ('funding_credit','refund_credit') THEN amount_micro::numeric ELSE 0 END),0) AS signed_sum,
        COUNT(*) FILTER (WHERE kind NOT IN ('purchase_debit','reversal_debit','funding_credit','refund_credit')) AS unknown_entries
        FROM public.customer_usdt_balance_entries WHERE customer_usdt_balance_account_id=a.id) h
    LEFT JOIN LATERAL (SELECT id,account_sequence,balance_after_micro FROM public.customer_usdt_balance_entries
        WHERE customer_usdt_balance_account_id=a.id ORDER BY id DESC LIMIT 1) l ON true ORDER BY a.id"""


def _assess(row):
    issues = []
    balance, version, count = row['balance_micro'], row['version'], row['entry_count']
    if row['currency'] != 'USDT' or row['unknown_entries'] or balance != row['signed_sum']:
        issues.append('balance_mismatch')
    if version == 0:
        cursor_ok = row['latest_id'] is None and row['last_entry_id'] is None and balance == 0
        history_ok = count == 0 and row['last_entry_id'] is None
    else:
        cursor_ok = (row['latest_id'] is not None and row['last_entry_id'] == row['latest_id']
                     and row['latest_sequence'] == version and row['latest_balance'] == balance)
        history_ok = count == version and row['min_sequence'] == 1 and row['max_sequence'] == version
    if not cursor_ok:
        issues.append('cursor_mismatch')
    if not history_ok:
        issues.append('history_gap')
    return dict(row, signed_sum=int(row['signed_sum']), issues=issues)


def _accounts(db, customer_id=None, after=None, account_id=None, limit=1):
    return [_assess(row) for row in db.rows(_ACCOUNT_QUERY,
        (customer_id, customer_id, after, after, account_id, account_id, limit))]


def account_health(db, *, account_id):
    rows = _accounts(db, account_id=account_id)
    return rows[0] if rows else None


class UsdtReconciliation:
    def __init__(self, using='default'):
        self.db = Database(using)

    def snapshot(self, customer_id):
        """Read projection and signed history in the same database snapshot."""
        require_integer(customer_id, minimum=1)
        rows = _accounts(self.db, customer_id=customer_id)
        if not rows:
            return {'available': True, 'balance_micro': 0, 'frozen_reason': None, 'issues': []}
        row = rows[0]
        return {'available': not row['issues'], 'balance_micro': row['balance_micro'],
                'frozen_reason': row['frozen_reason'], 'issues': row['issues']}

    def inspect(self, *, customer_id=None, after_account_id=None, limit=100):
        if customer_id is not None:
            require_integer(customer_id, minimum=1)
        if after_account_id is not None:
            require_integer(after_account_id, minimum=1)
        if type(limit) is not int or not 1 <= limit <= 1000:
            raise ValueError('Invalid reconciliation limit')
        rows = _accounts(self.db, customer_id, after_account_id, limit=limit + 1)
        more = len(rows) > limit
        page = rows[:limit]
        return {'accounts_inspected': len(page), 'findings': [r for r in page if r['issues']],
                'has_more': more, 'next_cursor': page[-1]['account_id'] if more else None}

    def freeze_drift(self, account_id):
        """Recheck under the account lock; caller commits the freeze transaction."""
        self.db.require_transaction()
        require_integer(account_id, minimum=1)
        account = self.db.one('SELECT id,frozen_reason FROM public.customer_usdt_balance_accounts WHERE id=%s FOR UPDATE', (account_id,))
        if account is None:
            return 'missing'
        if account['frozen_reason'] is not None:
            return 'already_frozen'
        finding = account_health(self.db, account_id=account_id)
        if finding is None or not finding['issues']:
            return 'stale'
        self.db.execute("""UPDATE public.customer_usdt_balance_accounts SET frozen_reason=%s,
            updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta' WHERE id=%s AND frozen_reason IS NULL""",
            ('reconciliation_drift:' + finding['issues'][0], account_id))
        return 'frozen'


class UsdtAccountingAggregator:
    def __init__(self, using='default'):
        self.db = Database(using)

    @staticmethod
    def _consumer(consumer):
        if type(consumer) is not str or re.fullmatch(r'[a-z0-9][a-z0-9_-]{0,63}', consumer, re.ASCII) is None:
            raise ValueError('Invalid accounting consumer')

    def catch_up(self, consumer='reporting-v1', limit=100):
        """Claim and aggregate one bounded batch atomically in the caller's transaction."""
        self.db.require_transaction()
        self._consumer(consumer)
        if type(limit) is not int or not 1 <= limit <= 1000:
            raise ValueError('Invalid accounting batch limit')
        events = self.db.rows("""SELECT e.id,e.currency,e.amount_micro,e.debit_account,e.credit_account
            FROM public.customer_usdt_balance_accounting_events e WHERE NOT EXISTS
            (SELECT 1 FROM public.usdt_balance_accounting_aggregate_claims c
             WHERE c.consumer=%s AND c.customer_usdt_balance_accounting_event_id=e.id)
            ORDER BY e.id LIMIT %s""", (consumer, limit))
        processed = 0
        for event in events:
            amount = require_integer(event['amount_micro'], minimum=1)
            if event['currency'] != 'USDT':
                raise ValueError('Invalid accounting currency')
            claim = self.db.one("""INSERT INTO public.usdt_balance_accounting_aggregate_claims
                (consumer,currency,customer_usdt_balance_accounting_event_id,created_at)
                VALUES (%s,'USDT',%s,CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta')
                ON CONFLICT (consumer,customer_usdt_balance_accounting_event_id) DO NOTHING RETURNING id""",
                (consumer, event['id']))
            if claim is None:
                continue
            self.db.execute("""INSERT INTO public.usdt_balance_accounting_aggregates
                (consumer,currency,debit_account,credit_account,entries_count,amount_micro,first_event_id,last_event_id,created_at,updated_at)
                VALUES (%s,'USDT',%s,%s,1,%s,%s,%s,CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta',CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta')
                ON CONFLICT (consumer,currency,debit_account,credit_account) DO UPDATE SET
                entries_count=usdt_balance_accounting_aggregates.entries_count+1,
                amount_micro=usdt_balance_accounting_aggregates.amount_micro+EXCLUDED.amount_micro,
                first_event_id=LEAST(usdt_balance_accounting_aggregates.first_event_id,EXCLUDED.first_event_id),
                last_event_id=GREATEST(usdt_balance_accounting_aggregates.last_event_id,EXCLUDED.last_event_id),
                updated_at=EXCLUDED.updated_at""", (consumer, event['debit_account'], event['credit_account'], amount, event['id'], event['id']))
            processed += 1
        return {'consumer': consumer, 'processed': processed, 'last_event_id': events[-1]['id'] if events else None}

    def status(self, consumer='reporting-v1'):
        self._consumer(consumer)
        summary = self.db.one("""SELECT
            (SELECT COUNT(*) FROM public.customer_usdt_balance_accounting_events e WHERE NOT EXISTS
             (SELECT 1 FROM public.usdt_balance_accounting_aggregate_claims c WHERE c.consumer=%s
              AND c.customer_usdt_balance_accounting_event_id=e.id)) AS pending_events,
            (SELECT COUNT(*) FROM public.usdt_balance_accounting_aggregate_claims WHERE consumer=%s) AS claimed_events,
            (SELECT COALESCE(SUM(e.amount_micro::numeric),0) FROM public.usdt_balance_accounting_aggregate_claims c
             JOIN public.customer_usdt_balance_accounting_events e ON e.id=c.customer_usdt_balance_accounting_event_id
             WHERE c.consumer=%s) AS claimed_micro,
            (SELECT COALESCE(SUM(entries_count::numeric),0) FROM public.usdt_balance_accounting_aggregates WHERE consumer=%s) AS aggregated_events,
            (SELECT COALESCE(SUM(amount_micro::numeric),0) FROM public.usdt_balance_accounting_aggregates WHERE consumer=%s) AS aggregated_micro""",
            (consumer, consumer, consumer, consumer, consumer))
        legs = self.db.rows("""SELECT currency,debit_account,credit_account,entries_count,amount_micro
            FROM public.usdt_balance_accounting_aggregates WHERE consumer=%s ORDER BY debit_account,credit_account""", (consumer,))
        for key in ('claimed_micro', 'aggregated_events', 'aggregated_micro'):
            summary[key] = int(summary[key])
        return dict(summary, consumer=consumer, legs=legs,
                    consistent=(summary['claimed_events'] == summary['aggregated_events']
                                and summary['claimed_micro'] == summary['aggregated_micro']))
