import json
import unittest
from contextlib import nullcontext
from datetime import datetime
from unittest.mock import patch

from lhie_candidate.usdt.operations import ReorgIncidents
from lhie_candidate.usdt.operations_reconciliation import OperationalReconciliation, _micro
from lhie_candidate.usdt.operations_restore import RestoreGate


class Rows:
    def __init__(self, *rows):
        self.items = list(rows)
        self.writes = []

    def one(self, sql, params=()):
        self.writes.append((sql, params))
        return self.items.pop(0)

    def execute(self, sql, params=()):
        self.writes.append((sql, params))
        return 1


class OperationalTests(unittest.TestCase):
    def test_exact_token_conversion(self):
        self.assertEqual(_micro('1500000000000000000', 18), 1500000)
        self.assertIsNone(_micro('1000000000001', 18))
        self.assertIsNone(_micro('1.0', 6))

    def test_custody_registry_keeps_unavailable_distinct_from_zero(self):
        from lhie_candidate.usdt.treasury import CustodyObservation, CustodyRegistry
        class Unreachable:
            def provider_key(self):
                return 'watch_only'
            def observe(self):
                raise OSError('unreachable')
        result = CustodyRegistry((Unreachable(),)).observe_all()[0]
        self.assertEqual((result.state, result.assets_micro, result.reason), ('unavailable', None, 'adapter_error'))
        with self.assertRaises(ValueError):
            CustodyObservation('watch_only', 'available', -1, datetime(2026, 1, 1))

    @patch('lhie_candidate.usdt.operations._outside')
    @patch('lhie_candidate.usdt.operations.transaction.atomic', return_value=nullcontext())
    @patch('lhie_candidate.usdt.operations.legacy_now', return_value=datetime(2026, 1, 1))
    def test_spent_reorg_freezes_without_posting_or_rewriting_history(self, _clock, _atomic, _outside):
        target = {'id': 't', 'customer_id': 1, 'receiver_id': 2, 'entry_id': 3, 'receipt_id': 4,
                  'evidence_payload': {'block_number': 5, 'block_hash': '0x' + 'a' * 64}}
        checkpoint = {'version': 9, 'review_reason': 'checkpoint_reorg', 'last_block': 5, 'last_block_hash': 'hash'}
        account = {'id': 6, 'balance_micro': 500000, 'version': 2}
        db = Rows(target, checkpoint, {'id': 1}, account, {'id': 't'}, {'id': 2}, checkpoint,
                  {'entry_id': 3, 'receipt_id': 4}, None, {'amount_micro': 2000000}, {'amount': 0},
                  {'evidence': {'reason': 'checkpoint'}}, {'id': 7})
        service = ReorgIncidents(None, admin_chat_id='1')
        service.db = db
        self.assertTrue(service.check('t'))
        insert = next(params for sql, params in db.writes if 'INSERT INTO public.usdt_reorg_incidents' in sql)
        self.assertEqual((insert[7], insert[8], insert[9]), (2000000, 500000, 1500000))
        self.assertTrue(any('usdt_reorg_incident:7' in params for _, params in db.writes))
        self.assertFalse(any('UPDATE public.customer_usdt_balance_entries' in sql or 'INSERT INTO public.customer_usdt_balance_entries' in sql for sql, _ in db.writes))

    def test_receipt_pages_preserve_unresolved_money(self):
        class PageDB:
            def rows(self, sql, params):
                if sql.lstrip().startswith('SELECT r.id,r.currency'):
                    return [{'id': 1, 'currency': 'USDT', 'status': 'claimed', 'amount_units': '1000000',
                             'decimals': 6, 'entry_id': None, 'resolution_kind': None, 'refund_receipt_id': None},
                            {'id': 2, 'currency': 'USDT', 'status': 'claimed', 'amount_units': '2000000',
                             'decimals': 6, 'entry_id': None, 'resolution_kind': None, 'refund_receipt_id': None}]
                return []
        service = OperationalReconciliation()
        service.db = PageDB()
        page = service.receipt_page(limit=1)
        self.assertTrue(page['receipts_more'])
        self.assertEqual(page['receipt_cursor'], 1)
        self.assertEqual(page['unresolved'], 1000000)
        self.assertEqual(page['findings'], ['unsettled_receipt'])

    @patch('lhie_candidate.usdt.operations_restore._outside')
    @patch('lhie_candidate.usdt.operations_restore.transaction.atomic', return_value=nullcontext())
    def test_restore_rejects_missing_coverage_or_unknown_custody(self, _atomic, _outside):
        now = datetime(2026, 1, 1)
        evidence = {'window_opened_at': now.isoformat(), 'settlement': {'failed': 0, 'has_more': False},
                    'scan': {'status': 'completed'}, 'outbound': {'remaining': 0}}
        gate = {'state': 'open', 'opened_at': now, 'replay_completed_at': now, 'replay_evidence': evidence}
        run = {'cursor_after': None, 'receipt_cursor_after': None, 'credit_cursor_after': None,
               'verdict': 'consistent', 'account_finding_count': 0, 'receipt_finding_count': 0,
               'custody_state': 'missing', 'custody_assets_micro': None,
               'findings': json.dumps({'accounts': {'has_more': False}, 'receipts': {'receipts_has_more': False,
                                       'credits_have_more': False}, 'treasury': {'unexplained_micro': '0'}})}
        service = RestoreGate()
        service.db = Rows(gate, {'n': 0}, run)
        with self.assertRaises(ValueError):
            service.close(actor='operator')
        run['custody_state'], run['custody_assets_micro'] = 'available', 0
        run['findings'] = json.dumps({'accounts': {'has_more': False}, 'receipts': {'receipts_has_more': True,
                                       'credits_have_more': False}, 'treasury': {'unexplained_micro': '0'}})
        service.db = Rows(gate, {'n': 0}, run)
        with self.assertRaises(ValueError):
            service.close(actor='operator')

    def test_recovery_preview_requires_owner_session_and_credentials(self):
        from types import SimpleNamespace
        from lhie_candidate.adjustments.password import AdjustmentRejected
        from lhie_candidate.compat.identity import sha256_digest
        actor = {'id': 5, 'password': 'hash'}
        request = SimpleNamespace(auth_session=SimpleNamespace(token='session'))
        row = {'id': 'request', 'owner_user_id': 5, 'fingerprint': 'proof',
               'expires_at': datetime(2027, 1, 1), 'session_binding': sha256_digest('session'),
               'credential_binding': sha256_digest('hash')}
        with patch('lhie_candidate.usdt.operations.legacy_now', return_value=datetime(2026, 1, 1)):
            self.assertEqual(ReorgIncidents._bound(Rows(row), request, actor, 'request', 'proof', 1), row)
            with self.assertRaises(AdjustmentRejected):
                ReorgIncidents._bound(Rows(row), request, actor, 'request', 'wrong', 1)
            with self.assertRaises(AdjustmentRejected):
                ReorgIncidents._bound(Rows(row), request, dict(actor, password='changed'), 'request', 'proof', 1)

    @patch('lhie_candidate.usdt.operations._outside')
    @patch('lhie_candidate.usdt.operations.transaction.atomic', return_value=nullcontext())
    @patch('lhie_candidate.usdt.operations.owner')
    def test_recovery_confirmation_consumes_bound_authority_and_keeps_loss_unresolved(self, owner, _atomic, _outside):
        from types import SimpleNamespace
        from unittest.mock import Mock
        owner.return_value = {'id': 5, 'password': 'hash'}
        service = ReorgIncidents(None, admin_chat_id='1')
        service._bound = Mock(return_value={'incident_id': 9})
        service.passwords = Mock()
        service.passwords.verify.return_value = 'lease'
        service.db = Rows({'id': 9}, None, {'id': 10, 'request_id': 'request',
                                            'status': 'authorized_unresolved'})
        with patch('lhie_candidate.usdt.operations.legacy_now', return_value=datetime(2026, 1, 1)):
            result = service.confirm_recovery(SimpleNamespace(), 'request', 'proof', 'password')
        self.assertEqual(result['status'], 'authorized_unresolved')
        service.passwords.consume.assert_called_once_with(5, 'lease', 'request', 'proof')
        service.passwords.release.assert_called_once_with(5, 'lease')
        self.assertFalse(any('customer_usdt_balance_accounts' in sql or 'customer_usdt_balance_entries' in sql
                             for sql, _ in service.db.writes))

    @patch('lhie_candidate.usdt.operations._outside')
    @patch('lhie_candidate.usdt.operations_reconciliation.transaction.atomic', return_value=nullcontext())
    def test_complete_reconciliation_walks_all_three_facets(self, _atomic, _outside):
        class Accounts:
            def __init__(self):
                self.cursors = []
            def inspect(self, *, after_account_id, limit):
                self.cursors.append(after_account_id)
                return {'accounts_inspected': 1, 'findings': [], 'has_more': after_account_id is None,
                        'next_cursor': 1 if after_account_id is None else None}
        class Accounting:
            def status(self):
                return {'consistent': True, 'pending_events': 0}
        class ReconciliationDB:
            def execute(self, *args):
                pass
            def one(self, sql, params=()):
                if 'AS liability' in sql:
                    return {'liability': 0, 'fees': 0, 'movement_in': 0, 'movement_out': 0,
                            'movement_count': 0, 'incident_count': 0, 'pending_reorg_receivers': 0,
                            'exposure': 0, 'spent': 0, 'authorized': 0}
                return {'verdict': params[3], 'findings': json.loads(params[-1])}
        from lhie_candidate.usdt.treasury import CustodyObservation
        class Provider:
            def provider_key(self):
                return 'synthetic'
            def observe(self):
                return CustodyObservation('synthetic', 'available', 0, datetime(2026, 1, 1))
        service = OperationalReconciliation(custody_providers=(Provider(),))
        service.accounts, service.accounting, service.db = Accounts(), Accounting(), ReconciliationDB()
        pages = []
        def receipt_page(*, after_receipt, after_credit, limit):
            pages.append((after_receipt, after_credit))
            more = after_receipt == 0
            return {'receipt_count': 1, 'credit_count': 1, 'findings': [], 'unresolved': 0,
                    'overcredited': 0, 'receipts_more': more, 'credits_more': more,
                    'receipt_cursor': after_receipt + 1, 'credit_cursor': after_credit + 1}
        service.receipt_page = receipt_page
        result = service.complete(limit=1)
        self.assertEqual(service.accounts.cursors, [None, 1])
        self.assertEqual(pages, [(0, 0), (1, 1)])
        self.assertEqual(result['verdict'], 'consistent')
        self.assertFalse(result['findings']['receipts']['credits_have_more'])
        self.assertEqual(result['findings']['treasury']['unexplained_micro'], '0')
