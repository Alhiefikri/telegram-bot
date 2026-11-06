"""Local PostgreSQL tests for the shared physical-money and address registries."""
import os
from concurrent.futures import ThreadPoolExecutor
from threading import Barrier
from uuid import uuid4

import unittest

def setUpModule():
    if os.environ.get('P16_REGISTRY_TEST') != 'disposable-local-only':
        raise unittest.SkipTest('requires explicitly selected disposable PostgreSQL')


def load_tests(loader, tests, pattern):
    for name, value in globals().items():
        if name.startswith('test_') and callable(value):
            tests.addTest(unittest.FunctionTestCase(value))
    return tests


def database():
    if (os.environ.get('P16_REGISTRY_TEST') != 'disposable-local-only'
            and os.environ.get('P07_CHECKOUT_SMOKE') != 'disposable-local-only'):
        raise unittest.SkipTest('requires explicitly selected disposable PostgreSQL')
    from lhie_candidate.startup import configure_django
    configure_django()
    from lhie_candidate.checkout.db import Database
    db = Database()
    identity = db.one('SELECT current_database() AS db,current_user AS role,host(inet_server_addr()) AS host')
    assert identity == {'db': 'disposable', 'role': 'candidate_runtime', 'host': '127.0.0.1'}
    return db


def test_bsc_parent_cross_target_blocks_consumption_and_replay():
    db = database()
    from django.db import transaction
    from lhie_candidate.payments.receipts import claim_bsc_payment, claim_usdt_bsc_top_up, receipt_consumable
    tx = '0x' + uuid4().hex * 2
    def claim(target, index):
        return claim_usdt_bsc_top_up(db, top_up_id=target, customer_id='123', chain_id=56,
                                    tx_hash=tx, log_index=index, amount_units='1000000000000000000', decimals=18)
    with transaction.atomic():
        first = claim('topup-a', 1)
        assert first.status == 'claimed'
        assert receipt_consumable(db, receipt_id=first.receipt_id, target_type='customer_usdt_top_up', target_id='topup-a')
        checkout = {'id': str(uuid4()), 'currency': 'USDT'}
        conflict = claim_bsc_payment(db, payment=checkout, chain_id=56, tx_hash=tx, log_index=1,
            token_contract='0x' + '1' * 40, receiver='0x' + '2' * 40,
            amount_units='1000000000000000000', decimals=18)
        assert conflict.status == 'conflict'
        assert claim('topup-b', 2).status == 'review'
        assert not receipt_consumable(db, receipt_id=first.receipt_id, target_type='customer_usdt_top_up', target_id='topup-a')
        assert claim('topup-a', 1).status == 'review'
        transaction.set_rollback(True)


def test_binance_alias_collision_rolls_back_second_receipt():
    db = database()
    from django.db import transaction
    from lhie_candidate.payments.receipts import claim_usdt_binance_top_up
    reference = uuid4().hex
    def claim(target, tx, order):
        return claim_usdt_binance_top_up(db, top_up_id=target, customer_id='123', receiver_type='pay_id',
            receiver_value='12345', transaction_id=tx, order_id=order, amount_micro=1000000)
    with transaction.atomic():
        assert claim('first', reference, reference + 'alias').status == 'claimed'
        assert claim('first', reference, reference + 'alias').status == 'replayed'
        assert claim('second', reference + 'alias', None).status == 'conflict'
        assert db.one('SELECT count(*) AS n FROM public.external_money_receipts WHERE canonical_key=%s',
                      ('v1|provider_reference|binance|pay_id:12345|' + reference + 'alias',))['n'] == 0
        transaction.set_rollback(True)


def test_receiver_claim_is_permanent_across_channels_and_checkout_reuse():
    db = database()
    from django.db import transaction
    from lhie_candidate.payments.receiver_ownership import claim_address, ReceiverAddressConflict
    address = '0x' + uuid4().hex + '12345678'
    with transaction.atomic():
        claim = claim_address(db, chain_id=56, address=address.upper().replace('0X', '0x'), channel='checkout')
        assert claim_address(db, chain_id=56, address=address, channel='checkout')['id'] == claim['id']
        try:
            claim_address(db, chain_id=56, address=address, channel='customer_deposit')
        except ReceiverAddressConflict:
            pass
        else:
            raise AssertionError('cross-channel claim was accepted')
        assert db.one('SELECT channel FROM public.usdt_receiver_address_claims WHERE id=%s', (claim['id'],))['channel'] == 'checkout'
        transaction.set_rollback(True)


