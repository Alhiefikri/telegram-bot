"""Public receiver validation and disposable PostgreSQL configuration proof."""
from dataclasses import replace
from types import SimpleNamespace
import unittest

from lhie_candidate.checkout.errors import CheckoutError
from lhie_candidate.payments.receiving import PublicReceivingSettings, _binance_receiver


class BinanceReceiverValidationTests(unittest.TestCase):
    def test_only_ascii_numeric_receiver_fits_canonical_namespace(self):
        for kind in ('pay_id', 'uid'):
            value = '0' * 56 + '7'
            self.assertEqual(_binance_receiver(kind, value), (kind, value))
            self.assertLessEqual(len(kind + ':' + value), 64)
        for kind, value in (('email', '123'), (None, '123'), ('', '123'),
                            ('uid', '7' * 58), ('uid', '１２３'), ('uid', '١٢٣'),
                            ('uid', '+123'), ('uid', '123\n'), ('uid', ' 123'),
                            ('uid', 123), ('uid', True), ('uid', b'123')):
            with self.subTest(kind=kind, value=value), self.assertRaises(CheckoutError) as caught:
                _binance_receiver(kind, value)
            self.assertEqual(caught.exception.code, 'binance_receiver_invalid')

    def test_empty_value_clears_both_fields_without_guessing_a_receiver(self):
        for kind in (None, '', 'pay_id', 'uid'):
            for value in (None, ''):
                self.assertEqual(_binance_receiver(kind, value), (None, None))
        with self.assertRaises(CheckoutError):
            _binance_receiver('email', '')


