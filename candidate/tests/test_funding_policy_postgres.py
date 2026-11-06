"""Policy and projection checks within the admitted disposable funding smoke."""
import json
import os
import subprocess
import sys


def exercise_policy_and_reconciliation(topup_id, customer_id):
    if os.environ.get('P719_FUNDING_SMOKE') != 'disposable-local-only':
        raise RuntimeError('Explicit disposable funding opt-in required')
    import psycopg
    from django.db import transaction
    from lhie_candidate.checkout.balance import StoreBalanceLedger
    from lhie_candidate.checkout.db import Database
    from lhie_candidate.checkout.errors import CheckoutError
    from lhie_candidate.funding.policy import PolicyError
    from lhie_candidate.funding.policy_admin import FundingPolicyAdmin
    from lhie_candidate.funding.reconciliation import StoreBalanceReconciliation

    admin = psycopg.connect(host='127.0.0.1', hostaddr='127.0.0.1', port=5432,
                            dbname='disposable', user='harness', password='harness', autocommit=True)
    identity = admin.execute('SELECT current_database(),current_user,host(inet_server_addr()),inet_server_port()').fetchone()
    if identity != ('disposable', 'harness', '127.0.0.1', 5432):
        admin.close()
        raise RuntimeError('Disposable funding identity mismatch')
    db = Database()
    try:
        owner, finance = [admin.execute('''INSERT INTO public.users (name,email,password,role,created_at,updated_at)
            VALUES (%s,%s,'synthetic-no-login',%s,now(),now()) RETURNING id''',
            (f'P15 {role}', f'p15-{role}@example.invalid', role)).fetchone()[0]
                          for role in ('owner', 'finance')]
        policy = FundingPolicyAdmin()
        current = db.one('''SELECT version,minimum_amount,preset_amounts FROM public.store_balance_funding_policies
            WHERE singleton_key='store_balance_funding_policy' ''')
        original_topup = db.one('''SELECT provider,payment_setting_id,payment_provider_version,merchant_ref,amount,expires_at
            FROM public.store_balance_top_ups WHERE id=%s''', (topup_id,))
        receipt = admin.execute('''SELECT state,telegram_chat_id,locale FROM public.telegram_outbound_effects
            WHERE effect_key=%s AND effect_type='balance.top_up_receipt' ''',
            (f'balance-top-up:{topup_id}:sakurupiah-receipt:v1',)).fetchall()
        assert receipt == [('pending', '1', 'id')], receipt
        try:
            policy.change(6000, [6000, 12000], expected_version=current['version'], actor_user_id=finance)
        except PolicyError as error:
            assert str(error) == 'operator_forbidden'
        else:
            raise AssertionError('Finance changed the funding policy')
        changed = policy.change(6000, [6000, 12000], expected_version=current['version'], actor_user_id=owner)
        assert changed.changed and changed.current.version == current['version'] + 1
        assert not policy.change(6000, [6000, 12000], expected_version=changed.current.version,
                                 actor_user_id=owner).changed
        try:
            policy.change(7000, [7000, 14000], expected_version=current['version'], actor_user_id=owner)
        except PolicyError as error:
            assert str(error) == 'funding_policy_conflict'
        else:
            raise AssertionError('Stale policy was accepted')
        assert db.one('''SELECT provider,payment_setting_id,payment_provider_version,merchant_ref,amount,expires_at
            FROM public.store_balance_top_ups WHERE id=%s''', (topup_id,)) == original_topup
        assert admin.execute("SELECT count(*) FROM public.audit_logs WHERE user_id=%s AND action='store_balance.funding_policy.updated'", (owner,)).fetchone()[0] == 1
        reconciliation = StoreBalanceReconciliation()
        report = reconciliation.inspect(customer_id=customer_id)
        assert report.accounts_inspected == 1 and report.consistent
        before = admin.execute('SELECT count(*) FROM public.store_balance_entries').fetchone()[0]
        # Simulate a lost projection update without ever committing altered history.
        with transaction.atomic():
            account = db.one('SELECT id,balance FROM public.customer_balance_accounts WHERE customer_id=%s', (customer_id,))
            db.execute('''INSERT INTO public.store_balance_entries
                (customer_balance_account_id,kind,amount,balance_after,source_type,source_id,idempotency_key,created_at)
                VALUES (%s,'top_up_credit',1,%s,'balance_top_up','p15-drift','p15-drift',now())''',
                (account['id'], account['balance'] + 1))
            report = reconciliation.inspect(customer_id=customer_id)
            assert not report.consistent and report.mismatches[0].ledger_balance == account['balance'] + 1
            try:
                StoreBalanceLedger().lock_for_top_up(customer_id)
            except CheckoutError as error:
                assert error.code == 'balance_projection_mismatch'
            else:
                raise AssertionError('Drift did not block a funding posting')
            transaction.set_rollback(True)
        with transaction.atomic():
            db.execute('''INSERT INTO public.store_balance_entries
                (customer_balance_account_id,kind,amount,balance_after,source_type,source_id,idempotency_key,created_at)
                VALUES (%s,'unknown_kind',1,%s,'balance_top_up','p15-unknown','p15-unknown',now())''',
                (account['id'], account['balance']))
            report = reconciliation.inspect(customer_id=customer_id)
            assert not report.consistent and report.mismatches[0].unknown_entries == 1
            try:
                StoreBalanceLedger().lock_for_top_up(customer_id)
            except CheckoutError as error:
                assert error.code == 'balance_projection_mismatch'
            else:
                raise AssertionError('Unknown ledger direction did not block funding')
            transaction.set_rollback(True)
        assert admin.execute('SELECT count(*) FROM public.store_balance_entries').fetchone()[0] == before
        assert reconciliation.inspect(customer_id=customer_id).consistent
        command = subprocess.run([sys.executable, 'check_store_balance.py', '--customer', str(customer_id)],
                                 capture_output=True, text=True, timeout=20, check=True)
        result = json.loads(command.stdout)
        assert result['consistent'] and result['accounts_inspected'] == 1 and result['mismatch_count'] == 0
        return {'policy_versioned': True, 'policy_unauthorized_rejected': True,
                'snapshot_unchanged': True, 'drift_rejected_without_repair': True}
    finally:
        admin.close()