def test_competing_channels_have_one_winner():
    database()
    from django.db import connections, transaction
    from lhie_candidate.payments.receiver_ownership import claim_address, ReceiverAddressConflict
    address = '0x' + uuid4().hex + '12345678'
    barrier = Barrier(2)
    def compete(channel):
        try:
            db = database()
            barrier.wait(timeout=10)
            with transaction.atomic():
                claim_address(db, chain_id=56, address=address, channel=channel)
            return 'claimed'
        except ReceiverAddressConflict:
            return 'conflict'
        finally:
            connections.close_all()
    with ThreadPoolExecutor(max_workers=2) as pool:
        assert sorted(pool.map(compete, ('checkout', 'customer_deposit'))) == ['claimed', 'conflict']
    return address


def test_competing_binance_aliases_have_one_owner():
    database()
    from django.db import connections, transaction
    from lhie_candidate.payments.receipts import claim_usdt_binance_top_up
    alias = uuid4().hex
    barrier = Barrier(2)
    def compete(suffix):
        try:
            db = database()
            barrier.wait(timeout=10)
            with transaction.atomic():
                return claim_usdt_binance_top_up(db, top_up_id='race-' + suffix, customer_id='123',
                    receiver_type='uid', receiver_value='12345', transaction_id=alias + suffix,
                    order_id=alias, amount_micro=1000000).status
        finally:
            connections.close_all()
    with ThreadPoolExecutor(max_workers=2) as pool:
        assert sorted(pool.map(compete, ('a', 'b'))) == ['claimed', 'conflict']
    return 'v1|provider_reference|binance|uid:12345|' + alias


def prove_customer_receiver_identity_is_immutable(admin):
    import psycopg
    from django.db import transaction
    from lhie_candidate.payments.receiver_ownership import claim_address, find_deposit_receiver, ReceiverAddressConflict
    db = database()
    customer, other = [row[0] for row in admin.execute('SELECT id FROM public.customers ORDER BY id LIMIT 2').fetchall()]
    address = '0x' + uuid4().hex + '12345678'
    with transaction.atomic():
        claim = claim_address(db, chain_id=56, address=address, channel='customer_deposit')
    receiver = admin.execute('''INSERT INTO public.customer_usdt_deposit_receivers
        (customer_id,address_claim_id,chain_id,address,address_normalized,token_contract,token_decimals,
         custody_namespace,source,verification_status,created_at,updated_at)
        VALUES (%s,%s,56,%s,%s,%s,18,'operator','owner','pending_verification',now(),now()) RETURNING id''',
        (customer, claim['id'], address, address, '0x' + '1' * 40)).fetchone()[0]
    try:
        with admin.transaction():
            admin.execute('UPDATE public.customer_usdt_deposit_receivers SET customer_id=%s WHERE id=%s',
                          (other, receiver))
    except psycopg.Error:
        pass
    else:
        raise AssertionError('Customer receiver was reassigned')
    admin.execute("UPDATE public.customer_usdt_deposit_receivers SET retired_at=now(),retirement_reason='synthetic' WHERE id=%s", (receiver,))
    assert find_deposit_receiver(db, chain_id=56, address=address)['customer_id'] == customer
    try:
        with transaction.atomic():
            claim_address(db, chain_id=56, address=address, channel='customer_deposit')
    except ReceiverAddressConflict:
        pass
    else:
        raise AssertionError('Retired receiver was reused')


def exercise_usdt_receipt_receiver_registry():
    """Run the P16 registry proof against the guarded disposable database."""
    from tests.test_checkout_postgres import admin_connection
    admin = admin_connection()
    try:
        test_bsc_parent_cross_target_blocks_consumption_and_replay()
        test_binance_alias_collision_rolls_back_second_receipt()
        test_receiver_claim_is_permanent_across_channels_and_checkout_reuse()
        prove_customer_receiver_identity_is_immutable(admin)
        alias = test_competing_binance_aliases_have_one_owner()
        assert admin.execute('SELECT count(*) FROM public.external_money_receipt_aliases WHERE alias_key=%s',
                             (alias,)).fetchone() == (1,)
        address = test_competing_channels_have_one_winner()
        assert admin.execute('''SELECT count(*) FROM public.usdt_receiver_address_claims
            WHERE chain_id=56 AND address_normalized=%s''', (address,)).fetchone() == (1,)
        return {'bsc_parent': 'review_blocks_consumption', 'binance_alias': 'conflict_rollback',
                'receiver_ownership': 'exclusive_race'}
    finally:
        admin.close()
