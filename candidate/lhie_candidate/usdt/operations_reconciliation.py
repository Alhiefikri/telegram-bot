"""Complete paginated USDT reconciliation and custody coverage evidence."""
import json
from django.db import transaction
from lhie_candidate.auth.types import legacy_now
from lhie_candidate.checkout.db import Database
from lhie_candidate.usdt.reconciliation import UsdtAccountingAggregator, UsdtReconciliation
from lhie_candidate.usdt.treasury import CustodyRegistry
from lhie_candidate.domain.money import require_integer


def _micro(units, decimals):
    if not isinstance(units, (str, int)) or isinstance(units, bool) or not str(units).isdigit() or type(decimals) is not int or not 0 <= decimals <= 36:
        return None
    value = int(units)
    if decimals <= 6:
        return value * 10 ** (6 - decimals)
    factor = 10 ** (decimals - 6)
    return value // factor if value % factor == 0 else None


class OperationalReconciliation:
    def __init__(self, using='default', *, custody_providers=()):
        self.using, self.db = using, Database(using)
        self.accounts = UsdtReconciliation(using)
        self.accounting = UsdtAccountingAggregator(using)
        self.custody = CustodyRegistry(custody_providers)

    def receipt_page(self, *, after_receipt=0, after_credit=0, limit=100):
        if type(limit) is not int or not 1 <= limit <= 1000:
            raise ValueError('Invalid page limit')
        receipts = self.db.rows('''SELECT r.id,r.currency,r.status,r.amount_units,r.decimals,r.target_id,
            e.id AS entry_id,e.amount_micro AS credited_micro,f.amount_micro AS fee_micro,
            z.kind AS resolution_kind,z.receipt_id AS resolution_receipt_id,z.refund_receipt_id,z.resolved_amount_micro,
            returned.status AS return_status,deposited.status AS deposit_status
            FROM public.external_money_receipts r
            LEFT JOIN public.customer_usdt_top_up_resolutions z ON z.top_up_id::text=r.target_id
              AND r.target_type='customer_usdt_top_up'
            LEFT JOIN public.external_money_receipts returned ON returned.id=z.refund_receipt_id
            LEFT JOIN public.external_money_receipts deposited ON deposited.id=z.receipt_id
            LEFT JOIN public.customer_usdt_balance_entries e ON e.source_id=r.target_id
              AND e.source_type=r.target_type AND e.kind='funding_credit'
              AND (z.top_up_id IS NULL OR z.receipt_id=r.id)
            LEFT JOIN public.customer_usdt_top_up_fee_events f ON f.receipt_id=r.id
            WHERE r.target_type IN ('customer_usdt_top_up','customer_usdt_recovery') AND r.id>%s
            ORDER BY r.id LIMIT %s''', (after_receipt, limit + 1))
        credits = self.db.rows('''SELECT e.id FROM public.customer_usdt_balance_entries e
            WHERE e.kind='funding_credit' AND e.source_type IN ('customer_usdt_top_up','customer_usdt_recovery')
            AND e.id>%s AND NOT EXISTS (SELECT 1 FROM public.external_money_receipts r
            WHERE r.target_id=e.source_id AND r.target_type=e.source_type) ORDER BY e.id LIMIT %s''', (after_credit, limit + 1))
        more_receipts, more_credits = len(receipts) > limit, len(credits) > limit
        receipts, credits = receipts[:limit], credits[:limit]
        findings, unresolved, overcredited = [], 0, 0
        for row in receipts:
            amount = _micro(row['amount_units'], row['decimals'])
            if (row['resolution_kind'] == 'externally_refunded' and row['refund_receipt_id'] is not None
                    and row['id'] in (row['resolution_receipt_id'], row['refund_receipt_id'])
                    and row['status'] == 'claimed' and row['return_status'] == 'claimed'
                    and row['deposit_status'] == 'claimed' and row['currency'] == 'USDT'
                    and amount is not None and amount == row['resolved_amount_micro']):
                continue
            if row['currency'] != 'USDT' or amount is None:
                findings.append('currency_mismatch' if row['currency'] != 'USDT' else 'unrepresentable_amount')
            elif row['entry_id'] is None:
                findings.append('unsettled_receipt')
                unresolved += amount
            else:
                difference = amount - row['credited_micro'] - (row['fee_micro'] or 0)
                if difference or row['status'] != 'claimed':
                    findings.append('receipt_mismatch')
                unresolved += max(0, difference)
                overcredited += max(0, -difference)
        findings.extend(['credit_without_receipt'] * len(credits))
        return {'receipt_count': len(receipts), 'credit_count': len(credits), 'findings': findings,
                'unresolved': unresolved, 'overcredited': overcredited,
                'receipts_more': more_receipts, 'credits_more': more_credits,
                'receipt_cursor': receipts[-1]['id'] if receipts else after_receipt,
                'credit_cursor': credits[-1]['id'] if credits else after_credit}

    def complete(self, *, actor='operator', limit=100):
        """Observe approved providers before capturing the complete database snapshot."""
        from .operations import _outside
        _outside(self.using)
        if type(limit) is not int or not 1 <= limit <= 1000 or not isinstance(actor, str) or not actor.strip() or len(actor) > 191:
            raise ValueError('Invalid reconciliation arguments')
        observations = self.custody.observe_all()
        # No provider or adapter is called in this transaction.
        with transaction.atomic(using=self.using):
            self.db.execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')
            checked = legacy_now()
            cursor, account_count, account_findings, frozen = None, 0, 0, 0
            while True:
                page = self.accounts.inspect(after_account_id=cursor, limit=limit)
                account_count += page['accounts_inspected']
                account_findings += len(page['findings'])
                for finding in page['findings']:
                    frozen += self.accounts.freeze_drift(finding['account_id']) == 'frozen'
                if not page['has_more']:
                    break
                cursor = page['next_cursor']
            receipt_cursor = credit_cursor = 0
            receipt_count = credit_count = unresolved = overcredited = receipt_findings = 0
            while True:
                page = self.receipt_page(after_receipt=receipt_cursor, after_credit=credit_cursor, limit=limit)
                receipt_count += page['receipt_count']; credit_count += page['credit_count']
                unresolved += page['unresolved']; overcredited += page['overcredited']
                receipt_findings += len(page['findings'])
                receipt_cursor, credit_cursor = page['receipt_cursor'], page['credit_cursor']
                if not page['receipts_more'] and not page['credits_more']:
                    break
            totals = self.db.one('''SELECT
                (SELECT COALESCE(SUM(CASE WHEN kind IN ('purchase_debit','reversal_debit') THEN -amount_micro::numeric ELSE amount_micro::numeric END),0) FROM public.customer_usdt_balance_entries) AS liability,
                (SELECT COALESCE(SUM(amount_micro::numeric),0) FROM public.customer_usdt_top_up_fee_events) AS fees,
                (SELECT COALESCE(SUM(amount_micro::numeric),0) FROM public.usdt_treasury_movements WHERE direction='in') AS movement_in,
                (SELECT COALESCE(SUM(amount_micro::numeric),0) FROM public.usdt_treasury_movements WHERE direction='out') AS movement_out,
                (SELECT COUNT(*) FROM public.usdt_treasury_movements) AS movement_count,
                (SELECT COUNT(*) FROM public.usdt_reorg_incidents) AS incident_count,
                (SELECT COUNT(*) FROM public.usdt_bsc_scan_checkpoints WHERE review_reason IN ('checkpoint_reorg','log_block_reorg','page_boundary_reorg','finalized_block_changed')) AS pending_reorg_receivers,
                (SELECT COALESCE(SUM(exposure_micro::numeric),0) FROM public.usdt_reorg_incidents) AS exposure,
                (SELECT COALESCE(SUM(spent_exposure_micro::numeric),0) FROM public.usdt_reorg_incidents) AS spent,
                (SELECT COUNT(*) FROM public.usdt_reorg_recovery_cases) AS authorized''')
            valid = bool(observations) and all(x.state == 'available' for x in observations)
            assets = require_integer(sum(x.assets_micro for x in observations)) if valid else None
            custody_state = ('available' if valid else 'missing' if not observations else
                             'unavailable' if any(x.state == 'unavailable' for x in observations) else 'unsupported')
            liability, fees = int(totals['liability']), int(totals['fees'])
            movement_in, movement_out = int(totals['movement_in']), int(totals['movement_out'])
            unexplained = assets - liability - unresolved - fees - movement_in + movement_out if valid else None
            accounting = self.accounting.status()
            issues = (account_findings or receipt_findings or totals['incident_count'] or totals['pending_reorg_receivers'] or not accounting['consistent']
                      or accounting['pending_events'] or unexplained != 0 or overcredited)
            findings = {'accounts': {'has_more': False}, 'receipts': {'receipts_has_more': False, 'credits_have_more': False,
                        'inspected': receipt_count, 'credits_inspected': credit_count, 'overcredited_micro': str(overcredited)},
                        'treasury': {'unexplained_micro': str(unexplained) if unexplained is not None else None,
                        'custody_providers': [{'provider': x.provider, 'state': x.state,
                            'assets_micro': str(x.assets_micro) if x.assets_micro is not None else None,
                            'observed_at': x.observed_at.isoformat() if x.observed_at is not None else None,
                            'reason': x.reason} for x in observations],
                        'reorg_exposure_micro': str(totals['exposure']), 'reorg_spent_exposure_micro': str(totals['spent']),
                        'authorized_recovery_cases': totals['authorized'], 'unresolved_reorg_loss': bool(totals['incident_count']),
                        'pending_reorg_receivers': totals['pending_reorg_receivers']},
                        'accounting': {'consistent': accounting['consistent'], 'pending_events': accounting['pending_events']}}
            return self.db.one('''INSERT INTO public.usdt_balance_reconciliation_runs
                (scope,customer_id,cursor_after,receipt_cursor_after,credit_cursor_after,"limit",checked_at,created_at,verdict,
                 accounts_inspected,account_finding_count,drift_frozen_count,receipt_finding_count,liability_micro,
                 unresolved_deposit_micro,custody_state,custody_assets_micro,movement_in_micro,movement_out_micro,
                 movement_count,aggregate_pending_events,actor,findings)
                VALUES ('full',NULL,NULL,NULL,NULL,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s) RETURNING *''',
                (limit, checked, legacy_now(), 'findings' if issues else 'consistent', account_count, account_findings,
                 frozen, receipt_findings, liability, unresolved, custody_state, assets,
                 movement_in, movement_out, totals['movement_count'], accounting['pending_events'], actor.strip(), json.dumps(findings)))