def exercise_public_receiving_settings(fixtures=None):
    from django.db import DatabaseError, transaction
    from lhie_candidate.checkout.db import Database
    from lhie_candidate.checkout.external_usdt import BinancePreparation
    from lhie_candidate.checkout.intents import PaymentSetting
    from tests.test_checkout_postgres import admin_connection

    admin, service = admin_connection(), PublicReceivingSettings()
    actors = []
    first, second = '0' * 56 + '7', '8' * 57

    def reject(call, code):
        try:
            call()
        except CheckoutError as error:
            assert error.code == code, (error.code, code)
        else:
            raise AssertionError('Expected receiver configuration refusal: ' + code)

    def snapshots():
        payments = admin.execute('SELECT id,row_to_json(p)::text FROM public.payments p ORDER BY id').fetchall()
        policies = tuple(admin.execute('SELECT row_to_json(p)::text FROM public.' + table + ' p ORDER BY id').fetchall()
                         for table in ('customer_usdt_funding_policies', 'store_balance_funding_policies'))
        settings = admin.execute('''SELECT id,to_jsonb(s) - 'binance_receiver_type' - 'binance_receiver_value'
            - 'payment_provider_version' - 'updated_at' FROM public.manual_payment_settings s ORDER BY id''').fetchall()
        return payments, policies, settings

    def audit_rows():
        return admin.execute('''SELECT new_values FROM public.audit_logs
            WHERE action='binance_receiver.updated' AND user_id=%s ORDER BY id''', (owner,)).fetchall()

    try:
        for role in ('owner', 'admin'):
            actors.append(admin.execute('''INSERT INTO public.users(name,email,password,role,created_at,updated_at)
                VALUES (%s,%s,'not-a-login',%s,now(),now()) RETURNING id''',
                ('Synthetic receiver ' + role, 'p14-public-receiver-' + role + '@example.invalid', role)).fetchone()[0])
        owner, administrator = actors
        initial = service.current(actor_user_id=owner)
        before = snapshots()
        assert before[0], 'Run receiver configuration proof after checkout creates a Payment'
        reject(lambda: service.current(actor_user_id=administrator), 'operator_forbidden')
        reject(lambda: service.current(actor_user_id=9223372036854775807), 'operator_forbidden')
        reject(lambda: service.change_binance('uid', first, expected_version=initial.version,
                                              actor_user_id=administrator), 'operator_forbidden')
        assert service.current(actor_user_id=owner) == initial
        assert audit_rows() == []

        version = service.change_binance('uid', first, expected_version=initial.version, actor_user_id=owner)
        assert version == initial.version + 1
        configured = service.current(actor_user_id=owner)
        assert (configured.id, configured.version, configured.binance_receiver_type,
                configured.binance_receiver_value) == (initial.id, version, 'uid', first)
        assert configured.updated_at is not None
        if initial.updated_at is not None:
            assert configured.updated_at >= initial.updated_at
        assert first not in repr(configured)
        assert service.change_binance('uid', first, expected_version=version, actor_user_id=owner) == version
        assert service.current(actor_user_id=owner) == configured
        assert len(audit_rows()) == 1
        reject(lambda: service.change_binance('uid', first, expected_version=initial.version,
                                              actor_user_id=owner), 'provider_switch_conflict')
        reject(lambda: service.change_binance('pay_id', second, expected_version=initial.version,
                                              actor_user_id=owner), 'provider_switch_conflict')
        assert service.current(actor_user_id=owner) == configured
        assert len(audit_rows()) == 1

        class RevokedOwnerDatabase(Database):
            def one(self, sql, params=()):
                row = super().one(sql, params)
                if sql == 'SELECT role FROM public.users WHERE id=%s':
                    admin.execute("UPDATE public.users SET role='admin' WHERE id=%s", (owner,))
                return row

        service.db = RevokedOwnerDatabase()
        try:
            reject(lambda: service.change_binance('pay_id', second, expected_version=version,
                                                  actor_user_id=owner), 'operator_forbidden')
        finally:
            admin.execute("UPDATE public.users SET role='owner' WHERE id=%s", (owner,))
            service.db = Database()
        assert service.current(actor_user_id=owner) == configured
        assert len(audit_rows()) == 1

        class FailedAuditDatabase(Database):
            def execute(self, sql, params=()):
                if 'INSERT INTO public.audit_logs' in sql:
                    return super().execute('SELECT 1/0')
                return super().execute(sql, params)

        service.db = FailedAuditDatabase()
        try:
            try:
                service.change_binance('pay_id', second, expected_version=version, actor_user_id=owner)
            except DatabaseError:
                pass
            else:
                raise AssertionError('Receiver change committed without its audit')
        finally:
            service.db = Database()
        assert service.current(actor_user_id=owner) == configured
        assert len(audit_rows()) == 1

        with transaction.atomic():
            try:
                service.change_binance('pay_id', second, expected_version=version, actor_user_id=owner)
            except RuntimeError:
                pass
            else:
                raise AssertionError('Receiver change accepted an ambient transaction')
        version = service.change_binance('pay_id', second, expected_version=version, actor_user_id=owner)
        assert service.current(actor_user_id=owner).binance_receiver_value == second
        version = service.change_binance('pay_id', '', expected_version=version, actor_user_id=owner)
        cleared = service.current(actor_user_id=owner)
        assert (cleared.version, cleared.binance_receiver_type, cleared.binance_receiver_value) == (version, None, None)
        assert service.change_binance(None, None, expected_version=version, actor_user_id=owner) == version
        assert len(audit_rows()) == 3
        for (audit,) in audit_rows():
            assert first not in str(audit) and second not in str(audit)
            assert audit['old_receiver_value'] in (None, '[redacted]')
            assert audit['receiver_value'] in (None, '[redacted]')

        def unexpected_network():
            raise AssertionError('Cleared receiver attempted readiness network access')

        prep = BinancePreparation(SimpleNamespace(readiness=unexpected_network))
        setting = replace(PaymentSetting(cleared.id, cleared.version, True), binance_enabled=True,
                          binance_receiver_type=cleared.binance_receiver_type,
                          binance_receiver_value=cleared.binance_receiver_value)
        reject(lambda: prep.preflight(setting), 'provider_unavailable')
        assert snapshots() == before, 'Receiver configuration retargeted a Payment or changed activation/funding'
        return {'status': 'passed', 'scope': ['owner authorization and locked role revalidation',
            'optimistic version/no-op/stale conflicts', 'atomic masked audit and rollback',
            'clearing fails new readiness before network', 'immutable Payments and unchanged activation/funding']}
    finally:
        admin.close()
